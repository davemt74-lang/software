<?php
declare(strict_types=1);
require __DIR__.'/operator-schedule-history-v1.php';

// Restore the series to active after the history fixture and reassign its current objectives.
$pdo->prepare("UPDATE user_calendar_events SET status='active' WHERE id=?")->execute([$eventId]);
$current=$read();
$choice=array_replace($choice,['expected_revision'=>$current['revision'],'assignment_revision'=>$current['assignment_revision']]);
action('assign_scheduled_operator',$choice);
$due=(int)$pdo->query('SELECT next_due_epoch FROM phone_operator_scheduled_assignments_v1')->fetchColumn();
$event=event($eventId);
$duration=strtotime($event['end_at_utc'].' UTC')-strtotime($event['start_at_utc'].' UTC');
$assignment=$pdo->query('SELECT * FROM phone_operator_scheduled_assignments_v1')->fetch();
$reason=static fn()=>vp3_operator_occurrence_calendar_reason_v1($pdo,$assignment,$due,$due+$duration,$due+5);
c($reason()==='awaiting_dispatch_checks','the assigned recurrence is excluded from its own calendar check');
$pdo->prepare("INSERT INTO user_calendar_events(owner_user_id,title,start_at_utc,end_at_utc) VALUES(1,'Private appointment',?,?)")
    ->execute([gmdate('Y-m-d H:i:s',$due+$duration),gmdate('Y-m-d H:i:s',$due+$duration+60)]);
$other=(int)$pdo->lastInsertId();
$sql=$pdo->prepare('UPDATE user_calendar_events SET start_at_utc=?,end_at_utc=? WHERE id=?');
$sql->execute([gmdate('Y-m-d H:i:s',$due+$duration),gmdate('Y-m-d H:i:s',$due+$duration+60),$other]);
c($reason()==='awaiting_dispatch_checks','an adjacent event does not overlap');
$sql->execute([gmdate('Y-m-d H:i:s',$due),gmdate('Y-m-d H:i:s',$due+$duration),$other]);
c($reason()==='calendar_conflict','an unrelated event with identical times blocks the call');
$pdo->prepare("UPDATE user_calendar_events SET status='cancelled' WHERE id=?")->execute([$other]);
ddl("CREATE TABLE agent_scheduling_bookings(id INTEGER PRIMARY KEY,owner_user_id INTEGER,status VARCHAR(24),start_at_utc DATETIME,end_at_utc DATETIME,event_title VARCHAR(190),guest_name VARCHAR(120),location_value VARCHAR(190),organizer_timezone VARCHAR(80),source VARCHAR(32),schedule_id INTEGER)");
$pdo->prepare("INSERT INTO agent_scheduling_bookings VALUES(?,1,'confirmed',?,?,'Booking','','','UTC','user',1)")
    ->execute([$eventId,gmdate('Y-m-d H:i:s',$due),gmdate('Y-m-d H:i:s',$due+$duration)]);
c($reason()==='calendar_conflict','a booking with the same id and interval as the call still blocks');
$missing=array_replace($assignment,['conversation_id'=>21]);
c(vp3_operator_occurrence_calendar_reason_v1($pdo,$missing,$due,$due+$duration,$due)==='chat_unavailable','a foreign Chat is blocked');
$future=$due+4*7*86400;
$pdo->prepare('UPDATE vp3_phone_devices_v1 SET expires_at=?')->execute([$future+86400]);
$pdo->prepare('UPDATE phone_operator_scheduled_assignments_v1 SET next_due_epoch=? WHERE event_id=?')->execute([$future,$eventId]);
$pdo->prepare('UPDATE agent_scheduling_bookings SET start_at_utc=?,end_at_utc=? WHERE id=?')
    ->execute([gmdate('Y-m-d H:i:s',$future),gmdate('Y-m-d H:i:s',$future+$duration),$eventId]);
