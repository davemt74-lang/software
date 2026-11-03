<?php
declare(strict_types=1);
require __DIR__.'/fixtures/profile-handoff-pa3.php';

$checks=0;
function pa3_check(bool $ok,string $label): void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;echo "PASS $label\n";}
function pa3_denied(callable $work,string $label,?string $code=null): void
{
    try{$work();}catch(VP3ProfileHandoffException|VP3ProfileAgentPublicException $e){pa3_check($code===null||$e->publicCode===$code,$label.' ('.$e->publicCode.')');return;}
    throw new RuntimeException($label.' unexpectedly succeeded');
}
function pa3_presence(array $extra=[],int $owner=1): array
{
    return vp3_profile_handoff_presence_pa3(db(),pa3_owner($owner),array_replace(['tab_id'=>str_repeat('c',32),'chat_conversation_id'=>11,'chat_agent_id'=>7,'visible'=>true,'presence_sequence'=>1],$extra));
}
function pa3_turn(array $receipt): array{return vp3_profile_handoff_row_pa3(db(),(int)$receipt['handoff']['turn_id'])??throw new RuntimeException('Missing saved turn.');}
function pa3_delivery(array $turn,array $extra=[]): array
{
    return vp3_profile_handoff_delivery_pa3(db(),pa3_owner(),array_replace(['turn_id'=>(int)$turn['id'],'revision'=>(int)$turn['version'],'tab_id'=>str_repeat('c',32),'chat_conversation_id'=>11,'chat_agent_id'=>7],$extra));
}
function pa3_ack_input(array $delivery,array $extra=[]): array
{
    return array_replace(['turn_id'=>$delivery['turn']['turn_id'],'revision'=>$delivery['turn']['revision'],'tab_id'=>str_repeat('c',32),'delivery_id'=>$delivery['delivery_id'],'chat_conversation_id'=>$delivery['chat_conversation_id'],'chat_agent_id'=>$delivery['chat_agent_id'],'chat_message_id'=>$delivery['chat_message_id']],$extra);
}
function pa3_action_input(array $turn,string $operation='reply',array $extra=[]): array
{
    return array_replace(['operation'=>$operation,'turn_id'=>(int)$turn['id'],'revision'=>(int)$turn['version'],'conversation_id'=>(int)$turn['conversation_id'],'chat_conversation_id'=>11,'chat_agent_id'=>7,'request_id'=>bin2hex(random_bytes(16)),'message'=>'Owner public reply'], $extra);
}
function pa3_dispatch_revocable(int $turnId): void
{
    try{vp3_profile_handoff_dispatch_pa3(db(),$turnId);}
    catch(VP3ProfileHandoffException|VP3ProfileAgentPublicException $error){
        if(!in_array($error->publicCode,['HANDOFF_UNAVAILABLE','PROFILE_AGENT_UNAVAILABLE'],true))throw $error;
    }
}
if(defined('PA3_HANDOFF_HELPERS_ONLY')&&PA3_HANDOFF_HELPERS_ONLY)return;

pa3_reset();
pa3_check(agent_job_engine_schema_ready_v1900($pdo)&&vp3_profile_handoff_schema_ready_pa3($pdo),'production job and handoff schema initialize on actual SQL');
$pdo->exec('DROP TABLE profile_agent_handoff_deliveries_pa3');
pa3_check(!vp3_profile_handoff_schema_ready_pa3($pdo),'missing delivery table cannot report schema ready');
$pdo->beginTransaction();try{vp3_profile_handoff_ensure_schema_pa3($pdo);throw new RuntimeException('DDL ran inside admission');}catch(RuntimeException $e){pa3_check($e->getMessage()==='Profile handoff schema must be initialized before admission.','schema installation refuses implicit transaction commit');}finally{$pdo->rollBack();}
vp3_profile_handoff_ensure_schema_pa3($pdo);vp3_profile_handoff_ensure_schema_pa3($pdo);
pa3_check(vp3_profile_handoff_schema_ready_pa3($pdo),'outside-transaction schema initialization is repeatable');

