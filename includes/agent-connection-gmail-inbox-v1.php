<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-gmail-inbox-settings-v1.php';
require_once __DIR__.'/agent-connection-gmail-inbox-notice-v1.php';
require_once __DIR__.'/agent-commerce-recovery-pa2.php';

function agent_connection_gmail_inbox_receipts_install_v1(PDO $pdo): void
{
    $sqlite = agent_commerce_sqlite_pa2($pdo);
    $pdo->exec('CREATE TABLE IF NOT EXISTS agent_connection_gmail_inbox_receipts_v1 (
        owner_user_id INT UNSIGNED NOT NULL,
        message_key CHAR(64) NOT NULL,
        PRIMARY KEY (owner_user_id,message_key),
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
    )'.($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'));
}

function agent_connection_gmail_inbox_receipts_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT message_key FROM agent_connection_gmail_inbox_receipts_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

/** Receipts are retained for one active consent window and removed when that window ends. */
function agent_connection_gmail_inbox_receipts_clear_v1(PDO $pdo, int $owner): void
{
    if (!agent_connection_gmail_inbox_receipts_ready_v1($pdo)) return;
    $pdo->prepare('DELETE FROM agent_connection_gmail_inbox_receipts_v1 WHERE owner_user_id=?')->execute([$owner]);
}

function agent_connection_gmail_inbox_compare_cursor_v1(string $left, string $right): int
{
    return (strlen($left) <=> strlen($right)) ?: strcmp($left,$right);
}

function agent_connection_gmail_inbox_history_v1(string $access, string $cursor, string $page, ?callable $http): array
{
    $parameters = [
        'startHistoryId'=>$cursor, 'historyTypes'=>'messageAdded', 'labelId'=>'INBOX', 'maxResults'=>5,
        'fields'=>'history(id,messagesAdded(message(id))),nextPageToken,historyId',
    ];
    if ($page !== '') $parameters['pageToken'] = $page;
    $data = agent_connection_gmail_api_v1($access,'/history?'.http_build_query($parameters,'','&',PHP_QUERY_RFC3986),null,$http);
    $latest = agent_connection_gmail_inbox_history_id_v1($data['historyId']??null);
    $next = $data['nextPageToken']??'';
    $records = $data['history']??[];
    if ($latest === '' || agent_connection_gmail_inbox_compare_cursor_v1($latest,$cursor)<0
        || !is_string($next) || strlen($next)>4096 || !is_array($records) || !array_is_list($records) || count($records)>5) {
        throw new AgentConnectionGmailExceptionV1('Gmail returned invalid inbox history.');
    }
    $events = [];
    foreach ($records as $record) {
        $event = agent_connection_gmail_inbox_history_id_v1($record['id']??null);
        $added = $record['messagesAdded']??[];
        if ($event === '' || agent_connection_gmail_inbox_compare_cursor_v1($event,$cursor)<=0
            || agent_connection_gmail_inbox_compare_cursor_v1($latest,$event)<0 || !is_array($added) || !array_is_list($added)) {
            throw new AgentConnectionGmailExceptionV1('Gmail returned an invalid inbox history event.');
        }
        $ids = [];
        foreach ($added as $message) {
            $id = agent_connection_gmail_id_v1($message['message']['id']??null);
            if ($id === '') throw new AgentConnectionGmailExceptionV1('Gmail returned an invalid inbox message reference.');
            $ids[] = $id;
        }
        $events[] = ['id'=>$event,'messages'=>$ids];
        $cursor = $event;
    }
    return ['events'=>$events,'latest'=>$latest,'next'=>$next];
}

/** Load only headers needed for the owner's private review and deterministic filters. */
function agent_connection_gmail_inbox_message_v1(string $access, string $id, int $enabledAt, ?callable $http): ?array
{
    $metadata = agent_connection_gmail_inbox_metadata_v1($access,$id,$enabledAt,['From','Cc','Subject','In-Reply-To'],$http);
    return $metadata === null ? null : agent_connection_gmail_inbox_review_message_v1($metadata);
}

