<?php
declare(strict_types=1);

/**
 * Agent-led optional visual identity v1.20.
 * Cloud owns selection and explicit self-enrollment consent; Tracky retains
 * local descriptors/photos and device-side recognition authority. Cloud may
 * report camera/model readiness but NEVER infers enrollment from a profile
 * photograph, a browser acknowledgement or generic Tracky connectivity.
 */
const VP3_VISUAL_ONBOARDING_V120='vp3-visual-onboarding-v120';
const VP3_VISUAL_CONSENT_SCOPE_V120='owner-self-local-recognition-v1';

function vp3_visual_onboarding_site_readiness_v120(array $sites,int $now): array {
    foreach($sites as $site){
        if(!is_array($site))continue;
        $last=strtotime((string)($site['last_seen_at']??''));
        $caps=(array)($site['capabilities']??[]);
        $health=(array)($site['health']??[]);
        $recognition=in_array(strtolower((string)($caps['recognition']??'')),['1','true','yes'],true);
        $camera=in_array(strtolower((string)($health['camera']??'')),['healthy','available'],true);
        $fresh=$last!==false&&abs($now-$last)<300;
        if($fresh&&$recognition&&$camera){
            return ['ready'=>true,'site_id'=>(string)($site['site_id']??''),'last_seen_at'=>(string)$site['last_seen_at']];
        }
    }
    return ['ready'=>false,'site_id'=>'','last_seen_at'=>''];
}

/** Tracky v1F2 authenticated site status. It is NOT biometric proof.
 * Only a fresh paired HomeServer's own site row may report this signal.
 * Cloud never receives a participant ID, local contact, template or portrait.
 */
function vp3_visual_onboarding_association_v1f2(array $sites,int $now): array {
    $active=null;$revoked=null;
    foreach($sites as $site){
        if(!is_array($site))continue;
        $siteId=(string)($site['site_id']??'');
        $deviceId=(string)($site['device_id']??'');
        if($siteId===''||$deviceId===''||!hash_equals($siteId,$deviceId))continue;
        $seen=strtotime((string)($site['last_seen_at']??''));
        if($seen===false||$seen>$now+60||$now-$seen>=300)continue;
        // Real Cloud DB site rows always carry a separately accepted visual
        // timestamp. Non-visual heartbeats and rejected stale requests must
        // never refresh or reactivate an old consent status. Compatibility
        // fallback applies only to caller-supplied legacy test site arrays.
        $visualDate=array_key_exists('visual_owner_reported_at',$site)
            ?(string)($site['visual_owner_reported_at']??'')
            :(string)($site['last_seen_at']??'');
        $visualSeen=$visualDate!==''?strtotime($visualDate):false;
        if($visualSeen===false||$visualSeen>$now+60||$now-$visualSeen>=300)continue;
        $health=(array)($site['health']??[]);
        $state=$health['visual_owner_association']??null;
        if(!in_array($state,['owner_attributed_unverified','revoked'],true))continue;
        $entry=['state'=>$state,'reported_at'=>$visualDate,
                'site_id'=>$siteId,'transport'=>'authenticated_tracky_site_report',
                'independently_verified'=>false,'biometric_data_received'=>false,
                '_seen'=>$visualSeen];
        if($state==='owner_attributed_unverified'){
            if($active===null||$seen>$active['_seen'])$active=$entry;
        }elseif($revoked===null||$seen>$revoked['_seen'])$revoked=$entry;
    }
    $selected=$active??$revoked;
    if($selected!==null){unset($selected['_seen']);return $selected;}
    return ['state'=>'unreported','reported_at'=>'','site_id'=>'',
            'transport'=>'none','independently_verified'=>false,
            'biometric_data_received'=>false];
}

