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
const child=spawn(php,['-d','display_errors=0','-S',`127.0.0.1:${port}`,'-t',tmp],{env:{...process.env,VP3_PA3_ROOT:root,VP3_PA3_CONFIG:config,VP3_PA3_INFERENCE_LOG:path.join(tmp,'model.log'),VP3_PA3_HOME_LOG:path.join(tmp,'home.log')},stdio:['ignore','ignore','pipe']});
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
const homeCalls=()=>fs.existsSync(path.join(tmp,'home.log'))?fs.readFileSync(path.join(tmp,'home.log'),'utf8').trim().split('\n').filter(Boolean).length:0;
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
  const legacyReply={action:'owner_reply',csrf_token:csrf,conversation_id:cid,request_id:'9'.repeat(32),message:'Owner inbox retry stays on this visitor.'};
  r=await profile('POST',legacyReply,owner);
  check(r.status===200&&r.data.request_id===legacyReply.request_id&&r.data.message_id>0&&r.data.duplicate===false,'legacy inbox HTTP reply acknowledges its exact prepared request ('+r.status+' '+(r.data.error||'')+')');
  const legacyReceipt=r.data,afterLegacy=JSON.stringify(state());
  r=await profile('POST',legacyReply,owner);
  check(r.status===200&&r.data.duplicate===true&&r.data.message_id===legacyReceipt.message_id,'legacy inbox lost-ACK retry recovers its original turn despite advanced revision');
  check(JSON.stringify(state())===afterLegacy&&modelCalls()===1,'legacy retry publishes no second reply, event or competing Agent action');
  r=await profile('POST',{...legacyReply,message:'Changed retry body'},owner);
  check(r.status===409&&r.data.code==='OWNER_REPLY_CONFLICT','legacy operation request cannot be replayed with changed message content');
  r=await profile('POST',{...legacyReply,conversation_id:automatic.conversation_id},owner);
  check(r.status===409&&r.data.code==='OWNER_REPLY_CONFLICT','legacy operation request cannot be moved to a different owned visitor thread');
  r=await profile('POST',legacyReply,otherOwner);
  check(r.status===404&&JSON.stringify(state())===afterLegacy,'current conversation ownership is still required to recover a saved reply');

  const responseSettings={action:'save_profile_agent',csrf_token:csrf,profile_agent_id:7,profile_agent_enabled:1,profile_agent_greeting:'',profile_agent_instructions:'Use approved facts',profile_agent_response_mode:'user_responses',profile_agent_response_revision:0};
  r=await profile('POST',responseSettings,owner);
  check(r.status===200&&r.data.state.profile.profile_agent_response_mode==='user_responses'&&r.data.state.profile.profile_agent_response_revision===1,'actual owner HTTP saves and returns the selected response mode ('+r.status+' '+(r.data.error||'')+')');
  const selectedMode=JSON.stringify(state());
  r=await profile('POST',{...responseSettings,profile_agent_response_mode:'automated'},owner);
  check(r.status===409&&JSON.stringify(state())===selectedMode,'stale settings revision cannot overwrite mode through HTTP');
  r=await profile('POST',{...responseSettings,profile_agent_response_mode:'invalid',profile_agent_response_revision:1},owner);
  check(r.status===422&&JSON.stringify(state())===selectedMode,'invalid HTTP mode leaves all saved Profile and response settings unchanged');

  const waitingVisitor=actor(0,'e','waiting'),waitingBoot=await(await fetch(base+'/boot.php',{headers:headers(waitingVisitor)})).json();
  const waitingBase={username:'fixture-owner',profile_token:waitingBoot.token,conversation_namespace:waitingBoot.namespace};
  const waitingMessage={...waitingBase,action:'message',client_request_id:'7'.repeat(32),message:'Please ask the owner to confirm the opening time.',chat_mode:'agent'};
  const providersBefore=[modelCalls(),homeCalls()];
  r=await profile('POST',waitingMessage,waitingVisitor);
  check(r.status===200&&r.data.accepted&&r.data.handoff.state==='owner_requested'&&r.data.answer===''&&r.data.response.request.status==='waiting','User responses HTTP admission overrides stale Agent choice and returns a durable submitted request');
  const waiting=r.data,waitingRequest=waiting.response.request;
  check(Number.isInteger(waitingRequest.id)&&waitingRequest.receipt_message_id>0&&waitingRequest.reply_message_id===0,'waiting HTTP receipt exposes exact integer identity without claiming an owner answer');
  let responseRows=state();
  check(responseRows.profile_agent_owner_requests_v1.length===1&&responseRows.profile_agent_messages.filter(m=>m.conversation_id==waiting.conversation_id&&m.sender_type==='receipt').length===1,'actual User responses POST stores one owner request and one system-receipt row');
  r=await profile('POST',waitingMessage,waitingVisitor);
  check(r.status===200&&r.data.duplicate&&r.data.response.request.id===waitingRequest.id&&state().profile_agent_owner_requests_v1.length===1,'lost submission response retries the same HTTP owner request');
  now+=60000;
  r=await profile('POST',{...waitingBase,action:'dispatch',conversation_id:waiting.conversation_id,turn_id:waiting.handoff.turn_id},waitingVisitor);
  check(r.status===200&&JSON.stringify([modelCalls(),homeCalls()])===JSON.stringify(providersBefore),'expired pickup interval and explicit HTTP dispatch start neither Cloud nor HomeServer inference');
  r=await profile('GET',{...waitingBase,action:'poll',conversation_id:waiting.conversation_id},waitingVisitor);
  check(r.status===200&&r.data.response.request.status==='waiting'&&r.data.handoff.state==='owner_requested'&&r.data.messages.filter(m=>m.sender_type==='system'&&m.id===waitingRequest.receipt_message_id).length===1&&r.data.messages.every(m=>m.sender_type!=='agent'),'fresh HTTP poll preserves waiting status and the submission receipt without an Agent answer');
  const ownerResponse={action:'owner_reply',csrf_token:csrf,conversation_id:waiting.conversation_id,request_id:'8'.repeat(32),message:'The opening time is 9am, confirmed by the owner.'};
  r=await profile('POST',ownerResponse,owner);
  check(r.status===200&&r.data.message_id>0,'owner HTTP reply completes the selected waiting conversation');
  const answeredMessage=r.data.message_id;
  r=await profile('GET',{...waitingBase,action:'poll',conversation_id:waiting.conversation_id},waitingVisitor);
  check(r.status===200&&r.data.response.request.status==='answered'&&r.data.response.request.reply_message_id===answeredMessage&&r.data.messages.filter(m=>m.sender_type==='owner'&&m.id===answeredMessage).length===1,'fresh visitor HTTP poll distinguishes the owner answer from the retained submission receipt');
  responseRows=state();r=await profile('POST',ownerResponse,owner);
  check(r.status===200&&r.data.duplicate&&JSON.stringify(state())===JSON.stringify(responseRows)&&JSON.stringify([modelCalls(),homeCalls()])===JSON.stringify(providersBefore),'owner HTTP reply retry preserves exact settlement and never dispatches competing inference');

  const knowledgeSource={conversation_id:waiting.conversation_id,owner_request_id:waitingRequest.id};
  const beforeKnowledge=state();
  const accessSnapshot=rows=>JSON.stringify({policies:rows.user_data_policies,rules:rows.user_agent_data_rules,capabilities:rows.role_permissions});
  r=await profile('GET',{action:'owner_knowledge_preview',...knowledgeSource},owner);
  check(r.status===200&&r.data.preview.contract==='vp3.profile-knowledge.v1'
    &&r.data.preview.visitor_message_id===waitingRequest.visitor_message_id&&r.data.preview.reply_message_id===answeredMessage
    &&r.data.preview.question===waitingMessage.message&&r.data.preview.reply===ownerResponse.message
    &&/^[a-f0-9]{64}$/.test(r.data.preview.source_revision),'actual owner GET preview binds the exact saved visitor question and owner reply');
  const preview=r.data.preview;
  check(JSON.stringify(preview.folders)===JSON.stringify([{id:10,name:'Owner answers'}])&&preview.saved===null
    &&JSON.stringify(state())===JSON.stringify(beforeKnowledge),'actual preview lists only owner folders and performs no note, policy or other stored mutation');
  const knowledgeSave={action:'owner_knowledge_save',csrf_token:csrf,...knowledgeSource,source_revision:preview.source_revision,
    title:'Reviewed opening time',content:'The opening time is 9am on weekdays, confirmed by the owner.',folder_id:10,approve_content:true};
  r=await profile('GET',{action:'owner_knowledge_save',...knowledgeSource},owner);
  check(r.status===405,'GET cannot file an owner answer into Knowledge');
  r=await profile('POST',{...knowledgeSave,csrf_token:'wrong'},owner);
  check(r.status===419,'actual Knowledge save requires owner body CSRF');
  r=await profile('GET',{action:'owner_knowledge_preview',...knowledgeSource},otherOwner);
  check(r.status===404&&!r.raw.includes(ownerResponse.message),'a different owner cannot preview this answered request');
  r=await profile('POST',{...knowledgeSave,approve_profile_use:true,audience_scope:'public'},owner);
  check(r.status===422&&JSON.stringify(state())===JSON.stringify(beforeKnowledge),'save rejects parallel sharing fields and denied HTTP requests leave all stored state unchanged');
  r=await profile('POST',knowledgeSave,owner);
  check(r.status===200&&r.data.created===true&&Number.isInteger(r.data.saved.knowledge_id)&&r.data.saved.knowledge_id>0
    &&r.data.saved.folder_id===10&&r.data.saved.folder_name==='Owner answers','actual owner POST files the reviewed answer in the selected existing Knowledge folder');
  const knowledgeId=r.data.saved.knowledge_id,afterKnowledge=state(),savedNote=afterKnowledge.knowledge_items.find(item=>Number(item.id)===knowledgeId);
  check(savedNote?.created_by_user_id==1&&savedNote.folder_id==10&&savedNote.title===knowledgeSave.title&&savedNote.content_text===knowledgeSave.content
    &&savedNote.knowledge_scope==='personal'&&savedNote.file_type==='personal_note'&&savedNote.visibility==='private'&&Number(savedNote.is_published)===0
    &&afterKnowledge.knowledge_chunks.some(chunk=>Number(chunk.knowledge_id)===knowledgeId),'real HTTP storage creates an ordinary owner note and searchable chunks with the reviewed content');
  const filing=afterKnowledge.profile_agent_knowledge_saves_v1;
  check(filing.length===1&&Number(filing[0].owner_request_id)===waitingRequest.id&&Number(filing[0].reply_message_id)===answeredMessage
    &&Number(filing[0].knowledge_id)===knowledgeId&&filing[0].source_revision===preview.source_revision,'actual save retains exact answered-request provenance');
  check(accessSnapshot(afterKnowledge)===accessSnapshot(beforeKnowledge),'actual save preserves every data policy, Agent rule and capability grant without creating item sharing overrides');
  r=await profile('GET',{action:'conversation_messages',conversation_id:waiting.conversation_id},owner);
  check(r.status===200&&r.data.messages.find(message=>message.id===answeredMessage)?.knowledge_requests?.some(request=>request.id===waitingRequest.id),'real owner history decorates the exact answer with its reviewable Knowledge request');
  r=await profile('GET',{...waitingBase,action:'poll',conversation_id:waiting.conversation_id},waitingVisitor);
  check(r.status===200&&r.data.messages.every(message=>!('knowledge_requests' in message))&&!r.raw.includes(knowledgeSave.title),'public visitor history receives no owner Knowledge filing metadata');
  const afterHistory=state();
  r=await profile('POST',knowledgeSave,owner);
  check(r.status===200&&r.data.created===false&&r.data.saved.knowledge_id===knowledgeId&&JSON.stringify(state())===JSON.stringify(afterHistory),'lost Knowledge save acknowledgment retries the same note without duplicate storage or permission changes');
  r=await profile('GET',{action:'owner_knowledge_preview',...knowledgeSource},owner);
  check(r.status===200&&r.data.preview.saved.knowledge_id===knowledgeId&&r.data.preview.saved.view_url.includes('edit='+knowledgeId),'fresh HTTP preview links back to the existing saved Knowledge note');
  r=await profile('POST',{...knowledgeSave,content:'Replace an already filed answer'},owner);
  check(r.status===409&&JSON.stringify(state())===JSON.stringify(afterHistory)&&JSON.stringify([modelCalls(),homeCalls()])===JSON.stringify(providersBefore),'a changed HTTP retry cannot overwrite the note, alter permissions or invoke inference');
  console.log(`PROFILE_HANDOFF_API_PA3=PASS (${checks} checks; ${driver})`);
}finally{
  child.kill('SIGTERM');await new Promise(r=>child.once('exit',r));invoke('cleanup');fs.rmSync(tmp,{recursive:true,force:true});
}
