<?php
declare(strict_types=1);
require __DIR__.'/phone-browser-workspace-v1.php';

// The inherited fixture is an isolated SQLite database or an explicitly disposable CI database.
function part_fixture(?int $seconds=null,?int $createdAt=null):array
{
    $pdo=db();$now=$createdAt??time();
    $pdo->exec("UPDATE users SET is_active=1,plan=1,denied=0 WHERE id=1");
    $pdo->exec("UPDATE phone_cloud_numbers_v100 SET owner_user_id=1,agent_id=7,voice_enabled=1,status='ready' WHERE id=1");
    vp3_phone_browser_number_policy_set_v1($pdo,1,true,true);
    vp3_phone_recording_save_policy_v1($pdo,user(),7,['enabled'=>true,'retain_audio'=>true,'transcribe'=>true,'analyze'=>false]);
    $pdo->exec("UPDATE phone_operator_live_sessions_v1 SET state='closed',reserved_seconds=0");
    $pdo->exec('UPDATE phone_operator_live_assist_v1 SET suggestion_attempts=0,suggestion_pending=0');
    $pdo->exec('DELETE FROM phone_operator_voice_turns_v1');$pdo->exec('DELETE FROM phone_operator_participation_v1');
    $public=bin2hex(random_bytes(16));$parent='CA'.bin2hex(random_bytes(16));$child='CA'.bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO phone_browser_calls_v1(public_id,owner_user_id,agent_id,phone_number_id,phone_e164,peer_e164,direction,provider_call_sid,conversation_id,chat_agent_id,state,recording_enabled,created_epoch,updated_epoch) VALUES(?,1,7,1,'+16025550101','+16025550999','outbound',?,11,0,'dialing',0,?,?)")
        ->execute([$public,$parent,$now,$now]);$id=(int)$pdo->lastInsertId();
    $form=form($child,['ParentCallSid'=>$parent,'CallStatus'=>'in-progress']);
    c(vp3_phone_browser_status_v1($pdo,$form,signature('status',$form,$public),$public)['accepted'],'a signed answered carrier receipt governs the conversation');
    // The real signed receipt uses its own clock; fixture setup may cross a second.
    $receipt=readcall($public);$now=(int)$receipt['updated_epoch'];
    $GLOBALS['partModelCalls']=0;$GLOBALS['partSpeechCalls']=0;$GLOBALS['partSttIndex']=0;
    $GLOBALS['partModel']=static function(array $user,array $evidence,callable $guard):string{
        $guard();c(!vp3_phone_transaction_active_v1(db()),'conversation model IO is outside owner locks');
        $GLOBALS['partModelCalls']++;$GLOBALS['partEvidence']=$evidence;
        return '{"action":"speak","text":"Which day works for you?"}';
    };
    $GLOBALS['partSpeech']=static function(string $script):string{
        c(!vp3_phone_transaction_active_v1(db()),'conversation synthesis IO is outside owner locks');
        c(str_starts_with($script,VP3_OPERATOR_PARTICIPATION_PREFIX_V1)&&!str_contains($script,'relaying the owner'),'automatic speech identifies the Agent and does not impersonate the owner');
        $GLOBALS['partSpeechCalls']++;return str_repeat("\0",64000);
    };
    $ctx=['public'=>$public,'id'=>$id,'now'=>$now];
    $grant=part_request($ctx,['action'=>'start','consent_attested'=>true,'capture_mode'=>'both','suggestions_attested'=>true]+($seconds===null?[]:['session_seconds'=>$seconds]),0);
    $ctx['token']=$grant['token'];return $ctx;
}
function part_request(array $ctx,array $input,int $offset=4,int $owner=1):array
{
    $base=['csrf_token'=>'csrf-fixture','agent_id'=>0,'conversation_id'=>11,'call_id'=>$ctx['public']];
    if($input['action']!=='start')$base['token']=$ctx['token'];
    if(isset($ctx['grant'])&&in_array($input['action'],['participation_decide','participation_stop','participation_pause','participation_resume','voice_prepare','voice_claim'],true))$base['participation_token']=$ctx['grant'];
    $stt=static function(string $pcm):string{$side=$GLOBALS['partSttIndex']++%2;return $side===0?'Agent, what should we do next?':'';};
    return vp3_operator_live_request_v1(db(),user($owner),'POST',array_replace($base,$input),'csrf-fixture',$stt,$ctx['now']+$offset,$GLOBALS['partModel']??null,$GLOBALS['partSpeech']??null);
}
function part_grant(array &$ctx,string $mode='addressed',int $decisions=4,int $responses=2):array
{
    $grant=part_request($ctx,['action'=>'participation_start','consent_attested'=>true,'mode'=>$mode,'max_decisions'=>$decisions,'max_responses'=>$responses],1);
    $ctx['grant']=$grant['participation_token'];return $grant;
}
function part_captions(array $ctx,int $seq=1,int $offset=4):array
{
    $pcm=base64_encode(str_repeat("\0",128000));
    return part_request($ctx,['action'=>'chunk','seq'=>$seq,'pcm'=>$pcm,'local_pcm'=>$pcm],$offset)['receipts'];
}
function part_decide(array $ctx,array $receipts,int $offset=4,array $previous=[]):array
{return part_request($ctx,['action'=>'participation_decide','receipts'=>$receipts,'previous'=>$previous],$offset);}
function part_prepare(array $ctx,array $decision,int $offset=4):array
{return part_request($ctx,['action'=>'voice_prepare','source_seq'=>$decision['based_on_seq'],'suggestion'=>$decision['text'],'proof'=>$decision['proof'],'voice_consent_attested'=>false],$offset);}