function vp3_visual_onboarding_status_v120(array $prefs,array $sites=[],?int $now=null): array {
    $interests=(array)($prefs['feature_interests']??[]);
    $selected=!empty($interests['workflow.visual_profile']);
    $consented=$selected
        &&($interests['visual.owner_self.consent_scope']??'')===VP3_VISUAL_CONSENT_SCOPE_V120
        &&is_string($interests['visual.owner_self.consented_at']??null)
        &&trim((string)$interests['visual.owner_self.consented_at'])!=='';
    $clock=$now??time();
    $site=vp3_visual_onboarding_site_readiness_v120($sites,$clock);
    $association=$selected&&$consented
        ?vp3_visual_onboarding_association_v1f2($sites,$clock)
        :['state'=>'suppressed_without_cloud_consent','reported_at'=>'','site_id'=>'',
          'transport'=>'none','independently_verified'=>false,'biometric_data_received'=>false];
    $stage=!$selected?'not_selected':(!$consented?'needs_explicit_consent':
        ($association['state']==='owner_attributed_unverified'
            ?'local_owner_attribution_reported_unverified'
            :($site['ready']?'ready_for_local_enrollment':'awaiting_local_tracky')));
    return [
        'build'=>VP3_VISUAL_ONBOARDING_V120,'selected'=>$selected,'consented'=>$consented,
        'consent_scope'=>$consented?VP3_VISUAL_CONSENT_SCOPE_V120:'',
        'consented_at'=>$consented?(string)$interests['visual.owner_self.consented_at']:'',
        'stage'=>$stage,'site_ready'=>$site['ready'],'site_id'=>$site['site_id'],
        'owner_contact_association'=>$association,
        'owner_contact_attribution_reported'=>$association['state']==='owner_attributed_unverified',
        'enrollment_verified'=>false,'cloud_biometric_storage'=>false,
        'local_participant_enrollment'=>'requires_local_tracky_receipt',
        'tracking_enabled'=>false,'contact_creation_enabled'=>false,
        'warning'=>'Selecting visual identity does not grant camera access. Local Tracky requires separate camera permission. Revoking Cloud consent does not delete a locally stored participant; delete it in Tracky.',
        'setup_url'=>'/tracky.php',
    ];
}
function vp3_visual_onboarding_owner_state_v120(PDO $pdo,array $user,array $prefs=[]): array {
    if(!$prefs)$prefs=onboarding_intelligence_preferences($pdo,(int)$user['id']);
    $sites=[];
    if(!empty($prefs['feature_interests']['workflow.visual_profile'])
        &&function_exists('tracky_cloud_v270_schema_ready')&&tracky_cloud_v270_schema_ready($pdo)
        &&function_exists('tracky_cloud_v270_sites')){
        try{$sites=tracky_cloud_v270_sites($pdo,(int)$user['id']);}catch(Throwable $e){$sites=[];}
    }
    return vp3_visual_onboarding_status_v120($prefs,$sites);
}
function vp3_visual_onboarding_record_consent_v120(PDO $pdo,array $user,bool $enabled,string $scope): array {
    if($scope!==VP3_VISUAL_CONSENT_SCOPE_V120)throw new InvalidArgumentException('Only explicit owner-self local enrollment is supported.');
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('A signed-in VP3 account is required.');
    $prefs=onboarding_intelligence_preferences($pdo,$uid);
    $at=(string)($prefs['onboarding_step']??'complete');
    $patch=[
        'workflow.visual_profile'=>$enabled,
        'visual.owner_self.consent_scope'=>$enabled?VP3_VISUAL_CONSENT_SCOPE_V120:'',
        'visual.owner_self.consented_at'=>$enabled?gmdate(DATE_ATOM):'',
        'visual.owner_self.enrollment_intent'=>$enabled?'awaiting_local_tracky':'revoked_cloud'
    ];
    onboarding_intelligence_save_progress(
        $pdo,$user,onboarding_intelligence_valid_step($at),
        [],null,$patch
    );
    return vp3_visual_onboarding_owner_state_v120($pdo,$user);
}
