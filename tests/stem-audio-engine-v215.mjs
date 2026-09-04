import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';

const source=fs.readFileSync('admin/stem-audio-engine-v215.js','utf8');
const hardening=fs.readFileSync('admin/stem-audio-engine-v215-hardening.js','utf8');
const css=fs.readFileSync('admin/stem-audio-engine-v215.css','utf8');
const endpoint=fs.readFileSync('api/stem-audio-engine-v215.php','utf8');
const wrapper=fs.readFileSync('admin/stems.php','utf8');
const core=fs.readFileSync('admin/stems-v108.js','utf8');
const v213=fs.readFileSync('admin/stem-recording-engine-v213.js','utf8');
const v214=fs.readFileSync('admin/stem-render-export-v214.js','utf8');
const v214Hardening=fs.readFileSync('admin/stem-render-export-v214-hardening.js','utf8');

execFileSync('php',['-l','api/stem-audio-engine-v215.php'],{stdio:'pipe'});
execFileSync(process.execPath,['--check','admin/stem-audio-engine-v215-hardening.js'],{stdio:'pipe'});

const sandbox={console};sandbox.globalThis=sandbox;vm.createContext(sandbox);vm.runInContext(source,sandbox,{filename:'stem-audio-engine-v215.js'});
const api=sandbox.StonefellowStemAudioEngineV215;
assert.ok(api,'v215 API should load');
assert.equal(api.build,'stem-audio-engine-v215-20260901');

assert.equal(api.pluginLatencyMs([{type:'eq5',enabled:true},{type:'compressor',enabled:true},{type:'limiter',enabled:false}]),6);
assert.equal(api.pluginLatencyMs([{type:'compressor',latency_ms:9,enabled:true}]),9);
assert.equal(api.singlePluginLatencyMs({type:'compressor',params:{lookahead_ms:12},enabled:true}),12);

const basic=api.compensationPlan([
  {id:1,pluginMs:10,manualMs:0},
  {id:2,pluginMs:2,manualMs:0},
  {id:3,pluginMs:2,manualMs:-5},
  {id:4,pluginMs:2,manualMs:8}
]);
const basicById=new Map(Array.from(basic,row=>[row.id,row]));
assert.equal(basicById.get(1).compensationMs,0);
assert.equal(basicById.get(2).compensationMs,8);
assert.equal(basicById.get(3).compensationMs,3);
assert.equal(basicById.get(4).compensationMs,16);
assert.equal(basicById.get(3).resultingMs,5);
assert.equal(basicById.get(4).resultingMs,18);
assert.ok(basic.every(row=>row.compensationMs>=0));

const mix={
  channelPlugins:{
    master:[{type:'limiter',enabled:true}],
    'group-vocals':[{type:'compressor',enabled:true}],
    'aux-a':[{type:'compressor',enabled:true}]
  },
  stems:{
    '1':{id:1,group:'vocals',plugins:[{type:'compressor',enabled:true}],sends:{a:.5,b:0}},
    '2':{id:2,group:'direct',plugins:[],sends:{a:0,b:0}}
  }
};
const pdc=api.computePdcPlan(mix,{pdc:true,tracks:{'2':{manualDelayMs:-4}}},48000);
assert.equal(pdc.masterLatencyMs,6);
assert.equal(pdc.maxBaseLatencyMs,18);
assert.equal(pdc.tracks['1'].paths.main.delayMs,0);
assert.equal(pdc.tracks['1'].paths.auxA.delayMs,0);
assert.equal(pdc.tracks['2'].paths.main.delayMs,8);
assert.equal(pdc.tracks['2'].manualDelayMs,-4);

