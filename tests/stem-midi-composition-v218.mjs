import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';

const js=fs.readFileSync('admin/stem-midi-composition-v218.js','utf8');
const hardening=fs.readFileSync('admin/stem-midi-composition-v218-hardening.js','utf8');
const css=fs.readFileSync('admin/stem-midi-composition-v218.css','utf8');
const apiPhp=fs.readFileSync('api/stem-midi-v217.php','utf8');
const footer=fs.readFileSync('admin/_footer.php','utf8');

execFileSync(process.execPath,['--check','admin/stem-midi-composition-v218.js'],{stdio:'pipe'});
execFileSync(process.execPath,['--check','admin/stem-midi-composition-v218-hardening.js'],{stdio:'pipe'});
execFileSync('php',['-l','api/stem-midi-v217.php'],{stdio:'pipe'});
execFileSync('php',['-l','admin/_footer.php'],{stdio:'pipe'});

const sandbox={console};
sandbox.globalThis=sandbox;
vm.createContext(sandbox);
vm.runInContext(js,sandbox,{filename:'stem-midi-composition-v218.js'});
const api=sandbox.StonefellowStemMidiCompositionV218;
assert.ok(api);
assert.equal(api.build,'stem-midi-composition-v218-20260902');
assert.equal(api.PPQ,960);
assert.equal(api.divisionTicks('1/16',960),240);
assert.equal(api.divisionTicks('1/16',480),120,'division math must scale with PPQ');
assert.deepEqual(Array.from(api.scalePitchClasses(0,'major')),[0,2,4,5,7,9,11]);
assert.equal(api.pitchInScale(61,0,'major'),false);
assert.equal(api.nearestScalePitch(61,0,'major'),60);
assert.deepEqual(Array.from(api.chordIntervals('min7')),[0,3,7,10]);
assert.deepEqual(Array.from(api.chordPitches(60,'major',1)),[64,67,72]);
assert.equal(api.swingTick(240,'1/16',0),240);
assert.equal(api.swingTick(240,'1/16',50),300);
assert.equal(api.swingTick(120,'1/16',50,480),150,'swing must honor alternate PPQ');

const human=api.humanizeNotes([{id:'n',pitch:60,startTick:480,durationTick:240,velocity:.8,channel:1}],30,10,'seed');
assert.equal(human.length,1);
assert.ok(human[0].startTick>=450&&human[0].startTick<=510);
assert.ok(human[0].velocity>=.72&&human[0].velocity<=.88);

const invalidDefault=api.defaultPattern('track-a','clip-a',19);
assert.equal(invalidDefault.steps,16);
assert.equal(invalidDefault.lanes[0].steps.length,16,'default lanes must match sanitized pattern length');

const pattern=api.defaultPattern('track-a','clip-a',16);
pattern.id='beat';
pattern.lanes[0].steps[0].on=true;
pattern.lanes[0].steps[0].velocity=.9;
pattern.lanes[1].steps[4].on=true;
let generated=api.patternToNotes(pattern,{ppq:960,swing:0});
assert.equal(generated.length,2);
assert.equal(generated[0].pitch,36);
assert.equal(generated[0].startTick,0);
assert.ok(generated[0].id.startsWith('v218pat-beat-'));
pattern.lanes[0].steps[0].probability=0;
generated=api.patternToNotes(pattern,{ppq:960,swing:0});
assert.equal(generated.length,1,'zero probability step must never materialize');

const normalized=api.normalizeComposition({
 scale:{root:99,mode:'bad',lock:true},swing:200,
 patterns:[{id:'p',steps:19,division:'bad',lanes:[{pitch:500,steps:[{on:true,velocity:4,probability:-1}]}]}],
 ccLanes:[{controller:'999',points:[{tick:-1,value:9999}]}]
});
assert.equal(normalized.scale.root,11);
assert.equal(normalized.scale.mode,'major');
assert.equal(normalized.swing,75);
assert.equal(normalized.patterns[0].steps,16);
assert.equal(normalized.patterns[0].division,'1/16');
assert.equal(normalized.patterns[0].lanes[0].pitch,127);
assert.equal(normalized.patterns[0].lanes[0].steps[0].velocity,1);
assert.equal(normalized.patterns[0].lanes[0].steps[0].probability,0);
assert.equal(normalized.ccLanes[0].controller,'1','CC numbers above 127 must be rejected');

