import fs from 'node:fs';
import assert from 'node:assert/strict';
import vm from 'node:vm';

const source=fs.readFileSync('admin/stem-time-stretch-v203.js','utf8');
const sandbox={console};sandbox.globalThis=sandbox;
vm.runInNewContext(source,sandbox);
const api=sandbox.StonefellowStemTimeStretchV203;

const clip={timelineStart:4,timelineLength:8,sourceStart:2,gainDb:-3,fadeIn:1,fadeOut:2};
let plan=api.planEvent(clip,{position:0,projectEnd:20,projectRate:2,mediaRate:1.5,sampleRate:48000,bufferDuration:30});
assert.equal(plan.startDelay,2);
assert.equal(plan.outputDuration,4);
assert.equal(plan.sourceFrame,96000);
assert.equal(plan.rate,1.5);
assert.equal(plan.fadeInDuration,.5);
assert.equal(plan.fadeOutDuration,1);

plan=api.planEvent(clip,{position:6,projectEnd:20,projectRate:2,mediaRate:1.5,sampleRate:48000,bufferDuration:30});
assert.equal(plan.startDelay,0);
assert.equal(plan.outputDuration,3);
assert.equal(plan.sourceFrame,168000);
assert.equal(plan.fadeInDuration,0);

assert.equal(api.planEvent({...clip,muted:true},{position:0,projectEnd:20}),null);

class MockPort{
  constructor(){this.messages=[];}
  postMessage(message,transfer=[]){this.messages.push({message,transfer});}
}
class MockWorkletNode{
  constructor(){this.port=new MockPort();this.destination=null;}
  connect(destination){this.destination=destination;}
}
const engineSandbox={console,AudioWorkletNode:MockWorkletNode};
engineSandbox.globalThis=engineSandbox;
vm.runInNewContext(source,engineSandbox);
const engineApi=engineSandbox.StonefellowStemTimeStretchV203;
const modules=[];
const context={
  currentTime:10,
  sampleRate:48000,
  audioWorklet:{addModule:async url=>modules.push(url)}
};
const engine=engineApi.createEngine(context,{workletUrl:'/worklet.js'});
const buffer={
  duration:30,
  numberOfChannels:2,
  getChannelData:()=>new Float32Array(480)
};
await engine.prepareTrack('track-1',buffer,{});
assert.deepEqual(modules,['/worklet.js']);
assert.equal(engine.trackCount(),1);
const scheduled=engine.schedule('track-1',[clip],{position:0,projectEnd:20,projectRate:2,mediaRate:1.5,startAt:10.1});
assert.equal(scheduled.startAt,10.1);
assert.equal(scheduled.eventCount,1);
assert.equal(scheduled.generation,0);
assert.equal(engine.stop(),1);
assert.equal(engine.generation(),1);

const worklet=fs.readFileSync('admin/stem-time-stretch-worklet-v203.js','utf8');
assert.match(worklet,/extends AudioWorkletProcessor/);
assert.match(worklet,/registerProcessor\('stonefellow-time-stretch-v203'/);
assert.match(worklet,/grain=1024/);
assert.match(worklet,/hop=256/);
assert.match(worklet,/currentFrame/);
assert.doesNotMatch(worklet,/playbackRate/);

let ProcessorClass=null;
class MockProcessor{constructor(){this.port={onmessage:null};}}
const dspSandbox={
  AudioWorkletProcessor:MockProcessor,
  currentFrame:0,
  registerProcessor:(name,klass)=>{assert.equal(name,'stonefellow-time-stretch-v203');ProcessorClass=klass;},
  Math,Float32Array,Number
};
vm.runInNewContext(worklet,dspSandbox);
const processor=new ProcessorClass();
const samples=new Float32Array(48000);
for(let index=0;index<samples.length;index++)samples[index]=Math.sin(2*Math.PI*440*index/48000);
processor.receive({type:'buffer',channels:[samples]});
processor.receive({type:'schedule',generation:0,events:[{
  startFrame:0,outputFrames:12000,sourceFrame:0,rate:1.5,gain:1,fadeInFrames:0,fadeOutFrames:0
}]});
const rendered=[];
for(let block=0;block<48;block++){
  dspSandbox.currentFrame=block*128;
  const channel=new Float32Array(128);
  processor.process([],[[channel]]);
  rendered.push(...channel);
}
const analysis=rendered.slice(1024);
let crossings=0;
for(let index=1;index<analysis.length;index++)if(analysis[index-1]<=0&&analysis[index]>0)crossings++;
const measuredHz=crossings/(analysis.length/48000);
assert.ok(measuredHz>350&&measuredHz<550,`Expected preserved pitch near 440 Hz, received ${measuredHz}`);

const studio=fs.readFileSync('admin/stems-v108.js','utf8');
const page=fs.readFileSync('admin/stems-legacy-v108.php','utf8');
assert.match(studio,/StonefellowStemTimeStretchV203/);
assert.match(studio,/prepareTimeStretchTransport/);
assert.match(studio,/startTimeStretchTransport/);
assert.match(studio,/timeStretchEngine\.schedule/);
assert.match(studio,/mediaRate:sessionTempo/);
assert.ok(page.indexOf('stem-buffer-scheduler-v202.js') < page.indexOf('stem-time-stretch-v203.js'));
assert.ok(page.indexOf('stem-time-stretch-v203.js') < page.indexOf('stem-transport-v200.js'));

console.log('STEM_TIME_STRETCH_V203=PASS');
