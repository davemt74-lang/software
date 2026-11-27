<?php
declare(strict_types=1);

// Real admission, SQL, worker, ownership and source-policy code. Only outbound
// inference is a configurable fixture; every answer crosses the JSON parser.
define('PA3_HANDOFF_HELPERS_ONLY',true);
require __DIR__.'/profile-handoff-pa3.php';
require_once dirname(__DIR__).'/includes/profile-agent-responses-v1.php';

function response_mode_v1(string $mode): array
{
    $p=db();$p->beginTransaction();
    try{
        $settings=vp3_profile_response_settings_v1($p,1,true);
        $saved=vp3_profile_response_settings_save_v1($p,1,$mode,(int)$settings['revision']);
        $p->commit();return $saved;
    }catch(Throwable $error){if($p->inTransaction())$p->rollBack();throw $error;}
}
function response_send_v1(string $query='What are the public hours?',int $cid=0,array $delivery=[]): array
{
    return vp3_profile_agent_public_message_service_v110(db(),pa3_context(),$query,$cid,
        array_replace(['async'=>true,'client_request_id'=>bin2hex(random_bytes(16)),'chat_mode'=>'agent'],$delivery));
}
function response_poll_v1(int $cid): array
{
    return vp3_profile_agent_public_poll_service_v110(db(),pa3_context(),$cid);
}
function response_request_v1(int $cid): array
{
    $poll=response_poll_v1($cid);$request=$poll['response']['request']??null;
    if(!is_array($request))throw new RuntimeException('Public poll omitted the durable owner request.');
    return $request;
}
function response_row_v1(int $id): array
{
    $q=db()->prepare('SELECT * FROM profile_agent_owner_requests_v1 WHERE id=?');$q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC)?:throw new RuntimeException('Owner request was not persisted.');
}
function response_attention_v1(int $visitorMessageId): array
{
    $rows=db()->query("SELECT a.id,a.status,e.metadata_json FROM agent_attention_items a INNER JOIN profile_events e ON e.id=a.source_event_id AND e.owner_user_id=a.owner_user_id WHERE a.owner_user_id=1 AND a.attention_type='needs_owner'")->fetchAll();
    foreach($rows as $row){$meta=json_decode((string)$row['metadata_json'],true);if((int)($meta['message_id']??0)===$visitorMessageId)return $row;}
    throw new RuntimeException('Exact owner-request attention is missing.');
}
function response_provider_v1(string $disposition,string $answer): array
{
    return ['ok'=>true,'answer'=>json_encode(compact('disposition','answer'),JSON_THROW_ON_ERROR)];
}
function response_rejected_v1(callable $operation,string $label): void
{
    $error=null;try{$operation();}catch(Throwable $caught){$error=$caught;}
    pa3_check($error instanceof RuntimeException,$label);
}
function response_waiting_v1(array $receipt,string $label): array
{
    $turn=pa3_turn($receipt);$cid=(int)$turn['conversation_id'];$request=response_request_v1($cid);
    $row=response_row_v1((int)$request['id']);
    pa3_check($turn['state']==='owner_requested'&&(int)$turn['answer_message_id']===0,$label.' keeps the accepted turn waiting without a terminal answer');
    pa3_check(vp3_profile_handoff_thread_pa3(db(),1,$cid)['mode']==='owner_waiting',$label.' assigns the conversation to the durable owner wait');
    pa3_check($request['status']==='waiting'&&(int)$request['visitor_message_id']===(int)$turn['visitor_message_id']
        &&$row['status']==='waiting'&&(int)$row['conversation_id']===$cid,$label.' saves and projects the exact unanswered visitor request');
    foreach(['id','conversation_id','visitor_message_id','receipt_message_id','reply_message_id','revision'] as $field){
        pa3_check(is_int($request[$field]??null),$label.' projects '.$field.' as an integer');
    }
    pa3_check($request['receipt_message_id']>0&&$request['reply_message_id']===0,$label.' distinguishes its submission receipt from an owner reply');
    $q=db()->prepare('SELECT * FROM profile_agent_messages WHERE id=? AND conversation_id=?');$q->execute([$request['receipt_message_id'],$cid]);$stored=$q->fetch();
    pa3_check($stored&&$stored['sender_type']==='receipt'&&$stored['sender_user_id']===null&&trim($stored['message'])!=='',$label.' stores a server receipt without impersonating an Agent answer');
    $public=array_values(array_filter(response_poll_v1($cid)['messages'],static fn($message)=>(int)$message['id']===$request['receipt_message_id']));
    pa3_check(count($public)===1&&$public[0]['sender_type']==='system'
        &&preg_match('/submitt|sent|forward|request/i',$public[0]['message'])&&preg_match('/wait|respond|reply|response/i',$public[0]['message']),$label.' exposes a submitted/waiting system acknowledgment on poll');
    pa3_check(pa3_count('profile_agent_messages',"conversation_id=$cid AND sender_type='system'")===1
        &&pa3_count('profile_agent_messages',"conversation_id=$cid AND sender_type='agent'")===0,$label.' retains one hidden binding and zero Agent answers');
    pa3_check(pa3_count('agent_attention_items',"source_conversation_id=$cid AND status IN ('pending','seen','snoozed')")>0,$label.' leaves durable owner attention pending after acknowledgment');
    return $request;
}

