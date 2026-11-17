<?php
declare(strict_types=1);

require_once __DIR__.'/agent-commerce-recovery-pa2.php';

const VP3_GMAIL_INBOX_SYNC_WINDOW_MS_V1 = 604800000;
const VP3_GMAIL_INBOX_SYNC_PAGE_SIZE_V1 = 10;
const VP3_GMAIL_INBOX_SYNC_MAX_MESSAGES_V1 = 200;
const VP3_GMAIL_INBOX_SYNC_MAX_PAGES_V1 = 32;

function agent_connection_gmail_inbox_sync_install_v1(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new LogicException('Gmail sync storage cannot be installed inside a transaction.');
    $sqlite = agent_commerce_sqlite_pa2($pdo);
    $owner = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_connection_gmail_inbox_sync_v1 (
        owner_user_id $owner PRIMARY KEY,
        connection_id BIGINT UNSIGNED NOT NULL,
        grant_key CHAR(64) NOT NULL,
        mode VARCHAR(12) NOT NULL,
        status VARCHAR(12) NOT NULL,
        baseline VARCHAR(20) NOT NULL,
        since_ms BIGINT UNSIGNED NOT NULL,
        before_ms BIGINT UNSIGNED NOT NULL,
        page_token TEXT NOT NULL,
        pages INT UNSIGNED NOT NULL DEFAULT 0,
        checked INT UNSIGNED NOT NULL DEFAULT 0,
        reviewed INT UNSIGNED NOT NULL DEFAULT 0,
        max_checked INT UNSIGNED NOT NULL DEFAULT 200,
        max_pages INT UNSIGNED NOT NULL DEFAULT 32,
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (connection_id) REFERENCES agent_connections_v1(id) ON DELETE CASCADE
    )".($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'));
}

function agent_connection_gmail_inbox_sync_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT grant_key,mode,status,baseline,since_ms,before_ms,page_token,pages,checked,reviewed,max_checked,max_pages
            FROM agent_connection_gmail_inbox_sync_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function agent_connection_gmail_inbox_triage_enabled_v1(PDO $pdo,array $policy): bool
{
    if (($policy['rules']['triage_enabled'] ?? false) !== true) return false;
    require_once __DIR__.'/agent-email-triage-v1.php';
    return agent_email_triage_ready_v1($pdo);
}

function agent_connection_gmail_inbox_sync_scope_v1(array $policy): array
{
    return [(int)$policy['owner_user_id'],(int)$policy['connection_id'],
        hash_hmac('sha256','vp3-gmail-inbox-sync-v1',(string)$policy['authorization_key'])];
}

function agent_connection_gmail_inbox_sync_clear_v1(PDO $pdo,int $owner): void
{
    if ($owner > 0 && agent_connection_gmail_inbox_sync_ready_v1($pdo)) {
        $pdo->prepare('DELETE FROM agent_connection_gmail_inbox_sync_v1 WHERE owner_user_id=?')->execute([$owner]);
    }
}

