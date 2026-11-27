<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures/phone-agent-email-call-native-v1.php';

$f=pec_fixture_v1();$pdo=$f['pdo'];$now=$f['now'];$owner=$f['owner'];$device=$f['device_id'];
$base=['owner_key'=>'1','agent_id'=>7,'conversation_id'=>11,'csrf_token'=>csrf_token()];
$request=static fn(array $input):array=>vp3_phone_agent_email_calls_request_v1($pdo,$owner,$input+$base,null,$now);
$saved=vp3_phone_agent_email_calls_policy_v1($pdo,1);
pec_check_v1(vp3_phone_agent_email_calls_ready_v1($pdo),'complete policy/request/review/decision schema is ready');
vp3_phone_agent_email_calls_install_v1($pdo);
pec_check_v1(vp3_phone_agent_email_calls_policy_v1($pdo,1)===$saved,'idempotent upgrade preserves explicit owner consent');
$pdo->beginTransaction();$ddlRefused=false;
try{vp3_phone_agent_email_calls_install_v1($pdo);}catch(LogicException){$ddlRefused=true;}
$pdo->rollBack();pec_check_v1($ddlRefused,'installer refuses a caller-owned transaction');
$state=$request(['action'=>'state']);
pec_check_v1($state['ok']&&$state['policy']['urgent_enabled']&&$state['policy']['drafts_enabled']
    &&$state['readiness']['state']==='ready'&&$state['readiness']['reason']==='eligible','real configured owner route is ready');
pec_check_v1($state['owner_phone']['masked_number']==='Ending in 0100'&&$state['owner_phone']['updates_requested']
    &&count($state['devices'])===2&&$state['devices'][0]['lines'][0]['number_id']===1,'owner sees connected devices, assigned line and masked destination');
$json=json_encode($state,JSON_THROW_ON_ERROR);
pec_check_v1(!str_contains($json,'+16025550100')&&!str_contains($json,$saved['credential_hash'])
    &&!str_contains($json,$saved['owner_fingerprint'])&&!str_contains($json,$saved['grant_id']),'settings DTO excludes destination, credentials and grant secrets');
$preferences=VP3OperatorOwnerPhoneV1::read($pdo,$owner);
pec_check_v1(!$preferences['verified']&&!$preferences['execution_enabled'],'explicit email channel does not promote storage-only Account preferences');
pec_check_v1((int)$f['context']['enrollment']['notifications_enabled']===0,'email call permission is independent of push enrollment');
$session=$_SESSION;$_SESSION=[];
pec_check_v1(vp3_phone_agent_email_calls_context_v1($pdo,1,$now)['policy']['device_id']===$device,'background worker uses saved exact grant without manufacturing a session');
pec_reject_v1(fn()=>$request(['action'=>'state']),'settings still require actual owner sign-in','owner_sign_in_required');$_SESSION=$session;
pec_reject_v1(fn()=>$request(['action'=>'state','csrf_token'=>'wrong']),'CSRF cannot be replaced by phone or account identity','session_changed');
pec_reject_v1(fn()=>$request(['action'=>'state','owner_key'=>'2']),'request cannot select a different owner','owner_access_changed');
pec_reject_v1(fn()=>$request(['action'=>'state','conversation_id'=>21]),'foreign private Chat cannot authorize settings');
pec_reject_v1(fn()=>$request(['action'=>'state','send'=>true]),'settings boundary rejects action smuggling','invalid_request');
pec_reject_v1(fn()=>$request(['action'=>'save','expected_revision'=>0,'urgent_enabled'=>true,'drafts_enabled'=>true,
    'grace_seconds'=>300,'selected_device_id'=>$device,'number_id'=>1,'confirmed'=>true]),'parallel stale save is rejected','policy_changed');
pec_reject_v1(fn()=>$request(['action'=>'save','expected_revision'=>1,'urgent_enabled'=>true,'drafts_enabled'=>true,
    'grace_seconds'=>300,'selected_device_id'=>$device,'number_id'=>1,'confirmed'=>false]),'category enable requires explicit recorded-call consent','consent_required');
pec_reject_v1(fn()=>$request(['action'=>'save','expected_revision'=>1,'urgent_enabled'=>true,'drafts_enabled'=>true,
    'grace_seconds'=>0,'selected_device_id'=>$device,'number_id'=>1,'confirmed'=>true]),'zero grace cannot bypass minimum review time','invalid_request');
$phone=vp3_phone_agent_workspace_scope_v1($pdo,$owner,$f['token'],$device,false,$now);
$phoneBase=['device_id'=>$device,'enrollment_revision'=>(int)$phone['enrollment']['revision'],'scope_revision'=>$phone['scope_revision'],
    'agent_id'=>7,'conversation_id'=>11,'csrf_token'=>csrf_token()];