const settings=api.engineSettings({pdc:false,recordOffsetMs:1200,preRollSeconds:20,postRollSeconds:-4,tracks:{'5':{manualDelayMs:-800,polarity:true}}});
assert.equal(settings.pdc,false);
assert.equal(settings.recordOffsetMs,1000);
assert.equal(settings.preRollSeconds,10);
assert.equal(settings.postRollSeconds,0);
assert.equal(settings.tracks['5'].manualDelayMs,-500);
assert.equal(settings.tracks['5'].polarity,true);

assert.deepEqual(JSON.parse(JSON.stringify(api.contextLatency({baseLatency:.01,outputLatency:.02}))),{baseSeconds:.01,outputSeconds:.02,totalSeconds:.03,totalMs:30});
assert.ok(Math.abs(api.adjustedRecordingStart(10,{baseLatency:.01,outputLatency:.02},5,10,0)-9.955)<1e-9);
assert.ok(Math.abs(api.adjustedRecordingStart(10,{baseLatency:.01,outputLatency:.02},0,0,80)-9.92)<1e-9);
assert.equal(api.adjustedRecordingStart(.01,{baseLatency:.02,outputLatency:.02},0,0,0),0);

assert.deepEqual(JSON.parse(JSON.stringify(api.selectedBounceRange({duration:20,selected_clip_id:'c1',clips:[{id:'c1',start:4,duration:3}]},null))),{start:4,end:7,duration:3,source:'clip'});
assert.deepEqual(JSON.parse(JSON.stringify(api.selectedBounceRange({duration:20,clips:[]},{start:6,end:9}))),{start:6,end:9,duration:3,source:'range'});
assert.equal(api.renderName('Lead Vocal','freeze'),'Lead Vocal Freeze');
const flattened=api.flattenChunks([[new Float32Array([1,2]),new Float32Array([3,4])],[new Float32Array([5]),new Float32Array([6])]],2);
assert.deepEqual(Array.from(flattened[0]),[1,2,5]);
assert.deepEqual(Array.from(flattened[1]),[3,4,6]);

assert.match(core,/getContext:\(\) => context/,'core must expose Web Audio context');
assert.match(core,/getStem:id => stemById/,'core must expose live stem graph');
assert.match(core,/liveCaptureTap/,'core must retain stable post-plugin capture tap');
assert.match(core,/auxASendGain/,'core must retain AUX A send node');
assert.match(core,/auxBSendGain/,'core must retain AUX B send node');
assert.match(v213,/root\.fetch=projectFetch/,'v215 recording compensation must layer after v213 fetch wrapper');
assert.match(v214,/encodeWav/,'v215 freeze/bounce must reuse the v214 WAV encoder');
assert.match(v214Hardening,/runtime\.seek=seekRuntime/,'v215 real-time bounce relies on the verified v214 timeline seek bridge');

assert.match(source,/computePdcPlan/);
assert.match(source,/channelPlugins\?\.master/);
assert.match(source,/group-vocals|standardGroupKey/);
assert.match(source,/auxADelay/);
assert.match(source,/auxBDelay/);
assert.match(source,/createDelay\(2\)/);
assert.match(source,/phase\.gain/);
assert.match(source,/v215_latency_compensation_ms/);
assert.match(source,/getSettings\?\.\(\)\.latency/);
assert.match(source,/calibrateLoopback/);
assert.match(source,/createOscillator/);
assert.match(source,/roundTripMs/);
assert.match(source,/preRollSeconds/);
assert.match(source,/postRollSeconds/);
assert.match(source,/captureRealtime/);
assert.match(source,/captureOffline/);
assert.match(source,/OfflineAudioContext/);
assert.match(source,/registerOfflineProvider/);
assert.match(source,/import_render/);
assert.match(source,/remove_render/);
assert.match(source,/FREEZE TRACK/);
assert.match(source,/COMMIT TRACK/);
assert.match(source,/BOUNCE RANGE \/ CLIP/);
assert.match(source,/plugin_bypass/,'freeze/unfreeze must preserve live plugin enable state');
assert.doesNotMatch(source,/SpeechRecognition|ElevenLabs|premium-voice|conversation-voice|chatVoiceButton/);