c(vp3_operator_occurrences_tick_v1($pdo,$future+1)['blocked']===1,'worker blocks the due call on a later booking');
c($read()['last_occurrence']['reason']==='calendar_conflict','the private schedule history explains the collision');
c(array_sum(vp3_operator_occurrences_tick_v1($pdo,$future+1))===0,'a repeated worker tick cannot add a second outcome');
echo "PASS scheduled call Calendar conflicts, adjacency and Chat revalidation\n";

require_once dirname(__DIR__).'/includes/agent-operator-dispatch-preview-v1.php';
$pdo->exec('DELETE FROM agent_scheduling_bookings');
vp3_operator_auto_call_ensure_schema_v1($pdo);
vp3_phone_recording_ensure_schema_v1($pdo);
vp3_phone_browser_number_policy_set_v1($pdo,1,true,true);
vp3_phone_recording_save_policy_v1($pdo,user(),7,['enabled'=>true,'retain_audio'=>true,'transcribe'=>true,'analyze'=>false]);
$scope=vp3_phone_workspace_scope_v1($pdo,user(),['agent_id'=>0,'conversation_id'=>11]);
$callTime=$future+7*86400;
$pdo->prepare('UPDATE vp3_phone_devices_v1 SET expires_at=?')->execute([$callTime+86400]);
$pdo->exec("UPDATE vp3_phone_communication_profiles_v1 SET window_start='00:00',window_end='23:59',timezone='UTC'");
c(vp3_operator_occurrences_tick_v1($pdo,$callTime)['prepared']===1,'fixture prepares the following weekly occurrence');
$preview=fn()=>vp3_operator_preview_due_v1($pdo,$callTime);
c($preview()===['owner_permission'=>1],'preparation alone cannot authorize an outbound request');
$callingPolicy=array_replace(vp3_operator_auto_call_defaults_v1(),['window_start'=>'00:00','window_end'=>'23:59']);
$permission=['agent_id'=>0,'conversation_id'=>11,'contact_id'=>$id,'number_id'=>1,'expected_revision'=>0,
    'expected_destination'=>$choice['expected_destination'],'expected_line'=>$choice['expected_line'],'settings'=>$callingPolicy,'enabled'=>true];
vp3_operator_auto_call_save_v1($pdo,$scope,$permission);
$attest=array_intersect_key($permission,array_flip(['agent_id','conversation_id','contact_id','number_id','expected_destination','expected_line']));
$attest+=['decision'=>'attest','expected_revision'=>1,'expected_consent_revision'=>0,'purpose'=>'follow_up','recipient_agreement_attested'=>true];
vp3_operator_consent_save_v1($pdo,$scope,$attest,$callTime);
$q=$pdo->prepare('SELECT * FROM phone_operator_occurrences_v1 WHERE owner_user_id=1 AND event_id=? AND start_epoch=?');
$q->execute([$eventId,$callTime]);
$row=$q->fetch(PDO::FETCH_ASSOC);
$plan=vp3_operator_preview_request_v1($pdo,$row,$callTime);
c($plan['reason']==='eligible'&&$plan['fields']['To']===$choice['expected_destination']&&$plan['fields']['From']===$choice['expected_line']
    &&$plan['fields']['TimeLimit']===$duration&&$plan['objectives']===$event['description'],'verified route, time limit and objectives reach the simulated carrier boundary');
c($preview()===['eligible'=>1]&&$preview()===['eligible'=>1],'preview returns only counts and leaves the occurrence available');
$pdo->exec("UPDATE vp3_phone_communication_profiles_v1 SET window_start='10:00',window_end='11:00'");
c($preview()===['calling_window'=>1],'recipient local calling hours apply immediately');
$pdo->exec('UPDATE vp3_phone_communication_profiles_v1 SET agent_call_consent=0');
c($preview()===['recipient_permission'=>1],'recipient withdrawal after preparation prevents a carrier plan');
echo "PASS scheduled outbound request preview, current consent and privacy\n";

