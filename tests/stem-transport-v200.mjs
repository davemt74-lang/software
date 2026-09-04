import fs from 'node:fs';
import assert from 'node:assert/strict';
import vm from 'node:vm';

const source=fs.readFileSync('admin/stem-transport-v200.js','utf8');
const sandbox={setTimeout,clearTimeout,console};
sandbox.globalThis=sandbox;
vm.runInNewContext(source,sandbox);
const transport=sandbox.StonefellowStemTransportV200;
const hardening=sandbox.StonefellowStemTransportHardeningV208;

class MockAudio extends EventTarget{
  constructor(){super();this._currentTime=0;this.seeking=false;this.paused=true;this.assignments=0;this.dataset={};}
  get currentTime(){return this._currentTime;}
  set currentTime(value){this.assignments+=1;this._currentTime=Number(value);this.seeking=true;}
  completeSeek(value=this._currentTime){this._currentTime=Number(value);this.seeking=false;this.dispatchEvent(new Event('seeked'));}
  fail(){this.dispatchEvent(new Event('error'));}
  pause(){this.paused=true;}
  play(){this.paused=false;return Promise.resolve();}
}

assert.equal(transport.mediaTargetReached({currentTime:4.02,seeking:false},4,.055),true);
assert.equal(transport.mediaTargetReached({currentTime:4.2,seeking:false},4,.055),false);
assert.equal(transport.__STONEFELLOW_SEEK_RECOVERY_V230__,true);
assert.equal(transport.__STONEFELLOW_SEEK_RECOVERY_V234__,true);

{
  const audio=new MockAudio();
  const pending=transport.waitForSeek(audio,8,{timeoutMs:300,generation:2,isCurrent:value=>value===2});
  setTimeout(()=>audio.completeSeek(8.01),5);
  assert.equal(await pending,8.01);
}
{
  const audio=new MockAudio();
  const landings=[7.82,8];
  Object.defineProperty(audio,'currentTime',{
    get(){return audio._currentTime;},
    set(value){
      audio.assignments+=1;
      audio.seeking=true;
      const landing=landings.length?landings.shift():Number(value);
      setTimeout(()=>audio.completeSeek(landing),3);
    },
    configurable:true
  });
  const corrected=await transport.waitForSeek(audio,8,{timeoutMs:400,generation:3,isCurrent:value=>value===3,tolerance:.055});
  assert.ok(Math.abs(corrected-8)<=.055);
  assert.ok(audio.assignments>=2,'canonical transport must correct an imprecise first seek landing');
}
{
  const audio=new MockAudio();
  let generation=3;
  const pending=transport.waitForSeek(audio,2,{timeoutMs:300,generation:3,isCurrent:value=>value===generation});
  generation=4;
  setTimeout(()=>audio.completeSeek(2),5);
  await assert.rejects(pending,/Stale/);
}
{
  const audio=new MockAudio();
  const degraded=await transport.waitForSeek(audio,2,{timeoutMs:260});
  assert.ok(Number.isFinite(degraded));
  assert.equal(audio.__STONEFELLOW_SEEK_DEGRADED_V234__,true);
  assert.equal(transport.mediaDrift(audio,2),null,'degraded media must not trigger repeated drift recovery');
}

assert.equal(transport.expectedStemTime({sourceTempo:120},{sourceStart:2,timelineStart:4},6,120),4);
assert.equal(transport.expectedStemTime({sourceTempo:60},{sourceStart:2,timelineStart:4},6,120),6);
assert.equal(transport.mediaDrift({paused:false,seeking:false,currentTime:4.09},4),.08999999999999986);
assert.equal(transport.driftRequiresRecovery([.01,.03]),false);
assert.equal(transport.driftRequiresRecovery([.03]),false,'small single-track drift must not recover');
assert.equal(transport.driftRequiresRecovery([.2]),true,'large single-track drift must recover against the master clock');
assert.equal(transport.driftRequiresRecovery([-.02,.09]),true);
assert.equal(transport.driftRequiresRecovery([.14,.15]),true);

