import fs from 'node:fs';
import assert from 'node:assert/strict';

const stems=fs.readFileSync('admin/stems.php','utf8');
const listening=fs.readFileSync('artist-listening.php','utf8');

assert.match(stems,/\$1' \. \$editingRuntime \. \$professionalEditingRuntime/);
assert.match(stems,/\$editingRuntime \. \$professionalEditingRuntime/,'full Stem runtime must load v209 before dependent v210');
assert.doesNotMatch(stems,/STUDIO SAFE RUNTIME · PHASE 2/,'retired Stem recovery label must stay removed');
assert.doesNotMatch(stems,/data-stem-recovery-v227/,'retired Stem recovery badge hook must stay removed');

// Artist Listening has one deterministic canonical frontend boot rather than
// the retired enhanced/fallback/version-layer stack.
const build='artist-listening-normalized-20260903';
const assets=[
  'artist-listening-recognition.js',
  'artist-listening-transcript.js',
  'artist-listening-workspace.js',
  'artist-listening-recordings.js',
  'artist-listening-naming.js',
  'artist-listening-ui.js',
];
for(const asset of assets) assert.ok(listening.includes(`${asset}?v=${build}`),`${asset} must be present in canonical boot`);
assert.ok(listening.includes('artist-listening-realtime.js?v=e07b7c39'),'artist-listening-realtime.js must use the deduplicated realtime source blob cache key');
assert.ok(listening.includes('artist-listening.js?v=9ac023be'),'artist-listening.js must use the fixed capture source blob cache key');
assert.ok(listening.includes('artist-listening-ai.js?v=c18c3dc8'),'artist-listening-ai.js must use the transcription-app source blob cache key');
assert.equal((listening.match(/artist-listening-ai\.js\?/g)||[]).length,1,'Artist Listening AI must load exactly once');
assert.doesNotMatch(listening,/artist-listening[^"']*-v\d+[^"']*\.(?:js|css)|artist-recordings-v\d+\.js/,'numbered Artist Listening frontend layers must stay removed');
assert.doesNotMatch(listening,/\$artistListeningEnhanced|<\?php else: \?>|LISTENING SAFE RUNTIME · PHASE 2/);

const realtimeIndex=listening.indexOf('artist-listening-realtime.js?v=e07b7c39');
const recognitionIndex=listening.indexOf(`artist-listening-recognition.js?v=${build}`);
const transcriptIndex=listening.indexOf(`artist-listening-transcript.js?v=${build}`);
const workspaceIndex=listening.indexOf(`artist-listening-workspace.js?v=${build}`);
const captureIndex=listening.indexOf('artist-listening.js?v=9ac023be');
const recordingsIndex=listening.indexOf(`artist-listening-recordings.js?v=${build}`);
const aiIndex=listening.indexOf('artist-listening-ai.js?v=c18c3dc8');
assert.ok(realtimeIndex>=0 && realtimeIndex<recognitionIndex,'deduplication runtime must load before recognition continuity');
assert.ok(transcriptIndex>=0 && transcriptIndex<workspaceIndex,'paged transcript adapter must load before workspace');
assert.ok(workspaceIndex<captureIndex,'workspace shell must load before capture binds controls');
assert.ok(captureIndex<recordingsIndex,'recordings enhancement must load after capture');
assert.ok(recordingsIndex<aiIndex,'AI controller must load after workspace/capture enhancements');

console.log('RUNTIME_REINTRODUCTION_V223=PASS');
