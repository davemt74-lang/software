import fs from 'node:fs';
import assert from 'node:assert/strict';
import vm from 'node:vm';

const source=fs.readFileSync('admin/stem-buffer-scheduler-v202.js','utf8');
const defaultFetchCalls=[];
const sandbox={
  console,
  fetch:async(...args)=>{
    defaultFetchCalls.push(args);
    return {ok:true,arrayBuffer:async()=>new ArrayBuffer(8)};
  }
};
sandbox.globalThis=sandbox;
vm.runInNewContext(source,sandbox);
const api=sandbox.StonefellowStemBufferSchedulerV202;
assert.equal('installTransportSeekRecovery' in api,false,'scheduler must not expose seek authority');
assert.equal('installStemMediaOfflineBridge' in api,false,'scheduler must not own media failure neutralization');
assert.doesNotMatch(source,/function installStemMediaOfflineBridge/,'scheduler source must not contain media failure neutralization');
assert.equal('installSpaceTransportGuard' in api,false,'scheduler must not own transport UI keyboard guards');
assert.doesNotMatch(source,/function installSpaceTransportGuard/,'scheduler source must not contain transport UI keyboard guards');
assert.doesNotMatch(source,/Object\.defineProperty\(host,'StonefellowStemTransportV200'/,'scheduler must not patch canonical transport');

const clip={timelineStart:4,timelineLength:8,sourceStart:2,sourceEnd:10,gainDb:-6,fadeIn:1,fadeOut:2};
let plan=api.planClip(clip,{position:0,projectEnd:20,projectRate:1,mediaRate:1,bufferDuration:20});
assert.equal(plan.when,4);
assert.equal(plan.offset,2);
assert.equal(plan.sourceDuration,8);
assert.equal(plan.timelineEnd,12);
assert.equal(plan.gainAtStart,0);
assert.equal(plan.fadeInRemaining,1);

plan=api.planClip(clip,{position:6,projectEnd:20,projectRate:1,mediaRate:1,bufferDuration:20});
assert.equal(plan.when,0);
assert.equal(plan.offset,4);
assert.equal(plan.sourceDuration,6);
assert.ok(Math.abs(plan.gainAtStart-Math.pow(10,-6/20))<1e-12);
assert.equal(plan.fadeInRemaining,0);

plan=api.planClip(clip,{position:0,projectEnd:20,projectRate:2,mediaRate:1,bufferDuration:20});
assert.equal(plan.when,2);
assert.equal(plan.sourceDuration,4);
assert.equal(plan.timelineEnd,12);

assert.equal(api.planClip({...clip,muted:true},{position:0,projectEnd:20,projectRate:1,mediaRate:1,bufferDuration:20}),null);
assert.equal(api.planClip(clip,{position:13,projectEnd:20,projectRate:1,mediaRate:1,bufferDuration:20}),null);

class Param{constructor(){this.value=1;this.events=[];}cancelScheduledValues(t){this.events.push(['cancel',t]);}setValueAtTime(v,t){this.value=v;this.events.push(['set',v,t]);}linearRampToValueAtTime(v,t){this.events.push(['ramp',v,t]);}}
class Node{constructor(){this.connections=[];}connect(node){this.connections.push(node);}disconnect(){this.connections=[];}}
class Source extends Node{constructor(){super();this.playbackRate={value:1};this.started=null;this.stopped=false;}start(...args){this.started=args;}stop(){this.stopped=true;}}
const context={
  currentTime:10,
  decodeAudioData:async()=>({duration:20,length:960000,numberOfChannels:2}),
  createBufferSource:()=>new Source(),
  createGain:()=>Object.assign(new Node(),{gain:new Param()})
};
const scheduler=api.createScheduler(context,{fetcher:async()=>({ok:true,arrayBuffer:async()=>new ArrayBuffer(8)})});
const destination=new Node();
await scheduler.prepare(['/audio.wav','/audio.wav']);
assert.equal(scheduler.bufferCount(),1);
assert.equal(scheduler.decodedBytes(),7680000);
assert.equal(scheduler.memoryBudgetExceeded(),false);
const scheduled=await scheduler.schedule([{url:'/audio.wav',clip,position:0,projectEnd:20,projectRate:1,mediaRate:1,destination}],10.05);
assert.equal(scheduled.events.length,1);
assert.equal(scheduled.events[0].when,14.05);
assert.equal(scheduled.startAt,10.05);
assert.equal(scheduler.activeCount(),1);
scheduler.stop();
assert.equal(scheduler.activeCount(),0);

const browserScheduler=api.createScheduler(context);
await browserScheduler.prepare(['/browser-default.wav']);
assert.equal(defaultFetchCalls.length,1);
assert.equal(defaultFetchCalls[0][0],'/browser-default.wav');
assert.equal(defaultFetchCalls[0][1].credentials,'same-origin');

await assert.rejects(
  scheduler.schedule([{url:'/audio.wav',clip,position:0,projectEnd:20,projectRate:1,mediaRate:1,destination}],10.05,{isCurrent:()=>false}),
  /Stale/
);

let largeDecodeCalls=0;
let largeFetchCalls=0;
const largeContext={
  ...context,
  decodeAudioData:async()=>{
    largeDecodeCalls+=1;
    return {duration:60,length:3000000,numberOfChannels:2};
  }
};
const bounded=api.createScheduler(largeContext,{
  maxDecodedBytes:16*1024*1024,
  fetcher:async()=>{
    largeFetchCalls+=1;
    return {ok:true,arrayBuffer:async()=>new ArrayBuffer(8)};
  }
});
await assert.rejects(bounded.prepare(['/large.wav']),error=>error?.code==='STEM_DECODED_AUDIO_BUDGET');
assert.equal(bounded.memoryBudgetExceeded(),true);
assert.equal(bounded.bufferCount(),0);
assert.equal(bounded.decodedBytes(),0);
assert.equal(largeDecodeCalls,1);
assert.equal(largeFetchCalls,1);
await assert.rejects(bounded.prepare(['/large.wav']),error=>error?.code==='STEM_DECODED_AUDIO_BUDGET');
assert.equal(largeDecodeCalls,1,'budget circuit breaker must prevent repeated full decodes');
assert.equal(largeFetchCalls,1,'budget circuit breaker must prevent repeated refetches');

sandbox.STONEFELLOW_STEM_STUDIO={stems:[
  {url:'/known-a.wav',duration:120},
  {url:'/known-b.wav',duration:120}
]};
let preflightFetchCalls=0;
let preflightDecodeCalls=0;
const preflight=api.createScheduler({
  ...context,
  decodeAudioData:async()=>{
    preflightDecodeCalls+=1;
    return {duration:120,length:5760000,numberOfChannels:2};
  }
},{
  maxDecodedBytes:64*1024*1024,
  fetcher:async()=>{
    preflightFetchCalls+=1;
    return {ok:true,arrayBuffer:async()=>new ArrayBuffer(8)};
  }
});
await assert.rejects(
  preflight.prepare(['/known-a.wav','/known-b.wav']),
  error=>error?.code==='STEM_DECODED_AUDIO_BUDGET'&&Number(error?.estimatedDecodedBytes)>64*1024*1024
);
assert.equal(preflightFetchCalls,0,'preflight must avoid downloading a project already known to exceed the decode budget');
assert.equal(preflightDecodeCalls,0,'preflight must avoid decoding a project already known to exceed the decode budget');
assert.equal(preflight.memoryBudgetExceeded(),true);
assert.ok(preflight.estimatedDecodedBytes()>64*1024*1024);
assert.equal(api.configuredProjectRequiresStreaming(64*1024*1024),true);
delete sandbox.STONEFELLOW_STEM_STUDIO;

const hugeBrowserSandbox={
  console,setTimeout,clearTimeout,document:{},globalThis:null,
  STONEFELLOW_STEM_STUDIO:{stems:Array.from({length:10},(_,index)=>({id:index+1,url:`/huge-${index+1}.wav`,duration:180,channels:2,sampleRate:48000}))}
};
hugeBrowserSandbox.globalThis=hugeBrowserSandbox;
vm.runInNewContext(source,hugeBrowserSandbox);
assert.equal(hugeBrowserSandbox.__STONEFELLOW_STEM_STREAMING_ONLY_V234__,true);
assert.equal(hugeBrowserSandbox.StonefellowStemBufferSchedulerV202,null,'oversized browser projects must bypass decoded AudioBuffer transport completely');
assert.equal(typeof hugeBrowserSandbox.StonefellowStemBufferSchedulerV202Runtime?.createScheduler,'function');
assert.equal(hugeBrowserSandbox.StonefellowStemBufferSchedulerV202Runtime.configuredProjectRequiresStreaming(),true);


assert.equal('installProjectLoadingOverlay' in api,false,'scheduler must not own project readiness');
assert.doesNotMatch(source,/stem-project-loader-v232|stem-playback-ready|data-loader-failures/,'scheduler must not contain project loader UI/readiness behavior');

const studio=fs.readFileSync('admin/stem-editor.js','utf8');
const page=fs.readFileSync('admin/stems-legacy-v108.php','utf8');
const wrapper=fs.readFileSync('admin/stems.php','utf8');
assert.match(studio,/StonefellowStemBufferSchedulerV202/);
assert.match(studio,/prepareBufferTransport/);
assert.match(studio,/startBufferTransport/);
assert.match(studio,/scheduled\.startAt/);
assert.match(studio,/Pitch-preserving tempo changes still use the media compatibility transport/);
assert.match(studio,/event\.code === 'Space'/);
assert.match(studio,/if \(armedRecordingStem\(\)\) \{\s*startStudioRecording\(\);/s,'armed-track Space recording behavior must remain intact');
assert.ok(page.indexOf('stem-master-clock-v201.js') < page.indexOf('stem-buffer-scheduler-v202.js'));
assert.ok(page.indexOf('stem-buffer-scheduler-v202.js') < page.indexOf('stem-transport-v200.js'));
assert.ok(page.indexOf('stem-transport-v200.js') < page.indexOf('stems-v79.js'));
assert.doesNotMatch(source,/queueStemWaveform|waveformQueue|data-stem-waveform-canvas/);
assert.match(studio,/const waveformQueue = \[\]/);
assert.match(studio,/queueStemWaveform\(stem\)/);
assert.match(studio,/data-stem-waveform-canvas/);
assert.match(wrapper,/\$transportToken = '[a-f0-9]{8}';/,'transport-family cache token must be content-derived');
assert.match(wrapper,/\$coreToken = '[a-f0-9]{8}';/);
assert.match(wrapper,/'admin\/stem-buffer-scheduler-v202\.js\?v=202' => 'admin\/stem-buffer-scheduler-v202\.js\?v=' \. \$transportToken/);
assert.match(wrapper,/'admin\/stems-v79\.js\?v=101' => 'admin\/stem-editor\.js\?v=' \. \$coreToken/);
assert.doesNotMatch(wrapper,/Open editor anyway|data-loader-force|boot\.classList\.add\('is-slow'\)/,'first-paint preloader must not expose a bypass');
assert.doesNotMatch(wrapper,/STUDIO SAFE RUNTIME · PHASE 2/,'obsolete Phase 2 badge must not be rendered');
assert.doesNotMatch(wrapper,/data-stem-recovery-v227/,'obsolete recovery badge hook must be removed');

console.log('STEM_BUFFER_SCHEDULER_V202=PASS');