function agent_connection_gmail_inbox_sync_row_v1(PDO $pdo,array $policy): ?array
{
    if (!agent_connection_gmail_inbox_triage_enabled_v1($pdo,$policy)
        || !agent_connection_gmail_inbox_sync_ready_v1($pdo)) return null;
    $select = $pdo->prepare('SELECT * FROM agent_connection_gmail_inbox_sync_v1
        WHERE owner_user_id=? AND connection_id=? AND grant_key=?');
    $select->execute(agent_connection_gmail_inbox_sync_scope_v1($policy));
    return $select->fetch(PDO::FETCH_ASSOC) ?: null;
}

function agent_connection_gmail_inbox_sync_view_v1(PDO $pdo,array $policy): ?array
{
    $row = agent_connection_gmail_inbox_sync_row_v1($pdo,$policy);
    if ($row === null) return null;
    return [
        'mode'=>$row['mode'],'status'=>$row['status'],'checked'=>(int)$row['checked'],'reviewed'=>(int)$row['reviewed'],
        'can_continue'=>$row['status'] === 'truncated' && $row['page_token'] !== '',
        'since'=>gmdate('Y-m-d\TH:i:s\Z',intdiv((int)$row['since_ms'],1000)),
        'before'=>gmdate('Y-m-d\TH:i:s\Z',intdiv((int)$row['before_ms'],1000)),
    ];
}

function agent_connection_gmail_inbox_sync_start_v1(array $policy,string $mode,string $baseline,int $beforeMs): array
{
    if (!in_array($mode,['catchup','recovery'],true)
        || agent_connection_gmail_inbox_history_id_v1($baseline) === ''
        || $beforeMs <= VP3_GMAIL_INBOX_SYNC_WINDOW_MS_V1) {
        throw new InvalidArgumentException('Invalid Gmail catch-up checkpoint.');
    }
    $since = $beforeMs - VP3_GMAIL_INBOX_SYNC_WINDOW_MS_V1;
    if ($mode === 'recovery') $since = max($since,(int)$policy['enabled_at_ms']+1);
    return ['mode'=>$mode,'status'=>'pending','baseline'=>$baseline,'since_ms'=>min($since,$beforeMs),
        'before_ms'=>$beforeMs,'page_token'=>'','pages'=>0,'checked'=>0,'reviewed'=>0,
        'max_checked'=>VP3_GMAIL_INBOX_SYNC_MAX_MESSAGES_V1,'max_pages'=>VP3_GMAIL_INBOX_SYNC_MAX_PAGES_V1];
}

/** A policy claim and its sync page are published in the same transaction. */
function agent_connection_gmail_inbox_sync_save_v1(PDO $pdo,array $policy,array $sync): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Gmail sync requires the Inbox Review transaction.');
    [$owner,$connection,$grant] = agent_connection_gmail_inbox_sync_scope_v1($policy);
    $pdo->prepare('DELETE FROM agent_connection_gmail_inbox_sync_v1 WHERE owner_user_id=?')->execute([$owner]);
    $pdo->prepare('INSERT INTO agent_connection_gmail_inbox_sync_v1
        (owner_user_id,connection_id,grant_key,mode,status,baseline,since_ms,before_ms,page_token,pages,checked,reviewed,max_checked,max_pages)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$owner,$connection,$grant,$sync['mode'],$sync['status'],$sync['baseline'],
            $sync['since_ms'],$sync['before_ms'],$sync['page_token'],$sync['pages'],$sync['checked'],$sync['reviewed'],
            $sync['max_checked'],$sync['max_pages']]);
}

/** Extend a clipped, authorized interval without replaying its initial pages. */
function agent_connection_gmail_inbox_sync_continue_v1(PDO $pdo,array $policy): void
{
    $sync = agent_connection_gmail_inbox_sync_row_v1($pdo,$policy);
    if (($sync['status'] ?? null) !== 'truncated' || $sync['page_token'] === '') {
        throw new AgentConnectionExceptionV1('This Gmail catch-up can no longer continue.',409);
    }
    $sync['status'] = 'pending';
    $sync['max_checked'] = (int)$sync['checked']+VP3_GMAIL_INBOX_SYNC_MAX_MESSAGES_V1;
    $sync['max_pages'] = (int)$sync['pages']+VP3_GMAIL_INBOX_SYNC_MAX_PAGES_V1;
    agent_connection_gmail_inbox_sync_save_v1($pdo,$policy,$sync);
}

