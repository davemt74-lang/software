import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn,spawnSync} from 'node:child_process';

const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-call-api-')),root=process.cwd();
const php=process.env.VP3_PHP_BINARY||'php',config=path.join(tmp,'fixture.json');
const fixture=path.resolve('tests/fixtures/profile-calls-api-pa3c-db.php');
const invoke=action=>{const r=spawnSync(php,[fixture,action,config],{encoding:'utf8'});assert.equal(r.status,0,r.stderr||r.stdout);return r.stdout;};
const driver=invoke('init');fs.mkdirSync(path.join(tmp,'includes'));fs.mkdirSync(path.join(tmp,'api'));fs.mkdirSync(path.join(tmp,'sessions'));
for(const name of fs.readdirSync('includes'))if(name!=='bootstrap.php')fs.symlinkSync(path.resolve('includes',name),path.join(tmp,'includes',name));
fs.copyFileSync('api/profile-calls-pa3c.php',path.join(tmp,'api/profile-calls-pa3c.php'));
fs.copyFileSync('tests/fixtures/profile-calls-api-pa3c-bootstrap.php',path.join(tmp,'includes/bootstrap.php'));
fs.writeFileSync(path.join(tmp,'boot.php'),`<?php require __DIR__.'/includes/bootstrap.php'; header('Content-Type: application/json'); $ctx=vp3_profile_agent_public_context_v110(db(),profile_by_username(db(),'fixture-owner'),current_user()); echo json_encode(['token'=>profile_chat_token(1),'namespace'=>hash('sha256','profile-widget-v1|'.$ctx['conversation_authority']['credential_hash'])],JSON_THROW_ON_ERROR);`);
const reservation=net.createServer();await new Promise(r=>reservation.listen(0,'127.0.0.1',r));const port=reservation.address().port;await new Promise(r=>reservation.close(r));
const child=spawn(php,['-d','display_errors=0','-d','session.save_path='+path.join(tmp,'sessions'),'-S',`127.0.0.1:${port}`,'-t',tmp],{env:{...process.env,VP3_PA3_ROOT:root,VP3_PA3_CONFIG:config,VP3_PA3C_ROOMS:path.join(tmp,'rooms.json'),VP3_PA3C_PROVIDER_LOG:path.join(tmp,'provider.log'),VP3_PA3C_NEW_SESSION:path.join(tmp,'new-session.txt')},stdio:['ignore','ignore','pipe']});
let logs='',checks=0,now=Date.now(),intent=10000;child.stderr.on('data',v=>logs+=v);
const base=`http://127.0.0.1:${port}`,csrf='pa3-fixture-csrf',state=()=>JSON.parse(invoke('state'));
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const key=n=>n.toString(16).padStart(32,'0');
const actor=(user,browser,session)=>({user,cookie:`PHPSESSID=call${session}; sf_profile_visitor=${browser.repeat(64)}; sf_profile_conversation=${browser.repeat(64)}`});
const visitor=actor(2,'b','visitor'),foreign=actor(3,'f','foreign'),owner=actor(1,'c','owner'),otherOwner=actor(4,'d','otherowner'),guest=actor(0,'e','guest');
const headers=(who,extra={})=>({'Content-Type':'application/json','X-Fixture-User':String(who.user),'X-Fixture-Now':String(now),Cookie:who.cookie,...extra});
async function request(method,fields,who=visitor,extra={},rawBody=null){
  const read=['GET','HEAD'].includes(method),r=await fetch(base+'/api/profile-calls-pa3c.php'+(read?'?'+new URLSearchParams(fields):''),{method,headers:headers(who,extra),...(!read?{body:rawBody??JSON.stringify(fields)}:{})});
  const raw=await r.text();let data=null;try{data=raw?JSON.parse(raw):null;}catch{throw Error(raw+'\n'+logs.slice(-3000));}if(r.status===500&&!raw)throw Error(logs.slice(-4000));return {status:r.status,data,raw,cookies:r.headers.getSetCookie()};
}
const providerCalls=()=>fs.existsSync(path.join(tmp,'provider.log'))?fs.readFileSync(path.join(tmp,'provider.log'),'utf8').trim().split('\n').filter(Boolean).map(JSON.parse):[];
const safe=raw=>!/PRIVATE_CHAT_SECRET|FOREIGN_CHAT_SECRET|provider_snapshot|credential_hash|authority_json|binding_hash|api_secret|provider_lease_token|SQLSTATE|\.php/.test(raw);
try{
  let ready=false;for(let i=0;i<80;i++){try{const r=await fetch(base+'/boot.php',{headers:headers(visitor)});if(r.status===200){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,25));}assert.ok(ready,logs);
  const boot=async who=>(await (await fetch(base+'/boot.php',{headers:headers(who)})).json());
  const v=await boot(visitor),f=await boot(foreign),g=await boot(guest);
  const pub={username:'fixture-owner',profile_token:v.token,conversation_namespace:v.namespace};
  const fp={username:'fixture-owner',profile_token:f.token,conversation_namespace:f.namespace};
  const gp={username:'fixture-owner',profile_token:g.token,conversation_namespace:g.namespace};
  const own={csrf_token:csrf,chat_conversation_id:11,chat_agent_id:7};
  const before=state();
  for(const method of ['GET','HEAD','PUT','DELETE'])for(const action of ['visitor_offer','visitor_join','visitor_dispatch','visitor_end','owner_accept','owner_delivery','owner_return_to_agent']){
    const r=await request(method,{...pub,...own,action},action.startsWith('owner')?owner:visitor);check(r.status===405,method+' cannot mutate '+action);
  }
  for(const bad of ['', 'wrong', []]){
    const r=await request('POST',{...own,action:'owner_offer',csrf_token:bad},owner);check(r.status===419,'owner mutation requires exact body CSRF');
  }
  let r=await request('POST',{...own,action:'owner_offer'},guest);check(r.status===401,'anonymous visitor cannot use owner API');
  r=await request('GET',{...own,action:'owner_state'},visitor);check(r.status===403,'foreign visitor cannot read owner Chat cards');
  for(const raw of ['[]','null','{','"text"']){r=await request('POST',{},visitor,{},raw);check(r.status===422,'invalid JSON request shape fails safely');}
  r=await request('POST',{},visitor,{},JSON.stringify({action:'visitor_offer',extra:'x'.repeat(17000)}));check(r.status===413,'oversized JSON is rejected before execution');
  r=await request('POST',{...pub,action:'visitor_offer',profile_token:'wrong',conversation_id:0,mode:'audio',request_id:key(1)});check(r.status===419,'visitor mutation requires the native Profile token');
  r=await request('GET',{...pub,action:'visitor_state',conversation_namespace:'a'.repeat(64),conversation_id:0});check(r.status===409&&r.data.code==='PROFILE_SESSION_CHANGED','visitor read requires exact native namespace');
  r=await request('GET',{...pub,action:'visitor_state',conversation_id:0});check(r.status===200&&r.data.can_offer&&r.data.conversation_id===0&&r.data.calls.length===0,'call-only capability read returns no invented conversation');
  check(JSON.stringify(before)===JSON.stringify(state())&&providerCalls().length===0,'denied and read-only requests write no conversation/call/job and make no provider call');
  r=await request('POST',{...gp,action:'visitor_offer',conversation_id:0,mode:'audio',request_id:key(2)},guest,{'X-Fixture-Fault':'offer'});
  check(r.status===500&&safe(r.raw),'failed atomic offer exposes only a fixed public error');
  check(JSON.stringify(before)===JSON.stringify(state()),'failed call-only offer rolls back the new bound conversation too');
  r=await request('POST',{...pub,action:'visitor_offer',conversation_id:0,mode:'audio',request_id:key(3)},visitor,{'X-Fixture-Ready':'0'});check(r.status===503&&state().profile_calls_pa3c.length===0,'disabled configuration admits no call');
  const offer={...pub,action:'visitor_offer',conversation_id:0,mode:'audio',request_id:key(4)};
  r=await request('POST',offer);check(r.status===200&&r.data.call.state==='offered'&&r.data.conversation_id>0,'call-only native POST creates the exact offered call');
  let call=r.data.call;const cid=call.conversation_id;
  check(state().profile_agent_messages.every(m=>m.sender_type==='system')&&state().profile_agent_handoff_turns_pa3.length===0&&providerCalls().length===0,'call-only offer creates no fake text, inference turn or provider room');
  r=await request('POST',offer);check(r.status===200&&r.data.duplicate&&r.data.call_id===call.call_id&&state().profile_calls_pa3c.length===1,'lost offer response retries the same canonical call');
  const newerCid=Number(invoke('second_thread'));assert.notEqual(newerCid,cid);
  r=await request('POST',offer);check(r.status===200&&r.data.duplicate&&r.data.conversation_id===cid&&r.data.call_id===call.call_id,'lost cid0 offer recovers its original receipt after another tab creates a newer native thread');
  r=await request('POST',{...offer,mode:'video'});check(r.status===409,'same request identity cannot change the offered media');
  for(const action of ['visitor_state','visitor_accept','visitor_dispatch','visitor_join']){
    r=await request(action==='visitor_state'?'GET':'POST',{...fp,action,conversation_id:cid,call_id:call.call_id,revision:call.revision,request_id:key(5),microphone:true,camera:false,client_instance_id:key(55)},foreign);
    check([403,404].includes(r.status),'foreign native visitor cannot '+action);
  }
  r=await request('GET',{...pub,action:'visitor_state',conversation_id:cid},{...visitor,user:3});check(r.status===409,'same browser member switch cannot inherit call authority');
  r=await request('POST',{...pub,action:'visitor_accept',conversation_id:cid,call_id:call.call_id,revision:call.revision,request_id:key(6)});check(r.status===409,'offerer cannot accept for the other person');
  r=await request('POST',{...own,action:'owner_delivery',call_id:call.call_id,revision:call.revision},owner);check(r.status===200&&r.data.delivery_id&&r.data.chat_message_id>0,'owner sees an exact durable call card in selected private Chat');
  let delivery=r.data.delivery_id;const ownerInput=(action,extra={})=>({...own,action,delivery_id:delivery,conversation_id:cid,call_id:call.call_id,revision:call.revision,request_id:key(++intent),...extra});
  const visitorInput=(action,extra={})=>({...pub,action,conversation_id:cid,call_id:call.call_id,revision:call.revision,request_id:key(++intent),...extra});
  r=await request('POST',ownerInput('owner_accept',{delivery_id:key(99)}),owner);check(r.status===409,'forged owner call-card proof cannot accept');
  r=await request('POST',ownerInput('owner_accept'),otherOwner);check([403,404].includes(r.status),'another owner cannot take over this visitor thread');
  r=await request('POST',ownerInput('owner_accept'),owner);check(r.status===200&&r.data.call.state==='preparing','explicit other-role consent prepares the call without capturing media');call=r.data.call;
  check(state().profile_agent_handoff_conversations_pa3.some(t=>Number(t.conversation_id)===cid&&t.mode==='owner')&&providerCalls().length===0,'accepted call takes the existing public thread without inference or provider I/O');
  r=await request('POST',visitorInput('visitor_dispatch'));check(r.status===200&&r.data.call.state==='ready'&&!r.data.participant_token,'authorized dispatch verifies the room without exposing join credentials');call=r.data.call;
  check(providerCalls().every(p=>p.session_active===false),'provider work runs after releasing the PHP session lock');
  const instance=key(123),join=visitorInput('visitor_join',{client_instance_id:instance,microphone:true,camera:false,request_id:key(124)});
  r=await request('POST',{...join,camera:true});check(r.status===409,'audio consent cannot be escalated to video at Join');
  r=await request('POST',join);check(r.status===200&&r.data.participant_token==='SECRET_PARTICIPANT_TOKEN'&&r.data.role==='visitor'&&r.data.client_instance_id===instance&&r.data.expires_at_ms===now+60000,'explicit visitor Join receives only its exact short-lived role credential');call=r.data.call;
  const lease=r.data.authority_valid_until_ms;
  check(safe(r.raw)&&!JSON.stringify(state()).includes('SECRET_PARTICIPANT_TOKEN'),'join credentials are neither persisted nor mixed with private Chat material');
  r=await request('POST',join);check(r.status===200&&r.data.duplicate&&r.data.expires_at_ms===now+60000,'uncertain Join retry keeps the original lifetime and intent');
  r=await request('POST',{...join,request_id:key(125),client_instance_id:key(126)});check(r.status===409,'a second local tab cannot seize the joined role');
  now+=1000;r=await request('GET',{...pub,action:'visitor_state',conversation_id:cid});check(r.status===200&&r.data.calls[0].authority_valid_until_ms===lease&&!r.raw.includes('SECRET_PARTICIPANT_TOKEN'),'GET state never renews media authority or returns credentials');
  r=await request('POST',visitorInput('visitor_heartbeat',{client_instance_id:key(999)}));check(r.status===409,'heartbeat cannot substitute the joined browser instance');
  r=await request('POST',visitorInput('visitor_heartbeat',{client_instance_id:instance}));check(r.status===200&&r.data.call.authority_valid_until_ms===now+30000,'current browser heartbeat renews only its exact role lease');call=r.data.call;
  const ownerJoin=ownerInput('owner_join',{client_instance_id:key(130),microphone:true,camera:false,request_id:key(131)});
  r=await request('POST',ownerJoin,owner);check(r.status===200&&r.data.role==='owner'&&r.data.participant_identity!==r.data.peer_identity,'owner explicit Join uses the other exact human role');call=r.data.call;
  r=await request('POST',visitorInput('visitor_end'));check(r.status===200&&r.data.call.state==='ending'&&r.data.call.cleanup_pending,'ending truthfully retains cleanup pending');call=r.data.call;
  r=await request('POST',join);check(r.status===409&&!r.raw.includes('SECRET_PARTICIPANT_TOKEN'),'old Join retry cannot release a credential after End');
  r=await request('POST',visitorInput('visitor_return_to_agent'));check(r.status===200&&r.data.call.can_return_to_agent===false,'explicit return-to-Agent changes the exact saved owner epoch');call=r.data.call;
  check(state().profile_call_slots_pa3c.some(s=>Number(s.active_call_id)===call.call_id),'return to Agent does not release the media cleanup slot');
  now+=10000;r=await request('POST',visitorInput('visitor_dispatch'));check(r.status===200&&r.data.call.state==='ended','browser-assisted POST completes the canonical cleanup job');call=r.data.call;
  check(providerCalls().some(p=>p.operation==='cleanup')&&state().profile_call_slots_pa3c.every(s=>Number(s.active_call_id)===0),'slot clears only after provider cleanup proof');
  check(state().profile_agent_messages.every(m=>m.message!=='PRIVATE_CHAT_SECRET')&&state().chat_messages.some(m=>m.message==='PRIVATE_CHAT_SECRET'),'call control never forwards ordinary private Chat text');
  // A second call verifies fresh authority after provider work without a live provider.
  r=await request('POST',{...own,action:'owner_offer',conversation_id:cid,mode:'video',request_id:key(200)},owner);check(r.status===200&&r.data.call.offered_by==='owner','owner can explicitly offer a video call on the existing thread');call=r.data.call;
  r=await request('POST',visitorInput('visitor_accept'));check(r.status===200&&r.data.call.state==='preparing','visitor explicitly accepts the owner offered media');call=r.data.call;
  r=await request('POST',visitorInput('visitor_dispatch'));assert.equal(r.status,200,r.raw);call=r.data.call;
  r=await request('POST',visitorInput('visitor_join',{client_instance_id:key(201),microphone:true,camera:true}),visitor,{'X-Fixture-Provider-Mode':'logout_after_token'});
  check([403,404,409].includes(r.status)&&!r.raw.includes('SECRET_PARTICIPANT_TOKEN'),'logout during slow Join is re-read before any credential reaches the browser');
  now+=6000;const priorProviderCalls=providerCalls().length;
  const rotated=await request('POST',visitorInput('visitor_join',{client_instance_id:key(201),microphone:true,camera:true}),visitor,{'X-Fixture-Provider-Mode':'switch_session_after_token'});
  assert.ok(providerCalls().length>priorProviderCalls&&fs.existsSync(path.join(tmp,'new-session.txt')),'session-rotation case must reach the real post-provider refresh');
  check([403,404,409].includes(rotated.status)&&!rotated.raw.includes('SECRET_PARTICIPANT_TOKEN'),'account switch deleting the captured session denies a delayed Join result');
  check(rotated.cookies.length===0,'delayed session refresh never emits a cookie that could overwrite a newer browser login');
  check(providerCalls().every(p=>p.session_active===false),'all explicit Join and cleanup provider calls release the browser session lock');
  console.log(`PROFILE_CALLS_API_PA3C=PASS (${checks} checks; ${driver})`);
}finally{
  child.kill('SIGTERM');await new Promise(r=>child.once('exit',r));invoke('cleanup');fs.rmSync(tmp,{recursive:true,force:true});
}
