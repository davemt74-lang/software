import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';

const source=fs.readFileSync('admin/stem-render-export-v214.js','utf8');
const hardening=fs.readFileSync('admin/stem-render-export-v214-hardening.js','utf8');
const css=fs.readFileSync('admin/stem-render-export-v214.css','utf8');
const endpoint=fs.readFileSync('api/stem-render-v214.php','utf8');
const wrapper=fs.readFileSync('admin/stems.php','utf8');
const live=fs.readFileSync('admin/stem-live-recording-v107.js','utf8');
const core=fs.readFileSync('admin/stems-v108.js','utf8');
const v210=fs.readFileSync('admin/stem-professional-editing-v210.js','utf8');

const sandbox={console};sandbox.globalThis=sandbox;vm.createContext(sandbox);vm.runInContext(source,sandbox,{filename:'stem-render-export-v214.js'});
const api=sandbox.StonefellowStemRenderExportV214;
assert.ok(api,'v214 API should load');
assert.equal(api.build,'stem-render-export-v214-20260901');

assert.equal(api.sanitizeFilename(' Song: Master?  '),'Song Master');
assert.deepEqual(JSON.parse(JSON.stringify(api.validRange({start:2,end:5},10))),{start:2,end:5,duration:3});
assert.equal(api.validRange({start:5,end:5},10),null);
assert.equal(api.isVocalRole('Lead Vocal'),true);
assert.equal(api.isVocalRole('Bass'),false);

const state={stems:{'1':{id:1,volume:1,muted:false,solo:true,plugins:[{enabled:true}]},'2':{id:2,volume:1,muted:false,solo:false,plugins:[{enabled:true}]}}};
const agent={stems:[{id:1,name:'Lead',role:'Vocal'},{id:2,name:'Bass',role:'Bass'}]};
let result=api.prepareVariantState(state,agent,'instrumental');
assert.equal(result.state.stems['1'].muted,true);
assert.equal(result.state.stems['2'].muted,false);
assert.equal(result.state.stems['1'].solo,false);
assert.equal(state.stems['1'].muted,false,'variant helper must not mutate original mix state');
result=api.prepareVariantState(state,agent,'acapella');
assert.equal(result.state.stems['1'].muted,false);
assert.equal(result.state.stems['2'].muted,true);
result=api.prepareVariantState(state,agent,'vocal_up',{vocalDeltaDb:2});
assert.ok(result.state.stems['1'].volume>1);
assert.equal(result.state.stems['2'].volume,1);
result=api.prepareVariantState(state,agent,'stem',{stemId:2,includeTrackFx:false});
assert.equal(result.state.stems['1'].muted,true);
assert.equal(result.state.stems['2'].muted,false);
assert.equal(result.state.stems['2'].plugins[0].enabled,false);

assert.equal(api.buildJobs('queue',agent).length,5);
assert.deepEqual(Array.from(api.buildJobs('all_stems',agent),job=>job.stemId),[1,2]);
assert.deepEqual(Array.from(api.buildJobs('selected_stems',agent,[2]),job=>job.stemId),[2]);

const left=new Float32Array([0,.5,-.5,1]);
const right=new Float32Array([0,.25,-.25,.5]);
const resampled=api.linearResample([left,right],48000,96000);
assert.equal(resampled[0].length,8);
const mono=api.downmixMono([left,right]);
assert.equal(mono.length,1);
assert.ok(Math.abs(mono[0][1]-.375)<1e-6);
const analysis=api.analyzePcm([left,right],48000);
assert.ok(analysis.peakDb<=0.001&&analysis.peakDb>=-.01);
assert.ok(Number.isFinite(analysis.rmsDb));
const normalized=api.normalizePcm([new Float32Array([.5,-.5])],48000,{mode:'peak',peakTargetDb:-6,ceilingDb:-.3});
assert.ok(Math.abs(normalized.after.peakDb+6)<.08);

for(const depth of [16,24,32]){
  const wav=api.encodeWav([left,right],48000,depth,false,214);
  const view=new DataView(wav);
  const text=(offset,length)=>Array.from({length},(_,i)=>String.fromCharCode(view.getUint8(offset+i))).join('');
  assert.equal(text(0,4),'RIFF');
  assert.equal(text(8,4),'WAVE');
  assert.equal(text(36,4),'data');
  assert.equal(view.getUint16(22,true),2);
  assert.equal(view.getUint32(24,true),48000);
  assert.equal(view.getUint16(34,true),depth);
  assert.equal(view.getUint16(20,true),depth===32?3:1);
}