require_once dirname(__DIR__).'/includes/agent-operator-dispatch-worker-v1.php';
$attempt=fn(callable $send)=>vp3_operator_carrier_attempt_v1($pdo,1,$eventId,$callTime,$send,$callTime);
$unexpected=static function(){throw new LogicException('Unexpected adapter invocation');};
$noCalls=static function(){throw new LogicException('Unexpected provider call');};
ddl("CREATE TABLE IF NOT EXISTS phone_voice_turns_v140(id INTEGER PRIMARY KEY AUTOINCREMENT,call_sid VARCHAR(40),step_number INTEGER,owner_user_id INTEGER,agent_id INTEGER,speech_hash TEXT,speech_text TEXT,response_text TEXT,state TEXT,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(call_sid,step_number))");
c(vp3_operator_dispatch_tick_v1($pdo,$noCalls,$callTime)===['submitted'=>0,'uncertain'=>0,'skipped'=>1,'errors'=>0],
    'worker skips a due occurrence after the recipient withdraws');
c($attempt($unexpected)==='recipient_permission','withdrawal blocks reservation before simulated transport');
$pdo->exec("UPDATE vp3_phone_communication_profiles_v1 SET agent_call_consent=1,window_start='00:00',window_end='23:59'");
$pdo->exec('ALTER TABLE phone_voice_turns_v140 RENAME TO phone_voice_turns_upgrade_fixture');
try{
    $sentBeforeUpgrade=0;
    try{
        vp3_operator_dispatch_tick_v1($pdo,static function()use(&$sentBeforeUpgrade){++$sentBeforeUpgrade;},$callTime);
        $missingUpgrade=false;
    }catch(PDOException){$missingUpgrade=true;}
    c($missingUpgrade&&$sentBeforeUpgrade===0,'missing speech turn storage blocks dispatch before any provider call');
}finally{$pdo->exec('ALTER TABLE phone_voice_turns_upgrade_fixture RENAME TO phone_voice_turns_v140');}
$sent=0;
$scenario=$argv[1]??'success';
$worker=vp3_operator_dispatch_tick_v1($pdo,function($request)use(&$sent,$plan,$pdo,$attempt,$unexpected,$scenario){
    ++$sent;
    $plan['fields']+=['Url'=>'https://cloud.example/api/phone-operator-answer-v1.php?event=answer','Method'=>'POST',
        'StatusCallback'=>'https://cloud.example/api/phone-operator-answer-v1.php?event=status','StatusCallbackMethod'=>'POST'];
    c(!vp3_phone_transaction_active_v1($pdo)&&$request===$plan,'mock carrier receives the approved objective outside a transaction');
    c($attempt($unexpected)==='submitting','a second worker cannot submit the in-flight occurrence');
    if($scenario==='timeout')throw new RuntimeException('Response lost after submission');
    $request['fields']['PrivateObjective']='fixture-private';
    return vp3_operator_submit_scheduled_v1($request,static function($method,$path,$fields)use($plan,$scenario){
        $account=vp3_phone_twilio_state_v100()['account_sid'];
        c($method==='POST'&&$path==='/2010-04-01/Accounts/'.$account.'/Calls.json'&&$fields===$plan['fields'],
            'carrier receives the seven expected fields and no private context');
        return ['sid'=>'CA'.str_repeat('c',32),'account_sid'=>$account,'from'=>$fields['From'],
            'to'=>$scenario==='mismatch'?'wrong destination':$fields['To']];
    });
},$callTime);
$expected=$scenario==='success'?'submitted':'uncertain';
c($worker[$expected]===1&&array_sum($worker)===1&&array_sum(vp3_operator_dispatch_tick_v1($pdo,$unexpected,$callTime))===0,
    'a repeated scheduled worker tick cannot dial an already claimed occurrence');
c($attempt($unexpected)===$expected&&$sent===1,'terminal outcome fences replay');
c($read()['last_occurrence']['reason']==='carrier_'.$expected,'original private schedule history shows the submission outcome');
echo "PASS single carrier attempt ($scenario), in-flight exclusion and retry handling\n";

