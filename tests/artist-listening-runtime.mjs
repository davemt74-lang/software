import fs from 'node:fs';
import assert from 'node:assert/strict';

const page = fs.readFileSync('artist-listening.php','utf8');
const workspace = fs.readFileSync('artist-listening-workspace.js','utf8');
const capture = fs.readFileSync('artist-listening.js','utf8');
const ai = fs.readFileSync('artist-listening-ai.js','utf8');
const transcript = fs.readFileSync('artist-listening-transcript.js','utf8');
const realtime = fs.readFileSync('artist-listening-realtime.js','utf8');
const ui = fs.readFileSync('artist-listening-ui.js','utf8');

/* One canonical frontend layer per concern. */
for (const asset of [
  'artist-listening.css',
  'artist-listening-realtime.js',
  'artist-listening-recognition.js',
  'artist-listening-transcript.js',
  'artist-listening-workspace.js',
  'artist-listening.js',
  'artist-listening-recordings.js',
  'artist-listening-naming.js',
  'artist-listening-ai.js',
  'artist-listening-ui.js',
]) {
  assert.ok(page.includes(asset), `${asset} must be loaded by the canonical page`);
}
assert.match(page,/artist-listening\.js\?v=9ac023be/,'capture controller must use the fixed source blob cache key');
assert.match(page,/artist-listening-ai\.js\?v=c18c3dc8/,'AI controller must use the current source blob cache key');
assert.doesNotMatch(page,/artist-listening[^"']*-v\d+[^"']*\.(?:js|css)/,'Artist Listening page must not load numbered frontend layers');
assert.equal((page.match(/artist-listening-ai\.js\?/g) || []).length, 1, 'AI controller must load exactly once');
assert.equal((page.match(/artist-listening\.css\?/g) || []).length, 1, 'one canonical stylesheet must load exactly once');

/* Header ownership: EXIT + Save + AI Summary. Copy/Download do not exist. */
assert.match(workspace,/data-listening-workspace-exit/);
assert.match(workspace,/data-listening-workspace-save>Save<\/button>/);
assert.match(workspace,/data-listening-ai-toggle aria-expanded="false"/);
assert.doesNotMatch(workspace,/>Copy<\/button>|>Download<\/button>|copyDocument|downloadDocument/);

/* AI Summary keeps one direct owner while transcription apps live inside it. */
assert.match(ai,/function getButton\(\)/);
assert.match(ai,/document\.querySelector\('\[data-listening-ai-toggle\]'\)/);
assert.match(ai,/button\.addEventListener\('click'/);
assert.doesNotMatch(ai,/document\.addEventListener\('click'/);
assert.match(ai,/function setOpen\(open\)/);
assert.match(ai,/setOpen\(!state\.open\)/);
assert.match(ai,/panel\.classList\.toggle\('open', state\.open\)/);
assert.match(ai,/function setResearchEnabled\(enabled\)/);
assert.match(ai,/data-listening-ai-research/);
assert.match(ai,/data-listening-ai-app-options/);
assert.match(ai,/data-listening-ai-tabs/);
assert.match(ai,/apps:state\.selectedApps/);
assert.match(ai,/request\('analyze'/);
assert.match(ai,/save_brain/);
assert.match(ai,/save_knowledge/);
assert.match(ai,/sf-listening-ai-footer-actions/);
assert.match(ai,/sf-listening-ai-report-state/);
assert.doesNotMatch(ai,/data-listening-ai-saved/);
assert.doesNotMatch(ai,/MutationObserver|window\.fetch\s*=|Continuous View|sfListeningTranscriptNav/);
assert.doesNotMatch(ui,/data-listening-ai-toggle|sf-listening-ai-open|fallbackAiClick|ensureAiButton/);

/* Listening/transcription must not abort because optional notice UI is absent. */
assert.match(capture,/const requiredControls=/);
assert.match(capture,/if\(requiredControls\.some\(node=>!node\)\)return;/);
assert.match(capture,/if\(!el\.notice\)return;/);
assert.doesNotMatch(capture,/Object\.values\(el\)\.some\(node=>!node\)/);
assert.match(capture,/el\.start\.addEventListener\('click',\(\)=>void startCapture\('button'\)\)/);
assert.match(capture,/window\.dispatchEvent\(new CustomEvent\('stonefellow:artist-listening-ready'/);

/* Page / Continuous View belongs only to the transcript controller. */
assert.match(transcript,/Continuous View/);
assert.match(transcript,/function enterContinuous\(\)/);
assert.match(transcript,/function exitContinuous\(\)/);
assert.match(transcript,/stonefellow:artist-listening-view-changed/);
assert.doesNotMatch(transcript,/ensureAiUi|analyze_page|analyze_master|window\.fetch\s*=/);
assert.doesNotMatch(page,/left:292px!important|left:238px!important/);

/* Realtime may reconcile speech, but it may never inject another runtime. */
assert.doesNotMatch(realtime,/createElement\('script'\)|artist-listening-intelligence-v236\.js/);

/* Temporary diagnostics stay removed; product state belongs in the report canvas. */
assert.doesNotMatch(ai,/sfListeningAiDebug|AI SUMMARY DEBUG|renderDebug|ensureDebug/);
assert.match(ai,/return 'Not analyzed yet'/);
assert.match(ai,/class="sf-listening-ai-report-state"/);
assert.match(page,/\.sf-listening-ai-footer-actions\{[\s\S]*flex-wrap:nowrap!important/);
assert.match(page,/\.sf-listening-ai-tabs\{display:flex/);

/* Sidebar remains interactive unless this browser is actually transcribing. */
assert.match(workspace,/function browserListeningActive\(\)/);
assert.match(workspace,/if \(listeningActive && activeSessionId && id !== activeSessionId\)/);
assert.match(workspace,/api174\('session', \{session_id:id\}\)/);

assert.doesNotMatch(ai,/MediaRecorder|chat-voice-v142|premium-voice-v117|conversation-voice/);
assert.doesNotMatch(workspace,/MediaRecorder/);

console.log('ARTIST_LISTENING_RUNTIME=PASS');
