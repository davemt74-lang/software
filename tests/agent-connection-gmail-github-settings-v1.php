<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/agent-connection-gmail-github-settings-v1.php';
require_once dirname(__DIR__).'/includes/agent-connection-gmail-github-v1.php';
require_once dirname(__DIR__).'/includes/agent-connection-gmail-github-notice-v1.php';

final class GitHubSettingsFixturePDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace(' FOR UPDATE','',$query),$options);
    }
}

function db(): PDO { return $GLOBALS['pdo']; }
function setting(string $key, mixed $default = ''): mixed { return $default; }
function table_exists(string $table): bool
{
    $query = db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $query->execute([$table]);
    return (bool)$query->fetchColumn();
}
function has_permission(string $permission, array $user): bool
{
    return !empty($user['is_active']) && ($permission === 'account.access' || !empty($user['chat_allowed']));
}
function personal_capability_has_v242(string $permission, array $user): bool
{
    return $permission === 'personal_knowledge.access' || ($permission === 'personal_knowledge.manage' && !empty($user['knowledge_manage']));
}
function github_settings_check(bool $passed, string $label): void
{
    if (!$passed) throw new RuntimeException($label);
    $GLOBALS['checks']++;
    echo 'PASS '.$label.PHP_EOL;
}
function github_settings_reject(int $status, callable $operation, string $label): void
{
    try { $operation(); }
    catch (AgentConnectionExceptionV1 $error) {
        github_settings_check($error->getCode() === $status,$label);
        return;
    }
    throw new RuntimeException('Expected rejection: '.$label);
}

$checks = 0;
$root = sys_get_temp_dir().'/vp3-github-settings-'.bin2hex(random_bytes(8));
mkdir($root,0700);
define('STONEFELLOW_ROOT',$root);
putenv('VP3_GMAIL_CLIENT_ID=vp3-github-fixture.apps.googleusercontent.com');
putenv('VP3_GMAIL_CLIENT_SECRET=vp3-github-fixture-secret');

