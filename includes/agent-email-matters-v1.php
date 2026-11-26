<?php
declare(strict_types=1);

require_once __DIR__.'/agent-commerce-recovery-pa2.php';
require_once __DIR__.'/agent-connection-gmail-inbox-notice-v1.php';
require_once __DIR__.'/agent-connection-gmail-v1.php';

function agent_email_matters_install_v1(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new LogicException('Email matter storage cannot be installed inside a transaction.');
    $tail = agent_commerce_sqlite_pa2($pdo) ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
    $sqlite = agent_commerce_sqlite_pa2($pdo);
    $scopeIndex = $sqlite ? '' : ', INDEX idx_email_matter_scope_v1 (owner_user_id,connection_id,grant_key,stage,next_wake_at)';
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_email_matters_v1 (
        matter_id CHAR(64) PRIMARY KEY,
        owner_user_id INT UNSIGNED NOT NULL,
        connection_id BIGINT UNSIGNED NOT NULL,
        grant_key CHAR(64) NOT NULL,
        thread_id_enc VARCHAR(512) NOT NULL,
        latest_message_id_enc VARCHAR(512) NULL,
        latest_message_key CHAR(64) NULL,
        latest_received_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
        subject VARCHAR(240) NOT NULL,
        sender VARCHAR(254) NOT NULL,
        important TINYINT NOT NULL DEFAULT 0,
        is_reply TINYINT NOT NULL DEFAULT 0,
        stage VARCHAR(24) NOT NULL,
        resume_stage VARCHAR(24) NULL,
        resume_wake_at DATETIME NULL,
        next_wake_at DATETIME NULL,
        last_operation_key CHAR(64) NULL,
        last_sent_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
        first_seen_at DATETIME NOT NULL,
        last_seen_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (connection_id) REFERENCES agent_connections_v1(id) ON DELETE CASCADE
        $scopeIndex
    )".$tail);
    if ($sqlite) $pdo->exec('CREATE INDEX IF NOT EXISTS idx_email_matter_scope_v1
        ON agent_email_matters_v1 (owner_user_id,connection_id,grant_key,stage,next_wake_at)');
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_email_matter_events_v1 (
        matter_id CHAR(64) NOT NULL,
        event_key CHAR(64) NOT NULL,
        event_kind VARCHAR(24) NOT NULL,
        operation_key CHAR(64) NULL,
        chat_message_id BIGINT UNSIGNED NULL,
        observed_at DATETIME NOT NULL,
        PRIMARY KEY (matter_id, event_key),
        FOREIGN KEY (matter_id) REFERENCES agent_email_matters_v1(matter_id) ON DELETE CASCADE
    )".$tail);
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_email_matter_scans_v1 (
        owner_user_id INT UNSIGNED NOT NULL,
        connection_id BIGINT UNSIGNED NOT NULL,
        grant_key CHAR(64) NOT NULL,
        completed_at DATETIME NULL,
        previous_at DATETIME NULL,
        total BIGINT UNSIGNED NOT NULL DEFAULT 0,
        important BIGINT UNSIGNED NOT NULL DEFAULT 0,
        replies BIGINT UNSIGNED NOT NULL DEFAULT 0,
        pending_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
        pending_important BIGINT UNSIGNED NOT NULL DEFAULT 0,
        pending_replies BIGINT UNSIGNED NOT NULL DEFAULT 0,
        in_progress TINYINT NOT NULL DEFAULT 0,
        PRIMARY KEY (owner_user_id, connection_id, grant_key),
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (connection_id) REFERENCES agent_connections_v1(id) ON DELETE CASCADE
    )".$tail);
}

function agent_email_matters_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT matter_id,thread_id_enc,revision FROM agent_email_matters_v1 WHERE 1=0');
        $pdo->query('SELECT event_key,operation_key,chat_message_id FROM agent_email_matter_events_v1 WHERE 1=0');
        $pdo->query('SELECT completed_at,pending_total FROM agent_email_matter_scans_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

/** The caller holds the Inbox Review consent transaction when clearing this projection. */
function agent_email_matters_clear_v1(PDO $pdo,int $owner): void
{
    if ($owner < 1 || !agent_email_matters_ready_v1($pdo)) return;
    agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$owner): void {
        $pdo->prepare('DELETE FROM agent_email_matter_events_v1 WHERE matter_id IN
            (SELECT matter_id FROM agent_email_matters_v1 WHERE owner_user_id=?)')->execute([$owner]);
        $pdo->prepare('DELETE FROM agent_email_matters_v1 WHERE owner_user_id=?')->execute([$owner]);
        $pdo->prepare('DELETE FROM agent_email_matter_scans_v1 WHERE owner_user_id=?')->execute([$owner]);
    });
}

function agent_email_matters_policy_scope_v1(array $policy): ?array
{
    $owner = (int)($policy['owner_user_id'] ?? 0);
    $generation = agent_connection_gmail_inbox_notice_generation_v1($policy);
    if ($owner < 1 || $generation === null || empty($policy['enabled'])) return null;
    $grant = (string)$policy['authorization_key'];
    return [$owner,(int)$policy['connection_id'],hash_hmac('sha256','vp3-email-matter-grant-v1',$grant)];
}

/** Read scope comes from the canonical owner and live Inbox Review grant check. */
function agent_email_matters_bound_scope_v1(array $scope): ?array
{
    if (($scope['state'] ?? null) !== 'ready' || empty($scope['provider_ready'])) return null;
    $bound = agent_email_matters_policy_scope_v1($scope['policy'] ?? []);
    return $bound !== null && $bound[0] === (int)($scope['owner'] ?? 0)
        && agent_connection_gmail_inbox_notice_generation_v1($scope['policy']) === ($scope['generation'] ?? null)
        ? $bound : null;
}

function agent_email_matters_source_key_v1(array $policy,string $type,string $value): string
{
    $scope = agent_email_matters_policy_scope_v1($policy);
    if ($scope === null) throw new LogicException('Email matter needs a live inbox grant.');
    return hash_hmac('sha256','vp3-email-matter-v1:'.implode(':',$scope).':'.$type.':'.$value,
        (string)$policy['authorization_key']);
}

function agent_email_matters_find_v1(PDO $pdo,array $scope,string $id,bool $lock=false): ?array
{
    $query = $pdo->prepare('SELECT * FROM agent_email_matters_v1 WHERE owner_user_id=? AND connection_id=?
        AND grant_key=? AND matter_id=?'.($lock ? agent_commerce_lock_pa2($pdo) : ''));
    $query->execute([...$scope,$id]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function agent_email_matters_event_v1(PDO $pdo,string $id,string $key,string $kind,string $at,
    ?string $operation=null,?int $chatMessage=null): bool
{
    $query = $pdo->prepare(agent_commerce_insert_ignore_pa2($pdo).' INTO agent_email_matter_events_v1
        (matter_id,event_key,event_kind,operation_key,chat_message_id,observed_at) VALUES (?,?,?,?,?,?)');
    $query->execute([$id,$key,$kind,$operation,$chatMessage,$at]);
    return $query->rowCount() === 1;
}

function agent_email_matters_metadata_v1(mixed $value,int $max): string
{
    return is_string($value) ? mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/',' ',$value) ?? ''),0,$max,'UTF-8') : '';
}

function agent_email_matters_received_ms_v1(mixed $value): int
{
    if (is_int($value)) return max(0,$value);
    if (!is_string($value) || !preg_match('/\A[1-9][0-9]{0,14}\z/D',$value)) return 0;
    return (int)$value;
}

function agent_email_matters_add_v1(PDO $pdo,array $scope,string $id,string $thread,string $subject,
    string $sender,string $at): void
{
    $pdo->prepare(agent_commerce_insert_ignore_pa2($pdo).' INTO agent_email_matters_v1
        (matter_id,owner_user_id,connection_id,grant_key,thread_id_enc,subject,sender,stage,first_seen_at,last_seen_at,updated_at)
        VALUES (?,?,?,?,?,?,?,\'needs_review\',?,?,?)')
        ->execute([$id,...$scope,ai_encrypt_secret($thread),$subject,$sender,$at,$at,$at]);
}

/** Commit an accepted, unfiltered provider observation with its verified inbox cursor. */
function agent_email_matters_observe_inbox_v1(PDO $pdo,array $policy,array $message,string $observedAt,
    bool $verifiedWatch=false,bool $historical=false): bool
{
    $scope = agent_email_matters_policy_scope_v1($policy);
    $thread = agent_connection_gmail_id_v1($message['thread_id'] ?? null);
    $messageId = agent_connection_gmail_id_v1($message['message_id'] ?? null);
    $received = agent_email_matters_received_ms_v1($message['received_at_ms'] ?? null);
    if ($scope === null || $thread === '' || $messageId === '' || $received < 1
        || (!$verifiedWatch && !$historical && $received <= (int)$policy['enabled_at_ms'])
        || agent_commerce_date_pa2($observedAt) === 0) return false;
    if (!$pdo->inTransaction()) throw new LogicException('Inbox matter observations require the cursor transaction.');
    $id = agent_email_matters_source_key_v1($policy,'thread',$thread);
    $key = agent_email_matters_source_key_v1($policy,'inbox',$messageId);
    $sender = agent_email_matters_metadata_v1($message['from'] ?? null,254);
    $subject = agent_email_matters_metadata_v1($message['subject'] ?? null,240);
    $row = agent_email_matters_find_v1($pdo,$scope,$id,true);
    if ($row === null) {
        agent_email_matters_add_v1($pdo,$scope,$id,$thread,$subject,$sender,$observedAt);
        $row = agent_email_matters_find_v1($pdo,$scope,$id,true);
        if ($row === null) throw new RuntimeException('The observed email matter could not be saved.');
    }
    if (!agent_email_matters_event_v1($pdo,$id,$key,'inbox',$observedAt)) return false;
    if ([$received,$key] <= [(int)$row['latest_received_ms'],(string)$row['latest_message_key']]) return true;

    // Delayed Inbox history can add context to a sent-only matter without undoing a verified send.
    $predatesSend = $received < (int)$row['last_sent_at'] * 1000;
    // A possible reply in the inbox cannot satisfy a specific outstanding watch.
    $keepStage = in_array($row['stage'],['awaiting_reply','snoozed'],true)
        || ($predatesSend && $row['stage'] === 'actioned');
    $stage = $keepStage ? $row['stage'] : 'needs_review';
    $resume = null;
    if ($stage === 'snoozed') {
        $resume = $row['resume_stage'] === 'awaiting_reply' || ($predatesSend && $row['resume_stage'] === 'actioned')
            ? $row['resume_stage'] : 'needs_review';
    }
    $nextWake = in_array($stage,['snoozed','awaiting_reply'],true) ? $row['next_wake_at'] : null;
    $resumeWake = $resume === 'awaiting_reply' ? $row['resume_wake_at'] : null;
    $pdo->prepare('UPDATE agent_email_matters_v1 SET latest_message_id_enc=?,latest_message_key=?,latest_received_ms=?,
        subject=?,sender=?,important=?,is_reply=?,stage=?,resume_stage=?,resume_wake_at=?,next_wake_at=?,last_seen_at=?,updated_at=?,revision=revision+1
        WHERE matter_id=?')
        ->execute([ai_encrypt_secret($messageId),$key,$received,$subject,$sender,
            (int)(!empty($message['important']) || (bool)agent_connection_gmail_subject_priority_signals_v1($subject)),
            (int)!empty($message['reply']),$stage,$resume,$resumeWake,$nextWake,$observedAt,$observedAt,$id]);
    return true;
}

/** Save only a matched native Sent ledger receipt from the approved send transaction. */
function agent_email_matters_note_send_v1(PDO $pdo,array $policy,array $receipt,?string $replyTo,string $subject): bool
{
    $scope = agent_email_matters_policy_scope_v1($policy);
    $thread = agent_connection_gmail_id_v1($receipt['thread_id'] ?? null);
    $messageId = agent_connection_gmail_id_v1($receipt['message_id'] ?? null);
    $operation = $receipt['operation_key'] ?? null;
    $sentAt = (int)($receipt['sent_at'] ?? 0);
    if ($scope === null || $thread === '' || $messageId === '' || $sentAt <= 0
        || !is_string($operation) || !preg_match('/\A[a-f0-9]{64}\z/D',$operation)
        || ($replyTo !== null && agent_connection_gmail_id_v1($replyTo) === '')
        || (int)($receipt['connection_id'] ?? 0) !== $scope[1]
        || ($receipt['authorization_key'] ?? null) !== $policy['authorization_key']) return false;
    if (!$pdo->inTransaction()) throw new LogicException('Email matter sends require the verified send transaction.');
    $verify = $pdo->prepare("SELECT 1 FROM agent_connection_sends_v1 WHERE owner_user_id=? AND connection_id=?
        AND authorization_key=? AND operation_key=? AND status='sent' AND provider_message_id=? AND provider_thread_id=? AND sent_at=?");
    $verify->execute([$scope[0],$scope[1],$policy['authorization_key'],$operation,$messageId,$thread,$sentAt]);
    if ($verify->fetchColumn() === false) return false;

    $at = agent_commerce_time_pa2();
    $id = agent_email_matters_source_key_v1($policy,'thread',$thread);
    $row = agent_email_matters_find_v1($pdo,$scope,$id,true);
    if ($row === null) {
        agent_email_matters_add_v1($pdo,$scope,$id,$thread,agent_email_matters_metadata_v1($subject,240),
            agent_email_matters_metadata_v1($receipt['to'] ?? null,254),$at);
        $row = agent_email_matters_find_v1($pdo,$scope,$id,true);
        if ($row === null) throw new RuntimeException('The sent email matter could not be saved.');
    }
    $key = agent_email_matters_source_key_v1($policy,'sent',$operation);
    if (!agent_email_matters_event_v1($pdo,$id,$key,'sent',$at,$operation)) return false;
    if (($replyTo !== null && $row['latest_message_key'] !== null
            && !hash_equals((string)$row['latest_message_key'],agent_email_matters_source_key_v1($policy,'inbox',$replyTo)))
        || intdiv((int)$row['latest_received_ms'],1000) > $sentAt
        || (int)$row['last_sent_at'] > $sentAt
        || in_array($row['stage'],['dismissed','resolved'],true)) return true;

    $watch = $pdo->prepare("SELECT expires_at FROM agent_connection_email_followups_v1 WHERE owner_user_id=? AND connection_id=?
        AND authorization_key=? AND operation_key=? AND sent_message_id=? AND thread_id=? AND status='waiting' AND sender_after_ms IS NULL");
    $watch->execute([$scope[0],$scope[1],$policy['authorization_key'],$operation,$messageId,$thread]);
    $expires = $watch->fetchColumn();
    $stage = is_string($expires) && $expires > $at ? 'awaiting_reply' : 'actioned';
    $pdo->prepare('UPDATE agent_email_matters_v1 SET stage=?,resume_stage=?,resume_wake_at=?,last_operation_key=?,last_sent_at=?,next_wake_at=?,
        updated_at=?,revision=revision+1 WHERE matter_id=?')
        ->execute([$row['stage'] === 'snoozed' ? 'snoozed' : $stage,$row['stage'] === 'snoozed' ? $stage : null,
            $row['stage'] === 'snoozed' && $stage === 'awaiting_reply' ? $expires : null,
            $operation,$sentAt,$row['stage'] === 'snoozed' ? $row['next_wake_at'] : ($stage === 'awaiting_reply' ? $expires : null),$at,$id]);
    return true;
}

/** Only an exact delivered thread watch can establish the reply-received milestone. */
function agent_email_matters_note_watched_reply_v1(PDO $pdo,array $policy,array $watch,array $reply): bool
{
    $scope = agent_email_matters_policy_scope_v1($policy);
    $thread = agent_connection_gmail_id_v1($reply['thread_id'] ?? null);
    $messageId = agent_connection_gmail_id_v1($reply['message_id'] ?? null);
    $operation = $watch['operation_key'] ?? null;
    if ($scope === null || $thread === '' || $messageId === '' || ($watch['thread_id'] ?? null) !== $thread
        || ($watch['authorization_key'] ?? null) !== $policy['authorization_key']
        || (int)($watch['owner_user_id'] ?? 0) !== $scope[0] || (int)($watch['connection_id'] ?? 0) !== $scope[1]
        || agent_email_matters_received_ms_v1($reply['received_at_ms'] ?? null) < 1
        || !is_string($operation) || !preg_match('/\A[a-f0-9]{64}\z/D',$operation)) return false;
    if (!$pdo->inTransaction()) throw new LogicException('Email matter replies require the verified watch transaction.');
    $verify = $pdo->prepare("SELECT reply_chat_message_id FROM agent_connection_email_followups_v1
        WHERE owner_user_id=? AND connection_id=? AND authorization_key=? AND operation_key=? AND thread_id=?
        AND status='delivered' AND reply_message_id=? AND sender_after_ms IS NULL");
    $verify->execute([$scope[0],$scope[1],$policy['authorization_key'],$operation,$thread,$messageId]);
    $chatMessage = (int)$verify->fetchColumn();
    if ($chatMessage < 1) return false;
    $id = agent_email_matters_source_key_v1($policy,'thread',$thread);
    if (agent_email_matters_find_v1($pdo,$scope,$id,true) === null) return false;
    $at = agent_commerce_time_pa2();
    $eventKey = agent_email_matters_source_key_v1($policy,'watched_reply',$operation.':'.$messageId);
    if (!agent_email_matters_event_v1($pdo,$id,$eventKey,'watched_reply',$at,$operation,$chatMessage)) return false;
    $reply['reply'] = true;
    $reply['important'] = in_array('IMPORTANT',(array)($reply['labels'] ?? []),true);
    $reply['from'] = agent_connection_gmail_address_v1($reply['from'] ?? null);
    agent_email_matters_observe_inbox_v1($pdo,$policy,$reply,$at,true);
    $row = agent_email_matters_find_v1($pdo,$scope,$id,true);
    if ($row['last_operation_key'] !== $operation
        || !in_array($row['stage'],['needs_review','awaiting_reply','followup_due','snoozed'],true)) return true;
    $pdo->prepare('UPDATE agent_email_matters_v1 SET stage=?,resume_stage=?,resume_wake_at=NULL,next_wake_at=?,
        updated_at=?,revision=revision+1 WHERE matter_id=?')
        ->execute([$row['stage'] === 'snoozed' ? 'snoozed' : 'reply_received',
            $row['stage'] === 'snoozed' ? 'reply_received' : null,
            $row['stage'] === 'snoozed' ? $row['next_wake_at'] : null,$at,$id]);
    return true;
}

/** The checked cursor transaction owns these counters, including unfinished pages. */
function agent_email_matters_record_scan_v1(PDO $pdo,array $policy,array $review,bool $more): void
{
    $scope = agent_email_matters_policy_scope_v1($policy);
    $at = $review['at'] ?? null;
    if ($scope === null || agent_commerce_date_pa2($at) === 0) return;
    if (!$pdo->inTransaction()) throw new LogicException('Email scan counters require the cursor transaction.');
    $pdo->prepare(agent_commerce_insert_ignore_pa2($pdo).' INTO agent_email_matter_scans_v1
        (owner_user_id,connection_id,grant_key) VALUES (?,?,?)')->execute($scope);
    $lookup = $pdo->prepare('SELECT * FROM agent_email_matter_scans_v1 WHERE owner_user_id=? AND connection_id=?
        AND grant_key=?'.agent_commerce_lock_pa2($pdo));
    $lookup->execute($scope);
    $row = $lookup->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('The inbox scan checkpoint could not be saved.');
    $count = max(0,(int)($review['total'] ?? 0));
    $total = (int)$row['pending_total']+$count;
    $important = (int)$row['pending_important']+max(0,min($count,(int)($review['important'] ?? 0)));
    $replies = (int)$row['pending_replies']+max(0,min($count,(int)($review['replies'] ?? 0)));
    if ($more) {
        $pdo->prepare('UPDATE agent_email_matter_scans_v1 SET pending_total=?,pending_important=?,pending_replies=?,in_progress=1
            WHERE owner_user_id=? AND connection_id=? AND grant_key=?')->execute([$total,$important,$replies,...$scope]);
        return;
    }
    $pdo->prepare('UPDATE agent_email_matter_scans_v1 SET previous_at=?,completed_at=?,total=?,important=?,replies=?,
        pending_total=0,pending_important=0,pending_replies=0,in_progress=0
        WHERE owner_user_id=? AND connection_id=? AND grant_key=?')
        ->execute([$row['completed_at'],$at,$total,$important,$replies,...$scope]);
    agent_email_matters_advance_due_v1($pdo,$policy,$scope,$at);
}

/** Native watch lifecycle evidence is limited to the matter's exact operation and current mailbox grant. */
function agent_email_matters_ended_watch_clause_v1(PDO $pdo,array $policy,string $at): array
{
    require_once __DIR__.'/agent-connection-email-followup-v1.php';
    if (!agent_connection_email_followup_ready_v1($pdo)) return ['0=1',[]];
    return ["EXISTS (SELECT 1 FROM agent_connection_email_followups_v1 f
        WHERE f.owner_user_id=m.owner_user_id AND f.connection_id=m.connection_id AND f.authorization_key=?
        AND f.operation_key=m.last_operation_key AND f.sender_after_ms IS NULL
        AND (f.status IN ('stopped','expired') OR (f.status='waiting' AND f.expires_at<=?)))",
        [$policy['authorization_key'],$at]];
}

/** A successful provider check resumes due matters without dispatching external actions. */
function agent_email_matters_advance_due_v1(PDO $pdo,array $policy,array $scope,string $at): void
{
    [$ended,$watchParams] = agent_email_matters_ended_watch_clause_v1($pdo,$policy,$at);
    $query = $pdo->prepare("SELECT matter_id,stage,resume_stage,resume_wake_at,revision,$ended AS watch_ended
        FROM agent_email_matters_v1 m WHERE m.owner_user_id=? AND m.connection_id=? AND m.grant_key=?
        AND stage IN ('snoozed','awaiting_reply') AND (next_wake_at<=? OR (stage='awaiting_reply' AND $ended))
        ORDER BY next_wake_at,matter_id LIMIT 50".agent_commerce_lock_pa2($pdo));
    $query->execute([...$watchParams,...$scope,$at,...$watchParams]);
    $update = $pdo->prepare('UPDATE agent_email_matters_v1 SET stage=?,resume_stage=NULL,resume_wake_at=NULL,next_wake_at=?,
        updated_at=?,revision=revision+1 WHERE matter_id=? AND revision=?');
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $stage = $row['stage'] === 'snoozed' ? ($row['resume_stage'] ?? 'needs_review') : 'followup_due';
        $wake = $stage === 'awaiting_reply' ? $row['resume_wake_at'] : null;
        if ($stage === 'awaiting_reply' && (!empty($row['watch_ended']) || (is_string($wake) && $wake <= $at))) {
            $stage = 'followup_due';
            $wake = null;
        }
        $update->execute([$stage,$wake,$at,$row['matter_id'],$row['revision']]);
        if ($update->rowCount() === 1) {
            $key = agent_email_matters_source_key_v1($policy,'wake',$row['matter_id'].':'.$row['revision']);
            agent_email_matters_event_v1($pdo,$row['matter_id'],$key,$stage === 'followup_due' ? $stage : 'snooze_elapsed',$at);
        }
    }
}

/** Discard incomplete pages when an owner explicitly restarts the Gmail cursor. */
function agent_email_matters_reset_scan_v1(PDO $pdo,array $policy): void
{
    $scope = agent_email_matters_policy_scope_v1($policy);
    if ($scope === null || !agent_email_matters_ready_v1($pdo)) return;
    if (!$pdo->inTransaction()) throw new LogicException('Inbox scan reset requires the policy transaction.');
    $pdo->prepare('UPDATE agent_email_matter_scans_v1 SET pending_total=0,pending_important=0,pending_replies=0,in_progress=0
        WHERE owner_user_id=? AND connection_id=? AND grant_key=?')->execute($scope);
}

function agent_email_matters_iso_v1(mixed $value): ?string
{
    return agent_commerce_date_pa2($value) === 0 ? null : str_replace(' ','T',$value).'Z';
}

function agent_email_matters_scan_status_v1(PDO $pdo,array $scope): ?array
{
    $bound = agent_email_matters_bound_scope_v1($scope);
    if ($bound === null || !agent_email_matters_ready_v1($pdo)) return null;
    $query = $pdo->prepare('SELECT completed_at,previous_at,total,important,replies,in_progress FROM agent_email_matter_scans_v1
        WHERE owner_user_id=? AND connection_id=? AND grant_key=?');
    $query->execute($bound);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return $row ? ['at'=>agent_email_matters_iso_v1($row['completed_at']),
        'from'=>agent_email_matters_iso_v1($row['previous_at']),'total'=>(int)$row['total'],
        'important'=>(int)$row['important'],'replies'=>(int)$row['replies'],'in_progress'=>(bool)$row['in_progress']] : null;
}

function agent_email_matters_view_v1(array $row): array
{
    return ['id'=>$row['matter_id'],'stage'=>$row['stage'],'from'=>$row['sender'],'subject'=>$row['subject'],
        'direction'=>$row['latest_message_key'] === null ? 'outbound' : 'inbound',
        'important'=>(bool)$row['important'],'is_reply'=>(bool)$row['is_reply'],
        'first_seen_at'=>agent_email_matters_iso_v1($row['first_seen_at']),'last_seen_at'=>agent_email_matters_iso_v1($row['last_seen_at']),
        'next_wake_at'=>$row['stage'] === 'followup_due' ? null : agent_email_matters_iso_v1($row['next_wake_at']),
        'revision'=>(int)$row['revision']];
}

/** A watch owns its own claim, poll schedule and retention, independent of owner response deadlines. */
function agent_email_matters_watch_views_v1(PDO $pdo,array $scope,array $rows): array
{
    $operations = [];
    foreach ($rows as $row) {
        if (in_array($row['stage'],['awaiting_reply','followup_due','reply_received'],true) && $row['last_operation_key'] !== null) {
            $operations[] = $row['last_operation_key'];
        }
    }
    if (!$operations) return [];
    require_once __DIR__.'/agent-connection-email-followup-v1.php';
    if (!agent_connection_email_followup_ready_v1($pdo)) return [];
    $query = $pdo->prepare('SELECT operation_key,recipient_email,status,claim_key,next_check_at,expires_at,
        agent_id,conversation_id,reply_chat_message_id FROM agent_connection_email_followups_v1
        WHERE owner_user_id=? AND connection_id=? AND authorization_key=? AND sender_after_ms IS NULL
        AND operation_key IN ('.implode(',',array_fill(0,count($operations),'?')).')');
    $query->execute([$scope['owner'],$scope['policy']['connection_id'],$scope['policy']['authorization_key'],...$operations]);
    $views = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['status'] === 'delivered' && (int)$row['reply_chat_message_id'] > 0) {
            $views[$row['operation_key']] = ['reply_result'=>['agent_id'=>(int)$row['agent_id'],
                'conversation_id'=>(int)$row['conversation_id'],'message_id'=>(int)$row['reply_chat_message_id']]];
            continue;
        }
        $views[$row['operation_key']] = ['waiting_for'=>$row['recipient_email'],
            'next_check_at'=>$row['status'] === 'waiting' && $row['claim_key'] === null
                && (string)$row['expires_at'] > agent_commerce_time_pa2()
                ? agent_email_matters_iso_v1($row['next_check_at']) : null];
    }
    return $views;
}

/** Saved response tracking is an additive view of the exact receipt, never a second lifecycle store. */
function agent_email_matters_followthrough_views_v1(PDO $pdo,array $scope,array $rows): array
{
    $agent=$scope['agent_id'] ?? null;
    if(!is_int($agent) || $agent<0 || agent_email_matters_bound_scope_v1($scope)===null)return [];
    $operations=[];
    foreach($rows as $row)if(is_string($row['last_operation_key'] ?? null))$operations[]=$row['last_operation_key'];
    if(!$operations)return [];
    require_once __DIR__.'/agent-email-followthrough-status-v1.php';
    if(!agent_email_followthrough_ready_v1($pdo))return [];
    $q=$pdo->prepare('SELECT * FROM agent_email_followthrough_v1 WHERE owner_user_id=? AND agent_id=? AND connection_id=? AND authorization_key=?
        AND COALESCE(active_operation_key,operation_key) IN ('.implode(',',array_fill(0,count($operations),'?')).')');
    $q->execute([(int)$scope['owner'],$agent,(int)$scope['policy']['connection_id'],$scope['policy']['authorization_key'],...$operations]);$views=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        try{
            $view=agent_email_followthrough_public_v1($pdo,$row);
            if($view['outcome']==='replied'){
                $connection=agent_connections_find_v1($pdo,(int)$scope['owner'],(int)$scope['policy']['connection_id']);
                $reply=$view['reply_target'];$proof=$reply===null?[]:agent_connection_email_followup_delivered_v1($pdo,$connection,$agent,
                    (int)$view['conversation_id'],[(int)$reply['message_id']]);
                if($reply===null || !isset($proof[(int)$reply['message_id']]))continue;
            }
            $views[$row['active_operation_key'] ?? $row['operation_key']]=['response_followthrough'=>array_intersect_key($view,
                array_flip(['followthrough_ref','revision','agent_id','conversation_id','outcome','paused','awaiting_new_deadline',
                    'deadline_at','time_zone','priority','check','preparation']))];
        }catch(DomainException|RuntimeException $error){if($error instanceof PDOException)throw $error;}
    }
    return $views;
}

function agent_email_matters_summary_v1(PDO $pdo,array $scope,int $limit=5): array
{
    $counts = array_fill_keys(['needs_review','awaiting_reply','reply_received','followup_due','actioned','snoozed'],0);
    $bound = agent_email_matters_bound_scope_v1($scope);
    if ($bound === null || !agent_email_matters_ready_v1($pdo)) return ['counts'=>$counts,'items'=>[],'closed'=>[]];
    $where = 'm.owner_user_id=? AND m.connection_id=? AND m.grant_key=?';
    $active = "$where AND m.stage IN ('needs_review','awaiting_reply','reply_received','followup_due','actioned','snoozed')";
    $columns = 'matter_id,sender,subject,important,is_reply,first_seen_at,last_seen_at,next_wake_at,revision,latest_message_key,last_operation_key';
    [$ended,$watchParams] = agent_email_matters_ended_watch_clause_v1($pdo,$scope['policy'],agent_commerce_time_pa2());
    $stage = "CASE WHEN m.stage='awaiting_reply' AND $ended THEN 'followup_due' ELSE m.stage END";
    $query = $pdo->prepare("SELECT stage,COUNT(*) AS total FROM
        (SELECT $stage AS stage FROM agent_email_matters_v1 m WHERE $active) matter_stages GROUP BY stage");
    $query->execute([...$watchParams,...$bound]);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $counts[$row['stage']] = (int)$row['total'];
    $query = $pdo->prepare("SELECT $columns,stage FROM
        (SELECT $columns,updated_at,$stage AS stage FROM agent_email_matters_v1 m WHERE $active) open_matters
        ORDER BY CASE stage WHEN 'reply_received' THEN 0 WHEN 'followup_due' THEN 1 WHEN 'needs_review' THEN 2
            WHEN 'awaiting_reply' THEN 3 WHEN 'actioned' THEN 4 ELSE 5 END, important DESC, updated_at DESC, matter_id ASC LIMIT ".max(1,min(12,$limit)));
    $query->execute([...$watchParams,...$bound]);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    $watchViews = agent_email_matters_watch_views_v1($pdo,$scope,$rows);
    $followthroughViews = agent_email_matters_followthrough_views_v1($pdo,$scope,$rows);
    $mailboxViews = [];
    if (($scope['policy']['rules']['triage_enabled'] ?? false) === true) {
        require_once __DIR__.'/agent-connection-gmail-inbox-activity-v1.php';
        $mailboxViews = agent_connection_gmail_inbox_activity_views_v1($pdo,$scope,$rows);
    }
    $items = array_map(static fn(array $row): array => agent_email_matters_view_v1($row)
        + ($watchViews[(string)$row['last_operation_key']] ?? []) + ($followthroughViews[(string)$row['last_operation_key']] ?? [])
        + ($mailboxViews[$row['matter_id']] ?? []),$rows);
    $query = $pdo->prepare("SELECT $columns,stage FROM agent_email_matters_v1 m WHERE $where AND stage IN ('dismissed','resolved')
        ORDER BY updated_at DESC,matter_id ASC LIMIT 3");
    $query->execute($bound);
    return ['counts'=>$counts,'items'=>$items,'closed'=>array_map('agent_email_matters_view_v1',$query->fetchAll(PDO::FETCH_ASSOC))];
}

/** Owner decisions use the observed revision so a stale click cannot dismiss newer mail. */
function agent_email_matters_update_v1(PDO $pdo,array $user,string $id,int $revision,string $action,?string $wakeAt=null): ?array
{
    if ($revision < 1 || !preg_match('/\A[a-f0-9]{64}\z/D',$id)
        || !in_array($action,['snooze','dismiss','resolve','reopen'],true)
        || ($action === 'snooze' && agent_commerce_date_pa2($wakeAt) <= time())
        || !agent_email_matters_ready_v1($pdo)) return null;
    require_once __DIR__.'/agent-connection-gmail-inbox-v1.php';
    return agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$user,$id,$revision,$action,$wakeAt): ?array {
        $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$user,true);
        $bound = agent_email_matters_bound_scope_v1($scope);
        $row = $bound === null ? null : agent_email_matters_find_v1($pdo,$bound,$id,true);
        if ($row === null || (int)$row['revision'] !== $revision) return null;
        $now = agent_commerce_time_pa2();
        if ($row['stage'] === 'awaiting_reply' || ($row['stage'] === 'snoozed' && $row['resume_stage'] === 'awaiting_reply')) {
            [$ended,$watchParams] = agent_email_matters_ended_watch_clause_v1($pdo,$scope['policy'],$now);
            $watch = $pdo->prepare("SELECT 1 FROM agent_email_matters_v1 m
                WHERE m.owner_user_id=? AND m.connection_id=? AND m.grant_key=? AND m.matter_id=? AND $ended");
            $watch->execute([...$bound,$id,...$watchParams]);
            if ($watch->fetchColumn() !== false) {
                if ($row['stage'] === 'snoozed') {
                    $row['resume_stage'] = 'followup_due';
                    $row['resume_wake_at'] = null;
                } else {
                    $row['stage'] = 'followup_due';
                    $row['next_wake_at'] = null;
                }
            }
        }
        $closed = in_array($row['stage'],['dismissed','resolved'],true);
        if (($action === 'reopen' && !$closed && $row['stage'] !== 'snoozed') || ($action !== 'reopen' && $closed)) return null;
        $stage = match ($action) {
            'snooze'=>'snoozed',
            'dismiss'=>'dismissed',
            'resolve'=>'resolved',
            'reopen'=>$row['resume_stage'] ?? 'needs_review',
        };
        $resume = $action === 'snooze' ? ($row['resume_stage'] ?? $row['stage']) : null;
        $resumeWake = $resume === 'awaiting_reply' ? ($row['resume_wake_at'] ?? $row['next_wake_at']) : null;
        $wake = $action === 'snooze' ? $wakeAt : ($stage === 'awaiting_reply' ? $row['resume_wake_at'] : null);
        if ($stage === 'awaiting_reply' && is_string($wake) && $wake <= $now) {
            $stage = 'followup_due';
            $wake = null;
        }
        $update = $pdo->prepare('UPDATE agent_email_matters_v1 SET stage=?,resume_stage=?,resume_wake_at=?,next_wake_at=?,
            updated_at=?,revision=revision+1 WHERE matter_id=? AND revision=?');
        $update->execute([$stage,$resume,$resumeWake,$wake,$now,$id,$revision]);
        if ($update->rowCount() !== 1) return null;
        $event = agent_email_matters_source_key_v1($scope['policy'],'owner_action',$id.':'.$revision.':'.$action);
        agent_email_matters_event_v1($pdo,$id,$event,$action,$now);
        return agent_email_matters_view_v1(array_replace($row,['stage'=>$stage,'next_wake_at'=>$wake,'revision'=>$revision+1]));
    });
}
