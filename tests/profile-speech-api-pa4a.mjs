import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn,spawnSync} from 'node:child_process';

const root=process.cwd(),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-speech-api-')),php=process.env.VP3_PHP_BINARY||'php',config=path.join(tmp,'fixture.json');
const fixture=path.resolve('tests/fixtures/profile-speech-api-pa4a-db.php');
const invoke=(action,input={})=>{const r=spawnSync(php,[fixture,action,config,JSON.stringify(input)],{encoding:'utf8'});assert.equal(r.status,0,r.stderr||r.stdout);return r.stdout;};
let init=JSON.parse(invoke('init')),now=init.now,intent=1000,checks=0,logs='';
for(const name of ['includes','api','sessions'])fs.mkdirSync(path.join(tmp,name));
for(const name of fs.readdirSync('includes'))if(name!=='bootstrap.php')fs.symlinkSync(path.resolve('includes',name),path.join(tmp,'includes',name));
fs.copyFileSync('api/profile-speech-pa4a.php',path.join(tmp,'api/profile-speech-pa4a.php'));
fs.copyFileSync('tests/fixtures/profile-speech-api-pa4a-bootstrap.php',path.join(tmp,'includes/bootstrap.php'));
fs.writeFileSync(path.join(tmp,'boot.php'),`<?php require __DIR__.'/includes/bootstrap.php'; header('Content-Type: application/json'); $ctx=vp3_profile_agent_public_context_v110(db(),profile_by_username(db(),'fixture-owner'),current_user()); echo json_encode(['token'=>profile_chat_token(1),'namespace'=>hash('sha256','profile-widget-v1|'.$ctx['conversation_authority']['credential_hash'])],JSON_THROW_ON_ERROR);`);
const reservation=net.createServer();await new Promise(r=>reservation.listen(0,'127.0.0.1',r));const port=reservation.address().port;await new Promise(r=>reservation.close(r));
const child=spawn(php,['-d','display_errors=0','-d','session.save_path='+path.join(tmp,'sessions'),'-S',`127.0.0.1:${port}`,'-t',tmp],{env:{...process.env,VP3_PA3_ROOT:root,VP3_PA3_CONFIG:config,VP3_PA4A_PROVIDER_LOG:path.join(tmp,'provider.log'),VP3_PA4A_NEW_SESSION:path.join(tmp,'new-session.txt')},stdio:['ignore','ignore','pipe']});
child.stderr.on('data',v=>logs+=v);
const base=`http://127.0.0.1:${port}`,key=n=>n.toString(16).padStart(32,'0');
const actor=(user,browser,session)=>({user,cookie:`PHPSESSID=speech${session}; sf_profile_visitor=${'a'.repeat(64)}; sf_profile_conversation=${browser.repeat(64)}`});
const owner=actor(1,'c','owner'),visitor=actor(2,'b','visitor'),foreign=actor(3,'f','foreign'),otherOwner=actor(4,'d','other'),guest=actor(0,'e','guest');
const own={csrf_token:'pa3-fixture-csrf',chat_conversation_id:11,chat_agent_id:7,tab_id:'a'.repeat(32),client_instance_id:'c'.repeat(32)};
const headers=(who,extra={})=>({'Content-Type':'application/json','X-Fixture-User':String(who.user),'X-Fixture-Now':String(now),Cookie:who.cookie,...extra});
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const state=()=>JSON.parse(invoke('state'));
const records=()=>fs.existsSync(path.join(tmp,'provider.log'))?fs.readFileSync(path.join(tmp,'provider.log'),'utf8').trim().split('\n').filter(Boolean).map(JSON.parse):[];
const sends=()=>records().filter(r=>r.operation==='synthesize');
const safe=raw=>!/PRIVATE_CHAT_SECRET|FOREIGN_CHAT_SECRET|credential_hash|authority_json|execution_token|audio_key|provider_request_id|voice_id|SECRET_|SQLSTATE/.test(raw);
async function request(method,fields,who=owner,extra={},rawBody=null){
 const read=['GET','HEAD'].includes(method),r=await fetch(base+'/api/profile-speech-pa4a.php'+(read?'?'+new URLSearchParams(fields):''),{method,headers:headers(who,extra),...(!read?{body:rawBody??JSON.stringify(fields)}:{})});
 const bytes=Buffer.from(await r.arrayBuffer()),type=r.headers.get('content-type')||'',raw=type.startsWith('audio/')?'':bytes.toString();let data=null;
 if(raw)try{data=JSON.parse(raw);}catch{throw Error(raw+'\n'+logs.slice(-3000));}
 return {status:r.status,data,raw,bytes,type,length:r.headers.get('content-length'),cookies:r.headers.getSetCookie(),cache:r.headers.get('cache-control')};
}
const boot=async who=>(await (await fetch(base+'/boot.php',{headers:headers(who)})).json());
let pub,gp;
const reset=async()=>{init=JSON.parse(invoke('init'));now=init.now;fs.writeFileSync(path.join(tmp,'provider.log'),'');const v=await boot(visitor);pub={username:'fixture-owner',profile_token:v.token,conversation_namespace:v.namespace};const g=await boot(guest);gp={username:'fixture-owner',profile_token:g.token,conversation_namespace:g.namespace};};
const getItem=async id=>{const r=await request('GET',{...own,action:'owner_state'});assert.equal(r.status,200,r.raw);return r.data.items.find(i=>i.id===id);};
async function delivered(id){let item=await getItem(id);if(!item){const row=state().profile_speech_items_pa4a.find(i=>Number(i.id)===id);const r=await request('GET',{...own,action:'owner_state',public_conversation_id:Number(row.public_conversation_id)});assert.equal(r.status,200,r.raw);item=r.data.items.find(i=>i.id===id);}
 const r=await request('POST',{...own,action:'owner_delivery',item_id:id,revision:item.revision,request_id:key(++intent)});assert.equal(r.status,200,r.raw);const i=r.data.item;return {...own,item_id:id,revision:i.revision,chat_message_id:i.chat_message_id,delivery_id:i.delivery_id,mode:'manual',request_id:key(++intent)};}
