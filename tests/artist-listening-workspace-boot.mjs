import fs from 'node:fs';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';

const page=fs.readFileSync('artist-listening.php','utf8');
const workspace=fs.readFileSync('artist-listening-workspace.js','utf8');
const listener=fs.readFileSync('artist-listening.js','utf8');
const transcript=fs.readFileSync('artist-listening-transcript.js','utf8');

for (const file of ['artist-listening-transcript.js','artist-listening-workspace.js','artist-listening.js']) {
  execFileSync(process.execPath,['--check',file],{stdio:'pipe'});
}

const build='artist-listening-normalized-20260903';
const transcriptIndex=page.indexOf(`artist-listening-transcript.js?v=${build}`);
const workspaceIndex=page.indexOf(`artist-listening-workspace.js?v=${build}`);
const listenerIndex=page.indexOf('artist-listening.js?v=9ac023be');
const recordingsIndex=page.indexOf(`artist-listening-recordings.js?v=${build}`);
assert.ok(transcriptIndex>=0,'canonical transcript runtime must load');
assert.ok(transcriptIndex<workspaceIndex,'transcript adapter must be available before workspace session loading');
assert.ok(workspaceIndex<listenerIndex,'workspace must construct listener controls before capture runtime starts');
assert.ok(listenerIndex<recordingsIndex,'recording library must enhance the already-running transcription workspace');
assert.match(workspace,/id="artistListeningButton"/,'workspace must construct the listener start control');
assert.match(listener,/document\.getElementById\('artistListeningButton'\)/,'capture runtime requires the workspace start control');
assert.match(transcript,/workspaceRequest/,'transcript runtime must adapt workspace transcript reads explicitly');
assert.match(transcript,/stonefellow:artist-listening-live/,'transcript runtime must consume the live transcription event');
assert.doesNotMatch(page,/artist-listening[^"']*-v\d+[^"']*\.(?:js|css)/,'boot path must not load numbered Artist Listening frontend layers');
assert.doesNotMatch(page,/<\?php else: \?>/,'Artist Listening must use one deterministic boot path');

console.log('ARTIST_LISTENING_WORKSPACE_BOOT=PASS');
