<?php
declare(strict_types=1);

// Canonical check-in source/model/queue/worker/receipt/Chat fixture, with real
// account/Agent ownership. Account entitlement and TTS transport are host IO.
require __DIR__.'/agent-initiative-checkin-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-voice-v1.php';
if(!function_exists('user_has_role')){
    function user_has_role(string $role,array $user): bool{return $role==='admin'&&(int)$user['id']===1&&!empty($GLOBALS['voiceAccountEntitled']);}
}
$GLOBALS['voiceAccountEntitled']=true;
db()->exec('CREATE TABLE IF NOT EXISTS team_user_presence(user_id INTEGER PRIMARY KEY,presence_mode TEXT,social_chat_enabled INTEGER,sound_enabled INTEGER,agent_voice_enabled INTEGER)');
db()->exec("INSERT INTO team_user_presence VALUES(1,'online',1,1,1)");
$voiceChecks=0;
function voice_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);$GLOBALS['voiceChecks']++;echo 'PASS '.$label.PHP_EOL;
}
function voice_reject(callable $run,int $code,string $label): void
{
    try{$run();}catch(Throwable $error){voice_check($error->getCode()===$code,$label.' ('.$error->getMessage().')');return;}
    throw new RuntimeException('Expected refusal: '.$label);
}
function voice_seed(?string $delivery='voice',?string $draft=null): array
{
    checkin_reset();db()->exec('UPDATE team_user_presence SET agent_voice_enabled=1 WHERE user_id=1');$GLOBALS['voiceAccountEntitled']=true;
    $work=checkin_execute(static function()use($delivery,$draft):string{
        $reply=['draft'=>$draft??'The launch review is coming up. Would a focused agenda help with the supplier decision?'];
        if($delivery!==null)$reply['delivery']=$delivery;return json_encode($reply,JSON_THROW_ON_ERROR);
    });
    voice_check(!empty($work['completed']['ok'])&&$work['result']['initiative_delivery']==='delivered','the actual check-in worker posts visible text before any voice claim');
    voice_presence();
    return ['run_id'=>$work['run'],'message_id'=>(int)$work['result']['initiative_message_id'],'request_id'=>'11111111-1111-4111-8111-111111111111',
        'activity_tab'=>str_repeat('a',32),'working_attention_binding'=>vp3_catch_up_page_binding_v1(['id'=>1,'is_active'=>1])];
}
function voice_presence(array $overrides=[]): void
{
    $details=array_replace(['visible'=>true,'focused'=>true,'conversation_id'=>170,'chat_focused'=>true,'chat_busy'=>false,
        'activity_tab'=>str_repeat('a',32)],$overrides);
    db()->prepare("UPDATE agent_activity_state SET surface='chat',context_key='chat:170',last_heartbeat_at=NOW(),details_json=? WHERE user_id=1")
        ->execute([json_encode($details,JSON_THROW_ON_ERROR)]);
}
function voice_call(string $action,array $input): array{return vp3_initiative_voice_v1(db(),['id'=>1,'is_active'=>1],17,170,$action,$input);}
function voice_saved(int $run): array
{
    $q=db()->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');$q->execute([$run]);$action=(string)$q->fetchColumn();
    $q=db()->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=? AND status=\'completed\'');$q->execute([$run]);$receipt=(string)$q->fetchColumn();
    voice_check($action!==''&&hash_equals($action,$receipt),'canonical action and receipt retain byte-identical voice state');
    return json_decode($action,true,32,JSON_THROW_ON_ERROR);
}
$input=voice_seed();$models=count($GLOBALS['scopeInference']);$messages=count(scope_messages());$changes=(int)db()->query('SELECT total_changes()')->fetchColumn();
$candidates=voice_call('voice_candidates',$input);
voice_check($candidates['candidates']===[['run_id'=>$input['run_id'],'message_id'=>$input['message_id']]]
    &&!str_contains(json_encode($candidates),'supplier decision')&&(int)db()->query('SELECT total_changes()')->fetchColumn()===$changes,
    'candidate polling exposes exact identifiers only and performs zero writes or inference');
$claimed=voice_call('voice_claim',$input);$token=$claimed['claim']['token'];$saved=voice_saved($input['run_id']);
voice_check($claimed['claim']['text']===$saved['initiative_draft']&&preg_match('/\A[a-f0-9]{64}\z/D',$token)===1
    &&!str_contains(json_encode($saved['initiative_voice']),$token)&&$saved['initiative_voice']['status']==='claimed',
    'the exact real Chat text is released only with a bound claim and a hash-only stored token');