pa3_reset();
$initial=vp3_profile_response_settings_v1($pdo,1);
pa3_check($initial['mode']==='automated'&&(int)$initial['revision']===0,'existing profiles default to Automated without inventing a saved revision');
$saved=response_mode_v1('user_responses');
pa3_check($saved['mode']==='user_responses'&&(int)$saved['revision']>0&&vp3_profile_response_mode_v1($pdo,1)==='user_responses','response setting persists through the real SQL helper');
$pdo->beginTransaction();
response_rejected_v1(fn()=>vp3_profile_response_settings_save_v1($pdo,1,'automated',0),'stale settings revision cannot overwrite the selected mode');
$pdo->rollBack();
pa3_check(vp3_profile_response_mode_v1($pdo,1)==='user_responses','a rejected stale save leaves the stored mode intact');

// Settings go through the actual existing owner configuration function too.
if(!column_exists('user_agents','is_profile_agent'))$pdo->exec('ALTER TABLE user_agents ADD COLUMN is_profile_agent INTEGER NOT NULL DEFAULT 0');
$settingsInput=['profile_agent_id'=>7,'profile_agent_enabled'=>1,'profile_agent_greeting'=>'Welcome from the owner.','profile_agent_instructions'=>'Use only approved facts.',
    'profile_agent_response_mode'=>'automated','profile_agent_response_revision'=>(int)$saved['revision']];
profile_configure_agent($pdo,pa3_owner(),$settingsInput);
pa3_check(vp3_profile_response_mode_v1($pdo,1)==='automated'&&profile_for_user($pdo,1,false)['profile_agent_greeting']==='Welcome from the owner.','real owner configuration saves mode and Profile fields together');
response_mode_v1('user_responses');
$legacyInput=$settingsInput;unset($legacyInput['profile_agent_response_mode'],$legacyInput['profile_agent_response_revision']);
profile_configure_agent($pdo,pa3_owner(),$legacyInput);
pa3_check(vp3_profile_response_mode_v1($pdo,1)==='user_responses','an older settings caller that omits mode preserves the owner selection');
$before=$pdo->query('SELECT * FROM user_profiles WHERE user_id=1')->fetch();
response_rejected_v1(fn()=>profile_configure_agent($pdo,pa3_owner(),array_replace($settingsInput,['profile_agent_greeting'=>'Must not persist','profile_agent_response_mode'=>'invalid'])),'invalid response mode is rejected by real owner configuration');
pa3_check($pdo->query('SELECT * FROM user_profiles WHERE user_id=1')->fetch()===$before,'invalid mode does not partially update greeting or other Profile fields');

