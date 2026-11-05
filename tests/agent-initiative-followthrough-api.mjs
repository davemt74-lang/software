import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {request as httpRequest} from 'node:http';
import {spawn} from 'node:child_process';

// Reuse the real three-controller capability fixture. Only SQLite infrastructure
// and provider transport are adapted; no Chat or Initiative implementation is
// replaced, and every source post is made by the actual leased worker.
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-followthrough-chat-'));
fs.cpSync('includes',path.join(tmp,'includes'),{recursive:true});
fs.mkdirSync(path.join(tmp,'api'));
for(const name of ['chat.php','chat-v236.php','chat-stream-v121.php'])fs.copyFileSync('api/'+name,path.join(tmp,'api',name));
for(const name of ['agent-runtime-routing-v420.php','ai-settings.php'])fs.writeFileSync(path.join(tmp,'includes',name),'<?php // Controlled infrastructure supplied by the fixture bootstrap.');
const infrastructureFixtures=['phone-agent-schema-v1.php','workflow-agent-schema-v1.php','record-agent-schema-v1.php','media-agent-schema-v1.php','app-agent-remote-v1.php','system-app-agent-schema-v1.php','app-operation-schema-v1.php'];
for(const name of infrastructureFixtures)fs.copyFileSync('tests/fixtures/'+name,path.join(tmp,'includes',name));
fs.copyFileSync('tests/fixtures/initiative-followthrough-chat.php',path.join(tmp,'includes/initiative-followthrough-chat-fixture.php'));
let bootstrap=fs.readFileSync('tests/fixtures/capability-chat-bootstrap.php','utf8');
// Provider function names alone change so the extra fixture can intercept the
// normal main model and Initiative's governed intent model independently.
for(const name of ['chat_remote_answer','ai_generate_chat_response','calendar_schedule_awareness_snapshot_v1330','agent_runtime_v125_idempotency_claim','agent_runtime_v125_idempotency_complete'])bootstrap=bootstrap.replace('function '+name+'(', 'function capability_fixture_'+name+'(');
// Instrument the infrastructure transaction boundary only. The production
// preflight, owner/Chat locks and candidate recheck execute unchanged.
assert.ok(bootstrap.includes('class TranscriptFixturePDO extends PDO {'));
bootstrap=bootstrap.replace('class TranscriptFixturePDO extends PDO {',`class TranscriptFixturePDO extends PDO {
public function beginTransaction(): bool {if(function_exists('followthrough_fixture_before_user_gate_v1'))followthrough_fixture_before_user_gate_v1();return parent::beginTransaction();}
`);
// Preserve the actual record format and file locks so production retry/CAS code
// exercises its real primitives, rather than a test replacement of that code.
const runtimeSource=fs.readFileSync('includes/agent-runtime-v125.php','utf8');
const primitiveStart=runtimeSource.indexOf('function agent_runtime_v125_idempotency_path('),primitiveEnd=runtimeSource.indexOf('function agent_background_v125_jobs_dir(',primitiveStart);
assert.ok(primitiveStart>=0&&primitiveEnd>primitiveStart,'Canonical idempotency primitives must be present.');
bootstrap+="\nfunction agent_runtime_v125_root(): string {return dirname(__DIR__).'/runtime-fixture';}\nfunction agent_runtime_v125_dir(string $bucket): string { $dir=agent_runtime_v125_root().'/'.preg_replace('/[^a-z0-9_-]/i','',$bucket);if(!is_dir($dir))mkdir($dir,0700,true);return $dir;}\n"+runtimeSource.slice(primitiveStart,primitiveEnd);
bootstrap+="\nrequire_once __DIR__.'/initiative-followthrough-chat-fixture.php';\n";
fs.writeFileSync(path.join(tmp,'includes/bootstrap.php'),bootstrap);
fs.writeFileSync(path.join(tmp,'includes/ai-stream-v121.php'),`<?php
function ai_v121_stream_chat_response($query,$history,$context,$user,$onDelta){
 $answer=chat_remote_answer($query,$history,$context,$user);$onDelta($answer);
 return ['ok'=>true,'answer'=>$answer,'usage'=>[]];
}`);
fs.writeFileSync(path.join(tmp,'followthrough-control.php'),`<?php require __DIR__.'/includes/bootstrap.php';followthrough_fixture_control_v1();`);
async function availablePort(){
 const reserve=net.createServer();await new Promise(resolve=>reserve.listen(0,'127.0.0.1',resolve));
 const port=reserve.address().port;await new Promise(resolve=>reserve.close(resolve));return port;
}
const port=await availablePort();
const child=spawn(process.env.VP3_PHP_BINARY||'php',['-S',`127.0.0.1:${port}`,'-t',tmp],{stdio:['ignore','ignore','pipe'],detached:true,env:{...process.env,PHP_CLI_SERVER_WORKERS:'4'}});
const crashWorkers=new Set();
let logs='';child.stderr.on('data',chunk=>logs+=chunk);
const base=`http://127.0.0.1:${port}`;
const commonHeaders={'Content-Type':'application/json','Connection':'close','X-Fixture-Brain-Ready':'1'};
const endpoints=['chat.php','chat-v236.php','chat-stream-v121.php'];
const named={conversation_id:11,agent_context:{user_agent_id:7}};
let checks=0;
function check(ok,label,details=''){assert.ok(ok,label+(details?'\n'+details:''));checks++;console.log('PASS '+label);}
function context(message){return JSON.parse(message.context_json||'{}');}
function root(state){return state.messages.find(message=>context(message).source==='agent_initiative');}
function association(message){const marker=context(message).initiative_work?.followthrough;assert.ok(marker,'The actual worker post must expose its strict reply association.');return {message_id:Number(message.id),reply_ref:marker.reply_ref,revision:marker.revision};}
function calls(state,kind){return state.calls.filter(call=>call.kind===kind).map(call=>JSON.parse(call.payload));}
async function control(action='state',query={}){
 const result=await fetch(base+'/followthrough-control.php?'+new URLSearchParams({action,...query}),{headers:commonHeaders});
 const body=await result.text();let state;try{state=JSON.parse(body);}catch{throw new Error(body+'\n'+logs.slice(-4000));}
 assert.ok(result.ok&&state.ok,'Fixture '+action+': '+body+'\n'+logs.slice(-3000));return state;
}
// These requests are reserved for the intentional worker-kill probes. A fresh
// socket cannot reuse the connection that was interrupted by SIGKILL.
function freshHttp(url,{method='GET',headers=commonHeaders,body,signal}={}){
 return new Promise((resolve,reject)=>{
  const request=httpRequest(url,{method,headers,signal,agent:false},response=>{
   let body='';response.setEncoding('utf8');response.on('data',chunk=>body+=chunk);
   response.once('error',reject);response.once('end',()=>resolve({result:{status:response.statusCode,ok:response.statusCode>=200&&response.statusCode<300},body}));
  });
  request.once('error',reject);request.end(body);
 });
}
async function freshControl(origin=base){
 const {result,body}=await freshHttp(origin+'/followthrough-control.php?action=state',{signal:AbortSignal.timeout(10000)});
 const state=JSON.parse(body);
 assert.ok(result.status===200&&state.ok,'A surviving PHP worker must serve the read-only state probe after SIGKILL.');return state;
}
async function startCrashWorker(){
 const port=await availablePort(),env={...process.env};delete env.PHP_CLI_SERVER_WORKERS;
 // The CLI supervisor can serve requests too; killing it also kills its forked
 // workers. Isolate the doomed request while sharing the real DB and cache.
 const worker=spawn(process.env.VP3_PHP_BINARY||'php',['-S',`127.0.0.1:${port}`,'-t',tmp],{stdio:['ignore','ignore','pipe'],env});
 crashWorkers.add(worker);
 const stopped=new Promise(resolve=>worker.once('exit',()=>{crashWorkers.delete(worker);resolve();}));
 const listening=new Promise((resolve,reject)=>{
  const timer=setTimeout(()=>reject(new Error('The dedicated crash worker did not announce its listening server.')),10000);let startup='';
  worker.once('error',error=>{clearTimeout(timer);reject(error);});
  worker.once('exit',()=>{clearTimeout(timer);reject(new Error('The dedicated crash worker exited before readiness.'));});
  worker.stderr.on('data',chunk=>{logs+=chunk;startup+=chunk;if(startup.includes('Development Server')&&startup.includes('started')){clearTimeout(timer);resolve();}});
 });
 await listening;const origin=`http://127.0.0.1:${port}`;
 return {worker,origin,stopped,state:await freshControl(origin)};
}
async function post(endpoint,message,extra={},headers={},signal,freshConnection=false,origin=base){
 const route=endpoint==='chat-v236.php'?endpoint+'?agent='+String(extra.agent_context?.user_agent_id??7):endpoint;
 const options={method:'POST',signal,headers:{...commonHeaders,...headers},body:JSON.stringify({action:'send',message,csrf_token:'fixture',knowledge_scope:'off',...named,...extra})};
 let result,body;
 if(freshConnection)({result,body}=await freshHttp(origin+'/api/'+route,options));
 else{result=await fetch(origin+'/api/'+route,options);body=await result.text();}
 let data,events=[];
 try{events=body.trim().split('\n').filter(Boolean).map(line=>JSON.parse(line));data=endpoint.includes('stream')?(events.find(event=>event.type==='done')?.data??events.find(event=>event.type==='error')):events[0];}
 catch{throw new Error(body+'\n'+logs.slice(-4000));}
 return {result,data,body,events};
}
function noDomainWrite(before,after,label){
 check(after.reminders===before.reminders&&after.task.memory_text===before.task.memory_text&&after.task.metadata_json===before.task.metadata_json,label);
}
async function barrier(ready,label){const deadline=Date.now()+10000;while(!ready()){assert.ok(Date.now()<deadline,label);await new Promise(resolve=>setTimeout(resolve,10));}}
function privateProcessingRecord(messageId){
 const dir=path.join(tmp,'runtime-fixture/idempotency');
 const matches=fs.readdirSync(dir).filter(name=>name.endsWith('.json')).map(name=>{const file=path.join(dir,name);return {file,row:JSON.parse(fs.readFileSync(file,'utf8'))};})
  .filter(({row})=>row.scope==='initiative-reply:user-1:agent-7:chat-11'&&row.status==='processing'&&row.initiative_request?.initiative_reply?.message_id===messageId);
 assert.equal(matches.length,1,'Exactly one real canonical private request must be processing at the provider barrier.');return matches[0];
}
try{
 for(let attempt=0;attempt<80;attempt++){try{await control();break;}catch(error){if(attempt===79)throw error;await new Promise(resolve=>setTimeout(resolve,50));}}
 for(const endpoint of endpoints){
  const before=await control('seed'),source=root(before),ref=association(source);
  const response=await post(endpoint,'Help me think through that',{initiative_reply:ref});
  check(response.data?.ok===true&&response.data.answer.includes('verified launch task'),endpoint+' sends associated discussion through the normal main model',response.body);
  const outcome=response.data.initiative_followthrough;
  check(outcome?.owner_user_id===1&&outcome.agent_id===7&&outcome.conversation_id===11&&outcome.message_id===source.id&&outcome.reply_message_id===response.data.user_message_id,
   endpoint+' returns exact owner, Agent, Chat, original post and reply identities');
  const after=await control(),main=calls(after,'main_model');
  check(main.length===1&&main[0].owner===1&&main[0].context.response_kind==='followthrough_reply'&&main[0].context.request==='Help me think through that'&&
   main[0].context.agent?.name==='Longfellow'&&JSON.stringify(main[0].context).includes('product image')&&!JSON.stringify(main).includes('SECRET')&&calls(after,'ordinary_agent_chat').length===0,
   endpoint+' supplies verified private facts to the governed main-model reply without agent.chat tool authority');
  const saved=after.messages.find(message=>message.id===response.data.assistant_message_id);
  check(context(saved).initiative_followthrough?.message_id===source.id,endpoint+' saves the exact followthrough outcome with the natural answer');
  check((response.data.actions??[]).length===0&&!response.data.tool_receipt?.changes_applied,endpoint+' associated discussion grants no ordinary tool action');
  noDomainWrite(before,after,endpoint+' discussion leaves saved task and reminder domains unchanged');
 }

 for(const endpoint of endpoints){
  const before=await control('seed'),ref=association(root(before));
  const response=await post(endpoint,'yes',{initiative_reply:ref},{'X-Fixture-Followthrough-Intent':'none'});
  check(response.data?.ok===true&&response.data.initiative_followthrough?.continuation_run_id===0&&response.data.answer.includes('Which next step')&&calls(await control(),'main_model').length===1,
   endpoint+' keeps an explicitly associated bare yes on the main-model path when intent is unclear');
  noDomainWrite(before,await control(),endpoint+' unclear yes cannot execute a domain write');
 }

 for(const endpoint of endpoints){
  const seed=await control('seed'),source=root(seed),ref=association(source),settings=await control('participants_on');
  check(settings.participant_settings.enabled===true&&settings.participant_settings.self_participant_id===101,
   endpoint+' enables actual canonical participant settings before cached-response isolation');
  const speaker=id=>({user_agent_id:7,voice_speaker:{participant_id:id,source:'owner_selected_participant',authentication_authority:false}});
  const extra={initiative_reply:ref,agent_context:speaker(101),...(endpoint.includes('stream')?{idempotency_key:'participant-cache-'+source.id}:{})};
  const query='Discuss this owner-private proposal',first=await post(endpoint,query,extra);
  check(first.data?.ok===true,endpoint+' caches an actual owner response with the canonical self participant');
  const before=await control(),attack=await post(endpoint,query,{...extra,agent_context:speaker(102)}),after=await control();
  check(attack.data?.ok!==true&&(attack.result.status===403||attack.data?.status===403)&&!attack.body.includes('verified launch task')&&
   !attack.events.some(event=>event.type==='delta')&&after.messages.length===before.messages.length&&after.calls.length===before.calls.length,
   endpoint+' a non-self participant cannot borrow an owner cached response using the same account and valid proposal association',attack.body);
 }

 for(const endpoint of endpoints){
  for(const phase of ['intent','ack']){
   const seed=await control('seed'),source=root(seed),ref=association(source),query='Please prepare this private next step after recovery';
   const extra={initiative_reply:ref,...(endpoint.includes('stream')?{idempotency_key:'provider-recovery-'+phase+'-'+source.id}:{})};
   const failureHeaders={'X-Fixture-Followthrough-Intent':'advance',[phase==='intent'?'X-Fixture-Followthrough-Intent-Fail':'X-Fixture-Followthrough-Model-Fail']:'1'};
   const failed=await post(endpoint,query,extra,failureHeaders),afterFailure=await control();
   const originalTurn=afterFailure.messages.find(message=>message.role==='user'&&message.message===query);
   check(failed.data?.ok!==true&&originalTurn&&!failed.events.some(event=>event.type==='delta'),
    endpoint+' '+phase+' provider failure leaves one recoverable saved owner turn without answer deltas',failed.body);
   const healthy=await post(endpoint,query,extra,{'X-Fixture-Followthrough-Intent':'advance'}),afterHealthy=await control();
   const children=afterHealthy.runs.filter(run=>JSON.parse(run.goal||'{}').followthrough?.message_id===source.id);
   const decisions=afterHealthy.events.filter(event=>event.event_type==='initiative_owner_decision');
   const intentCalls=calls(afterHealthy,'initiative_model').filter(call=>call.protocol==='vp3.initiative-reply.v1');
   check(healthy.data?.ok===true&&healthy.data.user_message_id===originalTurn.id&&children.length===1&&decisions.length===1&&
    afterHealthy.messages.filter(message=>message.role==='user'&&message.message===query).length===1&&
    intentCalls.length===(phase==='intent'?2:1)&&calls(afterHealthy,'main_model').length===(phase==='intent'?1:2),
    endpoint+' the same healthy '+phase+' retry resumes the original turn and commits one decision and one private child',healthy.body);
   const repeated=await post(endpoint,query,extra,{'X-Fixture-Followthrough-Intent':'advance'}),afterRepeat=await control();
   check(repeated.data?.ok===true&&repeated.data.assistant_message_id===healthy.data.assistant_message_id&&afterRepeat.messages.length===afterHealthy.messages.length&&
    afterRepeat.runs.length===afterHealthy.runs.length&&afterRepeat.calls.length===afterHealthy.calls.length,
    endpoint+' completed '+phase+' recovery is thereafter replayed without another owner turn, model call or child');
  }
 }

 for(const [endpoint,implicit,phase] of [['chat.php',false,'ack'],['chat-v236.php',true,'ack'],['chat-stream-v121.php',false,'ack'],['chat.php',false,'intent']]){
  const seed=await control('seed'),source=root(seed),ref=association(source),query='Yes, advance that private preparation despite an interrupted response';
  const extra={...(implicit?{}:{initiative_reply:ref}),...(endpoint.includes('stream')?{idempotency_key:'stale-processing-'+source.id}:{})};
  const token='old-generation-'+source.id,gate=path.join(tmp,'provider-gate-'+token);let oldSettled=false;
  const old=post(endpoint,query,extra,{'X-Fixture-Followthrough-Intent':'advance','X-Fixture-Followthrough-Gate':token,'X-Fixture-Followthrough-Gate-Phase':phase}).finally(()=>{oldSettled=true;});old.catch(()=>{});
  let recovered,winner,winnerCache,record,winnerRecords;
  try{
   await barrier(()=>fs.existsSync(gate+'.entered'),endpoint+' original generation did not reach its real provider barrier');
   record=privateProcessingRecord(source.id);const original={...record.row};
   record.row.created_at=Math.floor(Date.now()/1000)-331;fs.writeFileSync(record.file,JSON.stringify(record.row));
   const aged=JSON.parse(fs.readFileSync(record.file,'utf8'));const before=await control();
   check(!oldSettled&&Object.keys(original).every(key=>key==='created_at'||JSON.stringify(aged[key])===JSON.stringify(original[key]))&&
    aged.created_at<Math.floor(Date.now()/1000)-330,
    endpoint+' '+(implicit?'implicit':'explicit')+' interrupted-process test ages only the real private processing record while its original provider remains held');
   recovered=await post(endpoint,query,extra,{'X-Fixture-Followthrough-Intent':'advance'},AbortSignal.timeout(10000));winner=await control();winnerCache=fs.readFileSync(record.file,'utf8');
   const cacheDir=path.dirname(record.file);
   winnerRecords=fs.readdirSync(cacheDir).filter(name=>name.endsWith('.json')).map(name=>{const file=path.join(cacheDir,name),raw=fs.readFileSync(file,'utf8');return {file,raw,row:JSON.parse(raw)};})
    .filter(({row})=>row.result?.assistant_message_id===recovered.data?.assistant_message_id);
   const savedOwner=before.messages.find(message=>message.role==='user'&&message.message===query);
   check(recovered.data?.ok===true&&!oldSettled&&recovered.data.user_message_id===savedOwner?.id&&
    winner.messages.filter(message=>message.role==='user'&&message.message===query).length===1&&
    winner.messages.filter(message=>context(message).initiative_followthrough?.message_id===source.id).length===1&&
    winner.events.filter(event=>event.event_type==='initiative_owner_decision').length===1&&
    winner.runs.filter(run=>JSON.parse(run.goal||'{}').followthrough?.message_id===source.id).length===1&&
    calls(winner,'initiative_model').filter(call=>call.protocol==='vp3.initiative-reply.v1').length===(phase==='intent'?2:1)&&calls(winner,'main_model').length===(phase==='intent'?1:2),
    endpoint+' manual stale-processing '+phase+' recovery reuses the original owner turn and commits one decision, child and replacement acknowledgement',recovered.body);
  }finally{fs.writeFileSync(gate+'.release','release');}
  const late=await old,afterLate=await control();
  check(late.data?.ok!==true&&!late.body.includes('verified launch task')&&!late.events.some(event=>event.type==='delta')&&
   afterLate.messages.length===winner.messages.length&&afterLate.calls.length===winner.calls.length&&
   afterLate.runs.length===winner.runs.length&&afterLate.events.length===winner.events.length&&fs.readFileSync(record.file,'utf8')===winnerCache&&
   winnerRecords.length>0&&winnerRecords.every(({file,raw})=>fs.readFileSync(file,'utf8')===raw),
   endpoint+' the late original processing generation cannot emit, save another answer or overwrite the recovered canonical cache',late.body);
  const replay=await post(endpoint,query,extra),afterReplay=await control();
  check(replay.data?.ok===true&&replay.data.assistant_message_id===recovered.data.assistant_message_id&&
   afterReplay.messages.length===afterLate.messages.length&&afterReplay.calls.length===afterLate.calls.length,
   endpoint+' the winning stale-process recovery remains the sole replayable answer after the old generation returns',replay.body);
 }

 for(const endpoint of endpoints){
  const seed=await control('seed'),source=root(seed),ref=association(source),query='Recover this exact private preparation concurrently';
  const extra={initiative_reply:ref,...(endpoint.includes('stream')?{idempotency_key:'concurrent-recovery-'+source.id}:{})};
  const failed=await post(endpoint,query,extra,{'X-Fixture-Followthrough-Intent':'advance','X-Fixture-Followthrough-Model-Fail':'1'}),afterFailure=await control();
  const ownerTurn=afterFailure.messages.find(message=>message.role==='user'&&message.message===query);
  check(failed.data?.ok!==true&&ownerTurn,endpoint+' prepares one real failed acknowledgement record for concurrent recovery');
  const token='retry-'+source.id,gate=path.join(tmp,'provider-gate-'+token);
  let heldSettled=false;
  const held=post(endpoint,query,extra,{'X-Fixture-Followthrough-Intent':'advance','X-Fixture-Followthrough-Gate':token}).finally(()=>{heldSettled=true;});held.catch(()=>{});
  try{
   await barrier(()=>fs.existsSync(gate+'.entered'),endpoint+' recovered claimant did not enter the transaction-free provider barrier');
   const busy=await post(endpoint,query,extra,{'X-Fixture-Followthrough-Intent':'advance'},AbortSignal.timeout(5000)),afterBusy=await control();
   check(busy.data?.ok!==true&&(busy.result.status===503||busy.data?.status===503)&&!busy.events.some(event=>event.type==='delta')&&
    afterBusy.calls.length===afterFailure.calls.length+2&&afterBusy.messages.length===afterFailure.messages.length&&afterBusy.runs.length===afterFailure.runs.length&&
    afterBusy.events.length===afterFailure.events.length&&!fs.existsSync(gate+'.release')&&!heldSettled,
    endpoint+' a second real HTTP worker rejects concurrent exact recovery before another model call or write while the CAS claimant is held',busy.body);
  }finally{fs.writeFileSync(gate+'.release','release');}
  const recovered=await held,after=await control();
  const children=after.runs.filter(run=>JSON.parse(run.goal||'{}').followthrough?.message_id===source.id);
  check(recovered.data?.ok===true&&recovered.data.user_message_id===ownerTurn.id&&children.length===1&&
   after.events.filter(event=>event.event_type==='initiative_owner_decision').length===1&&
   after.messages.filter(message=>message.role==='user'&&message.message===query).length===1&&
   after.messages.filter(message=>context(message).initiative_followthrough?.message_id===source.id).length===1&&
   calls(after,'main_model').length===2&&calls(after,'initiative_model').filter(call=>call.protocol==='vp3.initiative-reply.v1').length===1,
   endpoint+' the single recovered claimant saves one original owner turn, decision, child and assistant acknowledgement',recovered.body);
  const replay=await post(endpoint,query,extra),afterReplay=await control();
  check(replay.data?.ok===true&&replay.data.assistant_message_id===recovered.data.assistant_message_id&&afterReplay.messages.length===after.messages.length&&afterReplay.calls.length===after.calls.length,
   endpoint+' concurrent recovery thereafter replays normally without repeating its model');
 }

 for(const endpoint of endpoints){
  for(const change of ['enable','self','deactivate']){
   const seed=await control('seed'),source=root(seed),ref=association(source);
   if(change!=='enable')await control('participants_on');
   const speaker=change==='enable'?102:101;
   const response=await post(endpoint,'Please advance this private proposal',{initiative_reply:ref,agent_context:{user_agent_id:7,
    voice_speaker:{participant_id:speaker,source:'owner_selected_participant',authentication_authority:false}}},
    {'X-Fixture-Followthrough-Intent':'advance','X-Fixture-Followthrough-Participant-Change':change});
   const after=await control();
   check(response.data?.ok!==true&&(response.result.status===403||response.data?.status===403)&&!response.events.some(event=>event.type==='delta')&&
    context(root(after)).initiative_work.followthrough.state==='open'&&after.runs.length===seed.runs.length&&
    after.events.filter(event=>event.event_type==='initiative_owner_decision').length===0&&calls(after,'main_model').length===0&&
    calls(after,'initiative_model').filter(call=>call.protocol==='vp3.initiative-reply.v1').length===1,
    endpoint+' canonical participant '+change+' during intent inference blocks the private decision before any child or acknowledgement',response.body);
   noDomainWrite(seed,after,endpoint+' participant '+change+' during inference grants no task or reminder authority');
  }
 }

 for(const endpoint of endpoints){
  const seed=await control('seed'),source=root(seed),ref=association(source);
  const extra={initiative_reply:ref,...(endpoint.includes('stream')?{idempotency_key:'whitespace-reply-'+source.id}:{})};
  const first=await post(endpoint,'  Tell me more  ',extra),beforeRetry=await control(),retry=await post(endpoint,'  Tell me more  ',extra),afterRetry=await control();
  check(first.data?.ok===true&&retry.data?.ok===true&&retry.data.assistant_message_id===first.data.assistant_message_id&&
   beforeRetry.messages.find(message=>message.id===first.data.user_message_id)?.message==='Tell me more'&&
   afterRetry.messages.length===beforeRetry.messages.length&&afterRetry.calls.length===beforeRetry.calls.length,
   endpoint+' exact explicit retries preserve canonical leading and trailing whitespace normalization',retry.body);
 }

 for(const endpoint of endpoints){
  for(const [intent,state] of [['defer','deferred'],['decline','declined'],['resolve','owner_resolved']]){
   const before=await control('seed'),source=root(before),ref=association(source);
   const response=await post(endpoint,'My decision about that proposal is '+intent,{initiative_reply:ref},{'X-Fixture-Followthrough-Intent':intent}),after=await control();
   const decision=after.events.filter(event=>event.event_type==='initiative_owner_decision');
   check(response.data?.ok===true&&response.data.initiative_followthrough?.state===state&&response.data.initiative_followthrough?.continuation_run_id===0&&
    context(root(after)).initiative_work.followthrough.state===state&&after.runs.length===before.runs.length&&decision.length===1&&JSON.parse(decision[0].metadata_json).intent===intent,
    endpoint+' '+intent+' persists one exact owner decision without scheduling extra work');
   noDomainWrite(before,after,endpoint+' '+intent+' does not change saved task status or reminder state');
  }
 }

 for(const endpoint of endpoints){
  const seeded=await control('seed'),ref=association(root(seeded));
  const pending=await post(endpoint,'Add take a shower to my unrelated prep list',{}, {'X-Fixture-Followthrough-Intent':'none'});
  check(pending.data?.tool_receipt?.status==='clarification_required',endpoint+' prepares an actual unrelated list approval before the associated reply',pending.body);
  const before=await control();
  check(before.list_actions.some(action=>action.status==='prepared'),endpoint+' has a real persisted list confirmation waiting');
  const response=await post(endpoint,'yes',{initiative_reply:ref},{'X-Fixture-Followthrough-Intent':'none','X-Fixture-Participant-Owner-Plan':JSON.stringify({operation:'task_list',ids:['tasks.lists'],action:'confirm'})});
  const after=await control();
  check(response.data?.ok===true&&response.data.initiative_followthrough&&after.lists.length===before.lists.length&&
   JSON.stringify(after.list_actions)===JSON.stringify(before.list_actions),endpoint+' explicitly associated yes cannot consume an unrelated pending approval');
  noDomainWrite(before,after,endpoint+' associated yes leaves unrelated task and reminder domains unchanged');
 }

 for(const endpoint of endpoints){
  const seed=await control('seed'),source=root(seed),ref=association(source);
  const extra={initiative_reply:ref,...(endpoint.includes('stream')?{idempotency_key:'followthrough-lost-response-'+source.id}:{})};
  const headers={'X-Fixture-Followthrough-Intent':'advance'};
  const first=await post(endpoint,'Please prepare the next private step',extra,headers),afterFirst=await control();
  check(first.data?.ok===true&&Number(first.data.initiative_followthrough?.continuation_run_id)>0,
   endpoint+' explicit advance queues a private continuation through the canonical workflow');
  const continuationId=Number(first.data.initiative_followthrough.continuation_run_id);
  const run=afterFirst.runs.find(run=>Number(run.id)===continuationId);
  check(run?.source_kind==='agent_initiative'&&run.capability_key==='initiative.prepare'&&run.status==='approved'&&run.agent_id===7,
   endpoint+' uses the existing Initiative queue with the exact named Agent');
  const goal=JSON.parse(run.goal);
  check(goal.tool==='work.prepare_private'&&goal.followthrough?.message_id===source.id&&
   goal.followthrough.root_run_id===context(source).initiative_run_id&&goal.followthrough.reply_message_id===first.data.user_message_id,
   endpoint+' queued private work binds the original root run, root post and actual owner reply');
  const replay=await post(endpoint,'Please prepare the next private step',extra,headers),afterReplay=await control();
  check(replay.data?.ok===true&&replay.data.assistant_message_id===first.data.assistant_message_id&&
   replay.data.initiative_followthrough?.continuation_run_id===continuationId&&afterReplay.messages.length===afterFirst.messages.length&&
   afterReplay.runs.length===afterFirst.runs.length&&afterReplay.events.length===afterFirst.events.length&&afterReplay.calls.length===afterFirst.calls.length,
   endpoint+' a lost-response retry returns the saved result without a duplicate reply, decision, queue entry or model call');
  const finished=await control('execute',{id:String(continuationId)});
  check(finished.execution_result?.ok===true,endpoint+' the real leased worker executes its queued private continuation');
  const action=finished.actions.find(action=>Number(action.run_id)===continuationId),receipt=finished.receipts.find(receipt=>Number(receipt.run_id)===continuationId);
  check(action?.status==='completed'&&receipt?.status==='completed'&&action.result_json===receipt.result_json,
   endpoint+' continuation completion has the same actual verified result in its canonical action and receipt');
  const actual=JSON.parse(action.result_json),delivered=finished.messages.find(message=>Number(message.id)===Number(actual.initiative_message_id));
  check(actual.initiative_delivery==='delivered'&&delivered?.conversation_id===11&&delivered.message.includes('Verified continuation'),
   endpoint+' ultimately delivers the actual verified continuation into the original ordinary Chat');
  check(context(delivered).initiative_followthrough?.message_id===source.id&&context(delivered).initiative_followthrough?.run_id===context(source).initiative_run_id&&
   context(delivered).initiative_followthrough?.state==='completed'&&context(root(finished)).initiative_work?.followthrough?.state==='completed',
   endpoint+' delivered continuation retains its exact original root post and root run association');
  noDomainWrite(seed,finished,endpoint+' private continuation does not mark the task complete or create reminders');
  const alteredReplay=await post(endpoint,'Please prepare the next private step',{...extra,initiative_reply:{...ref,reply_ref:'e'.repeat(64)}},headers);
  check(alteredReplay.data?.ok!==true&&!alteredReplay.body.includes('verified launch task'),endpoint+' a cached response cannot be borrowed with a forged reply reference');
  const otherChatReplay=await post(endpoint,'Please prepare the next private step',{...extra,conversation_id:14},headers);
  check(otherChatReplay.data?.ok!==true&&!otherChatReplay.body.includes('verified launch task'),endpoint+' a cached response cannot be replayed into another ordinary Chat');
  const wrongQuery=await post(endpoint,'A different decision',{...extra,...(endpoint.includes('stream')?{idempotency_key:'followthrough-other-query-'+source.id}:{})},headers);
  check(wrongQuery.data?.ok!==true,endpoint+' an already consumed revision cannot authorize a different reply');
 }

 for(const endpoint of endpoints){
  for(const action of ['source_change','source_revoke','state_change']){
   const seed=await control('seed'),source=root(seed),ref=association(source);
   const extra={initiative_reply:ref,...(endpoint.includes('stream')?{idempotency_key:'followthrough-replay-guard-'+source.id}:{})};
   const first=await post(endpoint,'Discuss this exact proposal',extra);check(first.data?.ok===true,endpoint+' saves a reply before replay guard '+action);
   await control(action);const before=await control(),replay=await post(endpoint,'Discuss this exact proposal',extra),after=await control();
   check(replay.data?.ok!==true&&!replay.body.includes('verified launch task')&&!replay.events.some(event=>event.type==='delta')&&
    after.messages.length===before.messages.length&&after.calls.length===before.calls.length,
    endpoint+' cached replies recheck current '+action+' and expose no old text or duplicate turn');
  }
 }

 for(const endpoint of endpoints){
  const seed=await control('seed'),source=root(seed),ref=association(source);
  const query='Advance this private preparation while I review it',extra={initiative_reply:ref,...(endpoint.includes('stream')?{idempotency_key:'complete-during-ack-'+source.id}:{})};
  const headers={'X-Fixture-Followthrough-Intent':'advance','X-Fixture-Followthrough-Complete':'1'};
  const response=await post(endpoint,query,extra,headers),completed=await control();
  const reasoning=calls(completed,'main_model');
  check(response.data?.ok===true&&response.data.initiative_followthrough?.state==='completed'&&response.data.answer.includes('verified private continuation is complete')&&
   !response.body.includes('Let us work through')&&reasoning.length===2&&reasoning[0].context.verified_followthrough.proposal.state==='queued'&&
   reasoning[1].context.verified_followthrough.proposal.state==='completed'&&reasoning[1].context.verified_followthrough.verified_private_completion===true&&
   reasoning[1].context.verified_followthrough.private_completion?.draft.includes('Verified continuation completed during'),
   endpoint+' regenerates the queued acknowledgement once from the actual verified child completion',response.body);
  const children=completed.runs.filter(run=>JSON.parse(run.goal||'{}').followthrough?.message_id===source.id);
  check(children.length===1&&children[0].status==='completed'&&context(root(completed)).initiative_work.followthrough.state==='completed',
   endpoint+' the inference race retains exactly one actually completed canonical child');
  const retry=await post(endpoint,query,extra,headers),afterRetry=await control();
  check(retry.data?.ok===true&&retry.data.assistant_message_id===response.data.assistant_message_id&&afterRetry.runs.length===completed.runs.length&&afterRetry.calls.length===completed.calls.length&&afterRetry.messages.length===completed.messages.length,
   endpoint+' retrying the refreshed acknowledgement returns its saved completed answer without another child or inference');
  const delivered=await control('deliver'),action=delivered.actions.find(action=>Number(action.run_id)===Number(children[0].id)),receipt=delivered.receipts.find(receipt=>Number(receipt.run_id)===Number(children[0].id));
  const result=JSON.parse(action.result_json),message=delivered.messages.find(message=>Number(message.id)===Number(result.initiative_message_id));
  check(delivered.delivered===true&&result.initiative_delivery==='delivered'&&action.result_json===receipt.result_json&&message?.conversation_id===11&&
   message.message.includes('Verified continuation completed during')&&context(message).initiative_followthrough?.message_id===source.id&&
   context(message).initiative_followthrough?.state==='completed'&&!delivered.messages.some(message=>message.message.includes('Let us work through')),
   endpoint+' resumed delivery exposes only the actual verified completed result in the same root-linked Chat');
  noDomainWrite(seed,delivered,endpoint+' completion during acknowledgement cannot complete the domain task or create reminders');
 }

 {
  const seed=await control('seed'),source=root(seed),ref=association(source),key='voice-exact-replay-'+source.id;
  const message='Review this exact proposal';
  const original=await post('chat-stream-v121.php',message,{initiative_reply:ref,idempotency_key:key});
  check(original.data?.ok===true,'voice saves a current associated response before exact-key replay attacks');
  const attacks=[
   ['changed Chat',message,{initiative_reply:ref,idempotency_key:key,conversation_id:14}],
   ['changed query','A different owner instruction',{initiative_reply:ref,idempotency_key:key}],
   ['dropped association',message,{idempotency_key:key}],
   ['null association',message,{initiative_reply:null,idempotency_key:key}],
  ];
  for(const [label,query,extra] of attacks){
   const before=await control(),replay=await post('chat-stream-v121.php',query,extra),after=await control();
   check(replay.data?.ok!==true&&!replay.body.includes('verified launch task')&&!replay.events.some(event=>event.type==='delta')&&
    after.messages.length===before.messages.length&&after.calls.length===before.calls.length,
    'voice same-key replay rejects '+label+' before cached text, inference or duplicate persistence');
  }
 }

 for(const endpoint of endpoints){
  const seed=await control('seed'),ref=association(root(seed));await control('policy_change');
  const before=await control(),response=await post(endpoint,'Please prepare the next private step',{initiative_reply:ref},{'X-Fixture-Followthrough-Intent':'advance'}),after=await control();
  check(response.data?.ok===true&&response.data.initiative_followthrough?.continuation_run_id===0&&after.runs.length===before.runs.length&&after.policy.enabled===false,
   endpoint+' permits current-source discussion while paused but cannot queue private work or resume Initiative');
  noDomainWrite(before,after,endpoint+' a paused advance grants no domain authority');
 }

 for(const endpoint of endpoints){
  for(const action of ['source_change','source_revoke','state_change']){
   const seeded=await control('seed'),ref=association(root(seeded));await control(action);
   const before=await control(),response=await post(endpoint,'Help me with that',{initiative_reply:ref});
   const after=await control();
   check(response.data?.ok!==true&&!response.body.includes('verified launch task')&&calls(after,'main_model').length===calls(before,'main_model').length,
    endpoint+' rejects '+action+' before main-model inference or stale text');
   noDomainWrite(before,after,endpoint+' '+action+' rejection has no domain write');
  }
 }

 for(const endpoint of endpoints){
  const seed=await control('seed'),source=root(seed),ref=association(source);
  const forgeries=[
   ['foreign owner',{...named,initiative_reply:ref},{'X-Fixture-User':'2'}],
   ['sibling Agent',{conversation_id:12,agent_context:{user_agent_id:8},initiative_reply:ref},{}],
   ['other Chat',{conversation_id:14,initiative_reply:ref},{}],
   ['ordinary post',{initiative_reply:{...ref,message_id:seed.messages.find(message=>message.role==='user').id}},{}],
   ['wrong reply reference',{initiative_reply:{...ref,reply_ref:'f'.repeat(64)}},{}],
   ['wrong revision',{initiative_reply:{...ref,revision:ref.revision+1}},{}],
   ['string message ID',{initiative_reply:{...ref,message_id:String(ref.message_id)}},{}],
   ['string revision',{initiative_reply:{...ref,revision:String(ref.revision)}},{}],
   ['null association',{initiative_reply:null},{}],
   ['array association',{initiative_reply:[ref]},{}],
   ['extra authority field',{initiative_reply:{...ref,approved:true}},{}],
  ];
  for(const [label,extra,headers] of forgeries){
   const before=await control(),response=await post(endpoint,'yes',extra,headers),after=await control();
   check(response.data?.ok!==true&&!response.body.includes('verified launch task')&&calls(after,'main_model').length===calls(before,'main_model').length,
    endpoint+' rejects '+label+' association before reasoning');
   noDomainWrite(before,after,endpoint+' '+label+' cannot consume unrelated mutation authority');
  }
  const cloned=await control('clone_post'),before=await control();
  const forged=await post(endpoint,'yes',{initiative_reply:{...ref,message_id:cloned.forged_post_id}}),after=await control();
  check(forged.data?.ok!==true&&calls(after,'main_model').length===calls(before,'main_model').length&&!forged.body.includes('verified launch task'),
   endpoint+' a copied Initiative post cannot borrow the original canonical workflow receipt');
  noDomainWrite(before,after,endpoint+' a copied post grants no task or reminder authority');
 }

 for(const endpoint of endpoints){
  const seeded=await control('seed'),ref=association(root(seeded));
  const response=await post(endpoint,'Help me think through that',{initiative_reply:ref},{'X-Fixture-Followthrough-Change':'1'});
  const after=await control();
  check(response.data?.ok!==true&&!response.body.includes('verified launch task')&&!response.events.some(event=>event.type==='delta'),
   endpoint+' withholds all generated answer text when its verified source changes during main-model inference');
  check(!after.messages.some(message=>message.role==='assistant'&&message.message.includes('Let us work through')),endpoint+' never persists a stale main-model answer');
 }

 for(const endpoint of ['chat.php','chat-v236.php']){
  const seed=await control('seed'),source=root(seed),ref=association(source);
  const advanced=await post(endpoint,'Please prepare the private next step',{initiative_reply:ref},{'X-Fixture-Followthrough-Intent':'advance'});
  const queued=await control(),child=advanced.data?.initiative_followthrough?.continuation_run_id;
  const initial=await post(endpoint,'',{action:'activity',initiative_message_ids:[source.id]});
  const initialUpdate=initial.data?.initiative_updates?.[0];
  check(advanced.data?.ok===true&&queued.notifications_exists===false&&initial.data?.ok===true&&initialUpdate?.available===true&&
   initialUpdate.initiative_followthrough?.message_id===source.id&&initialUpdate.initiative_followthrough?.state==='queued'&&
   initialUpdate.initiative_followthrough?.continuation_run_id===child,
   endpoint+' actual activity projects the queued root when notifications storage is absent',initial.body);
  const failed=await control('execute',{id:String(child),fail:'1'}),messages=failed.messages.length,modelCalls=failed.calls.length;
  const projection=await post(endpoint,'',{action:'activity',initiative_message_ids:[source.id]}),after=await control();
  const update=projection.data?.initiative_updates?.[0],failedRun=failed.runs.find(run=>Number(run.id)===Number(child));
  check(failed.execution_result?.ok===false&&failedRun?.status==='failed'&&projection.data?.ok===true&&update?.available===true&&update.recoverable===true&&
   context(root(failed)).initiative_work.followthrough.state==='stale'&&root(after).context_json===root(failed).context_json&&
   update.initiative_followthrough?.message_id===source.id&&update.initiative_followthrough?.state==='stale'&&
   update.initiative_followthrough?.revision>initialUpdate.initiative_followthrough.revision&&after.messages.length===messages&&after.calls.length===modelCalls,
   endpoint+' actual activity refreshes terminal child failure on the old root without another Chat message or model call',projection.body);
  for(const [label,extra,headers] of [
   ['another same-Agent Chat',{conversation_id:14},{}],
   ['a sibling Agent',{conversation_id:12,agent_context:{user_agent_id:8}},{}],
   ['another owner',{conversation_id:13,agent_context:{user_agent_id:9}},{'X-Fixture-User':'2'}],
  ]){
   const hidden=await post(endpoint,'',{action:'activity',initiative_message_ids:[source.id],...extra},headers);
   check(hidden.data?.ok===true&&JSON.stringify(hidden.data.initiative_updates)==JSON.stringify([{message_id:source.id,available:false}]),
    endpoint+' activity reveals no root metadata through '+label,hidden.body);
  }
  for(const ids of [[String(source.id)],[source.id,source.id]]){
   const invalid=await post(endpoint,'',{action:'activity',initiative_message_ids:ids});
   check(invalid.data?.ok!==true&&invalid.result.status===422,endpoint+' activity rejects malformed or duplicate root selectors',invalid.body);
  }
  await control('participants_on');
  const guest=await post(endpoint,'',{action:'activity',initiative_message_ids:[source.id],agent_context:{user_agent_id:7,
   voice_speaker:{participant_id:102,source:'owner_selected_participant',authentication_authority:false}}});
  check(guest.data?.ok!==true&&guest.result.status===403&&!guest.body.includes('reply_ref'),endpoint+' activity blocks non-self participant metadata retrieval',guest.body);
 }

 for(const endpoint of endpoints){
  const seed=await control('seed'),source=root(seed),query='Yes, prepare that private next step';
  const token='adjacency-'+source.id,gate=path.join(tmp,'before-user-gate-'+token);
  const first=post(endpoint,query,endpoint.includes('stream')?{idempotency_key:'adjacency-'+source.id}:{},
   {'X-Fixture-Followthrough-Intent':'advance','X-Fixture-Followthrough-Before-User-Gate':token});first.catch(()=>{});
  let pending,beforeRelease;
  try{
   await barrier(()=>fs.existsSync(gate+'.entered'),endpoint+' implicit preflight did not reach the actual saved-user transaction boundary');
   pending=await post(endpoint,'Add take a shower to my competing tab prep list',{}, {'X-Fixture-Followthrough-Intent':'none'});
   beforeRelease=await control();
   check(pending.data?.ok===true&&pending.data.tool_receipt?.status==='clarification_required'&&beforeRelease.list_actions.some(action=>action.status==='prepared'),
    endpoint+' a second real HTTP tab commits an unrelated canonical pending approval after implicit preflight');
  }finally{fs.writeFileSync(gate+'.release','release');}
  const rejected=await first,after=await control();
  check(rejected.data?.ok!==true&&(rejected.result.status===409||rejected.data?.status===409)&&!rejected.events.some(event=>event.type==='delta')&&
   after.messages.length===beforeRelease.messages.length&&!after.messages.some(message=>message.role==='user'&&message.message===query)&&
   after.calls.length===beforeRelease.calls.length&&after.runs.length===beforeRelease.runs.length&&after.events.length===beforeRelease.events.length&&
   context(root(after)).initiative_work.followthrough.state==='open',
   endpoint+' the actual user transaction rereads implicit adjacency and rejects the raced reply before any owner turn, intent or tool effect',rejected.body);
 }

 for(const endpoint of endpoints){
  for(const phase of ['intent','ack']){
   const seed=await control('seed'),source=root(seed),query='Yes, prepare a private next step';
   // This is the unchanged ordinary client payload: no initiative_reply or
   // client-provided root/turn anchor. Voice retains its existing request key.
   const extra=endpoint.includes('stream')?{idempotency_key:'implicit-provider-recovery-'+phase+'-'+source.id}:{};
   const failureHeaders={'X-Fixture-Followthrough-Intent':'advance',[phase==='intent'?'X-Fixture-Followthrough-Intent-Fail':'X-Fixture-Followthrough-Model-Fail']:'1'};
   const failed=await post(endpoint,query,extra,failureHeaders),afterFailure=await control();
   const originalTurn=afterFailure.messages.find(message=>message.role==='user'&&message.message===query);
   check(failed.data?.ok!==true&&originalTurn&&!failed.events.some(event=>event.type==='delta'),
    endpoint+' implicit '+phase+' provider failure retains one ordinary saved owner turn',failed.body);
   const healthy=await post(endpoint,query,extra,{'X-Fixture-Followthrough-Intent':'advance'}),afterHealthy=await control();
   const children=afterHealthy.runs.filter(run=>JSON.parse(run.goal||'{}').followthrough?.message_id===source.id);
   const decisions=afterHealthy.events.filter(event=>event.event_type==='initiative_owner_decision');
   const intentCalls=calls(afterHealthy,'initiative_model').filter(call=>call.protocol==='vp3.initiative-reply.v1');
   check(healthy.data?.ok===true&&healthy.data.user_message_id===originalTurn.id&&healthy.data.initiative_followthrough?.message_id===source.id&&
    children.length===1&&decisions.length===1&&afterHealthy.messages.filter(message=>message.role==='user'&&message.message===query).length===1&&
    afterHealthy.messages.filter(message=>context(message).initiative_followthrough?.message_id===source.id).length===1&&
    intentCalls.length===(phase==='intent'?2:1)&&calls(afterHealthy,'main_model').length===(phase==='intent'?1:2),
    endpoint+' identical implicit '+phase+' retry resumes the trusted original turn with one decision, child and assistant',healthy.body);
   const repeated=await post(endpoint,query,extra),afterRepeat=await control();
   check(repeated.data?.ok===true&&repeated.data.assistant_message_id===healthy.data.assistant_message_id&&
    afterRepeat.messages.length===afterHealthy.messages.length&&afterRepeat.runs.length===afterHealthy.runs.length&&afterRepeat.calls.length===afterHealthy.calls.length,
    endpoint+' completed implicit '+phase+' recovery replays without another turn, decision, child or inference',repeated.body);
  }
 }

 for(const endpoint of endpoints){
  const seed=await control('seed'),source=root(seed),ref=association(source),query='Yes, please prepare that private next step for historical replay';
  const extra={initiative_reply:ref,...(endpoint.includes('stream')?{idempotency_key:'completed-ack-replay-'+source.id}:{})};
  const first=await post(endpoint,query,extra,{'X-Fixture-Followthrough-Intent':'advance'}),child=first.data?.initiative_followthrough?.continuation_run_id;
  const completed=await control('execute',{id:String(child)}),action=completed.actions.find(action=>Number(action.run_id)===Number(child)),receipt=completed.receipts.find(receipt=>Number(receipt.run_id)===Number(child));
  check(first.data?.ok===true&&first.data.initiative_followthrough?.state==='queued'&&completed.execution_result?.ok===true&&
   action?.status==='completed'&&receipt?.status==='completed'&&action.result_json===receipt.result_json&&context(root(completed)).initiative_work.followthrough.state==='completed',
   endpoint+' historical replay fixture completes the actual canonical child after its queued ACK was saved');
  const replay=await post(endpoint,query,extra),after=await control();
  check(replay.data?.ok===true&&replay.data.assistant_message_id===first.data.assistant_message_id&&replay.data.user_message_id===first.data.user_message_id&&
   replay.data.answer===first.data.answer&&replay.data.initiative_followthrough?.state==='completed'&&
   replay.data.initiative_followthrough?.continuation_run_id===child&&after.messages.length===completed.messages.length&&after.calls.length===completed.calls.length&&
   after.runs.length===completed.runs.length&&after.events.length===completed.events.length&&
   after.messages.find(message=>message.id===first.data.assistant_message_id)?.message===first.data.answer,
   endpoint+' exact retry preserves the historical queued ACK identity and body while projecting its verified completed outcome without new effects',replay.body);
 }

 for(const [endpoint,implicit] of [['chat.php',false],['chat-v236.php',true]]){
  const seed=await control('seed'),source=root(seed),ref=association(source),query='Yes, please prepare that private next step';
  const extra=implicit?{}:{initiative_reply:ref};
  const first=await post(endpoint,query,extra,{'X-Fixture-Followthrough-Intent':'advance'}),before=await control();
  const dir=path.join(tmp,'runtime-fixture/idempotency');
  const records=fs.readdirSync(dir).filter(name=>name.endsWith('.json')).map(name=>{const file=path.join(dir,name);return {file,row:JSON.parse(fs.readFileSync(file,'utf8'))};})
   .filter(({row})=>row.scope==='initiative-reply:user-1:agent-7:chat-11'&&row.status==='completed'&&row.result?.assistant_message_id===first.data?.assistant_message_id);
  assert.equal(records.length,1,'The actual completed canonical ACK request must be present.');
  const {file,row}=records[0];assert.equal(row.initiative_request?.user_message_id,first.data.user_message_id);assert.equal(typeof row.initiative_claim,'string');
  row.status='processing';row.created_at=Math.floor(Date.now()/1000)-331;delete row.result;fs.writeFileSync(file,JSON.stringify(row));
  const recovered=await post(endpoint,query,extra),after=await control(),completed=JSON.parse(fs.readFileSync(file,'utf8'));
  check(first.data?.ok===true&&recovered.data?.ok===true&&recovered.data.user_message_id===first.data.user_message_id&&
   recovered.data.assistant_message_id===first.data.assistant_message_id&&recovered.data.answer===first.data.answer&&
   after.messages.length===before.messages.length&&after.calls.length===before.calls.length&&after.runs.length===before.runs.length&&after.events.length===before.events.length&&
   completed.status==='completed'&&completed.result?.assistant_message_id===first.data.assistant_message_id,
   endpoint+' '+(implicit?'implicit':'explicit')+' crash after saved ACK reconstructs the same real assistant response from DB with zero model or effect replay',recovered.body);
 }

 for(const [endpoint,implicit] of [['chat.php',false],['chat.php',true],['chat-v236.php',false],['chat-v236.php',true]]){
  const seed=await control('seed'),source=root(seed),query='Yes, prepare that private next step without a cache record',extra=implicit?{}:{initiative_reply:association(source)};
  const first=await post(endpoint,query,extra, {'X-Fixture-Followthrough-Intent':'advance'});
  const completed=await control('execute',{id:String(first.data?.initiative_followthrough?.continuation_run_id)});
  const dir=path.join(tmp,'runtime-fixture/idempotency');
  const matches=fs.readdirSync(dir).filter(name=>name.endsWith('.json')).map(name=>{const file=path.join(dir,name);return {file,row:JSON.parse(fs.readFileSync(file,'utf8'))};})
   .filter(({row})=>row.scope==='initiative-reply:user-1:agent-7:chat-11'&&row.result?.assistant_message_id===first.data?.assistant_message_id);
  assert.equal(matches.length,1,'The actual ACK must have one canonical private cache record.');fs.unlinkSync(matches[0].file);
  const names=fs.readdirSync(dir).sort(),refused=await post(endpoint,query,extra),after=await control();
  check(first.data?.ok===true&&completed.execution_result?.ok===true&&refused.data?.ok!==true&&refused.result.status===409&&
   !fs.existsSync(matches[0].file)&&JSON.stringify(fs.readdirSync(dir).sort())===JSON.stringify(names)&&
   after.messages.length===completed.messages.length&&after.calls.length===completed.calls.length&&after.runs.length===completed.runs.length&&after.events.length===completed.events.length,
   endpoint+' completed '+(implicit?'implicit':'explicit')+' evidence without its cache record fails closed before creating a request file or any model, turn or job',refused.body);
 }

 for(const [endpoint,implicit] of [['chat.php',false],['chat-v236.php',true]]){
  const seed=await control('seed'),source=root(seed),ref=association(source),query='Yes, please advance that proposal across the pre-anchor crash';
  const extra=implicit?{}:{initiative_reply:ref},token='missing-anchor-'+source.id,gate=path.join(tmp,'before-anchor-gate-'+token);
  const crash=await startCrashWorker();
  let killed=false;
  try{
   assert.equal(root(crash.state)?.id,source.id,'The doomed worker must share the same real database and Initiative post.');
   const original=post(endpoint,query,extra,{'X-Fixture-Followthrough-Intent':'advance','X-Fixture-Followthrough-Before-Anchor-Gate':token},undefined,false,crash.origin);original.catch(()=>{});
   await barrier(()=>fs.existsSync(gate+'.entered'),endpoint+' did not reach the actual committed-USER/pre-anchor transaction gap');
   const record=privateProcessingRecord(source.id),before=await control(),owner=before.messages.find(message=>message.role==='user'&&message.message===query);
   check(owner&&record.row.initiative_request?.user_message_id===owner.id&&!context(owner).initiative_reply_anchor_v1&&
    before.calls.length===0&&before.runs.length===seed.runs.length&&context(root(before)).initiative_work.followthrough.state==='open',
    endpoint+' '+(implicit?'implicit':'explicit')+' crash fixture has a real committed owner turn and matching private-file MID before the DB anchor exists');
   record.row.created_at=Math.floor(Date.now()/1000)-331;fs.writeFileSync(record.file,JSON.stringify(record.row));
   const pid=Number(fs.readFileSync(gate+'.entered','utf8'));assert.ok(Number.isSafeInteger(pid)&&pid>1&&pid!==process.pid);
   assert.equal(pid,crash.worker.pid,'Only the dedicated worker serving the actual doomed request may be killed.');
   assert.notEqual(pid,child.pid,'The primary server must remain available for the recovery request.');
   process.kill(pid,'SIGKILL');killed=true;await crash.stopped;const interrupted=await original.then(()=>false,()=>true);
   await freshControl();
   const cacheDir=path.join(tmp,'runtime-fixture/idempotency');
   const cacheSnapshot=()=>JSON.stringify(fs.readdirSync(cacheDir).filter(name=>name.endsWith('.json')).sort().map(name=>[name,fs.readFileSync(path.join(cacheDir,name),'utf8')]));
   const cacheBefore=cacheSnapshot();
   const refused=await post(endpoint,query,extra,{'X-Fixture-Followthrough-Intent':'advance'},AbortSignal.timeout(10000),true),after=await freshControl();
   check(interrupted&&refused.data?.ok!==true&&(refused.result.status===409||refused.data?.status===409)&&
    !refused.events.some(event=>event.type==='delta')&&!refused.body.includes('verified launch task')&&
    after.messages.length===before.messages.length&&after.calls.length===0&&after.runs.length===before.runs.length&&after.events.length===before.events.length&&
    !context(after.messages.find(message=>message.id===owner.id)).initiative_reply_anchor_v1&&cacheSnapshot()===cacheBefore,
    endpoint+' '+(implicit?'implicit':'explicit')+' stale processing without a real DB anchor fails closed after an actual worker kill, with no model, owner turn or child',refused.body);
  }finally{if(!killed){fs.writeFileSync(gate+'.release','release');crash.worker.kill('SIGTERM');await crash.stopped;}}
 }

 console.log(`AGENT_INITIATIVE_FOLLOWTHROUGH_API=PASS (${checks} assertions)`);
}finally{
 await Promise.all([...crashWorkers].map(worker=>new Promise(resolve=>{worker.once('exit',resolve);if(!worker.kill('SIGTERM'))resolve();})));
 try{process.kill(-child.pid,'SIGTERM');}catch{child.kill('SIGTERM');}
 if(process.env.VP3_KEEP_FOLLOWTHROUGH_FIXTURE)console.log('Fixture: '+tmp);
 else fs.rmSync(tmp,{recursive:true,force:true});
}