assert.match(live,/getMasterSource/,'existing live recorder must prove post-master capture support');
assert.match(live,/getStemCaptureSource/,'existing live recorder must prove post-fader stem capture support');
assert.match(core,/getMasterSource:\(\) => masterLiveMixTap \|\| masterAnalyser/,'core must retain real post-master capture node');
assert.match(core,/getStemCaptureSource:id =>/,'core must retain per-stem capture nodes');
assert.match(core,/channelPlugins:Object\.fromEntries/,'core mix state must retain fixed/master insert chains');
assert.match(core,/timelineSurface\?\.addEventListener\('click'/,'core must retain track-area timeline seek path used by v214 bridge');
assert.match(core,/const trackArea = event\.target\.closest/,'core timeline seek must resolve from a track/clip target');
assert.match(v210,/getRange/,'v214 selected-range render must reuse v210 range selection');

assert.match(source,/createScriptProcessor/,'v214 must print the live graph rather than download sources');
assert.match(source,/getMasterSource/);
assert.match(source,/getStemCaptureSource/);
assert.match(source,/Selected Range/);
assert.match(source,/Master Delivery Set/);
assert.match(source,/Instrumental/);
assert.match(source,/Acapella/);
assert.match(source,/Vocal Up/);
assert.match(source,/Vocal Down/);
assert.match(source,/All Stems/);
assert.match(source,/Selected Stems/);
assert.match(source,/44\.1 kHz/);
assert.match(source,/96 kHz/);
assert.match(source,/32-bit float/);
assert.match(source,/normalizePcm/);
assert.match(source,/integratedLufs/);
assert.match(source,/truePeakApprox/);
assert.match(source,/encodeWav/);
assert.match(source,/transcode_mp3/);
assert.match(source,/MP3 unavailable — WAV created instead/);
assert.match(source,/SOURCE FILES/,'existing source-download workflow must remain reachable');
assert.doesNotMatch(source,/SpeechRecognition|ElevenLabs|premium-voice|conversation-voice|chatVoiceButton/);

assert.match(hardening,/renderSafeState/);
assert.match(hardening,/next\.loop\.active=false/,'render snapshot must disable active loop playback');
assert.match(hardening,/next\.channelPlugins\.master/,'master-FX bypass must use the production mix-state field');
assert.match(hardening,/\.daw-arrange-track/,'seek bridge must dispatch through an actual track target');
assert.match(hardening,/Stem Studio could not confirm the requested render seek/,'seek bridge must verify final transport position');
assert.match(hardening,/originalMix/,'render hardening must snapshot the true pre-render mix');
assert.match(hardening,/restoreOriginalMix/,'render hardening must restore the true pre-render mix after printing');
assert.match(hardening,/LUFS \(estimate\)/,'non-standards-metering readout must be labeled as an estimate');
assert.match(hardening,/True peak est\./);
assert.doesNotMatch(hardening,/SpeechRecognition|ElevenLabs|premium-voice|conversation-voice|chatVoiceButton/);

assert.match(endpoint,/require_permission\('chat\.access'\)/);
assert.match(endpoint,/verify_csrf\(\)/);
assert.match(endpoint,/can_manage_track_production/);
assert.match(endpoint,/is_uploaded_file/);
assert.match(endpoint,/RIFF/);
assert.match(endpoint,/WAVE/);
assert.match(endpoint,/536870912/);
assert.match(endpoint,/ffmpeg/);
assert.match(endpoint,/libmp3lame/);
assert.match(endpoint,/escapeshellarg/);
assert.match(endpoint,/Content-Type: audio\/mpeg/);
assert.doesNotMatch(endpoint,/INSERT INTO|UPDATE track_|DELETE FROM track_/,'v214 transcode service must not mutate project/database content');

assert.match(css,/sf-v214-modal/);
assert.match(css,/sf-v214-results/);
assert.match(css,/sf-v214-toolbar-button/);

assert.match(wrapper,/\$renderExportToken = 'stem-render-export-v214-20260901';/);
assert.match(wrapper,/STONEFELLOW_STEM_RENDER_V214/);
assert.match(wrapper,/api\/stem-render-v214\.php/);
assert.match(wrapper,/data-stem-render-v214/);
assert.match(wrapper,/data-stem-render-v214-hardening/);
assert.match(wrapper,/stem-render-export-v214\.js\?v=/);
assert.match(wrapper,/stem-render-export-v214-hardening\.js\?v=/);
assert.match(wrapper,/stem-render-export-v214\.css\?v=/);
assert.match(wrapper,/stem-render-export-v214-20260901/);
assert.match(wrapper,/stem-render-export-v214-hardening-20260901/);
assert.match(wrapper,/data-stem-recording-v213-hardening/,'v214 wrapper must preserve the merged v213 hardening runtime');
const v213Index=wrapper.indexOf('data-stem-recording-v213-hardening');
const v214Index=wrapper.indexOf('data-stem-render-v214');
const v214HardeningIndex=wrapper.indexOf('data-stem-render-v214-hardening');
assert.ok(v213Index>=0&&v214Index>v213Index,'v214 should load after the complete v213 recording/hardening stack');
assert.ok(v214HardeningIndex>v214Index,'v214 hardening must load after the base renderer');

console.log('STEM_RENDER_EXPORT_V214=PASS');
