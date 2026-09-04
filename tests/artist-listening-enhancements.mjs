import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const page = read('artist-listening.php');
const api = read('api/artist-listening-v174.php');
const captureApi = read('api/artist-listening-v172.php');
const helper = read('includes/artist-listening.php');
const capture = read('artist-listening.js');
const workspace = read('artist-listening-workspace.js');
const naming = read('artist-listening-naming.js');
const chat = read('chat.php');

assert.doesNotMatch(page, /artist-listening-enhancements-v174\.js/);
assert.match(page, /artist-listening-workspace\.js\?v=artist-listening-normalized-20260903/);
assert.match(page, /artist-listening-naming\.js\?v=artist-listening-normalized-20260903/);
assert.doesNotMatch(page,/artist-listening[^"']*-v\d+[^"']*\.(?:js|css)/,'numbered Artist Listening frontend assets must not return');

/* v174 remains a backend compatibility endpoint for document/library operations. */
assert.match(api, /metadata_json/);
assert.match(api, /update_metadata/);
assert.match(api, /replace_transcript/);
assert.match(api, /track_projects/);
assert.match(api, /association_type/);
assert.match(api, /artist_listening_v172_track_allowed/);
assert.match(api, /continuous_text/);
assert.match(api, /artist_listening_v174_chat_options/);
assert.match(api, /chat_options/);
assert.match(api, /conversation_id/);
assert.match(api, /column_exists\('tracks', 'updated_at'\)/);
assert.match(api, /status<>'discarded'/);
assert.doesNotMatch(api, /audio_(?:path|blob|file)/i);

/* Capture and song-note promotion behavior remains intact. */
assert.match(captureApi, /function artist_listening_v195_sync_song_notes\(/);
assert.match(captureApi, /song_note_promotions_v195/);
assert.match(captureApi, /segment_type='note'/);
assert.match(captureApi, /INSERT INTO track_notes \(track_id,user_id,note\)/);
assert.match(captureApi, /artist_listening_v172_track_allowed\(\$pdo, \$user, \$trackId\)/);
assert.match(captureApi, /\$action === 'append'[\s\S]*artist_listening_v195_sync_song_notes\(\$pdo, \$user, \$sessionId\)/);
assert.match(captureApi, /\$action === 'stop'[\s\S]*artist_listening_v195_sync_song_notes\(\$pdo, \$user, \$sessionId\)/);
assert.match(captureApi, /upload_recording/);
assert.match(captureApi, /audio_retained'=>true/);
assert.match(captureApi, /artist_listening_v197_stream_recording/);
assert.match(helper, /recordings_v197/);
assert.match(helper, /Content-Range/);
assert.match(capture, /heard==='start recording'/);
assert.match(capture, /heard==='stop recording'/);
assert.match(capture, /MediaRecorder/);
assert.match(capture, /toLowerCase\(\)==='r'/);
assert.match(workspace, /data-listening-record/);
assert.match(workspace, /Audio Clips/);
assert.match(chat, /chat-transcription-audio/);

/* Naming behavior survives the filename normalization. */
assert.match(naming, /Name this transcription:/);
assert.match(naming, /Rename this transcription:/);
assert.match(naming, /request\(endpoint174, 'create_draft'/);
assert.match(naming, /request\(endpoint172, 'rename'/);
assert.match(naming, /\[data-listening-workspace-new\],\[data-listening-workspace-create\]/);
assert.match(naming, /\+ New Transcription/);
assert.doesNotMatch(page, /editor-agent-v131|stem-agent-v131/);

console.log('ARTIST_LISTENING_ENHANCEMENTS=PASS');
