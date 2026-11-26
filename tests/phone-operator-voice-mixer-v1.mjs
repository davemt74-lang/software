import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../phone-operator-voice-mixer-v1.js',import.meta.url),'utf8');
const window={};
vm.runInNewContext(source,{window,Int16Array});
const events=[];
function track(){return {readyState:'live',enabled:true,handlers:new Map(),stop(){this.readyState='ended';},addEventListener(name,fn){this.handlers.set(name,fn);},removeEventListener(name){this.handlers.delete(name);}};}
function stream(t){return {getTracks:()=>[t],getAudioTracks:()=>[t]};}
function audioNode(){return {connected:[],connect(dest){this.connected.push(dest);return dest;},disconnect(){this.connected=[];}};}
function gain(){return Object.assign(audioNode(),{gain:{value:1,cancelScheduledValues(){},setValueAtTime(v){this.value=v;events.push('mic:'+v);}}});}
let lastSource;
const ctx={destination:audioNode(),state:'running',currentTime:0,createMediaStreamSource(input){return Object.assign(audioNode(),{input});},createGain:gain,
  createMediaStreamDestination(){return Object.assign(audioNode(),{stream:stream(track())});},
  createBuffer(ch,length,rate){assert.equal(ch,1);assert.equal(rate,16000);const data=new Float32Array(length);return {getChannelData:()=>data};},
  createBufferSource(){return lastSource=Object.assign(audioNode(),{onended:null,start(){events.push('start');},stop(){events.push('stop');}});},
  close(){this.state='closed';return Promise.resolve();}};
const lost=[];const mixer=window.VP3OperatorVoiceMixerV1.create(ctx,reason=>lost.push(reason));
const raw=track(),input=stream(raw),output=await mixer.createProcessedStream(input);
assert.ok(mixer.matches(output));assert.equal(mixer.matches(input),false);
assert.equal(mixer.graph.mic.gain.value,1);assert.equal(mixer.speaking,null);
const pcm=new Int16Array(1600).fill(5000);
let completed=0;mixer.play(pcm,output,()=>completed++);
assert.equal(mixer.graph.mic.gain.value,0);assert.deepEqual(events.slice(-2),['mic:0','start']);
assert.throws(()=>mixer.play(pcm,output,()=>{}));
const naturalEnd=lastSource.onended;naturalEnd();
assert.equal(completed,1);assert.equal(mixer.speaking,null);assert.equal(mixer.graph.mic.gain.value,1);
mixer.play(pcm,output,()=>completed++);const late=lastSource.onended;mixer.stop();
assert.equal(mixer.speaking,null);assert.equal(mixer.graph.mic.gain.value,1);late();
assert.equal(completed,1,'late ended event cannot restore stale turn state');
assert.equal(raw.readyState,'live','takeover does not stop original microphone');
assert.equal(output.getTracks()[0].readyState,'live','takeover preserves the carrier output');
mixer.play(pcm,output,()=>completed++,true);
const shared=mixer.speaking,sharedEnd=shared.source.onended;
assert.equal(mixer.graph.mic.gain.value,1,'the owner can speak throughout a shared response');
assert.deepEqual(shared.voice.connected,[mixer.graph.destination,ctx.destination],'the agent reaches the caller and the owner');
assert.deepEqual(mixer.graph.mic.connected,[mixer.graph.destination],'the owner microphone is never monitored locally');
mixer.stop();sharedEnd();
assert.equal(completed,1);assert.equal(shared.voice.connected.length,0);assert.ok(mixer.matches(output));
assert.equal(raw.readyState,'live');assert.equal(output.getTracks()[0].readyState,'live');
mixer.play(pcm,output,()=>completed++);ctx.state='suspended';ctx.onstatechange();
assert.deepEqual(lost,['audio_context_lost']);assert.equal(mixer.speaking,null);
ctx.state='running';await mixer.destroyProcessedStream(output);
assert.equal(raw.readyState,'live');assert.equal(output.getTracks()[0].readyState,'ended');assert.equal(mixer.graph,null);
mixer.close();assert.equal(ctx.state,'closed');
console.log('PHONE_OPERATOR_VOICE_MIXER_V1=PASS (human passthrough, single turn, natural return, immediate takeover, stale callbacks, capture loss)');
