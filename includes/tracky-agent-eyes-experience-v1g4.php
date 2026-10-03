<?php
declare(strict_types=1);
/** Presentation of canonical sync/scene state; no capture or consent authority. */
function tracky_eyes_experience_v1g4(?array $site,array $scene,?float $now=null): array {
    $now??=microtime(true); $contactAge=null;
    try { if(!empty($site['last_seen_at']))$contactAge=$now-(float)(new DateTimeImmutable($site['last_seen_at'],new DateTimeZone('UTC')))->format('U.u'); } catch(Throwable) {}
    $recorded=(string)($site['status']??'');
    $connection=!$site?'not_reported':(in_array($recorded,['offline','disabled','failed'],true)?'disconnected':
        ($contactAge!==null&&is_finite($contactAge)&&$contactAge>=0&&$contactAge<=300?'recent_contact':
        ($contactAge!==null&&is_finite($contactAge)&&$contactAge>300?'contact_stale':'unknown')));
    $reason=(string)($scene['reason']??'never_shared');
    $state=(string)($scene['state']??'never_shared');
    $age=$scene['age_seconds']??null;
    if($state==='available'&&(!is_numeric($age)||!is_finite((float)$age)||$age<0||$age>=60)) { $state='unavailable';$reason='observation_expired'; }
    $title='Scene status unavailable'; $guidance='Open HomeServer Tracky and refresh Agent Eyes status.';
    if(!$site){$title='No HomeServer scene report';$guidance='Pair HomeServer in device settings, then finish the local camera and scene review in Tracky.';}
    elseif($state==='available'){$title='Recent checked scene';$guidance='This snapshot expires after 60 seconds. A new observation requires the owner on HomeServer.';}
    elseif($state==='revoked'){$title='Sharing off — confirmed by Cloud';$guidance='Enable sharing on HomeServer only if desired. Restarting HomeServer turns sharing off again.';}
    elseif($state==='never_shared'){$title='No scene has been shared';$guidance='Check the local camera/model review and optional scene-sharing control in HomeServer Tracky. Cloud cannot tell whether local sharing is off or setup is incomplete.';}
    elseif($reason==='observation_expired'){$title='Scene expired';$guidance='Complete a new supervised observation on HomeServer. Reloading this page does not start the camera.';}
    elseif(in_array($reason,['owner_approval_required','model_review_required'],true)){$title='Local review required';$guidance='Complete the installed camera and model review in HomeServer Tracky before a new observation.';}
    elseif($reason==='privacy_enabled'){$title='Camera privacy enabled';$guidance='Review the privacy control locally on HomeServer. Cloud cannot turn the camera on.';}
    elseif(in_array($reason,['session_stopped','owner_presence_expired'],true)){$title='Supervised session stopped';$guidance='Inspect camera release and any recovery instructions in HomeServer Tracky before a new session.';}
    elseif(in_array($reason,['no_session','observation_missing'],true)){$title='No completed scene observation';$guidance='Open HomeServer Tracky and complete an owner-approved supervised observation.';}
    $consent=$state==='revoked'?'revoked_confirmed':($state==='never_shared'?'not_reported':'last_report_received');
    return ['contract'=>'tracky.agent-eyes.experience.v1g4','site_id'=>(string)($site['site_id']??''),
      'site_label'=>(string)($site['label']??$site['site_id']??'HomeServer'),'device_id'=>(string)($site['device_id']??''),
      'connection'=>$connection,'connection_label'=>['recent_contact'=>'Recent HomeServer contact','contact_stale'=>'HomeServer contact is stale','disconnected'=>'HomeServer reported disconnected','unknown'=>'HomeServer connection unknown','not_reported'=>'No HomeServer report'][$connection],'contact_age_seconds'=>$contactAge!==null&&is_finite($contactAge)&&$contactAge>=0?round($contactAge,1):null,
      'contact_freshness_limit_seconds'=>300,'state'=>$state,'reason'=>$reason,'title'=>$title,'guidance'=>$guidance,
      'observed_at'=>$state==='available'?(string)($scene['observed_at']??''):($reason==='observation_expired'?($scene['last_observed_at']??null):null),
      'age_seconds'=>$state==='available'?(float)$age:null,'freshness_limit_seconds'=>60,
      'meaning'=>$state==='available'?tracky_scene_text_v1g3d($scene):'No current scene meaning is available.',
      'consent'=>$consent,'local_consent_known'=>false,'pending_revocation_known'=>false,
      'consent_note'=>'Cloud shows the last received state. A newer local change may be waiting for acknowledgment; check HomeServer.',
      'capture_authority'=>false,'automatic_recovery'=>false,'hardware_certified'=>false];
}
function tracky_eyes_reports_v1g4(PDO $pdo,int $userId,string $siteId=''): array {
    $sites=tracky_cloud_v270_sites($pdo,$userId);$out=[];
    foreach($sites as $site){
        if($siteId!==''&&$site['site_id']!==$siteId)continue;
        $out[]=tracky_eyes_experience_v1g4($site,tracky_scene_read_v1g3d($pdo,$userId,(string)$site['site_id']));
        if(count($out)>=20)break;
    }
    return $out?:[tracky_eyes_experience_v1g4(null,[])];
}
