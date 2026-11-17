<?php
declare(strict_types=1);

require_once __DIR__.'/agent-email-matters-v1.php';
require_once __DIR__.'/agent-connection-gmail-inbox-settings-v1.php';

function agent_connection_gmail_inbox_activity_install_v1(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new LogicException('Gmail activity storage cannot be installed inside a transaction.');
    $tail = agent_commerce_sqlite_pa2($pdo) ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_connection_gmail_inbox_activity_v1 (
        matter_id CHAR(64) PRIMARY KEY,
        checked_at DATETIME NOT NULL,
        mailbox_state VARCHAR(16) NULL,
        mailbox_anchor_key CHAR(64) NULL,
        sent_anchor_key CHAR(64) NULL,
        sent_message_id_enc VARCHAR(512) NULL,
        FOREIGN KEY (matter_id) REFERENCES agent_email_matters_v1(matter_id) ON DELETE CASCADE
    )".$tail);
}

function agent_connection_gmail_inbox_activity_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT matter_id,checked_at,mailbox_state,mailbox_anchor_key,sent_anchor_key,sent_message_id_enc
            FROM agent_connection_gmail_inbox_activity_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

/** Call before removing the owner's matter rows, including when SQLite foreign keys are disabled. */
function agent_connection_gmail_inbox_activity_clear_v1(PDO $pdo,int $owner): void
{
    if ($owner < 1 || !agent_connection_gmail_inbox_activity_ready_v1($pdo) || !agent_email_matters_ready_v1($pdo)) return;
    $pdo->prepare('DELETE FROM agent_connection_gmail_inbox_activity_v1 WHERE matter_id IN
        (SELECT matter_id FROM agent_email_matters_v1 WHERE owner_user_id=?)')->execute([$owner]);
}

/** Fetch only the metadata needed to match an already-tracked inbound message. */
function agent_connection_gmail_inbox_activity_thread_v1(string $access,string $thread,?callable $http): array
{
    $query = 'format=metadata&metadataHeaders=From&metadataHeaders=To&metadataHeaders=Message-ID'
        .'&metadataHeaders=In-Reply-To&metadataHeaders=References'
        .'&fields='.rawurlencode('id,messages(id,threadId,labelIds,internalDate,payload(headers))');
    $result = agent_connection_gmail_api_v1($access,'/threads/'.$thread.'?'.$query,null,$http);
    $records = $result['messages'] ?? null;
    if (($result['id'] ?? null) !== $thread || !is_array($records) || !array_is_list($records)
        || !$records || count($records) > 500) {
        throw new AgentConnectionGmailExceptionV1('Gmail did not verify this tracked thread.',502);
    }
    $messages = [];
    foreach ($records as $record) {
        if (!is_array($record)) throw new AgentConnectionGmailExceptionV1('Gmail returned an invalid tracked thread message.',502);
        $id = agent_connection_gmail_id_v1($record['id'] ?? null);
        $labels = array_key_exists('labelIds',$record) ? $record['labelIds'] : [];
        if ($id === '' || isset($messages[$id]) || ($record['threadId'] ?? null) !== $thread
            || !is_array($labels) || !array_is_list($labels)
            || array_filter($labels,static fn($label): bool=>!is_string($label))) {
            throw new AgentConnectionGmailExceptionV1('Gmail returned an invalid tracked thread message.',502);
        }
        $messages[$id] = agent_connection_gmail_message_v1($record);
    }
    return $messages;
}

function agent_connection_gmail_inbox_activity_reply_matches_v1(array $message,string $parent): bool
{
    if ($message['in_reply_to'] !== '') return hash_equals($parent,$message['in_reply_to']);
    $references = preg_split('/\s+/',$message['references'],-1,PREG_SPLIT_NO_EMPTY);
    if (!$references || count($references) > 60 || end($references) !== $parent) return false;
    foreach ($references as $ref) if (!agent_connection_gmail_reply_rfc_id_v1($ref)) return false;
    return true;
}

function agent_connection_gmail_inbox_activity_assess_v1(
    array $messages,string $anchorId,string $sender,int $receivedAt,string $mailbox
): array {
    $anchor = $messages[$anchorId] ?? null;
    if ($anchor === null || agent_connection_gmail_address_v1($anchor['from']) !== $sender
        || $sender === $mailbox || array_intersect($anchor['labels'],['SENT','DRAFT','SPAM','TRASH'])) {
        return ['mailbox_state'=>null,'sent'=>null];
    }
    $state = 'archived';
    $newerInbound = false;
    $candidates = [];
    foreach ($messages as $message) {
        if (array_intersect($message['labels'],['DRAFT','SPAM','TRASH'])) continue;
        if (in_array('INBOX',$message['labels'],true)) $state = 'inbox';
        if ($message['message_id'] === $anchorId) continue;
        if (in_array('SENT',$message['labels'],true)) {
            // A native send can already be present in Gmail while its VP3 receipt is still pending.
            if (!preg_match('/\A<vp3-[a-f0-9]{64}@vp3\.me>\z/D',$message['rfc_message_id'])) $candidates[] = $message;
        } elseif (agent_connection_gmail_address_v1($message['from']) !== $mailbox
            && ($message['received_at_ms'] < 1 || $message['received_at_ms'] >= $receivedAt)) {
            $newerInbound = true;
        }
    }
    $parent = $anchor['rfc_message_id'];
    $sent = null;
    if (!$newerInbound && $anchor['received_at_ms'] === $receivedAt && agent_connection_gmail_reply_rfc_id_v1($parent)) {
        foreach ($candidates as $message) {
            $when = $message['received_at_ms'];
            if ($when <= $receivedAt || ($sent !== null && $when <= $sent['at_ms'])
                || agent_connection_gmail_address_v1($message['from']) !== $mailbox
                || !in_array($sender,agent_connection_gmail_inbox_mailboxes_v1($message['to']),true)
                || !agent_connection_gmail_inbox_activity_reply_matches_v1($message,$parent)) continue;
            $sent = ['message_id'=>$message['message_id'],'at_ms'=>$when];
        }
    }
    return ['mailbox_state'=>$state,'sent'=>$sent];
}

/** Rotate over two already-tracked threads. Gmail is never called within a database transaction. */
function agent_connection_gmail_inbox_activity_scan_v1(
    PDO $pdo,array $policy,string $access,string $mailbox,callable $claimCurrent,?callable $http=null
): array {
    $scope = agent_email_matters_policy_scope_v1($policy);
    $mailbox = agent_connection_gmail_sender_v1($mailbox);
    if ($pdo->inTransaction() || $scope === null || $mailbox === '' || ($policy['rules']['triage_enabled'] ?? false) !== true
        || !agent_connection_gmail_inbox_activity_ready_v1($pdo) || !$claimCurrent()) return [];
    $query = $pdo->prepare("SELECT m.matter_id,m.revision,m.thread_id_enc,m.latest_message_id_enc,
        m.latest_message_key,m.latest_received_ms,m.sender FROM agent_email_matters_v1 m
        LEFT JOIN agent_connection_gmail_inbox_activity_v1 a ON a.matter_id=m.matter_id
        WHERE m.owner_user_id=? AND m.connection_id=? AND m.grant_key=?
        AND m.stage NOT IN ('resolved','dismissed') AND m.latest_message_key IS NOT NULL
        ORDER BY a.checked_at ASC,m.matter_id ASC LIMIT 2");
    $query->execute($scope);
    $observations = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!$claimCurrent()) return [];
        $thread = agent_connection_gmail_id_v1(ai_decrypt_secret($row['thread_id_enc']));
        $anchor = agent_connection_gmail_id_v1(ai_decrypt_secret($row['latest_message_id_enc']));
        $sender = agent_connection_gmail_address_v1($row['sender']);
        $receipt = ['mailbox_state'=>null,'sent'=>null];
        $throttled = false;
        if ($thread !== '' && $anchor !== '' && $sender !== ''
            && hash_equals($row['matter_id'],agent_email_matters_source_key_v1($policy,'thread',$thread))
            && hash_equals($row['latest_message_key'],agent_email_matters_source_key_v1($policy,'inbox',$anchor))) {
            try {
                $messages = agent_connection_gmail_inbox_activity_thread_v1($access,$thread,$http);
                $receipt = agent_connection_gmail_inbox_activity_assess_v1(
                    $messages,$anchor,$sender,(int)$row['latest_received_ms'],$mailbox);
            } catch (AgentConnectionGmailExceptionV1 $e) {
                // An unavailable thread must not prevent Inbox Review or block other tracked threads.
                $throttled = in_array($e->getCode(),[401,403,429],true);
            }
        }
        if (!$claimCurrent()) return [];
        $observations[] = ['matter_id'=>$row['matter_id'],'revision'=>(int)$row['revision'],
            'anchor_key'=>$row['latest_message_key'],'thread_id'=>$thread]+$receipt;
        if ($throttled) break;
    }
    return $observations;
}

