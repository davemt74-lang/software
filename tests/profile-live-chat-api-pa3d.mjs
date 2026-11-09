import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {randomBytes} from 'node:crypto';
import {spawn,spawnSync} from 'node:child_process';

// Run the unmodified production HTTP adapters and services against real SQL.
// The shared fixture supplies only authentication/session setup and external inference.
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-pa3d-api-'));
const php=process.env.VP3_PHP_BINARY||'php',root=process.cwd(),config=path.join(tmp,'fixture.json');
const fixture=path.resolve('tests/fixtures/profile-handoff-api-pa3-db.php');
const sqlFixture=path.resolve('tests/fixtures/profile-live-chat-api-pa3d-db.php');
function invoke(action,...args){const p=spawnSync(php,[['init','cleanup'].includes(action)?fixture:sqlFixture,action,config,...args.map(String)],{encoding:'utf8'});assert.equal(p.status,0,p.stderr||p.stdout);return p.stdout;}
const driver=invoke('init');
fs.mkdirSync(path.join(tmp,'includes'));fs.mkdirSync(path.join(tmp,'api'));
for(const name of fs.readdirSync('includes'))if(name!=='bootstrap.php')fs.symlinkSync(path.resolve('includes',name),path.join(tmp,'includes',name));
for(const name of ['profile-agent.php','chat-notifications-brain-v240.php'])fs.copyFileSync('api/'+name,path.join(tmp,'api',name));
fs.copyFileSync('tests/fixtures/profile-handoff-api-pa3-bootstrap.php',path.join(tmp,'includes/bootstrap.php'));
fs.writeFileSync(path.join(tmp,'boot.php'),`<?php require __DIR__.'/includes/bootstrap.php'; header('Content-Type: application/json'); $ctx=vp3_profile_agent_public_context_v110(db(),profile_by_username(db(),'fixture-owner'),current_user()); echo json_encode(['token'=>profile_chat_token(1),'namespace'=>hash('sha256','profile-widget-v1|'.$ctx['conversation_authority']['credential_hash'])],JSON_THROW_ON_ERROR);`);
const reservation=net.createServer();await new Promise(r=>reservation.listen(0,'127.0.0.1',r));const port=reservation.address().port;await new Promise(r=>reservation.close(r));
const modelLog=path.join(tmp,'model.log');
const child=spawn(php,['-d','display_errors=0','-S',`127.0.0.1:${port}`,'-t',tmp],{env:{...process.env,VP3_PA3_ROOT:root,VP3_PA3_CONFIG:config,VP3_PA3_INFERENCE_LOG:modelLog},stdio:['ignore','ignore','pipe']});
let logs='',checks=0,now=Date.now(),presenceSequence=0;
child.stderr.on('data',value=>logs+=value);
const base=`http://127.0.0.1:${port}`,csrf='pa3-fixture-csrf',key=()=>randomBytes(16).toString('hex');
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const actor=(user,browser,session)=>({user,cookie:`PHPSESSID=pa3d${session}; sf_profile_visitor=${browser.repeat(64)}; sf_profile_conversation=${browser.repeat(64)}`});
const visitor=actor(2,'b','visitor'),foreign=actor(3,'f','foreign'),owner=actor(1,'c','owner'),otherOwner=actor(4,'d','otherowner');
const headers=(who,extra={})=>({'Content-Type':'application/json','X-Fixture-User':String(who.user),'X-Fixture-Now':String(now),Cookie:who.cookie,...extra});
async function request(endpoint,method,fields,who=visitor,extra={},query={}){
  const read=['GET','HEAD'].includes(method),params=read?fields:query;
  const response=await fetch(base+'/'+endpoint+(Object.keys(params).length?'?'+new URLSearchParams(params):''),{method,headers:headers(who,extra),signal:AbortSignal.timeout(10000),...(!read?{body:JSON.stringify(fields)}:{})});
  const raw=await response.text();let data=null;try{data=raw?JSON.parse(raw):null;}catch{throw Error(raw+'\n'+logs.slice(-4000));}
  if(response.status===500&&!raw)throw Error('Empty HTTP 500: '+logs.slice(-4000));
  return {status:response.status,data,raw};
}
const profile=(method,fields,who=visitor,extra={},query={})=>request('api/profile-agent.php',method,fields,who,extra,query);
const chat=(method,fields,who=owner)=>request('api/chat-notifications-brain-v240.php',method,fields,who);
const boot=async(who=visitor)=>(await request('boot.php','GET',{},who)).data;
const snapshot=()=>JSON.parse(invoke('snapshot'));
const modelCalls=()=>fs.existsSync(modelLog)?fs.readFileSync(modelLog,'utf8').trim().split('\n').filter(Boolean).length:0;
const rows=table=>snapshot().rows[table]||[];
const turn=receipt=>rows('profile_agent_handoff_turns_pa3').find(t=>Number(t.id)===Number(receipt.handoff?.turn_id||receipt.turn_id));
let publicBase,foreignBase;
async function reset(){invoke('init');now=Date.now();presenceSequence=0;if(fs.existsSync(modelLog))fs.writeFileSync(modelLog,'');const a=await boot(),b=await boot(foreign);publicBase={username:'fixture-owner',profile_token:a.token,conversation_namespace:a.namespace};foreignBase={username:'fixture-owner',profile_token:b.token,conversation_namespace:b.namespace};}
async function presence(extra={}){return chat('POST',{action:'doorbell_presence',csrf_token:csrf,tab_id:'c'.repeat(32),chat_conversation_id:11,chat_agent_id:7,visible:true,presence_sequence:++presenceSequence,...extra});}
const send=(mode,message,cid=0,requestId=key(),who=visitor,fields={})=>profile('POST',{...(who===foreign?foreignBase:publicBase),action:'message',conversation_id:cid,client_request_id:requestId,message,...(mode===undefined?{}:{chat_mode:mode}),...fields},who);
const poll=cid=>profile('GET',{...publicBase,action:'poll',conversation_id:cid});
const dispatch=receipt=>profile('POST',{...publicBase,action:'dispatch',conversation_id:receipt.conversation_id,turn_id:receipt.handoff.turn_id});
const ownerFields=(operation,receipt,extra={})=>{const t=turn(receipt);return {action:'doorbell_'+operation,csrf_token:csrf,turn_id:Number(t.id),revision:Number(t.version),conversation_id:Number(t.conversation_id),chat_conversation_id:11,chat_agent_id:7,request_id:key(),message:'The owner is here in this Profile conversation.',...extra};};
const ownerAction=(operation,receipt,extra={},who=owner)=>profile('POST',ownerFields(operation,receipt,extra),who);
const endFields=receipt=>{const t=turn(receipt);return {...publicBase,action:'end_live_chat',conversation_id:Number(t.conversation_id),turn_id:Number(t.id),revision:Number(t.version)};};
const safe=raw=>!/PRIVATE_CHAT_SECRET|FOREIGN_CHAT_SECRET|credential_hash|accepted_json|lease_token|password_hash|SQLSTATE|SECRET fixture|\.php(?::| on line)/.test(raw);
const projection=(data,mode,status,available=true)=>data?.chat?.contract==='vp3.profile-chat-choice.v1'&&data.chat.owner_name==='Fixture Owner'&&data.chat.mode===mode&&data.chat.live_status===status&&data.chat.live_text_available===available&&typeof data.chat.can_end_live_chat==='boolean';
try{
  let ready=false;for(let i=0;i<80;i++){try{if((await request('boot.php','GET',{})).status===200){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,25));}assert.ok(ready,logs);
  await reset();
  let r=await profile('GET',{...publicBase,action:'state'});
  check(r.status===200&&projection(r.data,'auto','none',false),'public state names the owner and fails closed before visible main Chat presence');
  invoke('settings','online',1);
  r=await presence();check(r.status===200&&r.data.reachable,'actual Chat heartbeat establishes fresh exact owner presence');
  r=await profile('GET',{...publicBase,action:'state'});check(projection(r.data,'auto','none'),'existing Online and social settings expose live text availability');
  const beforeSessionRefresh=snapshot();
  r=await profile('GET',{username:'fixture-owner',action:'visitor_session'});
  check(r.status===200&&r.data.conversation_namespace===publicBase.conversation_namespace&&r.data.profile_token===publicBase.profile_token&&Object.keys(r.data).length===3,'session recovery returns only the current native browser binding');
  r=await profile('GET',{username:'fixture-owner',action:'visitor_session',conversation_id:31},foreign);
  check(r.status===422&&r.data.code==='VALIDATION_FAILED','session recovery cannot inspect or adopt a supplied conversation');
  r=await profile('POST',{username:'fixture-owner',action:'visitor_session'});
  check(r.status===405,'session recovery accepts only a read');
  r=await profile('GET',{username:'fixture-owner',action:'visitor_session'},owner);
  check(r.status===403&&r.data.code==='OWNER_VISITOR_PREVIEW_REQUIRED','owner cannot use session recovery to start a visitor conversation with their own profile');
  check(JSON.stringify(snapshot())===JSON.stringify(beforeSessionRefresh)&&modelCalls()===0,'session recovery creates no messages, owner attention, jobs or conversations');
  const invalidBefore=snapshot();
  for(const mode of ['', 'LIVE', 'invalid', null,1,true,[],{}]){
    r=await send(mode,'Invalid mode must never save a turn');
    check(r.status===422&&r.data.ok===false&&r.data.code==='HANDOFF_INVALID'&&safe(r.raw),'HTTP rejects chat_mode '+JSON.stringify(mode)+' with a safe validation response');
  }
  check(JSON.stringify(snapshot())===JSON.stringify(invalidBefore)&&modelCalls()===0,'invalid HTTP modes create no canonical messages, jobs, cards, schema or model calls');

  const agentKey=key();r=await send('agent','An Agent-only public hours question',0,agentKey,visitor,{async:false});const agent=r.data;
  check(r.status===200&&r.data.accepted&&r.data.answer===''&&projection(r.data,'agent','none')&&r.data.handoff.state==='agent_ready','explicit Agent mode queues immediately even with async=false and an available owner');
  check(turn(agent).pickup_deadline_ms===null&&rows('agent_workflow_runs').length===1&&modelCalls()===0,'Agent admission uses the durable job without a pickup clock or synchronous provider');
  r=await chat('POST',{action:'doorbell_list',csrf_token:csrf,chat_conversation_id:11,chat_agent_id:7});
  check(r.status===200&&r.data.turns.length===0&&rows('profile_agent_handoff_deliveries_pa3').length===0,'Agent-only mode creates no proactive owner card');
  r=await chat('POST',{action:'doorbell_delivery',csrf_token:csrf,turn_id:agent.handoff.turn_id,revision:agent.handoff.revision,tab_id:'c'.repeat(32),chat_conversation_id:11,chat_agent_id:7});
  check(r.status===409&&rows('profile_agent_handoff_deliveries_pa3').length===0,'direct delivery cannot ring a new Agent-only conversation');
  r=await send('agent','An Agent-only public hours question',0,agentKey);
  check(r.status===200&&r.data.duplicate&&r.data.handoff.turn_id===agent.handoff.turn_id&&rows('profile_agent_messages').filter(m=>m.sender_type==='visitor').length===1,'same explicit mode retries the exact saved visitor message');
  for(const mode of ['live',undefined]){
    r=await send(mode,'An Agent-only public hours question',0,agentKey);
    check(r.status===409&&r.data.code==='HANDOFF_REPLAY_CONFLICT','saved Agent retry cannot become '+(mode??'legacy auto'));
  }
  r=await dispatch(agent);check(r.status===200&&r.data.dispatch_checked&&modelCalls()===1&&!r.raw.includes('PA3 approved public answer'),'actual dispatch executes the canonical Agent job without returning its slow answer');
  r=await poll(agent.conversation_id);check(r.status===200&&r.data.messages.filter(m=>m.sender_type==='agent').length===1&&safe(r.raw),'authenticated public poll recovers one durable public Agent answer');
  check(JSON.parse(fs.readFileSync(modelLog,'utf8').trim()).session_active===false,'HTTP inference releases the PHP session lock');

  await reset();await presence();const legacyKey=key();r=await send(undefined,'Legacy client without an explicit delivery choice',0,legacyKey);const legacy=r.data;
  check(r.status===200&&projection(r.data,'auto','none')&&r.data.handoff.state==='awaiting_delivery'&&turn(legacy).chat_mode==='auto','missing chat_mode retains legacy auto handoff behavior');
  r=await send(undefined,'Legacy client without an explicit delivery choice',0,legacyKey);check(r.status===200&&r.data.duplicate&&r.data.handoff.turn_id===legacy.handoff.turn_id,'legacy uncertain-response retry retains its exact original request binding');
  r=await send('agent','Legacy client without an explicit delivery choice',0,legacyKey);check(r.status===409&&r.data.code==='HANDOFF_REPLAY_CONFLICT','legacy saved request cannot be reinterpreted as explicit Agent mode');

  for(const [label,mode,social] of [['Offline','offline',1],['social chat disabled','online',0],['invalid Online value','broken',1],['invalid social value','online',2]]){
    await reset();await presence();invoke('settings',mode,social);
    r=await profile('GET',{...publicBase,action:'state'});check(r.status===200&&r.data.chat.live_text_available===false,label+' in existing Chat Settings blocks public live availability');
    r=await send('live','Availability changed before this live request');
    check(r.status===200&&projection(r.data,'live','unavailable',false)&&r.data.handoff.state==='agent_ready'&&!r.data.chat.can_end_live_chat&&modelCalls()===0,label+' live request durably falls back to the Agent without losing its message');
  }
  await reset();await presence({availability:'away'});r=await profile('GET',{...publicBase,action:'state'});check(r.data.chat.live_text_available===false,'Away remains a narrower override of canonical Online settings');
  await presence({availability:'dnd'});r=await profile('GET',{...publicBase,action:'state'});check(r.data.chat.live_text_available===false,'Do Not Disturb remains a narrower override of canonical Online settings');

  await reset();await presence();r=await send('live','First live request in the shared Profile transcript');const live=r.data,cid=live.conversation_id;
  check(r.status===200&&projection(r.data,'live','waiting')&&r.data.chat.can_end_live_chat&&r.data.handoff.state==='awaiting_delivery','explicit live message waits for owner acceptance in the canonical visitor thread');
  r=await chat('POST',{action:'doorbell_delivery',csrf_token:csrf,turn_id:live.handoff.turn_id,revision:live.handoff.revision,tab_id:'c'.repeat(32),chat_conversation_id:11,chat_agent_id:7});const delivery=r.data;
  check(r.status===200&&delivery.chat_message_id>0&&delivery.turn.chat.live_status==='waiting','actual owner card delivery carries the same waiting chat state');
  const ack={action:'doorbell_ack',csrf_token:csrf,turn_id:delivery.turn.turn_id,revision:delivery.turn.revision,delivery_id:delivery.delivery_id,tab_id:'c'.repeat(32),chat_conversation_id:11,chat_agent_id:7,chat_message_id:delivery.chat_message_id};
  now+=500;r=await chat('POST',ack);const deadline=r.data.turn.pickup_deadline_ms;
  check(r.status===200&&deadline===now+15000,'visible exact owner card acknowledgement starts one server-owned 15 second acceptance window');
  invoke('age');now+=700;r=await send('live','A second message while acceptance is pending',cid,key(),visitor,{continue_live:true});const follow=r.data;
  check(r.status===200&&follow.conversation_id===cid&&projection(r.data,'live','waiting')&&r.data.handoff.pickup_deadline_ms===deadline,'pending follow-up preserves the same thread and original acceptance deadline');
  r=await chat('POST',{...ack,pickup_deadline_ms:now+900000});check(r.status===200&&r.data.duplicate&&r.data.turn.pickup_deadline_ms===deadline,'a repeated old card ACK cannot restart the live acceptance countdown');
  const readBefore=snapshot(),readCalls=modelCalls();
  for(const action of ['state','poll']){
    r=await profile('GET',{...publicBase,action,conversation_id:cid});
    check(r.status===200&&projection(r.data,'live','waiting')&&safe(r.raw),'public '+action+' reload recovers waiting live state without private data');
  }
  check(JSON.stringify(snapshot())===JSON.stringify(readBefore)&&modelCalls()===readCalls,'public GET state/poll performs no canonical writes, DDL, deadline maintenance or inference');

  const exactEnd=endFields(follow),beforeDenied=snapshot();
  for(const method of ['GET','HEAD','PUT','DELETE']){r=await profile(method,exactEnd);check(r.status===405,method+' cannot end a live chat');}
  for(const token of [undefined,'wrong']){const fields={...exactEnd};if(token===undefined)delete fields.profile_token;else fields.profile_token=token;r=await profile('POST',fields);check(r.status===419,'end requires a valid Profile token in the POST body');}
  const noBodyToken={...exactEnd};delete noBodyToken.profile_token;r=await profile('POST',noBodyToken,visitor,{}, {profile_token:publicBase.profile_token});check(r.status===419,'query-string Profile token cannot authorize end_live_chat');
  r=await profile('POST',{...exactEnd,conversation_namespace:'e'.repeat(64)});check(r.status===409&&r.data.code==='PROFILE_SESSION_CHANGED','end rejects a substituted widget namespace');
  r=await profile('POST',exactEnd,{...visitor,user:3});check(r.status===409&&r.data.code==='PROFILE_SESSION_CHANGED','same-browser member switch cannot end the old visitor live chat');
  r=await profile('POST',{...exactEnd,...foreignBase},foreign);check([403,404].includes(r.status)&&safe(r.raw),'another authenticated visitor cannot end the conversation with their own valid token');
  for(const fields of [{turn_id:exactEnd.turn_id+9999},{conversation_id:exactEnd.conversation_id+9999},{revision:exactEnd.revision+1},{turn_id:live.handoff.turn_id,revision:Number(turn(live).version)},{turn_id:[]},{revision:true},{conversation_id:0}]){
    r=await profile('POST',{...exactEnd,...fields});check([404,409,422].includes(r.status)&&r.data.ok===false&&safe(r.raw),'end binds exact latest positive conversation, turn and revision '+JSON.stringify(fields));
  }
  check(JSON.stringify(snapshot())===JSON.stringify(beforeDenied)&&modelCalls()===0,'all denied live-end attempts leave the waiting canonical thread unchanged');
  r=await chat('POST',{action:'doorbell_list',csrf_token:csrf,chat_conversation_id:11,chat_agent_id:7});const ownerCard=r.data?.turns?.[0];
  check(r.status===200&&r.data.turns.length===1&&ownerCard.turn_id===follow.handoff.turn_id&&ownerCard.chat.live_status==='waiting','owner rail reads the exact newest waiting visitor card for this Chat');
  const claim={action:'doorbell_claim',csrf_token:csrf,turn_id:ownerCard.turn_id,revision:ownerCard.revision,conversation_id:ownerCard.conversation_id,chat_conversation_id:ownerCard.chat_conversation_id,chat_agent_id:ownerCard.chat_agent_id,request_id:key()},beforeClaim=snapshot();
  for(const fields of [{turn_id:claim.turn_id+9999},{conversation_id:claim.conversation_id+9999},{revision:claim.revision+1},{chat_conversation_id:13,chat_agent_id:9}]){
    r=await profile('POST',{...claim,...fields},owner);check([403,404,409].includes(r.status)&&safe(r.raw),'owner Accept rejects substituted exact card binding '+JSON.stringify(fields));
  }
  r=await profile('POST',claim,otherOwner);check([403,404].includes(r.status),'foreign owner cannot accept this visitor request');
  check(JSON.stringify(snapshot())===JSON.stringify(beforeClaim),'denied owner acceptance has no saved side effects');
  r=await profile('POST',claim,owner);check(r.status===200&&r.data.turn.state==='owner_claimed'&&r.data.turn.chat.live_status==='accepted','actual owner Accept changes the exact saved live conversation to human text');
  r=await dispatch(follow);check(r.status===200&&modelCalls()===0,'Agent dispatch stays paused inside accepted owner chat');
  const reply=ownerFields('reply',follow);r=await profile('POST',reply,owner);const ownerReply=r.data;
  check(r.status===200&&ownerReply.message_id>0&&ownerReply.turn.chat.live_status==='accepted','actual owner reply publishes human text while preserving accepted live state');
  r=await profile('POST',reply,owner);check(r.status===200&&r.data.duplicate&&r.data.message_id===ownerReply.message_id,'owner uncertain-response reply retry saves no duplicate human message');
  r=await poll(cid);check(r.status===200&&projection(r.data,'live','accepted')&&r.data.messages.some(m=>m.sender_type==='owner'&&m.message===reply.message)&&r.data.messages.filter(m=>m.sender_type==='visitor').length===2&&safe(r.raw),'visitor reload sees both requests and exactly attributed owner text in one transcript');
  const acceptedEnd=endFields(follow);r=await profile('POST',acceptedEnd);
  check(r.status===200&&r.data.live_chat_ended&&projection(r.data,'agent','ended')&&!r.data.chat.can_end_live_chat&&r.data.conversation_id===cid,'visitor End live chat returns the same canonical conversation to Agent mode');
  const ended=snapshot();r=await profile('POST',acceptedEnd);check(r.status===200&&r.data.live_chat_ended&&JSON.stringify(snapshot())===JSON.stringify(ended),'exact lost end receipt retries safely without a second state transition');
  invoke('age');r=await send('live','Please start a new live session in this same thread',cid);const newer=r.data;
  check(r.status===200&&newer.conversation_id===cid&&newer.handoff.turn_id!==follow.handoff.turn_id&&projection(r.data,'live','waiting'),'visitor can start another live request in the same canonical transcript');
  const beforeStale=snapshot();r=await profile('POST',acceptedEnd);check(r.status===409&&r.data.code==='HANDOFF_CHANGED'&&JSON.stringify(snapshot())===JSON.stringify(beforeStale),'a stale end receipt cannot cancel a later live request');
  r=await ownerAction('delegate',newer);check(r.status===200&&r.data.turn.chat.live_status==='declined'&&r.data.turn.state==='agent_ready','actual owner Decline resumes canonical Agent work for the new live request');
  r=await dispatch(newer);check(r.status===200&&modelCalls()===1,'declined live request falls back through the real public Agent job');
  r=await poll(cid);check(r.status===200&&projection(r.data,'live','declined')&&r.data.messages.filter(m=>m.sender_type==='owner').length===1&&r.data.messages.filter(m=>m.sender_type==='agent').length===1&&r.data.messages.filter(m=>m.sender_type==='visitor').length===3,'live acceptance, ending, requesting again and declining retain one continuous transcript');
  check(rows('chat_messages').some(m=>m.message==='PRIVATE_CHAT_SECRET')&&rows('chat_messages').some(m=>m.message==='FOREIGN_CHAT_SECRET')&&!rows('profile_agent_messages').some(m=>/PRIVATE_CHAT_SECRET|FOREIGN_CHAT_SECRET/.test(m.message)),'private Chat messages never enter the visitor transcript or disappear during switching');

  await reset();await presence();r=await send('live','Owner accepts then ends without a human answer');const ownerEnded=r.data;
  await ownerAction('claim',ownerEnded);invoke('age');
  r=await send('live','A follow-up during the accepted live chat',ownerEnded.conversation_id,key(),visitor,{continue_live:true});const acceptedFollow=r.data;
  check(r.status===200&&acceptedFollow.conversation_id===ownerEnded.conversation_id&&projection(acceptedFollow,'live','accepted')&&acceptedFollow.handoff.state==='owner_claimed'&&acceptedFollow.handoff.pickup_deadline_ms===null&&modelCalls()===0,'accepted live continuation stays with the owner without restarting pickup or running inference');
  r=await ownerAction('delegate',acceptedFollow);
  check(r.status===200&&r.data.turn.chat.live_status==='ended'&&r.data.turn.state==='agent_ready','owner End live chat after Accept releases unanswered Agent work');
  await dispatch(ownerEnded);await dispatch(acceptedFollow);check(modelCalls()===2,'owner-ended unanswered messages each receive one durable Agent fallback in turn order');
  invoke('age');const lateKey=key(),lateText='Send from a visitor tab that has not yet seen the owner end';
  r=await send('live',lateText,ownerEnded.conversation_id,lateKey,visitor,{continue_live:true});const late=r.data;
  check(r.status===200&&late.conversation_id===ownerEnded.conversation_id&&late.chat.live_status==='ended'&&!late.chat.can_end_live_chat&&late.handoff.state==='agent_ready'&&modelCalls()===2,'stale live continuation after owner End saves the message for Agent without ringing again');
  r=await chat('POST',{action:'doorbell_list',csrf_token:csrf,chat_conversation_id:11,chat_agent_id:7});
  check(r.status===200&&r.data.turns.length===0&&rows('profile_agent_handoff_deliveries_pa3').length===0,'stale continuation publishes no new owner card');
  r=await send('live',lateText,ownerEnded.conversation_id,lateKey,visitor,{continue_live:true});
  check(r.status===200&&r.data.duplicate&&r.data.handoff.turn_id===late.handoff.turn_id,'stale live continuation retries the exact saved turn');
  r=await send('live',lateText,ownerEnded.conversation_id,lateKey);
  check(r.status===409&&r.data.code==='HANDOFF_REPLAY_CONFLICT','retry cannot promote a stale live continuation to a fresh owner request');
  for(const [mode,continuation] of [['live','true'],['agent',true]]){
    r=await send(mode,'Invalid continuation must not save a message',ownerEnded.conversation_id,key(),visitor,{continue_live:continuation});
    check(r.status===422&&r.data.code==='HANDOFF_INVALID'&&safe(r.raw),'HTTP rejects invalid continue_live '+JSON.stringify([mode,continuation]));
  }
  invoke('age');r=await send('live','Visitor explicitly requests a new live chat',ownerEnded.conversation_id);
  check(r.status===200&&r.data.conversation_id===ownerEnded.conversation_id&&projection(r.data,'live','waiting')&&r.data.handoff.state==='awaiting_delivery','a fresh explicit live selection can ring again after the stale continuation');
  await reset();await presence();r=await send('live','Visitor cancels before the owner accepts');const waiting=r.data;r=await profile('POST',endFields(waiting));
  check(r.status===200&&projection(r.data,'agent','ended')&&r.data.handoff.state==='agent_ready','visitor may end a pending live request before owner acceptance');
  const pendingEnd=endFields(waiting); // This changed revision is intentionally not the original lost receipt.
  r=await profile('POST',pendingEnd);check(r.status===409&&r.data.code==='HANDOFF_CHANGED','end retry remains bound to the originally observed revision');

  await reset();await presence();r=await send('live','Owner goes Offline after accepting');const offline=r.data;
  await ownerAction('claim',offline);invoke('settings','offline',1);const beforeOfflineRead=snapshot();r=await poll(offline.conversation_id);
  check(r.status===200&&projection(r.data,'live','accepted',false)&&JSON.stringify(snapshot())===JSON.stringify(beforeOfflineRead)&&modelCalls()===0,'Offline public poll reports unavailability without mutating an accepted live chat');
  r=await profile('POST',endFields(offline));check(r.status===200&&projection(r.data,'agent','ended',false),'visitor can cleanly end an accepted chat after owner goes Offline');

  await reset();await presence();r=await send('live','Timeout is maintained only by POST dispatch');const timed=r.data;
  now+=5000;const timeoutBefore=snapshot();r=await poll(timed.conversation_id);
  check(r.status===200&&r.data.chat.live_status==='waiting'&&JSON.stringify(snapshot())===JSON.stringify(timeoutBefore)&&modelCalls()===0,'late GET poll never advances a missed delivery grace or starts inference');
  r=await dispatch(timed);check(r.status===200&&modelCalls()===1,'exact POST dispatch maintains expired delivery grace and executes fallback');
  r=await poll(timed.conversation_id);check(r.status===200&&r.data.chat.live_status==='expired'&&r.data.messages.filter(m=>m.sender_type==='agent').length===1,'timed out live request reports durable expired status with one Agent answer');

  await reset();await presence();
  for(const missing of ['settings','handoff']){
    invoke('hide_'+missing);
    try{
      const missingBefore=snapshot(),calls=modelCalls();r=await profile('GET',{...publicBase,action:'state'});
      check(r.status===200&&r.data.chat.live_text_available===false&&safe(r.raw),'public state safely projects missing '+missing+' storage');
      check(JSON.stringify(snapshot())===JSON.stringify(missingBefore)&&modelCalls()===calls,'public read never installs or repairs missing '+missing+' schema');
    }finally{invoke('restore_'+missing);}
  }
  const beforeFault=snapshot();r=await send('live','Admission rollback must be safe',0,key(),visitor,{});const existing=r.data;
  invoke('age');const faultBefore=snapshot();r=await profile('POST',{...publicBase,action:'message',chat_mode:'live',client_request_id:key(),message:'Fail only this complete new admission',conversation_id:existing.conversation_id},visitor,{'X-Fixture-Fault':'turn'});
  check(r.status===500&&r.data.ok===false&&r.data.code==='PROFILE_AGENT_REQUEST_FAILED'&&safe(r.raw),'production admission SQL failure returns a safe unsuccessful HTTP response');
  check(JSON.stringify(snapshot())===JSON.stringify(faultBefore)&&modelCalls()===0,'failed live admission rolls back the message, attention, turn, cards and job atomically');
  check(beforeFault.rows.profile_agent_messages.length===0,'fault scenario begins with a clean independent SQL fixture');

  await reset();await presence();invoke('attachments');
  const image=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLbtAAAAABJRU5ErkJggg==','base64');
  const fileCaption='Caption for the profile owner alone';
  async function upload(cid,requestId=key(),name='photo.png',contents=image,fields={}){
    const form=new FormData();
    form.set('attachment_payload',JSON.stringify({...publicBase,action:'message',conversation_id:cid,client_request_id:requestId,message:fileCaption,chat_mode:'live',continue_live:true,...fields}));
    form.set('profile_attachment',new Blob([contents],{type:'image/png'}),name);
    const wire=headers(visitor);delete wire['Content-Type'];
    const response=await fetch(base+'/api/profile-agent.php',{method:'POST',headers:wire,body:form});
    return {status:response.status,data:await response.json()};
  }
  const emptyState=await profile('GET',{...publicBase,action:'state'});
  check(emptyState.data.attachment_max_bytes===2097152,'installed attachment storage reports its exact upload limit');
  r=await upload(0);
  check(r.status===409&&r.data.code==='PROFILE_ATTACHMENT_OWNER_REQUIRED'&&rows('profile_agent_messages').length===0,'a file cannot create a public Profile Agent conversation');
  const initial=(await send('live','Accept this live chat before receiving a file')).data, attachmentCid=initial.conversation_id;
  invoke('age');r=await upload(attachmentCid);
  check(r.status===409&&r.data.code==='PROFILE_ATTACHMENT_OWNER_REQUIRED','a waiting human chat cannot receive an attachment yet');
  await ownerAction('claim',initial);
  r=await upload(attachmentCid,key(),'unsafe.html',Buffer.from('<script>alert(1)</script>'));
  check(r.status===422&&r.data.code==='PROFILE_ATTACHMENT_INVALID','an executable document is rejected regardless of browser MIME');
  r=await upload(attachmentCid,key(),'pretending.png',Buffer.from('<script>alert(1)</script>'));
  check(r.status===422&&r.data.code==='PROFILE_ATTACHMENT_INVALID','an image extension cannot bypass content validation');
  const attachmentKey=key();r=await upload(attachmentCid,attachmentKey);const attachment=r.data;
  check(r.status===200&&attachment.handoff.state==='owner_claimed'&&attachment.attachment?.name==='photo.png'&&modelCalls()===0,'a validated attachment saves into the accepted owner turn');
  r=await upload(attachmentCid,attachmentKey);
  check(r.status===200&&r.data.duplicate&&r.data.visitor_message_id===attachment.visitor_message_id,'retry preserves the exact attachment without duplicate messages');
  r=await upload(attachmentCid,attachmentKey,'renamed.png');
  check(r.status===409&&r.data.code==='HANDOFF_REPLAY_CONFLICT','a retry cannot replace the saved attachment metadata');
  const download={action:'attachment',username:publicBase.username,conversation_namespace:publicBase.conversation_namespace,conversation_id:attachmentCid,message_id:attachment.visitor_message_id};
  const transfer=(params,who=visitor)=>fetch(base+'/api/profile-agent.php?'+new URLSearchParams(params),{headers:headers(who)});
  const preview=await transfer({...download,preview:1});
  check(preview.ok&&preview.headers.get('content-type')==='image/png'&&preview.headers.get('cache-control')==='private, no-store'&&Buffer.from(await preview.arrayBuffer()).equals(image),'only the authorized native visitor can preview the original image');
  const ownerDownload={action:'owner_attachment',conversation_id:attachmentCid,message_id:attachment.visitor_message_id};
  const file=await transfer(ownerDownload,owner);
  check(file.ok&&file.headers.get('content-type')==='application/octet-stream'&&file.headers.get('content-disposition')?.includes('attachment')&&Buffer.from(await file.arrayBuffer()).equals(image),'the authorized owner downloads the same bytes as a file');
  check(!(await transfer(ownerDownload,otherOwner)).ok&&!(await transfer({...download,conversation_namespace:foreignBase.conversation_namespace},foreign)).ok
    &&!(await transfer({...download,conversation_id:attachmentCid+10})).ok,'another profile owner, visitor, or conversation cannot download the attachment');
  const received=await profile('POST',{action:'conversation_messages',conversation_id:attachmentCid,csrf_token:csrf},owner);
  check(received.data.messages.find(m=>m.id===attachment.visitor_message_id)?.attachment?.name==='photo.png'
    &&(await poll(attachmentCid)).data.messages.find(m=>m.id===attachment.visitor_message_id)?.message===fileCaption
    &&!rows('profile_agent_messages').some(m=>m.message.includes(fileCaption)),'owner and visitor recover the caption and attachment metadata from the same private thread');
  const cards=await chat('POST',{action:'doorbell_list',csrf_token:csrf,chat_conversation_id:11,chat_agent_id:7});
  check(cards.data.turns[0]?.visitor_attachment?.name==='photo.png','owner Main Chat card carries its private attachment link metadata');
  r=await profile('POST',endFields(attachment));
  check(r.status===200&&turn(attachment).state==='cancelled','ending live chat never delegates the private attachment to the public Agent');
  await dispatch(attachment);
  check(modelCalls()===0,'private attachment has no public inference job after the owner leaves');
  invoke('age');r=await upload(attachmentCid);
  check(r.status===409&&r.data.code==='PROFILE_ATTACHMENT_OWNER_REQUIRED','stale live session cannot fall back to a public attachment send');
  console.log(`PROFILE_LIVE_CHAT_API_PA3D=PASS (${checks} checks; ${driver})`);
}finally{
  if(child.exitCode===null&&child.signalCode===null&&child.pid){
    const stopped=new Promise(resolve=>child.once('exit',resolve));child.kill('SIGTERM');await stopped;
  }
  invoke('cleanup');fs.rmSync(tmp,{recursive:true,force:true});
}
