import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');
const read = rel => fs.readFileSync(path.join(root, rel), 'utf8');

const notifications = read('includes/notifications.php');
const chatApi = read('api/chat-v236.php');
const settings = read('includes/chat-settings-v237.php');
const settingsApi = read('api/chat-settings-v237.php');
const settingsJs = read('chat-settings-v237.js');
const attentionJs = read('chat-notifications-drawer-v240.js');
const memberMenu = read('includes/member-user-menu.php');

assert.match(settings, /'agent_voice_enabled'\s*=>\s*true/, 'Agent Voice must have a persisted default');
assert.match(settings, /agent_voice_enabled/, 'Agent Voice must live in canonical Chat Settings storage');
assert.match(settingsApi, /save_agent_voice/, 'Agent Voice must support a focused settings mutation');
assert.match(memberMenu, /data-agent-voice-toggle/, 'shared member menu exposes Agent Voice toggle');
assert.match(settingsJs, /data-agent-voice-toggle/, 'Chat settings runtime owns Agent Voice toggle behavior');
assert.match(settingsJs, /stonefellow:agent-voice/, 'Agent Voice changes are broadcast to active Chat runtime');
assert.match(attentionJs, /agentVoiceEnabled/, 'attention speech must honor Agent Voice preference');
assert.match(attentionJs, /if \(!agentVoiceEnabled\(\)\) return/, 'voice-off attention stays visual without speaking');
assert.match(notifications, /created_at/, 'attention notification retains its source event timestamp');
assert.match(chatApi, /ORDER BY created_at DESC,id DESC LIMIT 300/, 'conversation loads are timestamp ordered');
assert.match(chatApi, /array_reverse\(\$stmt->fetchAll\(\)\)/, 'loaded messages remain oldest-to-newest in the canvas');

console.log('profile-agent-chat-order-voice contract: PASS');