voice_check($claimed['owner_user_id']===1&&$claimed['agent_id']===17&&$claimed['conversation_id']===170
    &&$claimed['activity_tab']===$input['activity_tab']&&$claimed['live_session_id']===null,
    'a claim preserves exact owner Agent conversation page tab and no-sensor session binding');
voice_check(voice_call('voice_claim',$input)===$claimed,'a lost acknowledgement can recover the same token and text before playback with the same request');
$other=$input;$other['request_id']='22222222-2222-4222-8222-222222222222';
voice_reject(fn()=>voice_call('voice_claim',$other),409,'a fresh request after reload cannot replay an uncertain claim');
voice_presence(['activity_tab'=>str_repeat('b',32)]);$other['activity_tab']=str_repeat('b',32);
voice_reject(fn()=>voice_call('voice_claim',$other),409,'another focused tab cannot acquire the already claimed check-in');
voice_presence();$finish=$input+['token'=>$token,'outcome'=>'spoken'];
voice_check(voice_call('voice_finish',$finish)['status']==='spoken','matching token completion marks the existing voice claim final');
voice_check(voice_call('voice_finish',$finish)['status']==='spoken','the same finish acknowledgement is idempotent');
voice_reject(fn()=>voice_call('voice_finish',array_replace($finish,['outcome'=>'suppressed'])),409,'terminal spoken state cannot be changed by a later contradictory completion');
voice_reject(fn()=>voice_call('voice_claim',$input),409,'a completed voice claim cannot speak again');
voice_check(voice_call('voice_candidates',$input)['candidates']===[]&&count($GLOBALS['scopeInference'])===$models&&count(scope_messages())===$messages,
    'voice rendering never adds a Chat turn or model call and consumed speech leaves the text intact');
$saved=voice_saved($input['run_id']);
voice_check(vp3_initiative_voice_metadata_v1($saved['initiative_voice'])===$saved['initiative_voice'],'actual stored claim metadata passes its strict typed validator');
foreach([['token_hash'=>'secret'],['activity_tab'=>'bad'],['message_id'=>'1'],['status'=>'pending'],['finished_at'=>''],['claimed_at'=>'tomorrow'],['finished_at'=>'2026-02-30T12:00:00+00:00'],['extra'=>'not allowed']] as $patch)
    voice_check(vp3_initiative_voice_metadata_v1(array_replace($saved['initiative_voice'],$patch))===null,'malformed typed voice metadata is rejected: '.array_key_first($patch));
foreach([null,'text'] as $delivery){$text=voice_seed($delivery);voice_check(voice_call('voice_candidates',$text)['candidates']===[],
    'default or explicit text check-ins remain visible without acquiring a voice claim');}
$long=voice_seed('voice',str_repeat('A useful source-grounded private next step. ',10));
voice_check(voice_call('voice_candidates',$long)['candidates']===[],'a long voice-requested check-in falls back to visible text');
$input=voice_seed();db()->exec('UPDATE team_user_presence SET agent_voice_enabled=0 WHERE user_id=1');
voice_reject(fn()=>voice_call('voice_candidates',$input),409,'the actual VoiceMaster off setting prevents candidate disclosure');
voice_reject(fn()=>voice_call('voice_claim',$input),409,'the actual VoiceMaster off setting prevents a claim');
db()->exec('UPDATE team_user_presence SET agent_voice_enabled=1 WHERE user_id=1');$GLOBALS['voiceAccountEntitled']=false;
voice_reject(fn()=>voice_call('voice_claim',$input),409,'current voice entitlement is required in addition to the stored master setting');$GLOBALS['voiceAccountEntitled']=true;
foreach([['chat_busy'=>true],['chat_focused'=>false],['focused'=>false],['visible'=>false],['activity_tab'=>str_repeat('c',32)]] as $change){
    voice_presence($change);voice_reject(fn()=>voice_call('voice_claim',$input),409,'current owner typing focus visibility and tab fences apply: '.array_key_first($change));
}
voice_presence();db()->exec("UPDATE agent_activity_state SET last_heartbeat_at='2000-01-01 00:00:00' WHERE user_id=1");
voice_reject(fn()=>voice_call('voice_claim',$input),409,'stale owner presence cannot authorize spoken output');voice_presence();
$bad=$input;$bad['working_attention_binding']='other-owner-page';voice_reject(fn()=>voice_call('voice_claim',$bad),403,'a stale page binding cannot claim voice');
$bad=$input;$bad['request_id']='not-a-uuid';voice_reject(fn()=>voice_call('voice_claim',$bad),0,'voice requests require a typed UUID identity');
$bad=$input;$bad['message_id']++;voice_reject(fn()=>voice_call('voice_claim',$bad),409,'a different Chat message cannot borrow the source run receipt');
voice_reject(fn()=>vp3_initiative_voice_v1(db(),['id'=>1,'is_active'=>1],18,170,'voice_claim',$input),403,'a sibling Agent cannot claim this conversation output');
voice_reject(fn()=>vp3_initiative_voice_v1(db(),['id'=>1,'is_active'=>1],17,171,'voice_claim',$input),403,'another owned Chat cannot borrow the Agent policy linked to this conversation');
checkin_change('user');voice_reject(fn()=>voice_call('voice_claim',$input),409,'an ordinary owner reply after the check-in invalidates its old conversational source');
$input=voice_seed();db()->exec("UPDATE initiative_scope_calendar SET title='Changed actual source' WHERE owner_id=1");
voice_reject(fn()=>voice_call('voice_claim',$input),409,'a source edit before claim prevents speech despite already visible text');
db()->exec("UPDATE initiative_scope_calendar SET title='Owner one private launch' WHERE owner_id=1");
$input=voice_seed();$claimed=voice_call('voice_claim',$input);$suppressed=$input+['token'=>$claimed['claim']['token'],'outcome'=>'suppressed'];
voice_presence(['visible'=>false,'focused'=>false]);
voice_check(voice_call('voice_finish',$suppressed)['status']==='suppressed','a hidden owner can finalize a previously bound suppressed claim without reopening playback');
voice_check(voice_saved($input['run_id'])['initiative_voice']['status']==='suppressed','suppression is durable in the same action and receipt');
$input=voice_seed();db()->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE id=".$input['message_id']);
voice_check(voice_call('voice_candidates',$input)['candidates']===[],'old delivered check-ins cannot form a voice backlog on a later page load');

