import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

// Execute the shipped AudioWorklet. Expected values come from signal amplitude
// and elapsed time, without reproducing its resampling implementation.
const source=fs.readFileSync(new URL('../phone-voicemail-capture-v1.js',import.meta.url),'utf8');
const rates=[16000,44100,48000,96000];
let checks=0;
function check(name,run){run();checks++;console.log('PASS '+name);}

function captureAt(rate){
  let Processor;
  const messages=[];
  class WorkletBase{
    constructor(){
      this.port={postMessage(data,transfer){
        assert.equal(Object.prototype.toString.call(data),'[object ArrayBuffer]','Worklet sends raw PCM bytes');
        assert.equal(data.byteLength,3200,'Each message is exactly 100 ms of 16 kHz mono PCM16');
        assert.equal(transfer?.length,1,'PCM ownership is transferred');
        assert.equal(transfer[0],data);
        // Match a real MessagePort transfer: detach the sender buffer so later
        // processing cannot mutate a previously delivered chunk.
        messages.push(structuredClone(data,{transfer:[data]}));
        assert.equal(data.byteLength,0);
      }};
    }
  }
  vm.runInNewContext(source,{
    sampleRate:rate,
    AudioWorkletProcessor:WorkletBase,
    registerProcessor(name,implementation){
      assert.equal(name,'vp3-voicemail-greeting-v1');
      assert.equal(Processor,undefined,'Processor is registered once');
      Processor=implementation;
    },
  },{filename:'phone-voicemail-capture-v1.js'});
  assert.equal(typeof Processor,'function');
  const processor=new Processor();
  function quantum(input,secondChannel){
    const length=input?.length||128;
    const outputs=[
      [new Float32Array(length).fill(0.75),new Float32Array(length).fill(-0.25)],
      [new Float32Array(length).fill(Number.NaN)],
    ];
    const inputs=input?[[input,...(secondChannel?[secondChannel]:[])]]:[];
    assert.equal(processor.process(inputs,outputs),true,'Capture remains an active worklet');
    for(const output of outputs)for(const channel of output)
      assert.ok(channel.every(value=>value===0),'Every output channel stays silent, including after capture ends');
  }
  function constant(inputSamples,value,secondValue){
    assert.ok(Number.isSafeInteger(inputSamples)&&inputSamples>=0);
    const block=new Float32Array(128).fill(value);
    const other=secondValue===undefined?undefined:new Float32Array(128).fill(secondValue);
    for(let offset=0;offset<inputSamples;offset+=128){
      const count=Math.min(128,inputSamples-offset);
      quantum(count===128?block:block.subarray(0,count),other&&(count===128?other:other.subarray(0,count)));
    }
  }
  return {messages,quantum,constant};
}

function pcmEquals(buffer,expected,start=0,end=1600){
  const view=new DataView(buffer);
  for(let i=start;i<end;i++){
    const value=view.getInt16(i*2,true);
    assert.ok(Number.isFinite(value)&&value>=-32768&&value<=32767,'Decoded PCM is finite signed 16-bit audio');
    assert.equal(value,expected,'PCM amplitude at sample '+i);
  }
}

for(const rate of rates){
  check(rate+' Hz yields exactly one 3200-byte mono chunk per 100 ms',()=>{
    const capture=captureAt(rate);
    capture.quantum();
    capture.constant(rate/10-1,0.25,-1);
    assert.equal(capture.messages.length,0,'A partial chunk is never emitted early');
    capture.constant(1,0.25,-1);
    assert.equal(capture.messages.length,1,'100 ms of hardware input becomes exactly 1600 mono samples');
    pcmEquals(capture.messages[0],8192);
    assert.deepEqual([...new Uint8Array(capture.messages[0]).slice(0,4)],[0x00,0x20,0x00,0x20],'Positive PCM is little-endian and is not interleaved with the second input channel');
  });

  check(rate+' Hz preserves a known positive/negative step across render quanta',()=>{
    const capture=captureAt(rate);
    // Quarter scale stays clear of a half-LSB rounding tie at fractional rates.
    capture.constant(rate/20,0.25);
    capture.constant(rate/20,-0.25);
    assert.equal(capture.messages.length,1);
    pcmEquals(capture.messages[0],8192,0,800);
    pcmEquals(capture.messages[0],-8192,800,1600);
    assert.deepEqual([...new Uint8Array(capture.messages[0]).slice(1598,1602)],[0x00,0x20,0x00,0xe0],'The signed transition has the expected little-endian bytes');
    capture.constant(rate/10,0);
    assert.equal(capture.messages.length,2);
    pcmEquals(capture.messages[1],0);
    pcmEquals(capture.messages[0],8192,0,800);
    pcmEquals(capture.messages[0],-8192,800,1600);
  });

  check(rate+' Hz clips overload and sanitizes nonfinite input without poisoning later audio',()=>{
    const capture=captureAt(rate);
    for(const [input,expected] of [[2,32767],[-2,-32768],[Number.NaN,0],[Infinity,0],[-Infinity,0],[0.25,8192]]){
      const before=capture.messages.length;
      capture.constant(rate/10,input);
      assert.equal(capture.messages.length,before+1);
      pcmEquals(capture.messages.at(-1),expected);
    }
    assert.deepEqual([...new Uint8Array(capture.messages[0]).slice(0,2)],[0xff,0x7f]);
    assert.deepEqual([...new Uint8Array(capture.messages[1]).slice(0,2)],[0x00,0x80]);
  });

  check(rate+' Hz stops emitting at exactly 60 seconds while output remains silent',()=>{
    const capture=captureAt(rate);
    capture.constant((rate/10)*599,0.125);
    assert.equal(capture.messages.length,599);
    capture.constant(rate/10-1,0.125);
    assert.equal(capture.messages.length,599,'The final frame is not padded or emitted early');
    capture.constant(1,0.125);
    assert.equal(capture.messages.length,600);
    assert.equal(capture.messages.reduce((bytes,frame)=>bytes+frame.byteLength,0),1920000,'60 seconds is 960000 signed 16-bit mono samples');
    capture.constant(rate*2,-1);
    capture.quantum();
    assert.equal(capture.messages.length,600,'Input beyond the recording bound cannot produce another chunk');
    for(const frame of capture.messages)pcmEquals(frame,4096);
  });
}

check('unsupported hardware rates emit no audio and still silence every output',()=>{
  for(const rate of [8000,15999,96001,192000]){
    const capture=captureAt(rate);
    capture.constant(20000,1);
    capture.quantum();
    assert.equal(capture.messages.length,0);
  }
});

console.log('PHONE_VOICEMAIL_CAPTURE_V1=PASS ('+checks+' cases; shipped worklet, mono PCM16LE, 16/44.1/48/96 kHz, exact 60-second bound)');