try {
    $pdo = new GitHubSettingsFixturePDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("PRAGMA foreign_keys=ON;
        CREATE TABLE users (id INTEGER PRIMARY KEY,is_active INTEGER,chat_allowed INTEGER,knowledge_manage INTEGER);
        INSERT INTO users VALUES (1,1,1,1),(2,1,1,1);
        CREATE TABLE settings (setting_key TEXT PRIMARY KEY,setting_value TEXT);
        CREATE TABLE agent_connections_v1 (id INTEGER PRIMARY KEY,owner_user_id INTEGER,provider TEXT,status TEXT,
            authorization_key TEXT,client_fingerprint TEXT,account_email TEXT,read_enabled INTEGER,access_expires_at INTEGER,
            access_token_enc TEXT,refresh_token_enc TEXT);
        CREATE TABLE agent_connection_sends_v1 (operation_key TEXT);
        CREATE TABLE agent_connection_email_drafts_v1 (authorization_key TEXT,message_enc TEXT);
        CREATE TABLE agent_connection_email_followups_v1 (claim_key TEXT);
        INSERT INTO agent_connection_email_followups_v1 VALUES ('explicit-watch');
        CREATE TABLE artist_transcript_folders_v177 (id INTEGER PRIMARY KEY AUTOINCREMENT,created_by_user_id INTEGER,
            folder_name TEXT COLLATE NOCASE,sort_order INTEGER DEFAULT 0,created_at TEXT,updated_at TEXT,UNIQUE(created_by_user_id,folder_name));
        INSERT INTO artist_transcript_folders_v177 (created_by_user_id,folder_name) VALUES (2,'Workflow Reviews');
        CREATE TABLE knowledge_items (id INTEGER PRIMARY KEY,folder_id INTEGER,knowledge_scope TEXT,created_by_user_id INTEGER);
        CREATE TABLE knowledge_chunks (knowledge_id INTEGER,chunk_index INTEGER,chunk_text TEXT);
        CREATE TABLE notifications (user_id INTEGER,type TEXT,source_type TEXT);");
    $grant = str_repeat('a',64);
    agent_connection_gmail_github_revoke_v1($pdo,1,7,$grant);
    github_settings_check(!agent_connection_gmail_github_ready_v1($pdo),'missing policy schema is safe before upgrade');
    agent_connection_gmail_github_install_v1($pdo);
    agent_connection_gmail_github_install_v1($pdo);
    agent_connection_gmail_github_receipts_install_v1($pdo);
    $token = ai_encrypt_secret('fixture-only-token');
    $pdo->prepare("INSERT INTO agent_connections_v1 VALUES (7,1,'gmail','connected',?,?,?,1,?,?,'')")
        ->execute([$grant,agent_connections_client_fingerprint_v1(),'owner@example.test',time()+3600,$token]);
    $owner = ['id'=>1,'is_active'=>1,'chat_allowed'=>1,'knowledge_manage'=>1];
    $other = ['id'=>2,'is_active'=>1,'chat_allowed'=>1,'knowledge_manage'=>1];
    $base = ['connection_id'=>7,'connection_revision'=>hash('sha256',$grant),'github_revision'=>'','action'=>'enable'];
    $named = ['folder_name'=>'Workflow Reviews'];
    $policy = static fn(): ?array => agent_connection_gmail_github_policy_v1($pdo,1);
    $state = static fn(): array => agent_connection_gmail_github_state_v1($pdo,$owner);
    $requests = 0;
    $profileEmail = 'different@example.test';
    $cursor = '18446744073709551615';
    $http = static function (string $method,string $url,array $headers,?array $body,bool $form) use ($pdo,&$requests,&$profileEmail,&$cursor): array {
        if ($method !== 'GET' || $url !== VP3_GMAIL_API_V1.'/profile?fields=emailAddress,historyId' || $pdo->inTransaction()
            || !in_array('Authorization: Bearer fixture-only-token',$headers,true) || $body !== null || $form) {
            throw new RuntimeException('Unexpected Gmail provider request.');
        }
        $requests++;
        return ['status'=>200,'json'=>['emailAddress'=>$profileEmail,'historyId'=>$cursor]];
    };
    github_settings_reject(503,static fn()=>agent_connection_gmail_github_save_v1($pdo,$owner,$base+$named,$http),
        'missing voice storage blocks activation before a provider request');
    agent_connection_gmail_github_actions_voice_install_v1($pdo);
    github_settings_check($policy() === null && $state()['available'] && !$state()['enabled'] && $state()['folders'] === [],
        'new owner starts off, without an invented default or another owner’s folders');
    github_settings_reject(409,static fn()=>agent_connection_gmail_github_save_v1($pdo,$other,$base+$named,$http),
        'another owner cannot use this connection revision');
    github_settings_reject(403,static fn()=>agent_connection_gmail_github_save_v1($pdo,array_replace($owner,['knowledge_manage'=>0]),$base+$named,$http),
        'Personal Knowledge management is required for activation');
    github_settings_reject(422,static fn()=>agent_connection_gmail_github_save_v1($pdo,$owner,$base+['folder_id'=>1],$http),
        'the destination cannot be another owner’s folder');
    github_settings_reject(422,static fn()=>agent_connection_gmail_github_save_v1($pdo,$owner,$base+['folder_name'=>"bad\nname"],$http),
        'destination names reject control characters');
    github_settings_reject(401,static fn()=>agent_connection_gmail_github_save_v1($pdo,$owner,$base+$named,$http),
        'a mismatched provider mailbox cannot activate the skill');
    github_settings_check($requests === 1 && $policy() === null && $state()['folders'] === [],
        'failed baseline leaves no policy or orphan Knowledge folder');

    $profileEmail = 'owner@example.test';
    github_settings_check(agent_connection_gmail_github_save_v1($pdo,$owner,$base+$named,$http) === 'github_enabled'
        && $requests === 2 && $policy()['history_id'] === $cursor && $state()['enabled'] && $state()['folder_name'] === 'Workflow Reviews'
        && !array_key_exists('authorization_key',$state()) && !array_key_exists('history_id',$state()),
        'verified profile initializes an exact independent cursor and private canonical destination');
    github_settings_reject(409,static fn()=>agent_connection_gmail_github_save_v1($pdo,$owner,$base+$named,$http),
        'stale policy revision rejects before rereading Gmail');

    $firstFolder = (int)$policy()['folder_id'];
    github_settings_check($firstFolder !== 1 && agent_commerce_atomic_pa2($pdo,
        static fn()=>agent_connection_gmail_github_folder_named_v1($pdo,$owner,'Workflow Reviews')) === $firstFolder,
        'the same exact name reuses the current owner’s canonical folder only');
    github_settings_reject(422,static fn()=>agent_commerce_atomic_pa2($pdo,
        static fn()=>agent_connection_gmail_github_folder_named_v1($pdo,$owner,'workflow reviews')),
        'a similar name cannot silently pick a different canonical destination');
    $base['github_revision'] = $policy()['revision'];
    $restart = array_replace($base,['action'=>'restart']);
    $cursor = '18446744073709551616';
    github_settings_check(agent_connection_gmail_github_save_v1($pdo,$owner,$restart,$http) === 'github_restarted'
        && $requests === 3 && (int)$policy()['folder_id'] === $firstFolder && $policy()['history_id'] === $cursor,
        'restart keeps the exact existing folder and begins from the new baseline');
    $pdo->prepare('DELETE FROM artist_transcript_folders_v177 WHERE id=?')->execute([$firstFolder]);
    $base['github_revision'] = $policy()['revision'];
    github_settings_check($state()['status'] === 'destination_unavailable' && $state()['folder_id'] === null,
        'removed folder is unavailable and does not silently point to another destination');
    github_settings_reject(422,static fn()=>agent_connection_gmail_github_save_v1($pdo,$owner,array_replace($base,['action'=>'restart']),$http),
        'restart cannot recreate a deleted folder');
    github_settings_check(agent_connection_gmail_github_save_v1($pdo,$owner,$base+$named,$http) === 'github_enabled'
        && $requests === 4 && (int)$policy()['folder_id'] !== $firstFolder,'explicit activation can select a new private destination');

    $pdo->exec("INSERT INTO knowledge_items VALUES (5,3,'personal',1);
        INSERT INTO agent_connection_gmail_github_receipts_v1 VALUES (1,'active'),(2,'other');
        INSERT INTO notifications VALUES (1,'agent_email_github_actions','agent_connection_gmail_github'),(1,'watch','watch');
        DROP TABLE agent_connection_gmail_github_voice_v1;");
    $base['github_revision'] = $policy()['revision'];
    github_settings_check(agent_connection_gmail_github_save_v1($pdo,$owner,array_replace($base,['action'=>'disable']),$http) === 'github_disabled'
        && $requests === 4 && !(int)$policy()['enabled'] && $policy()['history_id'] === '' && $policy()['claim_key'] === null
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_connection_gmail_github_receipts_v1')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM knowledge_items')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM agent_connection_email_followups_v1')->fetchColumn() === 1
        && (int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 1,
        'disable stops work and clears only skill activity even with missing voice storage');

    agent_connection_gmail_github_actions_voice_install_v1($pdo);
    $base['github_revision'] = $policy()['revision'];
    $revokingHttp = static function (...$args) use ($pdo,$http): array {
        $profile = $http(...$args);
        $pdo->exec('UPDATE users SET knowledge_manage=0 WHERE id=1');
        return $profile;
    };
    github_settings_reject(409,static fn()=>agent_connection_gmail_github_save_v1($pdo,$owner,$base+['folder_name'=>'Do not create'],$revokingHttp),
        'the live permission check after baseline blocks folder creation when access changes');
    github_settings_check(!(int)$policy()['enabled'] && (int)$pdo->query("SELECT COUNT(*) FROM artist_transcript_folders_v177
        WHERE created_by_user_id=1 AND folder_name='Do not create'")->fetchColumn() === 0,
        'a concurrent permission revocation leaves no partially created destination');
    echo 'GitHub Workflow Review settings: '.$checks.' checks passed'.PHP_EOL;
} finally {
    foreach (glob($root.'/private/*') ?: [] as $file) unlink($file);
    if (is_dir($root.'/private')) rmdir($root.'/private');
    rmdir($root);
}
