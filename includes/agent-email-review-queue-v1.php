<?php
declare(strict_types=1);

require_once __DIR__.'/agent-email-composer-v1.php';

/** The encrypted proposal stores only bounded source metadata, never presentation HTML. */
function agent_email_review_queue_source_metadata_v1(array $source): array
{
    if (array_keys($source) !== ['reason','priority','deadline'] || !is_string($source['reason'])
        || trim($source['reason']) === '' || !mb_check_encoding($source['reason'],'UTF-8') || mb_strlen($source['reason']) > 1200
        || preg_match('/[\x00-\x08\x0B-\x1F\x7F]/',$source['reason'])
        || !in_array($source['priority'],['high','normal','low'],true)) {
        throw new DomainException('The saved email proposal source is unavailable.',503);
    }
    $deadline = $source['deadline'];
    if ($deadline !== null) {
        if (!is_string($deadline) || !preg_match('/\A(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}):(\d{2})(?:Z|\+00:00))?\z/D',$deadline,$date)
            || !checkdate((int)$date[2],(int)$date[3],(int)$date[1])
            || (isset($date[4]) && ((int)$date[4] > 23 || (int)$date[5] > 59 || (int)$date[6] > 59))) {
            throw new DomainException('The saved email proposal deadline is unavailable.',503);
        }
    }
    return $source;
}

/** Read permission is independent of permission to send or of a proposal's retention deadline. */
function agent_email_review_queue_source_allowed_v1(array $draft, array $payload): bool
{
    if ((!isset($payload['reply']) && !isset($payload['outbound_followup'])) || isset($payload['auto_revision']) || $draft['status'] === 'discarded') return false;
    try {
        $connection = agent_connections_require_grant_v1(db(),(int)$draft['owner_user_id'],(int)$draft['connection_id'],'read',$draft['authorization_key']);
    } catch (AgentConnectionExceptionV1 $error) {
        return false;
    }
    return $connection['account_email'] === $draft['account_email'] && agent_connection_gmail_ready_v1();
}

function agent_email_review_queue_source_public_v1(array $draft, array $payload, bool $readable): array
{
    $reply = isset($payload['reply']) || isset($payload['outbound_followup']);
    if ($reply && !$readable) {
        return ['kind'=>'reply','reason'=>null,'priority'=>null,'deadline'=>null];
    }
    if (in_array($payload['composer']['version'] ?? null,[2,3],true)) {
        return ['kind'=>isset($payload['outbound_followup']) ? 'outbound_followup' : 'organic_reply'] + $payload['composer']['source'];
    }
    return ['kind'=>$reply ? 'reply' : 'new_email',
        'reason'=>$reply ? 'Drafted from the original email.' : 'Saved in this Chat.','priority'=>null,'deadline'=>null];
}

/** Cursor authority is separate from send approval and expires after a bounded browsing session. */
function agent_email_review_queue_cursor_v1(string $key, array $scope, array $snapshot, array $after, int $expires): string
{
    $body = json_encode(['version'=>1,'scope'=>$scope,'snapshot'=>$snapshot,'after'=>$after,'expires'=>$expires],JSON_THROW_ON_ERROR);
    $encoded = rtrim(strtr(base64_encode($body),'+/','-_'),'=');
    return $encoded.'.'.hash_hmac('sha256','vp3-email-review-queue-v1:'.$encoded,$key);
}

