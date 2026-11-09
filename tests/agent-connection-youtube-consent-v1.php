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
$pdo->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
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
youtube_consent_check(!vp3_youtube_artist_research_enabled_v1($pdo,1), 'YouTube consent leaves background artist research off.');
vp3_youtube_artist_research_save_v1($pdo,1,true);
youtube_consent_check(vp3_youtube_artist_research_enabled_v1($pdo,1)&&!vp3_youtube_artist_research_enabled_v1($pdo,2),
    'Background artist research is an independent member preference.');
$pdo->exec("UPDATE agent_connection_youtube_consent_v1 SET policy_version='old-version' WHERE owner_user_id=1");
youtube_consent_check(!agent_connection_youtube_consent_v1($pdo, 1), 'Consent to an earlier policy cannot activate the skill.');
agent_connection_youtube_set_consent_v1($pdo, 1, true);
agent_connection_youtube_set_consent_v1($pdo, 2, true);
agent_connection_youtube_set_consent_v1($pdo, 1, false);
agent_connection_youtube_set_consent_v1($pdo, 1, false);
youtube_consent_check(!agent_connection_youtube_consent_v1($pdo, 1) && agent_connection_youtube_consent_v1($pdo, 2),
    'Disabling must remove consent for only that member.');
youtube_consent_check(!vp3_youtube_artist_research_enabled_v1($pdo,1), 'Disabling YouTube also revokes background artist research.');
$pdo->exec('DELETE FROM users WHERE id=2');
youtube_consent_check(!agent_connection_youtube_consent_v1($pdo, 2), 'Deleting an account must remove its consent.');

require_once dirname(__DIR__).'/includes/agent-connection-youtube-playback-v1.php';
$pdo->exec('CREATE TABLE chat_messages (id INTEGER PRIMARY KEY)');
$pdo->exec('INSERT INTO users (id) VALUES (2)');
agent_connection_youtube_results_schema_v1($pdo);
vp3_youtube_playback_install_v1($pdo);
agent_connection_youtube_set_consent_v1($pdo,1,true,true);
$capture = vp3_youtube_capture_key_v1($pdo,1);
youtube_consent_check(strlen($capture)===32 && vp3_youtube_capture_key_v1($pdo,2)==='',
    'The default selection enables playback recording atomically for the enabling member.');
agent_connection_youtube_set_consent_v1($pdo,2,true,false);
youtube_consent_check(agent_connection_youtube_consent_v1($pdo,2) && vp3_youtube_capture_key_v1($pdo,2)==='',
    'Unchecking the default still enables the search skill without recording.');
vp3_youtube_history_change_v1($pdo,1,'pause_history');
agent_connection_youtube_set_consent_v1($pdo,1,true,true);
youtube_consent_check(vp3_youtube_capture_key_v1($pdo,1)==='',
    'A repeated activation must not override a member’s pause.');
agent_connection_youtube_set_consent_v1($pdo,1,false);
$pdo->exec('DROP TABLE vp3_youtube_history_settings_v1');
try {
    agent_connection_youtube_set_consent_v1($pdo,1,true,true);
    throw new RuntimeException('Missing capture storage must fail the selected activation.');
} catch (DomainException) {
    youtube_consent_check(!agent_connection_youtube_consent_v1($pdo,1), 'A failed capture selection rolls back consent.');
}

echo "AGENT_CONNECTION_YOUTUBE_CONSENT_V1=PASS\n";
