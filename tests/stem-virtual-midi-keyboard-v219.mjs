import fs from 'node:fs';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';

const js=fs.readFileSync('admin/stem-virtual-midi-keyboard-v219.js','utf8');
const css=fs.readFileSync('admin/stem-virtual-midi-keyboard-v219.css','utf8');
const loader=fs.readFileSync('admin/_footer.php','utf8');

execFileSync(process.execPath,['--check','admin/stem-virtual-midi-keyboard-v219.js'],{stdio:'pipe'});
execFileSync(process.execPath,['--check','admin/stem-midi-composition-v218-hardening.js'],{stdio:'pipe'});

assert.match(js,/stem-virtual-midi-keyboard-v219-20260902/);
assert.match(js,/StonefellowStemMidiV217Runtime/,'v219 must use the authoritative v217 MIDI runtime');
assert.match(js,/rt\.noteOn\(/,'virtual notes must route through v217 noteOn');
assert.match(js,/runtime\(\)\?\.noteOff\?\./,'note releases must route through v217 noteOff');
assert.match(js,/state\.tracks\?\.find\(track=>track\.armed\)/,'armed MIDI track must be preferred');
assert.match(js,/state\.selectedTrackId/,'selected track must remain the fallback');
assert.match(js,/let keyboardEnabled=false/,'computer QWERTY MIDI must default OFF every page load');
assert.match(js,/COMPUTER KEYS OFF/);
assert.match(js,/aria-pressed/);
assert.match(js,/input,textarea,select,\[contenteditable/,'typing controls must suppress QWERTY MIDI');
assert.match(js,/event\.ctrlKey\|\|event\.metaKey\|\|event\.altKey/,'command shortcuts must not emit notes');
assert.match(js,/a:0,w:1,s:2,e:3,d:4,f:5,t:6,g:7,y:8,h:9,u:10,j:11,k:12/,'standard DAW piano QWERTY map required');
assert.match(js,/key==='z'/);
assert.match(js,/key==='x'/);
assert.match(js,/key==='c'/);
assert.match(js,/key==='v'/);
assert.match(js,/key==='escape'/,'Escape must close the bottom keyboard drawer');
assert.match(js,/setPointerCapture/,'touch and pointer notes need pointer capture');
assert.match(js,/pointercancel/,'cancelled touch must release notes');
assert.match(js,/lostpointercapture/,'lost pointer capture must release notes');
assert.match(js,/root\.addEventListener\('blur',releaseAll\)/,'window blur must release notes');
assert.match(js,/visibilitychange/,'hidden tab must release notes');
assert.match(js,/runtime\(\)\?\.stop\?\.\(\)/,'PANIC must support all-notes-off');
assert.match(js,/nextTargetId!==lastTargetTrackId&&hasHeldNotes/,'track changes while notes are held must trigger stuck-note protection');
assert.match(js,/stonefellow:stem-midi-v217-change',handleMidiStateChange/,'MIDI target changes must be observed');
assert.match(js,/Keep listeners installed for browser back-forward cache restores/,'bfcache restore must keep keyboard controls live');
assert.doesNotMatch(js,/pagehide[\s\S]{0,300}removeEventListener\('keydown'/,'pagehide must not permanently disable keyboard after bfcache restore');
assert.match(js,/data-v219-keyboard-launch/,'MIDI toolbar needs keyboard launcher');
assert.match(js,/Uses armed MIDI track · REC follows MIDI workspace/,'recording behavior must be explicit');
assert.doesNotMatch(js,/SpeechRecognition|ElevenLabs|conversation-voice|premium-voice/,'v219 must stay out of frozen voice/conversation code');

assert.match(css,/position:fixed/);
assert.match(css,/left:0;right:0;bottom:0/);
assert.match(css,/width:100vw/,'keyboard drawer must span full viewport width');
assert.match(css,/height:25dvh/,'keyboard drawer must cover lower quarter of viewport');
assert.match(css,/transform:translateY\(105%\)/,'closed keyboard must sit below viewport');
assert.match(css,/\.sf-v219-keyboard-drawer\.open\{transform:translateY\(0\)\}/,'open keyboard must slide up');
assert.match(css,/z-index:2147482100/,'keyboard must sit above sticky player/chat UI');
assert.match(css,/\.sf-v219-key\.white/);
assert.match(css,/\.sf-v219-key\.black/);
assert.match(css,/\.sf-v219-key\.black\{[^}]*transform:translateX\(-50%\)/,'black keys need cross-browser deterministic centering');
assert.match(css,/touch-action:none/);

assert.match(loader,/stem-virtual-midi-keyboard-v219\.css/);
assert.match(loader,/stem-virtual-midi-keyboard-v219\.js/);
assert.match(loader,/data-stem-virtual-midi-v219/);
assert.doesNotMatch(loader,/document\.currentScript/,'v219 must not infer its production URL from another runtime file');
assert.doesNotMatch(loader,/midi_feature_enabled_v217|midi\.access/,'v219 loader should not create a parallel permission model');

console.log('STEM_VIRTUAL_MIDI_V219=PASS');
