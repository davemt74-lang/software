import fs from 'node:fs';
import assert from 'node:assert/strict';

const page = fs.readFileSync('chat.php','utf8');
const legacy = fs.readFileSync('chat-legacy-v108.php','utf8');
const chatCss = fs.readFileSync('chat.css','utf8');
const persistence = fs.readFileSync('chat-recordings-v242.js','utf8');
const api = fs.readFileSync('api/chat-recordings-v242.php','utf8');

assert.match(page,/chat-recordings-v242-20260902/);
assert.match(page,/STONEFELLOW_CHAT_RECORDINGS_V242_CONFIG/);
assert.match(page,/api\/chat-recordings-v242\.php/);
assert.ok(page.indexOf('chat-recordings-v242.js?v=') < page.indexOf('artist-listening-recordings.js?v='),'persistence listener must register before the existing recording command interceptor');

// Chat is now permanently light at the canonical source. The old v242 theme
// files may remain for other historical surfaces, but /chat.php must not load
// them or restore a saved dark preference on reload.
assert.doesNotMatch(page,/agent-theme-v242\.css/);
assert.doesNotMatch(page,/agent-theme-v242\.js/);
assert.doesNotMatch(page,/agent-theme-v242-20260902/);
assert.match(legacy,/chat\.css\?v=206-source-light-20260905/);
assert.match(chatCss,/:root\{--bg:#ffffff;--side:#f8fafc;--panel:#ffffff;--panel2:#f3f4f6;--cream:#111827;/);
for (const token of ['#0b0a09','#11100e','#171411','#1d1915','#ddc4a4','rgba(190,155,111']) {
  assert.equal(chatCss.includes(token), false, `recovered brown Chat token must stay removed: ${token}`);
}

assert.match(api,/hash_equals\(csrf_token\(\), \$csrf\)/);
assert.match(api,/chat_recordings_v242_owned_conversation/);
assert.match(api,/artist_listening_v172_session/,'recording refs must resolve only through owned Artist Listening sessions');
assert.match(api,/recordings_v197/);
assert.match(api,/recording_refs/);
assert.match(api,/STONEFELLOW_RECORDINGS_V242/);
assert.match(api,/INSERT INTO chat_messages/);
assert.match(api,/context_json/);
assert.doesNotMatch(api,/CREATE TABLE|ALTER TABLE/);

assert.match(persistence,/form\.addEventListener\('submit', captureCommand, true\)/,'recording command text must be captured before the existing interceptor clears it');
assert.match(persistence,/STONEFELLOW_RECORDINGS_V242/);
assert.match(persistence,/recording_refs:refs/);
assert.match(persistence,/currentConversationId\(\)/);
assert.match(persistence,/location\.reload\(\)/,'a first-message recording result must recover the newly persisted conversation');
assert.match(persistence,/data-v206-recording-card/);
assert.match(persistence,/data-v206-recording-audio/);
assert.match(persistence,/transcript_excerpt/);
assert.match(persistence,/Open transcript/);
assert.match(persistence,/MutationObserver\(queueScan\)/,'persisted cards must be restored after canonical chat reload/sync DOM rebuilds');
assert.doesNotMatch(persistence,/MediaRecorder/);

console.log('CHAT_RECORDINGS_THEME_V242=PASS');