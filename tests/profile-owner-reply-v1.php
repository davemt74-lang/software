<?php
declare(strict_types=1);

define('PA3_HANDOFF_HELPERS_ONLY',true);
require __DIR__.'/profile-handoff-pa3.php';
require __DIR__.'/../includes/profile-owner-reply-v1.php';

/** Read the receipt through the same current owner/conversation boundary as HTTP. */
function reply_recover(int $cid,string $request,string $message,?array $user=null): ?array
{
    $pdo=db();$user??=pa3_owner();$pdo->beginTransaction();
    try{
        $live=vp3_profile_owner_reply_lock_v1($pdo,$user);
        if(!vp3_profile_agent_owner_conversation_v390($pdo,$cid,(int)$live['id'],true)){
            throw new VP3ProfileAgentPublicException('CONVERSATION_NOT_FOUND','Conversation not found.',404);
        }
        return vp3_profile_owner_reply_recover_v1($pdo,$live,$cid,$request,$message);
    }finally{if($pdo->inTransaction())$pdo->rollBack();}
}
function reply_snapshot(): array
{
    $out=[];
    foreach(['profile_agent_messages','profile_agent_conversations','profile_events','agent_attention_items','notifications','profile_agent_handoff_turns_pa3','profile_agent_handoff_conversations_pa3','profile_agent_handoff_operations_pa3','agent_workflow_runs','agent_workflow_actions','agent_workflow_receipts'] as $table){
        $out[$table]=db()->query('SELECT * FROM '.$table)->fetchAll();
    }
    return $out;
}
function reply_history(int $owner=1): int
{
    db()->prepare("INSERT INTO profile_agent_conversations(owner_user_id,profile_agent_id,status) VALUES(?,7,'open')")->execute([$owner]);
    return (int)db()->lastInsertId();
}
function reply_history_save(int $cid,string $request,string $message,int $owner=1,?PDO $connection=null): int
{
    $connection??=db();
    $context=['profile_owner_reply_v1'=>vp3_profile_owner_reply_marker_v1($owner,$cid,$request,$message)];
    $connection->prepare("INSERT INTO profile_agent_messages(conversation_id,sender_type,sender_user_id,message,context_json) VALUES(?,'owner',?,?,?)")
        ->execute([$cid,$owner,$message,json_encode($context,JSON_THROW_ON_ERROR)]);
    return (int)$connection->lastInsertId();
}

pa3_reset();
foreach([null,'',[],str_repeat('a',31),str_repeat('G',32)] as $invalid){
    pa3_denied(fn()=>vp3_profile_owner_reply_request_v1($invalid),'missing/invalid retry identity refuses an untracked send','OWNER_REPLY_REQUEST_REQUIRED');
}
pa3_check(vp3_profile_owner_reply_request_v1(str_repeat('a',32))===str_repeat('a',32),'valid client retry identity is preserved exactly');

