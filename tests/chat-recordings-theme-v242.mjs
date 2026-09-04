import fs from 'node:fs';
import assert from 'node:assert/strict';

const page = fs.readFileSync('chat.php','utf8');
const persistence = fs.readFileSync('chat-recordings-v242.js','utf8');
const api = fs.readFileSync('api/chat-recordings-v242.php','utf8');
const theme = fs.readFileSync('agent-theme-v242.js','utf8');
const themeCss = fs.readFileSync('agent-theme-v242.css','utf8');

assert.match(page,/chat-recordings-v242-20260902/);
assert.match(page,/agent-theme-v242-20260902/);
assert.match(page,/STONEFELLOW_CHAT_RECORDINGS_V242_CONFIG/);
assert.match(page,/api\/chat-recordings-v242\.php/);
assert.match(page,/agent-theme-v242\.css/);
assert.match(page,/agent-theme-v242\.js/);
assert.ok(page.indexOf('chat-recordings-v242.js?v=') < page.indexOf('artist-listening-recordings.js?v='),'persistence listener must register before the existing recording command interceptor');

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

assert.match(themeCss,/--bg:#050914/,'dark Agent theme must use near-black navy, not brown');
assert.match(themeCss,/--side:#070d1a/);
assert.match(themeCss,/body\[data-agent-theme="light"\]/);
assert.match(themeCss,/--bg:#fff/);
assert.match(theme,/stonefellow:agent-theme:v242/);
assert.match(theme,/dataset\.v242ThemeToggle/);
assert.match(theme,/Use white theme/);
assert.match(theme,/Use dark theme/);
assert.match(theme,/localStorage\.setItem\(key, next\)/);
assert.doesNotMatch(theme,/chat-voice-v142|premium-voice-v117|conversation-voice/);

console.log('CHAT_RECORDINGS_THEME_V242=PASS');
