<?php
declare(strict_types=1);

require_once __DIR__.'/agent-connections-v1.php';
require_once __DIR__.'/agent-commerce-recovery-pa2.php';

function agent_connection_gmail_autosend_install_v1(PDO $pdo): void
{
    if ($pdo->inTransaction()) throw new LogicException('Gmail Auto Send storage cannot be installed inside a transaction.');
    $sqlite = agent_commerce_sqlite_pa2($pdo);
    $owner = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_connection_gmail_autosend_v1 (
        owner_user_id $owner PRIMARY KEY,
        connection_id BIGINT UNSIGNED NOT NULL,
        authorization_key CHAR(64) NOT NULL,
        revision CHAR(32) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        addresses_json TEXT NOT NULL,
        FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (connection_id) REFERENCES agent_connections_v1(id) ON DELETE CASCADE"
        .($sqlite ? ')' : ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'));
}

function agent_connection_gmail_autosend_ready_v1(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT owner_user_id,connection_id,authorization_key,revision,enabled,addresses_json
            FROM agent_connection_gmail_autosend_v1 WHERE 1=0');
        return true;
    } catch (PDOException) {
        return false;
    }
}

/** Keep aliases, plus tags and dots as entered, apart from existing Gmail case normalization. */
function agent_connection_gmail_autosend_addresses_v1(mixed $input): array
{
    if (!is_string($input) || strlen($input) > 6000 || preg_match('/[\x00-\x09\x0b\x0c\x0e-\x1f\x7f]/', $input)) {
        throw new AgentConnectionExceptionV1('Enter exact email addresses, one per line.', 422);
    }
    $lines = array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $input)), static fn(string $line): bool => $line !== '');
    if (count($lines) > 20) throw new AgentConnectionExceptionV1('Enter at most 20 exact email addresses.', 422);
    $addresses = [];
    foreach ($lines as $line) {
        $address = agent_connection_gmail_sender_v1($line);
        if ($address === '' || str_contains($address, '*')) {
            throw new AgentConnectionExceptionV1('Enter exact email addresses, one per line.', 422);
        }
        $addresses[$address] = true;
    }
    return array_keys($addresses);
}

function agent_connection_gmail_autosend_owner_v1(PDO $pdo, array $user, bool $lock = false): int
{
    $owner = (int)($user['id'] ?? 0);
    if ($owner < 1 || (function_exists('vp3_participant_scope_v1') && vp3_participant_scope_v1() !== null)) {
        throw new AgentConnectionExceptionV1('Gmail Auto Send is available in private owner Chat.', 403);
    }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id=?'.($lock ? agent_commerce_lock_pa2($pdo) : ''));
    $stmt->execute([$owner]);
    $live = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$live || empty($live['is_active']) || !has_permission('account.access', $live) || !has_permission('chat.access', $live)) {
        throw new AgentConnectionExceptionV1('Gmail Auto Send is available in private owner Chat.', 403);
    }
    return $owner;
}

function agent_connection_gmail_autosend_connection_v1(PDO $pdo, int $owner, int $connection, bool $lock = false): ?array
{
    $row = agent_connections_find_v1($pdo, $owner, $connection, $lock && !agent_commerce_sqlite_pa2($pdo));
    return $row && $row['provider'] === 'gmail' && $row['status'] === 'connected' ? $row : null;
}

function agent_connection_gmail_autosend_granted_v1(array $connection): bool
{
    $permissions = agent_connections_permissions_v1($connection);
    return $permissions['read'] && $permissions['send'] && agent_connection_gmail_ready_v1()
        && hash_equals($connection['client_fingerprint'], agent_connections_client_fingerprint_v1());
}