const arp=api.arpeggiate([
 {id:'a',pitch:60,startTick:0,durationTick:960,velocity:.8,channel:1},
 {id:'b',pitch:64,startTick:0,durationTick:960,velocity:.8,channel:1},
 {id:'c',pitch:67,startTick:0,durationTick:960,velocity:.8,channel:1}
],{division:'1/16',mode:'up',octaves:1,bars:1,gate:.8});
assert.equal(arp.length,16);
assert.deepEqual(Array.from(arp.slice(0,3).map(note=>note.pitch)),[60,64,67]);
assert.equal(arp[1].startTick,240);

assert.match(js,/COMPOSE \+ SEQUENCE/);
assert.match(js,/CONVERT TO MIDI/);
assert.match(js,/REROLL/);
assert.match(js,/probability/);
assert.match(js,/row\.style\.gridTemplateColumns=`132px repeat\(\$\{pattern\.steps\}/,'sequencer width must follow 8\/16\/32\/64-step patterns');
assert.match(js,/touchComposition\(false\)/,'continuous step controls must not replace themselves mid-drag');
assert.match(js,/copy\.startTick=source\.startTick\+Math\.round\(source\.steps\*divisionTicks/,'duplicate patterns must be placed after their source');
assert.match(js,/function extendClip/);
assert.match(js,/extendClip\(clip,start\+PPQ\)/,'chords must extend the clip when needed');
assert.match(js,/NOTES/);
assert.match(js,/Shift\/Ctrl-click notes/);
assert.match(js,/Alt-drag empty roll for lasso/);
assert.match(js,/QUANTIZE SELECTED/);
assert.match(js,/SET LENGTH/);
assert.match(js,/LEGATO/);
assert.match(js,/function duplicateSelected/);
assert.doesNotMatch(js,/function duplicateSelected\(\)\{copySelected\(\);pasteNotes\(\);\}/,'duplicate may not depend on playhead paste semantics');
assert.match(js,/function enforceScaleLock/);
assert.match(js,/track\.instrument\?\.type==='drum'/,'scale lock must leave drum tracks chromatic');
assert.match(js,/nearestScalePitch/);
assert.match(js,/ADD CHORD/);
assert.match(js,/ARPEGGIATE SELECTED/);
assert.match(js,/humanizeSelected/);
assert.match(js,/CAPTURE MIDI CC/);
assert.match(js,/requestMIDIAccess\(\{sysex:false\}\)/);
assert.match(js,/compositionV218/);
assert.match(js,/localStorage/,'composition must have local crash recovery');
assert.match(js,/runtime\(\)\?\.save/,'composition-only edits must persist through v217 save');
assert.doesNotMatch(js,/SpeechRecognition|ElevenLabs|premium-voice|conversation-voice|chatVoiceButton/);

assert.match(hardening,/stem-midi-composition-v218-hardening-20260902/);
assert.match(hardening,/requestMIDIAccess\(\{sysex:false\}\)/);
assert.match(hardening,/addEventListener\('statechange',stateHandler\)/,'v218 may not replace the v217 MIDIAccess state handler');
assert.doesNotMatch(hardening,/\.onstatechange\s*=/,'hardening must coexist with v217 Web MIDI state handling');
assert.match(hardening,/addEventListener\('midimessage',handler\)/,'CC capture must coexist with v217 note input');
assert.match(hardening,/type===0xe0/,'pitch bend hardware messages must be captured');
assert.match(hardening,/\(data\[1\]\|\|0\)\+\(\(data\[2\]\|\|0\)<<7\)/,'pitch bend must preserve 14-bit data');
assert.match(hardening,/absoluteTick\(\)-num\(target\.clip\.startTick,0\)/,'CC timing must be relative to the actually armed clip');
assert.match(hardening,/lane\.points\.length>2048/,'hardware CC capture must remain bounded');
assert.match(js,/restoreState\?\.\(beforeMidi,\{save:false\}\)/,'failed composition recall must restore its pre-load MIDI state');
assert.match(hardening,/cloneNode\(true\)/,'the unsafe first-pass CC button listener must be replaced');
assert.match(hardening,/removeEventListener\('statechange',stateHandler\)/,'MIDIAccess hardening listeners must be cleaned up');
assert.match(hardening,/if\(label\.textContent!==text\) label\.textContent=text/,'selection observer must not recursively rewrite identical text');
assert.doesNotMatch(hardening,/root\.fetch\s*=/,'hardening must not add another global fetch wrapper');
assert.match(hardening,/if\(event\?\.persisted\)/,'bfcache pagehide must preserve live observers and MIDI inputs');
assert.match(hardening,/root\.addEventListener\('pageshow'/,'hardening must refresh after bfcache restore');
assert.doesNotMatch(hardening,/SpeechRecognition|ElevenLabs|premium-voice|conversation-voice|chatVoiceButton/);

assert.match(apiPhp,/function midi_v218_clean_composition/);
assert.match(apiPhp,/'compositionV218'=>midi_v218_clean_composition/);
assert.match(apiPhp,/array_slice\(is_array\(\$raw\['patterns'\]/);
assert.match(apiPhp,/,0,128\)/,'pattern count must be bounded');
assert.match(apiPhp,/,0,2048\)/,'CC points must be bounded');
assert.match(apiPhp,/,0,32\)/,'pattern lanes must be bounded');
assert.match(apiPhp,/\[8,16,32,64\]/);
assert.match(apiPhp,/minor_pentatonic/);
assert.doesNotMatch(apiPhp,/CREATE TABLE|ALTER TABLE/,'v218 must not add schema');

const gateStart=footer.indexOf('<?php if ($midiV217Allowed): ?>');
const gateEnd=footer.indexOf('<?php endif; ?>',gateStart);
const gated=footer.slice(gateStart,gateEnd);
assert.match(gated,/stem-midi-composition-v218\.css/);
assert.match(gated,/stem-midi-composition-v218\.js/);
assert.match(gated,/stem-midi-composition-v218-hardening\.js/);
assert.match(gated,/stem-virtual-midi-keyboard-v219\.css/);
assert.match(gated,/stem-virtual-midi-keyboard-v219\.js/);
assert.ok(gated.indexOf('stem-midi-v217.js')<gated.indexOf('stem-midi-composition-v218.js'),'v218 must load after v217 runtime');
assert.ok(gated.indexOf('stem-midi-composition-v218.js')<gated.indexOf('stem-midi-composition-v218-hardening.js'),'hardening must load after base composition runtime');
assert.ok(gated.indexOf('stem-midi-composition-v218-hardening.js')<gated.indexOf('stem-virtual-midi-keyboard-v219.js'),'v219 must load explicitly after v218 hardening');
assert.match(js,/if\(event\.persisted\) return/,'v218 must retain its fetch coordinator during bfcache navigation');
assert.match(js,/root\.addEventListener\('pageshow'/,'v218 must restore its UI after bfcache navigation');
assert.match(gated,/stem-midi-session-v217-20260901/,'v217 session bridge must remain ahead of v218');
assert.match(gated,/stem-midi-composition-v218-20260902/);
assert.match(gated,/stem-midi-composition-v218-hardening-20260902/);

assert.match(css,/sf-v218-panel/);
assert.match(css,/sf-v218-sequencer/);
assert.match(css,/sf-v218-lasso/);
assert.match(css,/sf-v218-selected/);
assert.match(css,/sf-v218-cc-graph/);

console.log('STEM_MIDI_COMPOSITION_V218=PASS');
