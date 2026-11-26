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
}
console.log('PHONE_OPERATOR_CAPTURE_V1=PASS (4 hardware sample rates; consent, exact PCM size, silent output, stop/reset)');
{
  let Processor;
  const frames=[];
  class Base { constructor(){ this.port={postMessage:frame=>frames.push(frame)}; } }
  vm.runInNewContext(source,{sampleRate:16000,AudioWorkletProcessor:Base,registerProcessor:(_,type)=>{Processor=type;}});
  const capture=new Processor({processorOptions:{channels:2}});
  capture.port.onmessage({data:'start'});
  const remote=new Float32Array(128).fill(.5),local=new Float32Array(128).fill(-.25);
  for(let i=0;i<500;i++)capture.process([[remote],[local]],[]);
  assert.equal(frames.length,1);
  const pcm=new DataView(frames[0]);assert.equal(pcm.byteLength,256000);
  for(const offset of [0,128000,255996]){
    assert.equal(pcm.getInt16(offset,true),16384);
    assert.equal(pcm.getInt16(offset+2,true),-8192);
  }
}
