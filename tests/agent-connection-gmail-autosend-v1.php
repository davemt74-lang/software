<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-connection-gmail-autosend-v1.php';

function db(): PDO { return $GLOBALS['pdo']; }
function setting(string $key, mixed $fallback = ''): mixed { return $fallback; }
function url(string $path): string { return $path; }
function table_exists(string $table): bool
{
    $query = db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $query->execute([$table]);
    return (bool)$query->fetchColumn();
}
function has_permission(string $permission, array $user): bool
{
    return !empty($user['is_active']) && ($permission === 'account.access' || ($permission === 'chat.access' && !empty($user['chat_allowed'])));
}
function vp3_participant_scope_v1(): ?array { return $GLOBALS['participant'] ?? null; }
function autosend_check(bool $passed, string $message): void
{
    if (!$passed) throw new RuntimeException($message);
    $GLOBALS['checks']++;
    echo 'PASS '.$message.PHP_EOL;
}
function autosend_reject(int $code, callable $work, string $message): void
{
    try { $work(); }
    catch (AgentConnectionExceptionV1 $error) {
        autosend_check($error->getCode() === $code, $message);
        return;
    }
    throw new RuntimeException($message);
}

$dsn = (string)getenv('VP3_TEST_MYSQL_DSN');
if (!str_starts_with($dsn, 'mysql:')) throw new RuntimeException('A native MySQL acceptance DSN is required.');
$login = getenv('VP3_TEST_MYSQL_USER') ?: 'root';
$password = (string)getenv('VP3_TEST_MYSQL_PASS');
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false];
$server = new PDO($dsn, $login, $password, $options);
$database = 'vp3_autosend_fixture_'.bin2hex(random_bytes(6));
$fixtureRoot = sys_get_temp_dir().'/vp3-autosend-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT', $fixtureRoot);
mkdir($fixtureRoot, 0700);
putenv('VP3_GMAIL_CLIENT_ID=vp3-autosend-fixture.apps.googleusercontent.com');
putenv('VP3_GMAIL_CLIENT_SECRET=autosend-fixture-only');
$checks = 0;

