import assert from 'node:assert/strict';
import fs from 'node:fs';

const api = fs.readFileSync('transcription-editor.js','utf8');
const workspace = fs.readFileSync('artist-listening-workspace.js','utf8');
const capture = fs.readFileSync('artist-listening.js','utf8');
const transcript = fs.readFileSync('artist-listening-transcript.js','utf8');
const ai = fs.readFileSync('artist-listening-ai.js','utf8');
const recordings = fs.readFileSync('artist-listening-recordings.js','utf8');
const page = fs.readFileSync('artist-listening.php','utf8');

assert(api.includes("window.StonefellowTranscriptionEditor = {"), 'canonical Transcription Editor owner exists');
for (const method of ['getState','getSelection','getCommands','execute','verify']) {
  assert(new RegExp(`\\b${method}\\b`).test(api), `canonical API exposes ${method}`);
}
assert(!/transcription-editor-v\d+/i.test(api + page), 'canonical Transcription Editor has no numbered runtime name');

const expected = [
  'transcription.document.create','transcription.document.open','transcription.document.delete','transcription.document.rename','transcription.document.save','transcription.document.metadata.update','transcription.document.search',
  'transcription.library.filter','transcription.folder.create','transcription.folder.delete','transcription.turn.update',
  'transcription.selection.text.set','transcription.selection.turn.set','transcription.selection.clear',
  'transcription.view.prose','transcription.view.turns','transcription.page.go','transcription.page.continuous','transcription.page.paged',
  'transcription.listening.start','transcription.listening.stop','transcription.listening.pause','transcription.listening.resume',
  'transcription.recording.start','transcription.recording.stop','transcription.marker.add','transcription.note.add','transcription.speaker_mode.set','transcription.microphone.select','transcription.microphone.test','transcription.audio.seek',
  'transcription.knowledge.promote_memory','transcription.knowledge.promote_knowledge','transcription.knowledge.promote_project_note',
  'transcription.ai.open','transcription.ai.close','transcription.ai.research.set','transcription.ai.apps.set','transcription.ai.app.activate','transcription.ai.analyze','transcription.ai.save_brain','transcription.ai.save_knowledge',
  'transcription.recording_library.refresh','transcription.recording_library.search','transcription.recording_library.select','transcription.recording_library.play','transcription.recording_library.stop','transcription.recording_library.rename','transcription.recording_library.favorite','transcription.recording_library.delete',
];
for (const command of expected) assert(api.includes(`id:'${command}'`), `command registry includes ${command}`);
assert.equal((api.match(/\{id:'transcription\./g) || []).length, expected.length, 'registry has no unreviewed command drift');

assert(workspace.includes('proof.api = {'), 'workspace exposes direct capability bridge');
for (const contract of ['getState: transcriptionWorkspaceState','getSelection: transcriptionWorkspaceSelection','createDocument: transcriptionCreateDocument','openDocument: transcriptionOpenDocument','updateTurn: transcriptionUpdateTurn','selectText: transcriptionSelectText','selectTurn: transcriptionSelectTurn','pause: transcriptionPause','resume: transcriptionResume']) assert(workspace.includes(contract), `workspace bridge includes ${contract}`);
assert(capture.includes('proof.api={'), 'capture exposes direct capability bridge');
assert(capture.includes('const continuity=window.STONEFELLOW_CHAT_CONTINUITY;'), 'capture uses canonical Chat continuity owner');
assert(!/STONEFELLOW_CHAT_CONTINUITY_V\d+/.test(capture), 'capture has no versioned Chat continuity dependency');
for (const contract of ['start:async()=>','stop:async()=>','startRecording:async()=>','stopRecording:async()=>','addMarker:()=>','addNote:text=>','setSpeakerMode:transcriptionSetSpeakerMode']) assert(capture.includes(contract), `capture bridge includes ${contract}`);
assert(transcript.includes('proof.api={'), 'long transcript exposes direct capability bridge');
assert(transcript.includes('window.STONEFELLOW_ARTIST_LISTENING_WORKSPACE?.api'), 'long transcript page navigation uses workspace API');
assert(!transcript.includes('if(open)open.click()'), 'long transcript no longer clicks workspace DOM buttons for API navigation');
assert(ai.includes('proof.api={'), 'AI Summary exposes direct capability bridge');
for (const contract of ['analyze:async(','saveBrain:async()=>','saveKnowledge:async()=>','setApps:transcriptionSetApps']) assert(ai.includes(contract), `AI bridge includes ${contract}`);
assert(recordings.includes('proof.api={'), 'recording library exposes direct capability bridge');
assert(recordings.includes('async function deleteItem(item, confirmUser = true)'), 'recording API can delete without UI confirmation while UI keeps confirmation');
assert(recordings.includes('if(updated&&state.current&&itemId(state.current)===itemId(item))state.current=updated;'), 'recording mutations refresh selected recording state');
for (const contract of ['getSelection:()=>','search:async(args={})','select:async(args={})','play:async(args={})','rename:async(args={})','favorite:async(args={})','delete:async(args={})']) assert(recordings.includes(contract), `recording bridge includes ${contract}`);

const uiIndex = page.indexOf('/artist-listening-ui.js');
const apiIndex = page.indexOf('/transcription-editor.js');
assert(uiIndex >= 0 && apiIndex > uiIndex, 'canonical API loads after all transcription capability owners');
assert(api.includes("window.dispatchEvent(new CustomEvent('stonefellow:transcription-editor-executed'"), 'execution receipts are observable');
assert(api.includes('const ARGUMENTS = Object.freeze({'), 'command discovery exposes machine-readable argument metadata');
assert(api.includes('const DESTRUCTIVE = new Set(['), 'command discovery marks destructive operations');
assert(api.includes("'transcription.document.delete',"), 'document deletion is explicitly classified');
assert(api.includes('destructive:DESTRUCTIVE.has(command.id)'), 'getCommands exposes destructive metadata');
assert(api.includes('function validateArgs(command, args = {})'), 'execute validates required command arguments');
assert(api.includes('function receiptState(state)'), 'execution receipts use bounded state snapshots');
assert(api.includes('before:receiptState(before), after:receiptState(after)'), 'receipts do not duplicate full transcript and recording-library state');
assert(workspace.includes("if (!result?.ok) throw new Error(String(result?.label || 'Microphone test failed.'));"), 'microphone API propagates failed input tests truthfully');
assert(api.includes("method='server-ack'"), 'verification distinguishes server acknowledgements from observable state');
assert(api.includes('const verification = verify('), 'execute verifies each command result');
assert(api.includes("return {ok:false, command, args, error:`Unknown transcription command:"), 'unknown commands fail truthfully');
console.log(`PASS Transcription Editor API (${expected.length} commands)`);
