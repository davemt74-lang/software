import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const code=fs.readFileSync('studio-participants.js','utf8');
let cases=0;
function harness(){
 let cid=1,sid=0,now=1000;
 const requests=[],timers=new Map();let timerId=0;
 const w={STONEFELLOW_AGENT_CONTEXT:{csrf:'csrf',userId:1},StonefellowAgentContext:{conversationId:()=>cid},
  STONEFELLOW_ARTIST_LISTENING_WORKSPACE:{api:{getState:()=>({sessionId:sid})}},
  setTimeout:(fn,ms)=>{timers.set(++timerId,{fn,ms});return timerId;},clearTimeout:id=>timers.delete(id),
  addEventListener:()=>{},dispatchEvent:()=>{},location:{pathname:'/chat.php'}};
 const fetch=(url,options)=>new Promise((resolve,reject)=>{
  const req={url,options,resolve:data=>resolve({ok:true,status:200,json:async()=>data}),reject};requests.push(req);
  options.signal.addEventListener('abort',()=>reject(new Error('aborted')));
  if(url.includes('action=profiles'))req.resolve({ok:true,profiles:[]});
 });
 vm.runInNewContext(code,{window:w,fetch,AbortController,URLSearchParams,CustomEvent:class{},Date:{now:()=>now}});
 return {api:w.StonefellowStudioParticipants,requests,timers,scope:(c,s=0)=>{cid=c;sid=s;},advance:n=>now+=n,
  ctx:()=>requests.filter(r=>r.url.includes('action=context')),
  answer:(req,name='A',extra={})=>req.resolve({ok:true,context:{count:1,participants:[{participant_id:1,name,recognized:true,method:'manual',speaker_label:'S',...extra}]}})};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
async function test(name,fn){await fn();cases++;console.log('PASS '+name);}
await test('old conversation load cannot replace a newer scope',async()=>{
 const h=harness(),a=h.api.refresh(true);h.scope(2);const b=h.api.refresh(true);
 h.answer(h.ctx()[1],'B');await b;h.answer(h.ctx()[0],'A');await a;
 assert.equal(h.api.agentContext().participants[0].name,'B');
});
await test('A-B-A responses use request generation',async()=>{
 const h=harness(),a=h.api.refresh(true);h.scope(2);const b=h.api.refresh(true);h.scope(1);const a2=h.api.refresh(true);
 h.answer(h.ctx()[2],'New A');await a2;h.answer(h.ctx()[0],'Old A');h.answer(h.ctx()[1],'B');await Promise.all([a,b]);
 assert.equal(h.api.agentContext().participants[0].name,'New A');
});
await test('scope change hides cached identity before refresh',async()=>{
 const h=harness(),a=h.api.refresh();h.answer(h.ctx()[0]);await a;h.scope(2);
 assert.equal(h.api.agentContext().participants.length,0);assert.equal(h.api.snapshot().context.count,0);
});
await test('transcript scope excludes unrelated chat',async()=>{
 const h=harness();h.scope(999,7);const p=h.api.refresh();assert.match(h.ctx()[0].url,/transcript_session_id=7/);
 assert.doesNotMatch(h.ctx()[0].url,/conversation_id/);h.answer(h.ctx()[0]);await p;
 assert.equal(h.api.agentContext().transcript_session_id,7);
});
await test('failed refresh clears stale identity',async()=>{
 const h=harness(),a=h.api.refresh();h.answer(h.ctx()[0]);await a;const b=h.api.refresh(true);
 h.ctx()[1].reject(Error('offline'));await b;assert.equal(h.api.agentContext().participants.length,0);
});
await test('unknown identity strips names and account references',async()=>{
 const h=harness(),p=h.api.refresh();h.answer(h.ctx()[0],'DO NOT TRUST',{recognized:false,participant_id:7,linked_user_id:9});await p;
 const row=h.api.agentContext().participants[0];assert.equal(row.name,'');assert.equal(row.participant_id,0);assert.equal(row.linked_user_id,0);
});
await test('presence cache expires without renewal',async()=>{
 const h=harness(),p=h.api.refresh();h.answer(h.ctx()[0]);await p;h.advance(300001);assert.equal(h.api.agentContext().participants.length,0);
});
await test('late mutation retains original scope and refreshes current scope',async()=>{
 const h=harness(),p=h.api.recordPresence({speaker_label:'S',presence_state:'left'});await tick();
 const post=h.requests.find(r=>r.options.method==='POST');const payload=JSON.parse(post.options.body);
 assert.equal(payload.conversation_id,1);assert.equal(payload.presence_state,'left');h.scope(2);
 post.resolve({ok:true,receipt:{id:1},context:{participants:[{participant_id:1,name:'Old',recognized:true,method:'manual'}]}});await tick();
 assert.match(h.ctx()[0].url,/conversation_id=2/);h.answer(h.ctx()[0],'Current');await p;
 assert.equal(h.api.agentContext().participants[0].name,'Current');
});
await test('request deadline releases loading',async()=>{
 const h=harness(),p=h.api.refresh();[...h.timers.values()].filter(t=>t.ms===30000).forEach(t=>t.fn());await p;
 assert.equal(h.api.snapshot().loading,false);assert.equal(h.api.agentContext().participants.length,0);
});
await test('refresh during consent mutation cannot surface prior identity',async()=>{
 const h=harness(),p=h.api.setConsent({participant_id:1,recognition_consent:false});await tick();
 const read=h.api.refresh(true);h.answer(h.ctx()[0],'Prior');await read;
 assert.equal(h.api.agentContext().participants.length,0);
 h.requests.find(r=>r.options.method==='POST').resolve({ok:true});await tick();
 h.answer(h.ctx()[1],'Unknown',{recognized:false});await p;
 assert.equal(h.api.agentContext().participants[0].recognized,false);
});
await test('uncertain mutation failure clears identity and later refresh can retry',async()=>{
 const h=harness(),p=h.api.setConsent({participant_id:1,recognition_consent:false});await tick();
 h.requests.find(r=>r.options.method==='POST').reject(Error('lost acknowledgement'));
 await assert.rejects(p);assert.equal(h.api.agentContext().participants.length,0);
 const read=h.api.refresh(true);h.answer(h.ctx()[0],'Recovered');await read;
 assert.equal(h.api.agentContext().participants[0].name,'Recovered');
});
console.log(`PARTICIPANTS_SECTION5=PASS (${cases} browser cases)`);
