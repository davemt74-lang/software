<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connections-v1.php';
require_once __DIR__.'/initiative-work-product-v1.php';
require_once __DIR__.'/agent-commerce-recovery-pa2.php';

function agent_connection_email_followup_install_v1(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new LogicException('Reply watch storage cannot be installed inside a transaction.');
    $sqlite = agent_commerce_sqlite_pa2($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_connection_email_followups_v1 (
        owner_user_id INT UNSIGNED NOT NULL,
        operation_key VARCHAR(190) NOT NULL,
        connection_id BIGINT UNSIGNED NOT NULL,
        authorization_key VARCHAR(190) NOT NULL,
        agent_id BIGINT UNSIGNED NOT NULL,
        conversation_id BIGINT UNSIGNED NOT NULL,
        origin_user_message_id BIGINT UNSIGNED NULL,
        draft_id CHAR(32) NULL,
        account_email VARCHAR(254) NOT NULL,
        recipient_email VARCHAR(254) NOT NULL,
        sent_message_id VARCHAR(190) NOT NULL,
        thread_id VARCHAR(190) NOT NULL,
        status VARCHAR(20) NOT NULL,
        claim_key CHAR(32) NULL,
        next_check_at DATETIME NULL,
        expires_at DATETIME NOT NULL,
        reply_message_id VARCHAR(190) NULL,
        reply_chat_message_id BIGINT UNSIGNED NULL,
        sender_after_ms BIGINT UNSIGNED NULL,
        PRIMARY KEY (owner_user_id,operation_key)".($sqlite ? ')' : ",
        INDEX idx_agent_email_followups_due (status,next_check_at),
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin"));
    if ($sqlite) $pdo->exec('CREATE INDEX IF NOT EXISTS idx_agent_email_followups_due ON agent_connection_email_followups_v1 (status,next_check_at)');
    if (!agent_connection_email_sender_watch_ready_v1($pdo)) {
        $pdo->exec('ALTER TABLE agent_connection_email_followups_v1 ADD COLUMN sender_after_ms BIGINT UNSIGNED NULL');
    }
}

function agent_connection_email_sender_watch_ready_v1(PDO $pdo): bool
{
    return agent_commerce_recovery_column_pa2($pdo,'agent_connection_email_followups_v1','sender_after_ms');
}

function agent_connection_email_followup_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT claim_key,reply_chat_message_id FROM agent_connection_email_followups_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function agent_connection_email_followup_origin_v1(PDO $pdo, array $watch, bool $lock=false): bool
{
    $owner = (int)$watch['owner_user_id'];
    $scope = ['agent_id'=>(int)$watch['agent_id'],'conversation_id'=>(int)$watch['conversation_id']];
    try {
        initiative_work_product_scope_v1($pdo,$owner,$scope,$lock ? agent_commerce_lock_pa2($pdo) : '');
    } catch (DomainException $error) {
        if (!in_array($error->getCode(),[403,404,422],true)) throw $error;
        return false;
    }
    if ($watch['origin_user_message_id'] === null) return true;
    $query = $pdo->prepare("SELECT id FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'"
        .($lock ? agent_commerce_lock_pa2($pdo) : ''));
    $query->execute([(int)$watch['origin_user_message_id'],$scope['conversation_id'],$owner]);
    return $query->fetchColumn() !== false;
}

function agent_connection_email_followup_grant_v1(array $watch, ?array $record): bool
{
    return $record && ($record['provider']??'') === 'gmail' && ($record['status']??'') === 'connected'
        && hash_equals((string)$watch['authorization_key'],(string)($record['authorization_key']??''))
        && agent_connection_gmail_address_v1($record['account_email']??null) === $watch['account_email']
        && !empty($record['permissions']['read']) && !empty($record['permissions']['followups']);
}

/** A saved, explicit owner command authorizes one future match in this Chat. */
function agent_connection_email_sender_watch_register_v1(PDO $pdo, int $owner, int $agent, int $conversation, int $message, int $connectionId, string $grant, string $sender): bool
{
    if ($message < 1 || agent_connection_gmail_sender_v1($sender) !== $sender || $sender === '') {
        throw new DomainException('Use a saved private Chat and provide one exact sender address.',422);
    }
    return agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$owner,$agent,$conversation,$message,$connectionId,$grant,$sender): bool {
        $connection = agent_connections_require_grant_v1($pdo,$owner,$connectionId,'followups',$grant);
        $account = agent_connection_gmail_address_v1($connection['account_email']);
        $origin = ['owner_user_id'=>$owner,'agent_id'=>$agent,'conversation_id'=>$conversation,'origin_user_message_id'=>$message];
        if ($account === '' || !agent_connection_email_followup_origin_v1($pdo,$origin,true)) {
            throw new DomainException('This private Gmail watch is unavailable.',404);
        }
        $now = agent_commerce_time_pa2();
        $key = 'sender_watch:'.$message;
        $lookup = $pdo->prepare('SELECT * FROM agent_connection_email_followups_v1 WHERE owner_user_id=? AND operation_key=?'
            .agent_commerce_lock_pa2($pdo));
        $lookup->execute([$owner,$key]);
        if ($prior = $lookup->fetch(PDO::FETCH_ASSOC)) {
            return $prior['status'] === 'waiting' && $prior['expires_at'] > $now
                && $prior['authorization_key'] === $grant && (int)$prior['connection_id'] === $connectionId
                && (int)$prior['agent_id'] === $agent && (int)$prior['conversation_id'] === $conversation
                && $prior['recipient_email'] === $sender && $prior['sender_after_ms'] !== null;
        }
        $duplicate = $pdo->prepare("SELECT 1 FROM agent_connection_email_followups_v1
            WHERE owner_user_id=? AND connection_id=? AND authorization_key=? AND agent_id=? AND conversation_id=?
                AND recipient_email=? AND sender_after_ms IS NOT NULL AND status='waiting' AND expires_at>? LIMIT 1"
            .agent_commerce_lock_pa2($pdo));
        $duplicate->execute([$owner,$connectionId,$grant,$agent,$conversation,$sender,$now]);
        if ($duplicate->fetchColumn() !== false) return true;
        $active = $pdo->prepare("SELECT COUNT(*) FROM agent_connection_email_followups_v1 WHERE owner_user_id=? AND connection_id=?
            AND authorization_key=? AND sender_after_ms IS NOT NULL AND status='waiting' AND expires_at>?");
        $active->execute([$owner,$connectionId,$grant,$now]);
        if ((int)$active->fetchColumn() >= 10) throw new DomainException('You have ten active Gmail sender watches. Stop one before adding another.',422);
        $pdo->prepare("INSERT INTO agent_connection_email_followups_v1
            (owner_user_id,operation_key,connection_id,authorization_key,agent_id,conversation_id,origin_user_message_id,
             account_email,recipient_email,sent_message_id,thread_id,status,next_check_at,expires_at,sender_after_ms)
            VALUES (?,?,?,?,?,?,?,?,?,'','','waiting',?,?,?)")
            ->execute([$owner,$key,$connectionId,$grant,$agent,$conversation,$message,$account,$sender,
                $now,gmdate('Y-m-d H:i:s',time()+30*86400),(int)floor(microtime(true)*1000)]);
        return true;
    });
}

function agent_connection_email_sender_watch_stop_v1(PDO $pdo, int $owner, int $agent, int $conversation, int $message, string $sender): int
{
    $origin = ['owner_user_id'=>$owner,'agent_id'=>$agent,'conversation_id'=>$conversation,'origin_user_message_id'=>$message];
    return agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$owner,$agent,$conversation,$message,$sender,$origin): int {
        if ($message < 1 || !agent_connection_email_followup_origin_v1($pdo,$origin,true)) {
            throw new DomainException('Use the private Chat where this Gmail watch was started.',404);
        }
        $stop = $pdo->prepare("UPDATE agent_connection_email_followups_v1 SET status='stopped',claim_key=NULL,next_check_at=NULL
            WHERE owner_user_id=? AND agent_id=? AND conversation_id=? AND recipient_email=? AND sender_after_ms IS NOT NULL
                AND status='waiting' AND expires_at>?");
        $stop->execute([$owner,$agent,$conversation,$sender,agent_commerce_time_pa2()]);
        return $stop->rowCount();
    });
}

/** Project live background work for the private Chat without revealing mailbox data. */
function agent_connection_email_followup_specialist_v1(PDO $pdo, int $owner, int $agent, int $conversation): array
{
    initiative_work_product_scope_v1($pdo,$owner,['agent_id'=>$agent,'conversation_id'=>$conversation]);
    $idle = ['state'=>'idle','origin_message_id'=>null];
    if (!agent_connection_email_followup_ready_v1($pdo)) return $idle;

    $connection = agent_connections_find_v1($pdo,$owner,'gmail');
    if (!$connection || $connection['status'] !== 'connected' || !agent_connections_permissions_v1($connection)['followups']) return $idle;

    $stmt = $pdo->prepare("SELECT f.origin_user_message_id FROM agent_connection_email_followups_v1 f
        WHERE f.owner_user_id=? AND f.agent_id=? AND f.conversation_id=? AND f.connection_id=? AND f.authorization_key=?
            AND f.account_email=? AND f.status='waiting' AND f.expires_at>? AND f.next_check_at IS NOT NULL
            AND (f.origin_user_message_id IS NULL OR EXISTS (SELECT 1 FROM chat_messages m
                WHERE m.id=f.origin_user_message_id AND m.conversation_id=f.conversation_id AND m.user_id=f.owner_user_id AND m.role='user'))
        ORDER BY f.origin_user_message_id DESC, f.operation_key LIMIT 1");
    $stmt->execute([$owner,$agent,$conversation,(int)$connection['id'],$connection['authorization_key'],
        agent_connection_gmail_address_v1($connection['account_email']),agent_commerce_time_pa2()]);
    $watch = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$watch) return $idle;

    $ready = agent_connection_gmail_ready_v1();
    if ($ready) {
        try {
            if (!hash_equals($connection['client_fingerprint'],agent_connections_client_fingerprint_v1())) return $idle;
        } catch (AgentConnectionExceptionV1 $error) {
            if ($error->getCode() !== 403) throw $error;
            $ready = false;
        }
    }
    return ['state'=>$ready?'watching':'paused',
        'origin_message_id'=>$watch['origin_user_message_id'] === null ? null : (int)$watch['origin_user_message_id']];
}

/** The caller supplies the durable verified result from an explicitly reviewed send. */
function agent_connection_email_followup_register_v1(PDO $pdo, int $ownerId, int $agentId, int $conversationId, array $verifiedSend, ?array $initiative=null): bool
{
    if (!agent_connection_email_followup_ready_v1($pdo)) return false;
    $from = agent_connection_gmail_address_v1($verifiedSend['from']??null);
    $to = agent_connection_gmail_address_v1($verifiedSend['to']??null);
    $connectionId = (int)($verifiedSend['connection_id']??0);
    $sentAt = (int)($verifiedSend['sent_at']??0);
    if ($from === '' || $to === '' || $from === $to || $connectionId<1 || $sentAt<1) return false;
    foreach (['operation_key','authorization_key','message_id','thread_id'] as $field) {
        if (!is_string($verifiedSend[$field]??null) || !preg_match('/\A[A-Za-z0-9_.:-]{1,190}\z/D',$verifiedSend[$field])) return false;
    }
    $draft = $initiative['draft_id']??null;
    $originId = $initiative['user_message_id']??null;
    if ($initiative !== null && (!is_string($draft) || !preg_match('/\A[a-f0-9]{32}\z/D',$draft) || !is_int($originId) || $originId<1)) return false;
    $watch = ['owner_user_id'=>$ownerId,'agent_id'=>$agentId,'conversation_id'=>$conversationId,'origin_user_message_id'=>$originId,
        'account_email'=>$from,'authorization_key'=>$verifiedSend['authorization_key']];
    $expiresAt = $sentAt+30*86400;
    if ($expiresAt<=time()) return false;

    return agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$ownerId,$agentId,$conversationId,$verifiedSend,$connectionId,$watch,$draft,$originId,$from,$to,$expiresAt): bool {
        if (!agent_connection_email_followup_grant_v1($watch,agent_connections_record_v1($ownerId,$connectionId,true))
            || !agent_connection_email_followup_origin_v1($pdo,$watch,true) || !agent_connection_gmail_ready_v1()) return false;
        $lookup = $pdo->prepare('SELECT * FROM agent_connection_email_followups_v1 WHERE owner_user_id=? AND operation_key=?'.agent_commerce_lock_pa2($pdo));
        $lookup->execute([$ownerId,$verifiedSend['operation_key']]);
        if ($saved = $lookup->fetch(PDO::FETCH_ASSOC)) {
            return $saved['status'] !== 'stopped' && (int)$saved['connection_id'] === $connectionId
                && (int)$saved['agent_id'] === $agentId && (int)$saved['conversation_id'] === $conversationId
                && (int)$saved['origin_user_message_id'] === (int)$originId && $saved['draft_id'] === $draft
                && $saved['authorization_key'] === $verifiedSend['authorization_key'] && $saved['sent_message_id'] === $verifiedSend['message_id']
                && $saved['thread_id'] === $verifiedSend['thread_id'] && $saved['account_email'] === $from && $saved['recipient_email'] === $to;
        }
        $pdo->prepare("INSERT INTO agent_connection_email_followups_v1
            (owner_user_id,operation_key,connection_id,authorization_key,agent_id,conversation_id,origin_user_message_id,draft_id,
             account_email,recipient_email,sent_message_id,thread_id,status,next_check_at,expires_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'waiting',?,?)")
            ->execute([$ownerId,$verifiedSend['operation_key'],$connectionId,$verifiedSend['authorization_key'],$agentId,$conversationId,
                $originId,$draft,$from,$to,$verifiedSend['message_id'],$verifiedSend['thread_id'],agent_commerce_time_pa2(),gmdate('Y-m-d H:i:s',$expiresAt)]);
        return true;
    });
}

