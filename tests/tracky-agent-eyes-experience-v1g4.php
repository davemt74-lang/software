<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/tracky-cloud-v270.php';
require_once dirname(__DIR__).'/includes/tracky-agent-v271.php';
function eyesCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$now=microtime(true);$site=['site_id'=>'a','device_id'=>'device-a','label'=>'Office','status'=>'healthy','last_seen_at'=>gmdate('c',(int)$now)];
$summary=['protocol'=>TRACKY_SCENE_V1G3D,'state'=>'available','reason'=>'recent_observation','observed_at'=>gmdate('c',(int)$now),'room_id'=>'office','objects'=>['chair'],'setting'=>'indoor','lighting'=>'bright','owner_corrections'=>[]];
$scene=tracky_scene_project_v1g3d($summary,$now);$status=tracky_eyes_experience_v1g4($site,$scene,$now);
eyesCheck($status['state']==='available'&&$status['connection']==='recent_contact'&&str_contains($status['meaning'],'chair'),'Current checked meaning missing');
eyesCheck(!$status['local_consent_known']&&!$status['pending_revocation_known']&&!$status['capture_authority'],'Cloud invented local authority');
foreach([60,61,INF,-1,'bad'] as $age){$s=tracky_eyes_experience_v1g4($site,array_replace($scene,['age_seconds'=>$age]),$now);eyesCheck(!str_contains($s['meaning'],'chair')&&$s['state']!=='available','Expired or invalid scene retained labels');}
$expired=tracky_scene_project_v1g3d($summary,$now+61);$s=tracky_eyes_experience_v1g4($site,$expired,$now+61);
eyesCheck($s['title']==='Scene expired'&&$s['observed_at']===$summary['observed_at']&&!str_contains($s['meaning'],'chair'),'Expired scene lost time or leaked meaning');
$s=tracky_eyes_experience_v1g4($site,['state'=>'revoked','reason'=>'owner_revoked'],$now);eyesCheck($s['consent']==='revoked_confirmed'&&str_contains($s['title'],'confirmed'),'Revocation receipt misrepresented');
$s=tracky_eyes_experience_v1g4($site,[],$now);eyesCheck($s['consent']==='not_reported'&&str_contains($s['guidance'],'cannot tell'),'Missing state called disabled/setup failed');
foreach(['owner_approval_required','model_review_required','privacy_enabled','session_stopped','owner_presence_expired','no_session','observation_missing','evidence_invalid'] as $reason){$s=tracky_eyes_experience_v1g4($site,['state'=>'unavailable','reason'=>$reason],$now);eyesCheck($s['title']!==''&&!str_contains($s['meaning'],'chair'),'Unavailable reason missing');}
foreach(['offline','disabled','failed'] as $state)eyesCheck(tracky_eyes_experience_v1g4(array_replace($site,['status'=>$state]),[],$now)['connection']==='disconnected','Recorded disconnection hidden');
eyesCheck(tracky_eyes_experience_v1g4($site,[],$now+301)['connection']==='contact_stale','Stale contact shown recent');
eyesCheck(tracky_eyes_experience_v1g4($site,[],$now-10)['connection']==='unknown','Future heartbeat shown recent');
eyesCheck(tracky_eyes_experience_v1g4(null,[],$now)['connection']==='not_reported','No site not explained');
foreach(['Is Agent Eyes working?','Is scene sharing off?'] as $q)eyesCheck(tracky_agent_intent_v271($q)==='current','Agent status intent missing');
$answer=tracky_agent_answer_v271(['context'=>[],'agent_eyes_status'=>tracky_eyes_experience_v1g4($site,[],$now)],'current');eyesCheck(str_contains($answer,'No scene has been shared')&&str_contains($answer,'waiting for acknowledgment'),'Agent response omitted consent uncertainty');
echo "TRACKY_AGENT_EYES_EXPERIENCE_V1G4: state/connection/review/privacy/recovery/consent uncertainty, original time and Agent response PASS\n";