require_once dirname(__DIR__).'/includes/agent-operator-answer-v1.php';
$destination=$plan['fields']['To'];
$limits=fn(string $to,int $at)=>vp3_operator_admission_history_v1($pdo,$scope,['contact'=>['destination_e164'=>$to]],$callingPolicy,$at);
c(vp3_operator_preview_request_v1($pdo,$row,$callTime)['reason']==='call_frequency','a fresh dispatch check sees the carrier reservation');
$measured=$limits($destination,$callTime);
c($measured['today']===1&&!$measured['within_limit']&&!$measured['gap_ok'],'all carrier outcomes count toward the daily limit and spacing');
c($limits('+16025550189',$callTime)['within_limit'],'a different destination has its own history');
c(!vp3_operator_admission_idle_v1($pdo,1,$callTime)&&vp3_operator_admission_idle_v1($pdo,1,$callTime+$duration),'an unresolved attempt occupies the owner phone for its call window');
$pdo->beginTransaction();
try{
    $pdo->exec("UPDATE phone_operator_carrier_attempts_v1 SET destination_hash=''");
    c(!$limits('+16025550189',$callTime)['within_limit']&&$limits('+16025550189',$callTime+86401)['gap_ok'],'older unbound attempts protect other destinations only through the applicable limits');
}finally{$pdo->rollBack();}
echo "PASS scheduled reservation daily cap, spacing and concurrent call exclusion ($scenario)\n";
if($scenario!=='success')return;
$carrierForm=['AccountSid'=>vp3_phone_twilio_state_v100()['account_sid'],'CallSid'=>'CA'.str_repeat('c',32),
    'From'=>$plan['fields']['From'],'To'=>$plan['fields']['To']];
$recordSid='RE'.str_repeat('d',32);$recordStarts=0;
$recordCarrier=static function(string $method,string $resource,array $fields)use(&$recordStarts,$recordSid,$carrierForm,$pdo,$callTime):array{
    ++$recordStarts;
    $extraStarts=0;
    $overlap=vp3_operator_recording_start_v1($pdo,1,$carrierForm['CallSid'],$callTime+1,
        static function()use(&$extraStarts){++$extraStarts;});
    c($overlap['state']==='starting'&&$extraStarts===0,'a second worker observes the recording claim without sending another start');
    c(vp3_operator_conversation_context_v1($pdo,1,$carrierForm['CallSid'],$callTime+1)===[],
        'speech capture waits for confirmed recording');
    c($method==='POST'&&str_ends_with($resource,'/Calls/'.$carrierForm['CallSid'].'/Recordings.json')
        &&$fields['RecordingChannels']==='dual'&&$fields['RecordingTrack']==='both'&&$fields['PlayBeep']==='true'
        &&str_contains($fields['RecordingStatusCallback'],'event=recording'),'consent starts one dual-channel recording with an audible notice');
    return ['sid'=>$recordSid,'account_sid'=>$carrierForm['AccountSid'],'call_sid'=>$carrierForm['CallSid'],
        'source'=>'StartCallRecordingAPI','status'=>'in-progress','channels'=>2];
};
$answer=static function(string $stage,array $values=[],?string $signature=null,?callable $generator=null,?callable $recording=null,?callable $clock=null)use($carrierForm,$pdo,$callTime):string{
    $payload=array_replace($carrierForm,$values);
    $url=vp3_phone_browser_callback_v1('operator_answer',['event'=>$stage]);
    $signature??=vp3_phone_sms_twilio_signature_v110($url,$payload,'carrier-auth-token-fixture');
    return vp3_operator_answer_v1($pdo,$stage,$payload,$signature,$clock??($callTime+1),$generator,$recording);
};
$hangup=vp3_phone_browser_hangup_v1();
foreach([['answer',[],'bad signature'],['answer',['AccountSid'=>'AC'.str_repeat('d',32)],null]] as $invalid){
    try{$answer(...$invalid);$rejected=false;}catch(RuntimeException $e){$rejected=true;}
    c($rejected,'signature and carrier account must match');
}
$unknown=['CallSid'=>'CA'.str_repeat('e',32)];
c(str_contains($answer('answer',$unknown),'event=retry')&&str_contains($answer('retry',$unknown),'event=last')
    &&$answer('last',$unknown)===$hangup,'early callbacks wait briefly for the committed receipt and then stop');