/** Gmail supplies the actual mailbox records. Header matches establish linkage, not sender identity. */
function agent_connection_email_followup_match_v1(array $watch, array $messages): ?array
{
    $anchor = null;
    foreach ($messages as $message) {
        if ($message['message_id'] === $watch['sent_message_id'] && $message['thread_id'] === $watch['thread_id']) {
            $anchor = $message;
            break;
        }
    }
    if (!$anchor || !in_array('SENT',$anchor['labels'],true) || array_intersect(['DRAFT','TRASH','SPAM'],$anchor['labels'])
        || agent_connection_gmail_address_v1($anchor['from']) !== $watch['account_email']
        || agent_connection_gmail_address_v1($anchor['to']) !== $watch['recipient_email']
        || (int)$anchor['received_at_ms']<1 || !preg_match('/\A<[^<>\s]+@[^<>\s]+>\z/D',trim($anchor['rfc_message_id']))) return null;

    $reply = null;
    foreach ($messages as $candidate) {
        if ($candidate['thread_id'] !== $watch['thread_id'] || $candidate['message_id'] === $anchor['message_id']
            || $candidate['received_at_ms'] <= $anchor['received_at_ms'] || array_intersect(['SENT','DRAFT','TRASH','SPAM'],$candidate['labels'])
            || agent_connection_gmail_address_v1($candidate['from']) !== $watch['recipient_email']
            || agent_connection_gmail_address_v1($candidate['to']) !== $watch['account_email']) continue;
        $auth = $candidate['authentication_results']??'';
        if (preg_match('/\Amx\.google\.com\s*;/i',$auth) && preg_match('/;\s*dmarc\s*=\s*fail\b/i',$auth)) continue;
        $parentId = trim($candidate['in_reply_to']);
        if ($parentId === '' && preg_match('/(<[^<>\s]+>)\s*\z/D',$candidate['references'],$match)) $parentId = $match[1];
        if ($parentId !== trim($anchor['rfc_message_id'])) continue;
        if ($reply === null || [$candidate['received_at_ms'],$candidate['message_id']] < [$reply['received_at_ms'],$reply['message_id']]) $reply = $candidate;
    }
    return $reply;
}

