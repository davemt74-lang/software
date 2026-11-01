<?php
declare(strict_types=1);
// Reuse the real signed webhook and SQLite receipt harness from PH3C.
require __DIR__.'/phone-voice-ph3c-runtime.php';

$checks=0;
$sid='CA'.str_repeat('b',32);
$route=vp3_phone_voice_current_route_v131($pdo,$sid);
check($route['accepted']===true && $route['owner_user_id']===1
    && $route['agent_id']===7 && $route['phone_number_id']===1,'signed receipt resolves to its original tenant');
check($route['authority']==='unauthenticated_caller' && $route['execution']==='disabled',
    'caller ID never grants agent tools or account rights');
check(vp3_phone_voice_current_route_v131($pdo,'CA'.str_repeat('f',32))['reason']==='unknown_call',
    'unknown carrier call has no route');
check(vp3_phone_voice_current_route_v131($pdo,'not-a-sid')['reason']==='invalid_call',
    'malformed carrier identity rejected');
check(vp3_phone_voice_current_route_v131($pdo,$sid,86401)['reason']==='invalid_call',
    'routing age bound cannot be disabled by caller');

$pdo->exec('UPDATE phone_cloud_numbers_v100 SET owner_user_id=2,agent_id=8 WHERE id=1');
check(vp3_phone_voice_current_route_v131($pdo,$sid)['reason']==='assignment_changed',
    'transfer immediately revokes old call route');
$pdo->exec('UPDATE phone_cloud_numbers_v100 SET owner_user_id=1,agent_id=7 WHERE id=1');
$pdo->exec("INSERT INTO user_agents VALUES (9,1,1,'Alternative')");
$pdo->exec('UPDATE phone_cloud_numbers_v100 SET agent_id=9 WHERE id=1');
check(vp3_phone_voice_current_route_v131($pdo,$sid)['reason']==='assignment_changed',
    'same-owner agent switch invalidates original call');
try {inbound($pdo,callForm());throw new RuntimeException('missing rejection');}
catch(RuntimeException $e) {check($e->getMessage()==='Call receipt conflicts with number assignment.',
    'signed carrier replay cannot silently switch active agent');}
$pdo->exec('UPDATE phone_cloud_numbers_v100 SET agent_id=7 WHERE id=1');
$pdo->exec('UPDATE phone_cloud_numbers_v100 SET voice_enabled=0 WHERE id=1');
check(vp3_phone_voice_current_route_v131($pdo,$sid)['reason']==='assignment_changed',
    'disabled voice channel has no route');
$pdo->exec('UPDATE phone_cloud_numbers_v100 SET voice_enabled=1 WHERE id=1');
$pdo->exec("UPDATE phone_cloud_numbers_v100 SET phone_e164='+16025550155' WHERE id=1");
check(vp3_phone_voice_current_route_v131($pdo,$sid)['reason']==='assignment_changed',
    'same number row cannot be repurposed for another DID');
$pdo->exec("UPDATE phone_cloud_numbers_v100 SET phone_e164='+16025550101' WHERE id=1");
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');
check(vp3_phone_voice_current_route_v131($pdo,$sid)['reason']==='account_ineligible',
    'deactivated agent revokes all current calls');
$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=7');
$GLOBALS['phonePlanSuspended']=true;
check(vp3_phone_voice_current_route_v131($pdo,$sid)['reason']==='account_ineligible',
    'subscription lapse revokes voice access mid-call');
$GLOBALS['phonePlanSuspended']=false;
$pdo->exec("UPDATE phone_voice_calls_v130 SET state='completed' WHERE provider_call_sid='".$sid."'");
check(vp3_phone_voice_current_route_v131($pdo,$sid)['reason']==='assignment_changed',
    'completed calls cannot reopen voice routing');
try {inbound($pdo,callForm());throw new RuntimeException('missing rejection');}
catch(RuntimeException $e) {check($e->getMessage()==='Call receipt conflicts with number assignment.',
    'completed call cannot replay an answered callback');}
$pdo->exec("UPDATE phone_voice_calls_v130 SET state='voicemail_offered',created_at='2020-01-01 00:00:00' WHERE provider_call_sid='".$sid."'");
check(vp3_phone_voice_current_route_v131($pdo,$sid)['reason']==='call_expired',
    'stale receipts cannot be used for a new voice session');
$pdo->exec("UPDATE phone_voice_calls_v130 SET created_at=CURRENT_TIMESTAMP WHERE provider_call_sid='".$sid."'");
check(vp3_phone_voice_current_route_v131($pdo,$sid)['accepted']===true,
    'route is restored only when original account and call are current');
echo 'PHONE_VOICE_PH3E_RUNTIME=PASS ('.$checks.' checks)'.PHP_EOL;