c($answer('confirm',['Digits'=>'1'])===$hangup&&$answer('answer',['To'=>'+16025550189'])===$hangup
    &&$read()['last_occurrence']['reason']==='carrier_submitted','wrong route and out-of-order responses cannot change history');
foreach(['decline','silence','withdrawn'] as $case){
    $pdo->beginTransaction();
    try{
        $prompt=$answer('answer');
        c(str_contains($prompt,'input="dtmf"')&&str_contains($prompt,'recorded and transcribed')&&str_contains($prompt,'event=confirm')
            &&!str_contains($prompt,'<Record')&&!str_contains($prompt,$plan['objectives']),'keypad disclosure precedes capture and keeps objectives private');
        c($answer('answer')===$prompt,'answer retry can replay the pending prompt');
        if($case==='withdrawn')$pdo->exec('UPDATE vp3_phone_communication_profiles_v1 SET agent_call_consent=0');
        $digits=match($case){'decline'=>['Digits'=>'2'],'silence'=>[],default=>['Digits'=>'1']};
        $response=$answer('confirm',$digits);
        $reason=match($case){'agree'=>'answer_consented','decline'=>'answer_declined','silence'=>'answer_no_input',default=>'answer_permission_changed'};
        c($read()['last_occurrence']['reason']===$reason,'original private call history records '.$case);
        c($response===$hangup&&!str_contains($response,'<Record'),'refusal or missing current permission cannot start recording');
        c($answer('confirm',['Digits'=>'1'])===$hangup&&$answer('answer')===$hangup
            &&$read()['last_occurrence']['reason']===$reason,'replays cannot reopen a completed consent decision');
    }finally{$pdo->rollBack();}
}
echo "PASS signed scheduled answer and recipient consent, refusal, silence, withdrawal and replay\n";

$answer('answer');$consented=$answer('confirm',['Digits'=>'1'],null,null,$recordCarrier);
c(str_contains($consented,'input="speech"')&&$recordStarts===1,'explicit recipient consent starts recording before speech capture');
c($answer('confirm',['Digits'=>'1'],null,null,$recordCarrier)===$hangup&&$recordStarts===1,'consent replay cannot start a second recording');
$pdo->beginTransaction();
try{
    c(str_contains($consented,'input="speech"')&&str_contains($consented,'event=turn1'),'consent begins a bounded speech turn');
    c($answer('turn5',[])===$hangup&&$read()['last_occurrence']['reason']==='answer_consented',
        'a late or skipped silence callback cannot close the current conversation');
    $generated=0;$model=static function(string $speech,array $history,array $current)use(&$generated,$plan):array{
        ++$generated;c($speech==='Hello there'&&$history===[]&&$current['objectives']===$plan['objectives'],'model receives only this call speech, history and approved objectives');
        return ['ok'=>true,'answer'=>'Hello. What would you like to cover?'];
    };
    $turn=$answer('turn1',['SpeechResult'=>'Hello there'],null,$model);
    c($generated===1&&str_contains($turn,'event=turn2')&&str_contains($turn,'What would you like'),'successful reply advances one turn');
    c($answer('turn1',['SpeechResult'=>'Hello there'],null,$model)===$turn&&$generated===1,'exact carrier replay returns the saved response without another model call');
    c($answer('turn1',['SpeechResult'=>'changed replay'])===$hangup&&$generated===1,'conflicting turn replay stops without model work');
    c(str_contains($answer('turn2',['SpeechResult'=>'goodbye']),'Thank you')&&$read()['last_occurrence']['reason']==='conversation_completed','recipient can end the conversation by voice');
}finally{$pdo->rollBack();}
$pdo->beginTransaction();
try{
    c(str_contains($answer('turn1',[]),'did not hear')&&$read()['last_occurrence']['reason']==='conversation_silent','speech timeout ends without model work');
}finally{$pdo->rollBack();}
echo "PASS objective-scoped scheduled speech turns, replay fencing and voice completion\n";