/** Record an exact provider-observed reply once, without changing an active native watch. */
function agent_connection_gmail_inbox_activity_apply_sent_v1(
    PDO $pdo,array $policy,array $scope,array $matter,array $observation,string $now
): ?string {
    $sent = $observation['sent'] ?? null;
    $id = agent_connection_gmail_id_v1($sent['message_id'] ?? null);
    $when = agent_email_matters_received_ms_v1($sent['at_ms'] ?? null);
    $thread = agent_connection_gmail_id_v1($observation['thread_id'] ?? null);
    $seconds = intdiv($when,1000);
    if ($id === '' || $thread === '' || $when <= (int)$matter['latest_received_ms']
        || $seconds < (int)$matter['last_sent_at'] || $seconds > agent_commerce_date_pa2($now)
        || !hash_equals($matter['matter_id'],agent_email_matters_source_key_v1($policy,'thread',$thread))) return null;
    $ledger = $pdo->prepare("SELECT 1 FROM agent_connection_sends_v1 WHERE owner_user_id=? AND connection_id=?
        AND authorization_key=? AND status='sent' AND provider_message_id=? AND provider_thread_id=?");
    $ledger->execute([$scope[0],$scope[1],$policy['authorization_key'],$id,$thread]);
    if ($ledger->fetchColumn() !== false) return null;

    $event = agent_email_matters_source_key_v1($policy,'gmail-sent-anchor',$matter['latest_message_key']);
    if (!agent_email_matters_event_v1($pdo,$matter['matter_id'],$event,'sent_in_gmail',$now)) return null;
    $stage = match ($matter['stage']) {
        'awaiting_reply','snoozed' => $matter['stage'],
        default => 'actioned',
    };
    $resume = $stage === 'snoozed' ? ($matter['resume_stage'] === 'awaiting_reply' ? 'awaiting_reply' : 'actioned') : null;
    $watch = $stage === 'awaiting_reply' || $resume === 'awaiting_reply';
    $pdo->prepare('UPDATE agent_email_matters_v1 SET stage=?,resume_stage=?,resume_wake_at=?,next_wake_at=?,
        last_operation_key=?,last_sent_at=?,updated_at=?,revision=revision+1 WHERE matter_id=?')
        ->execute([$stage,$resume,$resume === 'awaiting_reply' ? $matter['resume_wake_at'] : null,
            $stage !== 'actioned' ? $matter['next_wake_at'] : null,
            $watch ? $matter['last_operation_key'] : null,$seconds,$now,$matter['matter_id']]);
    return ai_encrypt_secret($id);
}

/** Apply under the verified Inbox Review claim, after observations of incoming messages. */
function agent_connection_gmail_inbox_activity_commit_v1(
    PDO $pdo,array $policy,array $observations,string $now
): void {
    $scope = agent_email_matters_policy_scope_v1($policy);
    if (!$observations || $scope === null || ($policy['rules']['triage_enabled'] ?? false) !== true
        || agent_commerce_date_pa2($now) === 0 || !agent_connection_gmail_inbox_activity_ready_v1($pdo)) return;
    if (!$pdo->inTransaction()) throw new LogicException('Gmail thread observations require the Inbox Review transaction.');
    $lookup = $pdo->prepare('SELECT checked_at,mailbox_state,mailbox_anchor_key FROM agent_connection_gmail_inbox_activity_v1
        WHERE matter_id=?'.agent_commerce_lock_pa2($pdo));
    foreach (array_slice($observations,0,2) as $observation) {
        $id = $observation['matter_id'] ?? null;
        if (!is_string($id) || !preg_match('/\A[a-f0-9]{64}\z/D',$id)) continue;
        $matter = agent_email_matters_find_v1($pdo,$scope,$id,true);
        if ($matter === null || (int)$matter['revision'] !== ($observation['revision'] ?? null)
            || $matter['latest_message_key'] === null || $matter['latest_message_key'] !== ($observation['anchor_key'] ?? null)
            || in_array($matter['stage'],['resolved','dismissed'],true)) continue;
        $lookup->execute([$id]);
        $prior = $lookup->fetch(PDO::FETCH_ASSOC);
        if ($prior && $prior['checked_at'] > $now) continue;
        if (!$prior) {
            $pdo->prepare(agent_commerce_insert_ignore_pa2($pdo)
                .' INTO agent_connection_gmail_inbox_activity_v1 (matter_id,checked_at) VALUES (?,?)')->execute([$id,$now]);
        }
        $state = $observation['mailbox_state'] ?? null;
        $verifiedState = in_array($state,['inbox','archived'],true);
        $anchor = $matter['latest_message_key'];
        if ($verifiedState && $prior && $prior['mailbox_anchor_key'] === $anchor && $prior['mailbox_state'] !== $state) {
            $event = agent_email_matters_source_key_v1($policy,'gmail-mailbox',$id.':'.$state.':'.$now);
            agent_email_matters_event_v1($pdo,$id,$event,'mailbox_'.$state,$now);
        }
        $sent = $verifiedState ? agent_connection_gmail_inbox_activity_apply_sent_v1($pdo,$policy,$scope,$matter,$observation,$now) : null;
        $pdo->prepare('UPDATE agent_connection_gmail_inbox_activity_v1 SET checked_at=?,mailbox_state=?,mailbox_anchor_key=?,
            sent_anchor_key=COALESCE(?,sent_anchor_key),sent_message_id_enc=COALESCE(?,sent_message_id_enc) WHERE matter_id=?')
            ->execute([$now,$verifiedState ? $state : null,$verifiedState ? $anchor : null,$sent === null ? null : $anchor,$sent,$id]);
    }
}

/** A newer message or reply on the same thread cannot erase an exact answer receipt. */
function agent_connection_gmail_inbox_activity_answered_v1(PDO $pdo,array $scope,array $anchors): array
{
    $policy = $scope['policy'] ?? [];
    $bound = agent_email_matters_bound_scope_v1($scope);
    if ($bound === null || ($policy['rules']['triage_enabled'] ?? false) !== true
        || !agent_email_matters_ready_v1($pdo) || !agent_connection_gmail_inbox_activity_ready_v1($pdo)) return [];
    $events = [];
    foreach (array_slice($anchors,0,50) as $anchor) {
        if (is_string($anchor) && preg_match('/\A[a-f0-9]{64}\z/D',$anchor)) {
            $events[agent_email_matters_source_key_v1($policy,'gmail-sent-anchor',$anchor)] = $anchor;
        }
    }
    if (!$events) return [];
    $placeholders = implode(',',array_fill(0,count($events),'?'));
    $matched = $pdo->prepare("SELECT e.event_key FROM agent_email_matter_events_v1 e
        JOIN agent_email_matters_v1 m ON m.matter_id=e.matter_id
        WHERE m.owner_user_id=? AND m.connection_id=? AND m.grant_key=?
        AND e.event_kind='sent_in_gmail' AND e.event_key IN ($placeholders)");
    $matched->execute([...$bound,...array_keys($events)]);
    $answered = [];
    foreach ($matched->fetchAll(PDO::FETCH_COLUMN) as $event) $answered[$events[$event]] = true;
    $remaining = array_values(array_filter($events,static fn(string $anchor): bool => !isset($answered[$anchor])));
    if (!$remaining) return $answered;
    $placeholders = implode(',',array_fill(0,count($remaining),'?'));
    $projected = $pdo->prepare("SELECT a.sent_anchor_key FROM agent_connection_gmail_inbox_activity_v1 a
        JOIN agent_email_matters_v1 m ON m.matter_id=a.matter_id
        WHERE m.owner_user_id=? AND m.connection_id=? AND m.grant_key=? AND a.sent_anchor_key IN ($placeholders)");
    $projected->execute([...$bound,...$remaining]);
    foreach ($projected->fetchAll(PDO::FETCH_COLUMN) as $anchor) $answered[$anchor] = true;
    return $answered;
}

function agent_connection_gmail_inbox_activity_views_v1(PDO $pdo,array $scope,array $rows): array
{
    $bound = agent_email_matters_bound_scope_v1($scope);
    $ids = array_column($rows,'matter_id');
    if (!$ids || $bound === null || ($scope['policy']['rules']['triage_enabled'] ?? false) !== true
        || !agent_connection_gmail_inbox_activity_ready_v1($pdo)) return [];
    $query = $pdo->prepare('SELECT a.matter_id,a.mailbox_state,a.mailbox_anchor_key,a.sent_anchor_key
        FROM agent_connection_gmail_inbox_activity_v1 a JOIN agent_email_matters_v1 m ON m.matter_id=a.matter_id
        WHERE m.owner_user_id=? AND m.connection_id=? AND m.grant_key=?
        AND a.matter_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
    $query->execute([...$bound,...$ids]);
    $anchors = array_column($rows,'latest_message_key','matter_id');
    $views = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = $row['matter_id'];
        $anchor = $anchors[$id] ?? null;
        if ($anchor === null) continue;
        if ($row['mailbox_anchor_key'] === $anchor && in_array($row['mailbox_state'],['inbox','archived'],true)) {
            $views[$id]['mailbox_state'] = $row['mailbox_state'];
        }
        if ($row['sent_anchor_key'] === $anchor) $views[$id]['sent_in_gmail'] = true;
    }
    return $views;
}
