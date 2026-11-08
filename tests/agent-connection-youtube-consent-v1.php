<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/agent-connection-youtube-consent-v1.php';

function youtube_consent_check(bool $ok, string $reason): void
{
    if (!$ok) throw new RuntimeException($reason);
}

$pdo = new PDO('sqlite::memory:');
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY)');
$pdo->exec('INSERT INTO users (id) VALUES (1), (2)');
youtube_consent_check(!agent_connection_youtube_consent_ready_v1($pdo) && !agent_connection_youtube_consent_v1($pdo, 1),
    'Missing consent storage must fail closed.');
agent_connection_youtube_consent_schema_v1($pdo);
agent_connection_youtube_consent_schema_v1($pdo);
youtube_consent_check(agent_connection_youtube_consent_ready_v1($pdo) && !agent_connection_youtube_consent_v1($pdo, 1),
    'Fresh installs must require member consent.');
agent_connection_youtube_set_consent_v1($pdo, 1, true);
agent_connection_youtube_set_consent_v1($pdo, 1, true);
youtube_consent_check(agent_connection_youtube_consent_v1($pdo, 1) && !agent_connection_youtube_consent_v1($pdo, 2),
    'Consent must be idempotent and scoped to a single member.');
$pdo->exec("UPDATE agent_connection_youtube_consent_v1 SET policy_version='old-version' WHERE owner_user_id=1");
youtube_consent_check(!agent_connection_youtube_consent_v1($pdo, 1), 'Consent to an earlier policy cannot activate the skill.');
agent_connection_youtube_set_consent_v1($pdo, 1, true);
agent_connection_youtube_set_consent_v1($pdo, 2, true);
agent_connection_youtube_set_consent_v1($pdo, 1, false);
agent_connection_youtube_set_consent_v1($pdo, 1, false);
youtube_consent_check(!agent_connection_youtube_consent_v1($pdo, 1) && agent_connection_youtube_consent_v1($pdo, 2),
    'Disabling must remove consent for only that member.');
$pdo->exec('DELETE FROM users WHERE id=2');
youtube_consent_check(!agent_connection_youtube_consent_v1($pdo, 2), 'Deleting an account must remove its consent.');

echo "AGENT_CONNECTION_YOUTUBE_CONSENT_V1=PASS\n";