foreach([false,true] as $reachable){
    pa3_reset();response_mode_v1('user_responses');if($reachable)pa3_presence();
    $key=bin2hex(random_bytes(16));$receipt=response_send_v1('Please ask the owner for their hours.',0,['client_request_id'=>$key]);
    $request=response_waiting_v1($receipt,$reachable?'reachable owner':'unavailable owner');$turn=pa3_turn($receipt);$cid=(int)$turn['conversation_id'];
    pa3_check($receipt['accepted']&&($receipt['response']['request']['id']??0)===$request['id'],'native admission returns the exact durable owner-request acknowledgment');
    pa3_check($GLOBALS['pa3ModelCalls']===[]&&$GLOBALS['pa3HomeCalls']===[],'User responses bypasses both inference routes despite stale client chat_mode=agent');
    $again=response_send_v1('Please ask the owner for their hours.',0,['client_request_id'=>$key]);
    pa3_check($again['duplicate']&&pa3_count('profile_agent_owner_requests_v1')===1&&pa3_count('profile_agent_messages',"sender_type='receipt'")===1,'admission retry returns one owner request and one submission receipt');
    $GLOBALS['pa3Now']+=60000;
    vp3_profile_handoff_tick_pa3($pdo,1,$cid);vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);vp3_profile_handoff_runner_pa3($pdo);
    $after=response_request_v1($cid);
    pa3_check(pa3_turn($receipt)['state']==='owner_requested'&&$after===$request&&$GLOBALS['pa3ModelCalls']===[]&&$GLOBALS['pa3HomeCalls']===[],'more than fifteen seconds, explicit dispatch, and maintenance cannot expire an owner request into inference');
    pa3_check(pa3_count('agent_workflow_runs',"status IN ('approved','executing')")===0,'User responses leaves no runnable automatic reply');
    $wire=json_encode(response_poll_v1($cid),JSON_THROW_ON_ERROR);
    pa3_check(!preg_match('/credential_hash|accepted_json|lease_token|password_hash|PRIVATE_CHAT_SECRET|FOREIGN_CHAT_SECRET/',$wire),'waiting projection excludes authority credentials and private Chat');
}
$ownerCard=vp3_profile_handoff_owner_card_pa3($pdo,pa3_turn($receipt),11,7);
pa3_check(array_column($ownerCard['history'],'sender_type')===['visitor','system']
    &&$ownerCard['history'][1]['id']===$request['receipt_message_id']
    &&preg_match('/wait|respond|reply|response/i',$ownerCard['history'][1]['message']),
    'actual owner-card history renders the exact submitted receipt as system status and excludes the hidden authority binding');

pa3_reset();$receipt=response_send_v1();vp3_profile_handoff_dispatch_pa3($pdo,(int)$receipt['handoff']['turn_id']);
$poll=response_poll_v1((int)$receipt['conversation_id']);$answers=array_values(array_filter($poll['messages'],static fn($row)=>$row['sender_type']==='agent'));
pa3_check(count($answers)===1&&$answers[0]['message']==='PA3 approved public answer'&&pa3_turn($receipt)['state']==='replied','Automated publishes the natural answer extracted from the structured provider envelope');
pa3_check(pa3_count('profile_agent_owner_requests_v1')===0&&pa3_count('profile_agent_messages',"sender_type='receipt'")===0,'a supported automated answer creates no owner request or submission receipt');
$sent=$GLOBALS['pa3ModelCalls'][0];$sources=array_column($sent['context'],'source');
pa3_check(in_array('knowledge:101',$sources,true)&&!in_array('knowledge:102',$sources,true)&&!in_array('knowledge:103',$sources,true),'Automated receives currently permitted knowledge and excludes private and foreign items');
pa3_check(($sent['control']['profile_answer_contract']??'')==='vp3.profile-answer.v1'&&($GLOBALS['pa3HomeCalls'][0]['structured']??false)===true,'both inference routes are explicitly instructed to use the structured Profile response contract');

