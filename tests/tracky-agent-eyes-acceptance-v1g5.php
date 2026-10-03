<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/tracky-cloud-v270.php';
function acceptanceCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$input=['connection'=>'recent_contact','state'=>'available','reason'=>'recent_observation','consent'=>'last_report_received','observed_at'=>'2026-10-03T00:00:00Z','meaning'=>'PRIVATE_OBJECT_REPORT','device_id'=>'SECRET_DEVICE','site_label'=>'SECRET_PERSON','room_id'=>'SECRET_ROOM','owner_corrections'=>['PRIVATE_REPORT']];
$r=tracky_eyes_acceptance_report_v1g5([$input]);$json=json_encode($r,JSON_THROW_ON_ERROR);
foreach(['PRIVATE_OBJECT_REPORT','SECRET_DEVICE','SECRET_PERSON','SECRET_ROOM','PRIVATE_REPORT'] as $secret)acceptanceCheck(!str_contains($json,$secret),'Acceptance export retained private meaning/identity');
acceptanceCheck(!$r['hardware_certified']&&!$r['capture_authority']&&!$r['automatic_recovery'],'Cloud report claimed installed authority');
acceptanceCheck($r['checks'][0]['installed_device_acceptance']==='not_verifiable_from_cloud'&&!$r['checks'][0]['current_local_consent_known'],'Cloud inferred local acceptance or unsynced consent');
acceptanceCheck(count(tracky_eyes_acceptance_report_v1g5(array_fill(0,30,$input))['checks'])===20,'Report bounds missing');
foreach(['available','unavailable','revoked','never_shared'] as $state){$r=tracky_eyes_acceptance_report_v1g5([array_replace($input,['state'=>$state])]);acceptanceCheck($r['checks'][0]['scene_state']===$state&&!$r['scene_meaning_retained'],'Snapshot state altered or retained meaning');}
echo "TRACKY_AGENT_EYES_ACCEPTANCE_V1G5: redacted bounded status export, unchanged scene state and no local consent/hardware authority PASS\n";