function agent_connection_gmail_autosend_policy_v1(PDO $pdo, int $owner, int $connection, string $grant, bool $lock = false): ?array
{
    $stmt = $pdo->prepare('SELECT revision,enabled,addresses_json FROM agent_connection_gmail_autosend_v1
        WHERE owner_user_id=? AND connection_id=? AND authorization_key=?'.($lock ? agent_commerce_lock_pa2($pdo) : ''));
    $stmt->execute([$owner, $connection, $grant]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $saved = json_decode($row['addresses_json'], true, 4, JSON_THROW_ON_ERROR);
    if (!is_string($row['revision']) || !preg_match('/\A[a-f0-9]{32}\z/D', $row['revision'])
        || !in_array($row['enabled'], [0, 1, '0', '1'], true)
        || !is_array($saved) || !array_is_list($saved) || count($saved) > 20
        || array_filter($saved, static fn(mixed $address): bool => !is_string($address)
            || $address === '' || str_contains($address, '*') || agent_connection_gmail_sender_v1($address) !== $address)) {
        throw new AgentConnectionExceptionV1('Gmail Auto Send settings could not be verified.', 503);
    }
    return ['revision'=>$row['revision'], 'enabled'=>(bool)$row['enabled'], 'addresses'=>$saved];
}

/** This projection is only for the owner's Connections form. */
function agent_connection_gmail_autosend_settings_v1(PDO $pdo, array $user, int $connection): array
{
    $view = ['ready'=>false, 'available'=>false, 'enabled'=>false, 'addresses'=>[], 'revision'=>''];
    if (!agent_connection_gmail_autosend_ready_v1($pdo)) return $view;
    $view['ready'] = true;
    try {
        $owner = agent_connection_gmail_autosend_owner_v1($pdo, $user);
        $row = agent_connection_gmail_autosend_connection_v1($pdo, $owner, $connection);
        if (!$row) return $view;
        $policy = agent_connection_gmail_autosend_policy_v1($pdo, $owner, $connection, $row['authorization_key']);
        $view['available'] = agent_connection_gmail_autosend_granted_v1($row);
        return $policy === null ? $view : array_replace($view, $policy);
    } catch (AgentConnectionExceptionV1 | PDOException | JsonException) {
        return $view;
    }
}

function agent_connection_gmail_autosend_save_v1(PDO $pdo, array $user, array $input): void
{
    if (!agent_connection_gmail_autosend_ready_v1($pdo)) throw new AgentConnectionExceptionV1('Gmail Auto Send is unavailable.', 503);
    $connection = $input['connection_id'] ?? null;
    $grantRevision = $input['connection_revision'] ?? null;
    $policyRevision = $input['autosend_revision'] ?? null;
    $enabled = ($input['enabled'] ?? null) === '1';
    if (!is_string($connection) || !preg_match('/\A[1-9][0-9]{0,17}\z/D', $connection)
        || !is_string($grantRevision) || !preg_match('/\A[a-f0-9]{64}\z/D', $grantRevision)
        || !is_string($policyRevision) || !preg_match('/\A(?:[a-f0-9]{32})?\z/D', $policyRevision)
        || (array_key_exists('enabled', $input) && !$enabled)) {
        throw new AgentConnectionExceptionV1('Gmail Auto Send settings could not be verified.', 422);
    }
    $connectionId = (int)$connection;
    $addresses = agent_connection_gmail_autosend_addresses_v1($input['addresses'] ?? null);
    if ($enabled && !$addresses) throw new AgentConnectionExceptionV1('Add an approved email address before enabling Auto Send.', 422);
    agent_commerce_atomic_pa2($pdo, static function () use ($pdo, $user, $connectionId, $grantRevision, $policyRevision, $enabled, $addresses): void {
        $owner = agent_connection_gmail_autosend_owner_v1($pdo, $user);
        $row = agent_connection_gmail_autosend_connection_v1($pdo, $owner, $connectionId, true);
        if (!$row || !hash_equals(hash('sha256', $row['authorization_key']), $grantRevision)) {
            throw new AgentConnectionExceptionV1('The Gmail connection changed. Reopen Auto Send settings.', 409);
        }
        // The send ledger locks the connection before the owner and policy.
        agent_connection_gmail_autosend_owner_v1($pdo, $user, true);
        $grant = $row['authorization_key'];
        $policy = agent_connection_gmail_autosend_policy_v1($pdo, $owner, $connectionId, $grant, true);
        if (!hash_equals($policy['revision'] ?? '', $policyRevision)) {
            throw new AgentConnectionExceptionV1('Auto Send settings changed. Reopen them before saving.', 409);
        }
        if ($enabled && !agent_connection_gmail_autosend_granted_v1($row)) {
            throw new AgentConnectionExceptionV1('Gmail read and send permissions are required for Auto Send.', 403);
        }
        // Keep the revision stable for a save that changes no approval.
        if ($policy && $policy['enabled'] === $enabled && $policy['addresses'] === $addresses) return;
        $values = [bin2hex(random_bytes(16)), (int)$enabled, json_encode($addresses, JSON_THROW_ON_ERROR)];
        if ($policy !== null) {
            $stmt = $pdo->prepare('UPDATE agent_connection_gmail_autosend_v1 SET revision=?,enabled=?,addresses_json=?
                WHERE owner_user_id=? AND connection_id=? AND authorization_key=?');
            $stmt->execute([...$values, $owner, $connectionId, $grant]);
        } else {
            // A legacy stale grant can never provide approval to the replacement.
            $pdo->prepare('DELETE FROM agent_connection_gmail_autosend_v1 WHERE owner_user_id=?')->execute([$owner]);
            $pdo->prepare('INSERT INTO agent_connection_gmail_autosend_v1
                (owner_user_id,connection_id,authorization_key,revision,enabled,addresses_json) VALUES (?,?,?,?,?,?)')
                ->execute([$owner, $connectionId, $grant, ...$values]);
        }
    });
}

/** Check before any paid draft and again at the actual Gmail dispatch. */
function agent_connection_gmail_autosend_authorize_v1(PDO $pdo, array $user, int $connectionId, string $grant, string $recipient, ?string $expectedRevision = null): string
{
    $owner = agent_connection_gmail_autosend_owner_v1($pdo, $user);
    if (!agent_connection_gmail_autosend_ready_v1($pdo)) throw new AgentConnectionExceptionV1('Gmail Auto Send is unavailable.', 503);
    $lock = $pdo->inTransaction();
    try {
        $row = agent_connection_gmail_autosend_connection_v1($pdo, $owner, $connectionId, $lock);
        if (!$row || !hash_equals($row['authorization_key'], $grant) || !agent_connection_gmail_autosend_granted_v1($row)) {
            throw new AgentConnectionExceptionV1('Gmail access changed. Reopen the message before replying.', 409);
        }
        $policy = agent_connection_gmail_autosend_policy_v1($pdo, $owner, $connectionId, $grant, $lock);
    } catch (PDOException $error) {
        if ((int)($error->errorInfo[1] ?? 0) !== 1020) throw $error;
        throw new AgentConnectionExceptionV1('Auto Send settings changed. Reopen the message before replying.', 409, $error);
    }
    if ($expectedRevision !== null && !hash_equals($policy['revision'] ?? '', $expectedRevision)) {
        throw new AgentConnectionExceptionV1('Auto Send settings changed. Reopen the message before replying.', 409);
    }
    $address = agent_connection_gmail_sender_v1($recipient);
    if (!$policy || !$policy['enabled'] || $address === '' || !in_array($address, $policy['addresses'], true)) {
        throw new AgentConnectionExceptionV1('Auto Send is off or this address is not approved. Use Draft reply to review the message.', 403);
    }
    return $policy['revision'];
}

function agent_connection_gmail_autosend_eligible_v1(PDO $pdo, array $user, int $connectionId, string $grant, string $recipient): bool
{
    try {
        agent_connection_gmail_autosend_authorize_v1($pdo, $user, $connectionId, $grant, $recipient);
        return true;
    } catch (AgentConnectionExceptionV1 | PDOException | JsonException) {
        return false;
    }
}

function agent_connection_gmail_autosend_revoke_v1(PDO $pdo, int $owner, int $connection, string $grant): void
{
    if (!agent_connection_gmail_autosend_ready_v1($pdo)) return;
    $pdo->prepare('DELETE FROM agent_connection_gmail_autosend_v1 WHERE owner_user_id=? AND connection_id=? AND authorization_key=?')
        ->execute([$owner, $connection, $grant]);
}
