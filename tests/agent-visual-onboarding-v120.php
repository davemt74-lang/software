<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-visual-onboarding-v120.php';
function check_visual(bool $ok,string $why): void{if(!$ok)throw new RuntimeException($why);}
$now=strtotime('2026-10-01T18:30:00+00:00');
$unselected=['feature_interests'=>[]];
$base=vp3_visual_onboarding_status_v120($unselected,[],$now);
check_visual(!$base['selected']&&!$base['consented']&&!$base['enrollment_verified'],'Visual identity defaults off');
$pending=['feature_interests'=>['workflow.visual_profile'=>true]];
$state=vp3_visual_onboarding_status_v120($pending,[],$now);
check_visual($state['selected']&&!$state['consented']&&$state['stage']==='needs_explicit_consent','Selecting is not biometric consent');
$pending['feature_interests']['visual.owner_self.consent_scope']=VP3_VISUAL_CONSENT_SCOPE_V120;
$pending['feature_interests']['visual.owner_self.consented_at']='2026-10-01T18:25:00+00:00';
$site=['site_id'=>'home-1','last_seen_at'=>'2026-10-01T18:28:00+00:00','capabilities'=>['recognition'=>true],'health'=>['camera'=>'healthy']];
$ready=vp3_visual_onboarding_status_v120($pending,[$site],$now);
check_visual($ready['consented']&&$ready['site_ready']&&$ready['stage']==='ready_for_local_enrollment','Canonical live site permits local enrollment');
check_visual(!$ready['enrollment_verified']&&!$ready['cloud_biometric_storage']&&!$ready['tracking_enabled']&&!$ready['contact_creation_enabled'],'No inferred enrollment or privacy escalation');
$stale=vp3_visual_onboarding_status_v120($pending,[array_replace($site,['last_seen_at'=>'2026-10-01T17:50:00+00:00'])],$now);
check_visual(!$stale['site_ready']&&$stale['stage']==='awaiting_local_tracky','Stale heartbeat fails closed');
$missing=vp3_visual_onboarding_status_v120($pending,[array_replace($site,['capabilities'=>['recognition'=>false]])],$now);
check_visual(!$missing['site_ready'],'Camera alone cannot claim recognition readiness');
$revoked=vp3_visual_onboarding_status_v120(['feature_interests'=>array_replace($pending['feature_interests'],['workflow.visual_profile'=>false])],[$site],$now);
check_visual(!$revoked['consented']&&$revoked['stage']==='not_selected','Revocation wins');
echo "Visual onboarding v1.20: opt-in, live capability gate, no false enrollment, revoke PASS\n";