function agent_connection_gmail_inbox_review_message_v1(array $metadata): ?array
{
    $headers = [];
    foreach ($metadata['headers'] as $header) {
        if (!is_array($header) || !is_string($header['name']??null) || !is_string($header['value']??null)) continue;
        $name = strtolower($header['name']);
        if (!in_array($name,['from','cc','subject','in-reply-to'],true)) continue;
        $headers[$name] ??= $header['value'];
    }
    $from = agent_connection_gmail_inbox_mailboxes_v1($headers['from']??null);
    if (count($from) !== 1) return null;
    return [
        'message_id'=>agent_connection_gmail_id_v1($metadata['message_id']??null),
        'from'=>$from[0],
        'cc'=>$headers['cc']??'',
        'subject'=>trim(preg_replace('/[\x00-\x1f\x7f]+/',' ',$headers['subject']??'')??''),
        'important'=>in_array('IMPORTANT',$metadata['labels'],true),
        'reply'=>preg_match('/(?:^|\s)<[^<>\s@]+@[^<>\s@]+>(?:\s|$)/',$headers['in-reply-to']??'')===1,
    ];
}

function agent_connection_gmail_inbox_metadata_v1(string $access, string $id, int $enabledAt, array $names, ?callable $http, ?int $upperMs = null): ?array
{
    $path = '/messages/'.$id.'?format=metadata&metadataHeaders='.implode('&metadataHeaders=',array_map('rawurlencode',$names))
        .'&fields=id%2CinternalDate%2ClabelIds%2Cpayload%28headers%29';
    try {
        $message = agent_connection_gmail_api_v1($access,$path,null,$http);
    } catch (AgentConnectionGmailExceptionV1 $error) {
        if ($error->getCode() === 404) return null;
        throw $error;
    }
    if (($message['id']??null) !== $id) throw new AgentConnectionGmailExceptionV1('Gmail returned a different inbox message.');
    $receivedAt = $message['internalDate']??null;
    $labels = $message['labelIds']??[];
    if (!is_string($receivedAt) || !preg_match('/\A(?:0|-?[1-9][0-9]{0,18})\z/D',$receivedAt)
        || !is_array($labels) || array_filter($labels,static fn(mixed $label): bool => !is_string($label))) {
        throw new AgentConnectionGmailExceptionV1('Gmail returned invalid inbox message metadata.');
    }
    if (str_starts_with($receivedAt,'-') || agent_connection_gmail_inbox_compare_cursor_v1($receivedAt,(string)$enabledAt)<=0
        || ($upperMs !== null && agent_connection_gmail_inbox_compare_cursor_v1($receivedAt,(string)$upperMs)>=0)
        || !in_array('INBOX',$labels,true) || array_intersect(['SENT','DRAFT','TRASH','SPAM'],$labels)) return null;
    return ['message_id'=>$id,'received_at_ms'=>$receivedAt,'labels'=>$labels,'headers'=>(array)($message['payload']['headers']??[])];
}

function agent_connection_gmail_inbox_saved_item_v1(array $message): array
{
    $id = agent_connection_gmail_id_v1($message['message_id']??null);
    if ($id === '') throw new UnexpectedValueException('Gmail review message ID is missing or invalid.');
    return ['from'=>$message['from'],'subject'=>mb_substr($message['subject'],0,240,'UTF-8'),
        'category'=>$message['reply']?'reply':($message['important']?'important':'new'),
        'message_id_enc'=>ai_encrypt_secret($id)];
}

function agent_connection_gmail_inbox_key_v1(array $policy, string $value): string
{
    return hash_hmac('sha256',$policy['enabled_at_ms'].':'.$value,(string)$policy['authorization_key']);
}

