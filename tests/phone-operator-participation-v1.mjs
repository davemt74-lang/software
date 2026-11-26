import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../phone-operator-participation-v1.js',import.meta.url),'utf8');
const flush=async()=>{for(let i=0;i<40;i++)await Promise.resolve();};
const deferred=()=>{let resolve;const promise=new Promise(r=>resolve=r);return {promise,resolve};};
function fixture(overrides={}){
 let now=1800000000000,id=0,alive=true,ended;const timers=new Map(),calls=[],plays=[],stops=[],captures=[],revoked=[];
 const window={};vm.runInNewContext(source,{window,Date,setTimeout,clearTimeout,console});
 const set=(fn,ms)=>{const n=++id;timers.set(n,{fn,at:now+ms});return n;};
 const clear=n=>timers.delete(n);
 const request=async(action,fields)=>{
  calls.push({action,fields:structuredClone(fields)});if(overrides.request){const result=await overrides.request(action,fields);if(result!==undefined)return result;}
  const call_id='a'.repeat(32);
  if(action==='participation_start')return {call_id,participation_token:'f'.repeat(64),mode:fields.mode,state:'listening',max_decisions:4,max_responses:2,decisions_used:0,responses_used:0,minimum_gap:8,expires_epoch:Math.floor(now/1000)+60};
  if(action==='participation_decide')return {call_id,based_on_seq:fields.receipts.at(-1).seq,state:'speak',text:'Which day works?',proof:'d'.repeat(64)};
  if(action==='voice_prepare')return {call_id,source_seq:fields.source_seq,script:'This is the VP3 AI assistant. '+fields.suggestion,state:'ready',sample_rate:16000,turn_token:'e'.repeat(64),expires_epoch:Math.floor(now/1000)+15,pcm:'fixture',samples:32000};
  if(action==='voice_claim')return {call_id,source_seq:fields.source_seq,state:'playing',samples:32000,start_by_epoch:Math.floor(now/1000)+2,expires_epoch:Math.floor(now/1000)+5};
  if(action==='voice_finish')return {call_id,source_seq:fields.source_seq,state:'closed'};
  throw Error('Unexpected '+action);
 };
 const c=window.VP3OperatorParticipationV1.create({callId:'a'.repeat(32),deadline:now+60000,now:()=>now,setTimeout:set,clearTimeout:clear,
  current:()=>alive,request,revoke:async token=>revoked.push(token),decode:()=>new Int16Array(32000),
  capture:mode=>captures.push(mode),play:(pcm,end)=>{plays.push(pcm.length);ended=end;},stopVoice:()=>stops.push(now)});
 const advance=async ms=>{const target=now+ms;for(;;){const next=[...timers].filter(([,t])=>t.at<=target).sort((a,b)=>a[1].at-b[1].at)[0];if(!next)break;timers.delete(next[0]);now=next[1].at;next[1].fn();await flush();}now=target;await flush();};
 const receipts=(seq,text='Agent, which day works?')=>[{seq,speaker:'remote_audio',text,proof:'b'.repeat(64)}];
 return {c,calls,plays,stops,captures,revoked,advance,receipts,end:()=>ended?.(),flush,setAlive:value=>alive=value};
}
let count=0;
async function test(name,run){await run();count++;console.log('PASS '+name);}
await test('nothing processes or speaks before explicit conversation start',async()=>{
 const f=fixture();f.c.observe(f.receipts(1));await f.advance(2000);assert.equal(f.calls.length,0);assert.equal(f.plays.length,0);
});
await test('default mode waits for an address and a quiet gap, then speaks without another click',async()=>{
 const f=fixture();await f.c.start();f.c.observe(f.receipts(1,'Friday works.'));await f.advance(1000);assert.equal(f.calls.length,1);
 f.c.activity();f.c.observe(f.receipts(2));await f.advance(899);assert.equal(f.plays.length,0);await f.advance(1);
 assert.equal(f.plays.length,1);assert.equal(f.c.snapshot.phase,'speaking');
 assert.deepEqual(f.calls.map(x=>x.action),['participation_start','participation_decide','voice_prepare','voice_claim']);
 assert.equal(f.calls[2].fields.voice_consent_attested,false);assert.equal(f.calls[2].fields.participation_token,'f'.repeat(64));
 f.c.stop();
});
await test('human interruption stops local speech before its network finish and leaves the session listening',async()=>{
 const gate=deferred();const f=fixture({request:action=>action==='voice_finish'?gate.promise:undefined});
 await f.c.start();f.c.observe(f.receipts(1));await f.advance(900);const before=f.stops.length;
 f.c.activity();assert.ok(f.stops.length>before);assert.equal(f.c.snapshot.phase,'finishing');
 gate.resolve({call_id:'a'.repeat(32),source_seq:1,state:'closed'});await flush();
 assert.equal(f.c.snapshot.phase,'listening');assert.equal(f.revoked.length,0);f.c.stop();
});
await test('two responses share signed context and exhaust the call allowance without a third synthesis',async()=>{
 const f=fixture();await f.c.start();f.c.observe(f.receipts(1));await f.advance(900);f.end();await flush();
 assert.equal(f.c.snapshot.phase,'listening');f.c.observe(f.receipts(2));await f.advance(8000);
 assert.equal(f.plays.length,2);const decision=f.calls.filter(x=>x.action==='participation_decide')[1];
 assert.equal(decision.fields.previous.length,1);assert.equal(decision.fields.previous[0].proof,'d'.repeat(64));
 f.end();await flush();assert.equal(f.c.snapshot.phase,'closed');f.c.observe(f.receipts(3));await f.advance(10000);
 assert.equal(f.plays.length,2);assert.equal(f.revoked.length,1);
});
await test('a wait decision uses no speech service',async()=>{
 const f=fixture({request:(action,fields)=>action==='participation_decide'?{call_id:'a'.repeat(32),based_on_seq:fields.receipts.at(-1).seq,state:'wait'}:undefined});
 await f.c.start('helpful');f.c.observe(f.receipts(1,'Friday works.'));await f.advance(900);
 assert.equal(f.c.snapshot.phase,'listening');assert.equal(f.calls.some(x=>x.action==='voice_prepare'),false);f.c.stop();
});
await test('a late start grant is revoked after Remove instead of restoring the Agent',async()=>{
 const gate=deferred(),f=fixture({request:action=>action==='participation_start'?gate.promise:undefined});
 const start=f.c.start();f.c.stop();gate.resolve({participation_token:'f'.repeat(64)});await start;
 assert.equal(f.c.snapshot.phase,'closed');assert.deepEqual(f.revoked,['f'.repeat(64)]);assert.equal(f.plays.length,0);
});
await test('Remove during speech preparation discards the late result and never claims playback',async()=>{
 const gate=deferred(),f=fixture({request:action=>action==='voice_prepare'?gate.promise:undefined});
 await f.c.start();f.c.observe(f.receipts(1));await f.advance(900);assert.equal(f.c.snapshot.phase,'preparing');
 f.c.stop();gate.resolve({});await flush();assert.equal(f.calls.some(x=>x.action==='voice_claim'),false);assert.equal(f.plays.length,0);assert.equal(f.revoked.length,1);
});
await test('new human speech during a model request discards the old reply before synthesis',async()=>{
 const gate=deferred(),f=fixture({request:action=>action==='participation_decide'?gate.promise:undefined});
 await f.c.start();f.c.observe(f.receipts(1));await f.advance(900);f.c.activity();
 gate.resolve({call_id:'a'.repeat(32),based_on_seq:1,state:'speak',text:'Which day works?',proof:'d'.repeat(64)});await flush();
 assert.equal(f.c.snapshot.phase,'listening');assert.equal(f.calls.some(x=>x.action==='voice_prepare'),false);f.c.stop();
});
await test('new human speech during synthesis closes its reservation without playing',async()=>{
 const gate=deferred(),f=fixture({request:action=>action==='voice_prepare'?gate.promise:undefined});
 await f.c.start();f.c.observe(f.receipts(1));await f.advance(900);f.c.activity();
 gate.resolve({call_id:'a'.repeat(32),source_seq:1,script:'This is the VP3 AI assistant. Which day works?',state:'ready',sample_rate:16000,turn_token:'e'.repeat(64),expires_epoch:1800000015,pcm:'fixture',samples:32000});await flush();
 assert.equal(f.plays.length,0);assert.equal(f.calls.some(x=>x.action==='voice_finish'),true);assert.equal(f.c.snapshot.phase,'listening');f.c.stop();
});
await test('an uncertain paid request ends the mode and is never retried',async()=>{
 const f=fixture({request:action=>{if(action==='voice_prepare')throw Error('Speech result lost.');}});
 await f.c.start();f.c.observe(f.receipts(1));await f.advance(900);assert.equal(f.c.snapshot.phase,'closed');
 await f.advance(20000);assert.equal(f.calls.filter(x=>x.action==='voice_prepare').length,1);assert.equal(f.plays.length,0);
});
await test('changed call context stops active speech on the next authority heartbeat',async()=>{
 const f=fixture();await f.c.start();f.c.observe(f.receipts(1));await f.advance(900);f.setAlive(false);await f.advance(1100);
 assert.equal(f.c.snapshot.phase,'closed');assert.equal(f.revoked.length,1);
});
await test('invalid grant and invalid decision do not reach playback',async()=>{
 const a=fixture({request:action=>action==='participation_start'?{state:'listening'}:undefined});await a.c.start();assert.equal(a.c.snapshot.phase,'closed');assert.equal(a.plays.length,0);
 const b=fixture({request:action=>action==='participation_decide'?{call_id:'b'.repeat(32),based_on_seq:1,state:'speak'}:undefined});await b.c.start();b.c.observe(b.receipts(1));await b.advance(900);assert.equal(b.c.snapshot.phase,'closed');assert.equal(b.plays.length,0);
});
console.log('PHONE_OPERATOR_PARTICIPATION_V1=PASS ('+count+' cases)');