$before=$checks;$fixtureStart=time()-1;$ctx=part_fixture(null,$fixtureStart);
$receipt=readcall($ctx['public']);
c((int)$receipt['created_epoch']===$fixtureStart&&(int)$receipt['updated_epoch']>$fixtureStart,'a seeded earlier creation clock crosses the signed receipt boundary without waiting');
c($ctx['now']===(int)$receipt['updated_epoch']&&(int)vp3_operator_live_row_v1($pdo,$ctx['id'])['consent_epoch']===$ctx['now'],'live consent starts at the persisted accepted receipt clock');
c(vp3_operator_participation_ready_v1($pdo),'explicit live upgrade installs optional conversation storage');
vp3_operator_participation_install_v1($pdo);
c((int)$pdo->query('SELECT COUNT(*) FROM phone_operator_participation_v1')->fetchColumn()===0,'repeated installation never enables an Agent');
denies(fn()=>part_request($ctx,['action'=>'participation_start','consent_attested'=>false,'mode'=>'addressed'],1),422,'caption permission is not automatic speech permission');
denies(fn()=>part_request($ctx,['action'=>'participation_start','consent_attested'=>true,'mode'=>'anything'],1),422,'unknown participation policy is rejected');
denies(fn()=>part_request($ctx,['action'=>'participation_start','consent_attested'=>true,'mode'=>'addressed','csrf_token'=>'wrong'],1),403,'conversation permission requires owner CSRF');
$grant=part_grant($ctx);$row=vp3_operator_participation_row_v1($pdo,$ctx['id']);
c($row['token_hash']===hash('sha256',$ctx['grant'])&&!str_contains(json_encode($row),$ctx['grant']),'the separate conversation token is stored only as a hash');
c($grant['expires_epoch']===$ctx['now']+60&&$grant['decisions_used']===0&&$grant['responses_used']===0,'conversation permission cannot extend the current live session or reset spend');
denies(fn()=>part_grant($ctx),409,'another tab cannot replace or restart the conversation grant');
$receipts=part_captions($ctx);
$forged=$receipts;$forged[0]['text']='A forged request';
denies(fn()=>part_decide($ctx,$forged),409,'edited speech cannot become a model input');
denies(fn()=>part_request($ctx,['action'=>'participation_decide','receipts'=>$receipts,'previous'=>[],'conversation_id'=>21],4,2),404,'another owner and private Chat cannot use a conversation grant');
c($GLOBALS['partModelCalls']===0,'rejected context never reaches model IO');
$decision=part_decide($ctx,$receipts);
c($decision['state']==='speak'&&$GLOBALS['partModelCalls']===1,'fresh signed human speech yields one bounded Agent decision');
denies(fn()=>part_decide($ctx,$receipts,12),409,'consumed speech evidence cannot be reused for another model request');
$manual=['action'=>'voice_prepare','source_seq'=>1,'suggestion'=>$decision['text'],'proof'=>$decision['proof'],'voice_consent_attested'=>true,'participation_token'=>str_repeat('0',64)];
denies(fn()=>part_request($ctx,$manual),409,'another conversation token cannot authorize synthesis');
$voice=part_prepare($ctx,$decision);
c($GLOBALS['partSpeechCalls']===1&&$voice['script']===VP3_OPERATOR_PARTICIPATION_PREFIX_V1.$decision['text'],'session consent permits its exact authenticated Agent response');
denies(fn()=>part_prepare($ctx,$decision),409,'a paid synthesis reservation is never replayed');
$claim=part_request($ctx,['action'=>'voice_claim','source_seq'=>1,'turn_token'=>$voice['turn_token']]);
c($claim['state']==='playing','fresh exact-call grant authorizes playback once');
denies(fn()=>part_request($ctx,['action'=>'voice_claim','source_seq'=>1,'turn_token'=>$voice['turn_token']]),409,'playback claim cannot be reused');
part_request($ctx,['action'=>'voice_finish','source_seq'=>1,'turn_token'=>$voice['turn_token']],5);
$second=part_decide($ctx,part_captions($ctx,2,12),12,[['seq'=>1,'text'=>$decision['text'],'proof'=>$decision['proof']]]);
c(count($GLOBALS['partEvidence']['previous_agent_responses'])===1&&$GLOBALS['partEvidence']['previous_agent_responses'][0]['delivery']==='may_have_been_interrupted','a subsequent turn retains authenticated context without claiming carrier delivery');
$voice2=part_prepare($ctx,$second,12);
part_request($ctx,['action'=>'voice_finish','source_seq'=>2,'turn_token'=>$voice2['turn_token']],13);
$third=part_captions($ctx,3,20);
denies(fn()=>part_decide($ctx,$third,20),429,'automatic participation shares the existing two-response voice allowance');
c($GLOBALS['partSpeechCalls']===2&&$GLOBALS['partModelCalls']===2,'voice-budget refusal sends neither a third model request nor a third synthesis');
part_request($ctx,['action'=>'participation_stop'],21);
c(readcall($ctx['public'])['state']==='answered'&&vp3_operator_live_row_v1($pdo,$ctx['id'])['state']==='active','Remove Agent preserves the human call and captions');
denies(fn()=>part_request($ctx,['action'=>'voice_claim','source_seq'=>2,'turn_token'=>$voice2['turn_token']],22),409,'removal fences late automatic playback');
denies(fn()=>part_grant($ctx),409,'removal cannot reset the call grant');

