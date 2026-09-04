import fs from 'node:fs';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';

const footer=fs.readFileSync('admin/_footer.php','utf8');
const core=fs.readFileSync('admin/stems-v108.js','utf8');
const midi=fs.readFileSync('admin/stem-midi-v217.js','utf8');
const composition=fs.readFileSync('admin/stem-midi-composition-v218.js','utf8');
const hardening=fs.readFileSync('admin/stem-midi-composition-v218-hardening.js','utf8');

for(const file of ['admin/stems-v108.js','admin/stem-midi-v217.js','admin/stem-midi-composition-v218.js','admin/stem-midi-composition-v218-hardening.js','admin/stem-virtual-midi-keyboard-v219.js']) {
  execFileSync(process.execPath,['--check',file],{stdio:'pipe'});
}

assert.match(footer,/'compositionOwnsSnapshots'=>true/);
assert.match(footer,/delegatedTo:'v218'/);
assert.match(footer,/data-stem-virtual-midi-v219/);
assert.doesNotMatch(hardening,/root\.fetch\s*=/,'hardening may not create a second MIDI fetch coordinator');
assert.match(midi,/getMasterInput/,'MIDI must enter the Studio master input');
assert.match(core,/getMasterInput:\(\) => busInput/,'Studio must publish its normal master input');
assert.doesNotMatch(midi,/masterScale/,'master gain must be applied once by the Studio master graph');
assert.match(midi,/root\.addEventListener\('pageshow'/);
assert.doesNotMatch(midi,/pagehide[\s\S]{0,180}clearInterval\(scheduleTimer\)/);
assert.match(composition,/if\(event\.persisted\) return/);
assert.match(composition,/root\.addEventListener\('pageshow'/);
assert.match(hardening,/if\(event\?\.persisted\)/);
assert.match(hardening,/root\.addEventListener\('pageshow'/);

console.log('STEM_MIDI_RUNTIME_REPAIR_V220=PASS');