foreach(['window_elapsed','consent_withdrawn'] as $change){
    $pdo->beginTransaction();
    try{
        $at=$callTime+1;
        $clock=static function()use(&$at){return $at;};
        $generator=static function()use($pdo,$change,&$at,$row){
            if($change==='window_elapsed')$at=(int)$row['end_epoch'];
            else $pdo->exec('UPDATE vp3_phone_communication_profiles_v1 SET agent_call_consent=0');
            return ['ok'=>true,'answer'=>'Late model reply'];
        };
        $response=$answer('turn1',['SpeechResult'=>'Status update'],null,$generator,null,$clock);
        $stored=$pdo->prepare('SELECT state,response_text FROM phone_voice_turns_v140 WHERE call_sid=? AND step_number=1');
        $stored->execute([$carrierForm['CallSid']]);
        c($response===$hangup&&$stored->fetch(PDO::FETCH_ASSOC)===['state'=>'withheld','response_text'=>null],
            'late speech is neither spoken nor retained after '.$change);
        $answer('status',['CallStatus'=>'completed']);
        c($read()['last_occurrence']['reason']==='conversation_reply_withheld','call history records why the reply was withheld');
    }finally{$pdo->rollBack();}
}
echo "PASS live permission and time-window checks after a delayed model response\n";

require_once dirname(__DIR__).'/includes/agent-operator-outcome-chat-v1.php';
foreach(['failed'=>'conversation_model_failed','limit'=>'conversation_turn_limit','ended'=>'conversation_ended'] as $case=>$expectedReason){
    $pdo->beginTransaction();
    try{
        $turns=$case==='limit'?8:1;
        for($i=1;$i<=$turns;++$i){
            $answer('turn'.$i,['SpeechResult'=>'Status update'],null,static fn()=>['ok'=>$case!=='failed','answer'=>'Understood.']);
        }
        $answer('status',['CallStatus'=>'completed']);
        c($read()['last_occurrence']['reason']===$expectedReason,'signed completion classifies the last saved turn: '.$case);
        c(vp3_operator_outcome_chat_tick_v1($pdo,$callTime+3)['delivered']===1,'ended speech outcome reaches the original private Chat');
        $message=$pdo->query('SELECT message FROM chat_messages WHERE conversation_id=11 ORDER BY id DESC LIMIT 1')->fetchColumn();
        $expectedLabel=match($case){'failed'=>'could not generate','limit'=>'eight-turn limit',default=>'during the conversation'};
        c(str_contains($message,$expectedLabel)&&!str_contains($message,'Status update'),'private notice describes the outcome without copying speech');
        c(!empty(vp3_operator_recording_saved_policy_v1($pdo,['transport_kind'=>'operator_scheduled','owner_user_id'=>1,
            'provider_call_sid'=>$carrierForm['CallSid'],'public_id'=>$pdo->query('SELECT call_public_id FROM phone_operator_carrier_attempts_v1')->fetchColumn()],$recordSid)['transcribe']),
            'post-call recording policy still recognizes consent for the saved outcome');
    }finally{$pdo->rollBack();}
}
echo "PASS closed-browser chat and call history for model failure, turn limit and mid-call hangup\n";