$ctx=part_fixture();part_grant($ctx,'helpful');$receipts=part_captions($ctx);
$GLOBALS['partModel']=static function()use($ctx):string{$GLOBALS['partModelCalls']++;return '{"action":"wait"}';};
c(part_decide($ctx,$receipts)['state']==='wait'&&$GLOBALS['partSpeechCalls']===0,'a wait decision does not synthesize audio');
$ctx=part_fixture();part_grant($ctx);
$assist=vp3_operator_live_assist_row_v1($pdo,$ctx['id']);
$pdo->prepare('UPDATE phone_operator_live_sessions_v1 SET next_seq=2 WHERE call_id=?')->execute([$ctx['id']]);
$stopReceipt=vp3_operator_live_caption_receipt_v1($assist,$ctx['public'],1,'local_audio','Agent, stop.');
c(part_decide($ctx,[$stopReceipt])['state']==='stopped'&&$GLOBALS['partModelCalls']===0&&$GLOBALS['partSpeechCalls']===0,'either audio side can revoke participation without another paid decision');
c(vp3_operator_participation_row_v1($pdo,$ctx['id'])['state']==='closed'&&vp3_operator_live_row_v1($pdo,$ctx['id'])['state']==='active','spoken removal closes participation without changing separate caption permission');
$ctx=part_fixture();part_grant($ctx);$receipts=part_captions($ctx);
$GLOBALS['partModel']=static function(array $user,array $evidence,callable $guard)use($pdo,$ctx):string{
    $GLOBALS['partModelCalls']++;vp3_operator_participation_close_v1($pdo,$ctx['id']);$guard();return '{"action":"speak","text":"Hello."}';
};
denies(fn()=>part_decide($ctx,$receipts),409,'revocation during inference withholds its result');
c($GLOBALS['partModelCalls']===1&&$GLOBALS['partSpeechCalls']===0,'an uncertain model request remains spent without triggering synthesis');
$ctx=part_fixture();part_grant($ctx);$decision=part_decide($ctx,part_captions($ctx));
$GLOBALS['partSpeech']=static function()use($pdo,$ctx):string{$GLOBALS['partSpeechCalls']++;vp3_operator_participation_close_v1($pdo,$ctx['id']);return str_repeat("\0",64000);};
denies(fn()=>part_prepare($ctx,$decision),409,'revocation during synthesis withholds its PCM');
c($GLOBALS['partSpeechCalls']===1&&vp3_operator_voice_row_v1($pdo,$ctx['id'],1)['state']==='closed','uncertain synthesis stays counted and its reservation closes');

