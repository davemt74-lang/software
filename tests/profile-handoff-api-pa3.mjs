import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn,spawnSync} from 'node:child_process';

const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-pa3-api-'));
const php=process.env.VP3_PHP_BINARY||'php',root=process.cwd(),config=path.join(tmp,'fixture.json');
const fixture=path.resolve('tests/fixtures/profile-handoff-api-pa3-db.php');
const invoke=(action)=>{const r=spawnSync(php,[fixture,action,config],{encoding:'utf8'});assert.equal(r.status,0,r.stderr||r.stdout);return r.stdout;};
const driver=invoke('init');
fs.mkdirSync(path.join(tmp,'includes'));fs.mkdirSync(path.join(tmp,'api'));
for(const name of fs.readdirSync('includes'))if(name!=='bootstrap.php')fs.symlinkSync(path.resolve('includes',name),path.join(tmp,'includes',name));
for(const name of ['profile-agent.php','chat-notifications-brain-v240.php'])fs.copyFileSync('api/'+name,path.join(tmp,'api',name));
fs.copyFileSync('tests/fixtures/profile-handoff-api-pa3-bootstrap.php',path.join(tmp,'includes/bootstrap.php'));
fs.writeFileSync(path.join(tmp,'boot.php'),`<?php require __DIR__.'/includes/bootstrap.php'; header('Content-Type: application/json'); $ctx=vp3_profile_agent_public_context_v110(db(),profile_by_username(db(),'fixture-owner'),current_user()); echo json_encode(['token'=>profile_chat_token(1),'namespace'=>hash('sha256','profile-widget-v1|'.$ctx['conversation_authority']['credential_hash'])],JSON_THROW_ON_ERROR);`);
const reservation=net.createServer();await new Promise(r=>reservation.listen(0,'127.0.0.1',r));const port=reservation.address().port;await new Promise(r=>reservation.close(r));
const child=spawn(php,['-d','display_errors=0','-S',`127.0.0.1:${port}`,'-t',tmp],{env:{...process.env,VP3_PA3_ROOT:root,VP3_PA3_CONFIG:config,VP3_PA3_INFERENCE_LOG:path.join(tmp,'model.log')},stdio:['ignore','ignore','pipe']});
let logs='',checks=0,now=Date.now();child.stderr.on('data',v=>logs+=v);
const base=`http://127.0.0.1:${port}`,csrf='pa3-fixture-csrf';
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const actor=(user,browser,session)=>({user,cookie:`PHPSESSID=pa3${session}; sf_profile_visitor=${browser.repeat(64)}; sf_profile_conversation=${browser.repeat(64)}`});
const visitor=actor(2,'b','visitor'),foreign=actor(3,'f','foreign'),owner=actor(1,'c','owner'),otherOwner=actor(4,'d','otherowner');
function headers(who,extra={}){return {'Content-Type':'application/json','X-Fixture-User':String(who.user),'X-Fixture-Now':String(now),Cookie:who.cookie,...extra};}
async function request(endpoint,method,fields,who=owner,extra={}){
  const read=['GET','HEAD'].includes(method);const r=await fetch(base+'/api/'+endpoint+(read?'?'+new URLSearchParams(fields):''),{method,headers:headers(who,extra),...(!read?{body:JSON.stringify(fields)}:{})});
  const raw=await r.text();if(r.status===500&&!raw)throw Error('Empty HTTP 500: '+logs.slice(-4000));let data=null;try{data=raw?JSON.parse(raw):null;}catch{throw Error(raw+'\n'+logs.slice(-3000));}
  return {status:r.status,data,raw};
}
const state=()=>JSON.parse(invoke('state'));
const profile=(method,fields,who=visitor,extra={})=>request('profile-agent.php',method,fields,who,extra);
const chat=(method,fields,who=owner,extra={})=>request('chat-notifications-brain-v240.php',method,fields,who,extra);
const modelCalls=()=>fs.existsSync(path.join(tmp,'model.log'))?fs.readFileSync(path.join(tmp,'model.log'),'utf8').trim().split('\n').filter(Boolean).length:0;
try{
  let ready=false;for(let i=0;i<80;i++){try{const r=await fetch(base+'/boot.php',{headers:headers(visitor)});if(r.status===200){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,25));}assert.ok(ready,logs);
  const boot=await (await fetch(base+'/boot.php',{headers:headers(visitor)})).json();
  const foreignBoot=await (await fetch(base+'/boot.php',{headers:headers(foreign)})).json();
  const publicBase={username:'fixture-owner',profile_token:boot.token,conversation_namespace:boot.namespace};
  const presence={action:'doorbell_presence',csrf_token:csrf,tab_id:'c'.repeat(32),chat_conversation_id:11,chat_agent_id:7,visible:true,presence_sequence:1};
  const before=state();
  for(const method of ['GET','HEAD','PUT','DELETE']){
    for(const action of ['doorbell_presence','doorbell_delivery','doorbell_ack']){
      const r=await chat(method,{...presence,action});check(r.status===405,method+' cannot mutate '+action+' ('+r.status+' '+r.raw+')');
    }
    const r=await profile(method,{action:'doorbell_reply',csrf_token:csrf,turn_id:1,revision:1,conversation_id:1,request_id:'e'.repeat(32),message:'Never send'},owner);
    check(r.status===405,method+' cannot send a public owner card reply');
  }
  for(const token of ['', 'wrong', []]){
    const r=await chat('POST',{...presence,csrf_token:token});check(r.status===419,'owner card requires body CSRF');
    const reply=await profile('POST',{action:'doorbell_reply',csrf_token:token},owner);check(reply.status===419,'public owner reply requires body CSRF');
  }
  let r=await chat('POST',presence,visitor);check(r.status===403,'visitor cannot heartbeat a foreign owner Chat');
  r=await chat('POST',presence,actor(0,'b','anonymous'));check(r.status===401,'anonymous caller cannot use owner card API');
  check(JSON.stringify(state())===JSON.stringify(before),'denied owner requests leave public and private stored state unchanged');
  r=await chat('POST',presence);check(r.status===200&&r.data.reachable,'production endpoint accepts fresh owned main Chat presence');
  r=await profile('POST',{...publicBase,action:'message',client_request_id:'e'.repeat(32),message:'What are the public hours?',async:false});
  check(r.status===200&&r.data.accepted&&r.data.answer===''&&r.data.handoff.state==='awaiting_delivery'&&modelCalls()===0,'native HTTP always queues handoff even if client supplies async=false');
  const receipt=r.data,turn=receipt.handoff,cid=receipt.conversation_id;
  r=await profile('POST',{...publicBase,action:'message',client_request_id:'e'.repeat(32),message:'What are the public hours?'});
  check(r.status===200&&r.data.duplicate&&r.data.handoff.turn_id===turn.turn_id,'actual uncertain-response retry recovers one exact admitted turn');
  const delivered=await chat('POST',{action:'doorbell_delivery',csrf_token:csrf,turn_id:turn.turn_id,revision:turn.revision,tab_id:presence.tab_id,chat_conversation_id:11,chat_agent_id:7});
  check(delivered.status===200&&delivered.data.chat_message_id>0,'production card delivery persists exact private message identity');
  const delivery=delivered.data;
  const ack={action:'doorbell_ack',csrf_token:csrf,turn_id:turn.turn_id,revision:turn.revision,tab_id:presence.tab_id,delivery_id:delivery.delivery_id,chat_conversation_id:11,chat_agent_id:7,chat_message_id:delivery.chat_message_id};
  for(const fields of [{chat_message_id:99999},{chat_conversation_id:12,chat_agent_id:8},{tab_id:'f'.repeat(32)},{turn_id:turn.turn_id+999}]){
    r=await chat('POST',{...ack,...fields});check(r.status===409||r.status===404,'HTTP ACK rejects a substituted card binding');
  }
  r=await chat('POST',ack,otherOwner);check(r.status===403||r.status===404,'foreign owner cannot ACK another card');
  now+=777;r=await chat('POST',ack);check(r.status===200&&r.data.turn.pickup_deadline_ms===now+15000,'actual visible ACK establishes exact server deadline');
  const deadline=r.data.turn.pickup_deadline_ms,ackRevision=r.data.turn.revision;
  now+=1;r=await chat('POST',{...ack,pickup_deadline_ms:now+900000});check(r.status===200&&r.data.duplicate&&r.data.turn.pickup_deadline_ms===deadline,'duplicate HTTP ACK cannot extend pickup');
  for(const action of ['poll','dispatch']){
    r=await profile(action==='poll'?'GET':'POST',{username:'fixture-owner',profile_token:foreignBoot.token,conversation_namespace:foreignBoot.namespace,action,conversation_id:cid,turn_id:turn.turn_id},foreign);
    check(r.status===404||r.status===403,'foreign visitor cannot '+action+' an accepted conversation');
  }
  r=await profile('POST',{...publicBase,action:'dispatch',conversation_id:cid,turn_id:turn.turn_id,profile_token:'wrong'});check(r.status===419,'paid dispatch requires current Profile token');
  r=await profile('GET',{...publicBase,action:'dispatch',conversation_id:cid,turn_id:turn.turn_id});check(r.status===405,'read-only dispatch request cannot start a model');
  r=await profile('POST',{...publicBase,action:'dispatch',conversation_id:cid,turn_id:turn.turn_id});
  check(r.status===200&&modelCalls()===0,'exact dispatch before deadline respects the owner pickup window');
  r=await profile('POST',{action:'doorbell_reply',csrf_token:csrf,turn_id:turn.turn_id,revision:ackRevision,conversation_id:cid,chat_conversation_id:11,chat_agent_id:7,request_id:'a'.repeat(32),message:'I am here; these are the hours.'},owner);
  check(r.status===200&&r.data.turn.responder==='owner'&&r.data.message_id>0,'owner explicit card reply reaches exact visitor thread');
  const reply=r.data;
  r=await profile('POST',{action:'doorbell_reply',csrf_token:csrf,turn_id:turn.turn_id,revision:ackRevision,conversation_id:cid,chat_conversation_id:11,chat_agent_id:7,request_id:'a'.repeat(32),message:'I am here; these are the hours.'},owner);
  check(r.status===200&&r.data.duplicate&&r.data.message_id===reply.message_id,'owner HTTP retry returns exact original message');
  r=await profile('GET',{...publicBase,action:'poll',conversation_id:cid});
  check(r.status===200&&r.data.messages.some(m=>m.sender_type==='owner'&&m.message==='I am here; these are the hours.'),'visitor authenticated GET sees attributed owner reply');
  check(!/PRIVATE_CHAT_SECRET|FOREIGN_CHAT_SECRET|credential_hash|accepted_json|lease_token|password_hash/.test(r.raw),'public poll excludes private Chat and execution credentials');
  now=deadline+1;r=await profile('POST',{...publicBase,action:'dispatch',conversation_id:cid,turn_id:turn.turn_id});
  check(r.status===200&&modelCalls()===0,'expired original timer cannot override the owner response');
  r=await profile('POST',{...publicBase,action:'dispatch',conversation_id:cid,turn_id:turn.turn_id},{...visitor,user:3});
  check(r.status===409&&r.data.code==='PROFILE_SESSION_CHANGED','same-browser widget namespace rejects changed member authority');
  const saved=state();
  check(saved.profile_agent_messages.filter(m=>m.sender_type==='owner').length===1&&saved.profile_agent_messages.every(m=>m.message!=='PRIVATE_CHAT_SECRET'),'ordinary private Chat has no implicit forwarding route');
  check(saved.chat_messages.some(m=>m.message==='PRIVATE_CHAT_SECRET')&&saved.chat_messages.some(m=>m.message==='FOREIGN_CHAT_SECRET'),'public handling preserves both owners private Chat text');
  r=await chat('POST',{...presence,presence_sequence:2,availability:'away'});
  check(r.status===200&&!r.data.reachable,'owner Away setting takes effect through actual Chat endpoint');
  const foreignBase={username:'fixture-owner',profile_token:foreignBoot.token,conversation_namespace:foreignBoot.namespace};
  r=await profile('POST',{...foreignBase,action:'message',client_request_id:'f'.repeat(32),message:'Please explain the public hours.'},foreign);
  check(r.status===200&&r.data.handoff.state==='agent_ready','new visitor bypasses unavailable owner immediately');
  const automatic=r.data;
  r=await profile('POST',{...foreignBase,action:'dispatch',conversation_id:automatic.conversation_id,turn_id:automatic.handoff.turn_id},foreign);
  check(r.status===200&&r.data.dispatch_checked===true&&!r.raw.includes('PA3 approved public answer')&&modelCalls()===1,'actual POST runs exact public job and returns no slow-request answer payload');
  const inference=JSON.parse(fs.readFileSync(path.join(tmp,'model.log'),'utf8').trim());
  check(inference.session_active===false,'public dispatch releases PHP session lock before inference');
  r=await profile('GET',{...foreignBase,action:'poll',conversation_id:automatic.conversation_id},foreign);
  check(r.status===200&&r.data.messages.filter(m=>m.sender_type==='agent').length===1&&r.data.handoff.state==='replied','fresh visitor poll returns exactly one durable Agent answer');
  await profile('POST',{...foreignBase,action:'dispatch',conversation_id:automatic.conversation_id,turn_id:automatic.handoff.turn_id},foreign);
  check(modelCalls()===1,'completed public POST retry cannot invoke inference again');
  const guest=actor(0,'d','guest'),guestBoot=await (await fetch(base+'/boot.php',{headers:headers(guest)})).json(),beforeFailure=state();
  r=await profile('POST',{username:'fixture-owner',profile_token:guestBoot.token,conversation_namespace:guestBoot.namespace,action:'message',client_request_id:'d'.repeat(32),message:'A rollback-only test question.'},guest,{'X-Fixture-Fault':'turn'});
  check(r.status===500&&r.data.ok===false&&!/SECRET|SQLSTATE|credential|\.php/.test(r.raw),'admission SQL failure is a safe unsuccessful HTTP response');
  check(JSON.stringify(state())===JSON.stringify(beforeFailure),'actual failed HTTP admission rolls back messages, attention, cards and work together');
  console.log(`PROFILE_HANDOFF_API_PA3=PASS (${checks} checks; ${driver})`);
}finally{
  child.kill('SIGTERM');await new Promise(r=>child.once('exit',r));invoke('cleanup');fs.rmSync(tmp,{recursive:true,force:true});
}