pa3_check(!vp3_profile_handoff_reachable_pa3($pdo,1),'owner without visible main Chat is unavailable');
pa3_presence();pa3_check(vp3_profile_handoff_reachable_pa3($pdo,1),'fresh owned main Chat makes available owner reachable');
pa3_presence(['presence_sequence'=>2,'visible'=>false]);
$stale=pa3_presence(['presence_sequence'=>1]);
pa3_check(!$stale['accepted']&&!vp3_profile_handoff_reachable_pa3($pdo,1),'late visible heartbeat cannot undo newer hidden state');
pa3_presence(['presence_sequence'=>3,'availability'=>'away']);
pa3_check(!vp3_profile_handoff_reachable_pa3($pdo,1),'Away overrides a visible Chat tab');
pa3_presence(['presence_sequence'=>4,'availability'=>'dnd']);
pa3_check(!vp3_profile_handoff_reachable_pa3($pdo,1),'Do Not Disturb overrides a visible Chat tab');
pa3_presence(['presence_sequence'=>5,'availability'=>'available']);
$GLOBALS['pa3Now']+=45001;
pa3_check(!vp3_profile_handoff_reachable_pa3($pdo,1),'expired per-tab presence cannot delay the visitor');
pa3_presence(['presence_sequence'=>6]);
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');
pa3_check(!vp3_profile_handoff_reachable_pa3($pdo,1),'deactivated main Chat Agent cannot establish reachability');
$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=7');
foreach([['visible'=>'true'],['tab_id'=>'invalid'],['presence_sequence'=>0],['chat_conversation_id'=>13,'chat_agent_id'=>9],['chat_agent_id'=>8]] as $bad)pa3_denied(fn()=>pa3_presence(array_replace(['presence_sequence'=>7],$bad)),'presence rejects invalid or foreign context');
$pdo->exec("DELETE FROM role_permissions WHERE permission_key='chat.access'");
pa3_denied(fn()=>pa3_presence(['presence_sequence'=>7]),'current owner Chat access is required','HANDOFF_UNAVAILABLE');

pa3_reset();$request=str_repeat('d',32);$receipt=pa3_admit('What are the public hours?',0,$request);$turn=pa3_turn($receipt);
pa3_check($receipt['accepted']&&$receipt['answer']===''&&$turn['state']==='agent_ready'&&count($GLOBALS['pa3ModelCalls'])===0,'unavailable owner queues exact Agent work without synchronous inference');
pa3_check(pa3_count('profile_agent_messages',"sender_type='visitor'")===1&&pa3_count('profile_agent_handoff_turns_pa3')===1&&pa3_count('agent_workflow_runs')===1&&pa3_count('agent_workflow_actions')===1,'visitor admission saves one message, turn, run and action');
pa3_check((int)$turn['notification_id']>0&&pa3_count('agent_attention_items')>0,'admitted turn retains exact durable notification and attention lineage');
$again=pa3_admit('What are the public hours?',0,$request);
pa3_check($again['duplicate']&&$again['handoff']['turn_id']===$receipt['handoff']['turn_id']&&pa3_count('profile_agent_messages',"sender_type='visitor'")===1,'lost admission response retries the same exact saved request');
pa3_denied(fn()=>pa3_admit('A different question',0,$request),'same request cannot be repurposed','HANDOFF_REPLAY_CONFLICT');
$before=$pdo->query('SELECT * FROM profile_agent_handoff_turns_pa3')->fetchAll();
vp3_profile_agent_public_poll_service_v110($pdo,pa3_context(),(int)$turn['conversation_id']);
vp3_profile_handoff_owner_list_pa3($pdo,pa3_owner(),['chat_conversation_id'=>11,'chat_agent_id'=>7]);
pa3_check($before===$pdo->query('SELECT * FROM profile_agent_handoff_turns_pa3')->fetchAll()&&count($GLOBALS['pa3ModelCalls'])===0,'public and owner read projections neither advance state nor start inference');
$wire=json_encode($receipt,JSON_THROW_ON_ERROR);
foreach(['credential_hash','accepted_json','lease_token','password','PRIVATE_CHAT_SECRET','FOREIGN_CHAT_SECRET'] as $secret)pa3_check(!str_contains($wire,$secret),'public receipt excludes '.$secret);
foreach(['INSERT INTO profile_agent_handoff_turns_pa3','INSERT INTO agent_workflow_runs','INSERT INTO agent_workflow_actions'] as $fault){
    pa3_reset();$pdo->failSql=$fault;
    try{pa3_admit();throw new RuntimeException('Admission succeeded during SQL failure');}catch(PDOException $e){$pdo->failSql='';}
    pa3_check(pa3_count('profile_agent_messages')===0&&pa3_count('profile_agent_conversations')===0&&pa3_count('profile_events')===0&&pa3_count('notifications')===0&&pa3_count('profile_agent_handoff_turns_pa3')===0&&pa3_count('agent_workflow_runs')===0&&pa3_count('agent_workflow_actions')===0,'failure at '.$fault.' rolls back the entire accepted message');
}