$ctx=part_fixture();part_grant($ctx);$receipts=part_captions($ctx);
denies(fn()=>part_decide($ctx,$receipts,61),409,'expired conversation permission cannot invoke a model');
c($GLOBALS['partModelCalls']===0,'expiry is checked before provider work');
part_request($ctx,['action'=>'stop'],62);
c(vp3_operator_participation_row_v1($pdo,$ctx['id'])['state']==='closed','stopping live captions also revokes conversation permission');

$ctx=part_fixture();part_grant($ctx);$receipts=part_captions($ctx);
$assist=vp3_operator_live_assist_row_v1($pdo,$ctx['id']);
$ownerReceipt=vp3_operator_live_caption_receipt_v1($assist,$ctx['public'],1,'local_audio','Agent, which day works?');
c(part_decide($ctx,[$ownerReceipt])['state']==='speak','the owner can address the Agent while the other audio side is silent');

$ctx=part_fixture();part_grant($ctx,'helpful');
$GLOBALS['partModel']=static function():string{$GLOBALS['partModelCalls']++;return '{"action":"wait"}';};
for($seq=1;$seq<=4;$seq++){ $offset=4+($seq-1)*8;c(part_decide($ctx,part_captions($ctx,$seq,$offset),$offset)['state']==='wait','wait decision consumes its bounded model reservation'); }
$receipts=part_captions($ctx,5,36);
denies(fn()=>part_decide($ctx,$receipts,36),429,'four wait decisions cannot become unlimited automatic inference');
c($GLOBALS['partModelCalls']===4&&$GLOBALS['partSpeechCalls']===0,'the fifth model request is blocked before provider work');

