import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source=fs.readFileSync('admin/stem-recording-takes-v212.js','utf8');
const css=fs.readFileSync('admin/stem-recording-takes-v212.css','utf8');
const wrapper=fs.readFileSync('admin/stems.php','utf8');
const core=fs.readFileSync('admin/stems-v108.js','utf8');
const legacy=fs.readFileSync('admin/stems-legacy-v108.php','utf8');
const v210=fs.readFileSync('admin/stem-professional-editing-v210.js','utf8');

const sandbox={console};sandbox.globalThis=sandbox;vm.createContext(sandbox);vm.runInContext(source,sandbox,{filename:'stem-recording-takes-v212.js'});
const api=sandbox.StonefellowStemRecordingTakesV212;
assert.ok(api,'v212 API should load');
assert.equal(api.build,'stem-recording-takes-v212-20260901');
assert.equal(api.takeNumber('Lead Vocal Take 7'),7);
assert.equal(api.takeNumber('Lead Vocal'),0);
assert.equal(api.baseTakeName('Lead Vocal Take 12'),'Lead Vocal');
assert.equal(api.ratingValue(9),5);
assert.equal(api.ratingValue(-1),0);

const stems=[{id:10,name:'Lead Vocal'},{id:11,name:'Lead Vocal Take 2'},{id:12,name:'Lead Vocal Take 1'},{id:20,name:'Bass'}];
const takeOf=new Map([[11,10],[12,10]]);
const families=api.buildTakeFamilies(stems,id=>takeOf.get(id)||0);
assert.equal(families.size,1);
const family=api.familyForStem(families,11);
assert.equal(family.parentId,10);
assert.deepEqual(Array.from(family.members,member=>member.id),[10,12,11]);
assert.equal(api.nextFamilyMember(family,10,1).id,12);
assert.equal(api.nextFamilyMember(family,10,-1).id,11);

assert.ok(legacy.includes('/Take of stem:\\s*(\\d+)/i'),'server-rendered Stem Studio must retain take metadata parsing');
assert.match(core,/takeOfStemId/,'core runtime must retain take-family metadata');
assert.match(core,/recordPunchToggle/,'v212 must extend existing punch recording instead of replacing it');
assert.match(core,/recordCountInBars/,'v212 must extend existing count-in instead of replacing it');
assert.match(v210,/compSelectedTake/,'v212 comp must reuse v210 comp engine');
assert.match(v210,/getTakeInfo:selectedTakeInfo/,'v210 take identification must remain available');

assert.match(source,/RANGE → PUNCH/);
assert.match(source,/COUNT/);
assert.match(source,/AUDITION/);
assert.match(source,/COMP RANGE/);
assert.match(source,/HIDE TAKES/);
assert.match(source,/RESTORE MIX/);
assert.match(source,/compSelectedTake/);
assert.match(source,/recordPunchFromLoop/);
assert.match(source,/recordCountInBars/);
assert.match(source,/stonefellow:stem-edit-v210/);
assert.match(source,/stonefellow:stem-automation-mixer-v211/);
assert.doesNotMatch(source,/SpeechRecognition|ElevenLabs|premium-voice|chatVoiceButton|conversation-voice/);

assert.match(css,/sf-v212-toolbar/);
assert.match(css,/sf-v212-take-badge/);
assert.match(css,/sf-v212-take-child/);
assert.match(css,/sf-v212-status/);

assert.match(wrapper,/\$recordingTakesToken = 'stem-recording-takes-v212-20260901';/);
assert.match(wrapper,/data-stem-recording-v212/);
assert.match(wrapper,/stem-recording-takes-v212\.js\?v=/);
assert.match(wrapper,/stem-recording-takes-v212\.css\?v=/);
assert.match(wrapper,/stem-recording-takes-v212-20260901/);
const v211Index=wrapper.indexOf('data-stem-automation-v211');
const v212Index=wrapper.indexOf('data-stem-recording-v212');
assert.ok(v211Index>=0&&v212Index>v211Index,'v212 should load after v211 automation/mixer runtime');

console.log('STEM_RECORDING_TAKES_V212=PASS');