foreach([
    'explicit knowledge gap'=>response_provider_v1('needs_owner','I cannot determine the unpublished price; PRIVATE_MODEL_EXPLANATION.'),
    'plain text'=>['ok'=>true,'answer'=>'Unsupported ordinary provider text'],
    'invalid JSON'=>['ok'=>true,'answer'=>'{bad json'],
    'fenced JSON'=>['ok'=>true,'answer'=>'```json'.json_encode(['disposition'=>'answered','answer'=>'Unsupported fenced answer']).'```'],
    'unknown disposition'=>response_provider_v1('maybe','Unsupported guess'),
    'extra fields'=>['ok'=>true,'answer'=>json_encode(['disposition'=>'answered','answer'=>'Unsupported extra fields','submitted'=>true])],
    'empty supported answer'=>response_provider_v1('answered','  '),
    'provider failure'=>['ok'=>false],
    'quota exhaustion'=>['ok'=>false,'quota_exhausted'=>true],
] as $label=>$output){
    pa3_reset();$GLOBALS['pa3ModelResult']=$output;$receipt=response_send_v1('What is the unpublished price?');
    vp3_profile_handoff_dispatch_pa3($pdo,(int)$receipt['handoff']['turn_id']);$request=response_waiting_v1($receipt,$label);
    pa3_check(count($GLOBALS['pa3ModelCalls'])===1&&pa3_count('profile_agent_owner_requests_v1')===1,$label.' creates one owner request after one model attempt');
    vp3_profile_handoff_dispatch_pa3($pdo,(int)$receipt['handoff']['turn_id']);
    pa3_check(count($GLOBALS['pa3ModelCalls'])===1&&pa3_count('profile_agent_messages',"sender_type='receipt'")===1,$label.' dispatch retry neither repeats inference nor acknowledgment');
    $wire=json_encode(response_poll_v1((int)$receipt['conversation_id']),JSON_THROW_ON_ERROR);
    pa3_check(!str_contains($wire,'PRIVATE_MODEL_EXPLANATION')&&!str_contains($wire,'Unsupported'),$label.' publishes the server acknowledgment instead of untrusted failure text');
}

foreach(['answered','needs_owner','malformed'] as $disposition){
    pa3_reset();$GLOBALS['pa3HomeResult']=['answer'=>$disposition==='malformed'?'unstructured local response':json_encode(['disposition'=>$disposition,'answer'=>$disposition==='answered'?'Public HomeServer answer':'PRIVATE_LOCAL_EXPLANATION'],JSON_THROW_ON_ERROR)];
    $receipt=response_send_v1();vp3_profile_handoff_dispatch_pa3($pdo,(int)$receipt['handoff']['turn_id']);
    pa3_check(count($GLOBALS['pa3HomeCalls'])===1&&$GLOBALS['pa3ModelCalls']===[],'HomeServer '.$disposition.' result never triggers a second Cloud attempt');
    if($disposition==='answered'){
        $messages=response_poll_v1((int)$receipt['conversation_id'])['messages'];
        pa3_check(end($messages)['message']==='Public HomeServer answer'&&pa3_count('profile_agent_owner_requests_v1')===0,'structured HomeServer answer is extracted and published naturally');
    }else response_waiting_v1($receipt,'HomeServer '.$disposition);
}