function agent_connection_email_followup_reschedule_v1(PDO $pdo, array $watch, string $claim, ?int $delay): void
{
    $pdo->prepare('UPDATE agent_connection_email_followups_v1 SET status=?,claim_key=NULL,next_check_at=?
        WHERE owner_user_id=? AND operation_key=? AND claim_key=?')
        ->execute([$delay===null?'stopped':'waiting',$delay===null?null:gmdate('Y-m-d H:i:s',time()+$delay),
            $watch['owner_user_id'],$watch['operation_key'],$claim]);
}

function agent_connection_email_followup_deliver_v1(PDO $pdo, array $watch, string $claim, array $reply): bool
{
    // Observe an administrator pause committed while the provider request was running.
    if (!agent_commerce_sqlite_pa2($pdo)) $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    return agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$watch,$claim,$reply): bool {
        $owner = (int)$watch['owner_user_id'];
        // The verified-send callback holds the connection first, then the original Chat.
        $connection = agent_connections_find_v1($pdo,$owner,(int)$watch['connection_id'],true);
        $record = $connection ? agent_connections_view_v1($connection) + ['authorization_key'=>$connection['authorization_key']] : null;
        if (!agent_connection_email_followup_grant_v1($watch,$record)
            || !agent_connection_email_followup_origin_v1($pdo,$watch,true)) {
            agent_connection_email_followup_reschedule_v1($pdo,$watch,$claim,null);
            return false;
        }
        if (!agent_connection_gmail_ready_v1()) {
            agent_connection_email_followup_reschedule_v1($pdo,$watch,$claim,900);
            return false;
        }
        if (!hash_equals($connection['client_fingerprint'],agent_connections_client_fingerprint_v1())) {
            agent_connection_email_followup_reschedule_v1($pdo,$watch,$claim,null);
            return false;
        }
        $now = agent_commerce_time_pa2();
        $claimRow = $pdo->prepare("SELECT 1 FROM agent_connection_email_followups_v1 WHERE owner_user_id=? AND operation_key=?
            AND claim_key=? AND status='waiting' AND next_check_at>? AND expires_at>?".agent_commerce_lock_pa2($pdo));
        $claimRow->execute([$owner,$watch['operation_key'],$claim,$now,$now]);
        if ($claimRow->fetchColumn() === false) return false;
        $context = json_encode(['source'=>'agent_connection_email_followup','skip_brain_archive'=>true,'email_followup'=>[
            'connection_id'=>(int)$watch['connection_id'],'agent_id'=>(int)$watch['agent_id'],'draft_id'=>$watch['draft_id'],
            'origin_user_message_id'=>$watch['origin_user_message_id'] === null ? null : (int)$watch['origin_user_message_id'],
            'received_at'=>gmdate(DATE_ATOM,intdiv((int)$reply['received_at_ms'],1000)),
        ]],JSON_THROW_ON_ERROR);
        $senderWatch = ($watch['sender_after_ms']??null) !== null;
        $text = $senderWatch
            ? 'New Gmail message from '.$watch['recipient_email'].'. Open Gmail to read it. This sender watch is complete.'
            : 'Gmail found a reply from '.$watch['recipient_email'].' in the thread you asked me to watch. Open Gmail to read it.';
        $pdo->prepare("INSERT INTO chat_messages (conversation_id,user_id,role,message,context_json,created_at) VALUES (?,NULL,'assistant',?,?,?)")
            ->execute([$watch['conversation_id'],$text,$context,$now]);
        $messageId = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE chat_conversations SET updated_at=? WHERE id=? AND user_id=?')->execute([$now,$watch['conversation_id'],$owner]);
        notification_create_locked($owner,'agent_email_followup',$senderWatch ? 'New Gmail message' : 'Gmail reply received',
            'Open your private Agent Chat to see the Gmail watch update.',
            url('/chat.php?agent='.((int)$watch['agent_id'] ?: 'system').'&conversation_id='.(int)$watch['conversation_id']),
            'agent_connection_email_followup',$messageId);
        $pdo->prepare("UPDATE agent_connection_email_followups_v1 SET status='delivered',claim_key=NULL,next_check_at=NULL,reply_message_id=?,reply_chat_message_id=?
            WHERE owner_user_id=? AND operation_key=? AND claim_key=?")
            ->execute([$reply['message_id'],$messageId,$owner,$watch['operation_key'],$claim]);
        return true;
    });
}

/** Bound remote work per Cloud worker cycle and keep network calls outside owner transactions. */
function agent_connection_email_followup_sweep_v1(PDO $pdo, int $limit=5, ?callable $http=null): array
{
    $counts = ['processed'=>0,'delivered'=>0];
    if ($limit<1 || $pdo->inTransaction() || !agent_connection_email_followup_ready_v1($pdo)) return $counts;
    $due = $pdo->prepare("SELECT * FROM agent_connection_email_followups_v1 WHERE status='waiting' AND next_check_at<=?
        ORDER BY next_check_at,owner_user_id,operation_key LIMIT ".min($limit,5));
    $due->execute([agent_commerce_time_pa2()]);
    $reserve = $pdo->prepare("UPDATE agent_connection_email_followups_v1 SET claim_key=?,next_check_at=?
        WHERE owner_user_id=? AND operation_key=? AND status='waiting' AND next_check_at<=?");
    foreach ($due->fetchAll(PDO::FETCH_ASSOC) as $watch) {
        $now = agent_commerce_time_pa2();
        $claim = bin2hex(random_bytes(16));
        $after = (int)($watch['sender_after_ms']??0);
        // A sender poll may list messages and then fetch up to ten metadata records.
        $leaseSeconds = $after > 0 ? 180 : 120;
        $reserve->execute([$claim,gmdate('Y-m-d H:i:s',time()+$leaseSeconds),$watch['owner_user_id'],$watch['operation_key'],$now]);
        if ($reserve->rowCount() !== 1) continue;
        $counts['processed']++;
        $owner = (int)$watch['owner_user_id'];
        $connection = (int)$watch['connection_id'];
        try {
            if ($watch['expires_at'] <= $now || !agent_connection_email_followup_origin_v1($pdo,$watch)
                || !agent_connection_email_followup_grant_v1($watch,agent_connections_record_v1($owner,$connection))) {
                agent_connection_email_followup_reschedule_v1($pdo,$watch,$claim,null);
                continue;
            }
            $grant = agent_connections_access_v1($owner,$connection,'followups',$http);
            if (!hash_equals($watch['authorization_key'],(string)$grant['authorization_key'])) {
                agent_connection_email_followup_reschedule_v1($pdo,$watch,$claim,null);
                continue;
            }
            if ($after > 0) {
                $messages = agent_connection_gmail_search_sender_v1($grant['access_token'],$watch['recipient_email'],10,$http,$after);
                $reply = $messages[0] ?? null;
            } else {
                $messages = agent_connection_gmail_thread_v1($grant['access_token'],$watch['thread_id'],$http);
                $reply = agent_connection_email_followup_match_v1($watch,$messages);
            }
            if ($reply === null) {
                agent_connection_email_followup_reschedule_v1($pdo,$watch,$claim,300);
            } elseif (agent_connection_email_followup_deliver_v1($pdo,$watch,$claim,$reply)) {
                $counts['delivered']++;
            }
        } catch (Throwable $error) {
            agent_connection_email_followup_reschedule_v1($pdo,$watch,$claim,$error->getCode()===404?null:900);
            if (function_exists('agent_runtime_v125_trace')) {
                agent_runtime_v125_trace('worker.email_followup.poll_failed',['error_class'=>get_class($error)]);
            }
        }
    }
    return $counts;
}