function voice_packet(string $activity='quiet',string $session='33333333-3333-4333-8333-333333333333'): array
{
    $now=gmdate('Y-m-d\TH:i:s\Z');
    return ['contract'=>'vp3.live-context.v1','session_id'=>$session,'owner_user_id'=>1,'agent_id'=>17,'conversation_id'=>170,
        'activity_tab'=>str_repeat('a',32),'observed_at'=>$now,'received_at_ms'=>(int)floor(microtime(true)*1000),'screens'=>[],
        'microphone'=>['active'=>true,'context_allowed'=>true,'observed_at'=>$now,'activity'=>$activity,
            'quiet_duration_ms'=>$activity==='quiet'?5000:0,'observed_window_ms'=>10000,'authentication_authority'=>false]];
}
$input=voice_seed();voice_presence(['live_context'=>voice_packet('energy')]);$visible=checkin_count();
voice_reject(fn()=>voice_call('voice_candidates',$input),409,'actual nonverbal microphone energy defers the optional voice rendering');
voice_check(checkin_count()===$visible&&!isset(voice_saved($input['run_id'])['initiative_voice']),
    'microphone deferral leaves the visible canonical text and its unconsumed voice decision intact');
voice_presence(['live_context'=>voice_packet()]);$claim=voice_call('voice_claim',$input);
voice_check($claim['live_session_id']==='33333333-3333-4333-8333-333333333333',
    'an actual active sensory session is returned with the claim for preplay and generation guards');
voice_presence(['live_context'=>voice_packet('quiet','44444444-4444-4444-8444-444444444444')]);
voice_reject(fn()=>voice_call('voice_claim',$input),409,'replaced sensory sessions cannot recover a stale claimed clip even with the same request');
voice_check(voice_call('voice_finish',$input+['token'=>$claim['claim']['token'],'outcome'=>'suppressed'])['status']==='suppressed',
    'the old session token may finalize suppression without authorizing any new speech');
$input=voice_seed();$claim=voice_call('voice_claim',$input);$wrong=$input+['token'=>str_repeat('f',64),'outcome'=>'spoken'];
voice_reject(fn()=>voice_call('voice_finish',$wrong),409,'an incorrect token cannot finalize another speech claim');
checkin_change('edit');
voice_reject(fn()=>voice_call('voice_claim',$input),409,'a source edit after a lost claim acknowledgement cannot release the old text on recovery');
voice_check(voice_saved($input['run_id'])['initiative_voice']['status']==='claimed',
    'source uncertainty never frees an old claim for a fresh request or automatic replay');
$input=voice_seed();db()->prepare("UPDATE agent_workflow_receipts SET status='failed' WHERE run_id=?")->execute([$input['run_id']]);
voice_check(voice_call('voice_candidates',$input)['candidates']===[],'a Chat post without its successful canonical execution receipt is not a voice candidate');
voice_reject(fn()=>voice_call('voice_claim',$input),409,'a missing successful receipt cannot be substituted by Chat text alone');
echo 'AGENT_INITIATIVE_VOICE_V1=PASS ('.$voiceChecks.' focused cases; actual check-in foundation also passed)'.PHP_EOL;
