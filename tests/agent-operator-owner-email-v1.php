<?php
declare(strict_types=1);

require __DIR__.'/fixtures/phone-agent-email-call-operator-v1.php';
require_once dirname(__DIR__).'/includes/agent-operator-outcome-chat-v1.php';
require_once dirname(__DIR__).'/includes/agent-operator-history-v1.php';

/** Each scenario has an independent native call history; all data is in the private test database. */
function oec_clear_calls_v1(PDO $pdo): void
{
    foreach(['phone_agent_email_call_reviews_v1','phone_browser_deliveries_v1','phone_voice_turns_v140',
        'phone_browser_calls_v1','phone_operator_carrier_attempts_v1','phone_operator_occurrences_v1',
        'phone_operator_scheduled_assignments_v1','phone_agent_email_call_requests_v1','user_calendar_events'] as $table)
        $pdo->exec('DELETE FROM '.$table);
}
function oec_candidate_v1(array $f,string $message,bool $draft=false,int $chat=11): array
{
    $id=$draft?pec_draft_v1($f,$message,['conversation_id'=>$chat])['id']:pec_attention_v1($f,$message);
    $kind=$draft?'gmail_draft':'gmail_attention';
    $context=vp3_phone_agent_email_calls_context_v1($f['pdo'],1,time());
    foreach(vp3_phone_agent_email_call_candidates_v1($f['pdo'],$context,time()) as $candidate)
        if($candidate['source_kind']===$kind&&$candidate['source_id']===$id)return $candidate;
    throw new RuntimeException('Missing native fixture candidate: '.$message);
}
function oec_occurrence_v1(PDO $pdo,array $request): array
{
    return vp3_phone_sql_v1($pdo,'SELECT * FROM phone_operator_occurrences_v1 WHERE owner_user_id=? AND event_id=? AND start_epoch=?',
        [(int)$request['owner_user_id'],(int)$request['event_id'],(int)$request['start_epoch']])->fetch(PDO::FETCH_ASSOC);
}
function oec_limits_v1(array $f): array
{
    $c=vp3_phone_agent_email_calls_context_v1($f['pdo'],1,time());
    return vp3_operator_admission_history_v1($f['pdo'],$c['scope'],['contact'=>['destination_e164'=>$c['preferences']['phone_e164']]],$c['preferences'],time());
}
function oec_snapshot_ineligible_v1(callable $read): bool
{
    try{return ($read()['reason']??null)!=='eligible';}
    catch(PDOException $error){if((int)($error->errorInfo[1]??0)!==1020)throw $error;return true;}
}

$f=pec_fixture_v1();$pdo=$f['pdo'];$now=time();
pec_check_v1(vp3_operator_owner_email_readiness_v1($pdo,$f['context'],$now)['reason']==='eligible',
    'owner email route uses real Account, holder, private Chat and native Operator prerequisites');
pec_check_v1(!table_exists('crm_contacts'),'native owner-work fixture has no CRM contact table');
$candidate=oec_candidate_v1($f,'urgent_without_decision');
$fresh=vp3_phone_agent_email_call_refresh_v1($pdo,$f['context'],$candidate,time(),'pec_http_v1');
$unapproved=vp3_operator_owner_email_prepare_v1($pdo,$f['context'],$candidate,time());
pec_check_v1($fresh['reason']==='eligible'&&$unapproved['state']==='blocked'&&$unapproved['reason']==='decision_required'
    &&(int)$pdo->query('SELECT COUNT(*) FROM user_calendar_events')->fetchColumn()===0,
    'fresh Gmail evidence alone cannot prepare a call without a completed Main Agent usefulness decision');