/** Read one frozen Inbox list page. Gmail history resumes from the pre-list profile cursor. */
function agent_connection_gmail_inbox_sync_page_v1(
    array $policy,array $sync,string $access,callable $claimCurrent,callable $alreadyClassified,?callable $http=null
): array {
    if (!$claimCurrent()) return ['status'=>'retrying'];
    $since = (int)$sync['since_ms'];
    $before = (int)$sync['before_ms'];
    $page = (string)$sync['page_token'];
    $limit = min(VP3_GMAIL_INBOX_SYNC_PAGE_SIZE_V1,(int)$sync['max_checked']-(int)$sync['checked']);
    if ($limit < 1 || (int)$sync['pages'] >= (int)$sync['max_pages']
        || strlen($page)>4096 || preg_match('/[\x00-\x1f\x7f]/',$page) || $since>$before) {
        throw new InvalidArgumentException('Invalid Gmail catch-up page.');
    }
    $query = [
        'q'=>'after:'.(intdiv($since,1000)-1).' before:'.(intdiv($before,1000)+1),
        'labelIds'=>'INBOX','includeSpamTrash'=>'false','maxResults'=>$limit,'fields'=>'messages(id),nextPageToken',
    ];
    if ($page !== '') $query['pageToken'] = $page;
    try {
        $result = agent_connection_gmail_api_v1($access,'/messages?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),null,$http);
    } catch (AgentConnectionGmailExceptionV1 $error) {
        if ($error->getCode() !== 400 || $page === '') throw $error;
        // A stale list page token makes this bounded scan incomplete. Keep the
        // prior verified live cursor and report the uncovered interval.
        $sync['status'] = 'truncated';
        $sync['page_token'] = '';
        return agent_connection_gmail_inbox_sync_result_v1($sync,[]);
    }
    $records = $result['messages'] ?? [];
    $next = $result['nextPageToken'] ?? '';
    if (!is_array($records) || !array_is_list($records) || count($records)>$limit
        || !is_string($next) || strlen($next)>4096 || preg_match('/[\x00-\x1f\x7f]/',$next)) {
        throw new AgentConnectionGmailExceptionV1('Gmail returned an invalid catch-up page.',502);
    }
    $messages = [];
    foreach ($records as $record) {
        $id = is_array($record) ? agent_connection_gmail_id_v1($record['id']??null) : '';
        if ($id === '') throw new AgentConnectionGmailExceptionV1('Gmail returned an invalid catch-up message reference.',502);
        $key = agent_connection_gmail_inbox_key_v1($policy,'message:'.$id);
        if (array_key_exists($key,$messages) || $alreadyClassified($id)) continue;
        if (!$claimCurrent()) return ['status'=>'retrying'];
        $metadata = agent_connection_gmail_inbox_metadata_v1($access,$id,$since-1,
            ['From','Cc','Subject','In-Reply-To'],$http,$before,true);
        // Do not create a receipt for a message outside the frozen interval. It
        // may be a real arrival after the profile cursor.
        if ($metadata !== null) $messages[$key] = agent_connection_gmail_inbox_review_message_v1($metadata);
    }
    if (!$claimCurrent()) return ['status'=>'retrying'];
    $sync['pages'] = (int)$sync['pages']+1;
    $sync['checked'] = (int)$sync['checked']+count($records);
    if ($next !== '' && $next === $page) {
        $sync['status'] = 'truncated';
        $sync['page_token'] = '';
        return agent_connection_gmail_inbox_sync_result_v1($sync,$messages);
    }
    $sync['page_token'] = $next;
    $sync['status'] = match (true) {
        $next === '' => 'complete',
        $sync['pages'] >= (int)$sync['max_pages'] || $sync['checked'] >= (int)$sync['max_checked'] => 'truncated',
        default => 'pending',
    };
    return agent_connection_gmail_inbox_sync_result_v1($sync,$messages);
}

function agent_connection_gmail_inbox_sync_result_v1(array $sync,array $messages): array
{
    $blocked = $sync['mode'] === 'recovery' && $sync['status'] === 'truncated';
    $result = ['status'=>$blocked ? 'restart_required' : 'waiting','historical'=>true,'sync'=>$sync,
        'messages'=>$messages,'more'=>!$blocked];
    if ($sync['mode'] === 'recovery' && $sync['status'] === 'complete') {
        $result += ['cursor'=>$sync['baseline'],'page'=>'','pending'=>'','fingerprint'=>'','offset'=>0];
    }
    return $result;
}
