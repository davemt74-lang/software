<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-visual-onboarding-v120.php';
require_once dirname(__DIR__).'/includes/tracky-cloud-v270.php';

function require_v1f2(bool $condition,string $message): void {
    if(!$condition)throw new RuntimeException($message);
}
$now=strtotime('2026-10-01T22:30:00Z');
$prefs=['feature_interests'=>[
    'workflow.visual_profile'=>true,
    'visual.owner_self.consent_scope'=>VP3_VISUAL_CONSENT_SCOPE_V120,
    'visual.owner_self.consented_at'=>'2026-10-01T22:20:00Z',
]];
$site=['site_id'=>'hs-test-01','device_id'=>'hs-test-01',
    'last_seen_at'=>'2026-10-01T22:29:00Z',
    'capabilities'=>['recognition'=>false],
    'health'=>['camera'=>'unavailable','visual_owner_association'=>'owner_attributed_unverified']];
$health=tracky_cloud_v270_health($site['health']);
require_v1f2(($health['visual_owner_association']??'')==='owner_attributed_unverified',
             'Only typed local site attribution passes Cloud health normalization');
require_v1f2(tracky_cloud_v270_health(['visual_owner_association'=>'revoked'])
    ['visual_owner_association']==='revoked','Revocation tombstone is accepted');
foreach(['verified','face_recognized','enrolled_locally',true,['status'=>'verified']] as $unsafe){
    try{
        tracky_cloud_v270_health(['visual_owner_association'=>$unsafe]);
        throw new RuntimeException('Disallowed biometric authority was accepted');
    }catch(RuntimeException $e){
        if($e->getMessage()==='Disallowed biometric authority was accepted')throw $e;
    }
}
$stage=vp3_visual_onboarding_status_v120($prefs,[$site],$now);
require_v1f2($stage['owner_contact_attribution_reported']===true
    &&$stage['stage']==='local_owner_attribution_reported_unverified',
    'Fresh paired, consented owner status may be reported without recognition readiness');
require_v1f2($stage['site_ready']===false&&$stage['enrollment_verified']===false
    &&$stage['cloud_biometric_storage']===false&&$stage['tracking_enabled']===false
    &&$stage['contact_creation_enabled']===false,
    'Owner attribution may never promote facial proof, camera or contacts');
$assoc=$stage['owner_contact_association'];
require_v1f2($assoc['independently_verified']===false
    &&$assoc['biometric_data_received']===false
    &&$assoc['transport']==='authenticated_tracky_site_report',
    'Authenticated relay reports semantics but not independent biometric proof');
foreach(['participant_id','contact_id','embedding','portrait','template','signature'] as $forbidden){
    require_v1f2(!array_key_exists($forbidden,$assoc),'Cloud attribution exposes forbidden source ID: '.$forbidden);
}
$noConsent=$prefs;
unset($noConsent['feature_interests']['visual.owner_self.consented_at']);
$blocked=vp3_visual_onboarding_status_v120($noConsent,[$site],$now);
require_v1f2(!$blocked['owner_contact_attribution_reported']
    &&$blocked['owner_contact_association']['state']==='suppressed_without_cloud_consent'
    &&$blocked['stage']==='needs_explicit_consent','Cloud consent remains independent');
$wrongDevice=vp3_visual_onboarding_status_v120($prefs,[array_replace($site,['device_id'=>'other-device'])],$now);
require_v1f2(!$wrongDevice['owner_contact_attribution_reported'],'Misbound device cannot advertise attribution');
$stale=vp3_visual_onboarding_status_v120($prefs,[array_replace($site,
    ['last_seen_at'=>'2026-10-01T22:19:00Z'])],$now);
require_v1f2(!$stale['owner_contact_attribution_reported'],'Stale device heartbeat cannot claim active attribution');
$future=vp3_visual_onboarding_status_v120($prefs,[array_replace($site,
    ['last_seen_at'=>'2026-10-01T22:35:00Z'])],$now);
require_v1f2(!$future['owner_contact_attribution_reported'],'Future timestamp cannot prove current attribution');
$revoked=vp3_visual_onboarding_status_v120($prefs,[array_replace($site,
    ['health'=>['visual_owner_association'=>'revoked']])],$now);
require_v1f2(!$revoked['owner_contact_attribution_reported']
    &&$revoked['owner_contact_association']['state']==='revoked'
    &&!$revoked['enrollment_verified'],'Site revocation wins and retains honest state');
$disabled=vp3_visual_onboarding_status_v120(['feature_interests'=>[]],[$site],$now);
require_v1f2(!$disabled['owner_contact_attribution_reported']
    &&$disabled['stage']==='not_selected','Visual identity defaults off');
echo "TRACKY_CLOUD_VISUAL_ATTRIBUTION_V1F2: consent, device binding, freshness, revocation, no biometrics PASS\n";