async function claimed(id,extra={},mode=''){const input={...(await delivered(id)),...extra};const r=await request('POST',{...input,action:'owner_claim'},owner,mode?{'X-Fixture-Provider-Mode':mode}:{});assert.equal(r.status,200,r.raw);return {...input,revision:r.data.item.revision,attempt_id:r.data.attempt_id,ticket:r.data.ticket};}
const advance=ms=>{now+=ms;invoke('presence',{now});};
try{
 let ready=false;for(let i=0;i<80;i++){try{const r=await fetch(base+'/boot.php',{headers:headers(visitor)});if(r.status===200){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,25));}assert.ok(ready,logs);await reset();
 const before=state();
 for(const method of ['GET','HEAD','PUT','DELETE'])for(const action of ['owner_claim','owner_audio','owner_play','owner_report','owner_retry','visitor_preference']){const r=await request(method,{action},owner);check(r.status===405,method+' cannot mutate '+action);}
 for(const action of ['owner_delivery','owner_claim','owner_audio','owner_play','owner_report','owner_retry']){const r=await request('POST',{...own,action,csrf_token:'wrong'});check(r.status===419,'body CSRF protects '+action);}
 let r=await request('POST',{...own,action:'owner_claim'},guest);check(r.status===401,'guest cannot obtain an owner speech ticket');
 r=await request('GET',{...own,action:'owner_state'},visitor);check(r.status===403,'visitor cannot read another person’s private Chat speech items');
 for(const raw of ['[]','null','{','"text"']){r=await request('POST',{},owner,{},raw);check(r.status===422,'non-object or malformed JSON is rejected');}
 r=await request('POST',{},owner,{},JSON.stringify({action:'owner_claim',extra:'x'.repeat(9000)}));check(r.status===413,'oversized speech payload is rejected before execution');
 for(const injected of [{text:'PRIVATE_CHAT_SECRET'},{voice_id:'foreignClone'},{provider_url:'https://example.invalid/'},{public_conversation_id:1}]){r=await request('POST',{...own,action:'owner_claim',...injected});check(r.status===422,'owner speech cannot accept arbitrary text, provider voice/URL or a write-time thread selector');}
 r=await request('GET',{...pub,action:'visitor_state',conversation_namespace:'0'.repeat(64)},visitor);check(r.status===409,'visitor preference read requires current native namespace');
 r=await request('POST',{...pub,action:'visitor_preference',profile_token:'wrong',style:'female',revision:1,request_id:key(++intent)},visitor);check(r.status===419,'visitor preference write requires the current Profile token');
 check(JSON.stringify(state())===JSON.stringify(before)&&sends().length===0,'denied requests cause no speech/domain writes or paid synthesis');
 r=await request('GET',{...own,action:'owner_state'});check(r.status===200&&r.data.items.length===1&&r.data.items[0].kind==='arrival'&&safe(r.raw),'owner reads only bounded authorized saved arrival text');
 const input=await delivered(init.item_id);r=await request('POST',{...input,action:'owner_claim',mode:'automatic'});check(r.status===200&&r.data.ticket?.length===64&&sends().length===0,'claim reserves exact source and ticket without paid synthesis');
 let claim={...input,mode:'automatic',revision:r.data.item.revision,attempt_id:r.data.attempt_id,ticket:r.data.ticket};const firstTicket=claim.ticket;
 r=await request('POST',{...claim,action:'owner_claim'});check(r.status===200&&r.data.duplicate&&r.data.attempt_id===claim.attempt_id&&state().profile_speech_attempts_pa4a.length===1,'lost claim response recovers the same durable attempt');claim.ticket=r.data.ticket;
 r=await request('POST',{...claim,action:'owner_audio',ticket:firstTicket});check(r.status===409&&sends().length===0,'superseded ticket cannot start the provider request');
 r=await request('POST',{...claim,action:'owner_audio'},otherOwner);check(r.status===403&&sends().length===0,'another owner cannot use the speech ticket');
 r=await request('POST',{...claim,action:'owner_audio'});check(r.status===200&&r.type==='audio/mpeg'&&r.bytes.length===1668&&Number(r.length)===1668&&r.cache.includes('no-store'),'authorized audio is fully buffered with bounded binary headers');
 check(sends().length===1&&sends()[0].text==='Visitor A visited your profile.'&&sends()[0].voice_id==='fixtureOwnerVoice','arrival synthesis uses only the saved narrator source and owner Agent voice');
 r=await request('POST',{...claim,action:'owner_audio'});check(r.status===200&&sends().length===1,'ready audio retry serves saved bytes without a second paid request');
 const play={...claim,action:'owner_play',request_id:key(++intent)};r=await request('POST',play);check(r.status===200&&r.data.granted&&r.data.lease_until_ms===now+30000,'explicit playback receives a30-second current-source lease');const playToken=r.data.play_token,lease=r.data.lease_until_ms;
 r=await request('POST',play);check(r.status===200&&r.data.duplicate&&r.data.lease_until_ms===lease,'play grant retry cannot extend the original deadline');
 advance(1000);const report={...claim,action:'owner_report',play_token:playToken,status:'playing',report_sequence:1,request_id:key(++intent)};r=await request('POST',report);check(r.status===200&&r.data.report_sequence===1&&r.data.lease_until_ms===now+30000,'playing report renews through exact positive report sequence');const renewal=r.data.lease_until_ms;
 advance(1000);r=await request('POST',report);check(r.status===200&&r.data.server_now_ms===now&&r.data.lease_until_ms===renewal,'duplicate renewal returns its original deadline with fresh server time');
 r=await request('POST',{...report,report_sequence:2,request_id:key(++intent)});assert.equal(r.status,200,r.raw);r=await request('POST',report);check(r.status===409,'older sequence cannot renew after a newer playback report');
 r=await request('POST',{...claim,action:'owner_report',play_token:playToken,status:'ended',request_id:key(++intent)});check(r.status===200&&r.data.client_reported&&r.data.state==='ended','completion is recorded as a browser report, not proof a person heard audio');
 const completed=await getItem(init.item_id);check(completed.latest_attempt_id===claim.attempt_id&&!completed.automatic_allowed&&completed.playback_state==='ended','reload exposes exact last attempt and consumes automatic playback once');
 advance(95000);let manual=await claimed(init.item_id);r=await request('POST',{...manual,action:'owner_audio'});check(r.status===200&&sends().length===1&&state().profile_speech_attempts_pa4a.some(a=>Number(a.cache_of)===claim.attempt_id),'explicit replay creates a new playback intent using saved audio without billing');
 invoke('expire_audio',{now,attempt_id:manual.attempt_id});r=await request('POST',{...manual,action:'owner_audio'});check(r.status===409&&sends().length===1,'expired audio never triggers automatic regeneration');
 check(records().filter(r=>['metadata','synthesize'].includes(r.operation)).every(r=>r.session_active===false),'owner metadata and paid requests run without the PHP session lock');

 await reset();let m=JSON.parse(invoke('message',{text:'Exact saved visitor words.',mode:'agent'}));r=await request('GET',{...own,action:'owner_state'});check(!r.data.items.some(i=>i.id===m.item_id),'Agent-only visitor messages do not auto-read into owner Chat');
 r=await request('GET',{...own,action:'owner_state',public_conversation_id:m.conversation_id});check(r.status===200&&r.data.items.some(i=>i.id===m.item_id&&i.display_text==='Exact saved visitor words.'),'explicit owned public thread exposes its exact authorized message for manual review');
 const foreignCid=Number(invoke('foreign_thread'));r=await request('GET',{...own,action:'owner_state',public_conversation_id:foreignCid});check(r.status===403&&safe(r.raw),'explicit foreign public thread is denied rather than silently returning an empty/all list');
 let messageClaim=await claimed(m.item_id);r=await request('POST',{...messageClaim,action:'owner_audio'});check(r.status===200&&sends()[0].text==='Exact saved visitor words.'&&sends()[0].voice_id!=='fixtureOwnerVoice','manual message audio uses the persisted public message and distinct visitor voice');
 check(state().chat_messages.some(m=>m.message==='PRIVATE_CHAT_SECRET')&&!sends().some(r=>r.text.includes('PRIVATE_CHAT_SECRET')),'ordinary private Chat text stays private and never becomes a speech source');

 await reset();claim=await claimed(init.item_id);r=await request('POST',{...claim,action:'owner_audio'},owner,{'X-Fixture-Provider-Mode':'unknown'});check(r.status===409&&sends().length===1&&state().profile_speech_attempts_pa4a[0].provider_state==='outcome_unverified','lost provider result is recorded as unverified without claiming completion');
 r=await request('POST',{...claim,action:'owner_audio'});check(r.status===409&&sends().length===1,'same uncertain attempt never automatically sends again');
 const unknown=await getItem(init.item_id);check(unknown.latest_attempt_id===claim.attempt_id&&unknown.latest_attempt_state==='outcome_unverified','reload retains exact unknown attempt for an explicit linked retry');
 const retryInput={...(await delivered(init.item_id)),action:'owner_retry',retry_of:claim.attempt_id,mode:'manual'};r=await request('POST',retryInput);check(r.status===409&&sends().length===1,'a new potentially charged retry requires explicit confirmation');
 r=await request('POST',{...retryInput,retry_confirmed:true});check(r.status===200&&r.data.retry_may_charge&&r.data.attempt_id!==claim.attempt_id,'confirmed retry links a new attempt and discloses possible provider quota');
 const retry={...retryInput,revision:r.data.item.revision,attempt_id:r.data.attempt_id,ticket:r.data.ticket};delete retry.retry_of;delete retry.action;r=await request('POST',{...retry,action:'owner_audio'});check(r.status===200&&sends().length===2,'only an explicit linked retry authorizes another paid request');

 await reset();const beforeClaim=await delivered(init.item_id);r=await request('POST',{...beforeClaim,action:'owner_claim'},owner,{'X-Fixture-Provider-Mode':'logout_metadata'});check(r.status===403&&state().profile_speech_attempts_pa4a.length===0&&sends().length===0,'logout during delayed catalog I/O is rechecked before claim mutation');
 const preferenceBefore=state().profile_visitor_voices_pa4a;r=await request('POST',{...pub,action:'visitor_preference',style:'female',revision:1,request_id:key(++intent)},visitor,{'X-Fixture-Provider-Mode':'logout_metadata'});check([403,404].includes(r.status)&&JSON.stringify(preferenceBefore)===JSON.stringify(state().profile_visitor_voices_pa4a),'visitor logout during metadata cannot save a preference under the old member identity');
 r=await request('GET',{...pub,action:'visitor_state'},visitor,{'X-Fixture-Provider-Mode':'logout_metadata'});check([403,404].includes(r.status),'visitor read also rechecks the captured session after metadata');
 check(records().filter(r=>r.operation==='metadata').every(r=>!r.session_active),'delayed visitor metadata releases the PHP session for concurrent Chat/logout');
 await reset();claim=await claimed(init.item_id);r=await request('POST',{...claim,action:'owner_audio'});assert.equal(r.status,200,r.raw);r=await request('POST',{...claim,action:'owner_audio'},owner,{'X-Fixture-Provider-Mode':'logout_cached'});check(r.status===403&&r.type.startsWith('application/json')&&sends().length===1,'logout during cached artifact read prevents buffered bytes from being emitted');
 await reset();claim=await claimed(init.item_id);r=await request('POST',{...claim,action:'owner_audio'},owner,{'X-Fixture-Provider-Mode':'rotate_audio'});check(r.status===403&&r.type.startsWith('application/json')&&fs.existsSync(path.join(tmp,'new-session.txt')),'account switch during paid synthesis discards the delayed browser result');
 check(r.cookies.length===0&&state().profile_speech_attempts_pa4a[0].provider_state==='ready','delayed session refresh emits no replacement cookie and retains real provider completion evidence');
 for(const mode of ['disable_profile','disable_agent','block_visitor']){await reset();claim=await claimed(init.item_id);r=await request('POST',{...claim,action:'owner_audio'},owner,{'X-Fixture-Provider-Mode':mode});const a=state().profile_speech_attempts_pa4a[0];check([403,404,409].includes(r.status)&&r.type.startsWith('application/json')&&a.provider_state==='ready'&&a.delivery_state==='blocked','late source authority change retains usage and blocks audio: '+mode);}
 console.log(`PROFILE_SPEECH_API_PA4A=PASS (${checks} checks; ${init.driver})`);
}finally{child.kill('SIGTERM');await new Promise(r=>child.once('exit',r));invoke('cleanup');fs.rmSync(tmp,{recursive:true,force:true});}
