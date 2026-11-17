<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connections-v1.php';
require_once __DIR__.'/agent-commerce-recovery-pa2.php';
require_once __DIR__.'/agent-connection-gmail-inbox-batches-v1.php';
require_once __DIR__.'/agent-email-running-report-v1.php';
require_once __DIR__.'/agent-connection-gmail-inbox-sync-v1.php';

function agent_connection_gmail_inbox_install_v1(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new LogicException('Inbox Review storage cannot be installed inside a transaction.');
    $sqlite = agent_commerce_sqlite_pa2($pdo);
    $owner = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_connection_gmail_inbox_v1 (
        owner_user_id $owner PRIMARY KEY,
        connection_id BIGINT UNSIGNED NOT NULL,
        authorization_key CHAR(64) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        rules_json TEXT NOT NULL,
        revision CHAR(32) NOT NULL,
        history_id VARCHAR(20) NOT NULL DEFAULT '',
        pending_event_id VARCHAR(20) NOT NULL DEFAULT '',
        pending_offset INT UNSIGNED NOT NULL DEFAULT 0,
        pending_fingerprint CHAR(64) NOT NULL DEFAULT '',
        history_page_token TEXT NULL,
        enabled_at_ms BIGINT UNSIGNED NULL,
        claim_key CHAR(32) NULL,
        next_check_at DATETIME NULL,
        last_checked_at DATETIME NULL,
        last_status VARCHAR(24) NOT NULL DEFAULT 'disabled',
        last_review_json TEXT NULL,
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (connection_id) REFERENCES agent_connections_v1(id) ON DELETE CASCADE".($sqlite ? ')' : ",
        INDEX idx_gmail_inbox_review_due_v1 (enabled,next_check_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin"));
    if ($sqlite) $pdo->exec('CREATE INDEX IF NOT EXISTS idx_gmail_inbox_review_due_v1 ON agent_connection_gmail_inbox_v1 (enabled,next_check_at)');
    agent_connection_gmail_inbox_batches_install_v1($pdo);
    agent_email_running_report_install_v1($pdo);
    agent_connection_gmail_inbox_sync_install_v1($pdo);
}

function agent_connection_gmail_inbox_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT owner_user_id,connection_id,authorization_key,enabled,rules_json,revision,history_id,pending_event_id,
            pending_offset,pending_fingerprint,history_page_token,enabled_at_ms,claim_key,next_check_at,last_checked_at,last_status,last_review_json
            FROM agent_connection_gmail_inbox_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function agent_connection_gmail_inbox_storage_ready_v1(PDO $pdo): bool
{
    require_once __DIR__.'/agent-connection-gmail-inbox-v1.php';
    require_once __DIR__.'/agent-connection-gmail-inbox-initial-v1.php';
    return agent_connection_gmail_inbox_ready_v1($pdo) && agent_connection_gmail_inbox_receipts_ready_v1($pdo)
        && agent_connection_gmail_inbox_batches_ready_v1($pdo)
        && agent_connection_gmail_inbox_initial_capacity_ready_v1($pdo);
}

function agent_connection_gmail_inbox_policy_rules_v1(array $row): array
{
    $rules = json_decode((string)($row['rules_json'] ?? ''),true);
    return is_array($rules) ? $rules : ['senders'=>[],'domains'=>[],'subjects'=>[],'github_actions'=>false];
}

function agent_connection_gmail_inbox_policy_v1(PDO $pdo, int $ownerId, bool $lock = false): ?array
{
    if ($ownerId < 1) return null;
    $query = $pdo->prepare('SELECT * FROM agent_connection_gmail_inbox_v1 WHERE owner_user_id=?'
        .($lock ? agent_commerce_lock_pa2($pdo) : ''));
    $query->execute([$ownerId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['rules'] = agent_connection_gmail_inbox_policy_rules_v1($row);
    return $row;
}

function agent_connection_gmail_inbox_authority_v1(array $user, ?array $record): bool
{
    $ownerId = (int)($user['id'] ?? 0);
    return $ownerId > 0 && (int)($user['is_active'] ?? 0) === 1
        && $record && (int)($record['owner_user_id'] ?? $ownerId) === $ownerId
        && ($record['provider'] ?? '') === 'gmail' && ($record['status'] ?? '') === 'connected'
        && !empty($record['permissions']['read'] ?? $record['read_enabled'] ?? false)
        && has_permission('account.access',$user) && has_permission('chat.access',$user);
}

/** Gmail history IDs are opaque decimal strings and can exceed PHP's integer range. */
function agent_connection_gmail_inbox_history_id_v1(mixed $value): string
{
    return is_string($value) && preg_match('/\A[1-9][0-9]{0,19}\z/D',$value) ? $value : '';
}

function agent_connection_gmail_inbox_profile_v1(string $accessToken, ?callable $http = null): array
{
    $profile = agent_connection_gmail_api_v1($accessToken,'/profile?fields=emailAddress,historyId',null,$http);
    $email = agent_connection_gmail_sender_v1(is_string($profile['emailAddress'] ?? null) ? $profile['emailAddress'] : '');
    $cursor = agent_connection_gmail_inbox_history_id_v1($profile['historyId'] ?? null);
    if ($email === '' || $cursor === '') throw new AgentConnectionExceptionV1('Gmail did not verify the Inbox Review starting point.',502);
    return ['account_email'=>$email,'history_id'=>$cursor];
}

/** Store exact, bounded rules rather than Gmail query syntax. */
function agent_connection_gmail_inbox_rules_v1(array $input): array
{
    if (isset($input['ignore_github_actions']) && $input['ignore_github_actions'] !== '1') {
        throw new AgentConnectionExceptionV1('Inbox Review rules are invalid.',422);
    }
    $rules = ['github_actions'=>($input['ignore_github_actions'] ?? '') === '1'];
    if (isset($input['triage_enabled'])) {
        if ($input['triage_enabled'] !== '1') throw new AgentConnectionExceptionV1('Email triage settings are invalid.',422);
        $rules['triage_enabled'] = true;
    }
    if (isset($input['content_analysis'])) {
        if ($input['content_analysis'] !== '1') throw new AgentConnectionExceptionV1('Email content settings are invalid.',422);
        $rules['content_analysis'] = true;
    }
    foreach (['senders','domains','subjects'] as $kind) {
        $text = $input['ignore_'.$kind] ?? '';
        if (!is_string($text) || strlen($text) > 16384 || preg_match('//u',$text) !== 1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text)) {
            throw new AgentConnectionExceptionV1('Inbox Review rules are invalid.',422);
        }
        $lines = array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',$text)),static fn(string $line): bool => $line !== ''));
        if (count($lines) > 20) throw new AgentConnectionExceptionV1('Enter at most 20 rules of each kind.',422);
        $items = [];
        foreach ($lines as $line) {
            $value = match ($kind) {
                'senders' => agent_connection_gmail_sender_v1($line),
                'domains' => strlen($line) <= 253 && !str_ends_with($line,'.')
                    && filter_var($line,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME) ? strtolower($line) : '',
                'subjects' => mb_strlen($line,'UTF-8') <= 160 && !preg_match('/[\x00-\x1f\x7f]/',$line) ? $line : '',
            };
            if ($value === '') throw new AgentConnectionExceptionV1('Enter an exact sender, domain or subject phrase on each line.',422);
            $items[mb_strtolower($value,'UTF-8')] ??= $value;
        }
        $rules[$kind] = array_values($items);
    }
    return $rules;
}

/** Split a mailbox list at real address separators, never inside quoted display names. */
function agent_connection_gmail_inbox_mailboxes_v1(mixed $header): array
{
    if (!is_string($header) || $header === '' || strlen($header) > 8192 || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/',$header)) return [];
    $parts = [];
    $start = 0;
    $quoted = false;
    $length = strlen($header);
    for ($index = 0; $index < $length; $index++) {
        $character = $header[$index];
        if ($quoted && $character === '\\') { $index++; continue; }
        if ($character === '"') $quoted = !$quoted;
        if (!$quoted && $character === ',') {
            $parts[] = substr($header,$start,$index-$start);
            $start = $index+1;
        }
    }
    if ($quoted) return [];
    $parts[] = substr($header,$start);
    $mailboxes = [];
    foreach ($parts as $part) {
        // Reject group syntax and unquoted address-like text in the display name.
        if (preg_match('/\A([^<>]*)<[^<>]+>\s*\z/D',trim($part),$match)) {
            $display = preg_replace('~"(?:[^"\\\\]++|\\\\.)*"~','',$match[1]);
            if ($display === null || strpbrk($display,'()<>@,;:\\"[]') !== false) return [];
        }
        $address = agent_connection_gmail_address_v1($part);
        if ($address === '') return [];
        $mailboxes[] = $address;
    }
    return $mailboxes;
}

/** These filters apply only to Inbox Review, never explicit Gmail searches or watches. */
function agent_connection_gmail_inbox_ignored_v1(array $message, array $rules): bool
{
    $from = agent_connection_gmail_inbox_mailboxes_v1($message['from'] ?? null);
    if (count($from) === 1) {
        $sender = $from[0];
        $domain = substr(strrchr($sender,'@'),1);
        if (in_array($sender,(array)($rules['senders'] ?? []),true)
            || in_array($domain,(array)($rules['domains'] ?? []),true)) return true;
        if (!empty($rules['github_actions']) && $sender === 'notifications@github.com'
            && in_array('ci_activity@noreply.github.com',agent_connection_gmail_inbox_mailboxes_v1($message['cc'] ?? null),true)) return true;
    }
    $subject = $message['subject'] ?? '';
    if (!is_string($subject)) return false;
    foreach ((array)($rules['subjects'] ?? []) as $phrase) {
        if (is_string($phrase) && $phrase !== '' && mb_stripos($subject,$phrase,0,'UTF-8') !== false) return true;
    }
    return false;
}

function agent_connection_gmail_inbox_bound_policy_v1(?array $policy, array $connection): ?array
{
    return $policy && (int)$policy['connection_id'] === (int)$connection['id']
        && hash_equals($policy['authorization_key'],$connection['authorization_key']) ? $policy : null;
}

/** The caller holds the existing connection lock before changing Inbox Review authority. */
function agent_connection_gmail_inbox_revoke_v1(PDO $pdo, int $ownerId, int $connectionId, string $authorizationKey): void
{
    if (!agent_connection_gmail_inbox_ready_v1($pdo)) return;
    $update = $pdo->prepare("UPDATE agent_connection_gmail_inbox_v1 SET enabled=0,revision=?,history_id='',pending_event_id='',
        pending_offset=0,pending_fingerprint='',history_page_token=NULL,enabled_at_ms=NULL,claim_key=NULL,next_check_at=NULL,
        last_checked_at=NULL,last_status='disabled',last_review_json=NULL WHERE owner_user_id=? AND connection_id=? AND authorization_key=?");
    $update->execute([bin2hex(random_bytes(16)),$ownerId,$connectionId,$authorizationKey]);
    if ($update->rowCount() === 1) agent_connection_gmail_inbox_clear_output_v1($pdo,$ownerId);
}

function agent_connection_gmail_inbox_clear_output_v1(PDO $pdo, int $ownerId, bool $preserveMatters = false): void
{
    require_once __DIR__.'/agent-connection-gmail-inbox-v1.php';
    require_once __DIR__.'/agent-connection-gmail-inbox-notice-v1.php';
    require_once __DIR__.'/agent-connection-gmail-inbox-initial-v1.php';
    require_once __DIR__.'/agent-email-matters-v1.php';
    agent_connection_gmail_inbox_receipts_clear_v1($pdo,$ownerId);
    agent_connection_gmail_inbox_batches_clear_v1($pdo,$ownerId);
    agent_connection_gmail_inbox_notice_purge_v1($pdo,$ownerId);
    agent_connection_gmail_inbox_initial_purge_v1($pdo,$ownerId);
    agent_connection_gmail_inbox_sync_clear_v1($pdo,$ownerId);
    if (!$preserveMatters) {
        agent_connection_gmail_inbox_clear_triage_v1($pdo,$ownerId);
        agent_email_matters_clear_v1($pdo,$ownerId);
    }
}

function agent_connection_gmail_inbox_clear_triage_v1(PDO $pdo,int $ownerId): void
{
    require_once __DIR__.'/agent-email-triage-v1.php';
    agent_email_triage_clear_v1($pdo,$ownerId);
    agent_email_running_report_clear_v1($pdo,$ownerId);
    require_once __DIR__.'/agent-connection-gmail-inbox-activity-v1.php';
    agent_connection_gmail_inbox_activity_clear_v1($pdo,$ownerId);
}

/** A local projection failure must preserve the provider-confirmed receipt. */
function agent_connection_gmail_inbox_project_matter_receipt_v1(PDO $pdo, Closure $project): void
{
    if (!$pdo->inTransaction()) throw new LogicException('Email matter projection requires the receipt transaction.');
    $pdo->exec('SAVEPOINT vp3_email_matter_receipt');
    try {
        $project();
    } catch (Throwable $error) {
        $pdo->exec('ROLLBACK TO SAVEPOINT vp3_email_matter_receipt');
        if (function_exists('agent_runtime_v125_trace')) {
            agent_runtime_v125_trace('worker.gmail_matter.projection_failed',['error_class'=>get_class($error)]);
        }
    } finally {
        $pdo->exec('RELEASE SAVEPOINT vp3_email_matter_receipt');
    }
}

/** Verify the submitted grant and policy snapshots both before and after any provider request. */
function agent_connection_gmail_inbox_save_v1(PDO $pdo, array $user, array $input, ?callable $http = null): string
{
    if (!agent_connection_gmail_inbox_storage_ready_v1($pdo)) throw new AgentConnectionExceptionV1('Inbox Review storage is unavailable.',503);
    $ownerId = (int)($user['id'] ?? 0);
    $connectionId = filter_var($input['connection_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $connectionRevision = $input['connection_revision'] ?? null;
    $revision = $input['inbox_revision'] ?? null;
    $action = $input['action'] ?? null;
    if ($ownerId < 1 || !$connectionId || !is_string($connectionRevision) || !preg_match('/\A[a-f0-9]{64}\z/D',$connectionRevision)
        || !is_string($revision) || ($revision !== '' && !preg_match('/\A[a-f0-9]{32}\z/D',$revision))
        || (isset($input['enabled']) && $input['enabled'] !== '1')
        || (isset($input['catch_up_recent']) && $input['catch_up_recent'] !== '1')
        || !in_array($action,['save','restart','continue_sync'],true)) throw new AgentConnectionExceptionV1('Invalid Inbox Review request.',400);

    $load = static function (bool $lock) use ($pdo,$ownerId,$connectionId,$connectionRevision,$revision): array {
        $connection = agent_connections_find_v1($pdo,$ownerId,$connectionId,$lock && !agent_commerce_sqlite_pa2($pdo));
        if (!$connection || $connection['provider'] !== 'gmail' || $connection['status'] === 'disconnected'
            || !hash_equals(hash('sha256',$connection['authorization_key']),$connectionRevision)) {
            throw new AgentConnectionExceptionV1('The Gmail connection changed.',409);
        }
        $stored = agent_connection_gmail_inbox_policy_v1($pdo,$ownerId,$lock);
        $policy = agent_connection_gmail_inbox_bound_policy_v1($stored,$connection);
        if (!hash_equals($policy['revision'] ?? '',$revision)) throw new AgentConnectionExceptionV1('Inbox Review settings changed.',409);
        return [$connection,$policy,$stored !== null];
    };
    [$connection,$policy] = $load(false);
    $restart = $action === 'restart';
    $continuing = $action === 'continue_sync';
    if (($restart || $continuing) && empty($policy['enabled'])) {
        throw new AgentConnectionExceptionV1('Inbox Review is no longer active.',409);
    }
    $enabled = $restart || $continuing || ($input['enabled'] ?? '') === '1';
    $rules = $restart || $continuing ? $policy['rules'] : agent_connection_gmail_inbox_rules_v1($input);
    $triage = $enabled && ($rules['triage_enabled'] ?? false) === true;
    if (!$triage) unset($rules['content_analysis']);
    $contentAnalysis = agent_email_running_report_enabled_v1(['rules'=>$rules]);
    if ($contentAnalysis && !agent_email_running_report_ready_v1($pdo)) {
        throw new AgentConnectionExceptionV1('Run Database Upgrade to enable Email content analysis.',503);
    }
    $catchUp = $action === 'save' && ($input['catch_up_recent'] ?? '') === '1';
    if ($catchUp && !$triage) throw new AgentConnectionExceptionV1('Enable Email triage to review recent mail.',422);
    if ($triage && (!agent_connection_gmail_inbox_triage_enabled_v1($pdo,['rules'=>$rules])
        || !agent_connection_gmail_inbox_sync_ready_v1($pdo))) {
        throw new AgentConnectionExceptionV1('Run Database Upgrade to enable Email triage.',503);
    }
    $rulesJson = json_encode($rules,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    if ($enabled && !agent_connection_gmail_inbox_authority_v1($user,$connection)) {
        throw new AgentConnectionExceptionV1('Gmail read and Agent Chat access are required.',403);
    }

    $historyId = '';
    $enabledAt = null;
    $catchUpBefore = 0;
    if ($enabled && ($restart || empty($policy['enabled']))) {
        if ($pdo->inTransaction()) throw new LogicException('Gmail baseline cannot be fetched inside a settings transaction.');
        $access = agent_connections_access_v1($ownerId,$connectionId,'read',$http,$connection['authorization_key']);
        $enabledAt = (int)floor(microtime(true)*1000);
        $profile = agent_connection_gmail_inbox_profile_v1($access['access_token'],$http);
        if ($profile['account_email'] !== agent_connection_gmail_sender_v1($connection['account_email'])) {
            throw new AgentConnectionExceptionV1('Gmail returned a different mailbox.',401);
        }
        $historyId = $profile['history_id'];
        if ($restart && $triage && agent_connection_gmail_inbox_compare_cursor_v1($historyId,(string)$policy['history_id']) < 0) {
            throw new AgentConnectionExceptionV1('Gmail did not verify the Inbox Review recovery cursor.',409);
        }
        $catchUpBefore = (int)floor(microtime(true)*1000)+1;
    }

    return agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$ownerId,$connectionId,$load,$enabled,$restart,$rulesJson,
        $historyId,$enabledAt,$triage,$contentAnalysis,$catchUp,$catchUpBefore,$continuing): string {
        $owner = $pdo->prepare('SELECT * FROM users WHERE id=?'.agent_commerce_lock_pa2($pdo));
        $owner->execute([$ownerId]);
        $live = $owner->fetch(PDO::FETCH_ASSOC);
        if (!$live || (int)($live['is_active'] ?? 0) !== 1 || !has_permission('account.access',$live)) {
            throw new AgentConnectionExceptionV1('The connection owner is unavailable.',403);
        }
        [$connection,$policy,$stored] = $load(true);
        if ($enabled && (!agent_connection_gmail_inbox_authority_v1($live,$connection)
            || !hash_equals($connection['client_fingerprint'],agent_connections_client_fingerprint_v1()))) {
            throw new AgentConnectionExceptionV1('Gmail read access changed.',409);
        }
        $newRevision = bin2hex(random_bytes(16));
        $now = agent_commerce_time_pa2();
        if ($continuing) {
            agent_connection_gmail_inbox_sync_continue_v1($pdo,$policy);
            $pdo->prepare("UPDATE agent_connection_gmail_inbox_v1 SET revision=?,claim_key=NULL,next_check_at=?,
                last_status='waiting' WHERE owner_user_id=?")->execute([$newRevision,$now,$ownerId]);
            return 'inbox_sync_continued';
        }
        if ($restart && $triage) {
            $sync = agent_connection_gmail_inbox_sync_start_v1($policy,'recovery',$historyId,$catchUpBefore);
            agent_connection_gmail_inbox_sync_save_v1($pdo,$policy,$sync);
            agent_email_matters_reset_scan_v1($pdo,$policy);
            $pdo->prepare("UPDATE agent_connection_gmail_inbox_v1 SET revision=?,claim_key=NULL,next_check_at=?,
                last_status='waiting' WHERE owner_user_id=?")->execute([$newRevision,$now,$ownerId]);
            return 'inbox_restarted';
        }
        if ($enabled && $historyId === '') {
            $firstContentAnalysis = $contentAnalysis && !agent_email_running_report_enabled_v1($policy);
            $priorSync = $catchUp || $firstContentAnalysis ? agent_connection_gmail_inbox_sync_row_v1($pdo,$policy) : null;
            $busySync = ($priorSync['status'] ?? null) === 'pending'
                || (($priorSync['mode'] ?? null) === 'recovery' && $priorSync['status'] === 'truncated');
            if ($catchUp && $busySync) {
                throw new AgentConnectionExceptionV1('Finish the current Gmail catch-up before starting another.',409);
            }
            $startCatchUp = $catchUp || ($firstContentAnalysis && !$busySync && $policy['last_status'] !== 'restart_required');
            if (!hash_equals((string)$policy['rules_json'],$rulesJson)) {
                require_once __DIR__.'/agent-connection-gmail-inbox-initial-v1.php';
                agent_connection_gmail_inbox_initial_purge_v1($pdo,$ownerId);
            }
            if (!$triage && ($policy['rules']['triage_enabled'] ?? false) === true) {
                agent_connection_gmail_inbox_sync_clear_v1($pdo,$ownerId);
                agent_connection_gmail_inbox_clear_triage_v1($pdo,$ownerId);
            }
            if ($triage && !$contentAnalysis && agent_email_running_report_enabled_v1($policy)) {
                agent_email_running_report_clear_v1($pdo,$ownerId);
            }
            $recover = $triage && ($policy['rules']['triage_enabled'] ?? false) !== true
                && $policy['last_status'] === 'restart_required';
            $schedule = $startCatchUp || $recover
                || ($policy['claim_key'] !== null && $policy['next_check_at'] !== null);
            $nextCheck = $schedule ? $now : $policy['next_check_at'];
            $pdo->prepare('UPDATE agent_connection_gmail_inbox_v1 SET rules_json=?,revision=?,claim_key=NULL,next_check_at=?,last_status=? WHERE owner_user_id=?')
                ->execute([$rulesJson,$newRevision,$nextCheck,$recover?'waiting':$policy['last_status'],$ownerId]);
            if ($startCatchUp) {
                $sync = agent_connection_gmail_inbox_sync_start_v1($policy,'catchup',(string)$policy['history_id'],(int)floor(microtime(true)*1000)+1);
                agent_connection_gmail_inbox_sync_save_v1($pdo,$policy,$sync);
            }
            return 'inbox_saved';
        }

        $values = [$connectionId,$connection['authorization_key'],(int)$enabled,$rulesJson,$newRevision,
            $historyId,$enabledAt,$enabled ? $now : null,$enabled ? 'waiting' : 'disabled',$ownerId];
        if ($stored) {
            $pdo->prepare("UPDATE agent_connection_gmail_inbox_v1 SET connection_id=?,authorization_key=?,enabled=?,rules_json=?,revision=?,
                history_id=?,enabled_at_ms=?,next_check_at=?,last_status=?,pending_event_id='',pending_offset=0,pending_fingerprint='',
                history_page_token=NULL,claim_key=NULL,last_checked_at=NULL,last_review_json=NULL WHERE owner_user_id=?")->execute($values);
            agent_connection_gmail_inbox_clear_output_v1($pdo,$ownerId,$restart);
            if ($restart) agent_email_matters_reset_scan_v1($pdo,$policy);
        } else {
            $pdo->prepare('INSERT INTO agent_connection_gmail_inbox_v1 (connection_id,authorization_key,enabled,rules_json,revision,
                history_id,enabled_at_ms,next_check_at,last_status,owner_user_id) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute($values);
        }
        if ($catchUp || $contentAnalysis) {
            $livePolicy = agent_connection_gmail_inbox_policy_v1($pdo,$ownerId,true);
            $sync = agent_connection_gmail_inbox_sync_start_v1($livePolicy,'catchup',$historyId,$catchUpBefore);
            agent_connection_gmail_inbox_sync_save_v1($pdo,$livePolicy,$sync);
        }
        return $restart ? 'inbox_restarted' : ($enabled ? 'inbox_saved' : 'inbox_disabled');
    });
}
