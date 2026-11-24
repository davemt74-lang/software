import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../phone-operator-capture-v1.js',import.meta.url),'utf8');
for(const rate of [16000,44100,48000,96000]){
  let Processor;const messages=[];
  class Base{constructor(){this.port={onmessage:null,postMessage:(data)=>messages.push(data)};}}
  vm.runInNewContext(source,{sampleRate:rate,AudioWorkletProcessor:Base,registerProcessor:(name,klass)=>{assert.equal(name,'vp3-operator-remote-capture-v1');Processor=klass;}});
  const capture=new Processor();
  const run=(count,value)=>{for(let offset=0;offset<count;offset+=128){
    const input=new Float32Array(Math.min(128,count-offset)).fill(value),output=new Float32Array(input.length).fill(1);
    assert.equal(capture.process([[input]],[[output]]),true);
    assert.ok(output.every(v=>v===0),'Capture must never inject or duplicate phone audio');
  }};
  run(rate,0.5);assert.equal(messages.length,0,'No audio before explicit start');
  capture.port.onmessage({data:'start'});run(rate*4,0.5);
  assert.equal(messages.length,1,'Exactly one four-second frame at '+rate);
  const frame=new DataView(messages[0]);assert.equal(frame.byteLength,128000);
  assert.ok(Math.abs(frame.getInt16(0,true)-16384)<=1);assert.ok(Math.abs(frame.getInt16(127998,true)-16384)<=1);
  run(rate,0.25);capture.port.onmessage({data:'stop'});run(rate*4,-0.5);
  assert.equal(messages.length,1,'Stop discards partial PCM and emits nothing');
  capture.port.onmessage({data:'start'});run(rate*4,-0.5);
  assert.equal(messages.length,2);assert.ok(Math.abs(new DataView(messages[1]).getInt16(0,true)+16384)<=1);
  messages.length=0;
  const dual=new Processor({processorOptions:{mode:'both'}});
  const runPair=(count,includeLocal=true)=>{for(let offset=0;offset<count;offset+=128){
    const length=Math.min(128,count-offset),a=new Float32Array(length).fill(.25),b=new Float32Array(length).fill(-.75),out=new Float32Array(length).fill(1);
    dual.process(includeLocal?[[a],[b]]:[[a],[]],[[out]]);
    assert.ok(out.every(v=>v===0));
  }};
  runPair(rate*4);assert.equal(messages.length,0,'dual mode remains consent gated');
  dual.port.onmessage({data:'start'});runPair(rate*2);runPair(128,false);runPair(rate*2);
  assert.equal(messages.length,0,'missing local side discards incomplete aligned frame');
  runPair(rate*2);assert.equal(messages.length,1);
  const remote=new DataView(messages[0].remote),local=new DataView(messages[0].local);
  assert.equal(remote.byteLength,128000);assert.equal(local.byteLength,128000);
  assert.ok(Math.abs(remote.getInt16(0,true)-8192)<=1);assert.ok(Math.abs(local.getInt16(0,true)+24576)<=1);
  dual.port.onmessage({data:'stop'});runPair(rate*4);assert.equal(messages.length,1);
}
console.log('PHONE_OPERATOR_CAPTURE_V1=PASS (4 hardware sample rates; remote and dual consent, independent aligned PCM, silent output, stop/reset)');
