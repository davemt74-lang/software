import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-capability-chat-'));
fs.cpSync('includes',path.join(tmp,'includes'),{recursive:true});fs.mkdirSync(path.join(tmp,'api'));
for(const name of ['chat-v236.php','chat-stream-v121.php','chat.php'])fs.copyFileSync('api/'+name,path.join(tmp,'api',name));
for(const name of ['agent-runtime-routing-v420.php','ai-settings.php'])fs.writeFileSync(path.join(tmp,'includes',name),'<?php // Controlled infrastructure fixture; implementation supplied by fixture bootstrap.');
fs.copyFileSync('tests/fixtures/capability-chat-bootstrap.php',path.join(tmp,'includes/bootstrap.php'));
fs.copyFileSync('tests/fixtures/phone-agent-schema-v1.php',path.join(tmp,'includes/phone-agent-schema-v1.php'));
fs.copyFileSync('tests/fixtures/workflow-agent-schema-v1.php',path.join(tmp,'includes/workflow-agent-schema-v1.php'));
fs.copyFileSync('tests/fixtures/record-agent-schema-v1.php',path.join(tmp,'includes/record-agent-schema-v1.php'));
fs.copyFileSync('tests/fixtures/media-agent-schema-v1.php',path.join(tmp,'includes/media-agent-schema-v1.php'));
fs.copyFileSync('tests/fixtures/app-agent-remote-v1.php',path.join(tmp,'includes/app-agent-remote-v1.php'));
fs.copyFileSync('tests/fixtures/system-app-agent-schema-v1.php',path.join(tmp,'includes/system-app-agent-schema-v1.php'));
fs.writeFileSync(path.join(tmp,'state.php'),`<?php require __DIR__.'/includes/bootstrap.php';header('Content-Type: application/json');echo json_encode(['reminders'=>(int)db()->query('SELECT COUNT(*) FROM agent_reminders_v1')->fetchColumn(),'messages'=>(int)db()->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()]);`);
const reserve=net.createServer();await new Promise(r=>reserve.listen(0,'127.0.0.1',r));const port=reserve.address().port;await new Promise(r=>reserve.close(r));
const child=spawn(process.env.VP3_PHP_BINARY||'php',['-S',`127.0.0.1:${port}`,'-t',tmp],{stdio:['ignore','ignore','pipe']});let logs='';child.stderr.on('data',x=>logs+=x);
const base=`http://127.0.0.1:${port}`;let checks=0;
async function post(endpoint,message,extra={},headers={}){
 const r=await fetch(base+'/api/'+endpoint,{method:'POST',headers:{'Content-Type':'application/json',...headers},body:JSON.stringify({action:'send',message,csrf_token:'fixture',knowledge_scope:'off',...extra})});
 const body=await r.text();let data;
 try{const events=body.trim().split('\n').map(x=>JSON.parse(x));data=endpoint.includes('stream')?(events.find(x=>x.type==='done')?.data??events.find(x=>x.type==='error')):events[0];}catch{throw Error(body+'\n'+logs.slice(-1500));}
 return {r,data,body};
}
const state=async()=>await(await fetch(base+'/state.php')).json();
function check(ok,why){assert.ok(ok,why);checks++;console.log('PASS '+why.split(': {')[0]);}
try{
 for(let i=0;i<60;i++){try{await fetch(base+'/state.php');break;}catch{await new Promise(r=>setTimeout(r,50));}}
 for(const endpoint of ['chat-v236.php','chat-stream-v121.php','chat.php']){
  const {data,body}=await post(endpoint,'Can you create reminders?',{input_mode:'voice'});
  check(data?.ok===true&&data.answer.includes('Personal reminders: ready'),endpoint+' uses production discovery before legacy reminder routing: '+body);
  check((await state()).reminders===0,endpoint+' ability question does not save a reminder');
 }
 let response=await post('chat-v236.php','Can you create reminders?',{}, {'X-Fixture-Model-Fail':'1'});check(response.data?.answer.includes('No action was taken')&&(await state()).reminders===0,'failed discovery inference cannot fall through to an unintended mutation');
 response=await post('chat-v236.php','Remind me to stretch in two minutes');check(response.data?.ok===true&&response.data.answer.includes('Saved reminder #')&&(await state()).reminders===1,'actual action request reaches production reminder executor once: '+response.body);
 const savedConversation=response.data.conversation_id;check(response.data.tool_receipt?.status==='completed'&&response.data.tool_receipt.changes_applied,'typed endpoint exposes only the real successful mutation receipt');
 response=await post('chat-v236.php','Did that work?',{conversation_id:savedConversation});check(response.data?.answer.includes('the requested change completed')&&response.data.tool_receipt?.capability==='reminders.manage','typed follow-up diagnoses the saved executor receipt');
 response=await post('chat-stream-v121.php','Did that work?',{conversation_id:savedConversation});check(response.data?.answer.includes('the requested change completed')&&response.data.tool_receipt?.capability==='reminders.manage','streaming follow-up shares the same owner execution evidence');
 response=await post('chat.php','Did that work?',{conversation_id:savedConversation});check(response.data?.answer.includes('the requested change completed')&&response.data.tool_receipt?.capability==='reminders.manage','legacy follow-up shares the same owner execution evidence');
 response=await post('chat-stream-v121.php','Is HomeServer connected?');check(response.data?.ok===true&&response.data.answer.includes('HomeServer is not paired'),'streaming owner Chat performs authenticated HomeServer inspection: '+response.body);
 const before=await state();response=await post('chat-v236.php','Can you create reminders?',{conversation_id:1},{'X-Fixture-User':'2'});check(response.data?.ok===false&&(await state()).messages===before.messages,'foreign conversation fails before discovery or message insertion');
 response=await post('chat-v236.php','Can you create reminders?',{csrf_token:'wrong'});check(response.r.status===419,'existing CSRF boundary remains active');
 response=await post('chat-v236.php','Can you create reminders?',{}, {'X-Fixture-Denied':'1'});check(response.r.status===403,'existing account permission boundary remains active');
 // Use the production named-agent principal, resolver and SQL ownership boundary.
 const named={agent_context:{user_agent_id:7,timezone:'America/Phoenix'}};
 response=await post('chat-v236.php?agent=7','Can you create reminders?',named);
 check(response.data?.ok===true&&response.data.agent_id===7,'Longfellow capability inspection preserves authenticated named identity: '+response.body);
 const longfellowConversation=response.data.conversation_id;
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php']){
  response=await post(endpoint,'Do I have any events on my calendar',{...named,conversation_id:longfellowConversation});
  check(response.data?.ok===true&&response.data.answer.includes('Fixture calendar review')&&!response.data.answer.includes('SECRET'),'same named-Agent calendar read works through '+endpoint+': '+response.body);
  check(response.data.tool_receipt?.capability==='calendar.read','calendar read returns verified execution evidence through '+endpoint);
  response=await post(endpoint,'Did that work?',{...named,conversation_id:longfellowConversation});
  check(response.data?.ok===true&&response.data.answer.includes('the read completed'),'named-Agent diagnosis reads its own receipt through '+endpoint+': '+response.body);
 }
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php']){
  response=await post(endpoint,'Read the selected transcript',{...named,conversation_id:longfellowConversation,agent_context:{user_agent_id:7,transcript_session_id:21}});
  check(response.data?.ok===true&&response.data.answer.includes('twenty dollars')&&response.data.tool_receipt?.capability==='transcripts.read','named-Agent selected transcript read through '+endpoint+': '+response.body);
  response=await post(endpoint,'Read the saved analysis for this transcript',{...named,conversation_id:longfellowConversation,agent_context:{user_agent_id:7,transcript_session_id:21}});
  check(response.data?.ok===true&&response.data.answer.includes('Fixture saved AI analysis')&&response.data.answer.includes('unreviewed')&&response.data.tool_receipt?.operation==='read_analysis','saved AI interpretation stays distinct from transcript evidence through '+endpoint+': '+response.body);
  response=await post(endpoint,'Highlight the selected transcript quote',{...named,conversation_id:longfellowConversation,agent_context:{user_agent_id:7,transcript_session_id:21,transcript_selected_text:'The agreed pizza price is twenty dollars.'}},{'X-Fixture-Brain-Ready':'1'});
  check(response.data?.ok===true&&response.data.tool_receipt?.capability==='transcripts.highlight'&&response.data.tool_receipt.changes_applied,'exact selected quote is retained through '+endpoint+': '+response.body);
  response=await post(endpoint,'Read the selected transcript',{...named,conversation_id:longfellowConversation,agent_context:{user_agent_id:7,transcript_session_id:22}});
  check(response.data?.ok===true&&!response.data.answer.includes('SECRET')&&response.data.tool_receipt?.status==='failed','forged foreign selection fails closed through '+endpoint);
 }
 const beforeReads=(await state()).reminders;
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php']){
  for(const phrase of ['What was on my uh reminder list','What did I have planned for today on my reminders','Yeah check my open reminders']){
   response=await post(endpoint,phrase,{...named,conversation_id:longfellowConversation,input_mode:'voice'},{'X-Fixture-Reminder-Bad':'once'});
   check(response.data?.ok===true&&response.data.tool_receipt?.status==='read_complete'&&!response.data.tool_receipt.changes_applied,'named-Agent read repairs malformed output through '+endpoint+': '+phrase+': '+response.body);
  }
  response=await post(endpoint,'Check my open reminders',{...named,conversation_id:longfellowConversation},{'X-Fixture-Reminder-Bad':'always'});
  check(response.data?.ok===true&&response.data.tool_receipt?.reason==='invalid_plan'&&response.data.answer.includes('after retrying'),'persistent bad reminder output returns a bounded human error through '+endpoint);
 }
 check((await state()).reminders===beforeReads,'all transcript reminder reads and interpretation retries leave schedules unchanged');
 response=await post('chat-stream-v121.php','Can you create reminders?',{conversation_id:longfellowConversation,agent_context:{user_agent_id:8}});
 check(response.data?.type==='error','another owned Agent cannot reopen Longfellow conversation');
 response=await post('chat.php','Can you create reminders?',{conversation_id:longfellowConversation,agent_context:{user_agent_id:9}});
 check(response.data?.ok===false,'legacy transport cannot reassign Longfellow conversation to foreign Agent');
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php']){
  response=await post(endpoint,'Do I have any events on my calendar',{...named,conversation_id:longfellowConversation},{'X-Fixture-Calendar-Missing':'1'});
  check(response.data?.ok===true&&response.data.answer.includes('database upgrade')&&response.data.tool_receipt?.status==='blocked','missing calendar schema reports its real blocker through '+endpoint);
 }
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php']){
  const headers={'X-Fixture-Phone-Ready':'1'};
  response=await post(endpoint,'Can you read SMS?',{...named,conversation_id:longfellowConversation},headers);
  check(response.data?.answer.includes('Read SMS inbox: ready')&&response.data.tool_receipt?.capability==='capabilities.inspect'&&!response.data.answer.includes('pizza pickup'),endpoint+' phone ability inspection does not read messages');
  response=await post(endpoint,'List my phone numbers',{...named,conversation_id:longfellowConversation},headers);
  check(response.data?.answer.includes('15550001001')&&!response.data.answer.includes('15550001002')&&response.data.tool_receipt?.capability==='phone.numbers.read',endpoint+' reads only the named Agent phone assignment');
  response=await post(endpoint,'List my SMS threads',{...named,conversation_id:longfellowConversation},headers);
  check(response.data?.answer.includes('Thread #11')&&!response.data.answer.includes('Thread #12'),endpoint+' lists SMS within the named Agent scope');
  response=await post(endpoint,'Read SMS thread 11',{...named,conversation_id:longfellowConversation},headers);
  check(response.data?.answer.includes('pizza pickup at six')&&response.data.tool_receipt?.status==='read_complete'&&!response.data.tool_receipt.changes_applied,endpoint+' reads saved SMS through the shared broker without mutations');
  response=await post(endpoint,'Read SMS thread 12',{...named,conversation_id:longfellowConversation},headers);
  check(response.data?.tool_receipt?.status==='failed'&&!response.data.answer.includes('SECRET'),endpoint+' rejects another named Agent SMS thread');
  response=await post(endpoint,'Did that work?',{...named,conversation_id:longfellowConversation},headers);
  check(response.data?.answer.includes('phone.sms.read / read')&&response.data.answer.includes('failed'),endpoint+' diagnoses the actual phone execution receipt');
 }
 for(const [index,endpoint] of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php'].entries()){
  const headers={'X-Fixture-Work-Ready':'1'},id=41+index*10;
  response=await post(endpoint,'Inspect workflow #'+id,{...named,conversation_id:longfellowConversation},headers);
  check(response.data?.tool_receipt?.capability==='workflows.read'&&response.data.tool_receipt.status==='read_complete',endpoint+' inspects the actual owner work queue with a receipt');
  response=await post(endpoint,'Pause workflow #'+(id+1),{...named,conversation_id:longfellowConversation},headers);
  check(response.data?.tool_receipt?.operation==='pause_request'&&response.data.tool_receipt.changes_applied&&response.data.answer.includes('finishing current action'),endpoint+' saves an executing-work pause request without claiming work stopped');
  response=await post(endpoint,'Did that work?',{...named,conversation_id:longfellowConversation},headers);
  check(response.data?.answer.includes('not completion of the underlying job')&&response.data.answer.includes('may still be finishing'),endpoint+' diagnoses the exact queued control receipt');
  response=await post(endpoint,'Inspect workflow #43',{...named,conversation_id:longfellowConversation},headers);
  check(response.data?.tool_receipt?.status==='failed'&&!response.data.answer.includes('SECRET'),endpoint+' denies a foreign owner workflow');
 }
 for(const [index,endpoint] of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php'].entries()){
  const headers={'X-Fixture-Record-Ready':'1','X-Fixture-Brain-Ready':'1'},id=210+index*10;
  response=await post(endpoint,'Search saved Knowledge for pizza',{...named,conversation_id:longfellowConversation,knowledge_scope:'all'},headers);
  check(response.data?.answer.includes('Fixture pizza supplier Knowledge')&&!response.data.answer.includes('SECRET')&&response.data.tool_receipt?.capability==='knowledge.search',endpoint+' explicitly searches actual scoped Knowledge');
  response=await post(endpoint,'Search saved Knowledge for pizza',{...named,conversation_id:longfellowConversation,knowledge_scope:'off'},headers);
  check(response.data?.tool_receipt?.status==='blocked'&&response.data.tool_receipt.reason==='scope_restricted',endpoint+' never expands Knowledge Off');
  response=await post(endpoint,'Search saved Brain for pizza',{...named,conversation_id:longfellowConversation},headers);
  check(response.data?.answer.includes('Fixture pizza Brain record')&&!response.data.answer.includes('SECRET')&&response.data.tool_receipt?.capability==='brain.read',endpoint+' recalls only current Agent Brain records');
  response=await post(endpoint,'List saved Brain tasks',{...named,conversation_id:longfellowConversation},headers);
  check(response.data?.answer.includes('Task #'+id)&&!response.data.answer.includes('SECRET')&&response.data.tool_receipt?.capability==='tasks.read',endpoint+' lists real scoped Brain tasks');
  response=await post(endpoint,'Complete saved Brain task #'+id,{...named,conversation_id:longfellowConversation},headers);
  check(response.data?.tool_receipt?.capability==='tasks.manage'&&response.data.tool_receipt.changes_applied&&response.data.answer.includes('completed'),endpoint+' verifies canonical task status persistence');
  response=await post(endpoint,'Did that work?',{...named,conversation_id:longfellowConversation},headers);
  check(response.data?.answer.includes('tasks.manage / set_status')&&response.data.answer.includes('change completed'),endpoint+' diagnoses the actual task status receipt');
  response=await post(endpoint,'Complete saved Brain task #'+(id+1),{...named,conversation_id:longfellowConversation},headers);
  check(response.data?.tool_receipt?.status==='failed'&&!response.data.answer.includes('SECRET'),endpoint+' cannot change another Agent task');
  response=await post(endpoint,'Search my HomeServer files for fixture',{...named,conversation_id:longfellowConversation},{...headers,'X-Fixture-Home-Ready':'1'});
  check(response.data?.answer.includes('Fixture local file')&&response.data.tool_receipt?.capability==='homeserver.read'&&response.data.tool_receipt.status==='read_complete',endpoint+' preserves authenticated HomeServer read provenance');
  response=await post(endpoint,'Search my HomeServer files for fixture',{...named,conversation_id:longfellowConversation},{...headers,'X-Fixture-Home-Ready':'1','X-Fixture-Home-Fail':'1'});
  check(response.data?.tool_receipt?.status==='failed'&&!response.data.answer.includes('Fixture local file'),endpoint+' does not replace a failed local read with a Cloud answer');
  response=await post(endpoint,'Did that work?',{...named,conversation_id:longfellowConversation},headers);
  check(response.data?.answer.includes('homeserver.read / files')&&response.data.answer.includes('failed'),endpoint+' diagnoses the failed local execution receipt');
 }
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php']){
  const context={...named,conversation_id:longfellowConversation,input_mode:'voice'},headers={'X-Fixture-Media-Ready':'1'};
  response=await post(endpoint,'Read saved TV history',context,headers);
  check(response.data?.tool_receipt?.capability==='tv.history.read'&&response.data.tool_receipt.status==='read_complete'&&response.data.answer.includes('Fixture Cheers')&&!response.data.answer.includes('SECRET')&&response.data.answer.includes('personal viewing unconfirmed'),endpoint+' recalls actual TV history with camera off');
  response=await post(endpoint,'Read saved TV preferences',context,headers);
  check(response.data?.tool_receipt?.capability==='tv.preferences.read'&&response.data.answer.includes('you confirmed this preference'),endpoint+' distinguishes confirmed preferences from screen observations');
  for(const [phrase,cap] of [['Search local Music Server library','music.library.read'],['Search local Media Server library','media.library.read']]){
   response=await post(endpoint,phrase,context,headers);
   check(response.data?.tool_receipt?.capability===cap&&response.data.tool_receipt.status==='read_complete'&&!response.data.tool_receipt.changes_applied&&response.data.answer.includes('Playback has not been started'),endpoint+' reads '+cap+' without claiming playback');
   for(const [mode,status] of [['empty','no_matches'],['malformed','failed'],['offline','failed']]){
    response=await post(endpoint,phrase,context,{...headers,'X-Fixture-Media-Mode':mode});
    check(response.data?.tool_receipt?.status===status&&!response.data.answer.includes('SECRET'),endpoint+' preserves '+cap+' '+mode+' execution evidence');
   }
   response=await post(endpoint,'Did that work?',context,headers);
   check(response.data?.answer.includes(cap)&&response.data.answer.includes('failed'),endpoint+' diagnoses the actual '+cap+' failure');
  }
  response=await post(endpoint,'Can you play music on HomeServer?',context,headers);
  check(response.data?.tool_receipt?.capability==='capabilities.inspect'&&response.data.answer.includes('browser player controls are separate')&&!response.data.answer.includes('Fixture local song'),endpoint+' playback capability question does not retrieve or play a track');
 }
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php']){
  const context={...named,conversation_id:longfellowConversation,input_mode:'voice'},headers={'X-Fixture-App-Ready':'1'};
  response=await post(endpoint,'Can you inspect my apps?',context,headers);
  check(response.data?.tool_receipt?.capability==='capabilities.inspect'&&!response.data.answer.includes('Fixture Notes'),endpoint+' app capability question does not retrieve records');
  for(const [phrase,cap] of [['List saved HomeServer user apps','apps.user.read'],['List installed HomeServer apps','apps.manager.read']]){
   response=await post(endpoint,phrase,context,headers);
   check(response.data?.tool_receipt?.capability===cap&&response.data.tool_receipt.status==='read_complete'&&response.data.answer.includes('Fixture Notes')&&!JSON.stringify(response.data).includes('SECRET'),endpoint+' reads the current owner '+cap+' safe projection');
   check(response.data.actions?.[0]?.url==='/apps.php'&&response.data.actions[0].auto===false&&response.data.answer.includes('has not confirmed navigation'),endpoint+' authorizes an existing manual Apps link without claiming browser navigation');
   response=await post(endpoint,phrase,context,{...headers,'X-Fixture-App-Mode':'malformed'});
   check(response.data?.tool_receipt?.status==='failed',endpoint+' malformed '+cap+' read cannot become an empty registry');
  }
  response=await post(endpoint,'Inspect workspace user.notes',context,headers);
  check(response.data?.tool_receipt?.capability==='apps.workspace.read'&&response.data.tool_receipt.status==='read_complete'&&response.data.answer.includes('reported validation passes')&&!JSON.stringify(response.data).includes('SECRET')&&!response.data.tool_receipt.changes_applied,endpoint+' reads selected workspace diagnostics without source disclosure or validation execution');
  response=await post(endpoint,'Inspect workspace foreign.notes',context,headers);
  check(response.data?.tool_receipt?.status==='clarification_required'&&response.data.answer.includes('user.notes'),endpoint+' unknown workspace selector requires current registry clarification');
  response=await post(endpoint,'List saved HomeServer user apps',context,{...headers,'X-Fixture-App-Mode':'empty'});
  check(response.data?.tool_receipt?.status==='no_matches',endpoint+' valid empty app registry remains distinguishable from failure');
  response=await post(endpoint,'List installed HomeServer apps',context,{...headers,'X-Fixture-App-Mode':'offline'});
  check(response.data?.tool_receipt?.status==='failed'&&!response.data.answer.includes('SECRET'),endpoint+' explicit local app-read failure cannot fall through into a Cloud answer');
  response=await post(endpoint,'Did that work?',context,headers);
  check(response.data?.answer.includes('apps.manager.read')&&response.data.answer.includes('failed'),endpoint+' diagnoses the persisted app read receipt');
 }
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php']){
  const context={...named,conversation_id:longfellowConversation,input_mode:'voice'},headers={'X-Fixture-System-App-Ready':'1'};
  response=await post(endpoint,'Can you read System App permissions?',context,headers);
  check(response.data?.tool_receipt?.capability==='capabilities.inspect'&&!response.data.answer.includes('hardware.camera'),endpoint+' permission ability inspection does not read permission records');
  response=await post(endpoint,'List Cloud System App catalog',context,headers);
  check(response.data?.tool_receipt?.capability==='system_apps.catalog.read'&&response.data.tool_receipt.status==='read_complete'&&response.data.answer.includes('2020-01-01')&&!JSON.stringify(response.data).includes('SECRET'),endpoint+' retrieves actual Cloud ownership and stored runtime projections');
  response=await post(endpoint,'Read stored System App health',context,headers);
  check(response.data?.tool_receipt?.capability==='system_apps.health.read'&&response.data.answer.includes('not live runtime')&&!JSON.stringify(response.data).includes('SECRET'),endpoint+' distinguishes stored health from a live diagnostic and redacts raw errors');
  response=await post(endpoint,'Read System App permissions for vp3.notes',context,headers);
  check(response.data?.tool_receipt?.status==='read_complete'&&response.data.answer.includes('hardware.camera · denied')&&!response.data.tool_receipt.changes_applied&&!JSON.stringify(response.data).includes('SECRET'),endpoint+' reads exact owned-app permissions without granting them');
  response=await post(endpoint,'Read System App permissions for vp3.inventory',context,headers);
  check(response.data?.tool_receipt?.reason==='permission_denied',endpoint+' rejects permission lookup for an unowned System App');
  response=await post(endpoint,'Read System App permissions for unknown.app',context,headers);
  check(response.data?.tool_receipt?.status==='clarification_required',endpoint+' unknown System App requires a current catalog selection');
  for(const mode of ['malformed','identity','counts','offline']){
   response=await post(endpoint,'Read System App permissions for vp3.notes',context,{...headers,'X-Fixture-System-App-Mode':mode});
   check(response.data?.tool_receipt?.status==='failed'&&!JSON.stringify(response.data).includes('SECRET'),endpoint+' preserves '+mode+' permission-read failure evidence');
  }
  response=await post(endpoint,'Did that work?',context,headers);
  check(response.data?.answer.includes('system_apps.permissions.read')&&response.data.answer.includes('failed'),endpoint+' diagnoses the persisted permission read failure');
 }
 console.log(`CAPABILITY_CHAT_API=PASS (${checks} cases; production typed/voice/stream/legacy endpoints, fixture model)`);
}catch(error){console.error(error);console.error(logs.slice(-4000));throw error;}finally{const exited=child.exitCode!==null||child.signalCode!==null?Promise.resolve():new Promise(r=>child.once('exit',r));child.kill();await exited;fs.rmSync(tmp,{recursive:true,force:true});}