/** A stale worker stops making provider calls as soon as it observes a revoked claim. */
function agent_connection_gmail_inbox_claim_current_v1(PDO $pdo, array $policy, string $claim, bool $github=false): bool
{
    $table = $github ? 'agent_connection_gmail_github_v1' : 'agent_connection_gmail_inbox_v1';
    $query = $pdo->prepare("SELECT 1 FROM {$table} p
        INNER JOIN agent_connections_v1 c ON c.id=p.connection_id AND c.owner_user_id=p.owner_user_id
            AND c.authorization_key=p.authorization_key AND c.provider='gmail' AND c.status='connected' AND c.read_enabled=1
        INNER JOIN users u ON u.id=p.owner_user_id AND u.is_active=1
        WHERE p.owner_user_id=? AND p.connection_id=? AND p.authorization_key=? AND p.revision=?
          AND p.enabled=1 AND p.claim_key=? AND p.next_check_at>?");
    $query->execute([$policy['owner_user_id'],$policy['connection_id'],$policy['authorization_key'],$policy['revision'],$claim,agent_commerce_time_pa2()]);
    return $query->fetchColumn() !== false;
}

function agent_connection_gmail_inbox_scan_v1(PDO $pdo, array $policy, string $claim, string $access, ?callable $http): array
{
    $seen = $pdo->prepare('SELECT 1 FROM agent_connection_gmail_inbox_receipts_v1 WHERE owner_user_id=? AND message_key=?');
    return agent_connection_gmail_inbox_scan_checkpoint_v1($policy,$access,$http,
        static fn(): bool => agent_connection_gmail_inbox_claim_current_v1($pdo,$policy,$claim),
        static function(string $key) use ($seen,$policy): bool {
            $seen->execute([$policy['owner_user_id'],$key]);
            return $seen->fetchColumn() !== false;
        },
        static fn(string $id): ?array => agent_connection_gmail_inbox_message_v1($access,$id,(int)$policy['enabled_at_ms'],$http),10);
}

function agent_connection_gmail_inbox_scan_checkpoint_v1(
    array $policy, string $access, ?callable $http, callable $claimCurrent, callable $alreadySeen, callable $read, int $budget
): array
{
    $cursor = (string)$policy['history_id'];
    $page = (string)($policy['history_page_token']??'');
    $pending = (string)$policy['pending_event_id'];
    $fingerprint = (string)$policy['pending_fingerprint'];
    $offset = (int)$policy['pending_offset'];
    $out = ['cursor'=>$cursor,'page'=>$page,'pending'=>$pending,'fingerprint'=>$fingerprint,'offset'=>$offset,'messages'=>[],'more'=>false];
    if ($cursor === '') return $out + ['status'=>'restart_required'];
    if (!$claimCurrent()) return $out + ['status'=>'retrying'];
    try {
        $history = agent_connection_gmail_inbox_history_v1($access,$cursor,$page,$http);
    } catch (AgentConnectionGmailExceptionV1 $error) {
        if ($error->getCode() === 404) return $out + ['status'=>'restart_required'];
        if ($error->getCode() === 400 && $page !== '') {
            $out['page'] = '';
            return $out + ['status'=>'retrying'];
        }
        throw $error;
    }
    if (!$history['events'] && $history['next'] !== '') {
        if ($history['next'] === $page) throw new AgentConnectionGmailExceptionV1('Gmail returned a repeated inbox history page.');
        $out['page'] = $history['next'];
        $out['more'] = true;
        return $out + ['status'=>'checked'];
    }
    $out['page'] = '';
    $out['pending'] = '';
    $out['fingerprint'] = '';
    $out['offset'] = 0;
    $remaining = $budget;
    foreach ($history['events'] as $event) {
        $ids = $event['messages'];
        $signature = agent_connection_gmail_inbox_key_v1($policy,'event:'.implode(',', $ids));
        // Gmail label filtering may change a replayed event's membership or order.
        // Restart the slice on a changed fingerprint and skip already committed receipts.
        $position = $event['id'] === $pending && hash_equals($fingerprint,$signature) ? $offset : 0;
        for (; $position<count($ids) && $remaining>0; $position++, $remaining--) {
            $id = $ids[$position];
            $key = agent_connection_gmail_inbox_key_v1($policy,'message:'.$id);
            if (array_key_exists($key,$out['messages']) || $alreadySeen($key)) continue;
            if (!$claimCurrent()) return ['status'=>'retrying'];
            $out['messages'][$key] = $read($id);
        }
        if ($position<count($ids)) {
            $out['pending'] = $event['id'];
            $out['fingerprint'] = $signature;
            $out['offset'] = $position;
            $out['more'] = true;
            return $out + ['status'=>'checked'];
        }
        $out['cursor'] = $event['id'];
    }
    $out['more'] = $history['next'] !== '';
    if (!$out['more']) $out['cursor'] = $history['latest'];
    return $out + ['status'=>'checked'];
}

function agent_connection_gmail_inbox_user_v1(PDO $pdo, int $owner, bool $lock=false): array
{
    $query = $pdo->prepare('SELECT * FROM users WHERE id=?'.($lock ? agent_commerce_lock_pa2($pdo) : ''));
    $query->execute([$owner]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($user) ? $user : [];
}

function agent_connection_gmail_inbox_grant_v1(array $user, ?array $connection, array $policy): bool
{
    return agent_connection_gmail_inbox_authority_v1($user,$connection)
        && (int)$connection['id'] === (int)$policy['connection_id']
        && (int)$connection['owner_user_id'] === (int)$policy['owner_user_id']
        && hash_equals((string)$connection['authorization_key'],(string)$policy['authorization_key']);
}

/** Lock in the same order as Inbox Review settings and normal cursor commits. */
function agent_connection_gmail_inbox_bound_scope_v1(PDO $pdo, array $user, bool $lock=false): array
{
    $owner = (int)($user['id']??0);
    if ($owner<1 || (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1()!==null)) return ['state'=>'disabled'];
    $live = agent_connection_gmail_inbox_user_v1($pdo,$owner,$lock);
    if (!$live || empty($live['is_active']) || !has_permission('account.access',$live) || !has_permission('chat.access',$live)) return ['state'=>'disabled'];
    if (!agent_connection_gmail_inbox_ready_v1($pdo)) return ['state'=>'disabled'];
    $policy = agent_connection_gmail_inbox_policy_v1($pdo,$owner);
    if (empty($policy['enabled'])) return ['state'=>'disabled'];
    $connection = agent_connections_find_v1($pdo,$owner,(int)$policy['connection_id'],$lock && !agent_commerce_sqlite_pa2($pdo));
    if ($lock) $policy = agent_connection_gmail_inbox_policy_v1($pdo,$owner,true);
    if (empty($policy['enabled'])) return ['state'=>'disabled'];
    if (!agent_connection_gmail_inbox_grant_v1($live,$connection,$policy)) return ['state'=>'unavailable'];
    return ['state'=>'ready','owner'=>$owner,'user'=>$live,'policy'=>$policy,
        'generation'=>agent_connection_gmail_inbox_notice_generation_v1($policy),
        'provider_ready'=>agent_connection_gmail_ready_v1()
            && hash_equals((string)$connection['client_fingerprint'],agent_connections_client_fingerprint_v1())];
}

function agent_connection_gmail_inbox_review_v1(PDO $pdo, array $policy, array $messages, string $now): array
{
    $owner = (int)$policy['owner_user_id'];
    $insert = $pdo->prepare(agent_commerce_insert_ignore_pa2($pdo)
        .' INTO agent_connection_gmail_inbox_receipts_v1 (owner_user_id,message_key) VALUES (?,?)');
    $review = ['at'=>$now,'total'=>0,'important'=>0,'replies'=>0,'filtered'=>0,'items'=>[]];
    foreach ($messages as $key=>$message) {
        $insert->execute([$owner,$key]);
        if ($insert->rowCount() !== 1 || $message === null) continue;
        if (agent_connection_gmail_inbox_ignored_v1($message,$policy['rules'])) {
            $review['filtered']++;
            continue;
        }
        $review['total']++;
        $review['important'] += (int)$message['important'];
        $review['replies'] += (int)$message['reply'];
        $review['items'][] = agent_connection_gmail_inbox_saved_item_v1($message);
    }
    return $review;
}

/** Finalize the claimed cursor and private review atomically with its notification. */
function agent_connection_gmail_inbox_finish_v1(PDO $pdo, array $policy, string $claim, array $scan): bool
{
    if (!agent_commerce_sqlite_pa2($pdo)) $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    return agent_commerce_atomic_pa2($pdo,static function() use ($pdo,$policy,$claim,$scan): bool {
        $owner = (int)$policy['owner_user_id'];
        $user = agent_connection_gmail_inbox_user_v1($pdo,$owner,true);
        $connection = agent_connections_find_v1($pdo,$owner,(int)$policy['connection_id'],!agent_commerce_sqlite_pa2($pdo));
        $live = agent_connection_gmail_inbox_policy_v1($pdo,$owner,true);
        $now = agent_commerce_time_pa2();
        if (!$live || empty($live['enabled']) || (int)$live['connection_id'] !== (int)$policy['connection_id']
            || !hash_equals((string)$live['authorization_key'],(string)$policy['authorization_key'])
            || !hash_equals((string)$live['revision'],(string)$policy['revision'])
            || !hash_equals((string)$live['claim_key'],$claim) || (string)$live['next_check_at']<=$now) return false;
        if (!agent_connection_gmail_inbox_grant_v1($user,$connection,$live)) {
            agent_connection_gmail_inbox_revoke_v1($pdo,$owner,(int)$live['connection_id'],(string)$live['authorization_key']);
            return false;
        }
        $status = $scan['status'];
        if (!agent_connection_gmail_ready_v1()
            || !hash_equals((string)$connection['client_fingerprint'],agent_connections_client_fingerprint_v1())) {
            $scan = [];
            $status = 'retrying';
        }
        $review = agent_connection_gmail_inbox_review_v1($pdo,$live,$scan['messages']??[],$now);
        $reviewed = $review['total']>0 || $review['filtered']>0;
        $reviewJson = $reviewed ? json_encode($review,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE) : null;
        if ($reviewed) {
            // Preserve a pre-upgrade latest review before the cursor replaces it.
            agent_connection_gmail_inbox_batch_save_v1($pdo,$live,(string)($live['last_review_json'] ?? ''));
        }
        $next = match ($status) {
            'restart_required'=>null,
            'retrying'=>gmdate('Y-m-d H:i:s',time()+900),
            default=>gmdate('Y-m-d H:i:s',time()+(!empty($scan['more'])?0:300)),
        };
        $pdo->prepare('UPDATE agent_connection_gmail_inbox_v1 SET history_id=?,history_page_token=?,pending_event_id=?,
            pending_fingerprint=?,pending_offset=?,claim_key=NULL,next_check_at=?,last_checked_at=?,last_status=?,
            last_review_json=COALESCE(?,last_review_json) WHERE owner_user_id=?')
            ->execute([$scan['cursor']??$live['history_id'],($scan['page']??$live['history_page_token'])?:null,
                $scan['pending']??$live['pending_event_id'],$scan['fingerprint']??$live['pending_fingerprint'],
                $scan['offset']??$live['pending_offset'],$next,$now,$status,
                $reviewJson,$owner]);
        if ($review['total']>0) {
            $notice = agent_connection_gmail_inbox_notify_v1($pdo,$owner,$review,$live);
            agent_connection_gmail_inbox_batch_save_v1($pdo,$live,$reviewJson,$notice);
            require_once __DIR__.'/agent-connection-gmail-inbox-initial-v1.php';
            $scope = agent_connection_gmail_inbox_initial_scope_v1($pdo,$user);
            if ($scope['state'] === 'ready') {
                $target = agent_connection_gmail_inbox_update_destination_v1($pdo,$scope);
                if ($target) agent_connection_gmail_inbox_update_post_v1($pdo,$scope,$target['agent_id'],$target['conversation_id']);
            }
        }
        return $reviewed;
    });
}

/** Run only on the Cloud worker. Gmail network activity never runs inside inbox state locks. */
function agent_connection_gmail_inbox_sweep_v1(PDO $pdo, int $limit=5, ?callable $http=null): array
{
    $counts = ['processed'=>0,'reviewed'=>0];
    if ($limit<1 || $pdo->inTransaction() || !agent_connection_gmail_inbox_ready_v1($pdo)
        || !agent_connection_gmail_inbox_receipts_ready_v1($pdo)
        || !agent_connection_gmail_inbox_batches_ready_v1($pdo)) return $counts;
    $due = $pdo->prepare('SELECT * FROM agent_connection_gmail_inbox_v1 WHERE enabled=1 AND next_check_at<=?
        ORDER BY next_check_at,owner_user_id LIMIT '.min($limit,5));
    $due->execute([agent_commerce_time_pa2()]);
    $reserve = $pdo->prepare('UPDATE agent_connection_gmail_inbox_v1 SET claim_key=?,next_check_at=?
        WHERE owner_user_id=? AND connection_id=? AND authorization_key=? AND revision=? AND enabled=1 AND next_check_at<=?');
    foreach ($due->fetchAll(PDO::FETCH_ASSOC) as $policy) {
        $now = agent_commerce_time_pa2();
        $claim = bin2hex(random_bytes(16));
        $reserve->execute([$claim,gmdate('Y-m-d H:i:s',time()+180),$policy['owner_user_id'],$policy['connection_id'],
            $policy['authorization_key'],$policy['revision'],$now]);
        if ($reserve->rowCount() !== 1) continue;
        $counts['processed']++;
        $owner = (int)$policy['owner_user_id'];
        $connectionId = (int)$policy['connection_id'];
        $scan = ['status'=>'retrying'];
        try {
            $connection = agent_connections_find_v1($pdo,$owner,$connectionId);
            if (agent_connection_gmail_inbox_grant_v1(agent_connection_gmail_inbox_user_v1($pdo,$owner),$connection,$policy)
                && agent_connection_gmail_ready_v1()
                && hash_equals((string)$connection['client_fingerprint'],agent_connections_client_fingerprint_v1())
                && agent_connection_gmail_inbox_claim_current_v1($pdo,$policy,$claim)) {
                $access = agent_connections_access_v1($owner,$connectionId,'read',$http,(string)$policy['authorization_key']);
                if (hash_equals((string)$access['authorization_key'],(string)$policy['authorization_key'])) {
                    $scan = agent_connection_gmail_inbox_scan_v1($pdo,$policy,$claim,$access['access_token'],$http);
                }
            }
        } catch (Throwable $error) {
            if (function_exists('agent_runtime_v125_trace')) {
                agent_runtime_v125_trace('worker.gmail_inbox.poll_failed',['error_class'=>get_class($error)]);
            }
        }
        try {
            if (agent_connection_gmail_inbox_finish_v1($pdo,$policy,$claim,$scan)) $counts['reviewed']++;
        } catch (Throwable $error) {
            if (function_exists('agent_runtime_v125_trace')) {
                agent_runtime_v125_trace('worker.gmail_inbox.commit_failed',['error_class'=>get_class($error)]);
            }
        }
    }
    return $counts;
}