assert.ok(hardening,'v208 transport hardening API must be bundled with v200');
assert.equal(hardening.build,'stem-transport-hardening-v208-20260901');
assert.deepEqual(Array.from(hardening.snapModes),['free','bar','beat','1/2','1/4','1/8','1/16']);
assert.deepEqual({...hardening.parseSignature('6/8')},{numerator:6,denominator:8});
assert.equal(hardening.beatSeconds(120,'4/4'),.5);
assert.equal(hardening.barSeconds(120,'4/4'),2);
assert.equal(hardening.beatSeconds(120,'6/8'),.25);
assert.equal(hardening.barSeconds(120,'6/8'),1.5);
assert.equal(hardening.snapStepSeconds('1/8',120,'4/4'),.25);
assert.equal(hardening.snapStepSeconds('1/16',120,'4/4'),.125);
assert.equal(hardening.snapTime(.76,'beat',120,'4/4',48000),1);
assert.equal(hardening.snapTime(.37,'1/16',120,'4/4',48000),.375);
assert.equal(hardening.roundToSample(1/96000,48000),1/48000);
assert.deepEqual({...hardening.quantizeRange(1.03,1.96,'beat',120,'4/4',48000)},{start:1,end:2});
assert.equal(hardening.formatBBT(0,120,'4/4'),'001|01|000');
assert.equal(hardening.formatBBT(2.75,120,'4/4'),'002|02|480');
assert.equal(hardening.formatClock(65.432),'1:05.432');
assert.ok(hardening.zoomAroundAnchor(1,-120,100,1000,5000,.25).zoom>1);
assert.ok(hardening.zoomAroundAnchor(1,120,100,1000,5000,.75).zoom<1);
assert.match(source,/stemSnapModeV208/);
assert.match(source,/stemCountInV208/);
assert.match(source,/stemQuantizeLoopV208/);
assert.match(source,/stonefellow:stem-v208-quantized/);
assert.match(source,/clip_trim/);
assert.match(source,/clip_move/);
assert.doesNotMatch(source,/SpeechRecognition|premium-voice|ElevenLabs|chatVoiceButton/);


const spaceListeners={};
let buttonBlurred=false;
const spaceHost={
  document:{activeElement:null},
  addEventListener(type,handler){spaceListeners[type]=handler;}
};
const focusedButton={
  matches:selector=>selector.includes('button'),
  blur(){buttonBlurred=true;spaceHost.document.activeElement={tagName:'BODY'};}
};
spaceHost.document.activeElement=focusedButton;
assert.equal(hardening.installSpaceTransportGuard(spaceHost),true);
assert.equal(hardening.installSpaceTransportGuard(spaceHost),false,'V208 Space guard must install only once');
let keydownPrevented=false;
spaceListeners.keydown({
  code:'Space',altKey:false,ctrlKey:false,metaKey:false,shiftKey:false,
  target:{closest:()=>null},
  preventDefault(){keydownPrevented=true;}
});
assert.equal(keydownPrevented,true);
assert.equal(buttonBlurred,true);
assert.equal(spaceHost.document.activeElement.tagName,'BODY');
let keyupPrevented=false;
spaceListeners.keyup({code:'Space',preventDefault(){keyupPrevented=true;}});
assert.equal(keyupPrevented,true);
assert.match(source,/function installSpaceTransportGuard/);