function agent_email_review_queue_cursor_open_v1(string $key, array $scope, string $cursor, int $now): array
{
    if (strlen($cursor) > 2048 || !preg_match('/\A([A-Za-z0-9_-]+)\.([a-f0-9]{64})\z/D',$cursor,$parts)
        || !hash_equals(hash_hmac('sha256','vp3-email-review-queue-v1:'.$parts[1],$key),$parts[2])) {
        throw new DomainException('Refresh the saved email list to continue.',409);
    }
    $body = base64_decode(strtr($parts[1],'-_','+/'),true);
    $value = $body === false ? null : json_decode($body,true);
    if (!is_array($value) || array_keys($value) !== ['version','scope','snapshot','after','expires']
        || $value['version'] !== 1 || $value['scope'] !== $scope || !is_int($value['expires'])
        || $value['expires'] <= $now || $value['expires'] > $now + 1800) {
        throw new DomainException('Refresh the saved email list to continue.',409);
    }
    foreach (['snapshot','after'] as $field) {
        $tuple = $value[$field];
        if (!is_array($tuple) || !array_is_list($tuple) || count($tuple) !== 2 || !is_int($tuple[0]) || $tuple[0] < 1
            || !is_string($tuple[1]) || !preg_match('/\A[a-f0-9]{32}\z/D',$tuple[1])) {
            throw new DomainException('Refresh the saved email list to continue.',409);
        }
    }
    if ($value['after'][0] > $value['snapshot'][0]
        || ($value['after'][0] === $value['snapshot'][0] && strcmp($value['after'][1],$value['snapshot'][1]) > 0)) {
        throw new DomainException('Refresh the saved email list to continue.',409);
    }
    return $value;
}

function agent_email_review_queue_created_v1(array $draft, ?array $payload): ?int
{
    if (in_array($payload['composer']['version'] ?? null,[2,3],true)) return $payload['composer']['created_at'];
    $raw = $draft['request_created_at'] ?? null;
    if (is_string($raw)) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$raw,new DateTimeZone('UTC'));
        if ($date && $date->format('Y-m-d H:i:s') === $raw && $date->getTimestamp() > 0) return $date->getTimestamp();
    }
    return isset($payload['composer']['saved_at']) ? $payload['composer']['saved_at'] : null;
}

/** A bad payload remains a visible, non-actionable row rather than disappearing from the count. */
function agent_email_review_queue_row_v1(array $draft, string $key, int $now): array
{
    $payload = null;
    $unavailable = false;
    try {
        $payload = agent_connection_email_chat_payload_v1($draft);
        // Sent Auto Send receipts are valid in their existing review surface,
        // but never expose automatic draft content in the manual saved queue.
        if (isset($payload['auto_revision'])) throw new DomainException('This is an automatic email draft.',404);
        $public = agent_connection_email_chat_public_draft_v1($draft,$key,$now);
        $row = array_intersect_key($public,array_flip(['id','status','from','to','subject','is_reply','is_followup','saved_at','persistent',
            'can_edit','can_send','can_open_source','source','edit_revision','connection_changed']));
        $row['attachment_count'] = count($public['attachments']);
        $blocked = !empty($public['connection_changed']) ? 'connection_changed' : match ($public['status']) {
            'expired','answered','stale','source_check','failed' => $public['status'],
            'drafting','write_queued','writing' => 'writing',
            'sending' => 'sending',
            default => null,
        };
    } catch (DomainException|AgentConnectionGmailExceptionV1 $error) {
        $unavailable = true;
        $blocked = isset($payload['auto_revision']) ? 'automatic' : 'unavailable';
        $row = ['id'=>$draft['id'],'status'=>$blocked === 'automatic' ? 'automatic' : 'unavailable','from'=>'','to'=>'','subject'=>'',
            'is_reply'=>false,'saved_at'=>null,'persistent'=>false,'can_edit'=>false,'can_send'=>false,'can_open_source'=>false,
            'source'=>['kind'=>'unavailable','reason'=>null,'priority'=>null,'deadline'=>null],
            'edit_revision'=>agent_connection_email_chat_review_hash_v1($draft,false),'attachment_count'=>0];
    }
    if (empty($draft['request_message_id'])) {
        $unavailable = true;
        $blocked = 'unavailable';
        $row = array_replace($row,['from'=>'','to'=>'','subject'=>'','can_edit'=>false,'can_send'=>false,'can_open_source'=>false,
            'source'=>['kind'=>'unavailable','reason'=>null,'priority'=>null,'deadline'=>null],'attachment_count'=>0]);
    }
    $created = agent_email_review_queue_created_v1($draft,$unavailable ? null : $payload);
    $updated = !$unavailable && isset($payload['composer']['saved_at']) ? $payload['composer']['saved_at'] : $created;
    return $row + ['stored_status'=>$draft['status'],'blocked_reason'=>$blocked,'available'=>!$unavailable,
        'conversation_id'=>(int)$draft['conversation_id'],'conversation_title'=>mb_substr((string)$draft['conversation_title'],0,190),
        'created_at'=>$created === null ? null : gmdate(DATE_ATOM,$created),
        'updated_at'=>$updated === null ? null : gmdate(DATE_ATOM,$updated),'age_seconds'=>$created === null ? null : max(0,$now-$created),
        'target'=>['agent_id'=>(int)$draft['agent_id'],'conversation_id'=>(int)$draft['conversation_id'],'draft_id'=>$draft['id']]];
}

