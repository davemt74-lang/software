<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connection-gmail-inbox-settings-v1.php';
require_once __DIR__.'/knowledge.php';
require_once __DIR__.'/shared-folders-v161.php';

function agent_connection_gmail_github_install_v1(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new LogicException('GitHub Workflow Review storage cannot be installed inside a transaction.');
    $sqlite = agent_commerce_sqlite_pa2($pdo);
    $owner = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_connection_gmail_github_v1 (
        owner_user_id $owner PRIMARY KEY,
        connection_id BIGINT UNSIGNED NOT NULL,
        authorization_key CHAR(64) NOT NULL,
        folder_id BIGINT UNSIGNED NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
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
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (connection_id) REFERENCES agent_connections_v1(id) ON DELETE CASCADE".($sqlite ? ')' : ",
        INDEX idx_gmail_github_review_due_v1 (enabled,next_check_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin"));
    if ($sqlite) $pdo->exec('CREATE INDEX IF NOT EXISTS idx_gmail_github_review_due_v1 ON agent_connection_gmail_github_v1 (enabled,next_check_at)');
}

function agent_connection_gmail_github_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT owner_user_id,connection_id,authorization_key,folder_id,enabled,revision,history_id,pending_event_id,
            pending_offset,pending_fingerprint,history_page_token,enabled_at_ms,claim_key,next_check_at,last_checked_at,last_status
            FROM agent_connection_gmail_github_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function agent_connection_gmail_github_storage_ready_v1(PDO $pdo): bool
{
    require_once __DIR__.'/agent-connection-gmail-github-v1.php';
    require_once __DIR__.'/agent-connection-gmail-github-notice-v1.php';
    if (!agent_connection_gmail_github_ready_v1($pdo) || !agent_connection_gmail_github_receipts_ready_v1($pdo)
        || !agent_connection_gmail_github_actions_voice_ready_v1($pdo)) return false;
    try {
        $pdo->query('SELECT id,created_by_user_id,folder_name FROM artist_transcript_folders_v177 WHERE 1=0');
        $pdo->query('SELECT id,folder_id,knowledge_scope,created_by_user_id FROM knowledge_items WHERE 1=0');
        $pdo->query('SELECT knowledge_id,chunk_index,chunk_text FROM knowledge_chunks WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

function agent_connection_gmail_github_policy_v1(PDO $pdo, int $ownerId, bool $lock = false): ?array
{
    if ($ownerId < 1) return null;
    $query = $pdo->prepare('SELECT * FROM agent_connection_gmail_github_v1 WHERE owner_user_id=?'
        .($lock ? agent_commerce_lock_pa2($pdo) : ''));
    $query->execute([$ownerId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function agent_connection_gmail_github_authority_v1(array $user, ?array $connection): bool
{
    return agent_connection_gmail_inbox_authority_v1($user,$connection)
        && personal_capability_has_v242('personal_knowledge.access',$user)
        && personal_capability_has_v242('personal_knowledge.manage',$user);
}

/** Always resolve the canonical folder under the authenticated owner, including when a worker commits. */
function agent_connection_gmail_github_folder_v1(PDO $pdo, int $ownerId, int $folderId, bool $lock = false): ?array
{
    if ($ownerId < 1 || $folderId < 1) return null;
    $query = $pdo->prepare('SELECT id,folder_name FROM artist_transcript_folders_v177 WHERE id=? AND created_by_user_id=?'
        .($lock ? agent_commerce_lock_pa2($pdo) : ''));
    $query->execute([$folderId,$ownerId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? ['id'=>(int)$row['id'],'folder_name'=>$row['folder_name']] : null;
}

function agent_connection_gmail_github_folder_named_v1(PDO $pdo, array $user, string $name): int
{
    $query = $pdo->prepare('SELECT id,folder_name FROM artist_transcript_folders_v177 WHERE created_by_user_id=? AND folder_name=?'
        .agent_commerce_lock_pa2($pdo));
    $query->execute([(int)$user['id'],$name]);
    if ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        if ($row['folder_name'] !== $name) throw new AgentConnectionExceptionV1('Choose the existing folder by its exact name.',422);
        return (int)$row['id'];
    }
    return (int)personal_knowledge_create_folder($pdo,$user,$name)['id'];
}

function agent_connection_gmail_github_bound_v1(?array $policy, array $connection): ?array
{
    return $policy && (int)$policy['connection_id'] === (int)$connection['id']
        && hash_equals($policy['authorization_key'],$connection['authorization_key']) ? $policy : null;
}

/** Called within the existing exact Gmail grant mutation; historical Knowledge remains private and user-owned. */
function agent_connection_gmail_github_revoke_v1(PDO $pdo, int $ownerId, int $connectionId, string $authorizationKey): void
{
    if (!agent_connection_gmail_github_ready_v1($pdo)) return;
    $update = $pdo->prepare("UPDATE agent_connection_gmail_github_v1 SET enabled=0,revision=?,history_id='',pending_event_id='',
        pending_offset=0,pending_fingerprint='',history_page_token=NULL,enabled_at_ms=NULL,claim_key=NULL,next_check_at=NULL,
        last_checked_at=NULL,last_status='disabled' WHERE owner_user_id=? AND connection_id=? AND authorization_key=?");
    $update->execute([bin2hex(random_bytes(16)),$ownerId,$connectionId,$authorizationKey]);
    if ($update->rowCount() === 1) agent_connection_gmail_github_clear_activity_v1($pdo,$ownerId);
}

function agent_connection_gmail_github_clear_activity_v1(PDO $pdo, int $ownerId): void
{
    require_once __DIR__.'/agent-connection-gmail-github-v1.php';
    require_once __DIR__.'/agent-connection-gmail-github-notice-v1.php';
    agent_connection_gmail_github_receipts_clear_v1($pdo,$ownerId);
    agent_connection_gmail_github_actions_notice_purge_v1($pdo,$ownerId);
}

/** Deliberately omit provider credentials, history cursors, and other users' folder names. */
function agent_connection_gmail_github_state_v1(PDO $pdo, array $user): array
{
    $state = ['available'=>false,'enabled'=>false,'can_disable'=>false,'status'=>'unavailable','connection_id'=>null,
        'connection_revision'=>'','revision'=>'','folder_id'=>null,'folder_name'=>'','folders'=>[],'last_checked_at'=>null];
    $ownerId = (int)($user['id'] ?? 0);
    if ($ownerId < 1 || (int)($user['is_active'] ?? 0) !== 1 || !has_permission('account.access',$user)
        || !agent_connection_gmail_github_ready_v1($pdo)) return $state;
    $connection = agent_connections_find_v1($pdo,$ownerId,'gmail');
    if (!$connection || $connection['status'] === 'disconnected') {
        $state['status'] = 'gmail_required';
        return $state;
    }
    $policy = agent_connection_gmail_github_bound_v1(agent_connection_gmail_github_policy_v1($pdo,$ownerId),$connection);
    $state['connection_id'] = (int)$connection['id'];
    $state['connection_revision'] = hash('sha256',$connection['authorization_key']);
    $state['revision'] = $policy['revision'] ?? '';
    $state['enabled'] = !empty($policy['enabled']);
    $state['can_disable'] = $state['enabled'];
    if (!agent_connection_gmail_github_authority_v1($user,$connection)) {
        $state['status'] = $connection['status'] !== 'connected' ? 'gmail_required' : 'permission_required';
        return $state;
    }
    if (!agent_connection_gmail_github_storage_ready_v1($pdo) || !agent_connection_gmail_ready_v1()
        || !hash_equals($connection['client_fingerprint'],agent_connections_client_fingerprint_v1())) return $state;

    $state['available'] = true;
    $state['folders'] = array_map(static fn(array $folder): array => ['id'=>(int)$folder['id'],'name'=>$folder['folder_name']],
        personal_knowledge_folders($pdo,$user));
    $folder = $policy ? agent_connection_gmail_github_folder_v1($pdo,$ownerId,(int)$policy['folder_id']) : null;
    $state['folder_id'] = $folder['id'] ?? null;
    $state['folder_name'] = $folder['folder_name'] ?? '';
    $state['last_checked_at'] = $policy['last_checked_at'] ?? null;
    $status = $policy['last_status'] ?? 'disabled';
    $state['status'] = $state['enabled'] && !$folder ? 'destination_unavailable' : (in_array($status,
        ['waiting','checked','retrying','restart_required','destination_unavailable','disabled'],true) ? $status : 'unavailable');
    return $state;
}

function agent_connection_gmail_github_save_v1(PDO $pdo, array $user, array $input, ?callable $http = null): string
{
    if (!agent_connection_gmail_github_ready_v1($pdo)) throw new AgentConnectionExceptionV1('Run Database Upgrade to use GitHub Workflow Review.',503);
    $ownerId = (int)($user['id'] ?? 0);
    $connectionId = filter_var($input['connection_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $connectionRevision = $input['connection_revision'] ?? null;
    $revision = $input['github_revision'] ?? null;
    $action = $input['action'] ?? null;
    if ($ownerId < 1 || !$connectionId || !is_string($connectionRevision) || !preg_match('/\A[a-f0-9]{64}\z/D',$connectionRevision)
        || !is_string($revision) || ($revision !== '' && !preg_match('/\A[a-f0-9]{32}\z/D',$revision))
        || !in_array($action,['enable','disable','restart'],true)) throw new AgentConnectionExceptionV1('Invalid GitHub Workflow Review request.',400);

    $load = static function (bool $lock) use ($pdo,$ownerId,$connectionId,$connectionRevision,$revision): array {
        $connection = agent_connections_find_v1($pdo,$ownerId,$connectionId,$lock && !agent_commerce_sqlite_pa2($pdo));
        if (!$connection || $connection['provider'] !== 'gmail' || $connection['status'] === 'disconnected'
            || !hash_equals(hash('sha256',$connection['authorization_key']),$connectionRevision)) {
            throw new AgentConnectionExceptionV1('The Gmail connection changed. Reopen the tool and try again.',409);
        }
        $stored = agent_connection_gmail_github_policy_v1($pdo,$ownerId,$lock);
        $policy = agent_connection_gmail_github_bound_v1($stored,$connection);
        if (!hash_equals($policy['revision'] ?? '',$revision)) {
            throw new AgentConnectionExceptionV1('GitHub Workflow Review changed. Reopen the tool and try again.',409);
        }
        return [$connection,$policy,$stored !== null];
    };
    [$connection,$policy] = $load(false);
    $folderId = 0;
    $folderName = '';
    $historyId = '';
    $enabledAt = null;
    if ($action !== 'disable') {
        if (!agent_connection_gmail_github_storage_ready_v1($pdo)) {
            throw new AgentConnectionExceptionV1('Run Database Upgrade to use GitHub Workflow Review.',503);
        }
        if (!agent_connection_gmail_github_authority_v1($user,$connection)) {
            throw new AgentConnectionExceptionV1('GitHub Workflow Review requires Gmail read access, Agent Chat and Personal Knowledge management.',403);
        }
        if ($action === 'restart') {
            if (empty($policy['enabled'])) throw new AgentConnectionExceptionV1('GitHub Workflow Review is off.',409);
            $folderId = (int)$policy['folder_id'];
        } elseif (array_key_exists('folder_id',$input)) {
            $folderId = filter_var($input['folder_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0;
            if ($folderId === 0 || array_key_exists('folder_name',$input)) throw new AgentConnectionExceptionV1('Choose one Personal Knowledge folder.',422);
        } else {
            $name = $input['folder_name'] ?? null;
            if (!is_string($name) || strlen($name)>320 || preg_match('//u',$name)!==1 || preg_match('/[\x00-\x1f\x7f]/',$name)) {
                throw new AgentConnectionExceptionV1('Enter a Personal Knowledge folder name between 1 and 80 characters.',422);
            }
            try { $folderName = shared_folders_v161_clean_name($name); }
            catch (RuntimeException) { throw new AgentConnectionExceptionV1('Enter a Personal Knowledge folder name between 1 and 80 characters.',422); }
        }
        if ($folderId > 0 && !agent_connection_gmail_github_folder_v1($pdo,$ownerId,$folderId)) {
            throw new AgentConnectionExceptionV1('Choose an available folder from your Personal Knowledge.',422);
        }
        if ($pdo->inTransaction()) throw new LogicException('Gmail baseline cannot be fetched inside a settings transaction.');
        $access = agent_connections_access_v1($ownerId,$connectionId,'read',$http,$connection['authorization_key']);
        $enabledAt = (int)floor(microtime(true)*1000);
        $profile = agent_connection_gmail_inbox_profile_v1($access['access_token'],$http);
        if ($profile['account_email'] !== agent_connection_gmail_sender_v1($connection['account_email'])) {
            throw new AgentConnectionExceptionV1('Gmail returned a different mailbox. Reconnect Gmail and try again.',401);
        }
        $historyId = $profile['history_id'];
    }

    return agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$ownerId,$connectionId,$action,$load,$folderId,$folderName,$historyId,$enabledAt): string {
        $owner = $pdo->prepare('SELECT * FROM users WHERE id=?'.agent_commerce_lock_pa2($pdo));
        $owner->execute([$ownerId]);
        $live = $owner->fetch(PDO::FETCH_ASSOC);
        if (!$live || (int)($live['is_active'] ?? 0) !== 1 || !has_permission('account.access',$live)) {
            throw new AgentConnectionExceptionV1('The connection owner is unavailable.',403);
        }
        [$connection,$policy,$stored] = $load(true);
        $grant = $connection['authorization_key'];
        if ($action === 'disable') {
            if ($policy) agent_connection_gmail_github_revoke_v1($pdo,$ownerId,$connectionId,$grant);
            return 'github_disabled';
        }
        if (!agent_connection_gmail_github_authority_v1($live,$connection)
            || !hash_equals($connection['client_fingerprint'],agent_connections_client_fingerprint_v1())) {
            throw new AgentConnectionExceptionV1('GitHub Workflow Review access changed. Reopen the tool and try again.',409);
        }
        if ($folderId > 0 && !agent_connection_gmail_github_folder_v1($pdo,$ownerId,$folderId,true)) {
            throw new AgentConnectionExceptionV1('The Personal Knowledge folder is no longer available.',409);
        }
        $destination = $folderId ?: agent_connection_gmail_github_folder_named_v1($pdo,$live,$folderName);
        $values = [$connectionId,$grant,$destination,bin2hex(random_bytes(16)),$historyId,$enabledAt,agent_commerce_time_pa2(),$ownerId];
        if ($stored) {
            $pdo->prepare("UPDATE agent_connection_gmail_github_v1 SET connection_id=?,authorization_key=?,folder_id=?,revision=?,
                history_id=?,enabled_at_ms=?,next_check_at=?,enabled=1,last_status='waiting',pending_event_id='',pending_offset=0,
                pending_fingerprint='',history_page_token=NULL,claim_key=NULL,last_checked_at=NULL WHERE owner_user_id=?")->execute($values);
            agent_connection_gmail_github_clear_activity_v1($pdo,$ownerId);
        } else {
            $pdo->prepare("INSERT INTO agent_connection_gmail_github_v1 (connection_id,authorization_key,folder_id,revision,history_id,
                enabled_at_ms,next_check_at,owner_user_id,enabled,last_status) VALUES (?,?,?,?,?,?,?,?,1,'waiting')")->execute($values);
        }
        return $action === 'restart' ? 'github_restarted' : 'github_enabled';
    });
}
