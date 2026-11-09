<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/chat-settings-v237.php';

function table_exists(string $name): bool { return $name === 'team_user_presence'; }
function column_exists(string $table, string $column): bool
{
    return schema_metadata_column_exists($GLOBALS['pdo'], $table, $column);
}
function has_permission(string $permission, array $user): bool
{
    return in_array($permission, $user['permissions'], true);
}
function personal_capability_has_v242(string $capability, array $user): bool
{
    return $capability === 'voice_profile.access' && $user['voice'];
}
function vp3_participant_scope_v1(): ?array { return $GLOBALS['participant']; }
function check(bool $passed, string $reason): void
{
    if (!$passed) throw new RuntimeException($reason);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE team_user_presence (
    user_id INTEGER PRIMARY KEY, presence_mode TEXT NOT NULL DEFAULT 'online',
    social_chat_enabled INTEGER NOT NULL DEFAULT 1, sound_enabled INTEGER NOT NULL DEFAULT 1,
    agent_voice_enabled INTEGER NOT NULL DEFAULT 1,
    last_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
$owner = ['id'=>1, 'permissions'=>['account.access','chat.access'], 'voice'=>true];
$other = array_replace($owner, ['id'=>2]);
$participant = null;
require_once dirname(__DIR__).'/includes/schema-metadata.php';

check(!chat_settings_agent_auto_greet_enabled_v237($pdo, $owner), 'Old schema must read Auto Greet as off.');
chat_settings_ensure_schema_v237($pdo);
check(!chat_settings_agent_auto_greet_enabled_v237($pdo, $owner), 'New accounts must start with Auto Greet off.');
chat_settings_save_agent_voice_v237($pdo, $owner, false);
$settings = chat_settings_save_auto_greet_v237($pdo, $owner, true);
check($settings['auto_greet_enabled'] && !$settings['agent_voice_enabled'], 'Auto Greet must not enable Agent Voice.');
check(!chat_settings_agent_auto_greet_enabled_v237($pdo, $other), 'Another account inherited Auto Greet.');
chat_settings_save_agent_voice_v237($pdo, $owner, true);
check(chat_settings_agent_auto_greet_enabled_v237($pdo, $owner), 'Saving Agent Voice overwrote Auto Greet.');
$settings = chat_settings_save_agent_orb_shape_v237($pdo, $owner, 'particle_face');
check($settings['agent_orb_shape'] === 'particle_face' && $settings['auto_greet_enabled'] && $settings['agent_voice_enabled'],
    'Saving the orb style changed another preference.');
check(chat_settings_get_v237($pdo, 2)['agent_orb_shape'] === 'orbit', 'Another account inherited the orb style.');
foreach ([
    array_replace($owner, ['voice'=>false]),
    array_replace($owner, ['permissions'=>['chat.access']]),
] as $restricted) {
    check(!chat_settings_agent_auto_greet_enabled_v237($pdo, $restricted), 'An unauthorized account can activate Auto Greet.');
}
$participant = ['id'=>3];
check(!chat_settings_agent_auto_greet_enabled_v237($pdo, $owner), 'Participant context inherited Auto Greet.');
try {
    chat_settings_save_auto_greet_v237($pdo, $owner, false);
    throw new LogicException('Participant context changed the owner preference.');
} catch (RuntimeException $error) {
    check($error->getMessage() === 'Auto Greet is unavailable for this account.', 'Unexpected denial.');
}
$participant = null;
check(chat_settings_agent_auto_greet_enabled_v237($pdo, $owner), 'Denied update changed the saved value.');
check(!chat_settings_save_auto_greet_v237($pdo, $owner, false)['auto_greet_enabled'], 'Opt-out did not persist.');
check(chat_settings_get_v237($pdo, 1)['agent_orb_shape'] === 'particle_face', 'Opt-out replaced the saved orb style.');
echo "CHAT_AUTO_GREET_SETTINGS_V1=PASS\n";
