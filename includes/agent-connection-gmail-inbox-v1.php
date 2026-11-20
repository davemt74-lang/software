<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-gmail-inbox-settings-v1.php';
require_once __DIR__.'/agent-connection-gmail-inbox-notice-v1.php';
require_once __DIR__.'/agent-email-matters-v1.php';
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

/** Live arrivals can request a bounded Gmail snippet under the existing content opt-in. */
function agent_connection_gmail_inbox_message_v1(string $access, string $id, int $enabledAt, ?callable $http, bool $includePreview=false): ?array
{
    $metadata = agent_connection_gmail_inbox_metadata_v1($access,$id,$enabledAt,['From','Cc','Subject','In-Reply-To'],$http,null,true,$includePreview);
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
    $review = [
        'message_id'=>agent_connection_gmail_id_v1($metadata['message_id']??null),
        'from'=>$from[0],
        'cc'=>$headers['cc']??'',
        'subject'=>trim(preg_replace('/[\x00-\x1f\x7f]+/',' ',$headers['subject']??'')??''),
        'labels'=>$metadata['labels'],
        'important'=>in_array('IMPORTANT',$metadata['labels'],true),
        'reply'=>preg_match('/(?:^|\s)<[^<>\s@]+@[^<>\s@]+>(?:\s|$)/',$headers['in-reply-to']??'')===1,
    ];
    $threadId = agent_connection_gmail_id_v1($metadata['thread_id']??null);
    if ($threadId !== '') $review += ['thread_id'=>$threadId,'received_at_ms'=>$metadata['received_at_ms']];
    if (isset($metadata['preview'])) $review['preview'] = $metadata['preview'];
    return $review;
}

