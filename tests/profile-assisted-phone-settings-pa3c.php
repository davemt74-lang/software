<?php
declare(strict_types=1);

define('PA3_HANDOFF_HELPERS_ONLY', true);
require __DIR__.'/profile-handoff-pa3.php';
require_once dirname(__DIR__).'/includes/chat-settings-v237.php';

pa3_reset();
$owner = pa3_owner();
$foreign = pa3_owner(4);
$setting = 'agent_assisted_phone_chat_enabled';
$pdo->exec('ALTER TABLE team_user_presence ADD COLUMN agent_voice_enabled TINYINT(1) NOT NULL DEFAULT 1');
$pdo->exec('ALTER TABLE team_user_presence ADD COLUMN last_seen_at DATETIME');
$pdo->exec('ALTER TABLE team_user_presence ADD COLUMN updated_at DATETIME');
$pdo->exec('INSERT INTO team_user_presence (user_id) VALUES (1)');

pa3_check(!chat_settings_agent_assisted_phone_chat_schema_ready_v237($pdo), 'legacy settings lack the opt-in column');
pa3_check(chat_settings_get_v237($pdo, 1)[$setting] === false && !chat_settings_agent_assisted_phone_chat_enabled_v237($pdo, 1), 'unupgraded settings remain usable and assisted calls fail closed');
pa3_check(chat_settings_save_v237($pdo, $owner, ['sound_enabled'=>false])['sound_enabled'] === false && !column_exists('team_user_presence', $setting), 'an old client saves without initializing or enabling assisted calls');
try {
    chat_settings_save_v237($pdo, $owner, [$setting=>true]);
    throw new LogicException('An explicit opt-in before upgrade was accepted');
} catch (RuntimeException $error) {
    pa3_check(str_contains($error->getMessage(), '/upgrade.php'), 'explicit opt-in before upgrade returns the upgrade message');
}

chat_settings_ensure_schema_v237($pdo);
chat_settings_ensure_schema_v237($pdo);
pa3_check(chat_settings_agent_assisted_phone_chat_schema_ready_v237($pdo), 'the existing installer adds the setting idempotently');
pa3_check(!chat_settings_agent_assisted_phone_chat_enabled_v237($pdo, 1) && chat_settings_get_v237($pdo, 1)['sound_enabled'] === false, 'upgrade keeps the existing account off and preserves other preferences');
pa3_check(chat_settings_get_v237($pdo, 4)[$setting] === false, 'an account with no settings row defaults off');

$saved = chat_settings_save_v237($pdo, $owner, [$setting=>true]);
pa3_check($saved[$setting] === true && chat_settings_agent_assisted_phone_chat_enabled_v237($pdo, 1), 'the authorized owner explicitly opts in');
pa3_check(!chat_settings_agent_assisted_phone_chat_enabled_v237($pdo, 4), 'another account cannot inherit the owner opt-in');
chat_settings_save_v237($pdo, $foreign, ['sound_enabled'=>true]);
pa3_check(!chat_settings_agent_assisted_phone_chat_enabled_v237($pdo, 4), 'a legacy insertion uses the off schema default');
chat_settings_save_agent_voice_v237($pdo, $owner, false);
chat_settings_save_v237($pdo, $owner, ['presence_mode'=>'offline']);
pa3_check(chat_settings_agent_assisted_phone_chat_enabled_v237($pdo, 1) && chat_settings_get_v237($pdo, 1)['presence_mode'] === 'offline', 'Agent Voice and Online/Offline updates preserve the separate opt-in');

foreach ([null, 'true', 'false', 2, []] as $invalid) {
    try {
        chat_settings_save_v237($pdo, $owner, [$setting=>$invalid]);
        throw new LogicException('An invalid value changed the assisted call setting');
    } catch (RuntimeException $error) {
        pa3_check(str_contains($error->getMessage(), 'Choose On or Off'), 'rejects invalid opt-in value '.json_encode($invalid));
    }
}
$pdo->exec("DELETE FROM role_permissions WHERE permission_key='profile_chat.access'");
pa3_check(!chat_settings_agent_assisted_phone_chat_allowed_v237($pdo, $owner), 'revoked Profile Chat permission prohibits enabling');
try {
    chat_settings_save_v237($pdo, $foreign, [$setting=>true]);
    throw new LogicException('A permission-denied opt-in was accepted');
} catch (RuntimeException $error) {
    pa3_check(str_contains($error->getMessage(), 'not available for this account') && !chat_settings_agent_assisted_phone_chat_enabled_v237($pdo, 4), 'storage enforces permission before enabling');
}
pa3_check(chat_settings_save_v237($pdo, $owner, [$setting=>false])[$setting] === false && !chat_settings_agent_assisted_phone_chat_enabled_v237($pdo, 1), 'an owner can turn off assistance after permission is revoked');

$pdo->exec('UPDATE team_user_presence SET agent_assisted_phone_chat_enabled=2 WHERE user_id=1');
pa3_check(!chat_settings_agent_assisted_phone_chat_enabled_v237($pdo, 1) && chat_settings_get_v237($pdo, 1)[$setting] === false, 'malformed storage is treated as off');
$pdo->failSql = 'SELECT agent_assisted_phone_chat_enabled';
pa3_check(!chat_settings_agent_assisted_phone_chat_enabled_v237($pdo, 1, true), 'storage read failure fails closed outside a transaction');
$pdo->failSql = '';

echo 'PROFILE_ASSISTED_PHONE_SETTINGS_PA3C=PASS ('.$checks.' checks; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
