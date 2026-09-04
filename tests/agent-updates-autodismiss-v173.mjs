import fs from 'node:fs';
import assert from 'node:assert/strict';

const ui = fs.readFileSync('chat-agent-updates-autodismiss-v173.js', 'utf8');
const chat = fs.readFileSync('chat.php', 'utf8');
const chatLegacy = fs.readFileSync('chat-legacy-v108.php', 'utf8');
const endpoint = fs.readFileSync('api/ui-permissions-v187.php', 'utf8');

assert.match(ui, /agent-updates-hidden-v206-20260901/);
assert.match(ui, /function|const removeAgentUpdateOverlays/);
assert.match(ui, /#chatLiveUpdates/);
assert.match(ui, /\.chat-live-updates/);
assert.match(ui, /removeAgentUpdateOverlays\(\);/);
assert.match(ui, /new MutationObserver/);
assert.match(ui, /overlaysDisabled:true/);
assert.doesNotMatch(ui, /DISPLAY_MS/);
assert.doesNotMatch(ui, /RESUME_MS/);
assert.doesNotMatch(ui, /panel\.hidden = false/);
assert.doesNotMatch(ui, /pointerenter/);
assert.doesNotMatch(ui, /pointerleave/);
assert.match(chat, /agent-updates-hidden-v206-20260901/);
assert.match(chat, /chat-agent-updates-autodismiss-v173\.js\?v=' \s*\. \s*\$agentOverlayBuild/);
assert.match(chat, /#chatLiveUpdates,\.chat-live-updates/);

// The main notification system must remain server-rendered and untouched.
assert.match(chatLegacy, /notification_unread_count\(\$user\)/);
assert.match(chatLegacy, /notification_recent\(\$user, 6\)/);

// Chat dropdown profile-link recovery remains intact.
assert.match(chatLegacy, /class="chat-profile-links"/);
assert.match(chatLegacy, /\$chatArtistProfileUrl !== ''[\s\S]*View Artist Profile/);
assert.match(endpoint, /\$artistProfileUrl=artist_workspace_v181_profile_url_for_user\(\$user\)/);
assert.match(endpoint, /\$artistProfileUrl===''[\s\S]*artist_workspace_v181_schema_ready\(\$pdo\)/);
assert.match(endpoint, /artist_workspace_v181_lookup_public\(\$pdo,''\s*,\s*\(int\)\$user\['id'\]\)/);
assert.match(endpoint, /artist_workspace_v181_profile_url\(\$workspace\)/);
assert.match(endpoint, /'artist_profile_url'=>\$artistProfileUrl/);
assert.match(ui, /const syncArtistProfileLink = rawUrl =>/);
assert.match(ui, /syncArtistProfileLink\(data\.artist_profile_url\)/);
assert.match(ui, /data-chat-artist-profile-link/);
assert.match(ui, /label\.textContent = 'View Artist Profile'/);
assert.match(ui, /nav\.insertBefore\(link, adminLink \|\| logoutLink \|\| null\)/);

for (const forbidden of [
  'SpeechRecognition',
  'speechSynthesis',
  'getUserMedia',
  'chatVoiceButton',
  'STONEFELLOW_CHAT_CONTINUITY',
  'premium-voice',
  'barge',
  'echoCancellation',
]) {
  assert.ok(!ui.includes(forbidden), `Notification UI must not contain voice runtime term: ${forbidden}`);
}

console.log('AGENT_UPDATES_HIDDEN_V206=PASS');