pa3_reset();pa3_presence();$receipt=pa3_admit();$turn=pa3_turn($receipt);$cid=(int)$turn['conversation_id'];$tid=(int)$turn['id'];
pa3_check($turn['state']==='awaiting_delivery'&&$turn['pickup_deadline_ms']===null,'reachable owner waits for an exact visible-card acknowledgement');
$delivery=pa3_delivery($turn);$ack=pa3_ack_input($delivery);
pa3_check(pa3_turn($receipt)['pickup_deadline_ms']===null,'creating a private Chat card does not start pickup time');
$pdo->exec('UPDATE notifications SET is_read=1');
pa3_check(pa3_turn($receipt)['pickup_deadline_ms']===null,'marking notification read is not visible-card acknowledgement');
$list=vp3_profile_handoff_owner_list_pa3($pdo,pa3_owner(),['chat_conversation_id'=>11,'chat_agent_id'=>7]);
pa3_check(count($list['turns'])===1,'read notification does not remove the recoverable active visitor card');
foreach([['turn_id'=>$tid+999],['revision'=>99],['tab_id'=>str_repeat('e',32)],['delivery_id'=>str_repeat('f',32)],['chat_message_id'=>$delivery['chat_message_id']+999],['chat_conversation_id'=>12,'chat_agent_id'=>8]] as $bad)pa3_denied(fn()=>vp3_profile_handoff_ack_pa3($pdo,pa3_owner(),array_replace($ack,$bad)),'ACK rejects mismatched exact delivery binding');
pa3_denied(fn()=>vp3_profile_handoff_ack_pa3($pdo,pa3_owner(4),$ack),'foreign owner cannot acknowledge the card');
$GLOBALS['pa3Now']+=1234;$firstAck=$GLOBALS['pa3Now'];$acked=vp3_profile_handoff_ack_pa3($pdo,pa3_owner(),$ack);$turn=pa3_turn($receipt);
pa3_check((int)$turn['acknowledged_at_ms']===$firstAck&&(int)$turn['pickup_deadline_ms']===$firstAck+15000&&$turn['state']==='awaiting_owner','first exact ACK sets an exact server-owned 15000ms pickup deadline');
$GLOBALS['pa3Now']+=700;
$repeated=vp3_profile_handoff_ack_pa3($pdo,pa3_owner(),$ack+['server_now_ms'=>0,'pickup_deadline_ms'=>9999999999999]);
pa3_check($repeated['duplicate']&&(int)pa3_turn($receipt)['pickup_deadline_ms']===$firstAck+15000,'duplicate ACK and client timestamps cannot extend the deadline');
$GLOBALS['pa3Now']=$firstAck+14999;vp3_profile_handoff_tick_pa3($pdo,1,$cid);
pa3_check(pa3_turn($receipt)['state']==='awaiting_owner','Agent is ineligible one millisecond before pickup expiry');
$GLOBALS['pa3Now']=$firstAck+15000;vp3_profile_handoff_tick_pa3($pdo,1,$cid);
pa3_check(pa3_turn($receipt)['state']==='agent_ready','Agent becomes eligible at exactly the server pickup deadline');
pa3_check(count($GLOBALS['pa3ModelCalls'])===0,'deadline scheduling itself does not perform paid inference');

pa3_reset();pa3_presence();$receipt=pa3_admit();$turn=pa3_turn($receipt);$delivery=pa3_delivery($turn);
$GLOBALS['pa3Now']+=5000;
pa3_denied(fn()=>vp3_profile_handoff_ack_pa3($pdo,pa3_owner(),pa3_ack_input($delivery)),'late card ACK cannot resurrect an expired delivery grace','HANDOFF_CHANGED');
vp3_profile_handoff_tick_pa3($pdo,1,(int)$turn['conversation_id']);
pa3_check(pa3_turn($receipt)['state']==='agent_ready','missing delivery ACK has bounded fallback to Agent');

