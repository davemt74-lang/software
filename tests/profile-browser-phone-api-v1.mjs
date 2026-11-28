import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import crypto from 'node:crypto';
import {spawn,spawnSync} from 'node:child_process';

const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-profile-phone-api-'));
const root=process.cwd(),php=process.env.VP3_PHP_BINARY||'php',config=path.join(tmp,'fixture.json');
const fixture=path.resolve('tests/fixtures/profile-browser-phone-api-v1.php');
const invoke=(action,...args)=>{const p=spawnSync(php,[fixture,action,config,...args],{encoding:'utf8'});assert.equal(p.status,0,p.stderr||p.stdout);return p.stdout;};
const driver=invoke('init');
fs.mkdirSync(path.join(tmp,'includes'));fs.mkdirSync(path.join(tmp,'api'));
for(const name of fs.readdirSync('includes'))if(name!=='bootstrap.php')fs.symlinkSync(path.resolve('includes',name),path.join(tmp,'includes',name));
for(const name of ['profile-phone-browser-v1.php','phone-browser-voice-v1.php','phone-browser-status-v1.php','phone-browser-complete-v1.php'])fs.copyFileSync('api/'+name,path.join(tmp,'api',name));
fs.copyFileSync('tests/fixtures/profile-browser-phone-api-bootstrap-v1.php',path.join(tmp,'includes/bootstrap.php'));
fs.writeFileSync(path.join(tmp,'boot.php'),`<?php require __DIR__.'/includes/bootstrap.php'; header('Content-Type: application/json'); $ctx=vp3_profile_agent_public_context_v110(db(),profile_by_username(db(),'fixture-owner'),current_user()); echo json_encode(['token'=>profile_chat_token(1),'namespace'=>hash('sha256','profile-widget-v1|'.$ctx['conversation_authority']['credential_hash'])],JSON_THROW_ON_ERROR);`);
const reservation=net.createServer();await new Promise(r=>reservation.listen(0,'127.0.0.1',r));const port=reservation.address().port;await new Promise(r=>reservation.close(r));
const child=spawn(php,['-d','display_errors=0','-S',`127.0.0.1:${port}`,'-t',tmp],{env:{...process.env,VP3_PA3_ROOT:root,VP3_PA3_CONFIG:config},stdio:['ignore','ignore','pipe']});
const base=`http://127.0.0.1:${port}`,account='AC'+'a'.repeat(32);let logs='',checks=0;child.stderr.on('data',v=>logs+=v);
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const actor=(user,browser,session)=>({user,cookie:`PHPSESSID=phone${session}; sf_profile_visitor=${browser.repeat(64)}; sf_profile_conversation=${browser.repeat(64)}`});
const visitor=actor(0,'b','visitor'),foreign=actor(0,'f','foreign'),member=actor(2,'d','member'),owner=actor(1,'c','owner');
const headers=(who,extra={})=>({'Content-Type':'application/json','X-Fixture-User':String(who.user),Cookie:who.cookie,...extra});
async function api(method,fields,who=visitor,extra={},body=null){
  const read=['GET','HEAD'].includes(method),r=await fetch(base+'/api/profile-phone-browser-v1.php'+(read?'?'+new URLSearchParams(fields):''),{method,headers:headers(who,extra),...(!read?{body:body??JSON.stringify(fields)}:{})});
  const raw=await r.text();if(r.status===500&&!raw)throw Error('Empty HTTP500: '+logs.slice(-3500));let data=null;try{data=raw?JSON.parse(raw):null;}catch{throw Error(raw+'\n'+logs.slice(-3500));}return{status:r.status,data,raw,headers:r.headers};
}
const state=()=>JSON.parse(invoke('state'));
function signature(kind,form,call=''){
  let signed='https://cloud.example/api/phone-browser-'+kind+'-v1.php'+(call?'?call='+call:'');
  for(const key of Object.keys(form).sort())signed+=key+form[key];
  return crypto.createHmac('sha1','profile-phone-carrier-fixture').update(signed).digest('base64');
}
async function callback(kind,form,call='',sig=null){
  const r=await fetch(base+'/api/phone-browser-'+kind+'-v1.php'+(call?'?call='+call:''),{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Twilio-Signature':sig??signature(kind,form,call)},body:new URLSearchParams(form)});
  return{status:r.status,raw:await r.text()};
}
const boot=async who=>{const r=await fetch(base+'/boot.php',{headers:headers(who)});assert.equal(r.status,200,logs);return r.json();};
try{
  let ready=false;for(let i=0;i<80;i++){try{const r=await fetch(base+'/boot.php',{headers:headers(visitor)});if(r.status===200){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,25));}assert.ok(ready,logs);
  const v=await boot(visitor),f=await boot(foreign),m=await boot(member);
  const fields={username:'fixture-owner',profile_token:v.token,conversation_namespace:v.namespace,client_request_id:'1'.repeat(32),conversation_id:0};
  const before=state();
  for(const method of ['HEAD','PUT','DELETE']){const r=await api(method,{...fields,action:'prepare'});check(r.status===405,method+' cannot prepare a public call');}
  for(const [method,action] of [['GET','prepare'],['GET','end'],['POST','state']]){const r=await api(method,{...fields,action});check(r.status===405,method+' cannot use the '+action+' method boundary');}
  for(const patch of [{profile_token:''},{profile_token:'forged'},{profile_token:[]}]){const r=await api('POST',{...fields,...patch,action:'prepare'});check(r.status===419,'public preparation requires the current Profile CSRF token ('+r.status+' '+r.raw+')');}
  for(const patch of [{conversation_namespace:'forged'},{conversation_namespace:[]},{conversation_namespace:''}]){const r=await api('POST',{...fields,...patch,action:'prepare'});check(r.status===409&&r.data.code==='PROFILE_SESSION_CHANGED','public preparation rejects a changed visitor namespace');}
  for(const patch of [{to_number:'+16025550999'},{caller_id:'+16025550999'},{provider_call_sid:'CA'+'f'.repeat(32)},{number_id:62},{client_request_id:[]},{conversation_id:-1}]){const r=await api('POST',{...fields,...patch,action:'prepare'});check(r.status===422,'public API rejects caller-controlled routing or invalid identifiers');}
  let r=await api('POST',{...fields,action:'prepare'},visitor,{'Content-Type':'text/plain'});check(r.status===422,'public call mutations require JSON');
  r=await api('POST',{...fields,action:'prepare'},visitor,{},'x'.repeat(4100));check(r.status===413,'public call body size is bounded before admission');
  r=await api('POST',{...fields,action:'prepare'},owner);check(r.status===403,'profile owner cannot mint a public visitor call token');
  check(JSON.stringify(state())===JSON.stringify(before),'all denied public requests leave stored call state unchanged');
  r=await api('GET',{...fields,action:'state'});check(r.status===200&&r.data.call===null&&!('token'in r.data)&&r.headers.get('cache-control').includes('no-store'),'unknown call GET is private, credential-free and does not allocate a reservation');
  check(JSON.stringify(state())===JSON.stringify(before),'unknown call state GET does not mutate stored calls');

  r=await api('POST',{...fields,action:'end'});check(r.status===200&&r.data.call===null,'HTTP end-before-prepare records the exact cancellation');
  r=await api('POST',{...fields,action:'prepare'});check(r.status===409&&r.data.code==='PROFILE_PHONE_CANCELED','late HTTP prepare cannot resurrect its canceled request');
  fields.client_request_id='2'.repeat(32);
  r=await api('POST',{...fields,action:'prepare'});check(r.status===200&&r.data.ok&&r.data.call.state==='prepared'&&typeof r.data.token==='string','actual native public API issues an outgoing visitor token after exact admission');
  const first=r.data;fields.conversation_id=first.conversation_id;fields.call_id=first.call.id;
  check(state().profile_agent_conversations.find(c=>Number(c.id)===first.conversation_id)?.status==='open','a phone-only conversation remains open without implying an owner chat claim');
  const claims=JSON.parse(Buffer.from(first.token.split('.')[1],'base64url'));
  check(claims.grants.voice.incoming.allow===false&&claims.grants.identity.startsWith('vp3_profile_'),'HTTP token cannot receive calls under the owner identity');
  r=await api('POST',{...fields,action:'prepare'});check(r.status===200&&r.data.call.id===first.call.id&&r.data.connect_params.profile_call_intent===first.connect_params.profile_call_intent,'actual prepare retry recovers the same reservation and intent');
  r=await api('GET',{...fields,action:'state',profile_token:f.token,conversation_namespace:f.namespace},foreign);check(r.status===404,'another browser cannot read the first visitor call');
  r=await api('POST',{...fields,action:'end',profile_token:f.token,conversation_namespace:f.namespace},foreign);check(r.status===404,'another browser cannot cancel the first visitor call');
  r=await api('POST',{...fields,action:'end'},{...visitor,user:2});check(r.status===409&&r.data.code==='PROFILE_SESSION_CHANGED','same browser account switching invalidates the existing widget namespace');
  r=await api('POST',{...fields,action:'end'});check(r.status===200&&r.data.call.state==='canceled','actual prepared cancellation releases the exact public reservation');

  invoke('owner_online');
  fields.client_request_id='3'.repeat(32);delete fields.call_id;
  r=await api('POST',{...fields,action:'prepare'});check(r.status===200,'a fresh request can prepare after verified cancellation');
  const live=r.data,callId=live.call.id;fields.call_id=callId;
  const claim=JSON.parse(Buffer.from(live.token.split('.')[1],'base64url'));
  const voice={AccountSid:account,CallSid:'CA'+'1'.repeat(32),From:'client:'+claim.grants.identity,profile_call_intent:live.connect_params.profile_call_intent,To:'+16025550000'};
  let carrier=await callback('voice',voice,'','forged');check(carrier.status===403&&state().phone_voice_calls_v130.length===0,'actual carrier voice endpoint rejects forged signatures before consuming intent');
  carrier=await callback('voice',voice);check(carrier.status===200&&carrier.raw.includes('<Client ')&&carrier.raw.includes('timeLimit="600"')&&!carrier.raw.includes('<Number'),'actual signed SDK webhook routes directly to the existing owner browser');
  const twiml=carrier.raw,phone=state().phone_browser_calls_v1.find(c=>c.provider_call_sid===voice.CallSid);
  carrier=await callback('voice',voice);check(carrier.status===200&&carrier.raw===twiml&&state().phone_voice_calls_v130.length===1,'actual signed webhook retry reuses one immutable receipt');
  const miss={AccountSid:account,CallSid:'CA'+'2'.repeat(32),ParentCallSid:voice.CallSid,CallStatus:'no-answer',CallDuration:'0'};
  carrier=await callback('status',miss,phone.public_id);check(carrier.status===204,'existing child status HTTP path accepts its exact signed parent binding');
  r=await api('GET',{...fields,action:'state'});check(r.status===200&&!r.data.call.terminal&&!r.data.call.answered,'missed owner child status does not terminate or claim pickup on the public parent');
  const complete={AccountSid:account,CallSid:voice.CallSid,DialCallSid:miss.CallSid,DialCallStatus:'no-answer',DialCallDuration:'0'};
  carrier=await callback('complete',complete,phone.public_id);check(carrier.status===200&&carrier.raw.includes('<Record ')&&carrier.raw.includes('maxLength="120"'),'actual existing complete endpoint sends a missed owner call to bounded voicemail');
  r=await api('GET',{...fields,action:'state'});check(r.status===200&&r.data.call.route_kind==='voicemail'&&!r.data.call.terminal,'public state reports the actual fallback route while the parent continues');
  carrier=await callback('complete',complete,phone.public_id);check(carrier.status===200&&carrier.raw.includes('<Hangup'),'duplicate complete callback cannot claim another fallback');
  const parent={AccountSid:account,CallSid:voice.CallSid,CallStatus:'completed',CallDuration:'14'};
  carrier=await callback('status',parent,'',signature('status',parent,phone.public_id));check(carrier.status===403,'parent lifecycle endpoint verifies the fixed no-query signature rather than a child callback signature');
  carrier=await callback('status',parent);check(carrier.status===204,'actual fixed status endpoint accepts the exact public parent completion');
  r=await api('GET',{...fields,action:'state'});check(r.status===200&&r.data.call.terminal&&r.data.call.state==='completed'&&!r.data.call.answered,'actual public GET exposes terminal carrier evidence without inventing owner pickup');
  check(!/provider_call_sid|credential_hash|session_fingerprint|password_hash|PRIVATE_CHAT_SECRET|FOREIGN_CHAT_SECRET/.test(r.raw),'public call state never exports carrier handles, authority credentials or private Chat');
  carrier=await callback('status',parent);check(carrier.status===204&&state().profile_browser_phone_calls_v1.filter(c=>c.public_id===callId).length===1,'actual parent completion replay is idempotent');

  invoke('phone_agent');fields.client_request_id='5'.repeat(32);delete fields.call_id;
  r=await api('POST',{...fields,action:'prepare'});assert.equal(r.status,200,r.raw);const agentCall=r.data;
  const agentClaims=JSON.parse(Buffer.from(agentCall.token.split('.')[1],'base64url'));
  const agentVoice={AccountSid:account,CallSid:'CA'+'3'.repeat(32),From:'client:'+agentClaims.grants.identity,profile_call_intent:agentCall.connect_params.profile_call_intent};
  carrier=await callback('voice',agentVoice);assert.equal(carrier.status,200,carrier.raw);
  const agentPhone=state().phone_browser_calls_v1.find(c=>c.provider_call_sid===agentVoice.CallSid);invoke('choose_agent',agentPhone.public_id);
  const agentComplete={AccountSid:account,CallSid:agentVoice.CallSid,DialCallSid:'CA'+'4'.repeat(32),DialCallStatus:'no-answer',DialCallDuration:'0'};
  carrier=await callback('complete',agentComplete,agentPhone.public_id);check(carrier.status===200&&carrier.raw.includes('<Gather '),'actual owner-selected fallback uses the configured Phone Agent through the existing complete endpoint');
  r=await api('GET',{...fields,call_id:agentCall.call.id,action:'state'});check(r.status===200&&r.data.call.route_kind==='agent'&&!r.data.call.answered&&!r.data.call.terminal,'public state identifies the actual Phone Agent route without claiming human pickup');
  carrier=await callback('status',{AccountSid:account,CallSid:agentVoice.CallSid,CallStatus:'completed',CallDuration:'5'});check(carrier.status===204,'Phone Agent parent uses the same signed completion boundary');

  const memberFields={username:'fixture-owner',profile_token:m.token,conversation_namespace:m.namespace,client_request_id:'4'.repeat(32),conversation_id:0};
  r=await api('POST',{...memberFields,action:'prepare'},member);check(r.status===200&&r.data.conversation_id!==first.conversation_id,'member visitor receives a separately bound native call conversation');
  const memberPrepared=r.data;
  r=await api('GET',{...memberFields,action:'state'},member);check(r.status===200&&r.data.conversation_id===memberPrepared.conversation_id&&r.data.call.id===memberPrepared.call.id,'a lost prepare response recovers canonical call and conversation from its exact request ID');
  r=await api('POST',{...memberFields,action:'end'},member);check(r.status===200&&r.data.call.state==='canceled','member visitor can cancel only its own prepared request');
  check(r.data.conversation_id===memberPrepared.conversation_id,'cancel after a lost prepare response returns the canonical conversation for safe recovery');
  check(!/PHP (Warning|Fatal)|Uncaught|Fatal error/.test(logs),'actual public and callback requests complete without PHP runtime errors');
  console.log(`PROFILE_BROWSER_PHONE_API_V1=PASS (${checks} checks; ${driver})`);
}finally{
  child.kill('SIGTERM');await new Promise(resolve=>child.once('exit',resolve));
  invoke('cleanup');fs.rmSync(tmp,{recursive:true,force:true});
}