// A submitted knowledge gap is not a completed Agent answer. Explicit owner
// delegation must be able to resume the same canonical work after review.
pa3_reset();$GLOBALS['pa3ModelResult']=response_provider_v1('needs_owner','The approved answer is not available yet.');
$receipt=response_send_v1('What are the approved public hours?');vp3_profile_handoff_dispatch_pa3($pdo,(int)$receipt['handoff']['turn_id']);
response_waiting_v1($receipt,'delegatable automated knowledge gap');
pa3_check(pa3_count('agent_workflow_runs',"status='completed'")===0&&pa3_count('agent_workflow_receipts',"status='completed'")===0,'a knowledge-gap receipt cannot falsely complete the automatic-answer job');
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($receipt),'claim'),true);
$delegate=pa3_action_input(pa3_turn($receipt),'delegate');vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),$delegate,true);
pa3_check(response_request_v1((int)$receipt['conversation_id'])['status']==='delegated','owner review and delegation records the automated knowledge gap as delegated');
$GLOBALS['pa3ModelResult']=response_provider_v1('answered','Owner-approved public hours are Monday.');
vp3_profile_handoff_dispatch_pa3($pdo,(int)$receipt['handoff']['turn_id']);
pa3_check(pa3_turn($receipt)['state']==='replied'&&count($GLOBALS['pa3ModelCalls'])===2&&pa3_count('profile_agent_messages',"sender_type='agent'")===1,'delegating a former automated knowledge gap permits one new supported answer');
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),$delegate,true);vp3_profile_handoff_dispatch_pa3($pdo,(int)$receipt['handoff']['turn_id']);
pa3_check(count($GLOBALS['pa3ModelCalls'])===2&&pa3_count('profile_agent_messages',"sender_type='agent'")===1,'repeated delegation and dispatch after the supported answer cannot duplicate inference or publication');

pa3_reset();$GLOBALS['pa3ModelResult']=response_provider_v1('needs_owner','The owner information is still missing.');
$receipt=response_send_v1('A question still missing approved facts');vp3_profile_handoff_dispatch_pa3($pdo,(int)$receipt['handoff']['turn_id']);
$firstRequest=response_request_v1((int)$receipt['conversation_id']);
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($receipt),'delegate'),true);
$delegatedRequest=response_request_v1((int)$receipt['conversation_id']);
vp3_profile_handoff_dispatch_pa3($pdo,(int)$receipt['handoff']['turn_id']);$reopened=response_request_v1((int)$receipt['conversation_id']);
pa3_check($reopened['id']===$firstRequest['id']&&$reopened['status']==='waiting'&&$reopened['revision']>$delegatedRequest['revision']
    &&$reopened['receipt_message_id']!==$firstRequest['receipt_message_id']&&pa3_count('profile_agent_owner_requests_v1')===1,'a delegated question still needing owner input reopens the same durable request with a new revision and submission receipt');
vp3_profile_handoff_dispatch_pa3($pdo,(int)$receipt['handoff']['turn_id']);
pa3_check(response_request_v1((int)$receipt['conversation_id'])===$reopened&&pa3_count('profile_agent_messages',"sender_type='receipt'")===2
    &&count($GLOBALS['pa3ModelCalls'])===2,'retrying the reopened waiting request creates no further receipt or inference');

pa3_reset();$GLOBALS['pa3ModelResult']=response_provider_v1('needs_owner','Owner input is required.');$first=response_send_v1('First unanswered owner question');
vp3_profile_handoff_dispatch_pa3($pdo,(int)$first['handoff']['turn_id']);$firstRequest=response_request_v1((int)$first['conversation_id']);
pa3_age();$second=response_send_v1('A second question for the owner',(int)$first['conversation_id']);$secondTurn=pa3_turn($second);
pa3_check($secondTurn['state']==='owner_requested'&&pa3_count('profile_agent_owner_requests_v1',"status='waiting'")===2&&count($GLOBALS['pa3ModelCalls'])===1,'a pending owner request keeps later questions waiting despite an automated client choice');
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($first),'reply',['message'=>'Reply to the first question only.']),true);
pa3_check(response_row_v1($firstRequest['id'])['status']==='answered'&&pa3_count('profile_agent_owner_requests_v1',"status='waiting'")===1&&pa3_turn($second)['state']!=='replied','an owner reply through the first visitor message cannot falsely answer a later pending question');
pa3_check(response_attention_v1((int)$secondTurn['visitor_message_id'])['status']==='pending'
    &&response_attention_v1((int)pa3_turn($first)['visitor_message_id'])['status']==='handled','replying to the older question settles its exact attention and preserves the newer pending owner attention');
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($second),'reply',['message'=>'Reply to the second question.']),true);
pa3_check(pa3_count('profile_agent_owner_requests_v1',"status='answered'")===2&&pa3_count('profile_agent_messages',"sender_type='owner'")===2&&count($GLOBALS['pa3ModelCalls'])===1,'the later explicit owner reply completes the remaining request without a competing model');

