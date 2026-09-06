import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');
const read = rel => fs.readFileSync(path.join(root, rel), 'utf8');

const chatApi = read('api/chat-v236.php');
const attentionApi = read('api/chat-notifications-brain-v240.php');
const settings = read('includes/chat-settings-v237.php');
const settingsApi = read('api/chat-settings-v237.php');
const settingsJs = read('chat-settings-v237.js');
const attentionJs = read('chat-notifications-drawer-v240.js');
const memberMenu = read('includes/member-user-menu.php');
const memberNavigation = read('includes/member-navigation.php');
const memberHeader = read('includes/member-header.php');
const profileAgent = read('profile-agent.php');
const transcriptionCanvas = read('chat-transcription-canvas.js');

assert.match(settings, /'agent_voice_enabled'\s*=>\s*true/, 'Agent Voice must have a persisted default');
assert.match(settings, /agent_voice_enabled/, 'Agent Voice must live in canonical Chat Settings storage');
assert.match(settingsApi, /save_agent_voice/, 'Agent Voice must support a focused settings mutation');
assert.match(memberMenu, /member_agent_voice_toggle_html\(\$memberMenuUser\)/, 'shared member menu must render the canonical Agent Voice control');
assert.match(memberNavigation, /data-agent-voice-toggle/, 'canonical navigation helper must own the Agent Voice toggle markup');
assert.match(memberNavigation, /has_permission\('chat\.access',\s*\$user\)/, 'Agent Voice toggle must remain Chat-permission gated');
assert.match(settingsJs, /ensureAgentVoiceToggle/, 'legacy Main Feed profile menu receives the same Agent Voice control');
assert.match(settingsJs, /stonefellow:agent-voice/, 'Agent Voice changes are broadcast to active Chat runtime');
assert.match(attentionApi, /'agent_voice_enabled'=>member_agent_voice_enabled\(\$user\)/, 'Activity Center state must include persisted Agent Voice preference');
assert.match(attentionJs, /function agentVoiceEnabled\(/, 'attention speech must read Agent Voice preference');
assert.match(attentionJs, /if \(!agentVoiceEnabled\(\)\) return/, 'voice-off attention stays visual without speaking');
assert.match(attentionJs, /state = await request\('state'\)[\s\S]*agent_voice_enabled[\s\S]*agentVoicePreference = state\.agent_voice_enabled !== false/, 'Activity Center must apply persisted Agent Voice before polling');
assert.match(attentionJs, /refresh\(false\)\.finally\(startAttentionPolling\)/, 'initial attention polling waits for the voice preference state request');
assert.match(attentionJs, /function chatCanvasAvailable\(/, 'Activity Center can run on member pages without pretending they are Chat canvases');
assert.match(attentionJs, /if \(!chatCanvasAvailable\(\)\) return/, 'non-Chat pages must not retry Chat presentation forever');

assert.match(attentionApi, /\$notification\['created_at'\]/, 'attention persistence uses notification event time');
assert.match(attentionApi, /context_json,created_at\) VALUES/, 'attention messages persist their event timestamp');
assert.match(chatApi, /ORDER BY created_at DESC,id DESC LIMIT 300/, 'conversation loads are timestamp ordered');
assert.match(chatApi, /array_reverse\(\$stmt->fetchAll\(\)\)/, 'loaded messages remain oldest-to-newest in the canvas');

assert.match(profileAgent, /require __DIR__ \. '\/includes\/member-header\.php'/, 'Profile Agent uses the universal member header');
assert.doesNotMatch(profileAgent, /<header class="chat-topbar profile-agent-topbar">/, 'Profile Agent must not keep a second hand-built member topbar');
assert.match(memberHeader, /chat-notifications-drawer-v240\.js/, 'universal member header loads tabbed Activity Center');
assert.match(memberHeader, /chat-transcription-canvas\.js/, 'universal member header loads Transcription Activity');
assert.match(memberHeader, /STONEFELLOW_NOTIFICATION_DRAWER/, 'universal header configures notification UI');
assert.match(memberHeader, /has_permission\('chat\.access',\s*\$memberHeaderUser\)/, 'Chat-only member-header runtimes remain capability gated');
assert.doesNotMatch(transcriptionCanvas, /if\(!thread\)return/, 'Transcription Activity must work outside Main Feed');
assert.match(transcriptionCanvas, /if\(thread\)\{/, 'Chat-specific transcription observation remains guarded');

console.log('profile-agent-chat-order-voice contract: PASS');