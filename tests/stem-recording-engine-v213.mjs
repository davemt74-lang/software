import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source=fs.readFileSync('admin/stem-recording-engine-v213.js','utf8');
const hardening=fs.readFileSync('admin/stem-recording-engine-v213-hardening.js','utf8');
const css=fs.readFileSync('admin/stem-recording-engine-v213.css','utf8');
const endpoint=fs.readFileSync('api/stem-recording-v213.php','utf8');
const wrapper=fs.readFileSync('admin/stems.php','utf8');
const core=fs.readFileSync('admin/stems-v108.js','utf8');
const v210=fs.readFileSync('admin/stem-professional-editing-v210.js','utf8');
const v212=fs.readFileSync('admin/stem-recording-takes-v212.js','utf8');

const sandbox={console};
sandbox.globalThis=sandbox;
vm.createContext(sandbox);
vm.runInContext(source,sandbox,{filename:'stem-recording-engine-v213.js'});
const api=sandbox.StonefellowStemRecordingEngineV213;
assert.ok(api,'v213 API should load');
assert.equal(api.build,'stem-recording-engine-v213-20260901');
assert.equal(api.baseTakeName('Lead Vocal Take 7'),'Lead Vocal');
assert.equal(api.baseTakeName('Lead Vocal · Take 03'),'Lead Vocal');
assert.equal(api.baseTakeName('Lead Vocal'),'Lead Vocal');
assert.deepEqual(Array.from(api.parseTakeIds('4,4,0,8,bad,12')),[4,8,12]);
assert.deepEqual(JSON.parse(JSON.stringify(api.validRange({start:8,end:12}))),{start:8,end:12});
assert.equal(api.validRange({start:8,end:8}),null);

const main=api.normalizeRecordingTarget({id:10,name:'Lead Vocal',role:'Vocal',isEmptyRecordingTrack:false,takeOfStemId:0});
assert.equal(main.parentId,10);
assert.equal(main.archive,true);
assert.equal(main.name,'Lead Vocal');
const take=api.normalizeRecordingTarget({id:11,name:'Lead Vocal Take 2',role:'Vocal',isEmptyRecordingTrack:false,takeOfStemId:10});
assert.equal(take.parentId,10);
assert.equal(take.name,'Lead Vocal');
const empty=api.normalizeRecordingTarget({id:20,name:'Vocal 2',role:'Vocal',isEmptyRecordingTrack:true,takeOfStemId:0});
assert.equal(empty.archive,false);

const mix={stems:[{id:10,clips:[{id:'a',muted:false}]},{id:11,clips:[{id:'b',muted:false},{id:'c',muted:true}]}]};
const muted=api.muteStemClips(mix,[11],true);
assert.equal(muted.changed,1);
assert.equal(muted.state.stems[1].clips[0].muted,true);
assert.equal(muted.state.stems[1].clips[1].muted,true);
assert.equal(mix.stems[1].clips[0].muted,false,'pure helper must not mutate source state');

assert.match(source,/recording_start/);
assert.match(source,/recording_finish/);
assert.match(source,/recording_cancel/);
assert.match(source,/prepare_take/);
assert.match(source,/commit_take/);
assert.match(source,/cleanup_take/);
assert.match(source,/target_stem_id/);
assert.match(source,/stem_role/);
assert.match(source,/START TAKES/);
assert.match(source,/STOP AFTER PASS/);
assert.match(source,/recordPunchFromLoop/);
assert.match(source,/compTakeRange/);
assert.match(source,/stonefellow:stem-recording-engine-v213/);
assert.doesNotMatch(source,/SpeechRecognition|ElevenLabs|premium-voice|conversation-voice|chatVoiceButton/);

assert.match(hardening,/stem-recording-engine-v213-hardening-20260901/);
assert.match(hardening,/observer\.observe\(document\.body/,'save-dialog recovery must observe the whole document');
assert.match(hardening,/root\.getComputedStyle\(dialog\)/);
assert.match(hardening,/familyNeedsMute/,'legacy pristine take families should initialize with alternate takes muted');
assert.match(hardening,/action','reconcile'/);
assert.match(hardening,/saveWasOpen&&!open/,'a closed recording save dialog should trigger archive reconciliation');
assert.match(hardening,/getLoopSession/,'hardening should cover loop-save dialogs even outside #stemStudio');
assert.doesNotMatch(hardening,/SpeechRecognition|ElevenLabs|premium-voice|conversation-voice|chatVoiceButton/);

assert.match(endpoint,/require_permission\('chat\.access'\)/);
assert.match(endpoint,/verify_csrf\(\)/);
assert.match(endpoint,/can_manage_track_production/);
assert.match(endpoint,/prepare_take/);
assert.match(endpoint,/commit_take/);
assert.match(endpoint,/cleanup_take/);
assert.match(endpoint,/reconcile/);
assert.match(endpoint,/stem_v213_recording_completed/);
assert.match(endpoint,/glob\(\$dir \. '\/\*\.wav'\)/,'completed core recordings should self-heal provisional archive commits');
assert.match(endpoint,/max_age_hours/);
assert.match(endpoint,/copy\(\$source,\$createdFile\)/);
assert.match(endpoint,/Take of stem:/);
assert.match(endpoint,/V213 take archive:/);
assert.match(endpoint,/plugin_chain_json/);
assert.match(endpoint,/FOR UPDATE/);
assert.match(endpoint,/already_committed/,'take commit should be idempotent');
assert.match(endpoint,/Committed takes cannot be removed/);

assert.match(core,/recordingTrackName/,'v213 must extend the established recorder');
assert.match(core,/recording_finish/,'core recorder finalization must remain intact');
assert.match(v210,/compTakeRange/,'v213 punch composites reuse the v210 comp engine');
assert.match(v212,/getFamilies/,'v213 take-family resolution reuses v212');

assert.match(css,/sf-v213-loop-takes/);
assert.match(css,/sf-v213-status/);

assert.match(wrapper,/\$recordingEngineToken = 'stem-recording-engine-v213-20260901';/);
assert.match(wrapper,/STONEFELLOW_STEM_RECORDING_V213/);
assert.match(wrapper,/api\/stem-recording-v213\.php/);
assert.match(wrapper,/data-stem-recording-v213/);
assert.match(wrapper,/data-stem-recording-v213-hardening/);
assert.match(wrapper,/stem-recording-engine-v213\.js\?v=/);
assert.match(wrapper,/stem-recording-engine-v213-hardening\.js\?v=/);
assert.match(wrapper,/stem-recording-engine-v213\.css\?v=/);
assert.match(wrapper,/stem-recording-engine-v213-20260901/);
assert.match(wrapper,/stem-recording-engine-v213-hardening-20260901/);
const v212Index=wrapper.indexOf('data-stem-recording-v212');
const v213Index=wrapper.indexOf('data-stem-recording-v213');
const v213HardeningIndex=wrapper.indexOf('data-stem-recording-v213-hardening');
assert.ok(v212Index>=0&&v213Index>v212Index,'v213 should load after the v212 take manager');
assert.ok(v213HardeningIndex>v213Index,'v213 recovery hardening should load after the engine');

console.log('STEM_RECORDING_ENGINE_V213=PASS');