foreach([false,true] as $switchMode){
    pa3_reset();$GLOBALS['pa3ModelResult']=response_provider_v1($switchMode?'answered':'needs_owner','The first inference is still in flight.');
    $first=response_send_v1('First question already being considered');$second=null;
    $GLOBALS['pa3ModelHook']=static function()use($first,$switchMode,&$second):void{
        $GLOBALS['pa3ModelHook']=null;if($switchMode)response_mode_v1('user_responses');pa3_age();
        $second=response_send_v1('Newer question admitted during that inference',(int)$first['conversation_id']);
    };
    vp3_profile_handoff_dispatch_pa3($pdo,(int)$first['handoff']['turn_id']);
    $label=$switchMode?'in-flight mode change':'in-flight knowledge gap';
    $orphans=(int)$pdo->query("SELECT COUNT(*) FROM profile_agent_handoff_turns_pa3 t WHERE t.state='owner_requested' AND NOT EXISTS (SELECT 1 FROM profile_agent_owner_requests_v1 r WHERE r.owner_user_id=t.owner_user_id AND r.visitor_message_id=t.visitor_message_id AND r.status='waiting')")->fetchColumn();
    pa3_check($second!==null&&$orphans===0&&pa3_count('profile_agent_owner_requests_v1',"status='waiting'")===2
        &&pa3_count('profile_agent_messages',"sender_type='receipt'")===2,$label.' gives every affected unanswered text turn its own durable request and acknowledgment');
    vp3_profile_handoff_dispatch_pa3($pdo,(int)$first['handoff']['turn_id']);vp3_profile_handoff_dispatch_pa3($pdo,(int)$second['handoff']['turn_id']);
    pa3_check(count($GLOBALS['pa3ModelCalls'])===1&&pa3_count('profile_agent_messages',"sender_type='agent'")===0
        &&pa3_count('profile_agent_messages',"sender_type='receipt'")===2,$label.' leaves no orphaned runnable turn or duplicate retry receipt');
}

pa3_reset();response_mode_v1('user_responses');$receipt=response_send_v1('First waiting visitor');$request=response_request_v1((int)$receipt['conversation_id']);
pa3_age();$other=response_send_v1('Another waiting conversation');$otherRequest=response_request_v1((int)$other['conversation_id']);
$input=pa3_action_input(pa3_turn($receipt),'reply',['message'=>'Owner-confirmed hours are Monday.']);
$reply=vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),$input,true);
$answered=response_request_v1((int)$receipt['conversation_id']);
pa3_check($answered['status']==='answered'&&$answered['reply_message_id']===(int)$reply['message_id']&&$answered['receipt_message_id']===$request['receipt_message_id'],'manual owner reply completes the exact waiting request and retains its original receipt');
pa3_check(response_request_v1((int)$other['conversation_id'])===$otherRequest,'answering one conversation leaves the other waiting request unchanged');
$before=pa3_count('profile_agent_messages');$retry=vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),$input,true);
pa3_check($retry['duplicate']&&pa3_count('profile_agent_messages')===$before&&response_request_v1((int)$receipt['conversation_id'])===$answered,'owner reply retry preserves one message and one request settlement');
response_rejected_v1(fn()=>vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(4),pa3_action_input(pa3_turn($other),'reply'),true),'foreign owner cannot complete a waiting request');
response_rejected_v1(fn()=>vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($other),'delegate'),true),'owner cannot delegate into automation while User responses remains selected');
pa3_check(response_request_v1((int)$other['conversation_id'])===$otherRequest&&$GLOBALS['pa3ModelCalls']===[],'rejected delegation preserves the pending owner request without inference');
response_mode_v1('automated');
pa3_check(response_request_v1((int)$other['conversation_id'])===$otherRequest,'changing the global setting alone does not release an existing owner request');
$delegate=pa3_action_input(pa3_turn($other),'delegate');
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),$delegate,true);
pa3_check(response_request_v1((int)$other['conversation_id'])['status']==='delegated','explicit owner delegation records a durable delegated disposition');
vp3_profile_handoff_dispatch_pa3($pdo,(int)$other['handoff']['turn_id']);
pa3_check(pa3_turn($other)['state']==='replied'&&count($GLOBALS['pa3ModelCalls'])===1,'Automated plus explicit delegation authorizes the pending visitor answer once');
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),$delegate,true);
pa3_check(count($GLOBALS['pa3ModelCalls'])===1&&pa3_count('profile_agent_messages',"sender_type='agent'")===1,'delegation retry cannot replay inference or publish a second answer');

