<?php
declare(strict_types=1);

require __DIR__.'/fixtures/image-generation-v1.php';
require_once dirname(__DIR__).'/includes/agent-chat-read-v1.php';

function vp3_participant_scope_v1(): ?array{return $GLOBALS['chatReadParticipant']??null;}
function read_check(bool $ok,string $message): void
{
    if(!$ok)throw new RuntimeException($message);
    $GLOBALS['readChecks']=($GLOBALS['readChecks']??0)+1;
    echo 'PASS '.$message.PHP_EOL;
}
function read_reject(callable $operation,string $message,int $code=0): void
{
    try{$operation();}catch(RuntimeException|InvalidArgumentException|DomainException $e){
        read_check($code===0||$e->getCode()===$code,$message);
        return;
    }
    throw new RuntimeException('Expected rejection: '.$message);
}

$pdo->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id) VALUES (42,1,NULL),(43,2,NULL);
    INSERT INTO chat_messages(id,conversation_id,role,message) VALUES
        (1,42,'assistant','Historical assistant post'),(2,42,'user','Historical user post'),
        (3,42,'assistant',''),(4,42,'system','Internal system note'),(5,11,'assistant','Historical named Agent post')");
$owner=['id'=>1,'is_active'=>1];
read_check(!vp3_chat_read_schema_ready_v1($pdo),'an installation without read state is explicitly unavailable');
read_reject(static fn()=>vp3_chat_read_state_v1(db(),['id'=>1],0,42,[1]),'missing schema cannot report a historical post as new',503);
vp3_chat_read_ensure_schema_v1($pdo);
read_check(vp3_chat_read_schema_ready_v1($pdo),'schema initialization commits its rollout marker and receipt table');
read_check(vp3_chat_read_state_v1($pdo,$owner,0,42,[1])['unread_message_ids']===[]
    &&vp3_chat_read_state_v1($pdo,$owner,7,11,[5])['unread_message_ids']===[],
    'existing System and named Agent history starts read');
$pdo->exec("INSERT INTO chat_messages(id,conversation_id,role,message) VALUES
    (6,42,'assistant','New typed reply'),(7,42,'assistant','New worker report'),(8,42,'assistant','New Profile update'),
    (9,11,'assistant','New named Agent reply'),(10,43,'assistant','Foreign reply'),(11,42,'user','New user turn'),
    (12,42,'assistant','   ')");
$read=static fn(array $ids): array=>vp3_chat_read_state_v1(db(),['id'=>1,'is_active'=>1],0,42,$ids);
$ack=static fn(array $ids): array=>vp3_chat_read_acknowledge_v1(db(),['id'=>1,'is_active'=>1],0,42,$ids);
read_check($read([8,6,7])['unread_message_ids']===[8,6,7],'new persisted assistant posts are unread across typed, worker and Profile sources');
$ack([8]);
read_check($read([6,7,8])['unread_message_ids']===[6,7],'viewing a later post leaves lower offscreen posts unread');
$pdo->exec("UPDATE chat_messages SET message='The previously unfinished stream completed' WHERE id=3");
read_check($read([3])['unread_message_ids']===[3],'a stream placeholder present during rollout becomes new when completed');
$ack([3,6]);
$count=(int)$pdo->query('SELECT COUNT(*) FROM agent_chat_read_receipts_v1')->fetchColumn();
$pdo->exec("UPDATE agent_chat_read_receipts_v1 SET read_at='2001-01-01 00:00:00' WHERE message_id=6");
$ack([6,8]);$ack([8,6]);
read_check((int)$pdo->query('SELECT COUNT(*) FROM agent_chat_read_receipts_v1')->fetchColumn()===$count
    &&$pdo->query('SELECT read_at FROM agent_chat_read_receipts_v1 WHERE message_id=6')->fetchColumn()==='2001-01-01 00:00:00'
    &&$read([3,6,7,8])['unread_message_ids']===[7],
    'repeated and out-of-order tab acknowledgements preserve exact first-read receipts');

foreach([2,4,9,10,11,12,999] as $id){
    read_reject(static fn()=>$read([$id]),'state rejects a user, system, other-Agent, foreign, blank or missing post '.$id);
    read_reject(static fn()=>$ack([7,$id]),'acknowledgement rejects an invalid mixed batch atomically '.$id);
}
read_check($read([7])['unread_message_ids']===[7],'an invalid batch never marks its valid message read');
foreach([[7,7],[0],[-1],[true],[1.1],['7x'],[9007199254740992],array_fill(0,101,7)] as $ids){
    read_reject(static fn()=>$ack($ids),'malformed, duplicate or oversized acknowledgement batches are rejected');
}
read_reject(static fn()=>vp3_chat_read_state_v1($pdo,$owner,7,42,[7]),'a named Agent cannot read the System Agent Chat',404);
read_reject(static fn()=>vp3_chat_read_acknowledge_v1($pdo,$owner,0,11,[9]),'a System Agent cannot acknowledge a named Agent Chat',404);
read_reject(static fn()=>vp3_chat_read_state_v1($pdo,['id'=>2,'is_active'=>1],0,42,[7]),'another owner cannot read the first owner Chat',404);
$GLOBALS['chatReadParticipant']=['participant_id'=>3];
read_reject(static fn()=>$ack([7]),'a participant scope cannot acknowledge owner messages',403);
unset($GLOBALS['chatReadParticipant']);
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
read_reject(static fn()=>$ack([7]),'a deactivated owner is rechecked before acknowledgement',403);
$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');
read_reject(static fn()=>vp3_chat_read_state_v1($pdo,$owner,7,11,[9]),'an inactive named Agent loses access',404);
vp3_chat_read_ensure_schema_v1($pdo);
read_check($read([7])['unread_message_ids']===[7],'re-running an upgrade never backfills posts created after initial rollout');
$ack([7]);
read_check($read([3,6,7,8])['unread_message_ids']===[],'only the exact acknowledged persisted assistant posts become read');
echo 'AGENT_CHAT_READ_V1=PASS ('.$GLOBALS['readChecks'].' cases)'.PHP_EOL;
