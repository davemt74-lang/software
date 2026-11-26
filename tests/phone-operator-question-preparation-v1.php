<?php
declare(strict_types=1);
require __DIR__.'/phone-operator-objectives-v1.php';
require_once dirname(__DIR__).'/includes/agent-operator-answer-v1.php';
require_once dirname(__DIR__).'/includes/agent-operator-dispatch-worker-v1.php';
function knowledge_retrieval_v162_owner_session($user,$principal):bool{return ($principal['kind']??'')==='system'||(($principal['owner_user_id']??0)===$user['id']);}

function preparation_grant(array $ctx):string
{
    $pdo=db();$device=bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO vp3_phone_devices_v1(id,owner_user_id,device_status,expires_at) VALUES(?,1,'approved',?)")->execute([$device,time()+86400]);
    $pdo->prepare("INSERT INTO vp3_phone_communication_profiles_v1(owner_user_id,device_id,profile_id,revision,phone_revision,phone_e164,owner_calls,holder_calls,holder_proactive,agent_call_consent,holder_memory,window_start,window_end,max_calls_per_day,min_gap_minutes,updated_epoch)
        VALUES(1,?,?,1,1,?,1,1,1,3,1,'00:00','23:59',20,0,?)")->execute([$device,bin2hex(random_bytes(16)),$ctx['peer'],time()]);
    $pdo->prepare('UPDATE crm_contacts SET connected_phone_id=? WHERE id=?')->execute([$device,$ctx['contact']]);return $device;
}
function preparation_read(array $ctx,?array $saved=null,?callable $reader=null):array
{
    $line=vp3_phone_workspace_number_v1(db(),$ctx['scope'],1);$saved??=$ctx['saved'];
    return vp3_operator_question_preparation_v1(db(),$ctx['scope'],$line,$ctx['contact'],$ctx['peer'],time(),$reader??static fn(int $id):array=>$saved);
}
function preparation_accept(array $ctx,array $saved,int $index=0):array
{
    $saved['qa_items'][$index]['review_state']='accepted';$saved['qa_items'][$index]['reviewed_at']=gmdate('c');
    $saved['qa_items'][$index]['owner_review_proof_v1']=transcription_review_proof_v1((int)$ctx['job']['session_id'],$saved['source_hash'],'qa',$saved['qa_items'][$index]['section'],$saved['qa_items'][$index]);return $saved;
}
$before=$checks;$ctx=objective_fixture();$device=preparation_grant($ctx);$saved=preparation_accept($ctx,$ctx['saved']);
$p=preparation_read($ctx,$saved);
c($p['state']==='ready'&&$p['pending_count']===1&&$p['reviewed_count']===1&&array_column($p['questions'],'question')===['Who will attend?'],'reviewed exact answer is omitted and the open question leads preparation');
c($p['reason']==='open_question'&&str_contains($p['source_url'],'item=qa-open'),'preparation explains and links the actual unanswered finding');
$brief=vp3_operator_question_brief_v1($p,'',true,false);
c($brief['question_opening']==='Who will attend?'&&!str_contains($brief['objectives'],'Which day works')&&!str_contains(json_encode($brief),'Friday works'),'first spontaneous speech and model objectives use pending owner questions without historical answers');
$scheduled=vp3_operator_question_brief_v1($p,'Please revisit Which day works? because our plan changed.',false,false);
c(str_contains($scheduled['objectives'],'Please revisit Which day works? because our plan changed.')&&!isset($scheduled['opening'])&&!isset($scheduled['question_opening']),'an explicit scheduled owner objective may revisit a reviewed answer without misclassifying the call');
$raw=preparation_read($ctx);c($raw['reviewed_count']===0&&array_column($raw['questions'],'question')===['Who will attend?','Which day works?'],'unreviewed generated answers remain pending behind unanswered questions');
$conflict=$saved;$conflict['qa_items'][]=array_replace($conflict['qa_items'][0],['answer'=>'Saturday works.','item_id'=>'other']);
c(array_column(preparation_read($ctx,$conflict)['questions'],'state')===['open','conflicting'],'conflicting evidence stays pending rather than hiding a reviewed question');
$partial=$saved;$partial['complete']=false;c(preparation_read($ctx,$partial)['state']==='no_findings','incomplete analysis cannot prepare from its answers');
$stale=$saved;$stale['source_hash']=str_repeat('f',64);c(preparation_read($ctx,$stale)['state']==='no_findings','stale transcript evidence cannot prepare a call');
$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET holder_memory=0 WHERE device_id=?')->execute([$device]);$reads=0;
c(preparation_read($ctx,null,static function(int $id)use(&$reads,$saved):array{$reads++;return $saved;})['state']==='permission_required'&&$reads===0,'memory withdrawal stops prior-call reading before canonical Q&A access');
$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET holder_memory=1 WHERE device_id=?')->execute([$device]);
$late=preparation_read($ctx,null,static function(int $id)use($pdo,$device,$saved):array{$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET holder_memory=0 WHERE device_id=?')->execute([$device]);return $saved;});
c($late['state']==='changed'&&$late['questions']===[],'permission withdrawal while reading withholds the late result');
$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET holder_memory=1 WHERE device_id=?')->execute([$device]);
$other=$ctx;$other['scope']=vp3_phone_workspace_scope_v1($pdo,user(),['agent_id'=>7,'conversation_id'=>12]);c(preparation_read($other,$saved)['state']==='unavailable','another private Chat cannot borrow the earlier call findings');
$receipt=vp3_operator_question_receipt_v1($p,time());$receiptText=vp3_operator_question_receipt_text_v1($receipt);
c(str_contains($receiptText,'unanswered finding')&&!str_contains(json_encode($receipt),'Who will attend')&&!str_contains(json_encode($receipt),'Friday')&&!str_contains(json_encode($receipt),$ctx['peer']),'stored owner explanation contains only fixed reason, counts, fingerprint and source reference');
foreach(['//evil.test/artist-listening.php','https://evil.test/artist-listening.php','/other.php','/artist-listening.php\nprivate'] as $bad)c(vp3_operator_question_source_url_v1($bad)==='','source links cannot leave the canonical local evidence routes');
$prior=objective_fixture();preparation_grant($prior);$latest=objective_fixture($prior);$priorAccepted=preparation_accept($prior,$prior['saved']);
$latestSaved=$latest['saved'];$latestSaved['qa_items']=[$latestSaved['qa_items'][1]];
$both=static fn(int $id):array=>$id===(int)$prior['job']['session_id']?$priorAccepted:$latestSaved;
$carried=preparation_read($latest,null,$both);c($carried['reviewed_count']===1&&array_column($carried['questions'],'question')===['Who will attend?'],'newer missing evidence does not reopen an older exact reviewed answer');

// Seed the real canonical Transcription stores used by dispatch, answer and model-delivery checks.
foreach(['conversation_id'=>'INTEGER NOT NULL DEFAULT 11','status'=>"VARCHAR(20) NOT NULL DEFAULT 'draft'",'title'=>"VARCHAR(190) NOT NULL DEFAULT 'Call'",'started_at'=>'DATETIME NULL','last_activity_at'=>'DATETIME NULL'] as $column=>$definition)$pdo->exec('ALTER TABLE artist_transcript_sessions_v172 ADD COLUMN '.$column.' '.$definition);
foreach(['client_segment_key'=>"VARCHAR(64) NOT NULL DEFAULT ''",'segment_index'=>'INTEGER NOT NULL DEFAULT 0','speaker_label'=>"VARCHAR(64) NOT NULL DEFAULT 'Speaker'",'started_ms'=>'INTEGER NOT NULL DEFAULT 0','ended_ms'=>'INTEGER NOT NULL DEFAULT 1000','confidence'=>'REAL NULL','created_at'=>'DATETIME NULL','updated_at'=>'DATETIME NULL'] as $column=>$definition)$pdo->exec('ALTER TABLE artist_transcript_segments_v172 ADD COLUMN '.$column.' '.$definition);
ddl('CREATE TABLE artist_transcript_page_analysis_v237(session_id INTEGER,page_number INTEGER,source_hash VARCHAR(64),source_word_count INTEGER,start_segment_index INTEGER,end_segment_index INTEGER,analysis_json TEXT,provider VARCHAR(32),model VARCHAR(32),generated_at VARCHAR(40),PRIMARY KEY(session_id,page_number))');
ddl('CREATE TABLE artist_transcript_master_analysis_v237(session_id INTEGER PRIMARY KEY,source_hash VARCHAR(64),source_word_count INTEGER,page_count INTEGER,analyzed_page_count INTEGER,analysis_json TEXT,research_json TEXT,provider VARCHAR(32),model VARCHAR(32),generated_at VARCHAR(40))');
require_once dirname(__DIR__).'/includes/transcript-agent-v1.php';
function preparation_canonical(array $ctx):void
{
    $pdo=db();$session=(int)$ctx['job']['session_id'];$map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,$session));$hash=$map['source_hash'];$date=gmdate('c');
    $qa=['answered'=>[['item_id'=>'qa-answer','question'=>'Which day works?','answer'=>'Friday works.','review_state'=>'unreviewed','evidence_refs'=>[['page'=>1]]]],
        'unanswered'=>[['item_id'=>'qa-open','question'=>'Who will attend?','review_state'=>'unreviewed','evidence_refs'=>[['page'=>1]]]]];
    foreach($map['pages'] as $index=>$page)$pdo->prepare("INSERT INTO artist_transcript_page_analysis_v237 VALUES(?,?,?,?,?,?,?,'fixture','fixture',?)")
        ->execute([$session,$index+1,$page['source_hash'],$page['word_count']??1,0,0,'{}',$date]);
    $pdo->prepare("INSERT INTO artist_transcript_master_analysis_v237 VALUES(?,?,?,?,?,?,?,'fixture','fixture',?)")
        ->execute([$session,$hash,$map['total_words'],$map['page_count'],$map['page_count'],json_encode(['modules'=>['qa'=>['source_hash'=>$hash,'source_word_count'=>$map['total_words'],'generated_at'=>$date,'result'=>$qa]]]),'{}',$date]);
    $pdo->prepare('UPDATE phone_recording_jobs_v1 SET transcript_hash=? WHERE id=?')->execute([$hash,$ctx['job']['id']]);
    $q=$pdo->prepare('SELECT context_json FROM chat_messages WHERE id=?');$q->execute([$ctx['job']['report_message_id']]);$context=json_decode($q->fetchColumn(),true);$context['phone_v1']['report']['source_hash']=$hash;
    $pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?')->execute([json_encode($context),$ctx['job']['report_message_id']]);
    $master=artist_listening_v237_analysis_status($pdo,$session,$map)['master'];transcription_deeper_review_item_v307($pdo,$session,$master,'qa','qa-answer','accepted');
}
preparation_canonical($ctx);
$line=vp3_phone_workspace_number_v1($pdo,$ctx['scope'],1);
$real=vp3_operator_question_preparation_v1($pdo,$ctx['scope'],$line,$ctx['contact'],$ctx['peer'],time());
c($real['state']==='ready'&&$real['reviewed_count']===1&&array_column($real['questions'],'question')===['Who will attend?'],'default preparation reads actual canonical page coverage and actual owner Accept proof');

// Native scheduled occurrence and permissions, with provider/model calls replaced at their real boundaries.
vp3_operator_auto_call_ensure_schema_v1($pdo);$now=time();
// Preparation consumes the already consented saved Q&A above; this fixture does not start analysis.
vp3_phone_recording_save_policy_v1($pdo,user(),7,['enabled'=>true,'retain_audio'=>true,'transcribe'=>true,'analyze'=>false]);
$pdo->exec("UPDATE phone_browser_calls_v1 SET state='completed',ended_epoch=created_epoch+20 WHERE state IN ('prepared','dialing','ringing','answered')");
// The broad prerequisite fixture leaves a legacy Agent conversation active.
// Finish that earlier simulated call before preparing this independent scenario.
$pdo->exec("UPDATE phone_voice_calls_v130 SET state='completed' WHERE owner_user_id=1 AND state IN ('conversation_active','streaming_active')");
$pdo->exec('DELETE FROM phone_operator_carrier_attempts_v1');$pdo->exec('DELETE FROM phone_operator_occurrences_v1');$pdo->exec('DELETE FROM phone_operator_scheduled_assignments_v1');$pdo->exec('DELETE FROM user_calendar_events');$pdo->exec('DELETE FROM agent_scheduling_bookings');
ddl("CREATE TABLE IF NOT EXISTS phone_voice_turns_v140(id INTEGER PRIMARY KEY AUTOINCREMENT,call_sid VARCHAR(40),step_number INTEGER,owner_user_id INTEGER,agent_id INTEGER,speech_hash TEXT,speech_text TEXT,response_text TEXT,state VARCHAR(24),created_at DATETIME DEFAULT CURRENT_TIMESTAMP,UNIQUE(call_sid,step_number))");
$pdo->prepare('UPDATE phone_browser_calls_v1 SET created_epoch=?,updated_epoch=?,ended_epoch=?,intent_consumed_epoch=? WHERE id=?')->execute([$now-7200,$now-7180,$now-7180,$now-7200,$ctx['id']]);
$callingSettings=array_replace(vp3_operator_auto_call_defaults_v1(),['window_start'=>'00:00','window_end'=>'23:59','max_calls_per_day'=>3,'min_gap_minutes'=>60]);
$permission=['agent_id'=>0,'conversation_id'=>11,'contact_id'=>$ctx['contact'],'number_id'=>1,'expected_revision'=>0,'expected_destination'=>$ctx['peer'],'expected_line'=>$line['phone_e164'],'settings'=>$callingSettings,'enabled'=>true];
vp3_operator_auto_call_save_v1($pdo,$ctx['scope'],$permission);
$attest=array_intersect_key($permission,array_flip(['agent_id','conversation_id','contact_id','number_id','expected_destination','expected_line']));$attest+=['decision'=>'attest','expected_revision'=>1,'expected_consent_revision'=>0,'purpose'=>'follow_up','recipient_agreement_attested'=>true];
vp3_operator_consent_save_v1($pdo,$ctx['scope'],$attest,$now);
$pdo->prepare("INSERT INTO user_calendar_events(owner_user_id,title,description,start_at_utc,end_at_utc,timezone,recurrence,source,source_reference,status) VALUES(1,'Question follow-up','Ask who is attending.',?,?,'UTC','none','user',?,'active')")
    ->execute([gmdate('Y-m-d H:i:s',$now-1),gmdate('Y-m-d H:i:s',$now+599),'operator-contact:'.$ctx['contact'].':7:test']);$eventId=(int)$pdo->lastInsertId();
$event=user_calendar_event_v1300($pdo,1,$eventId);
$pdo->prepare('INSERT INTO phone_operator_scheduled_assignments_v1(owner_user_id,event_id,contact_id,agent_id,number_id,conversation_id,chat_agent_id,event_revision,destination_hash,line_binding,enabled,revision,next_due_epoch) VALUES(1,?,?,7,1,11,0,?,?,?,1,1,?)')
    ->execute([$eventId,$ctx['contact'],section12_revision($event),hash('sha256',$ctx['peer']),hash('sha256',$line['provider_sid'].'|'.$line['phone_e164']),$now-1]);
$pdo->prepare("INSERT INTO phone_operator_occurrences_v1(owner_user_id,event_id,start_epoch,end_epoch,assignment_revision,state,reason,observed_epoch,contact_id,conversation_id,chat_agent_id) VALUES(1,?,?,?,1,'prepared','awaiting_dispatch_checks',?,?,11,0)")
    ->execute([$eventId,$now-1,$now+599,$now,$ctx['contact']]);
$row=$pdo->query('SELECT * FROM phone_operator_occurrences_v1')->fetch(PDO::FETCH_ASSOC);$current=vp3_operator_current_request_v1($pdo,$row,$now);
c($current['reason']==='eligible'&&$current['question_preparation']['reviewed_count']===1&&str_contains($current['objectives'],'Ask who is attending.')&&!str_contains($current['objectives'],'Friday works.'),'real scheduled admission attaches pending questions and preserves the current objective ('.$current['reason'].'; '.($current['question_preparation']['state']??'no preparation').')');
$pdo->prepare('UPDATE phone_operator_contact_policies_v1 SET enabled=0 WHERE contact_id=?')->execute([$ctx['contact']]);
c(vp3_operator_current_request_v1($pdo,$row,$now)['reason']==='owner_permission','reviewed findings cannot override owner permission');
$pdo->prepare('UPDATE phone_operator_contact_policies_v1 SET enabled=1 WHERE contact_id=?')->execute([$ctx['contact']]);
$outside=gmdate('H',$now)==='00'?'12:00':'00:00';$outsideEnd=$outside==='12:00'?'12:01':'00:01';
$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET window_start=?,window_end=? WHERE device_id=?')->execute([$outside,$outsideEnd,$device]);
c(vp3_operator_current_request_v1($pdo,$row,$now)['reason']==='calling_window','real admission applies calling hours before question preparation');
$pdo->prepare("UPDATE vp3_phone_communication_profiles_v1 SET window_start='00:00',window_end='23:59' WHERE device_id=?")->execute([$device]);

// A questions-only spontaneous agenda with every answer reviewed cannot reserve a carrier call.
$allDraft=objective_fixture();
action('save_operator_agenda',['contact_id'=>$allDraft['contact'],'number_id'=>1,'expected_revision'=>1,'enabled'=>true,'review_missed'=>false,'review_account'=>false,'topics'=>'','questions'=>"Which day works?\nWho will attend?"]);
$all=objective_fixture($allDraft);preparation_grant($all);preparation_canonical($all);$allNow=time()+1;
$pdo->prepare('UPDATE phone_browser_calls_v1 SET created_epoch=?,updated_epoch=?,ended_epoch=?,intent_consumed_epoch=? WHERE peer_e164=?')->execute([$allNow-7200,$allNow-7180,$allNow-7180,$allNow-7200,$all['peer']]);
$map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,(int)$all['job']['session_id']));$master=artist_listening_v237_analysis_status($pdo,(int)$all['job']['session_id'],$map)['master'];
$modules=transcription_app_modules_v306($master['analysis'],$master);$open=$modules['qa']['result']['unanswered'][0];$open['answer']='The two invited guests.';$modules['qa']['result']['answered'][]=$open;$modules['qa']['result']['unanswered']=[];
$master['analysis']=transcription_deeper_persist_modules_v307($pdo,(int)$all['job']['session_id'],$modules);
transcription_deeper_review_item_v307($pdo,(int)$all['job']['session_id'],$master,'qa','qa-open','accepted');
$allPermission=array_replace($permission,['contact_id'=>$all['contact'],'expected_destination'=>$all['peer']]);vp3_operator_auto_call_save_v1($pdo,$all['scope'],$allPermission);
$allAttest=array_replace($attest,['contact_id'=>$all['contact'],'expected_destination'=>$all['peer']]);vp3_operator_consent_save_v1($pdo,$all['scope'],$allAttest,$allNow);
$pdo->prepare("UPDATE user_calendar_events SET status='cancelled' WHERE id=?")->execute([$eventId]);
$agenda=vp3_operator_agenda_row_v1($pdo,[1,$all['contact'],11,0]);$reason=vp3_operator_outreach_reason_v1($pdo,$agenda,'follow_up',$allNow);
$pdo->prepare("INSERT INTO user_calendar_events(owner_user_id,title,description,start_at_utc,end_at_utc,timezone,recurrence,source,source_reference,status) VALUES(1,'Reviewed questions',?,?,?,'UTC','none','agent',?,'active')")
    ->execute([$reason['objective'],gmdate('Y-m-d H:i:s',$allNow-1),gmdate('Y-m-d H:i:s',$allNow+599),'operator-outreach:'.$all['contact'].':'.$reason['fingerprint']]);$allEvent=(int)$pdo->lastInsertId();$allCalendar=user_calendar_event_v1300($pdo,1,$allEvent);