$ctx=part_fixture();part_grant($ctx);$receipts=part_captions($ctx);$fake=$ctx['id']+100000;
$pdo->prepare("INSERT INTO phone_operator_live_sessions_v1(call_id,owner_user_id,agent_id,chat_agent_id,conversation_id,number_id,policy_revision,token_hash,state,consent_epoch,expires_epoch) SELECT ?,owner_user_id,agent_id,chat_agent_id,conversation_id,number_id,policy_revision,token_hash,'closed',consent_epoch,expires_epoch FROM phone_operator_live_sessions_v1 WHERE call_id=?")
    ->execute([$fake,$ctx['id']]);
$pdo->prepare('INSERT INTO phone_operator_live_assist_v1(call_id,receipt_secret,suggestion_attempts) VALUES(?,?,8)')->execute([$fake,bin2hex(random_bytes(32))]);
denies(fn()=>part_decide($ctx,$receipts),429,'hourly model reservations from another call remain authoritative');
c($GLOBALS['partModelCalls']===0,'the shared hourly model limit is checked before inference');

$ctx=part_fixture();part_grant($ctx);$receipts=part_captions($ctx);
for($seq=1;$seq<=8;$seq++)$pdo->prepare("INSERT INTO phone_operator_voice_turns_v1(call_id,source_seq,owner_user_id,token_hash,state,created_epoch,expires_epoch) VALUES(?,?,1,?,'closed',?,?)")
    ->execute([$ctx['id']+100000,$seq,bin2hex(random_bytes(32)),$ctx['now'],$ctx['now']+20]);
denies(fn()=>part_decide($ctx,$receipts),429,'hourly speech reservations prevent unnecessary conversation inference');
c($GLOBALS['partModelCalls']===0&&$GLOBALS['partSpeechCalls']===0,'the exhausted speech budget causes no additional paid work');

$ctx=part_fixture();part_grant($ctx);$decision=part_decide($ctx,part_captions($ctx));$voice=part_prepare($ctx,$decision);
vp3_phone_recording_save_policy_v1($pdo,user(),7,['enabled'=>false,'retain_audio'=>false,'transcribe'=>false,'analyze'=>false]);
denies(fn()=>part_request($ctx,['action'=>'voice_claim','source_seq'=>1,'turn_token'=>$voice['turn_token']]),409,'withdrawn live transcription policy blocks a prepared automatic response');

$ctx=part_fixture();$receipts=part_captions($ctx);
$GLOBALS['partModel']=static fn():string=>'Which day works?';
$GLOBALS['partSpeech']=static function(string $script):string{$GLOBALS['partSpeechCalls']++;c(str_starts_with($script,VP3_OPERATOR_VOICE_PREFIX_V1),'reviewed responses retain their original disclosure');return str_repeat("\0",64000);};
$manual=part_request($ctx,['action'=>'suggest','receipts'=>$receipts]);
$input=['action'=>'voice_prepare','source_seq'=>1,'suggestion'=>$manual['suggestion'],'proof'=>$manual['speech_proof'],'voice_consent_attested'=>false];
denies(fn()=>part_request($ctx,$input),422,'outside conversation mode each response still requires explicit script approval');
$approved=part_request($ctx,array_replace($input,['voice_consent_attested'=>true]));
c($approved['state']==='ready'&&$GLOBALS['partSpeechCalls']===1,'the original reviewed-response path still works without a conversation grant');
echo 'PHONE_OPERATOR_PARTICIPATION_PHP=PASS ('.($checks-$before).' additional checks)'.PHP_EOL;

