import fs from 'node:fs';
import assert from 'node:assert/strict';

const api = fs.readFileSync('api/artist-recordings-v198.php', 'utf8');
const runtime = fs.readFileSync('artist-listening-recordings.js', 'utf8');
const artistPage = fs.readFileSync('artist-listening.php', 'utf8');
const chatPage = fs.readFileSync('chat.php', 'utf8');

/* Backend endpoint remains versioned compatibility; browser asset is canonical. */
assert.match(api, /STONEFELLOW_ARTIST_RECORDINGS_V198/);
assert.match(api, /artist_recordings_v198_library/);
assert.match(api, /artist_recordings_v198_mutate/);
assert.match(api, /artist_recordings_v198_delete/);
assert.match(api, /recordings_v197/);
assert.match(api, /'name'=>/);
assert.match(api, /'favorite'=>/);
assert.match(api, /transcript_excerpt/);
assert.match(api, /artist_listening_v197_private_dir/);
assert.match(api, /basename\(/);
assert.match(api, /hash_equals\(csrf_token\(\), \$csrf\)/);
assert.match(api, /has_permission\('artist_listening\.access'/);
assert.doesNotMatch(api, /CREATE TABLE|ALTER TABLE/);

/* Existing recording/search behavior is retained in the canonical file. */
assert.ok(runtime.includes('show|open|search'));
assert.ok(runtime.includes('last|latest'));
assert.ok(runtime.includes('find|search|show'));
assert.ok(runtime.includes('rename this recording'));
assert.ok(runtime.includes('rename recording'));
assert.ok(runtime.includes('stop (?:audio |recording )?playback'));
assert.ok(runtime.includes('show (?:the )?transcript for this recording'));
assert.match(runtime, /function removeLegacyChatCanvas/);
assert.match(runtime, /#chatRecordingsCanvas,\.chat-recordings-canvas/);
assert.match(runtime, /function appendInlineResults/);
assert.match(runtime, /message assistant/);
assert.match(runtime, /ARTIST LISTENING · NEW RECORDING/);
assert.match(runtime, />Download</);
assert.match(runtime, />Open transcript</);
assert.match(runtime, /SEEN_KEY/);
assert.match(runtime, /localStorage\.getItem\(SEEN_KEY\)/);
assert.match(runtime, /localStorage\.setItem\(SEEN_KEY/);
assert.match(runtime, /function pollNewRecordings/);
assert.match(runtime, /POLL_MS = 7000/);
assert.match(runtime, /stonefellow:recording-saved/);
assert.match(runtime, /timeupdate/);
assert.match(runtime, /data-listening-workspace-time/);
assert.match(runtime, /workspaceRefreshing/);
assert.match(runtime, /missingLibraryItem/);
assert.doesNotMatch(runtime, /MediaRecorder/);
assert.doesNotMatch(runtime, /admin\/stems|stem-agent|editor-agent/);

/* The same canonical recording runtime serves Artist Listening and Chat. */
assert.match(artistPage, /artist-listening-recordings\.js\?v=artist-listening-normalized-20260903/);
assert.doesNotMatch(artistPage,/artist-recordings-v\d+\.js|artist-listening-recordings-v\d+\.js/);
assert.match(chatPage, /STONEFELLOW_RECORDINGS_V198_CONFIG/);
assert.match(chatPage, /chat-recording-results-v206-20260901/);
assert.match(chatPage, /artist-listening-recordings\.js\?v=' \s*\. \s*\$recordingUiBuild/);
assert.doesNotMatch(chatPage, /Recent transcriptions & voice memos/);
assert.doesNotMatch(chatPage, /\$recentRecordings/);
assert.match(chatPage, /#chatRecordingsCanvas,\.chat-recordings-canvas/);

console.log('ARTIST_LISTENING_RECORDINGS=PASS');