assert.match(hardening,/stem-audio-engine-v215-hardening-20260901/);
assert.match(hardening,/registerOfflineProvider/,'hardening must intercept timing-sensitive fast bounces');
assert.match(hardening,/pluginLatencyMs/,'printed insert latency must be measured using the v215 latency model');
assert.match(hardening,/trimFrames/,'printed insert latency must be removed from committed audio');
assert.match(hardening,/sourceEngineSettings\(stemId\)\.polarity/,'polarity must be printed exactly once');
assert.match(hardening,/type:'route'/,'rendered tracks must inherit their source route');
assert.match(hardening,/type:'send'/,'rendered tracks must inherit source sends');
assert.match(hardening,/setTrackDelay\?\.\(renderedId/,'rendered tracks must inherit intentional manual timing');
assert.match(hardening,/save_mix_engine/,'v215 settings must be stored with saved Studio mixes');
assert.match(hardening,/load_mix_engine/,'v215 settings must be restored with saved Studio mixes');
assert.match(hardening,/has_engine!==false/,'legacy mixes must be able to report no v215 sidecar without resetting current engine state');
assert.doesNotMatch(hardening,/SpeechRecognition|ElevenLabs|premium-voice|conversation-voice|chatVoiceButton/);

assert.match(endpoint,/require_permission\('chat\.access'\)/);
assert.match(endpoint,/verify_csrf\(\)/);
assert.match(endpoint,/can_manage_track_production/);
assert.match(endpoint,/is_uploaded_file/);
assert.match(endpoint,/stem_wav_info/);
assert.match(endpoint,/536870912/);
assert.match(endpoint,/V215 .+ of stem:/);
assert.match(endpoint,/import_render/);
assert.match(endpoint,/remove_render/);
assert.match(endpoint,/save_mix_engine/);
assert.match(endpoint,/load_mix_engine/);
assert.match(endpoint,/engineV215/);
assert.match(endpoint,/'has_engine'=>\$hasEngine/,'legacy saved mixes must be explicitly distinguished from v215-aware mixes');
assert.match(endpoint,/UPDATE track_stems SET is_active=0/);
assert.match(endpoint,/Only a v215 rendered stem can be removed/);
assert.doesNotMatch(endpoint,/ALTER TABLE|CREATE TABLE/,'v215 must require no schema migration');

assert.match(css,/sf-v215-toolbar/);
assert.match(css,/sf-v215-track-engine/);
assert.match(css,/sf-v215-modal/);
assert.match(css,/sf-v215-metrics/);
assert.match(css,/sf-v215-capability/);

assert.match(wrapper,/\$audioEngineToken = 'stem-audio-engine-v215-20260901';/);
assert.match(wrapper,/STONEFELLOW_STEM_AUDIO_V215/);
assert.match(wrapper,/api\/stem-audio-engine-v215\.php/);
assert.match(wrapper,/data-stem-audio-v215/);
assert.match(wrapper,/data-stem-audio-v215-hardening/);
assert.match(wrapper,/stem-audio-engine-v215\.js\?v=/);
assert.match(wrapper,/stem-audio-engine-v215-hardening\.js\?v=/);
assert.match(wrapper,/stem-audio-engine-v215\.css\?v=/);
assert.match(wrapper,/stem-audio-engine-v215-hardening-20260901/);
const v214Index=wrapper.indexOf('data-stem-render-v214-hardening');
const v215Index=wrapper.indexOf('data-stem-audio-v215');
const v215HardeningIndex=wrapper.indexOf('data-stem-audio-v215-hardening');
assert.ok(v214Index>=0&&v215Index>v214Index,'v215 should load after the complete v214 render stack');
assert.ok(v215HardeningIndex>v215Index,'v215 hardening must load after the v215 engine');

console.log('STEM_AUDIO_ENGINE_V215=PASS');