// Session-controls acceptance uses the same authority/provider boundaries on SQLite and native MySQL.
$before=$checks;
foreach([30,60,120] as $duration){
    $ctx=part_fixture($duration);$live=vp3_operator_live_row_v1($pdo,$ctx['id']);
    c((int)$live['expires_epoch']===$ctx['now']+$duration&&(int)$live['audio_limit_seconds']===$duration*2,'chosen wall time and both audio sides have separate server limits');
}
denies(fn()=>part_request($ctx,['action'=>'start','consent_attested'=>true,'session_seconds'=>31],0),422,'arbitrary durations cannot enlarge the server cap');
denies(fn()=>part_request($ctx,['action'=>'start','consent_attested'=>true,'session_seconds'=>'120'],0),422,'duration cannot be coerced from a string');
denies(fn()=>part_grant($ctx,'addressed',9,4),422,'model allowance cannot exceed eight');
denies(fn()=>part_grant($ctx,'addressed',8,5),422,'speech allowance cannot exceed four');
$grant=part_grant($ctx,'addressed',8,4);$history=[];
c($grant['max_decisions']===8&&$grant['max_responses']===4&&$grant['expires_epoch']===$ctx['now']+120,'explicit larger grant retains the existing caption deadline');
for($seq=1;$seq<=4;$seq++){
    $at=4+($seq-1)*8;$decision=part_decide($ctx,part_captions($ctx,$seq,$at),$at,$history);$voice=part_prepare($ctx,$decision,$at);
    part_request($ctx,['action'=>'voice_finish','source_seq'=>$seq,'turn_token'=>$voice['turn_token']],$at+1);
    $history[]=['seq'=>$seq,'text'=>$decision['text'],'proof'=>$decision['proof']];
}
c($GLOBALS['partModelCalls']===4&&$GLOBALS['partSpeechCalls']===4,'four chosen speech reservations are available without per-turn approval');
denies(fn()=>part_decide($ctx,part_captions($ctx,5,36),36,$history),429,'a fifth speech reservation is blocked before model work');
$balance=vp3_operator_participation_balance_v1($pdo,vp3_operator_participation_row_v1($pdo,$ctx['id']),$ctx['now']+36);
c($balance['responses_used']===4&&$balance['hour_responses_remaining']===4,'discarded or completed speech remains spent in both balances');

$ctx=part_fixture(120);part_grant($ctx);$receipts=part_captions($ctx);$decision=part_decide($ctx,$receipts);$voice=part_prepare($ctx,$decision);
part_request($ctx,['action'=>'voice_claim','source_seq'=>1,'turn_token'=>$voice['turn_token']]);
$paused=part_request($ctx,['action'=>'participation_pause','expected_revision'=>0],5);
c($paused['state']==='paused'&&$paused['control_revision']===1&&$paused['responses_used']===1&&$paused['decisions_used']===1,'pause reports the charged balance and a new control revision');
c(vp3_operator_voice_row_v1($pdo,$ctx['id'],1)['state']==='closed'&&readcall($ctx['public'])['state']==='answered','pause closes synthetic speech without closing the human call');
denies(fn()=>part_decide($ctx,$receipts,6),409,'paused grants cannot invoke a model');
denies(fn()=>part_prepare($ctx,$decision,6),409,'paused grants cannot synthesize');
denies(fn()=>part_request($ctx,['action'=>'suggest','receipts'=>$receipts],6),409,'reviewed mode cannot bypass a paused conversation');
denies(fn()=>part_request($ctx,['action'=>'participation_resume','expected_revision'=>0],6),409,'another tab cannot replay a stale control');
denies(fn()=>part_request($ctx,['action'=>'participation_pause','expected_revision'=>1],6),409,'duplicate pause does not reset the grant');
part_captions($ctx,2,8);
$resumed=part_request($ctx,['action'=>'participation_resume','expected_revision'=>1],9);
c($resumed['state']==='listening'&&$resumed['control_revision']===2&&$resumed['resume_seq']===2&&$resumed['expires_epoch']===$ctx['now']+120,'resume preserves time, spend and the caption barrier');
denies(fn()=>part_decide($ctx,$receipts,12),409,'captions from before resume cannot trigger a new reply');
denies(fn()=>part_prepare($ctx,$decision,12),409,'a prepared decision from before pause cannot become new speech');
$next=part_decide($ctx,part_captions($ctx,3,12),12,[['seq'=>1,'text'=>$decision['text'],'proof'=>$decision['proof']]]);
c(count($GLOBALS['partEvidence']['previous_agent_responses'])===1&&$next['state']==='speak','verified earlier context survives pause and resume');

