<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat-boundary-v380.php';
require_once __DIR__.'/agent-capability-chat-v1.php';

function vp3_chat_read_schema_ready_v1(PDO $pdo): bool
{
    try{
        $pdo->query('SELECT owner_user_id,agent_id,conversation_id,message_id,read_at FROM agent_chat_read_receipts_v1 WHERE 1=0');
        return (bool)$pdo->query('SELECT singleton_id FROM agent_chat_read_rollout_v1 WHERE singleton_id=1')->fetchColumn();
    }catch(PDOException $e){return false;}
}

/** Backfill completed history exactly once, leaving unfinished streams eligible. */
function vp3_chat_read_ensure_schema_v1(PDO $pdo): void
{
    if($pdo->inTransaction())throw new RuntimeException('Chat read schema must be installed outside a transaction.');
    $mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
    $suffix=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec('CREATE TABLE IF NOT EXISTS agent_chat_read_rollout_v1 (
        singleton_id TINYINT UNSIGNED PRIMARY KEY,
        installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )'.$suffix);
    $pdo->exec('CREATE TABLE IF NOT EXISTS agent_chat_read_receipts_v1 (
        owner_user_id INT UNSIGNED NOT NULL,
        agent_id BIGINT UNSIGNED NOT NULL,
        conversation_id BIGINT UNSIGNED NOT NULL,
        message_id BIGINT UNSIGNED NOT NULL,
        read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(owner_user_id,message_id),
        FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY(conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
        FOREIGN KEY(message_id) REFERENCES chat_messages(id) ON DELETE CASCADE
    )'.$suffix);
    $pdo->beginTransaction();
    try{
        $insert=$mysql?'INSERT IGNORE':'INSERT OR IGNORE';
        $created=$pdo->exec($insert.' INTO agent_chat_read_rollout_v1(singleton_id) VALUES(1)');
        if($created===1){
            $pdo->exec('INSERT INTO agent_chat_read_receipts_v1(owner_user_id,agent_id,conversation_id,message_id)
                SELECT c.user_id,COALESCE(c.user_agent_id,0),m.conversation_id,m.id
                FROM chat_messages m JOIN chat_conversations c ON c.id=m.conversation_id
                WHERE m.role=\'assistant\' AND TRIM(m.message)<>\'\'');
        }
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function vp3_chat_read_id_v1(mixed $value,bool $allowZero=false): int
{
    if((!is_int($value)&&!is_string($value))||!preg_match('/^(?:0|[1-9][0-9]{0,15})$/D',(string)$value)){
        throw new InvalidArgumentException('invalid_chat_read_id');
    }
    $id=(int)$value;
    if($id>9007199254740991||$id<($allowZero?0:1))throw new InvalidArgumentException('invalid_chat_read_id');
    return $id;
}

function vp3_chat_read_ids_v1(mixed $values): array
{
    if(!is_array($values)||!array_is_list($values)||count($values)>100)throw new InvalidArgumentException('invalid_message_ids');
    $ids=[];
    foreach($values as $value){
        $id=vp3_chat_read_id_v1($value);
        if(isset($ids[$id]))throw new InvalidArgumentException('invalid_message_ids');
        $ids[$id]=$id;
    }
    return array_values($ids);
}

function vp3_chat_read_scope_v1(PDO $pdo,array $user,int $agentId,int $conversationId,bool $forUpdate=false): array
{
    vp3_chat_read_id_v1($agentId,true);
    vp3_chat_read_id_v1($conversationId);
    if(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null){
        throw new RuntimeException('forbidden',403);
    }
    try{$agent=vp3_agent_chat_resolve_agent_v380($pdo,$user,$agentId);}
    catch(PDOException $e){throw $e;}
    catch(RuntimeException $e){throw new RuntimeException('conversation_not_found',404);}
    $principal=vp3_agent_chat_principal_v380($user,$agent);
    $live=vp3_capability_live_user_v1($pdo,$user,$principal,[],null);
    if(!has_permission('account.access',$live))throw new RuntimeException('forbidden',403);
    if(!vp3_agent_chat_conversation_v380($pdo,$conversationId,(int)$live['id'],$agent,$forUpdate)){
        throw new RuntimeException('conversation_not_found',404);
    }
    return $live;
}

/** Only exact, persisted, completed assistant posts can acquire a read receipt. */
function vp3_chat_read_result_v1(PDO $pdo,int $owner,int $agentId,int $conversationId,array $ids,bool $forUpdate=false): array
{
    $unread=[];
    if($ids){
        $slots=implode(',',array_fill(0,count($ids),'?'));
        $lock=$forUpdate&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'?' FOR UPDATE':'';
        $stmt=$pdo->prepare("SELECT m.id,r.message_id AS read_message_id FROM chat_messages m
            LEFT JOIN agent_chat_read_receipts_v1 r ON r.owner_user_id=? AND r.agent_id=?
                AND r.conversation_id=m.conversation_id AND r.message_id=m.id
            WHERE m.conversation_id=? AND m.role='assistant' AND TRIM(m.message)<>'' AND m.id IN ($slots)".$lock);
        $stmt->execute([$owner,$agentId,$conversationId,...$ids]);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==count($ids))throw new DomainException('message_not_available');
        $read=[];
        foreach($rows as $row)$read[(int)$row['id']]=$row['read_message_id']!==null;
        foreach($ids as $id)if(!$read[$id])$unread[]=$id;
    }
    return ['agent_id'=>$agentId,'conversation_id'=>$conversationId,'message_ids'=>$ids,'unread_message_ids'=>$unread];
}

function vp3_chat_read_state_v1(PDO $pdo,array $user,int $agentId,int $conversationId,array $messageIds): array
{
    $ids=vp3_chat_read_ids_v1($messageIds);
    if(!vp3_chat_read_schema_ready_v1($pdo))throw new RuntimeException('chat_read_schema_not_ready',503);
    $live=vp3_chat_read_scope_v1($pdo,$user,$agentId,$conversationId);
    return vp3_chat_read_result_v1($pdo,(int)$live['id'],$agentId,$conversationId,$ids);
}

function vp3_chat_read_acknowledge_v1(PDO $pdo,array $user,int $agentId,int $conversationId,array $messageIds): array
{
    $ids=vp3_chat_read_ids_v1($messageIds);
    if(!vp3_chat_read_schema_ready_v1($pdo))throw new RuntimeException('chat_read_schema_not_ready',503);
    if($pdo->inTransaction())throw new RuntimeException('chat_read_transaction_active');
    $pdo->beginTransaction();
    try{
        $lock=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'?' FOR UPDATE':'';
        $stmt=$pdo->prepare('SELECT id FROM users WHERE id=?'.$lock);$stmt->execute([(int)($user['id']??0)]);
        if(!$stmt->fetchColumn())throw new RuntimeException('forbidden',403);
        $live=vp3_chat_read_scope_v1($pdo,$user,$agentId,$conversationId,true);
        vp3_chat_read_result_v1($pdo,(int)$live['id'],$agentId,$conversationId,$ids,true);
        $insert=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT IGNORE':'INSERT OR IGNORE';
        $save=$pdo->prepare($insert.' INTO agent_chat_read_receipts_v1(owner_user_id,agent_id,conversation_id,message_id) VALUES(?,?,?,?)');
        foreach($ids as $id)$save->execute([(int)$live['id'],$agentId,$conversationId,$id]);
        $state=vp3_chat_read_result_v1($pdo,(int)$live['id'],$agentId,$conversationId,$ids);
        $pdo->commit();
        return $state;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