pa3_reset();$receipt=pa3_admit();$turn=pa3_turn($receipt);$cid=(int)$turn['conversation_id'];
pa3_login(4);$_COOKIE=[]; // A job is not a fabricated owner browser session.
vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);$finished=pa3_turn($receipt);
pa3_check($finished['state']==='replied'&&(int)$finished['answer_message_id']>0&&pa3_count('profile_agent_messages',"sender_type='agent'")===1,'worker completes accepted public turn independently of current browser login');
pa3_check(count($GLOBALS['pa3ModelCalls'])===1&&$GLOBALS['pa3ModelCalls'][0]['scope']==='profile_agent'&&(int)$GLOBALS['pa3ModelCalls'][0]['user']['id']===1,'worker inference retains explicit owner allowance and public Profile scope');
pa3_check(!str_contains(json_encode($GLOBALS['pa3ModelCalls']),'PRIVATE_CHAT_SECRET')&&!str_contains(json_encode($GLOBALS['pa3ModelCalls']),'FOREIGN_CHAT_SECRET'),'private main Chat never enters public worker history');
vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);
pa3_check(count($GLOBALS['pa3ModelCalls'])===1&&pa3_count('agent_workflow_receipts',"status='completed'")===1,'completed dispatch retry neither reruns model nor duplicates completion receipt');
pa3_age();pa3_presence();$second=pa3_admit('Another public question',$cid);
pa3_check($second['handoff']['state']==='agent_ready','Agent-handled conversation skips another owner pickup window');

foreach(['claim','reply','resolve'] as $operation){
    pa3_reset();$receipt=pa3_admit();$turn=pa3_turn($receipt);$ownerInput=null;
    $GLOBALS['pa3ModelHook']=function()use($receipt,$operation,&$ownerInput){$fresh=pa3_turn($receipt);$ownerInput=pa3_action_input($fresh,$operation);vp3_profile_handoff_owner_action_pa3(db(),pa3_owner(),$ownerInput);};
    vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);
    pa3_check(pa3_count('profile_agent_messages',"sender_type='agent'")===0&&pa3_count('agent_workflow_receipts',"status='completed'")===0,'owner '.$operation.' during inference suppresses late Agent text and successful receipt');
    if($operation==='reply'){
        $again=vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),$ownerInput);
        pa3_check($again['duplicate']&&pa3_count('profile_agent_messages',"sender_type='owner'")===1,'lost owner reply response retries one exact public message');
        pa3_denied(fn()=>vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),array_replace($ownerInput,['message'=>'Different reply'])),'owner retry key cannot authorize changed text','HANDOFF_REPLAY_CONFLICT');
    }
}

$mutations=[
    'private Profile'=>fn()=>$pdo->exec('UPDATE user_profiles SET is_public=0 WHERE user_id=1'),
    'disabled Profile Agent'=>fn()=>$pdo->exec('UPDATE user_profiles SET profile_agent_enabled=0 WHERE user_id=1'),
    'replaced selected Agent'=>fn()=>$pdo->exec('UPDATE user_profiles SET profile_agent_id=8 WHERE user_id=1'),
    'disabled owner'=>fn()=>$pdo->exec('UPDATE users SET is_active=0 WHERE id=1'),
    'disabled public Agent'=>fn()=>$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7'),
    'disabled visitor'=>fn()=>$pdo->exec('UPDATE users SET is_active=0 WHERE id=2'),
    'changed visitor password'=>fn()=>$pdo->exec("UPDATE users SET password_hash='new-password' WHERE id=2"),
    'revoked public permission'=>fn()=>$pdo->exec("DELETE FROM role_permissions WHERE permission_key='profile_chat.access'"),
    'changed accepted question'=>fn()=>$pdo->exec("UPDATE profile_agent_messages SET message='changed' WHERE sender_type='visitor'"),
    'changed Profile instructions'=>fn()=>$pdo->exec("UPDATE user_profiles SET profile_agent_instructions='changed' WHERE user_id=1"),
];
foreach($mutations as $label=>$mutate){
    foreach(['before','during'] as $stage){
        pa3_reset();$receipt=pa3_admit('What are the public hours?',0,null,2);$turn=pa3_turn($receipt);
        if($stage==='before')$mutate();else $GLOBALS['pa3ModelHook']=$mutate;
        pa3_dispatch_revocable((int)$turn['id']);
        pa3_check(pa3_count('profile_agent_messages',"sender_type='agent'")===0&&pa3_count('agent_workflow_receipts',"status='completed'")===0,$label.' '.$stage.' inference prevents publication');
        if($stage==='before')pa3_check(count($GLOBALS['pa3ModelCalls'])===0,$label.' is rejected before any model request');
    }
}
foreach([
    'knowledge visibility'=>fn()=>$pdo->exec("UPDATE knowledge_items SET visibility='private' WHERE id=101"),
    'knowledge audience'=>fn()=>$pdo->exec("UPDATE user_data_policies SET audience_scope='private' WHERE resource_type='knowledge'"),
    'knowledge sharing'=>fn()=>$pdo->exec("UPDATE user_data_policies SET profile_agent_allowed=0 WHERE resource_type='knowledge'"),
    'Agent source rule'=>fn()=>$pdo->exec("INSERT INTO user_agent_data_rules VALUES(7,'knowledge','101','deny')"),
    'HomeServer sharing'=>fn()=>$pdo->exec("UPDATE user_data_policies SET profile_agent_allowed=0 WHERE resource_type='homeserver_knowledge'"),
] as $label=>$mutate){
    pa3_reset();$receipt=pa3_admit('What are the hours?',0,null,2);$GLOBALS['pa3ModelHook']=$mutate;vp3_profile_handoff_dispatch_pa3($pdo,(int)$receipt['handoff']['turn_id']);
    pa3_check(pa3_count('profile_agent_messages',"sender_type='agent'")===0,$label.' revoked during inference suppresses derived public answer');
}