$ctx=part_fixture(120);part_grant($ctx);$receipts=part_captions($ctx);
$GLOBALS['partModel']=static function()use($ctx):string{
    $GLOBALS['partModelCalls']++;part_request($ctx,['action'=>'participation_pause','expected_revision'=>0],5);
    denies(fn()=>part_request($ctx,['action'=>'participation_resume','expected_revision'=>1],6),409,'resume waits for an outstanding model reservation');
    return '{"action":"speak","text":"A late response."}';
};
denies(fn()=>part_decide($ctx,$receipts),409,'a model result returning after pause is fenced');
c(vp3_operator_participation_row_v1($pdo,$ctx['id'])['state']==='paused'&&$GLOBALS['partSpeechCalls']===0,'a late result leaves the grant paused and triggers no synthesis');
$resumed=part_request($ctx,['action'=>'participation_resume','expected_revision'=>1],7);
c($resumed['decisions_used']===1&&$resumed['responses_used']===0,'the uncertain model request remains spent on resume');

$ctx=part_fixture(120);part_grant($ctx);$decision=part_decide($ctx,part_captions($ctx));
$GLOBALS['partSpeech']=static function()use($ctx):string{
    $GLOBALS['partSpeechCalls']++;part_request($ctx,['action'=>'participation_pause','expected_revision'=>0],5);
    part_request($ctx,['action'=>'participation_resume','expected_revision'=>1],6);
    return str_repeat("\0",64000);
};
denies(fn()=>part_prepare($ctx,$decision),409,'pause and rapid resume cannot restore an in-flight synthesis result');
$row=vp3_operator_participation_row_v1($pdo,$ctx['id']);
c($row['state']==='active'&&(int)$row['control_revision']===2&&vp3_operator_voice_row_v1($pdo,$ctx['id'],1)['state']==='closed','resume remains active while the old synthesis stays unusable');
part_request($ctx,['action'=>'participation_pause','expected_revision'=>2],7);
denies(fn()=>part_request($ctx,['action'=>'participation_resume','expected_revision'=>3],121),409,'a paused grant still expires on its original deadline');
part_request($ctx,['action'=>'participation_stop'],122);
denies(fn()=>part_request($ctx,['action'=>'participation_resume','expected_revision'=>3],123),409,'Remove Agent cannot be undone with Resume');

$ctx=part_fixture(120);$last=[];
for($seq=1;$seq<=30;$seq++)$last=part_captions($ctx,$seq,$seq===30?119:$seq*4);
c((int)vp3_operator_live_row_v1($pdo,$ctx['id'])['reserved_seconds']===240,'120 seconds of dual capture consumes the full 240 audio-second allowance');
c(vp3_operator_live_budget_v1($pdo,1,$ctx['now']+119)===240,'the longer session does not bypass the hourly audio cap');

$ctx=part_fixture();part_grant($ctx);part_request($ctx,['action'=>'participation_stop'],2);
foreach(['decision_limit','response_limit','control_revision','resume_seq'] as $column)$pdo->exec('ALTER TABLE phone_operator_participation_v1 DROP COLUMN '.$column);
$pdo->exec('ALTER TABLE phone_operator_live_sessions_v1 DROP COLUMN audio_limit_seconds');
c(!vp3_operator_live_controls_ready_v1($pdo),'an old metadata schema does not advertise new controls');
vp3_operator_live_ensure_schema_v1($pdo);vp3_operator_live_ensure_schema_v1($pdo);
c(vp3_operator_live_controls_ready_v1($pdo)&&vp3_operator_participation_row_v1($pdo,$ctx['id'])['state']==='closed','additive upgrade is rerunnable and preserves prior grants');
echo 'PHONE_OPERATOR_SESSION_CONTROLS=PASS ('.($checks-$before).' additional checks)'.PHP_EOL;