$pdo->prepare('INSERT INTO phone_operator_scheduled_assignments_v1(owner_user_id,event_id,contact_id,agent_id,number_id,conversation_id,chat_agent_id,event_revision,destination_hash,line_binding,enabled,revision,next_due_epoch) VALUES(1,?,?,7,1,11,0,?,?,?,1,1,?)')
    ->execute([$allEvent,$all['contact'],section12_revision($allCalendar),hash('sha256',$all['peer']),hash('sha256',$line['provider_sid'].'|'.$line['phone_e164']),$allNow-1]);
$pdo->prepare("INSERT INTO phone_operator_outreach_uses_v1(owner_user_id,contact_id,reason_hash,event_id,agenda_revision,purpose,missed_event_id) VALUES(1,?,?,?,?,'follow_up',0)")->execute([$all['contact'],$reason['fingerprint'],$allEvent,$agenda['revision']]);
$pdo->prepare("INSERT INTO phone_operator_occurrences_v1(owner_user_id,event_id,start_epoch,end_epoch,assignment_revision,state,reason,observed_epoch,contact_id,conversation_id,chat_agent_id) VALUES(1,?,?,?,1,'prepared','awaiting_dispatch_checks',?,?,11,0)")
    ->execute([$allEvent,$allNow-1,$allNow+599,$allNow,$all['contact']]);$allSends=0;