const offlineListeners={};
let offlinePauseCalls=0;
const failedMedia={
  preload:'auto',
  pause(){offlinePauseCalls+=1;},
  play(){return Promise.resolve();}
};
const failedStem={
  id:77,
  mediaUnavailable:false,
  pendingPlay:true,
  pendingBoundarySeek:true,
  pendingCrossfadePlay:true,
  pendingCrossfadeSeek:true,
  audio:failedMedia,
  crossfadeAudio:null
};
const offlineHost={
  Promise,
  setTimeout,
  addEventListener(type,handler){offlineListeners[type]=handler;},
  STONEFELLOW_STUDIO_RUNTIME_V87:{getStem:id=>Number(id)===77?failedStem:null}
};
assert.equal(transport.installStemMediaOfflineBridge(offlineHost),true);
assert.equal(transport.installStemMediaOfflineBridge(offlineHost),false,'media failure bridge must install only once');
offlineListeners['stonefellow:stem-media-offline']({detail:{stemId:77}});
assert.equal(failedStem.mediaUnavailable,true);
assert.equal(failedStem.pendingPlay,false);
assert.equal(failedStem.pendingBoundarySeek,false);
assert.equal(failedStem.pendingCrossfadePlay,false);
assert.equal(failedStem.pendingCrossfadeSeek,false);
assert.equal(failedMedia.preload,'none');
assert.equal(offlinePauseCalls,1);
assert.equal(failedMedia.__STONEFELLOW_MEDIA_OFFLINE_V231__,true);
assert.equal(typeof failedMedia.play,'function');
await failedMedia.play();
assert.match(source,/function installStemMediaOfflineBridge/);
assert.match(source,/stonefellow:stem-media-offline/);
assert.match(source,/stonefellow:stem-runtime-ready/);
assert.doesNotMatch(source,/attempt<100|attempt\+1/,'offline failures must not expire on a fixed runtime-init timer');

const delayedListeners={};
const delayedMedia={preload:'auto',pause(){},play(){return Promise.resolve();}};
const delayedStem={
  id:88,mediaUnavailable:false,pendingPlay:true,pendingBoundarySeek:true,
  pendingCrossfadePlay:true,pendingCrossfadeSeek:true,audio:delayedMedia,crossfadeAudio:null
};
const delayedHost={Promise,addEventListener(type,handler){delayedListeners[type]=handler;}};
assert.equal(transport.installStemMediaOfflineBridge(delayedHost),true);
delayedListeners['stonefellow:stem-media-offline']({detail:{stemId:88}});
assert.equal(delayedStem.mediaUnavailable,false,'offline state may arrive before editor runtime exists');
delayedHost.STONEFELLOW_STUDIO_RUNTIME_V87={getStem:id=>Number(id)===88?delayedStem:null};
delayedListeners['stonefellow:stem-runtime-ready']({detail:{}});
assert.equal(delayedStem.mediaUnavailable,true,'pending offline state must apply when canonical editor runtime is ready');
await delayedMedia.play();

const schedulerSource=fs.readFileSync('admin/stem-buffer-scheduler-v202.js','utf8');
assert.doesNotMatch(schedulerSource,/installTransportSeekRecovery|Object\.defineProperty\(host,'StonefellowStemTransportV200'/,'scheduler must not patch canonical seek authority');
assert.doesNotMatch(schedulerSource,/installSpaceTransportGuard/,'scheduler must not own transport UI keyboard guards');
assert.match(source,/stonefellow:stem-seek-degraded/);
assert.match(source,/seek_timeout/);

const studio=fs.readFileSync('admin/stem-editor.js','utf8');
assert.match(studio,/StonefellowStemTransportV200/);
assert.match(studio,/transportGeneration/);
assert.match(studio,/monitorTransportDrift/);
assert.doesNotMatch(studio,/stonefellow:stem-media-offline/,'canonical editor must not duplicate transport-owned offline handling');
assert.match(studio,/function setStemPlayback\([\s\S]*?stem\.mediaUnavailable[\s\S]*?pendingCrossfadeSeek\s*=\s*false/,'media transport must skip unavailable stems');
assert.match(studio,/stonefellow:stem-runtime-ready/,'canonical editor must announce runtime readiness');
assert.match(studio,/cfg\.timeStretchWorkletUrl/,'time-stretch engine must consume the cache-busted worklet URL');
assert.doesNotMatch(studio.match(/function waitForSeek[\s\S]*?\n  }/)?.[0]||'',/'canplay'/);

console.log('STEM_TRANSPORT_V200=PASS');
console.log('STEM_TRANSPORT_HARDENING_V208=PASS');