/** Return the first complete sentence actually present in a bounded provider snippet. */
function agent_connection_gmail_inbox_preview_v1(mixed $snippet): ?string
{
    if (!is_string($snippet) || !mb_check_encoding($snippet,'UTF-8')) return null;
    $text = html_entity_decode(mb_substr($snippet,0,800,'UTF-8'),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $text = trim(preg_replace('/[\s\p{C}]+/u',' ',$text) ?? '');
    // Ignore common sentence-internal abbreviations and incomplete clipped text.
    if (!preg_match_all('/[.!?。！？]+(?:["”’)]*)(?= |\z)/u',$text,$matches,PREG_OFFSET_CAPTURE)) return null;
    foreach ($matches[0] as [$ending,$offset]) {
        if (str_starts_with($ending,'...')) return null;
        if ($ending === '.' && preg_match('/\b(?:Mr|Mrs|Ms|Dr|Prof|Sr|Jr|St|Inc|Ltd|e\.g|i\.e|[A-Z])\z/',substr($text,0,$offset))) {
            continue;
        }
        $sentence = substr($text,0,$offset+strlen($ending));
        return mb_strlen($sentence,'UTF-8') <= 280 && preg_match('/[\p{L}\p{N}]/u',$sentence) ? $sentence : null;
    }
    return null;
}

function agent_connection_gmail_inbox_metadata_v1(
    string $access, string $id, int $enabledAt, array $names, ?callable $http, ?int $upperMs = null, bool $withThread = false,
    bool $includePreview=false
): ?array
{
    $fields = $withThread ? 'id,threadId,internalDate,labelIds,payload(headers)' : 'id,internalDate,labelIds,payload(headers)';
    $base = '/messages/'.$id.'?format=';
    $metadataPath = $base.'metadata&metadataHeaders='.implode('&metadataHeaders=',array_map('rawurlencode',$names)).'&fields='.rawurlencode($fields);
    $path = $includePreview ? $base.'full&fields='.rawurlencode($fields.',snippet') : $metadataPath;
    try {
        try {
            $message = agent_connection_gmail_api_v1($access,$path,null,$http);
        } catch (AgentConnectionGmailExceptionV1 $error) {
            if (!$includePreview || !in_array($error->getCode(),[400,403],true)) throw $error;
            // Older grants or field handling can reject the optional preview while regular inbox reads still work.
            $includePreview = false;
            $message = agent_connection_gmail_api_v1($access,$metadataPath,null,$http);
        }
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
    $metadata = ['message_id'=>$id,'received_at_ms'=>$receivedAt,'labels'=>$labels,'headers'=>(array)($message['payload']['headers']??[])];
    if ($withThread) $metadata['thread_id'] = agent_connection_gmail_id_v1($message['threadId']??null);
    if ($includePreview && ($preview = agent_connection_gmail_inbox_preview_v1($message['snippet'] ?? null)) !== null) {
        $metadata['preview'] = $preview;
    }
    return $metadata;
}

function agent_connection_gmail_inbox_saved_item_v1(array $message): array
{
    $id = agent_connection_gmail_id_v1($message['message_id']??null);
    if ($id === '') throw new UnexpectedValueException('Gmail review message ID is missing or invalid.');
    $item = ['from'=>$message['from'],'subject'=>mb_substr($message['subject'],0,240,'UTF-8'),
        'category'=>$message['reply']?'reply':($message['important']?'important':'new'),
        'important'=>$message['important'],
        'message_id_enc'=>ai_encrypt_secret($id)];
    $signals = agent_connection_gmail_subject_priority_signals_v1($message['subject']);
    if ($signals) $item['priority_signals'] = $signals;
    if (!empty($message['priority_matches'])) $item['priority_matches'] = array_slice($message['priority_matches'],0,3);
    return $item;
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

function agent_connection_gmail_inbox_scan_v1(
    PDO $pdo,array $policy,string $claim,string $access,?callable $http,string $mailbox=''
): array
{
    $seen = $pdo->prepare('SELECT 1 FROM agent_connection_gmail_inbox_receipts_v1 WHERE owner_user_id=? AND message_key=?');
    $claimCurrent = static fn(): bool => agent_connection_gmail_inbox_claim_current_v1($pdo,$policy,$claim);
    $alreadySeen = static function(string $key) use ($seen,$policy): bool {
        $seen->execute([$policy['owner_user_id'],$key]);
        return $seen->fetchColumn() !== false;
    };
    $sync = agent_connection_gmail_inbox_sync_row_v1($pdo,$policy);
    if (($sync['status'] ?? null) === 'pending') {
        $scope = agent_email_matters_policy_scope_v1($policy);
        $reportLookup = agent_email_running_report_enabled_v1($policy) && agent_email_running_report_ready_v1($pdo)
            ? $pdo->prepare('SELECT received_ms,source_triage_id FROM agent_email_running_report_v1
                WHERE owner_user_id=? AND connection_id=? AND grant_key=? AND thread_key=?') : null;
        $alreadyClassified = static function (string $id) use ($pdo,$scope,$policy,$reportLookup): bool {
            if ($scope === null) return false;
            $triageId = agent_email_matters_source_key_v1($policy,'triage',$id);
            $triage = agent_email_triage_find_v1($pdo,$scope,$triageId);
            if ($triage === null) return false;
            if ($reportLookup === null || empty($triage['thread_id_enc'])) return true;
            $thread = agent_connection_gmail_id_v1(ai_decrypt_secret($triage['thread_id_enc']));
            if ($thread === '') return true;
            $reportLookup->execute([...$scope,agent_email_matters_source_key_v1($policy,'thread',$thread)]);
            $existing = $reportLookup->fetch(PDO::FETCH_ASSOC);
            if (!$existing) return !in_array($triage['attention_key'],['act_soon','review','unknown'],true);
            return (int)$existing['received_ms'] > (int)$triage['received_ms']
                || ((int)$existing['received_ms'] === (int)$triage['received_ms'] && strcmp($existing['source_triage_id'],$triageId) >= 0);
        };
        return agent_connection_gmail_inbox_sync_page_v1($policy,$sync,$access,$claimCurrent,$alreadyClassified,$http);
    }
    $preview = agent_email_running_report_enabled_v1($policy);
    $result = agent_connection_gmail_inbox_scan_checkpoint_v1($policy,$access,$http,$claimCurrent,$alreadySeen,
        static fn(string $id): ?array => agent_connection_gmail_inbox_message_v1($access,$id,(int)$policy['enabled_at_ms'],$http,$preview),10);
    if ($result['status'] === 'restart_required' && $mailbox !== ''
        && agent_connection_gmail_inbox_triage_enabled_v1($pdo,$policy)
        && agent_connection_gmail_inbox_sync_ready_v1($pdo)) {
        if (!$claimCurrent()) return ['status'=>'retrying'];
        $profile = agent_connection_gmail_inbox_profile_v1($access,$http);
        if ($profile['account_email'] !== agent_connection_gmail_sender_v1($mailbox)
            || agent_connection_gmail_inbox_compare_cursor_v1($profile['history_id'],(string)$policy['history_id']) < 0) {
            throw new AgentConnectionExceptionV1('Gmail did not verify the Inbox Review recovery cursor.',409);
        }
        if (!$claimCurrent()) return ['status'=>'retrying'];
        $sync = agent_connection_gmail_inbox_sync_start_v1($policy,'recovery',$profile['history_id'],(int)floor(microtime(true)*1000)+1);
        return agent_connection_gmail_inbox_sync_result_v1($sync,[]);
    }
    return $result;
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

function agent_connection_gmail_inbox_review_v1(PDO $pdo,array $policy,array $messages,string $now,bool $historical=false,?int $recoverySinceMs=null): array
{
    $owner = (int)$policy['owner_user_id'];
    $insert = $pdo->prepare(agent_commerce_insert_ignore_pa2($pdo)
        .' INTO agent_connection_gmail_inbox_receipts_v1 (owner_user_id,message_key) VALUES (?,?)');
    $review = ['at'=>$now,'total'=>0,'important'=>0,'replies'=>0,'filtered'=>0,'priority_count'=>0,'items'=>[]];
    $trackMatters = agent_email_matters_ready_v1($pdo);
    $triage = agent_connection_gmail_inbox_triage_enabled_v1($pdo,$policy);
    if ($triage) $review['triage_enabled'] = true;
    $analyze = $triage && agent_email_running_report_enabled_v1($policy) && agent_email_running_report_ready_v1($pdo);
    if ($historical) $review['historical'] = true;
    $scope = $trackMatters && $triage ? agent_email_matters_policy_scope_v1($policy) : null;
    foreach ($messages as $key=>$message) {
        $insert->execute([$owner,$key]);
        $fresh = $insert->rowCount() === 1;
        if ($message === null || (!$historical && !$fresh)) continue;
        if (agent_connection_gmail_inbox_ignored_v1($message,$policy['rules'])) {
            $review['filtered']++;
            continue;
        }
        $message['priority_matches'] = agent_connection_gmail_inbox_priority_matches_v1($message,$policy['rules']);
        $received = agent_email_matters_received_ms_v1($message['received_at_ms'] ?? null);
        if ($fresh && $recoverySinceMs !== null && $received >= $recoverySinceMs) {
            $review['recovered'][] = ['item'=>agent_connection_gmail_inbox_saved_item_v1($message),'received'=>$received];
        }
        $thread = $message['thread_id'] ?? '';
        $matter = $scope !== null && $thread !== ''
            ? agent_email_matters_find_v1($pdo,$scope,agent_email_matters_source_key_v1($policy,'thread',$thread),true)
            : null;
        $classified = null;
        if ($triage) {
            if ($matter !== null) {
                $message['active_followup'] = !in_array($matter['stage'],['dismissed','resolved','actioned'],true);
            }
            $classified = agent_email_triage_record_v1($pdo,$policy,$message,$now,$historical);
            if ($analyze) {
                $candidate = $classified;
                if ($candidate === null && $historical && $scope !== null) {
                    $id = agent_email_matters_source_key_v1($policy,'triage',$message['message_id']);
                    $saved = agent_email_triage_find_v1($pdo,$scope,$id);
                    if ($saved !== null) $candidate = agent_email_triage_view_v1($saved);
                }
                if ($candidate !== null) agent_email_running_report_enqueue_v1($pdo,$policy,$message,$candidate,$now);
            }
        }
        // A prior header-only receipt can still be classified with the owner's explicit catch-up.
        if ($historical && $classified === null) continue;
        $review['total']++;
        $review['important'] += (int)$message['important'];
        $review['replies'] += (int)$message['reply'];
        // Quiet catch-up stays quiet for routine mail. A priority match found
        // in an *explicitly scanned, previously unseen* historical message
        // gets its own alert without replaying old ignored receipts en masse.
        if (!$historical || ($fresh && !empty($message['priority_matches']))) {
            $item = agent_connection_gmail_inbox_saved_item_v1($message);
            if ($classified !== null) {
                $item['triage'] = array_intersect_key($classified,array_flip(['id','attention','reason','due_date','due_at','alert']));
                if ($historical && !empty($message['priority_matches'])) $item['triage']['alert'] = true;
                if ($analyze && agent_connection_gmail_inbox_routine_item_v1($item)
                    && ($preview = agent_connection_gmail_inbox_preview_v1($message['preview'] ?? null)) !== null) {
                    $item['preview_enc'] = ai_encrypt_secret($preview);
                }
            }
            if (!empty($message['priority_matches'])) $review['priority_count']++;
            $review['items'][] = $item;
        }
        if ($trackMatters && $thread !== '' && (!$triage || $matter !== null
            || in_array($classified['attention'] ?? null,['act_soon','review'],true))) {
            agent_email_matters_observe_inbox_v1($pdo,$policy,$message,$now,false,$historical);
        }
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
        $historical = ($scan['historical'] ?? false) === true;
        $recovering = $historical && ($scan['sync']['mode'] ?? null) === 'recovery';
        $lastChecked = $recovering ? agent_commerce_date_pa2($live['last_checked_at']) : 0;
        $review = agent_connection_gmail_inbox_review_v1($pdo,$live,$scan['messages']??[],$now,$historical,
            $lastChecked > 0 ? ($lastChecked+1)*1000 : null);
        if ($historical && isset($scan['sync'])) {
            $sync = $scan['sync'];
            $sync['reviewed'] = (int)$sync['reviewed']+$review['total'];
            agent_connection_gmail_inbox_sync_save_v1($pdo,$live,$sync);
            if ($sync['mode'] === 'recovery' && (int)$sync['pages'] === 0) {
                agent_email_matters_reset_scan_v1($pdo,$live);
            }
        }
        if (!empty($scan['activity'])) {
            agent_connection_gmail_inbox_activity_commit_v1($pdo,$live,$scan['activity'],$now);
        }
        if (!$historical && $status === 'checked' && agent_email_matters_ready_v1($pdo)) {
            agent_email_matters_record_scan_v1($pdo,$live,$review,!empty($scan['more']));
        }
        $reviewed = $review['total']>0 || $review['filtered']>0;
        $digest = !$historical && $review['total']>0 ? agent_connection_gmail_inbox_routine_digest_v1($review) : null;
        if ($digest !== null) {
            foreach ($review['items'] as &$item) unset($item['preview_enc']);
            unset($item);
        }
        $reviewJson = $reviewed && !$historical ? json_encode($review,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE) : null;
        $recovered = $review['recovered'] ?? [];
        if ($recovered) {
            $latestMs = max(array_column($recovered,'received'));
            $prior = agent_connection_gmail_inbox_batch_report_v1((string)($live['last_review_json'] ?? ''));
            // Gmail can return older arrivals on subsequent recovery pages.
            // Preserve the newest proven slice rather than replacing it with those.
            if ($latestMs > (int)($prior['latest_recovered_ms'] ?? 0)) {
                $items = array_column($recovered,'item');
                $recoveryReport = ['at'=>$now,'total'=>count($items),
                    'important'=>count(array_filter($items,static fn(array $item):bool=>$item['important'])),
                    'replies'=>count(array_filter($items,static fn(array $item):bool=>$item['category']==='reply')),
                    'filtered'=>0,'triage_enabled'=>true,'latest_recovered_ms'=>$latestMs,'items'=>$items];
                $reviewJson = json_encode($recoveryReport,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
            }
        }
        if ($reviewJson !== null) {
            // Preserve a pre-upgrade latest review before the cursor replaces it.
            agent_connection_gmail_inbox_batch_save_v1($pdo,$live,(string)($live['last_review_json'] ?? ''),retainArrivals:true);
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
                $scan['offset']??$live['pending_offset'],$next,$status === 'checked' ? $now : $live['last_checked_at'],$status,
                $reviewJson,$owner]);
        if (!$historical && $review['total']>0) {
            if ($digest !== null) {
                agent_connection_gmail_inbox_batch_save_v1($pdo,$live,json_encode($digest,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));
            }
            $notice = agent_connection_gmail_inbox_notify_v1($pdo,$owner,$review,$live);
            agent_connection_gmail_inbox_batch_save_v1($pdo,$live,$reviewJson,$notice,retainArrivals:true);
            if ($digest !== null || $review['priority_count']>0 || (!agent_email_running_report_enabled_v1($live)
                && agent_connection_gmail_inbox_report_needs_alert_v1($review))) {
                require_once __DIR__.'/agent-connection-gmail-inbox-initial-v1.php';
                $scope = agent_connection_gmail_inbox_initial_scope_v1($pdo,$user);
                if ($scope['state'] === 'ready') {
                    $target = agent_connection_gmail_inbox_update_destination_v1($pdo,$scope);
                    if ($target) agent_connection_gmail_inbox_update_post_v1($pdo,$scope,$target['agent_id'],$target['conversation_id']);
                }
            }
        }
        if ($historical && $review['priority_count']>0) {
            $priorityItems = $review['items'];
            $priorityReview = ['at'=>$now,'total'=>count($priorityItems),'important'=>count(array_filter($priorityItems,
                static fn(array $item):bool=>!empty($item['important']))),
                'replies'=>count(array_filter($priorityItems,static fn(array $item):bool=>$item['category']==='reply')),
                'triage_enabled'=>true,'priority_count'=>count($priorityItems),'items'=>$priorityItems];
            $rawPriority = json_encode($priorityReview,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
            $notice = agent_connection_gmail_inbox_notify_v1($pdo,$owner,$priorityReview,$live);
            agent_connection_gmail_inbox_batch_save_v1($pdo,$live,$rawPriority,$notice);
            require_once __DIR__.'/agent-connection-gmail-inbox-initial-v1.php';
            $scope = agent_connection_gmail_inbox_initial_scope_v1($pdo,$user);
            if ($scope['state'] === 'ready') {
                $target = agent_connection_gmail_inbox_update_destination_v1($pdo,$scope);
                if ($target) agent_connection_gmail_inbox_update_post_v1($pdo,$scope,$target['agent_id'],$target['conversation_id']);
            }
        }
        if ($historical && $reviewJson !== null) {
            agent_connection_gmail_inbox_batch_save_v1($pdo,$live,$reviewJson,retainArrivals:true);
        }
        return $reviewed || $reviewJson !== null;
    });
}

/** Process one exact due checkpoint outside inbox state locks. Null means the claim changed. */
function agent_connection_gmail_inbox_run_due_v1(PDO $pdo, array $policy, ?callable $http=null): ?bool
{
    if ($pdo->inTransaction()) return null;
    $now = agent_commerce_time_pa2();
    $claim = bin2hex(random_bytes(16));
    $reserve = $pdo->prepare("UPDATE agent_connection_gmail_inbox_v1 SET claim_key=?,next_check_at=?
        WHERE owner_user_id=? AND connection_id=? AND authorization_key=? AND revision=? AND enabled=1
          AND next_check_at=? AND COALESCE(claim_key,'')=? AND next_check_at<=?");
    $reserve->execute([$claim,gmdate('Y-m-d H:i:s',time()+180),$policy['owner_user_id'],$policy['connection_id'],
        $policy['authorization_key'],$policy['revision'],$policy['next_check_at'],(string)$policy['claim_key'],$now]);
    if ($reserve->rowCount() !== 1) return null;

    $policy['rules'] = agent_connection_gmail_inbox_policy_rules_v1($policy);
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
                $scan = agent_connection_gmail_inbox_scan_v1($pdo,$policy,$claim,$access['access_token'],$http,$connection['account_email']);
                if (agent_connection_gmail_inbox_triage_enabled_v1($pdo,$policy)) {
                    require_once __DIR__.'/agent-email-triage-inbox-v1.php';
                    $scan = agent_email_triage_enrich_inbox_v1($pdo,$policy,$scan,$access['access_token'],$http,$claim);
                    if (($scan['status'] ?? null) === 'checked' && empty($scan['more']) && empty($scan['activity_deferred'])) {
                        require_once __DIR__.'/agent-connection-gmail-inbox-activity-v1.php';
                        $scan['activity'] = agent_connection_gmail_inbox_activity_scan_v1($pdo,$policy,$access['access_token'],
                            $connection['account_email'],
                            static fn(): bool => agent_connection_gmail_inbox_claim_current_v1($pdo,$policy,$claim),$http);
                    }
                }
            }
        }
    } catch (Throwable $error) {
        $scan = ['status'=>'retrying'];
        if (function_exists('agent_runtime_v125_trace')) {
            agent_runtime_v125_trace('worker.gmail_inbox.poll_failed',['error_class'=>get_class($error)]);
        }
    }
    try {
        return agent_connection_gmail_inbox_finish_v1($pdo,$policy,$claim,$scan);
    } catch (Throwable $error) {
        if (function_exists('agent_runtime_v125_trace')) {
            agent_runtime_v125_trace('worker.gmail_inbox.commit_failed',['error_class'=>get_class($error)]);
        }
        return false;
    }
}

/** Older opted-in accounts can start one catch-up when they have eligible saved classifications. */
function agent_connection_gmail_inbox_content_unseeded_v1(PDO $pdo,array $policy,?array $sync,DateTimeImmutable $now): bool
{
    if ($sync !== null || $policy['last_status'] === 'restart_required'
        || (!empty($policy['claim_key']) && (string)$policy['next_check_at'] > $now->format('Y-m-d H:i:s'))
        || !agent_connection_gmail_inbox_sync_ready_v1($pdo)
        || !agent_connection_gmail_inbox_triage_enabled_v1($pdo,$policy)
        || !agent_connection_gmail_inbox_receipts_ready_v1($pdo) || !agent_connection_gmail_inbox_batches_ready_v1($pdo)) return false;
    $bound = agent_email_matters_policy_scope_v1($policy);
    if ($bound === null) return false;
    $query = $pdo->prepare("SELECT 1 FROM agent_email_triage_v1 t
        WHERE t.owner_user_id=? AND t.connection_id=? AND t.grant_key=?
          AND t.received_ms>=? AND t.received_ms<=? AND t.thread_id_enc IS NOT NULL
          AND t.attention_key IN ('act_soon','review','unknown')
          AND NOT EXISTS (SELECT 1 FROM agent_email_running_report_v1 r
            WHERE r.owner_user_id=t.owner_user_id AND r.connection_id=t.connection_id AND r.grant_key=t.grant_key)
        LIMIT 1");
    $nowMs = $now->getTimestamp()*1000+999;
    $query->execute([...$bound,$nowMs-VP3_GMAIL_INBOX_SYNC_WINDOW_MS_V1,$nowMs]);
    return $query->fetchColumn() !== false;
}

/** Advance one recent-mail page and one thread through the existing owner-scoped worker leases. */
function agent_connection_gmail_inbox_analyze_recent_v1(PDO $pdo,array $user,int $agentId,int $conversationId,
    ?callable $http=null,?callable $model=null,?string $unseededRevision=null): void
{
    if ($pdo->inTransaction()) throw new LogicException('Inbox analysis cannot start inside a transaction.');
    require_once __DIR__.'/agent-connection-gmail-inbox-chat-delivery-v1.php';
    $scheduled = agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$user,$agentId,$conversationId,$unseededRevision): ?array {
        $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$user,true);
        if (($scope['state'] ?? '') !== 'ready' || empty($scope['provider_ready']) || $scope['generation'] === null
            || !agent_email_running_report_enabled_v1($scope['policy'])
            || $conversationId < 1 || !agent_connection_gmail_inbox_chat_target_v1($pdo,$scope,$agentId,$conversationId,true)) {
            throw new RuntimeException('Open a private Agent Chat with Gmail content analysis enabled.',403);
        }
        $policy = $scope['policy'];
        if (!agent_email_running_report_ready_v1($pdo) || !agent_connection_gmail_inbox_sync_ready_v1($pdo)
            || !agent_connection_gmail_inbox_triage_enabled_v1($pdo,$policy)
            || !agent_connection_gmail_inbox_receipts_ready_v1($pdo) || !agent_connection_gmail_inbox_batches_ready_v1($pdo)) {
            throw new RuntimeException('Gmail content analysis storage is unavailable.',503);
        }
        $now = agent_commerce_time_pa2();
        $sync = agent_connection_gmail_inbox_sync_row_v1($pdo,$policy);
        if ($unseededRevision !== null && (!hash_equals($policy['revision'],$unseededRevision)
            || !agent_connection_gmail_inbox_content_unseeded_v1($pdo,$policy,$sync,new DateTimeImmutable($now,new DateTimeZone('UTC'))))) {
            return null;
        }
        if (($sync['status'] ?? '') === 'pending') {
            return [$policy,is_string($policy['next_check_at']) && $policy['next_check_at'] <= $now];
        }
        if ($policy['last_status'] === 'restart_required') {
            throw new RuntimeException('Gmail Inbox Review needs to be restarted in Connections.',409);
        }
        if (!empty($policy['claim_key']) && (string)$policy['next_check_at'] > $now) return [$policy,false];
        if ($sync !== null) {
            $bound = agent_email_matters_policy_scope_v1($policy);
            $pending = $pdo->prepare('SELECT 1 FROM agent_email_running_report_v1
                WHERE owner_user_id=? AND connection_id=? AND grant_key=? AND due_at IS NOT NULL LIMIT 1');
            $pending->execute($bound);
            if ($pending->fetchColumn() !== false) return [$policy,false];
        }
        $sync = agent_connection_gmail_inbox_sync_start_v1($policy,'catchup',(string)$policy['history_id'],
            (int)floor(microtime(true)*1000)+1);
        agent_connection_gmail_inbox_sync_save_v1($pdo,$policy,$sync);
        $pdo->prepare("UPDATE agent_connection_gmail_inbox_v1 SET next_check_at=?,claim_key=NULL,last_status='waiting'
            WHERE owner_user_id=?")->execute([$now,$scope['owner']]);
        $policy['next_check_at'] = $now;
        $policy['claim_key'] = null;
        return [$policy,true];
    });
    if ($scheduled === null) return;
    [$policy,$runScan] = $scheduled;
    if ($runScan) agent_connection_gmail_inbox_run_due_v1($pdo,$policy,$http);
    agent_email_running_report_sweep_v1($pdo,$http,$model,(int)$policy['owner_user_id'],$policy['revision']);
}

/** Find due reviews on the Cloud worker. */
function agent_connection_gmail_inbox_sweep_v1(PDO $pdo, int $limit=5, ?callable $http=null): array
{
    $counts = ['processed'=>0,'reviewed'=>0];
    if ($limit<1 || $pdo->inTransaction() || !agent_connection_gmail_inbox_ready_v1($pdo)
        || !agent_connection_gmail_inbox_receipts_ready_v1($pdo)
        || !agent_connection_gmail_inbox_batches_ready_v1($pdo)) return $counts;
    $due = $pdo->prepare('SELECT * FROM agent_connection_gmail_inbox_v1 WHERE enabled=1 AND next_check_at<=?
        ORDER BY next_check_at,owner_user_id LIMIT '.min($limit,5));
    $due->execute([agent_commerce_time_pa2()]);
    foreach ($due->fetchAll(PDO::FETCH_ASSOC) as $policy) {
        $reviewed = agent_connection_gmail_inbox_run_due_v1($pdo,$policy,$http);
        if ($reviewed === null) continue;
        $counts['processed']++;
        if ($reviewed) $counts['reviewed']++;
    }
    return $counts;
}