pa3_reset();response_mode_v1('user_responses');$receipt=response_send_v1('Question the owner chooses to resolve');
$resolve=pa3_action_input(pa3_turn($receipt),'resolve');vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),$resolve,true);
pa3_check(response_request_v1((int)$receipt['conversation_id'])['status']==='resolved'&&pa3_count('profile_agent_messages',"sender_type='owner'")===0,'owner resolution marks the request resolved without inventing a human answer');
vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),$resolve,true);vp3_profile_handoff_dispatch_pa3($pdo,(int)$receipt['handoff']['turn_id']);
pa3_check($GLOBALS['pa3ModelCalls']===[]&&$GLOBALS['pa3HomeCalls']===[],'resolution retry and old dispatch cannot revive automatic inference');

foreach(['before','during'] as $when){
    pa3_reset();$receipt=response_send_v1();
    if($when==='before')response_mode_v1('user_responses');
    else $GLOBALS['pa3ModelHook']=static fn()=>response_mode_v1('user_responses');
    vp3_profile_handoff_dispatch_pa3($pdo,(int)$receipt['handoff']['turn_id']);
    pa3_check(pa3_count('profile_agent_messages',"sender_type='agent'")===0,'switching to User responses '.$when.' inference suppresses automated publication');
    if($when==='before')pa3_check($GLOBALS['pa3ModelCalls']===[]&&$GLOBALS['pa3HomeCalls']===[],'queued work rechecks User responses before either inference route');
}
pa3_reset();$receipt=response_send_v1();$GLOBALS['pa3ModelHook']=static fn()=>db()->exec("UPDATE user_data_policies SET profile_agent_allowed=0 WHERE resource_type='knowledge'");
vp3_profile_handoff_dispatch_pa3($pdo,(int)$receipt['handoff']['turn_id']);
pa3_check(pa3_count('profile_agent_messages',"sender_type='agent'")===0,'source sharing revoked in flight cannot publish a structured answered result');

pa3_reset();response_mode_v1('user_responses');$pdo->failSql='INSERT INTO profile_agent_owner_requests_v1';
response_rejected_v1(fn()=>response_send_v1('Must not claim submission on failed storage'),'request persistence failure is unsuccessful');$pdo->failSql='';
pa3_check(pa3_count('profile_agent_owner_requests_v1')===0&&pa3_count('profile_agent_messages')===0&&pa3_count('profile_agent_handoff_turns_pa3')===0,'failed request storage rolls back the visitor admission and receipt together');

echo 'PROFILE_RESPONSE_MODES_V1=PASS ('.$checks.' cases; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)."; real SQL, inference transports mocked)\n";
