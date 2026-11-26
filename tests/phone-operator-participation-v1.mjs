import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source=fs.readFileSync(new URL('../phone-operator-participation-v1.js',import.meta.url),'utf8');
const flush=async()=>{for(let i=0;i<40;i++)await Promise.resolve();};
const deferred=()=>{let resolve;const promise=new Promise(r=>resolve=r);return {promise,resolve};};
function fixture(overrides={}){
 let now=1800000000000,id=0,alive=true,ended,revision=0;const duration=overrides.duration??60,deadline=now+duration*1000,timers=new Map(),calls=[],plays=[],stops=[],captures=[],revoked=[];
 const window={};vm.runInNewContext(source,{window,Date,setTimeout,clearTimeout,console});
 const set=(fn,ms)=>{const n=++id;timers.set(n,{fn,at:now+ms});return n;};
 const clear=n=>timers.delete(n);
 const request=async(action,fields)=>{
  calls.push({action,fields:structuredClone(fields)});if(overrides.request){const result=await overrides.request(action,fields);if(result!==undefined)return result;}
  const call_id='a'.repeat(32);
  const start=calls.find(c=>c.action==='participation_start')?.fields||{},decisions=calls.filter(c=>c.action==='participation_decide').length,responses=calls.filter(c=>c.action==='voice_prepare').length;
  const balance={max_decisions:start.max_decisions??4,max_responses:start.max_responses??2,decisions_used:decisions,responses_used:responses,hour_decisions_remaining:8-decisions,hour_responses_remaining:8-responses};
  if(action==='participation_start')return {call_id,participation_token:'f'.repeat(64),mode:fields.mode,state:'listening',...balance,minimum_gap:8,control_revision:0,expires_epoch:deadline/1000};
  if(['participation_pause','participation_resume'].includes(action))return {call_id,state:action==='participation_pause'?'paused':'listening',...balance,control_revision:++revision,expires_epoch:deadline/1000,resume_seq:Math.max(0,...calls.flatMap(c=>c.fields.receipts?.map(r=>r.seq)||[]))};
  if(action==='participation_decide')return {call_id,based_on_seq:fields.receipts.at(-1).seq,state:'speak',text:'Which day works?',proof:'d'.repeat(64)};
  if(action==='voice_prepare')return {call_id,source_seq:fields.source_seq,script:'This is the VP3 AI assistant. '+fields.suggestion,state:'ready',sample_rate:16000,turn_token:'e'.repeat(64),expires_epoch:Math.floor(now/1000)+15,pcm:'fixture',samples:32000};
  if(action==='voice_claim')return {call_id,source_seq:fields.source_seq,state:'playing',samples:32000,start_by_epoch:Math.floor(now/1000)+2,expires_epoch:Math.floor(now/1000)+5};
  if(action==='voice_finish')return {call_id,source_seq:fields.source_seq,state:'closed'};
  throw Error('Unexpected '+action);
 };
 const c=window.VP3OperatorParticipationV1.create({callId:'a'.repeat(32),deadline,now:()=>now,setTimeout:set,clearTimeout:clear,
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
await test('browser timers retain the window receiver through conversation callbacks',async()=>{
 const window={};
 vm.runInNewContext('function setTimeout(){if(this!==globalThis)throw Error("Illegal timer receiver");return 1;} function clearTimeout(){if(this!==globalThis)throw Error("Illegal timer receiver");}'+source,{window,Date,console});
 const now=Date.now();const c=window.VP3OperatorParticipationV1.create({callId:'a'.repeat(32),deadline:now+60000,current:()=>true,
  capture(){},stopVoice(){},revoke:async()=>{},request:async()=>({call_id:'a'.repeat(32),participation_token:'f'.repeat(64),mode:'addressed',state:'listening',max_decisions:4,max_responses:2,decisions_used:0,responses_used:0,minimum_gap:8,expires_epoch:Math.floor(now/1000)+60})});
 await c.start();assert.equal(c.snapshot.phase,'listening');c.stop();assert.equal(c.snapshot.phase,'closed');
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
await test('existing paid reservations remain spent when conversation mode starts',async()=>{
 const f=fixture({request:(action,fields)=>action==='participation_start'?{call_id:'a'.repeat(32),participation_token:'f'.repeat(64),mode:fields.mode,state:'listening',max_decisions:4,max_responses:2,decisions_used:3,responses_used:1,minimum_gap:8,expires_epoch:1800000060}:undefined});
 await f.c.start();assert.equal(f.c.snapshot.decisions,3);assert.equal(f.c.snapshot.responses,1);
 f.c.observe(f.receipts(1));await f.advance(900);f.end();await flush();
 assert.equal(f.c.snapshot.phase,'closed');assert.equal(f.plays.length,1);assert.equal(f.c.snapshot.responses,2);
});
await test('a verified participant stop closes conversation without speech',async()=>{
 const f=fixture({request:(action,fields)=>action==='participation_decide'?{call_id:'a'.repeat(32),based_on_seq:fields.receipts.at(-1).seq,state:'stopped'}:undefined});
 await f.c.start();f.c.observe(f.receipts(1,'Agent, stop.'));await f.advance(900);
 assert.equal(f.c.snapshot.phase,'closed');assert.equal(f.plays.length,0);assert.equal(f.calls.length,1,'A verified stop bypasses quiet-gap and model-spend gates');
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

await test('explicit larger limits permit four speech reservations within the original 120 second grant',async()=>{
 const f=fixture({duration:120});await f.c.start('addressed',{max_decisions:8,max_responses:4});
 assert.equal(f.c.snapshot.seconds_remaining,120);assert.equal(f.c.snapshot.checks_remaining,8);
 for(let seq=1;seq<=4;seq++){f.c.observe(f.receipts(seq));await f.advance(seq===1?900:8000);f.end();await flush();}
 assert.equal(f.plays.length,4);assert.equal(f.c.snapshot.responses,4);assert.equal(f.c.snapshot.phase,'closed');
});
await test('pause silences speech immediately and resume preserves signed context, time and spend',async()=>{
 const f=fixture({duration:120});await f.c.start();f.c.observe(f.receipts(1));await f.advance(900);
 const before=f.stops.length,pause=f.c.pause();assert.ok(f.stops.length>before);assert.equal(f.c.snapshot.phase,'pausing');await pause;
 assert.equal(f.c.snapshot.phase,'paused');assert.equal(f.c.snapshot.responses_remaining,1);
 f.c.observe(f.receipts(2));await f.advance(9000);assert.equal(f.plays.length,1);assert.equal(f.c.snapshot.seconds_remaining,111);
 await f.c.resume();await f.advance(1000);assert.equal(f.plays.length,1,'Paused captions are never replayed on resume');
 f.c.observe(f.receipts(3));await f.advance(900);assert.equal(f.plays.length,2);
 assert.equal(f.calls.filter(c=>c.action==='participation_decide')[1].fields.previous.length,1);f.c.stop();
});
await test('pause while inference is pending cannot resume or synthesize its late reply',async()=>{
 const gate=deferred(),f=fixture({request:action=>action==='participation_decide'?gate.promise:undefined});
 await f.c.start();f.c.observe(f.receipts(1));await f.advance(900);await f.c.pause();assert.equal(f.c.snapshot.pending,true);
 await f.c.resume();assert.equal(f.calls.some(c=>c.action==='participation_resume'),false);
 gate.resolve({call_id:'a'.repeat(32),based_on_seq:1,state:'speak',text:'Late response.',proof:'d'.repeat(64)});await flush();
 assert.equal(f.c.snapshot.phase,'paused');assert.equal(f.c.snapshot.pending,false);assert.equal(f.calls.some(c=>c.action==='voice_prepare'),false);
 await f.c.resume();assert.equal(f.c.snapshot.phase,'listening');assert.equal(f.c.snapshot.decisions,1);f.c.stop();
});
await test('pause during synthesis counts its reservation and rejects late audio',async()=>{
 const gate=deferred(),f=fixture({request:action=>action==='voice_prepare'?gate.promise:undefined});
 await f.c.start();f.c.observe(f.receipts(1));await f.advance(900);
 assert.equal(f.c.snapshot.responses,1,'Synthesis reserves speech before any playback');await f.c.pause();
 gate.resolve({});await flush();assert.equal(f.c.snapshot.phase,'paused');assert.equal(f.plays.length,0);
 await f.c.resume();assert.equal(f.c.snapshot.responses,1);assert.equal(f.c.snapshot.phase,'listening');f.c.stop();
});
await test('a failed in-flight provider result after pause stays spent without removing the paused grant',async()=>{
 const gate=deferred(),f=fixture({request:action=>action==='participation_decide'?gate.promise.then(()=>{throw Error('Result fenced.');}):undefined});
 await f.c.start();f.c.observe(f.receipts(1));await f.advance(900);await f.c.pause();gate.resolve();await flush();
 assert.equal(f.c.snapshot.phase,'paused');assert.equal(f.revoked.length,0);await f.c.resume();assert.equal(f.c.snapshot.decisions,1);f.c.stop();
});
await test('pausing does not freeze expiry and Remove prevents late resume from restoring permission',async()=>{
 const f=fixture();await f.c.start();await f.c.pause();await f.advance(60000);assert.equal(f.c.snapshot.phase,'closed');await f.c.resume();
 assert.equal(f.calls.some(c=>c.action==='participation_resume'),false);
 const gate=deferred(),g=fixture({request:action=>action==='participation_resume'?gate.promise:undefined});await g.c.start();await g.c.pause();
 const resume=g.c.resume();g.c.stop();gate.resolve({});await resume;assert.equal(g.c.snapshot.phase,'closed');assert.equal(g.plays.length,0);
});
await test('uncertain pause or an enlarged resume allowance closes permission without retry',async()=>{
 const f=fixture({request:action=>{if(action==='participation_pause')throw Error('Pause result lost.');}});await f.c.start();await f.c.pause();
 assert.equal(f.c.snapshot.phase,'closed');assert.equal(f.revoked.length,1);
 const g=fixture({request:action=>action==='participation_resume'?{call_id:'a'.repeat(32),state:'listening',control_revision:2,expires_epoch:1800000060,resume_seq:0,max_decisions:8,max_responses:4,decisions_used:0,responses_used:0}:undefined});
 await g.c.start();await g.c.pause();await g.c.resume();assert.equal(g.c.snapshot.phase,'closed');assert.equal(g.plays.length,0);
});
await test('participant removal remains immediate while the Agent is paused',async()=>{
 const f=fixture();await f.c.start();await f.c.pause();f.c.observe(f.receipts(1,'Agent, stop.'));assert.equal(f.c.snapshot.phase,'closed');assert.equal(f.calls.filter(c=>c.action==='participation_decide').length,0);
});
console.log('PHONE_OPERATOR_SESSION_CONTROLS_JS=PASS ('+count+' total cases)');