try {
    $server->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $fixtureDsn = preg_replace('/;dbname=[^;]+/', '', $dsn).';dbname='.$database;
    $pdo = new PDO($fixtureDsn, $login, $password, $options);
    $concurrent = new PDO($fixtureDsn, $login, $password, $options);
    $pdo->exec('CREATE TABLE settings (setting_key VARCHAR(190) PRIMARY KEY, setting_value TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY, is_active TINYINT NOT NULL, chat_allowed TINYINT NOT NULL)');
    $pdo->exec('CREATE TABLE chat_conversations (id BIGINT UNSIGNED PRIMARY KEY, user_id INT UNSIGNED NOT NULL, user_agent_id BIGINT UNSIGNED NULL)');
    $pdo->exec('INSERT INTO users VALUES (1,1,1),(2,1,1)');
    autosend_check(!agent_connection_gmail_autosend_ready_v1($pdo), 'Auto Send fails closed before upgrade');
    agent_connections_install_v1($pdo);
    agent_connections_install_v1($pdo);
    $owner = ['id'=>1];
    $other = ['id'=>2];
    $permissions = ['read'=>true, 'send'=>true, 'followups'=>false];
    $fingerprint = agent_connections_client_fingerprint_v1();
    $token = static fn(int $id): array => ['external_account_id'=>'fixture-'.$id, 'account_email'=>'owner'.$id.'@example.test',
        'access_token'=>'fixture-access-'.$id, 'refresh_token'=>'fixture-refresh-'.$id, 'expires_in'=>3600];
    agent_connections_store_gmail_v1(1, $token(1), $permissions, '', $fingerprint);
    agent_connections_store_gmail_v1(2, $token(2), $permissions, '', $fingerprint);
    $connection = agent_connections_find_v1($pdo, 1, 'gmail');
    $otherConnection = agent_connections_find_v1($pdo, 2, 'gmail');
    $id = (int)$connection['id'];
    $grant = $connection['authorization_key'];
    $otherId = (int)$otherConnection['id'];
    $otherGrant = $otherConnection['authorization_key'];
    $address = 'buyer+vp3@example.test';
    $view = static fn(): array => agent_connection_gmail_autosend_settings_v1($pdo, $owner, $id);
    $eligible = static fn(string $target): bool => agent_connection_gmail_autosend_eligible_v1($pdo, $owner, $id, $grant, $target);
    $form = static fn(string $revision, array $extra = []): array => $extra + ['connection_id'=>(string)$id,
        'connection_revision'=>hash('sha256', $grant), 'autosend_revision'=>$revision, 'enabled'=>'1', 'addresses'=>$address];
    $save = static fn(string $revision, array $extra = []) => agent_connection_gmail_autosend_save_v1($pdo, $owner, $form($revision, $extra));

    autosend_check($view() === ['ready'=>true, 'available'=>true, 'enabled'=>false, 'addresses'=>[], 'revision'=>'']
        && !$eligible($address), 'connecting Gmail and granting send permission leave Auto Send off');
    foreach (['', 'example.test', '*@example.test', 'b*@example.test', 'Buyer <buyer@example.test>',
        "buyer@example.test, other@example.test", "buyer@example.test\0", implode("\n", array_fill(0, 21, $address))] as $input) {
        autosend_reject(422, static fn() => $save('', ['addresses'=>$input]), 'Auto Send rejects non-exact, oversized, or missing addresses');
    }
    $save('', ['addresses'=>"BUYER+VP3@example.test\r\n$address\n Other@Example.test "]);
    $first = $view();
    autosend_check($first['enabled'] && $first['addresses'] === [$address, 'other@example.test']
        && $eligible('BUYER+VP3@EXAMPLE.TEST') && !$eligible('buyer@example.test') && !$eligible('buyer+vp3@sub.example.test')
        && !$eligible('buyer+vp30@example.test') && !$eligible('Buyer <buyer+vp3@example.test>'),
        'only normalized exact mailboxes match, with case normalization and no alias expansion');
    autosend_check(!agent_connection_gmail_autosend_eligible_v1($pdo, $other, $id, $grant, $address)
        && !agent_connection_gmail_autosend_eligible_v1($pdo, $other, $otherId, $otherGrant, $address)
        && agent_connection_gmail_autosend_settings_v1($pdo, $other, $id)['addresses'] === [],
        'another account cannot read or use the owner’s allowlist');
    $participant = ['id'=>10];
    autosend_check(!$eligible($address) && $view()['addresses'] === [], 'a participant cannot read or use Auto Send settings');
    $participant = null;

    $rev = agent_connection_gmail_autosend_authorize_v1($pdo, $owner, $id, $grant, $address);
    $save($rev, ['addresses'=>"$address\nother@example.test"]);
    autosend_check($view()['revision'] === $rev, 'an unchanged save keeps existing approval references current');
    autosend_reject(409, static fn() => $save(''), 'a stale form cannot replace current settings');

    $pdo->beginTransaction();
    $pdo->query('SELECT revision FROM agent_connection_gmail_autosend_v1 WHERE owner_user_id=1')->fetchColumn();
    agent_connection_gmail_autosend_save_v1($concurrent, $owner, $form($rev));
    autosend_reject(409, static fn() => agent_connection_gmail_autosend_authorize_v1($pdo, $owner, $id, $grant, $address, $rev),
        'the final locking check detects settings changed after the transaction’s initial snapshot');
    $pdo->rollBack();

    $disable = $form($view()['revision']);
    unset($disable['enabled']);
    agent_connection_gmail_autosend_save_v1($pdo, $owner, $disable);
    autosend_check(!$view()['enabled'] && $view()['addresses'] === [$address] && !$eligible($address),
        'turning off Auto Send keeps the owner’s list and immediately blocks authorization');
    $save($view()['revision']);
    $pdo->exec('UPDATE users SET chat_allowed=0 WHERE id=1');
    autosend_check(!$eligible($address), 'the current Chat permission is required even for a previously approved address');
    $pdo->exec('UPDATE users SET chat_allowed=1 WHERE id=1');

    foreach (['read','send'] as $permission) {
        autosend_check(agent_connections_update_policy_v1(1, $id, [$permission=>false] + $permissions, $grant)
            && agent_connections_update_policy_v1(1, $id, $permissions, $grant)
            && !$eligible($address) && $view()['addresses'] === [],
            'removing '.$permission.' permission retires the existing approval even if the permission is restored');
        $save('');
    }
    agent_connections_store_gmail_v1(1, $token(1), $permissions, $grant, $fingerprint);
    $replacement = agent_connections_find_v1($pdo, 1, 'gmail');
    autosend_check($replacement['authorization_key'] !== $grant && !$eligible($address) && !$view()['enabled']
        && $view()['addresses'] === [], 'reconnecting the same mailbox cannot inherit the old Auto Send policy');
    autosend_reject(409, static fn() => $save(''), 'a form for the replaced grant cannot enable Auto Send');
    agent_connection_gmail_autosend_save_v1($pdo, $owner, $form('', ['connection_revision'=>hash('sha256', $replacement['authorization_key'])]));
    autosend_check(agent_connections_disconnect_v1(1, $id, 'gmail', $replacement['authorization_key'])
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_connection_gmail_autosend_v1')->fetchColumn() === 0,
        'disconnect purges the saved Auto Send approval');
    echo 'AGENT_CONNECTION_GMAIL_AUTOSEND=PASS ('.$checks.' checks; native MySQL)'.PHP_EOL;
} finally {
    $server->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    foreach (['ai-key.php','ai-key.php.lock'] as $file) {
        if (is_file($fixtureRoot.'/private/'.$file)) unlink($fixtureRoot.'/private/'.$file);
    }
    if (is_dir($fixtureRoot.'/private')) rmdir($fixtureRoot.'/private');
    rmdir($fixtureRoot);
}