/**
 * Counts describe persisted lifecycle states, including blocked/expired proposals.
 * They do not claim that every pending row is ready to send. A source that was
 * answered elsewhere may project status=answered while stored_status=prepared.
 * Pagination is bounded and never advances past an unreturned row.
 */
function agent_email_review_queue_list_v1(PDO $pdo, array $user, array $principal, int $conversation, string $key, array $options=[]): array
{
    $user = agent_connection_email_chat_authority_v1($pdo,$user,$principal,$conversation);
    if (!table_exists('agent_connection_email_drafts_v1')) throw new DomainException('Run Database Upgrade to save Gmail drafts.',503);
    $scope = $options['scope'] ?? 'chat';
    $filter = $options['filter'] ?? 'pending';
    $limit = $options['limit'] ?? 20;
    $cursor = $options['cursor'] ?? null;
    if (array_diff(array_keys($options),['scope','filter','limit','cursor']) || !in_array($scope,['chat','agent'],true)
        || !in_array($filter,['pending','all'],true) || !is_int($limit) || $limit < 1 || $limit > 50
        || ($cursor !== null && (!is_string($cursor) || $cursor === ''))) {
        throw new DomainException('Choose a valid saved email list.',422);
    }
    $owner = (int)$user['id'];
    $agent = ($principal['kind'] ?? 'system') === 'user_agent' ? (int)($principal['agent_id'] ?? 0) : 0;
    $now = time();
    $cursorScope = [$owner,$agent,$conversation,$scope,$filter];
    $paging = $cursor === null ? null : agent_email_review_queue_cursor_open_v1($key,$cursorScope,$cursor,$now);
    $join = ' FROM agent_connection_email_drafts_v1 d JOIN chat_conversations c ON c.id=d.conversation_id AND c.user_id=d.owner_user_id';
    $where = ' WHERE d.owner_user_id=? AND d.agent_id=? AND '.($agent > 0 ? 'c.user_agent_id=?' : 'c.user_agent_id IS NULL');
    $params = [$owner,$agent];
    if ($agent > 0) $params[] = $agent;
    if ($scope === 'chat') { $where .= ' AND d.conversation_id=?'; $params[] = $conversation; }
    if ($paging === null) {
        $top = $pdo->prepare('SELECT d.user_message_id,d.id'.$join.$where.' ORDER BY d.user_message_id DESC,d.id DESC LIMIT 1');
        $top->execute($params);
        $first = $top->fetch(PDO::FETCH_ASSOC);
        $snapshot = $first ? [(int)$first['user_message_id'],$first['id']] : null;
    } else $snapshot = $paging['snapshot'];
    if ($snapshot !== null) {
        $where .= ' AND (d.user_message_id<? OR (d.user_message_id=? AND d.id<=?))';
        $params = [...$params,$snapshot[0],$snapshot[0],$snapshot[1]];
    } else $where .= ' AND 1=0';
    $count = $pdo->prepare("SELECT COUNT(*) total,
        COALESCE(SUM(CASE WHEN d.status='sent' THEN 1 ELSE 0 END),0) sent,
        COALESCE(SUM(CASE WHEN d.status='discarded' THEN 1 ELSE 0 END),0) discarded,
        COALESCE(SUM(CASE WHEN d.status='answered' THEN 1 ELSE 0 END),0) answered".$join.$where);
    $count->execute($params);
    $totals = array_map('intval',$count->fetch(PDO::FETCH_ASSOC));
    $counts = ['pending'=>$totals['total']-$totals['sent']-$totals['discarded']-$totals['answered']] + $totals;
    if ($filter === 'pending') $where .= " AND d.status NOT IN ('sent','discarded','answered')";
    if ($paging !== null) {
        $where .= ' AND (d.user_message_id<? OR (d.user_message_id=? AND d.id<?))';
        $params = [...$params,$paging['after'][0],$paging['after'][0],$paging['after'][1]];
    }
    $query = $pdo->prepare('SELECT d.*,c.title AS conversation_title,m.id AS request_message_id,m.created_at AS request_created_at'.$join.
        " LEFT JOIN chat_messages m ON m.id=d.user_message_id AND m.conversation_id=d.conversation_id AND m.user_id=d.owner_user_id AND m.role='user'".
        $where.' ORDER BY d.user_message_id DESC,d.id DESC LIMIT '.($limit+1));
    $query->execute($params);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > $limit;
    if ($more) array_pop($rows);
    $drafts = [];
    foreach ($rows as $draft) $drafts[] = agent_email_review_queue_row_v1($draft,$key,$now);
    // Recheck each distinct original Chat before returning private queue metadata.
    foreach (array_unique([$conversation,...array_map(static fn(array $row): int => (int)$row['conversation_id'],$rows)]) as $chat) {
        agent_connection_email_chat_authority_v1($pdo,$user,$principal,$chat);
    }
    $summary = agent_connections_summary_v1($owner);
    $connection = $summary['connection'] ?? null;
    $ready = !empty($summary['provider_ready']) && ($connection['status'] ?? '') === 'connected' && !empty($connection['permissions']['send']);
    // A later Chat/connection check must not release content projected under an
    // earlier read grant. Fail the page coherently instead of leaking old fields.
    $checked = [];
    foreach ($rows as $index=>$draft) {
        if (empty($drafts[$index]['is_reply']) || !empty($drafts[$index]['connection_changed']) || empty($drafts[$index]['available'])) continue;
        $grantKey = $draft['connection_id'].':'.$draft['authorization_key'].':'.$draft['account_email'];
        if (isset($checked[$grantKey])) continue;
        try {
            $live = agent_connections_require_grant_v1($pdo,$owner,(int)$draft['connection_id'],'read',$draft['authorization_key']);
        } catch (AgentConnectionExceptionV1 $error) {
            throw new DomainException('Gmail access changed. Refresh the saved email list.',409,$error);
        }
        if ($live['account_email'] !== $draft['account_email'] || !agent_connection_gmail_ready_v1()) {
            throw new DomainException('Gmail access changed. Refresh the saved email list.',409);
        }
        $checked[$grantKey] = true;
    }
    $last = $rows ? $rows[array_key_last($rows)] : null;
    $next = $more && $last !== null ? agent_email_review_queue_cursor_v1($key,$cursorScope,$snapshot,
        [(int)$last['user_message_id'],$last['id']],$paging['expires'] ?? $now+1800) : null;
    return ['drafts'=>$drafts,'from'=>$ready ? $connection['account_email'] : '','can_send'=>$ready,
        'scope'=>$scope,'filter'=>$filter,'limit'=>$limit,'next_cursor'=>$next,'has_more'=>$more,'counts'=>$counts];
}

/** A source read keeps the exact owner, Chat, read grant and saved revision across provider I/O. */
function agent_email_review_queue_source_current_v1(PDO $pdo, array $user, array $principal, array $draft, string $revision): void
{
    $owner = (int)$draft['owner_user_id'];
    $conversation = (int)$draft['conversation_id'];
    agent_connection_email_chat_authority_v1($pdo,$user,$principal,$conversation);
    try {
        $connection = agent_connections_require_grant_v1($pdo,$owner,(int)$draft['connection_id'],'read',$draft['authorization_key']);
    } catch (AgentConnectionExceptionV1 $error) {
        throw new DomainException('Gmail access changed. Reopen the source.',409,$error);
    }
    $current = agent_connection_email_chat_draft_v1($pdo,$owner,(int)$draft['agent_id'],$conversation,$draft['id']);
    if ($connection['account_email'] !== $draft['account_email'] || !agent_connection_gmail_ready_v1()
        || $current['status'] !== $draft['status'] || !hash_equals(agent_connection_email_chat_review_hash_v1($current,false),$revision)) {
        throw new DomainException('This email draft or its Gmail access changed. Reopen the source.',409);
    }
    $request = $pdo->prepare("SELECT id FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");
    $request->execute([(int)$draft['user_message_id'],$conversation,$owner]);
    if (!$request->fetchColumn()) throw new DomainException('The original draft request is unavailable.',404);
}

/** Read one immutable original email without refreshing a thread, changing a draft, or approving a send. */
function agent_email_review_queue_source_v1(PDO $pdo, array $user, array $principal, int $conversation, array $input,
    string $key, ?callable $http=null): array
{
    $user = agent_connection_email_chat_authority_v1($pdo,$user,$principal,$conversation);
    $id = $input['draft_id'] ?? null;
    $revision = $input['edit_revision'] ?? null;
    if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/D',$id)
        || !is_string($revision) || !preg_match('/\A[a-f0-9]{64}\z/D',$revision)) {
        throw new DomainException('Choose the saved email revision to read its source.',422);
    }
    $owner = (int)$user['id'];
    $agent = ($principal['kind'] ?? 'system') === 'user_agent' ? (int)($principal['agent_id'] ?? 0) : 0;
    $draft = agent_connection_email_chat_draft_v1($pdo,$owner,$agent,$conversation,$id);
    if (!hash_equals(agent_connection_email_chat_review_hash_v1($draft,false),$revision)) {
        throw new DomainException('This email draft changed. Open the latest saved version.',409);
    }
    $payload = agent_connection_email_chat_payload_v1($draft);
    if ((!isset($payload['reply']) && !isset($payload['outbound_followup'])) || isset($payload['auto_revision']) || $draft['status'] === 'discarded') {
        throw new DomainException('This saved draft has no original email to read.',404);
    }
    $request = $pdo->prepare("SELECT id FROM chat_messages WHERE id=? AND conversation_id=? AND user_id=? AND role='user'");
    $request->execute([(int)$draft['user_message_id'],$conversation,$owner]);
    if (!$request->fetchColumn()) throw new DomainException('The original draft request is unavailable.',404);
    try {
        $access = agent_connections_access_v1($owner,(int)$draft['connection_id'],'read',$http,$draft['authorization_key']);
    } catch (AgentConnectionExceptionV1 $error) {
        if (!in_array($error->getCode(),[401,403,404,409],true)) throw $error;
        throw new DomainException('Gmail access changed. Reopen the source.',409,$error);
    }
    if ($access['account_email'] !== $draft['account_email'] || !agent_connection_gmail_ready_v1()) {
        throw new DomainException('The original Gmail account changed.',409);
    }
    // Token refresh can itself take time. Recheck before the exact message GET,
    // then again before any fetched text or presentation markup is returned.
    agent_email_review_queue_source_current_v1($pdo,$user,$principal,$draft,$revision);
    $sourceKind=isset($payload['outbound_followup']) ? 'outbound_followup' : 'reply';
    $message = agent_connection_gmail_get_v1($access['access_token'],$payload[$sourceKind]['message_id'],true,$http,true);
    $original = $sourceKind==='outbound_followup'
        ? agent_email_followthrough_original_v1($message,$draft['account_email'],$draft['recipient'],$payload[$sourceKind])
        : agent_connection_gmail_reply_from_message_v1($message,$draft['account_email']);
    if ($original[$sourceKind] !== $payload[$sourceKind] || $original['to'] !== $draft['recipient'] || $original['subject'] !== $payload['subject']) {
        throw new DomainException('The original email no longer matches this saved draft.',409);
    }
    $display = agent_connection_gmail_read_message_v1($message,true);
    agent_email_review_queue_source_current_v1($pdo,$user,$principal,$draft,$revision);
    return ['draft_id'=>$id,'edit_revision'=>$revision,'conversation_id'=>$conversation,
        'message'=>['subject'=>$display['subject'],'from'=>$display['from'],'body'=>mb_substr($display['body'],0,8000)]
            + (isset($display['display']) ? ['display'=>$display['display']] : [])];
}
