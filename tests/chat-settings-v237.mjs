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
const teamApiCompat = read('api/team-chat-v109.php');
const teamApi = read('api/team-chat-v320.php');
const teamJs = read('team-chat-v109.js');
const widget = read('includes/team-chat-widget-v81.php');
const activity = read('agent-activity-v94.js');
const chatPhp = read('chat.php');
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
assert.match(settingsUi, /document\.body\.appendChild\(host\)/, 'Chat settings launcher must live outside the left sidebar');
assert.doesNotMatch(settingsUi, /sidebar\.appendChild\(host\)|chatSidebar/, 'Chat settings must not append its launcher to the left sidebar');
assert.match(settingsUi, /chat-settings-presence-dot/, 'compact launcher must expose the presence dot');
assert.match(settingsUi, /Choose whether other VP3 users see you as online\./, 'visible availability copy must use VP3 branding');
assert.match(settingsCss, /\.chat-settings-launcher\{[\s\S]*position:fixed;[\s\S]*right:10px;[\s\S]*bottom:12px;/, 'Chat settings launcher must sit at the bottom-right rail position');
assert.match(settingsCss, /\.chat-settings-button\[data-presence="online"\] \.chat-settings-presence-dot\{background:#22c55e\}/, 'online status must render as a green dot');
assert.match(settingsCss, /\.chat-settings-presence-label\{[\s\S]*clip:rect\(0,0,0,0\)/, 'Online/Offline text must be visually hidden');
assert.doesNotMatch(settingsCss, /#chatSidebar\{padding-bottom/, 'Chat settings must not reserve left-sidebar space');
assert.match(settingsCss, /chat-now-playing-close/);
assert.match(settingsCss, /chat-settings-modal/);

assert.doesNotThrow(() => new Function(activity), 'Agent Activity runtime must remain valid JavaScript');
assert.equal(activity.includes('window.STONEFELLOW_CHAT_SETTINGS='), false, 'Agent Activity must not bootstrap Chat Settings');
assert.equal(activity.includes('chat-settings-v237.js'), false, 'Agent Activity must not dynamically own Chat Settings assets');
assert.match(chatPhp, /window\.STONEFELLOW_CHAT_SETTINGS=/);
assert.match(chatPhp, /chat-settings-v237\.php/);
assert.match(chatPhp, /chat-settings-v237\.css\?v=/);
assert.match(chatPhp, /chat-settings-v237\.js\?v=/);
assert.equal(widget.includes('chat-settings-v237.css'), false, 'Team Chat widget must not own Agent Chat settings CSS');
assert.equal(widget.includes('chat-settings-v237.js'), false, 'Team Chat widget must not own Agent Chat settings JS');
assert.match(widget, /soundEnabled/);
assert.match(widget, /socialChatEnabled/);

// v109 remains the compatibility URL, while v320 is the canonical scoped Team Chat runtime.
// Preserve the original Chat Settings contract by following that delegation instead of
// requiring implementation details to remain duplicated in the compatibility shim.
assert.match(teamApiCompat, /require __DIR__\.'\/team-chat-v320\.php'/);
assert.match(teamApi, /chat_settings_get_v237/);
assert.match(teamApi, /social_chat_disabled/);
assert.match(teamApi, /COALESCE\(p\.presence_mode,'online'\)='online'/);
assert.match(teamApi, /\$messages=\[\];if\(\$since>0\)/);
assert.match(teamApi, /vp3_social_shared_workspace_v320/, 'Team Chat settings must operate inside the scoped workspace runtime');

assert.match(teamJs, /AudioContext/);
assert.match(teamJs, /playIncomingSound/);
assert.match(teamJs, /const incoming = Number\(message\.sender_id\) !== Number\(cfg\.userId\)/);
assert.match(teamJs, /const shouldNotify = incoming && messageId > 0 && !seen\.has\(messageId\)/);
assert.match(teamJs, /if \(notifyIncoming\) playIncomingSound\(\)/);
assert.match(teamJs, /stonefellow:chat-settings-updated/);
assert.match(teamJs, /sound_enabled/);
assert.match(teamJs, /social_chat_enabled/);

console.log('chat-settings-v237 contract: PASS');