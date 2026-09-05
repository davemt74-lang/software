import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');
const read = rel => fs.readFileSync(path.join(root, rel), 'utf8');

const settingsPhp = read('includes/chat-settings-v237.php');
const settingsApi = read('api/chat-settings-v237.php');
const settingsUi = read('chat-settings-v237.js');
const settingsCss = read('chat-settings-v237.css');
const teamApi = read('api/team-chat-v109.php');
const teamJs = read('team-chat-v109.js');
const widget = read('includes/team-chat-widget-v81.php');
const upgrade = read('upgrade.php');
const bootstrap = read('includes/bootstrap.php');

assert.match(settingsPhp, /presence_mode/);
assert.match(settingsPhp, /social_chat_enabled/);
assert.match(settingsPhp, /sound_enabled/);
assert.match(settingsPhp, /ALTER TABLE team_user_presence/);
assert.match(settingsPhp, /ON DUPLICATE KEY UPDATE/);
assert.match(bootstrap, /chat-settings-v237\.php/);
assert.match(upgrade, /chat_settings_schema_ready_v237/);
assert.match(upgrade, /chat_settings_ensure_schema_v237/);

assert.match(settingsApi, /save_chat/);
assert.match(settingsApi, /save_profile_agent/);
assert.match(settingsApi, /profile_configure_agent/);
assert.match(settingsApi, /profile_runtime_owner_state/);

assert.match(settingsUi, /chatSettingsLauncher/);
assert.match(settingsUi, /Chat Settings/);
assert.match(settingsUi, /presence_mode/);
assert.match(settingsUi, /Allow user-to-user chat/);
assert.match(settingsUi, /Incoming message sound/);
assert.match(settingsUi, /Accept Profile Agent conversations/);
assert.match(settingsUi, /chatNowPlayingClose/);
assert.match(settingsUi, /Close audio player/);
assert.match(settingsUi, /ensureNotificationNextToProfile/);
assert.match(settingsUi, /notification\.nextElementSibling !== profile/);
assert.match(settingsUi, /actions\.insertBefore\(notification, profile\)/);
assert.match(settingsCss, /chat-now-playing-close/);
assert.match(settingsCss, /chat-settings-modal/);

assert.match(widget, /chat-settings-v237\.css/);
assert.match(widget, /chat-settings-v237\.js/);
assert.match(widget, /soundEnabled/);
assert.match(widget, /socialChatEnabled/);

assert.match(teamApi, /chat_settings_get_v237/);
assert.match(teamApi, /social_chat_disabled/);
assert.match(teamApi, /COALESCE\(p\.presence_mode,'online'\)='online'/);
assert.match(teamApi, /if \(\$since < 1\)[\s\S]*?\$messages = \[\]/);

assert.match(teamJs, /AudioContext/);
assert.match(teamJs, /playIncomingSound/);
assert.match(teamJs, /const incoming = Number\(message\.sender_id\) !== Number\(cfg\.userId\)/);
assert.match(teamJs, /const shouldNotify = incoming && messageId > 0 && !seen\.has\(messageId\)/);
assert.match(teamJs, /if \(notifyIncoming\) playIncomingSound\(\)/);
assert.match(teamJs, /stonefellow:chat-settings-updated/);
assert.match(teamJs, /sound_enabled/);
assert.match(teamJs, /social_chat_enabled/);

console.log('chat-settings-v237 contract: PASS');