$request=pec_operator_prepare_v1($f,$candidate);$occurrence=oec_occurrence_v1($pdo,$request);
$event=user_calendar_event_v1300($pdo,1,(int)$request['event_id']);
$assignment=vp3_phone_sql_v1($pdo,'SELECT * FROM phone_operator_scheduled_assignments_v1 WHERE owner_user_id=1 AND event_id=?',[(int)$event['id']])->fetch(PDO::FETCH_ASSOC);
$plan=vp3_operator_preview_request_v1($pdo,$occurrence,time());
pec_check_v1($plan['reason']==='eligible'&&(int)$assignment['contact_id']===0&&(int)$occurrence['contact_id']===0
    &&$event['source_reference']==='operator-owner-email:'.$request['id']&&$event['recurrence']==='none',
    'owner-work uses one typed Calendar event, zero-contact native assignment and canonical occurrence');
$stored=json_encode([$request,$event,$assignment,$plan],JSON_THROW_ON_ERROR);
pec_check_v1(!str_contains($stored,'BODY_SENTINEL')&&!str_contains($stored,'Private fixture subject')
    &&!str_contains($stored,'sender@example.test')&&str_contains($event['description'],'Do not disclose email content'),
    'request and Calendar persist references and a generic review purpose without email content');
$again=vp3_operator_owner_email_prepare_v1($pdo,$f['context'],$candidate,time());
pec_check_v1($again['request_id']===$request['id']&&(int)$pdo->query('SELECT COUNT(*) FROM phone_agent_email_call_requests_v1')->fetchColumn()===1
    &&(int)$pdo->query('SELECT COUNT(*) FROM user_calendar_events')->fetchColumn()===1,
    'repeated preparation consumes one owner-wide work episode without a second Calendar event');

$mutations=[
    ['Account updates withdrawal',"UPDATE phone_operator_owner_preferences_v1 SET updates_requested=0 WHERE owner_user_id=1"],
    ['holder spontaneous-call withdrawal',"UPDATE vp3_phone_communication_profiles_v1 SET agent_call_consent=0 WHERE device_id=".$pdo->quote($f['device_id'])],
    ['holder consent revision change',"UPDATE vp3_phone_communication_profiles_v1 SET revision=revision+1 WHERE device_id=".$pdo->quote($f['device_id'])],
    ['owner credential change',"UPDATE users SET password_hash='changed-password' WHERE id=1"],
    ['device credential rotation',"UPDATE vp3_phone_devices_v1 SET token_hash='".str_repeat('d',64)."' WHERE id=".$pdo->quote($f['device_id'])],
    ['private enrollment withdrawal',"UPDATE vp3_phone_agent_enrollments_v1 SET enabled=0 WHERE device_id=".$pdo->quote($f['device_id'])],
    ['private Chat owner change',"UPDATE chat_conversations SET user_id=2 WHERE id=11"],
    ['Gmail read withdrawal',"UPDATE agent_connections_v1 SET read_enabled=0 WHERE owner_user_id=1"],
    ['line permission withdrawal',"UPDATE phone_cloud_numbers_v100 SET voice_enabled=0 WHERE id=1"],
    ['saved policy revision change',"UPDATE phone_agent_email_call_policies_v1 SET revision=revision+1 WHERE owner_user_id=1"],
    ['owner calling-hours change',"UPDATE phone_operator_owner_preferences_v1 SET window_start='00:00',window_end='00:01' WHERE owner_user_id=1"],
    ['holder calling-hours change',"UPDATE vp3_phone_communication_profiles_v1 SET window_start='00:00',window_end='00:01' WHERE device_id=".$pdo->quote($f['device_id'])],
    ['native assignment Chat change',"UPDATE phone_operator_scheduled_assignments_v1 SET conversation_id=12 WHERE event_id=".(int)$request['event_id']],
    ['native Calendar edit',"UPDATE user_calendar_events SET description='Changed purpose' WHERE id=".(int)$request['event_id']],
    ['existing destination opt-out',"INSERT INTO phone_operator_recipient_optouts_v1(owner_user_id,destination_hash,withdrawn_epoch) VALUES(1,'".hash('sha256','+16025550100')."',".time().")"],
];
foreach($mutations as [$label,$sql]){
    $pdo->beginTransaction();
    try{$pdo->exec($sql);$current=vp3_operator_preview_request_v1($pdo,$occurrence,time());
        pec_check_v1($current['reason']!=='eligible',$label.' blocks the exact prepared call');}
    finally{$pdo->rollBack();}
}