foreach(['completed'=>'ended_before_consent','busy'=>'carrier_busy','no-answer'=>'carrier_no_answer','failed'=>'carrier_failed','canceled'=>'carrier_canceled'] as $status=>$reason){
    $pdo->beginTransaction();
    try{
        $pdo->prepare("UPDATE phone_operator_occurrences_v1 SET state='submitted',reason='carrier_submitted' WHERE owner_user_id=1 AND event_id=? AND start_epoch=?")
            ->execute([$eventId,$callTime]);
        if($status==='completed')$answer('answer');
        $answer('status',['CallStatus'=>$status]);
        $answer('status',['CallStatus'=>'failed']);
        $last=$read()['last_occurrence'];
        c($last['state']==='ended'&&$last['reason']===$reason&&$answer('confirm',['Digits'=>'1'])===$hangup,'terminal callbacks close the occurrence once: '.$status);
    }finally{$pdo->rollBack();}
}
$recordingForm=['RecordingSid'=>$recordSid,'RecordingStatus'=>'completed','RecordingSource'=>'StartCallRecordingAPI',
    'RecordingChannels'=>'2','RecordingTrack'=>'both','RecordingDuration'=>'12'];
$pdo->exec("UPDATE phone_operator_carrier_attempts_v1 SET recording_state='uncertain',recording_sid=NULL");
$answer('recording',$recordingForm);
c((int)$pdo->query("SELECT COUNT(*) FROM phone_recording_jobs_v1 WHERE provider_recording_sid='$recordSid' AND state='queued'")->fetchColumn()===1,
    'signed recording receipt recovers a lost start response and enters the existing transcription and report pipeline once');
$answer('recording',$recordingForm);
c((int)$pdo->query("SELECT COUNT(*) FROM phone_recording_jobs_v1 WHERE provider_recording_sid='$recordSid'")->fetchColumn()===1,
    'duplicate recording receipt is idempotent');
$answer('status',['CallStatus'=>'completed']);
c($read()['last_occurrence']['reason']==='answer_consented'&&$read()['last_occurrence']['state']==='ended','final status preserves the recipient decision');
c(vp3_operator_admission_idle_v1($pdo,1,$callTime+5)&&!$limits($destination,$callTime+5)['within_limit'],'a finished call releases the owner phone while retaining the destination cap');
echo "PASS signed carrier outcomes and hangup before consent\n";

require_once dirname(__DIR__).'/includes/agent-operator-outcome-chat-v1.php';
$deliver=static fn(int $at)=>vp3_operator_outcome_chat_tick_v1($pdo,$at);
$notice='scheduled:'.$eventId.':'.$callTime;
$messages=$pdo->prepare('SELECT m.* FROM phone_browser_deliveries_v1 d JOIN chat_messages m ON m.id=d.chat_message_id WHERE d.owner_user_id=1 AND d.event_key=?');
$pdo->prepare('UPDATE chat_conversations SET user_id=2 WHERE id=11')->execute();
c($deliver($callTime+10)===['delivered'=>0,'deferred'=>1,'errors'=>0],'a changed original Chat defers delivery');
$messages->execute([$notice]);
c(!$messages->fetch(),'no private notice was redirected to a fallback Chat');
$pdo->exec('UPDATE chat_conversations SET user_id=1 WHERE id=11');
c($deliver($callTime+11)['delivered']===0,'deferred receipts honor their retry time');
$pdo->exec('ALTER TABLE chat_messages RENAME TO chat_messages_fixture_unavailable');
try{c($deliver($callTime+311)['errors']===1,'storage failure retains the receipt for another worker run');}
finally{$pdo->exec('ALTER TABLE chat_messages_fixture_unavailable RENAME TO chat_messages');}
c($deliver($callTime+612)['delivered']===1&&array_sum($deliver($callTime+613))===0,'closed-browser delivery recovers and repeat workers send once');
$messages->execute([$notice]);
$posted=$messages->fetchAll(PDO::FETCH_ASSOC);
c(count($posted)===1&&(int)$posted[0]['conversation_id']===11&&$posted[0]['role']==='assistant'
    &&str_contains($posted[0]['message'],'agreed')&&!str_contains($posted[0]['message'],$plan['objectives'])
    &&!str_contains($posted[0]['context_json'],$carrierForm['CallSid']),'the original Chat receives the outcome without objectives or provider identifiers');
echo "PASS server-side scheduled call outcome delivery, scope changes, recovery and deduplication\n";