// Named-Agent and system-Agent main Chat both admit real visitor work. Legacy
// inbox replies must recover their original message after the turn has moved on.
foreach([7,0] as $chatAgent){
    pa3_reset();$name=$chatAgent?'named Agent':'system Agent';
    if(!$chatAgent)$pdo->exec('UPDATE chat_conversations SET user_agent_id=NULL WHERE id=11');
    pa3_presence(['chat_agent_id'=>$chatAgent]);$admitted=pa3_admit();$turn=pa3_turn($admitted);
    $cid=(int)$turn['conversation_id'];$request=str_repeat('a',32);$message='Owner reply with exact request identity.';
    pa3_check($turn['state']==='awaiting_delivery',$name.' fixture has a real active accepted visitor turn');
    $sent=vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input($turn,'reply',['request_id'=>$request,'message'=>$message]),true);
    $snapshot=reply_snapshot();$recovered=reply_recover($cid,$request,$message);
    pa3_check($recovered===['message_id'=>$sent['message_id'],'duplicate'=>true],$name.' committed reply recovers exact message despite advanced turn revision');
    pa3_check(reply_snapshot()===$snapshot,$name.' recovery changes no responder fence, message, attention, event or job');
    pa3_denied(fn()=>reply_recover($cid,$request,'Different retry body'),$name.' request reuse cannot change the saved text','OWNER_REPLY_CONFLICT');
    $other=reply_history();pa3_denied(fn()=>reply_recover($other,$request,$message),$name.' owner-wide request cannot move into another owned conversation','OWNER_REPLY_CONFLICT');
    pa3_denied(fn()=>reply_recover($cid,$request,$message,pa3_owner(4)),$name.' another owner cannot recover this receipt','CONVERSATION_NOT_FOUND');

    pa3_age();$next=pa3_admit('A later visitor question',$cid);$nextTurn=pa3_turn($next);
    pa3_check((int)$nextTurn['id']!==(int)$turn['id'],$name.' later visitor message creates a different accepted turn');
    foreach(['delegate','resolve'] as $operation){
        $fresh=pa3_turn($next);
        vp3_profile_handoff_owner_action_pa3($pdo,pa3_owner(),pa3_action_input($fresh,$operation),true);
        $snapshot=reply_snapshot();$recovered=reply_recover($cid,$request,$message);
        pa3_check($recovered['message_id']===$sent['message_id']&&reply_snapshot()===$snapshot,$name.' late retry preserves later '.$operation.' decision and original receipt');
    }
    pa3_check(count($GLOBALS['pa3ModelCalls'])===0&&pa3_count('profile_agent_messages',"sender_type='owner'")===1,$name.' retry never dispatches competing Agent work or another owner reply');

    $row=$pdo->query('SELECT * FROM profile_agent_messages WHERE id='.(int)$sent['message_id'])->fetch();
    foreach([
        ['message','Replaced saved text'],['sender_type','visitor'],['sender_user_id',4],
        ['context_json','{}'],
        ['context_json',json_encode(['profile_handoff'=>['contract'=>VP3_PROFILE_HANDOFF_PA3,'turn_id'=>(int)$turn['id']+1,'owner_request_id'=>$request]],JSON_THROW_ON_ERROR)],
    ] as [$field,$value]){
        $pdo->prepare('UPDATE profile_agent_messages SET '.$field.'=? WHERE id=?')->execute([$value,$sent['message_id']]);
        pa3_denied(fn()=>reply_recover($cid,$request,$message),$name.' receipt rejects altered '.$field,'OWNER_REPLY_CONFLICT');
        $pdo->prepare('UPDATE profile_agent_messages SET '.$field.'=? WHERE id=?')->execute([$row[$field],$sent['message_id']]);
    }
    $staleOwner=pa3_owner();$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
    pa3_denied(fn()=>reply_recover($cid,$request,$message,$staleOwner),$name.' stale authenticated owner cannot bypass current deactivation','OWNER_REPLY_UNAVAILABLE');
    $pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
    $pdo->exec("DELETE FROM role_permissions WHERE permission_key='profile_agent.access'");
    pa3_denied(fn()=>reply_recover($cid,$request,$message,$staleOwner),$name.' saved receipt still requires current Profile permission','OWNER_REPLY_UNAVAILABLE');
}