$blocked=vp3_operator_carrier_attempt_v1($pdo,1,$allEvent,$allNow-1,static function()use(&$allSends):array{$allSends++;return [];},$allNow);
c($blocked==='questions_reviewed'&&$allSends===0,'all reviewed questions with no other current reason defer before any carrier reservation');
$again=vp3_operator_outreach_reason_v1($pdo,$agenda,'follow_up',$allNow);
c($again['fingerprint']===$reason['fingerprint'],'review progress does not reset the existing spontaneous agenda identity or used-reason guard');
$pdo->prepare("UPDATE user_calendar_events SET status='cancelled' WHERE id=?")->execute([$allEvent]);$pdo->prepare("UPDATE user_calendar_events SET status='active' WHERE id=?")->execute([$eventId]);
$carrierSid='CA'.bin2hex(random_bytes(16));$sent=0;
$attempt=vp3_operator_carrier_attempt_v1($pdo,1,$eventId,$now-1,static function(array $plan)use(&$sent,$carrierSid):array{$sent++;return ['sid'=>$carrierSid,'account_sid'=>vp3_phone_twilio_state_v100()['account_sid'],'from'=>$plan['fields']['From'],'to'=>$plan['fields']['To']];},$now);
c($attempt==='submitted'&&$sent===1,'one admitted scheduled call crosses the simulated carrier boundary');
$q=$pdo->prepare('SELECT * FROM phone_browser_calls_v1 WHERE provider_call_sid=?');$q->execute([$carrierSid]);$newCall=$q->fetch(PDO::FETCH_ASSOC);
$count=(int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn();$state=vp3_phone_workspace_state_v1($pdo,user(),['conversation_id'=>11,'agent_id'=>0]);
$projected=array_values(array_filter($state['calls'],static fn($c)=>$c['public_id']===$newCall['public_id']))[0];
c($projected['question_preparation']['pending_count']===1&&$state['question_preparation_review']['reason']==='open_question','same private Chat projects the original fixed preparation receipt into call cards and diagnostics');
$foreign=vp3_phone_workspace_state_v1($pdo,user(),['conversation_id'=>12,'agent_id'=>7]);
c($foreign['question_preparation_review']===null&&!str_contains(json_encode($foreign['calls']),'question_preparation'),'another private Chat does not receive the receipt');
c((int)$pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()===$count,'opening history and diagnostics never creates another announcement');
$answer=static function(string $event,array $extra=[],?callable $generator=null,?callable $recorder=null)use($pdo,$now,$carrierSid,$ctx,$line):string{
    $form=array_replace(['AccountSid'=>vp3_phone_twilio_state_v100()['account_sid'],'CallSid'=>$carrierSid,'From'=>$line['phone_e164'],'To'=>$ctx['peer']],$extra);
    return vp3_operator_answer_v1($pdo,$event,$form,vp3_phone_sms_twilio_signature_v110(vp3_phone_browser_callback_v1('operator_answer',['event'=>$event]),$form,'carrier-auth-token-fixture'),$now+1,$generator,$recorder);
};
$prompt=$answer('answer');c(str_contains($prompt,'recorded and transcribed')&&!str_contains($prompt,'Who will attend'),'saved questions stay behind the consent prompt');
$starts=0;$record=static function($method,$path,$fields)use(&$starts,$carrierSid):array{$starts++;return ['sid'=>'RE'.bin2hex(random_bytes(16)),'account_sid'=>vp3_phone_twilio_state_v100()['account_sid'],'call_sid'=>$carrierSid,'source'=>'StartCallRecordingAPI','status'=>'in-progress','channels'=>2];};
c(str_contains($answer('confirm',['SpeechResult'=>'I agree'],null,$record),'input="speech"')&&$starts===1,'recording is confirmed before the scheduled conversation continues');
$models=0;$spoken=$answer('turn1',['SpeechResult'=>'Who has confirmed?'],static function($speech,$history,$current)use(&$models):array{$models++;c(str_contains($current['objectives'],'Who will attend?')&&!str_contains($current['objectives'],'Which day works?'),'real model boundary receives only pending agenda questions');return ['ok'=>true,'answer'=>'Let us confirm who will attend.'];});
c(str_contains($spoken,'Let us confirm who will attend.')&&$models===1,'an unchanged canonical preparation allows one verified reply');
$q=$pdo->prepare('SELECT analysis_json FROM artist_transcript_master_analysis_v237 WHERE session_id=?');$q->execute([(int)$ctx['job']['session_id']]);$originalAnalysis=$q->fetchColumn();
foreach(['evidence','memory'] as $change){
    $withheld=$answer('turn2',['SpeechResult'=>'Please continue.'],static function()use($pdo,$device,$ctx,$change,&$models):array{
        $models++;
        if($change==='memory')$pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET holder_memory=0 WHERE device_id=?')->execute([$device]);
        else{$map=artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,(int)$ctx['job']['session_id']));$master=artist_listening_v237_analysis_status($pdo,(int)$ctx['job']['session_id'],$map)['master'];transcription_deeper_review_item_v307($pdo,(int)$ctx['job']['session_id'],$master,'qa','qa-answer','unreviewed');}
        return ['ok'=>true,'answer'=>'This late private reply must not be spoken.'];
    });
    c($withheld===vp3_phone_browser_hangup_v1()&&!str_contains($withheld,'late private reply'),$change.' change during inference withholds late speech');
    $beforeReplay=$models;$answer('turn2',['SpeechResult'=>'Please continue.'],static function()use(&$models):array{$models++;return ['ok'=>true,'answer'=>'replayed'];});
    c($models===$beforeReplay&&$sent===1,'a '.$change.'-withheld reply cannot trigger another model turn or dial');
    // Fork the same post-turn-one fixture for the independent revocation scenario.
    $pdo->prepare('DELETE FROM phone_voice_turns_v140 WHERE call_sid=? AND step_number=2')->execute([$carrierSid]);
    $pdo->prepare('UPDATE artist_transcript_master_analysis_v237 SET analysis_json=? WHERE session_id=?')->execute([$originalAnalysis,(int)$ctx['job']['session_id']]);
    $pdo->prepare('UPDATE vp3_phone_communication_profiles_v1 SET holder_memory=1 WHERE device_id=?')->execute([$device]);
}
echo 'OPERATOR_QUESTION_PREPARATION=PASS ('.($checks-$before).' additional checks)'.PHP_EOL;