$peer=new PDO((string)getenv('VP3_TEST_MYSQL_DSN').';dbname='.$f['database'],getenv('VP3_TEST_MYSQL_USER')?:'root',
    (string)getenv('VP3_TEST_MYSQL_PASS'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$pdo->beginTransaction();
try{
    $pdo->query('SELECT description FROM user_calendar_events')->fetchAll();
    $peer->prepare("UPDATE user_calendar_events SET description='Peer committed new purpose' WHERE id=?")->execute([(int)$event['id']]);
    pec_check_v1(oec_snapshot_ineligible_v1(fn()=>vp3_operator_owner_email_plan_v1($pdo,$occurrence,time())),
        'current Calendar locking read rejects peer edits hidden by an older transaction snapshot');
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    $peer->prepare('UPDATE user_calendar_events SET description=?,updated_at=? WHERE id=?')->execute([$event['description'],$event['updated_at'],(int)$event['id']]);
}
$pdo->beginTransaction();
try{
    $pdo->query('SELECT destination_hash FROM phone_operator_recipient_optouts_v1')->fetchAll();
    $peer->prepare('INSERT INTO phone_operator_recipient_optouts_v1(owner_user_id,destination_hash,withdrawn_epoch) VALUES(1,?,?)')
        ->execute([hash('sha256','+16025550100'),time()]);
    pec_check_v1(oec_snapshot_ineligible_v1(fn()=>vp3_operator_owner_email_plan_v1($pdo,$occurrence,time())),
        'current opt-out read rejects peer withdrawal hidden by an older transaction snapshot');
}finally{if($pdo->inTransaction())$pdo->rollBack();$peer->exec('DELETE FROM phone_operator_recipient_optouts_v1');}

// Readiness is rechecked after the committed attempt, immediately before carrier I/O.
$calls=0;$changed=false;
$revokingHttp=static function(string $method,string $url,array $headers,?array $body,bool $form)use($pdo,&$changed):array{
    if(!$changed){$changed=true;$pdo->exec('UPDATE phone_operator_owner_preferences_v1 SET updates_requested=0 WHERE owner_user_id=1');}
    return pec_http_v1($method,$url,$headers,$body,$form);
};
$neverCarrier=static function()use(&$calls):array{++$calls;throw new LogicException('Known veto reached carrier.');};
$veto=vp3_operator_carrier_attempt_v1($pdo,1,(int)$request['event_id'],(int)$request['start_epoch'],$neverCarrier,null,$revokingHttp);
$pdo->exec('UPDATE phone_operator_owner_preferences_v1 SET updates_requested=1 WHERE owner_user_id=1');
pec_check_v1($changed&&$veto==='blocked'&&$calls===0&&vp3_operator_owner_email_record_v1($pdo,1,$request['id'])['state']==='blocked',
    'permission revoked during final Gmail refresh records a known pre-carrier veto and makes zero calls');
pec_check_v1(vp3_operator_carrier_attempt_v1($pdo,1,(int)$request['event_id'],(int)$request['start_epoch'],$neverCarrier,null,'pec_http_v1')==='blocked'
    &&$calls===0&&oec_limits_v1($f)['today']===0,
    'known veto is immutable and consumes the episode without charging a call-frequency attempt');

oec_clear_calls_v1($pdo);
$candidate=oec_candidate_v1($f,'uncertain_submission');$request=pec_operator_prepare_v1($f,$candidate);$calls=0;$nested=null;
$transport=static function(array $plan)use(&$calls,&$nested,$pdo,$request,$neverCarrier):array{
    ++$calls;
    $nested=vp3_operator_carrier_attempt_v1($pdo,1,(int)$request['event_id'],(int)$request['start_epoch'],$neverCarrier,null,'pec_http_v1');
    throw new RuntimeException('Simulated response loss after carrier dispatch.');
};
$uncertain=vp3_operator_carrier_attempt_v1($pdo,1,(int)$request['event_id'],(int)$request['start_epoch'],$transport,null,'pec_http_v1');
pec_check_v1($uncertain==='uncertain'&&$calls===1&&$nested==='submitting'
    &&vp3_operator_owner_email_record_v1($pdo,1,$request['id'])['state']==='uncertain',
    'one committed native attempt fences a concurrent worker and preserves carrier uncertainty');
pec_check_v1(vp3_operator_carrier_attempt_v1($pdo,1,(int)$request['event_id'],(int)$request['start_epoch'],$neverCarrier,null,'pec_http_v1')==='uncertain'
    &&$calls===1&&oec_limits_v1($f)['today']===1&&!vp3_operator_admission_idle_v1($pdo,1,time()),
    'uncertain submission cannot redial and still counts toward owner frequency and busy limits');

oec_clear_calls_v1($pdo);
$candidate=oec_candidate_v1($f,'review_draft_source',true,12);$beforeHttp=$GLOBALS['pecHttpCalls'];
$active=pec_operator_active_v1($f,$candidate);$request=$active['request'];$form=$active['form'];$sid=$active['sid'];
pec_check_v1($GLOBALS['pecHttpCalls']>$beforeHttp&&$GLOBALS['pecCarrierCalls']===1&&$GLOBALS['pecRecordingCalls']===1
    &&$request['state']==='submitted'&&$request['reason']==='answer_consented',
    'exact draft passes native carrier, disclosure and recording handshake with one accepted call');
pec_check_v1((int)$request['conversation_id']===12&&(int)$active['call']['conversation_id']===12
    &&(int)$active['plan']['conversation_id']===12&&(int)$f['context']['scope']['conversation_id']===11,
    'native call and review bind the source private Chat while enrollment primary Chat remains distinct');
pec_check_v1(vp3_operator_owner_email_active_v1($pdo,$f['context'],str_repeat('0',32),time())===null,
    'an unrelated request cannot reuse this answered carrier leg');
pec_check_v1(oec_limits_v1($f)['today']===1&&vp3_operator_owner_email_plan_v1($pdo,oec_occurrence_v1($pdo,$request),time(),$sid)['reason']==='eligible',
    'accepted call counts once while its own exact SID is excluded from active-call continuation gates');
$pdo->beginTransaction();
try{
    $pdo->prepare("UPDATE agent_connection_email_drafts_v1 SET status='sending' WHERE id=?")->execute([$candidate['source_id']]);
    $before=$GLOBALS['pecHttpCalls'];$controls=vp3_operator_owner_email_control_v1($pdo,$f['context'],$request,time(),$sid);
    pec_check_v1($controls['sid']===$sid&&$controls['plan']['reason']==='eligible'&&$GLOBALS['pecHttpCalls']===$before,
        'already armed exact review can recheck native controls during its own sending transition without provider reads');
    pec_check_v1(!vp3_operator_owner_email_sending_v1($pdo,$f['context'],$request,$sid,time())
        &&vp3_operator_owner_email_active_v1($pdo,$f['context'],$request['id'],time())===null,
        'a sending draft without an authenticated exact review claim cannot bypass active source validation');
}finally{$pdo->rollBack();}
foreach([
    ['recording preference',"UPDATE phone_recording_policies_v1 SET transcribe=0 WHERE owner_user_id=1 AND agent_id=7"],
    ['line recording permission',"UPDATE phone_browser_numbers_v1 SET recording_enabled=0 WHERE phone_number_id=1"],
    ['Calendar objective',"UPDATE user_calendar_events SET description='Changed after review' WHERE id=".(int)$request['event_id']],
    ['assignment',"UPDATE phone_operator_scheduled_assignments_v1 SET enabled=0 WHERE event_id=".(int)$request['event_id']],
    ['destination opt-out',"INSERT INTO phone_operator_recipient_optouts_v1(owner_user_id,destination_hash,withdrawn_epoch) VALUES(1,'".hash('sha256','+16025550100')."',".time().")"],
] as [$label,$sql]){
    $pdo->beginTransaction();
    try{$pdo->exec($sql);pec_reject_v1(fn()=>vp3_operator_owner_email_control_v1($pdo,$f['context'],$request,time(),$sid),
        'exact review control fence rejects changed '.$label);}
    finally{$pdo->rollBack();}
}

$bad=$form;$bad['To']='+16025550199';
pec_check_v1(pec_operator_answer_v1($f,$bad,'answer')===vp3_phone_browser_hangup_v1(),
    'a correctly signed callback with a different destination cannot disclose the owner review');
pec_reject_v1(fn()=>vp3_operator_answer_v1($pdo,'answer',$form,'wrong-signature',time()),
    'invalid carrier signature cannot reach source or review authority');
$generations=0;$generator=static function()use(&$generations):array{++$generations;return ['ok'=>true,'answer'=>'SHOULD_NOT_SPEAK'];};
$turn=pec_operator_answer_v1($f,$form,'turn1',['SpeechResult'=>'Yes, send the email.'], $generator);
pec_check_v1($generations===0&&str_contains($turn,'event=turn2')&&str_contains($turn,'signed-in review')
    &&!str_contains($turn,'BODY_SENTINEL')&&!str_contains($turn,'SHOULD_NOT_SPEAK')
    &&(int)$pdo->query('SELECT COUNT(*) FROM agent_connection_sends_v1')->fetchColumn()===0,
    'generic spoken yes never invokes legacy private inference or Gmail send');
pec_check_v1(pec_operator_answer_v1($f,$form,'turn1',['SpeechResult'=>'Yes, send the email.'],$generator)===$turn&&$generations===0,
    'an exact repeated carrier turn replays its saved generic response without new work');

$grant=$f['context']['enrollment']['grant_id'];
$pdo->prepare('INSERT INTO vp3_phone_agent_receipts_v1(owner_user_id,device_id,grant_id,item_ref,source_revision,revision,read_epoch,updated_epoch)
    VALUES(1,?,?,?,?,1,?,?)')->execute([$f['device_id'],$grant,$candidate['item_ref'],$candidate['source_revision'],time(),time()]);
pec_check_v1(vp3_phone_agent_email_call_source_current_v1($pdo,$f['context'],$request,time(),'submit')['reason']==='owner_read'
    &&vp3_operator_owner_email_active_v1($pdo,$f['context'],$request['id'],time())!==null,
    'opening the exact item suppresses new escalation but preserves its already consented review call');
pec_check_v1(vp3_operator_history_owner_email_v1($pdo,$active['call']),
    'historical Gmail-review identity follows the accepted native receipt without a CRM contact');
$pdo->prepare('UPDATE agent_connection_email_drafts_v1 SET message_enc=? WHERE id=?')
    ->execute([ai_encrypt_secret(json_encode(['subject'=>'Re: Private fixture subject','body'=>'Changed draft','composer'=>['version'=>1,'saved_at'=>time()]],JSON_THROW_ON_ERROR)),$candidate['source_id']]);
$after=pec_operator_answer_v1($f,$form,'turn2',['SpeechResult'=>'Please continue'],$generator);
pec_check_v1($after===vp3_phone_browser_hangup_v1()&&$generations===0
    &&vp3_operator_owner_email_record_v1($pdo,1,$request['id'])['reason']==='conversation_reply_withheld',
    'editing the canonical draft invalidates further source disclosure during the call');
pec_operator_answer_v1($f,$form,'status',['CallStatus'=>'completed']);
$ended=vp3_operator_owner_email_record_v1($pdo,1,$request['id']);
pec_check_v1($ended['state']==='ended'&&$ended['reason']==='conversation_reply_withheld',
    'signed terminal receipt preserves the true stopped-call outcome after source changes');
$pdo->exec('UPDATE agent_connections_v1 SET read_enabled=0 WHERE owner_user_id=1');
$notices=vp3_operator_outcome_chat_tick_v1($pdo,time());$repeat=vp3_operator_outcome_chat_tick_v1($pdo,time());
$notice=$pdo->query("SELECT message,conversation_id FROM chat_messages WHERE message LIKE 'Gmail review phone call%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
pec_check_v1($notices['delivered']===1&&$repeat['delivered']===0&&(int)$notice['conversation_id']===12
    &&!str_contains($notice['message'],'BODY_SENTINEL'),
    'terminal outcome reaches its original private Chat once, even after Gmail read permission ends');
$pdo->exec('UPDATE agent_connections_v1 SET read_enabled=1 WHERE owner_user_id=1');

oec_clear_calls_v1($pdo);
$candidate=oec_candidate_v1($f,'expired_call');$request=pec_operator_prepare_v1($f,$candidate);
pec_check_v1(vp3_operator_expire_prepared_v1($pdo,(int)$request['end_epoch'])===1
    &&vp3_operator_owner_email_record_v1($pdo,1,$request['id'])['state']==='missed'
    &&(int)$pdo->query('SELECT COUNT(*) FROM phone_operator_carrier_attempts_v1')->fetchColumn()===0,
    'expired prepared review closes as missed without manufacturing a carrier attempt');
pec_check_v1(vp3_operator_expire_prepared_v1($pdo,(int)$request['end_epoch']+1)===0,
    'expired episode is final and cannot be repeatedly prepared by the scheduler');

oec_clear_calls_v1($pdo);
$candidate=oec_candidate_v1($f,'recipient_withdrawal',true);$active=pec_operator_active_v1($f,$candidate);
$response=pec_operator_answer_v1($f,$active['form'],'turn1',['SpeechResult'=>'Stop calling me.'],$generator);
pec_check_v1(str_contains($response,'I will stop calling this number.')&&!str_contains($response,'<Gather')
    &&$generations===0&&vp3_operator_recipient_opted_out_v1($pdo,1,hash('sha256','+16025550100'))
    &&vp3_operator_owner_email_record_v1($pdo,1,$active['request']['id'])['reason']==='conversation_opted_out',
    'exact recipient stop-calling request persists the canonical destination opt-out before any voice review hook');
pec_check_v1(vp3_operator_owner_email_readiness_v1($pdo,$f['context'],time())['reason']==='recipient_opted_out'
    &&(int)$pdo->query('SELECT COUNT(*) FROM agent_connection_sends_v1')->fetchColumn()===0,
    'recipient withdrawal suppresses later owner-email calls without creating Gmail authority');
pec_operator_answer_v1($f,$active['form'],'status',['CallStatus'=>'completed']);
pec_check_v1(vp3_operator_owner_email_record_v1($pdo,1,$active['request']['id'])['state']==='ended'
    &&vp3_operator_recording_saved_policy_v1($pdo,$active['call'],$active['recording_sid'])['transcribe'],
    'consented historical recording and terminal outcome remain verifiable after recipient withdrawal');

vp3_phone_assign_v100($pdo,1,2,9);
$revoked=vp3_phone_agent_email_calls_policy_v1($pdo,1);
vp3_phone_assign_v100($pdo,1,1,7);
pec_check_v1((int)$revoked['urgent_enabled']===0&&(int)$revoked['drafts_enabled']===0
    &&(int)$revoked['consent_epoch']===0&&(int)$revoked['revision']>(int)$f['context']['policy']['revision']
    &&vp3_phone_agent_email_calls_policy_v1($pdo,1)['decision_code']==='line_changed',
    'actual assigned-line transfer and move-back revoke old email-call consent instead of reviving it');

echo 'PASS native owner-email Operator checks: '.$GLOBALS['pecChecks'].PHP_EOL;
