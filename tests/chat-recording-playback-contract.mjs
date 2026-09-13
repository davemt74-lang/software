import fs from 'node:fs';
import assert from 'node:assert/strict';

const route = fs.readFileSync(new URL('../api/artist-listening.php', import.meta.url), 'utf8');
const playback = fs.readFileSync(new URL('../includes/artist-recording-playback-v243.php', import.meta.url), 'utf8');
const library = fs.readFileSync(new URL('../api/artist-recordings-v198.php', import.meta.url), 'utf8');
const ui = fs.readFileSync(new URL('../artist-listening-recordings.js', import.meta.url), 'utf8');

assert.match(
  route,
  /\$action === 'recording' && in_array\(\$method, \['GET', 'HEAD'\], true\)/,
  'canonical recording route must explicitly accept GET and HEAD'
);
assert.match(
  route,
  /artist-recording-playback-v243\.php/,
  'canonical recording route must load the hardened playback transport'
);
assert.match(
  route,
  /artist_recording_playback_v243_serve\(/,
  'canonical recording route must serve retained audio through the hardened transport'
);
assert.match(
  route,
  /require __DIR__ \. '\/artist-listening-v172\.php';/,
  'non-recording Artist Listening actions must continue through the canonical v172 runtime'
);

assert.match(
  playback,
  /artist_listening_v172_session\(\$pdo, \$user, \$sessionId\)/,
  'recording playback must retain owner-scoped session authorization'
);
assert.match(
  playback,
  /artist_listening_v197_private_dir\(\$user, \$sessionId\)/,
  'recording playback must resolve files only inside the private recording namespace'
);
assert.match(playback, /session_write_close\(\)/, 'media playback must release the PHP session lock');
assert.match(playback, /ini_set\('zlib\.output_compression', '0'\)/, 'media playback must disable output compression');
assert.match(playback, /ob_end_clean\(\)/, 'media playback must clear application output buffers');
assert.match(playback, /header_remove\('Content-Encoding'\)/, 'media playback must remove stale content encoding');
assert.match(playback, /header\('Accept-Ranges: bytes'\)/, 'media playback must advertise byte-range support');
assert.match(playback, /http_response_code\(416\)/, 'invalid byte ranges must return 416');
assert.match(playback, /'status'=>\$status/, 'valid ranges must preserve the computed 200 or 206 status');
assert.match(playback, /header\('Content-Range: bytes ' \./, 'partial responses must include Content-Range');
assert.match(playback, /header\('Content-Length: ' \. \$length\)/, 'media responses must publish exact Content-Length');
assert.match(
  playback,
  /if \(\$method === 'HEAD'\) \{\s*exit;\s*\}\s*\n\s*\$handle = @fopen/s,
  'HEAD must return media headers without opening or reading the recording body'
);
assert.match(
  playback,
  /preg_match\('\/\^bytes=\(\\d\*\)-\(\\d\*\)\$\/'/,
  'byte-range parser must accept one deterministic contiguous range only'
);

assert.match(
  library,
  /'url'=>url\('\/api\/artist-listening\.php\?action=recording&session_id='/,
  'recording library must keep emitting the canonical private playback URL'
);
assert.match(ui, /audio\.controls = true;/, 'Chat recording cards must retain native playback controls');
assert.match(ui, /audio\.preload = 'metadata';/, 'Chat recording cards must remain metadata-preloaded for fast duration discovery');
assert.match(ui, /audio\.src = item\.url;/, 'Chat recording cards must play the authorized canonical media URL');

console.log('CHAT_RECORDING_PLAYBACK_CONTRACT=PASS');