// Historical conversations do not have a handoff turn. Validate stored message
// provenance independently of JSON formatting or the currently selected thread.
pa3_reset();$cid=reply_history();$other=reply_history();$request=str_repeat('b',32);$message="Historical owner reply — exact body.";
pa3_check(reply_recover($cid,$request,$message)===null,'new historical request has no false saved receipt');
$mid=reply_history_save($cid,$request,$message);$snapshot=reply_snapshot();
pa3_check(reply_recover($cid,$request,$message)===['message_id'=>$mid,'duplicate'=>true]&&reply_snapshot()===$snapshot,'historical reply recovers one exact saved message without changing state');
$context=['profile_owner_reply_v1'=>array_reverse(vp3_profile_owner_reply_marker_v1(1,$cid,$request,$message),true),'unrelated'=>['retained'=>'metadata']];
$pretty=json_encode($context,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$pdo->prepare('UPDATE profile_agent_messages SET context_json=? WHERE id=?')->execute([$pretty,$mid]);
pa3_check(reply_recover($cid,$request,$message)['message_id']===$mid,'equivalent metadata whitespace, key ordering and extra fields retain the historical receipt');
pa3_denied(fn()=>reply_recover($cid,$request,'Different historical body'),'historical token cannot authorize changed content','OWNER_REPLY_CONFLICT');
pa3_denied(fn()=>reply_recover($other,$request,$message),'historical token is owner-wide across owned conversations','OWNER_REPLY_CONFLICT');
pa3_check(reply_recover($cid,str_repeat('c',32),$message)===null,'an intentional new identical reply uses a different request identity');
foreach([
    ['message','Altered body'],['sender_type','visitor'],['sender_user_id',4],['conversation_id',$other],
    ['context_json',json_encode(['profile_owner_reply_v1'=>['contract'=>VP3_PROFILE_OWNER_REPLY_V1,'request_id'=>$request,'request_hash'=>str_repeat('0',64)]],JSON_THROW_ON_ERROR)],
    ['context_json','{"unrelated_request":"'.$request.'"}'],
] as [$field,$value]){
    $original=['message'=>$message,'sender_type'=>'owner','sender_user_id'=>1,'conversation_id'=>$cid,'context_json'=>$pretty][$field];
    $pdo->prepare('UPDATE profile_agent_messages SET '.$field.'=? WHERE id=?')->execute([$value,$mid]);
    pa3_denied(fn()=>reply_recover($cid,$request,$message),'historical receipt rejects altered '.$field,'OWNER_REPLY_CONFLICT');
    $pdo->prepare('UPDATE profile_agent_messages SET '.$field.'=? WHERE id=?')->execute([$original,$mid]);
}
$duplicate=reply_history_save($cid,$request,$message);
pa3_denied(fn()=>reply_recover($cid,$request,$message),'ambiguous duplicate historical markers fail closed','OWNER_REPLY_CONFLICT');
$pdo->prepare('DELETE FROM profile_agent_messages WHERE id=?')->execute([$duplicate]);
$foreignCid=reply_history(4);$foreignMid=reply_history_save($foreignCid,$request,$message,4);
pa3_check(reply_recover($foreignCid,$request,$message,pa3_owner(4))['message_id']===$foreignMid,'different owners have independent request namespaces');
pa3_denied(fn()=>reply_recover($foreignCid,$request,$message),'historical receipt cannot cross current conversation ownership','CONVERSATION_NOT_FOUND');
$rollbackRequest=str_repeat('d',32);$pdo->beginTransaction();reply_history_save($cid,$rollbackRequest,$message);$pdo->rollBack();
pa3_check(reply_recover($cid,$rollbackRequest,$message)===null,'rolled-back historical message cannot create a successful receipt');

if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
    // Use an independent native connection: real InnoDB locking/current reads,
    // not the fixture's SQLite SQL translation or simulated concurrency.
    $cfg=$GLOBALS['pa3Config'];$second=new PDO($cfg['dsn'],$cfg['user'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $second->exec('SET SESSION innodb_lock_wait_timeout=1');
    $pdo->beginTransaction();vp3_profile_owner_reply_lock_v1($pdo,pa3_owner());$blocked=false;$second->beginTransaction();
    try{$second->query('SELECT id FROM users WHERE id=1 FOR UPDATE')->fetchColumn();}
    catch(PDOException $error){$blocked=(int)($error->errorInfo[1]??0)===1205;}
    finally{if($second->inTransaction())$second->rollBack();$pdo->rollBack();}
    pa3_check($blocked,'MySQL current owner row serializes a concurrent reply transaction');
    $second->beginTransaction();$second->query('SELECT id FROM users WHERE id=1 FOR UPDATE')->fetchColumn();$second->rollBack();
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$pdo->beginTransaction();
    $pdo->query('SELECT COUNT(*) FROM profile_agent_messages')->fetchColumn(); // Start an older consistent snapshot.
    $lateRequest=str_repeat('e',32);$lateMid=reply_history_save($cid,$lateRequest,$message,1,$second);
    $live=vp3_profile_owner_reply_lock_v1($pdo,pa3_owner());vp3_profile_agent_owner_conversation_v390($pdo,$cid,1,true);
    $late=vp3_profile_owner_reply_recover_v1($pdo,$live,$cid,$lateRequest,$message);$pdo->rollBack();
    pa3_check($late===['message_id'=>$lateMid,'duplicate'=>true],'MySQL locking recovery sees a committed receipt newer than the transaction snapshot');
}else echo "SKIP MySQL row-lock/current-read checks on sqlite; the MySQL CI invocation executes them.\n";

$pdo->exec('DROP TABLE profile_agent_handoff_deliveries_pa3');
pa3_check(!vp3_profile_handoff_schema_ready_pa3($pdo)&&reply_recover($cid,$request,$message)['message_id']===$mid,'historical receipt remains usable when the optional handoff schema is unavailable');
echo 'PROFILE_OWNER_REPLY_V1=PASS checks='.$checks.' driver='.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)."\n";
