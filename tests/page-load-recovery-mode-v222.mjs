import fs from 'node:fs';
import assert from 'node:assert/strict';

const stems=fs.readFileSync('admin/stems.php','utf8');
const footer=fs.readFileSync('admin/_footer.php','utf8');
const listening=fs.readFileSync('artist-listening.php','utf8');
const transcript=fs.readFileSync('artist-listening-transcript.js','utf8');

// Stem recovery protections remain deterministic without a floating recovery badge.
assert.match(stems,/\$advancedStudioRuntime = \(string\)\(\$_GET\['advanced_runtime'\]/);
assert.match(stems,/if \(\$advancedStudioRuntime\)/);
assert.doesNotMatch(stems,/STUDIO SAFE RUNTIME · PHASE 2/,'retired Stem recovery label must stay removed');
assert.doesNotMatch(stems,/data-stem-recovery-v227/,'retired Stem recovery badge hook must stay removed');
assert.match(stems,/\$1' \. \$editingRuntime \. \$professionalEditingRuntime/,'default Stem boot should load v209 before v210');
assert.match(footer,/STONEFELLOW_STEM_ADVANCED_RUNTIME/,'MIDI must stay out of the default recovery boot');

// Artist Listening has one canonical deterministic frontend boot. Backend
// compatibility endpoints may remain versioned until a separate API migration.
assert.doesNotMatch(listening,/\$artistListeningEnhanced|enhanced_runtime/,'retired Artist Listening enhanced-runtime fork must stay removed');
assert.doesNotMatch(listening,/LISTENING SAFE RUNTIME · PHASE 2/,'retired floating safety label must stay removed');
assert.doesNotMatch(listening,/<\?php else: \?>/,'Artist Listening must use one deterministic boot path');

const build='artist-listening-normalized-20260903';
for (const asset of [
  'artist-listening-recognition.js',
  'artist-listening-transcript.js',
  'artist-listening-workspace.js',
  'artist-listening-recordings.js',
  'artist-listening-naming.js',
  'artist-listening-ui.js',
]) assert.match(listening,new RegExp(`${asset.replaceAll('.','\\.')}\\?v=${build}`),`${asset} must load from the canonical boot`);
assert.match(listening,/artist-listening-realtime\.js\?v=e07b7c39/,'deduplicated realtime controller must load with its source blob cache key');
assert.match(listening,/artist-listening\.js\?v=9ac023be/,'fixed capture controller must load with its source blob cache key');
assert.match(listening,/artist-listening-ai\.js\?v=c18c3dc8/,'AI transcription-app controller must load with its source blob cache key');
assert.equal((listening.match(/artist-listening-ai\.js\?/g)||[]).length,1,'AI controller must load exactly once');
assert.doesNotMatch(listening,/artist-listening[^"']*-v\d+[^"']*\.(?:js|css)|artist-recordings-v\d+\.js/,'numbered Artist Listening frontend boot layers must stay removed');

const realtimeIndex=listening.indexOf('artist-listening-realtime.js?v=e07b7c39');
const recognitionIndex=listening.indexOf(`artist-listening-recognition.js?v=${build}`);
const transcriptIndex=listening.indexOf(`artist-listening-transcript.js?v=${build}`);
const workspaceIndex=listening.indexOf(`artist-listening-workspace.js?v=${build}`);
const captureIndex=listening.indexOf('artist-listening.js?v=9ac023be');
const aiIndex=listening.indexOf('artist-listening-ai.js?v=c18c3dc8');
assert.ok(realtimeIndex>=0 && realtimeIndex<recognitionIndex,'deduplication runtime must load before recognition continuity');
assert.ok(transcriptIndex>=0 && transcriptIndex<workspaceIndex,'canonical paging must install before workspace session reads');
assert.ok(workspaceIndex<captureIndex,'workspace shell must load before capture binds its controls');
assert.ok(captureIndex<aiIndex,'AI controller must bind only after workspace and capture controls exist');
assert.match(transcript,/workspaceRequest/,'canonical transcript adapter must own paged workspace reads explicitly');
assert.match(transcript,/detail\.segments=last\.segments/,'long live sessions must render only the current page');
assert.doesNotMatch(listening,/chat-voice-v142|conversation-voice-v122|premium-voice-v117/,'Artist Listening must not boot a competing Agent Chat voice owner');

console.log('PAGE_LOAD_RECOVERY_MODE_V222=PASS');