pa3_reset();$receipt=pa3_admit('Visitor A question',0,null,2);$turn=pa3_turn($receipt);
$card=vp3_profile_handoff_owner_card_pa3($pdo,$turn);
pa3_check($card['visitor_label']==='Visitor A','visitor card names the explicitly disclosed admitted member');
$pdo->prepare('UPDATE profile_visit_sessions SET visitor_user_id=3,identity_disclosed=1 WHERE id=?')->execute([(int)$turn['profile_session_id']]);
$card=vp3_profile_handoff_owner_card_pa3($pdo,$turn);
pa3_check($card['visitor_label']==='Visitor'&&!str_contains(json_encode($card),'Visitor B'),'changed CRM principal cannot rename or disclose a different member on the old card');
pa3_reset();$receipt=pa3_admit();$turn=pa3_turn($receipt);
$pdo->prepare('UPDATE profile_visit_sessions SET visitor_user_id=3,identity_disclosed=1 WHERE id=?')->execute([(int)$turn['profile_session_id']]);
pa3_check(vp3_profile_handoff_owner_card_pa3($pdo,$turn)['visitor_label']==='Visitor','guest card remains a guest after shared-browser member recognition');

pa3_reset();$receipt=pa3_admit('First durable question');$turn=pa3_turn($receipt);$next=null;
$GLOBALS['pa3ModelHook']=function()use($pdo,$turn,&$next){
    $GLOBALS['pa3ModelHook']=null;pa3_age();
    $next=vp3_profile_agent_public_message_service_v110($pdo,pa3_context(),'Retained caller follow-up',(int)$turn['conversation_id']);
};
vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);
pa3_check(!empty($next['accepted'])&&count($GLOBALS['pa3ModelCalls'])===1&&pa3_count('profile_agent_messages',"sender_type='agent'")===1,'retained native caller joins durable work instead of competing with active generation');
vp3_profile_handoff_dispatch_pa3($pdo,(int)$next['handoff']['turn_id']);
pa3_check(count($GLOBALS['pa3ModelCalls'])===2&&pa3_count('profile_agent_messages',"sender_type='agent'")===2,'retained native follow-up completes through the same ordered conversation queue');

pa3_reset();$receipt=pa3_admit();$turn=pa3_turn($receipt);
$GLOBALS['pa3ModelHook']=function()use($pdo,$receipt){
    $current=pa3_turn($receipt);
    vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input($current));
    vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input(pa3_turn($receipt),'delegate'));
};
vp3_profile_handoff_dispatch_pa3($pdo,(int)$turn['id']);
pa3_check(pa3_count('profile_agent_messages',"sender_type='owner'")===1&&pa3_count('profile_agent_messages',"sender_type='agent'")===0,'owner reply followed by delegation during generation suppresses the obsolete Agent answer');

echo 'PROFILE_HANDOFF_PA3=PASS ('.$checks.' cases; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