$phoneRequest=static fn(array $input,?string $token=null):array=>vp3_phone_agent_email_calls_request_v1($pdo,$owner,$input+$phoneBase,$token??$f['token'],$now);
$mobile=$phoneRequest(['action'=>'state']);
pec_check_v1($mobile['scope']===vp3_phone_agent_email_calls_phone_envelope_v1($phone),'phone response binds the exact real session, device and enrollment');
pec_reject_v1(fn()=>$phoneRequest(['action'=>'state'],$f['second_token']),'another connected phone credential cannot use this enrollment');
pec_reject_v1(fn()=>$phoneRequest(['action'=>'state','scope_revision'=>str_repeat('f',64)]),'another session binding cannot use the phone settings','workspace_changed');
pec_reject_v1(fn()=>$phoneRequest(['action'=>'state','enrollment_revision'=>2]),'stale phone enrollment response is fenced','workspace_changed');
pec_reject_v1(fn()=>$phoneRequest(['action'=>'state','owner_key'=>'1']),'phone route derives owner and rejects request-selected identity','invalid_request');
pec_check_v1($phoneRequest(['action'=>'statuses','item_refs'=>[]])['items']===[],'empty call status result is separate from canonical Gmail content');
pec_reject_v1(fn()=>$phoneRequest(['action'=>'statuses','item_refs'=>array_fill(0,41,str_repeat('a',32))]),'call status reads are bounded','invalid_request');

$mutate=static function(string $sql,array $params,string $reason,string $label)use($pdo,$now):void{
    $pdo->beginTransaction();
    try{vp3_phone_sql_v1($pdo,$sql,$params);pec_reject_v1(fn()=>vp3_phone_agent_email_calls_context_v1($pdo,1,$now,true),$label,$reason);}
    finally{$pdo->rollBack();}
};
$mutate("UPDATE users SET password_hash='changed' WHERE id=1",[],'enrollment_changed','password rotation revokes saved background owner grant');
$mutate('UPDATE users SET is_active=0 WHERE id=1',[],'owner_access_changed','inactive owner cannot be called through an old grant');
$mutate('UPDATE users SET chat_access=0 WHERE id=1',[],'owner_access_changed','removed Chat permission revokes background channel');
$mutate("UPDATE vp3_phone_devices_v1 SET device_status='revoked' WHERE id=?",[$device],'device_changed','revoked pairing revokes email calls');
$mutate('UPDATE vp3_phone_devices_v1 SET expires_at=? WHERE id=?',[$now,$device],'device_changed','expired pairing revokes email calls');
$mutate('UPDATE vp3_phone_devices_v1 SET token_hash=? WHERE id=?',[str_repeat('a',64),$device],'enrollment_changed','credential rotation fences old enrollment');
$mutate('UPDATE vp3_phone_agent_enrollments_v1 SET enabled=0 WHERE device_id=?',[$device],'workspace_enrollment_required','owner role label alone cannot replace an enabled private enrollment');
$mutate('UPDATE vp3_phone_agent_enrollments_v1 SET grant_id=? WHERE device_id=?',[str_repeat('b',32),$device],'enrollment_changed','re-enrollment requires fresh email call consent');
$mutate('UPDATE vp3_phone_agent_enrollments_v1 SET revision=revision+1 WHERE device_id=?',[$device],'enrollment_changed','changed workspace selection or notification grant fences old channel');
$mutate('UPDATE phone_operator_owner_preferences_v1 SET phone_revision=phone_revision+1 WHERE owner_user_id=1',[],'owner_phone_changed','number rotation cannot inherit earlier consent');
$mutate('UPDATE phone_operator_owner_preferences_v1 SET updates_requested=0 WHERE owner_user_id=1',[],'owner_updates_off','Account requested-update switch remains a master off control');
$mutate('UPDATE vp3_phone_communication_profiles_v1 SET agent_call_consent=1 WHERE device_id=?',[$device],'recipient_permission_required','scheduled-call permission is not spontaneous email-call permission');
$mutate('UPDATE vp3_phone_communication_profiles_v1 SET holder_calls=0 WHERE device_id=?',[$device],'recipient_permission_required','recipient withdrawal prevents background email calls');
$mutate('UPDATE vp3_phone_communication_profiles_v1 SET profile_id=? WHERE device_id=?',[str_repeat('c',32),$device],'recipient_changed','recreated communication profile cannot inherit old email consent');
$mutate('UPDATE vp3_phone_communication_profiles_v1 SET phone_revision=phone_revision+1 WHERE device_id=?',[$device],'recipient_changed','holder number move-away-and-back requires new email consent');
$mutate('UPDATE phone_cloud_numbers_v100 SET provider_sid=? WHERE id=1',['PN'.str_repeat('d',32)],'line_changed','changed carrier line cannot inherit route consent');

$off=$request(['action'=>'save','expected_revision'=>1,'urgent_enabled'=>false,'drafts_enabled'=>false,'grace_seconds'=>300,'confirmed'=>false]);
pec_check_v1($off['policy']['revision']===2&&$off['readiness']['state']==='off','disable-all needs no new permission or ready recipient');
pec_reject_v1(fn()=>vp3_phone_agent_email_calls_context_v1($pdo,1,$now),'disabled channel cannot produce background context','off');
$on=$request(['action'=>'save','expected_revision'=>2,'urgent_enabled'=>true,'drafts_enabled'=>true,'grace_seconds'=>300,
    'selected_device_id'=>$device,'number_id'=>1,'confirmed'=>true]);
$new=vp3_phone_agent_email_calls_policy_v1($pdo,1);
pec_check_v1((int)$new['urgent_since_epoch']===$now&&(int)$new['drafts_since_epoch']===$now,'re-enabled categories stay quiet for historical work');
$request(['action'=>'save','expected_revision'=>3,'urgent_enabled'=>true,'drafts_enabled'=>true,'grace_seconds'=>600,
    'selected_device_id'=>$device,'number_id'=>1,'confirmed'=>true]);
pec_check_v1((int)vp3_phone_agent_email_calls_policy_v1($pdo,1)['urgent_since_epoch']===$now,'grace edit preserves original category cutover');
vp3_phone_transaction_v1($pdo,static function()use($pdo):array{vp3_phone_agent_email_calls_revoke_line_v1($pdo,1);return [];});
$revoked=vp3_phone_agent_email_calls_policy_v1($pdo,1);
pec_check_v1(!(bool)$revoked['urgent_enabled']&&!(bool)$revoked['drafts_enabled']&&$revoked['decision_code']==='line_changed','existing line reassignment hook permanently revokes both categories');
pec_session_v1(2);$other=vp3_phone_agent_email_calls_request_v1($pdo,pec_user_v1(2),['action'=>'state','owner_key'=>'2','agent_id'=>9,'conversation_id'=>21,'csrf_token'=>csrf_token()],null,$now);
pec_check_v1($other['policy']['revision']===0&&!$other['policy']['urgent_enabled']&&!$other['policy']['drafts_enabled']
    &&$other['devices']===[]&&$other['active_call']===null,'another owner starts with both categories off and no private device leakage');

pec_session_v1(1);$e=vp3_phone_agent_workspace_enrollment_v1($pdo,1,$device);$d=vp3_phone_row_v1($pdo,1,$device);
vp3_phone_transaction_v1($pdo,fn()=>vp3_phone_agent_device_grant_v1($pdo,pec_user_v1(1),$d,[
    'device_id'=>$device,'generation'=>(int)$d['generation'],'expected_revision'=>(int)$e['revision'],'enabled'=>true,
    'confirmed'=>true,'agent_id'=>7,'notifications_enabled'=>false],$now));
$_SESSION=['csrf_token'=>'paired-email-call-csrf'];
$pairedScope=vp3_phone_agent_workspace_scope_v1($pdo,null,$f['token'],$device,false,$now);
$pairedBase=['device_id'=>$device,'enrollment_revision'=>(int)$pairedScope['enrollment']['revision'],'scope_revision'=>$pairedScope['scope_revision'],
    'agent_id'=>7,'conversation_id'=>(int)$pairedScope['enrollment']['conversation_id'],'csrf_token'=>$_SESSION['csrf_token']];
$pairedCalls=static fn(array $body):array=>vp3_phone_agent_email_calls_request_v1($pdo,null,$body+$pairedBase,$f['token'],$now);
pec_check_v1($pairedCalls(['action'=>'state'])['scope']===vp3_phone_agent_email_calls_phone_envelope_v1($pairedScope)
    &&!isset($_SESSION['user_id'],$_SESSION['auth_password_fingerprint']),'approved no-login phone reads its exact email-call review scope without authenticating the main app');
pec_check_v1($pairedCalls(['action'=>'statuses','item_refs'=>[]])['items']===[],'no-login phone keeps exact-item call status polling available');
pec_reject_v1(fn()=>$pairedCalls(['action'=>'save','expected_revision'=>(int)$revoked['revision'],'urgent_enabled'=>true,'drafts_enabled'=>true,
    'grace_seconds'=>300,'selected_device_id'=>$device,'number_id'=>1,'confirmed'=>true]),
    'delegated phone access cannot enable autonomous calling or change main-account call policy','owner_access_changed');

pec_check_v1($GLOBALS['pecHttpCalls']===0,'settings and revocation tests never contact Gmail or a carrier');
echo 'Email-call settings acceptance: '.$GLOBALS['pecChecks']." assertions passed.\n";
