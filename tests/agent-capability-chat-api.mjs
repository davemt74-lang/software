import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {createHmac} from 'node:crypto';
import {spawn} from 'node:child_process';
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-capability-chat-'));
fs.cpSync('includes',path.join(tmp,'includes'),{recursive:true});fs.mkdirSync(path.join(tmp,'api'));
for(const name of ['chat-v236.php','chat-stream-v121.php','chat.php','task-notes-v1.php','chat-canvas-media-v1.php','agent-catch-up-v1.php'])fs.copyFileSync('api/'+name,path.join(tmp,'api',name));
for(const name of ['agent-runtime-routing-v420.php','ai-settings.php'])fs.writeFileSync(path.join(tmp,'includes',name),'<?php // Controlled infrastructure fixture; implementation supplied by fixture bootstrap.');
fs.copyFileSync('tests/fixtures/capability-chat-bootstrap.php',path.join(tmp,'includes/bootstrap.php'));
fs.copyFileSync('tests/fixtures/phone-agent-schema-v1.php',path.join(tmp,'includes/phone-agent-schema-v1.php'));
fs.copyFileSync('tests/fixtures/workflow-agent-schema-v1.php',path.join(tmp,'includes/workflow-agent-schema-v1.php'));
fs.copyFileSync('tests/fixtures/record-agent-schema-v1.php',path.join(tmp,'includes/record-agent-schema-v1.php'));
fs.copyFileSync('tests/fixtures/media-agent-schema-v1.php',path.join(tmp,'includes/media-agent-schema-v1.php'));
fs.copyFileSync('tests/fixtures/app-agent-remote-v1.php',path.join(tmp,'includes/app-agent-remote-v1.php'));
fs.copyFileSync('tests/fixtures/system-app-agent-schema-v1.php',path.join(tmp,'includes/system-app-agent-schema-v1.php'));
fs.copyFileSync('tests/fixtures/app-operation-schema-v1.php',path.join(tmp,'includes/app-operation-schema-v1.php'));
fs.copyFileSync('tests/fixtures/private-share-agent-v1.php',path.join(tmp,'includes/private-share-agent-v1.php'));
fs.copyFileSync('tests/fixtures/vrss-agent-v1.php',path.join(tmp,'includes/vrss-agent-v1.php'));
for(const name of ['analytics-agent-v1.php','radar-agent-v1.php','tracky-agent-v1.php'])fs.copyFileSync('tests/fixtures/'+name,path.join(tmp,'includes',name));
fs.copyFileSync('tests/fixtures/observation-api-state-v1.php',path.join(tmp,'observation-state.php'));
fs.writeFileSync(path.join(tmp,'state.php'),`<?php require __DIR__.'/includes/bootstrap.php';header('Content-Type: application/json');echo json_encode(['reminders'=>(int)db()->query('SELECT COUNT(*) FROM agent_reminders_v1')->fetchColumn(),'conversations'=>(int)db()->query('SELECT COUNT(*) FROM chat_conversations')->fetchColumn(),'messages'=>(int)db()->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn()]);`);
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
const vrssCalls=()=>fs.existsSync(path.join(tmp,'vrss-calls.jsonl'))?fs.readFileSync(path.join(tmp,'vrss-calls.jsonl'),'utf8').trim().split('\n').filter(Boolean).map(JSON.parse):[];
const observationState=async(domain,headers={},reset=false)=>{const r=await fetch(base+'/observation-state.php?domain='+domain+(reset?'&action=reset':''),{headers});return await r.json();};
function check(ok,why){assert.ok(ok,why);checks++;console.log('PASS '+why.split(': {')[0]);}
try{
 for(let i=0;i<60;i++){try{await fetch(base+'/state.php');break;}catch{await new Promise(r=>setTimeout(r,50));}}
 for(const endpoint of ['chat-v236.php','chat-stream-v121.php','chat.php']){
  const {data,body}=await post(endpoint,'Can you create reminders?',{input_mode:'voice'});
  check(data?.ok===true&&data.answer.includes('Personal reminders: ready'),endpoint+' uses production discovery before legacy reminder routing: '+body);
  check((await state()).reminders===0,endpoint+' ability question does not save a reminder');
 }
 for(const endpoint of ['chat-v236.php','chat-stream-v121.php','chat.php']){
  const before=await state(),{data,body}=await post(endpoint,'Learn my voice');
  check(data?.ok===true&&data.actions?.some(a=>a.type==='voice_enrollment'&&a.auto===false),'guided voice setup uses the actual '+endpoint+': '+body);
  check(data.tool_receipt?.capability==='voice.profiles.enroll'&&data.tool_receipt?.status==='clarification_required'&&!data.tool_receipt.changes_applied,'voice setup receipt does not claim microphone capture or profile save');
  check((await state()).reminders===before.reminders,'voice setup does not invoke reminder writes');
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
 const beforeBrief=await state();
 const workingBinding=createHmac('sha256','fixture').update('working-attention|1|').digest('hex');
 const workingHeaders={'X-VP3-Working-Attention':workingBinding};
 for(const endpoint of ['chat-v236.php','chat-stream-v121.php','chat.php']){
  const staleState=await state();
  for(const headers of [{...workingHeaders,'X-Fixture-User':'2'},
    {'X-VP3-Working-Attention':createHmac('sha256','fixture').update('working-attention|1|previous-session').digest('hex')},
    {'X-VP3-Working-Attention':''}]){
   const stale=await post(endpoint,'Catch me up',{conversation_id:0},headers);
   check(stale.r.status===403&&stale.data?.code==='account_changed'&&!stale.data?.answer&&
    !stale.body.includes('"type":"start"')&&JSON.stringify(await state())===JSON.stringify(staleState),
    'a stale or invalid Chat page cannot create a conversation or receive a brief through '+endpoint);
  }
  const brief=await post(endpoint,'Catch me up',{input_mode:endpoint.includes('stream')?'voice':'text'},
    {...workingHeaders,'X-Fixture-Model-Fail':'1','X-Fixture-Owner-Trap':'1'});
  check(brief.data?.ok===true&&brief.data.tool_receipt?.capability==='brief.attention.read'&&
    !brief.data.tool_receipt.changes_applied&&brief.data.actions?.length===0,'shared brief bypasses inference and ambient calendar refresh through '+endpoint+': '+brief.body);
  for(const [request,scopeNote] of [['What did I miss?','verified history of recent changes'],
    ['What did I miss while I was away?','what changed while you were away'],
    ['Has anything changed since this morning?','comparison for that time window'],
    ['What needs my attention before my next meeting?','next meeting’s start time']]){
   const natural=await post(endpoint,request,{input_mode:endpoint.includes('stream')?'voice':'text'},
     {...workingHeaders,'X-Fixture-Model-Fail':'1','X-Fixture-Owner-Trap':'1'});
   check(natural.data?.ok===true&&natural.data.tool_receipt?.capability==='brief.attention.read'&&
     natural.data.answer.includes(scopeNote)&&!natural.data.tool_receipt.changes_applied&&natural.data.actions?.length===0,
     'natural catch-up reads current evidence and qualifies the time scope through '+endpoint+': '+natural.body);
  }
 }
 const briefUrl=base+'/api/agent-catch-up-v1.php';
 const projection=await fetch(briefUrl+'?scope=office');
 const projected=await projection.json();
 check(projection.ok&&projected.ok&&projected.brief.items.length<=3&&projected.brief.coverage&&
   projection.headers.get('Cache-Control')==='no-store','Chat card uses a bounded read-only no-store endpoint');
 const workingResponse=await fetch(briefUrl+'?scope=office',{headers:workingHeaders});
 const workingBrief=(await workingResponse.json()).brief;
 check(workingResponse.ok&&Object.hasOwn(workingBrief.coverage,'missions')&&Object.hasOwn(workingBrief.coverage,'profile_calls')&&
   !Object.hasOwn(projected.brief.coverage,'profile_calls'),'current bound Chat page opts into new sources and old clients keep their original scope');
 check((await fetch(briefUrl,{headers:{...workingHeaders,'X-Fixture-User':'2'}})).status===403,
   'a stale page binding cannot read the newly signed-in owner’s brief');
 check((await fetch(briefUrl+'?scope=unknown')).status===422&&
   (await fetch(briefUrl+'?agent_id[]=7')).status===422,'brief endpoint rejects invalid scopes and selectors');
 check((await fetch(briefUrl+'?agent_id=7',{headers:{'X-Fixture-User':'2'}})).status===403&&
   (await fetch(briefUrl,{headers:{'X-Fixture-Anonymous':'1'}})).status===403,'brief endpoint checks owner and named Agent scope');
 check((await fetch(briefUrl,{method:'POST'})).status===405&&(await state()).reminders===beforeBrief.reminders,
   'brief route rejects writes and leaves reminder records untouched');
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
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php']){
  let n=await post(endpoint,'Load from the asteroid you saved me',named);const cid=n.data.conversation_id;
  check(n.data.tool_receipt?.capability==='navigation.open'&&n.data.actions?.[0]?.auto&&n.data.actions[0].url.includes('track=9'),'explicit Studio load returns an authorized auto navigation through '+endpoint);
  n=await post(endpoint,'Offer to open that track in Stem Studio',{...named,conversation_id:cid});check(n.data.tool_receipt?.status==='clarification_required'&&n.data.actions.length===0,'Studio offer remains a pending source reference through '+endpoint);
  n=await post(endpoint,'Yes open it',{...named,conversation_id:cid});check(n.data.actions?.[0]?.auto&&n.data.actions[0].url.includes('track=9'),'affirmative follow-up resolves the saved Studio destination through '+endpoint);
  n=await post(endpoint,'Open forbidden Studio track',{...named,conversation_id:cid});check(n.data.actions.length===0&&!n.data.answer.includes('SECRET'),'Studio navigation cannot expose a foreign track through '+endpoint);
  n=await post(endpoint,'Open contacts',{...named,conversation_id:cid});check(n.data.actions?.[0]?.auto&&n.data.actions[0].url==='/contacts.php','canonical authorized page navigation works through '+endpoint);
 }
 const listHeaders={'X-Fixture-Brain-Ready':'1','X-Fixture-Record-Ready':'1'};
 for(const [index,endpoint] of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php'].entries()){
  const name='prep '+index;
  let t=await post(endpoint,'Add take a shower to my '+name+' list',named,listHeaders);const cid=t.data.conversation_id;
  check(t.data.tool_receipt?.status==='clarification_required'&&!t.data.tool_receipt.changes_applied&&t.data.answer.includes('Create it'),'missing named list asks before any list/item save through '+endpoint);
  t=await post(endpoint,'Yes create it',{...named,conversation_id:cid},listHeaders);
  check(t.data.tool_receipt?.changes_applied&&t.data.answer.includes('take a shower')&&t.data.sticky_notes?.[0]?.kind==='task_list','yes creates the frozen list and item through '+endpoint);
  const note=t.data.sticky_notes[0];
  let n=await fetch(base+'/api/task-notes-v1.php',{method:'POST',headers:{'Content-Type':'application/json',...listHeaders},body:JSON.stringify({csrf_token:'fixture',agent_id:7,note})});let nd=await n.json();
  check(nd.ok&&nd.note.items.length===1&&nd.note.items[0].title==='take a shower','sticky note resolves actual saved task through '+endpoint);
  t=await post(endpoint,'Add pizza dough to my '+name+' list',{...named,conversation_id:cid},listHeaders);
  check(t.data.tool_receipt?.changes_applied&&!t.data.answer.includes('Create it'),'existing named list accepts a new item through '+endpoint);
  t=await post(endpoint,'Add pizza dough to my '+name+' list',{...named,conversation_id:cid},listHeaders);
  check(!t.data.tool_receipt?.changes_applied&&t.data.answer.includes('already'),'repeated add does not duplicate a task through '+endpoint);
  t=await post(endpoint,'Show '+name+' list',{...named,conversation_id:cid},listHeaders);
  check(t.data.sticky_notes?.[0]?.id===note.id&&!t.data.tool_receipt.changes_applied,'show named list returns a current authorized note reference through '+endpoint);
  n=await fetch(base+'/api/task-notes-v1.php',{method:'POST',headers:{'Content-Type':'application/json',...listHeaders},body:JSON.stringify({csrf_token:'fixture',agent_id:8,note})});nd=await n.json();check(n.status===404&&!nd.ok&&!JSON.stringify(nd).includes('shower'),'sticky API rejects another Agent list through '+endpoint);
  t=await post(endpoint,'Save take a shower as a task',{...named,conversation_id:cid},listHeaders);
  check(t.data.tool_receipt?.changes_applied&&t.data.answer.includes('as task #')&&!t.data.answer.includes('remind'),'explicit save task does not loop into reminder setup through '+endpoint);
  t=await post(endpoint,'Add olive oil to my cancelled '+name+' list',{...named,conversation_id:cid},listHeaders);
  check(t.data.tool_receipt?.status==='clarification_required','second missing list is staged through '+endpoint);
  t=await post(endpoint,'No cancel the list',{...named,conversation_id:cid},listHeaders);
  check(!t.data.tool_receipt?.changes_applied&&t.data.answer.includes('Cancelled'),'cancelled list proposal makes no task/list mutation through '+endpoint);
  t=await post(endpoint,'Show reminders',{...named,conversation_id:cid},listHeaders);
  check(t.data.sticky_notes?.[0]?.kind==='reminders'&&!t.data.tool_receipt.changes_applied,'show reminders returns a sticky reference through '+endpoint);
 }
 const beforeChatRepair=(await state()).reminders;
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php']){
  for(const input_mode of ['text','voice']){
   response=await post(endpoint,'Can you set a reminder',{...named,conversation_id:longfellowConversation,input_mode});
   check(response.data?.ok===true&&response.data.answer.includes('when?')&&response.data.tool_receipt?.status==='clarification_required'&&!response.data.tool_receipt.changes_applied,'incomplete reminder setup asks for details through '+endpoint+' '+input_mode);
   response=await post(endpoint,'Do we have a list tool yet',{...named,conversation_id:longfellowConversation,input_mode});
   check(response.data?.ok===true&&response.data.answer.includes('Which list')&&!response.data.answer.includes('ready (read)'),'list question stays conversational through '+endpoint+' '+input_mode);
   response=await post(endpoint,'Hey tell me about the transcription from October 30th',{...named,conversation_id:longfellowConversation,input_mode});
   check(response.data?.ok===true&&response.data.answer.includes('twenty dollars')&&!response.data.answer.includes('SECRET')&&response.data.tool_receipt?.status==='read_complete','dated transcript request reads authorized evidence through '+endpoint+' '+input_mode);
  }
 }
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php']){
  response=await post(endpoint,'Tell me about the transcription from October 30th',{...named,conversation_id:longfellowConversation,agent_context:{user_agent_id:7,timezone:'UTC'}});
  check(response.data?.tool_receipt?.status==='clarification_required'&&response.data.answer.includes('Phoenix previous day'),'UTC date correctly includes the second recording that Phoenix excludes through '+endpoint);
  response=await post(endpoint,'The first one',{...named,conversation_id:longfellowConversation});
  check(response.data?.tool_receipt?.record_id===23&&response.data.answer.includes('different local recording day'),'ordinal transcript choice follows the preceding clarification through '+endpoint);
 }
 check((await state()).reminders===beforeChatRepair,'conversational setup, list discovery and transcript date reads never create reminders');
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php']){
  response=await post(endpoint,'Summarize the transcription titled jcal',{...named,conversation_id:longfellowConversation});
  check(response.data?.tool_receipt?.operation==='summarize'&&response.data.tool_receipt.record_id===24&&response.data.answer.includes('project budget'),'title discovery summarizes the source in one turn through '+endpoint);
  response=await post(endpoint,'Tell me about this transcription',{...named,conversation_id:longfellowConversation});
  check(response.data?.tool_receipt?.operation==='discuss'&&response.data.tool_receipt.record_id===24&&!response.data.answer.includes('ready (read)'),'content request overrides mistaken capability inspection through '+endpoint);
  response=await post(endpoint,'Can you set a reminder',{...named,conversation_id:longfellowConversation});
  response=await post(endpoint,'What did they say about the budget?',{...named,conversation_id:longfellowConversation});
  check(response.data?.tool_receipt?.record_id===24&&response.data.answer.includes('project budget'),'server source attachment survives an unrelated reminder turn through '+endpoint);
  response=await post(endpoint,'What were the main issues?',{...named,conversation_id:longfellowConversation});
  check(response.data?.tool_receipt?.operation==='discuss'&&response.data.tool_receipt.record_id===24,'source-aware routing understands content follow-ups without transcript keywords through '+endpoint);
  response=await post(endpoint,'Can you read transcripts?',{...named,conversation_id:longfellowConversation});
  check(response.data?.tool_receipt?.capability==='capabilities.inspect','genuine capability question remains inspection through '+endpoint);
  response=await post(endpoint,'Detach this transcript',{...named,conversation_id:longfellowConversation});
  response=await post(endpoint,'Tell me about this transcription',{...named,conversation_id:longfellowConversation});
  check(response.data?.tool_receipt?.status==='clarification_required','detached source is not silently reopened through '+endpoint);
  response=await post(endpoint,'Find the transcription titled jcal',{...named,conversation_id:longfellowConversation});
  response=await post(endpoint,'Tell me about this transcription',{...named,conversation_id:longfellowConversation});
  check(response.data?.tool_receipt?.record_id===24&&response.data.tool_receipt.operation==='discuss','single catalog result supports this transcript follow-up through '+endpoint);
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
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php']){
  const context={...named,conversation_id:longfellowConversation,input_mode:'voice'},headers={'X-Fixture-Operation-Ready':'1'};
  response=await post(endpoint,'Can you install System Apps?',context,headers);
  check(response.data?.tool_receipt?.capability==='capabilities.inspect',endpoint+' app action capability question does not prepare a change');
  for(const [phrase,cap] of [['Inspect app controls user.notes','apps.control.read'],['Inspect System App release vp3.notes','system_apps.release.read']]){
   response=await post(endpoint,phrase,context,headers);
   check(response.data?.tool_receipt?.capability===cap&&response.data.tool_receipt.status==='read_complete'&&!JSON.stringify(response.data).includes('SECRET')&&!response.data.tool_receipt.changes_applied,endpoint+' reads verified '+cap+' through the shared model');
   response=await post(endpoint,phrase,context,{...headers,'X-Fixture-Operation-Mode':'missing'});
   check(response.data?.tool_receipt?.status==='failed'&&!JSON.stringify(response.data).includes('SECRET'),endpoint+' missing '+cap+' metadata remains a failure');
  }
  response=await post(endpoint,'Install System App vp3.notes',context,headers);
  check(response.data?.tool_receipt?.status==='clarification_required'&&response.data.tool_receipt.reason==='confirmation_required'&&!response.data.tool_receipt.changes_applied,endpoint+' concrete app action saves a reviewable confirmation only: '+response.body);
  check(response.data?.system_app_action_card?.status==='prepared'&&response.data.system_app_action_card.details.some(x=>x.label==='Version'),endpoint+' existing action card exposes the reviewed catalog version');
  const code=response.data.answer.match(/Confirmation code: \*\*([A-Z2-9]{8})\*\*/)?.[1];check(Boolean(code),endpoint+' returns the existing exact confirmation code');
  response=await post(endpoint,'Confirm app '+code,context,headers);
  check(response.data?.tool_receipt?.status==='completed'&&response.data.tool_receipt.changes_applied&&!JSON.stringify(response.data).includes('SECRET'),endpoint+' confirmed app installation persists verified canonical outcome: '+response.body);
  response=await post(endpoint,'Confirm app '+code,context,headers);
  check(response.data?.tool_receipt?.status==='read_complete'&&!response.data.tool_receipt.changes_applied,endpoint+' repeated confirmation reads prior result without executing again');
  response=await post(endpoint,'Install System App vp3.notes',context,headers);const failedCode=response.data.answer.match(/Confirmation code: \*\*([A-Z2-9]{8})\*\*/)?.[1];
  response=await post(endpoint,'Confirm app '+failedCode,context,{...headers,'X-Fixture-Operation-Mode':'offline'});
  check(response.data?.tool_receipt?.reason==='outcome_unverified'&&!JSON.stringify(response.data).includes('SECRET'),endpoint+' uncertain remote mutation returns outcome evidence, not success');
  check(response.data?.system_app_action_card?.status==='failed',endpoint+' uncertain action uses a Needs attention card instead of completed');
  response=await post(endpoint,'Confirm app '+failedCode,context,headers);
  check(response.data?.tool_receipt?.reason==='outcome_unverified',endpoint+' uncertain confirmation stays consumed when HomeServer recovers');
  response=await post(endpoint,'Did that work?',context,headers);
  check(response.data?.answer.includes('Effects may already have been saved')&&response.data.tool_receipt?.reason==='outcome_unverified',endpoint+' diagnoses uncertain app outcome from the persisted receipt');
 }
 for(const endpoint of ['chat-v236.php','chat-stream-v121.php','chat.php']){
  const context=endpoint==='chat.php'?{}:{agent_id:7};
  for(const cap of ['photos.library.read','downloads.jobs.read','media.processing.read','video.projects.read']){
   const view=cap==='photos.library.read'?'search':'list',plan={operation:'photo_media_read',ids:[cap],view};
   const headers={'X-Fixture-Media-Ready':'1','X-Fixture-Participant-Owner-Plan':JSON.stringify(plan)};
   let response=await post(endpoint,'Show my requested media records',context,headers);
   check(response.data?.tool_receipt?.status==='read_complete'&&!response.data.tool_receipt.changes_applied&&!JSON.stringify(response.data).includes('SECRET'),endpoint+' verifies '+cap+' through the shared production read: '+response.body);
   response=await post(endpoint,'Show my requested media records',context,{...headers,'X-Fixture-Media-Mode':'offline'});
   check(response.data?.tool_receipt?.status==='failed'&&!JSON.stringify(response.data).includes('SECRET'),endpoint+' unavailable '+cap+' never becomes an empty or completed result');
  }
 }
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php']){
  const agent=endpoint.includes('v236')?7:0,context=endpoint.includes('stream')?{agent_context:{user_agent_id:agent}}:{};
  const mediaHeaders={'X-Fixture-Images-Ready':'1','X-Fixture-Participant-Owner-Plan':JSON.stringify({operation:'cloud_media_read',ids:['media.assets.read'],media_type:'photo'})};
  let response=await post(endpoint,'Show my photos',context,mediaHeaders);
  check(response.data?.canvas_media?.asset_ids?.includes(1)&&!response.data.canvas_media.asset_ids.includes(2),endpoint+' Cloud photo search returns only the owner canvas references');
  const generationHeaders={...mediaHeaders,'X-Fixture-Participant-Owner-Plan':JSON.stringify({operation:'image_generate',ids:['images.generate'],prompt:'A cinematic Arizona desert sunset',title:'Desert artwork'})};
  response=await post(endpoint,'Create a desert image',context,generationHeaders);
  check(response.data?.tool_receipt?.capability==='images.generate'&&response.data.tool_receipt.status==='completed'&&response.data.canvas_media?.generation_ids?.length===1,endpoint+' concrete image command queues a canonical job and returns its canvas card: '+response.body);
  const cid=response.data.conversation_id,id=response.data.canvas_media.generation_ids[0];
  const history=await fetch(base+'/api/'+(endpoint.includes('v236')?'chat-v236.php?agent=7':'chat.php'),{method:'POST',headers:{'Content-Type':'application/json',...mediaHeaders},body:JSON.stringify({csrf_token:'fixture',action:'load',conversation_id:cid})});
  const saved=await history.json();check(saved.messages.some(m=>m.role==='assistant'&&m.context_json?.includes(id)),endpoint+' image job references survive a fresh conversation history load');
  const cancel=await fetch(base+'/api/chat-canvas-media-v1.php',{method:'POST',headers:{'Content-Type':'application/json',...mediaHeaders},body:JSON.stringify({csrf_token:'fixture',action:'cancel',confirmed:true,generation_id:id,conversation_id:cid,agent_id:agent})});
  const cancelled=await cancel.json();check(cancelled.generation?.status==='cancelled',endpoint+' cancel button uses the canonical owner job control: '+JSON.stringify(cancelled));
 }
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php']){
  const context=endpoint.includes('stream')?{agent_context:{user_agent_id:7}}:{};
  const plan={operation:'private_share_read',ids:['apps.shares.read'],view:'all'};
  const headers={'X-Fixture-Private-Share-Ready':'1','X-Fixture-Participant-Owner-Plan':JSON.stringify(plan)};
  let response=await post(endpoint,'Show my private app shares',context,headers);
  check(response.data?.tool_receipt?.capability==='apps.shares.read'&&response.data.tool_receipt.status==='read_complete'&&response.data.tool_receipt.count===6&&!response.data.tool_receipt.changes_applied,endpoint+' private app shares use a verified read-only receipt: '+response.body);
  check(response.data.answer.includes('Kitchen Notes')&&response.data.answer.includes('Sent Inventory')&&!JSON.stringify(response.data).includes('SECRET'),endpoint+' private share list scopes both directions to the authenticated owner');
  check(response.data.answer.includes('2020-01-02 09:30:00')&&response.data.answer.includes('historical')&&!response.data.private_app_lifecycle,endpoint+' saved installation evidence retains its timestamp without raw lifecycle payload');
  response=await post(endpoint,'Show only private apps I sent',context,{...headers,'X-Fixture-Participant-Owner-Plan':JSON.stringify({...plan,view:'sent'})});
  check(response.data?.tool_receipt?.count===1&&response.data.answer.includes('Sent Inventory')&&!response.data.answer.includes('Kitchen Notes'),endpoint+' sent view does not silently include received apps');
  response=await post(endpoint,'Find the Weekend Plan shared app',context,{...headers,'X-Fixture-Participant-Owner-Plan':JSON.stringify({...plan,view:'received',query:'Weekend Plan'})});
  check(response.data?.tool_receipt?.count===1&&response.data.answer.includes('Weekend Plan')&&!response.data.answer.includes('Kitchen Notes'),endpoint+' explicit app title filters saved share records');
  response=await post(endpoint,'Find an absent private app',context,{...headers,'X-Fixture-Participant-Owner-Plan':JSON.stringify({...plan,query:'No Such Fixture'})});
  check(response.data?.tool_receipt?.status==='no_matches'&&response.data.tool_receipt.count===0,endpoint+' verified empty private-share search differs from unavailable data');
  response=await post(endpoint,'Show my private app shares',context,{...headers,'X-Fixture-Private-Share-Mode':'invalid_status'});
  check(response.data?.tool_receipt?.status==='failed'&&!response.data.answer.includes('Kitchen Notes')&&!JSON.stringify(response.data).includes('SECRET'),endpoint+' malformed share storage cannot produce partial success or leak raw fields');
  response=await post(endpoint,'Can you read private app shares?',context,{...headers,'X-Fixture-Private-Share-Mode':'invalid_status','X-Fixture-Participant-Owner-Plan':JSON.stringify({operation:'inspect',ids:['apps.shares.read']})});
  check(response.data?.tool_receipt?.capability==='capabilities.inspect'&&response.data.tool_receipt.status==='read_complete'&&!response.data.answer.includes('Kitchen Notes'),endpoint+' capability inspection does not retrieve private-share rows');
 }
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php']){
  const context=endpoint.includes('stream')?{agent_context:{user_agent_id:7}}:{};
  const postUrl='https://social.example/vrss/v1/posts/11111111-1111-4111-8111-111111111111';
  const baseHeaders={'X-Fixture-Vrss-Ready':'1'};
  const route=plan=>({...baseHeaders,'X-Fixture-Participant-Owner-Plan':JSON.stringify(plan)});
  const planFor=view=>({operation:'vrss_read',ids:['feeds.vrss.read'],view,...(['likes','reposts','comments','thread'].includes(view)?{url:postUrl}:{}),...(['comments','thread'].includes(view)?{cursor:'0'}:{})});
  const views=[['subscriptions','vrss.status','Fixture Kitchen Journal'],['posts','vrss.feed','Fixture Desert News'],['connections','vrss.social.status','Fixture Publisher'],['likes','vrss.social.reactions','2 likes'],['reposts','vrss.social.reposts','2 reposts'],['comments','vrss.social.comments','Fixture public comment'],['conversations','vrss.social.conversations','conversation settings'],['thread','vrss.social.thread','Fixture conversation reply']];
  let response;
  for(const [view,action,marker] of views){
   const before=vrssCalls().length;
   response=await post(endpoint,'Show VRSS '+view+(planFor(view).url?' '+postUrl:''),context,route(planFor(view)));
   check(response.data?.tool_receipt?.capability==='feeds.vrss.read'&&response.data.tool_receipt.status==='read_complete'&&!response.data.tool_receipt.changes_applied&&response.data.answer.includes(marker),endpoint+' verified '+view+' uses shared read receipt: '+response.body);
   const calls=vrssCalls();check(calls.length===before+1&&calls.at(-1)[2].action===action&&calls.at(-1)[2].app_key==='vp3.feed'&&calls.at(-1)[2].confirmed===false&&!JSON.stringify(response.data).includes('SECRET')&&!response.data.vrss,endpoint+' '+view+' is one authorized read with no raw provider payload');
  }
  for(const mode of ['offline','wrong_app','wrong_action','missing_result','bad_result','missing_projection','bad_row']){
   response=await post(endpoint,'Show my VRSS posts',context,{...route(planFor('posts')),'X-Fixture-Vrss-Mode':mode});
   check(response.data?.tool_receipt?.status==='failed'&&!response.data.answer.includes('Fixture Desert News')&&!JSON.stringify(response.data).includes('SECRET'),endpoint+' VRSS '+mode+' cannot masquerade as a successful or empty read');
  }
  response=await post(endpoint,'Show my VRSS posts',context,{...route(planFor('posts')),'X-Fixture-Vrss-Mode':'empty'});
  check(response.data?.tool_receipt?.status==='no_matches'&&response.data.tool_receipt.count===0,endpoint+' an explicitly empty feed page is distinct from unavailable data');
  response=await post(endpoint,'Show VRSS likes '+postUrl,context,{...route(planFor('likes')),'X-Fixture-Vrss-Mode':'missing_count'});
  check(response.data?.tool_receipt?.status==='failed',endpoint+' omitted author count cannot become zero');
  response=await post(endpoint,'Show VRSS likes '+postUrl,context,{...route(planFor('likes')),'X-Fixture-Vrss-Mode':'unavailable_count'});
  check(response.data?.tool_receipt?.status==='read_complete'&&response.data.answer.includes('unavailable')&&!response.data.answer.includes('0 likes'),endpoint+' explicitly unavailable author count remains unavailable');
  let before=vrssCalls().length;
  response=await post(endpoint,'Can you read and manage VRSS feeds?',context,{...route({operation:'inspect',ids:['feeds.vrss.read','feeds.vrss.manage']}),'X-Fixture-Vrss-Mode':'offline'});
  check(response.data?.tool_receipt?.capability==='capabilities.inspect'&&vrssCalls().length===before&&!response.data.answer.includes('Fixture'),endpoint+' feed capability inspection performs no remote read');
  response=await post(endpoint,'Show VRSS posts',context,route({...planFor('posts'),url:postUrl}));
  check(response.data?.tool_receipt?.reason==='invalid_plan'&&vrssCalls().length===before,endpoint+' read plan rejects unrelated URL selectors before I/O');
  response=await post(endpoint,'Show VRSS posts',context,route({...planFor('posts'),ids:['feeds.vrss.manage']}));
  check(response.data?.tool_receipt?.reason==='invalid_plan'&&vrssCalls().length===before,endpoint+' write capability cannot authorize a read plan');
  response=await post(endpoint,'Show VRSS posts',context,{...route(planFor('posts')),'X-Fixture-Vrss-Mode':'revoke'});
  check(response.data?.tool_receipt?.status==='blocked'&&!response.data.answer.includes('Fixture Desert News'),endpoint+' owner deactivation during the read discards remote content');
  response=await post(endpoint,'Show VRSS posts',context,route(planFor('posts')));
  const swapCid=response.data.conversation_id;
  response=await post(endpoint,'Show VRSS posts',{...context,conversation_id:swapCid},{...route(planFor('posts')),'X-Fixture-Vrss-Mode':'swap','X-Fixture-Vrss-Conversation':String(swapCid)});
  check(response.data?.tool_receipt?.status==='blocked'&&!response.data.answer.includes('Fixture Desert News'),endpoint+' changed conversation Agent discards remote content');
  for(const [command,action,mode,label] of [['refresh my feed','vrss.refresh_all','success','Queued'],['like VRSS '+postUrl,'vrss.social.like','success','Pending'],['refresh my feed','vrss.refresh_all','missing_projection','']]){
   before=vrssCalls().length;
   response=await post(endpoint,command,context,route({operation:'continue',ids:[]}));
   const plan=response.data?.system_app_plan,cid=response.data?.conversation_id;
   check(plan?.requires_confirmation===true&&plan.preview.operation===action&&vrssCalls().length===before,endpoint+' '+action+' saves a bound confirmation before any remote change: '+response.body);
   const headers={...route({operation:'system_apps_action',ids:['system_apps.manage'],action:'confirm',code:plan.confirmation_code}),'X-Fixture-Vrss-Mode':mode};
   response=await post(endpoint,'confirm app '+plan.confirmation_code,{...context,conversation_id:cid},headers);
   const card=response.data?.system_app_action_card;
   check(vrssCalls().length===before+1&&vrssCalls().at(-1)[2].confirmed===true&&response.data?.tool_receipt?.status===(label?'completed':'failed'),endpoint+' '+action+' confirms once and reports '+(label||'unverified')+': '+response.body);
   check(card?.status===(label?'recorded':'failed')&&(!label||card.outcome_label===label)&&!card.confirmation_code&&!card.actions.some(a=>a.type==='prompt')&&!JSON.stringify(response.data).includes('SECRET')&&!response.data.answer.includes('completed with a verified result'),endpoint+' feed card and answer preserve the observed outcome phase');
   if(label==='Queued')check(response.data.answer.includes('queued')&&!response.data.system_app_plan.result.vrss_outcome.final_verified,endpoint+' a queued refresh is not a completed refresh');
   if(label==='Pending')check(/pending|waiting for delivery/i.test(response.data.answer)&&!response.data.system_app_plan.result.vrss_outcome.final_verified,endpoint+' pending delivery is not a published interaction');
   response=await post(endpoint,'confirm app '+plan.confirmation_code,{...context,conversation_id:cid},headers);
   check(response.data?.system_app_plan?.idempotent_replay===true&&vrssCalls().length===before+1&&response.data?.tool_receipt?.status===(label?'read_complete':'failed')&&!response.data.tool_receipt.changes_applied,endpoint+' repeated feed confirmation reads its recorded outcome without a second effect');
   response=await post(endpoint,'Did that finish?',{...context,conversation_id:cid},route({operation:'diagnose',ids:[]}));
   check(vrssCalls().length===before+1&&!response.data.answer.includes('the requested change completed')&&(label==='Queued'?response.data.answer.includes('queued'):label==='Pending'?/pending|waiting for delivery/i.test(response.data.answer):response.data.answer.includes('did not verify')),endpoint+' next-turn diagnosis preserves the recorded feed phase: '+response.body);
   const historyEndpoint=endpoint.includes('v236')?'chat-v236.php?agent=7':'chat.php';
   const history=await fetch(base+'/api/'+historyEndpoint,{method:'POST',headers:{'Content-Type':'application/json',...baseHeaders},body:JSON.stringify({csrf_token:'fixture',action:'load',conversation_id:cid})});
   const saved=await history.json();
   const cards=saved.messages.filter(m=>m.role==='assistant').map(m=>JSON.parse(m.context_json||'{}').system_app_action_card).filter(c=>c?.action_id===plan.action_id);
   check(cards.some(c=>c.status===(label?'recorded':'failed')&&(!label||c.outcome_label===label)),endpoint+' persisted feed outcome card survives history reload');
  }
  response=await post(endpoint,'refresh my feed',context,route({operation:'continue',ids:[]}));
  const lostPlan=response.data.system_app_plan,lostCid=response.data.conversation_id;before=vrssCalls().length;
  const lostHeaders=route({operation:'system_apps_action',ids:['system_apps.manage'],action:'confirm',code:lostPlan.confirmation_code});
  response=await post(endpoint,'confirm app '+lostPlan.confirmation_code,{...context,conversation_id:lostCid},{...lostHeaders,'X-Fixture-Vrss-Mode':'lost_reply'});
  check(response.data?.tool_receipt?.reason==='outcome_unverified'&&vrssCalls().length===before+1&&!JSON.stringify(response.data).includes('SECRET'),endpoint+' lost feed mutation response records an uncertain outcome without provider errors');
  response=await post(endpoint,'confirm app '+lostPlan.confirmation_code,{...context,conversation_id:lostCid},lostHeaders);
  check(response.data?.tool_receipt?.reason==='outcome_unverified'&&vrssCalls().length===before+1&&!response.data.tool_receipt.changes_applied,endpoint+' uncertain feed confirmation cannot run again after the provider recovers');
 }
 // S10 uses the production planners, adapters and legacy handlers over real stored SQL evidence.
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php']){
  const context={...named,conversation_id:longfellowConversation,input_mode:'voice'};
  const baseHeaders={'X-Fixture-Analytics-Ready':'1'};
  const route=plan=>({...baseHeaders,'X-Fixture-Participant-Owner-Plan':JSON.stringify(plan)});
  const planFor=view=>({operation:'analytics_read',ids:['analytics.read'],scope:'all_owned',period:'30d',view});
  await observationState('analytics',{},true);const before=await observationState('analytics',baseHeaders);
  for(const [view,marker] of [['summary','4 stored Radar sessions'],['properties','Fixture Workshop Site'],['top_pages','/home'],['referrers','search.example'],['conversions','1 tracked conversion events'],['devices','Desktop'],['browsers','Chrome']]){
   response=await post(endpoint,'Read my requested Analytics view',context,route(planFor(view)));
   check(response.data?.tool_receipt?.capability==='analytics.read'&&response.data.tool_receipt.status==='read_complete'&&!response.data.tool_receipt.changes_applied&&response.data.answer.includes(marker),endpoint+' Analytics '+view+' reads complete stored evidence: '+response.body);
   check(!JSON.stringify(response.data).includes('SECRET')&&!response.data.answer.includes('9999')&&!response.data.answer.includes('5000')&&response.data.answer.includes('database timezone'),endpoint+' Analytics '+view+' omits private fields and lifetime totals and names its reporting clock');
  }
  const one={...planFor('summary'),scope:'property',property_id:11,period:'today'};
  response=await post(endpoint,'Read today for my kitchen site',context,route(one));
  check(response.data?.answer.includes('property #11')&&response.data.answer.includes('2 browser page-view events')&&!response.data.answer.includes('recorded profile-visit'),endpoint+' Analytics exact-property today scope excludes yesterday and native profile totals: '+response.body);
  for(const selector of [{property_id:21},{property:'absent.example'}]){
   response=await post(endpoint,'Read a selected Analytics site',context,route({...planFor('summary'),scope:'property',...selector}));
   check(response.data?.tool_receipt?.status==='clarification_required'&&!response.data.answer.includes('stored Radar sessions'),endpoint+' Analytics unavailable property never broadens to all sites');
  }
  const {scope:unusedScope,...unscoped}=planFor('summary');
  response=await post(endpoint,'Read analytics',context,route(unscoped));check(response.data?.tool_receipt?.reason==='invalid_plan',endpoint+' Analytics requires an explicit scope');
  response=await post(endpoint,'Read Analytics',context,{...route(planFor('summary')),'X-Fixture-Observation-Denied':'1'});check(response.data?.tool_receipt?.status==='blocked'&&!response.data.answer.includes('stored Radar sessions'),endpoint+' Analytics respects current permission denial');
  response=await post(endpoint,'Can you read Analytics?',context,route({operation:'inspect',ids:['analytics.read']}));check(response.data?.tool_receipt?.capability==='capabilities.inspect'&&!response.data.answer.includes('Fixture Kitchen Site'),endpoint+' Analytics capability question does not return traffic records');
  for(const phrase of ['Show Analytics for kitchen.example today','Show my website traffic today']){
   response=await post(endpoint,phrase,context,route({operation:'continue',ids:[]}));
   check(response.data?.tool_receipt?.capability==='analytics.read'&&response.data.tool_receipt.status==='read_complete'&&response.data.answer.includes('database timezone'),endpoint+' Analytics legacy fallback preserves the shared stored read: '+response.body);
  }
  check((await observationState('analytics')).fingerprint===before.fingerprint,endpoint+' Analytics reads do not create/reactivate properties, change counters or initialize schema');
  for(const [mode,status] of [['bad_json','failed'],['missing_schema','blocked'],['empty','read_complete']]){
   await observationState('analytics',{},true);
   response=await post(endpoint,'Read my Analytics summary',context,{...route(planFor('summary')),'X-Fixture-Analytics-Mode':mode});
   check(response.data?.tool_receipt?.status===status&&!JSON.stringify(response.data).includes('SECRET')&&(mode!=='empty'||response.data.answer.includes('0 stored Radar sessions')),endpoint+' Analytics '+mode+' is reported accurately: '+response.body);
  }
 }
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php']){
  const context={...named,conversation_id:longfellowConversation,input_mode:'voice'},baseHeaders={'X-Fixture-Radar-Ready':'1'};
  const route=plan=>({...baseHeaders,'X-Fixture-Participant-Owner-Plan':JSON.stringify(plan)}),planFor=view=>({operation:'radar_read',ids:['radar.read'],view});
  const legacy=route({operation:'continue',ids:[]});
  await observationState('radar',{},true);const before=await observationState('radar',baseHeaders);
  for(const [view,marker] of [['recent','Fixture GPTBot'],['high_risk','Fixture GPTBot'],['contact_policies','Fixture GPTBot'],['scoped_rules','Fixture OpenAI'],['access_profile','Monitor All']]){
   response=await post(endpoint,'Read my requested Radar view',context,route(planFor(view)));
   check(response.data?.tool_receipt?.capability==='radar.read'&&response.data.tool_receipt.status==='read_complete'&&!response.data.tool_receipt.changes_applied&&response.data.answer.includes(marker),endpoint+' Radar '+view+' reports current-owner saved evidence: '+response.body);
   check(!JSON.stringify(response.data).includes('SECRET')&&!response.data.answer.includes('FOREIGN')&&response.data.answer.includes('do not prove enforcement'),endpoint+' Radar '+view+' keeps sensitive fields private and distinguishes saved policy from enforcement');
  }
  response=await post(endpoint,'Read selected contact policy',context,route({...planFor('contact_policies'),contact_id:201}));check(response.data?.tool_receipt?.status==='no_matches'&&!response.data.answer.includes('FOREIGN'),endpoint+' Radar foreign contact ID cannot expose another owner policy');
  response=await post(endpoint,'Read Radar',context,{...route(planFor('recent')),'X-Fixture-Observation-Denied':'1'});check(response.data?.tool_receipt?.status==='blocked'&&!response.data.answer.includes('Fixture GPTBot'),endpoint+' Radar respects current permission denial');
  response=await post(endpoint,'Read Radar',context,route({...planFor('recent'),owner_user_id:2}));check(response.data?.tool_receipt?.reason==='invalid_plan',endpoint+' Radar planner rejects an owner override');
  response=await post(endpoint,'Show high-risk agents',context,legacy);check(response.data?.tool_receipt?.capability==='radar.read'&&response.data.answer.includes('Fixture GPTBot')&&!response.data.answer.includes('Fixture ChatGPT'),endpoint+' Radar legacy high-risk read uses the bounded adapter');
  for(const phrase of ['Can you block all crawlers?','How do I set my access profile to Locked Down?','If I block Fixture GPTBot, what happens?']){
   await post(endpoint,phrase,context,legacy);
   check((await observationState('radar')).fingerprint===before.fingerprint,endpoint+' Radar capability/tutorial/conditional wording cannot change saved policies');
  }
  response=await post(endpoint,'Block agent Fixture OpenAI',context,legacy);check(response.data?.tool_receipt?.status==='clarification_required'&&(await observationState('radar')).fingerprint===before.fingerprint,endpoint+' Radar operator text cannot silently choose one contact: '+response.body);
  for(const command of ['Block agent 101','Block operator Fixture OpenAI','Set access profile to Locked Down']){
   response=await post(endpoint,command,context,legacy);const cid=response.data?.conversation_id;
   check(response.data?.tool_receipt?.capability==='radar.gateway.manage'&&response.data.tool_receipt.status==='completed'&&response.data.tool_receipt.changes_applied&&!response.data.answer.includes('is now blocked across'),endpoint+' Radar explicit command verifies canonical saved state: '+response.body);
   const saved=await observationState('radar');
   response=await post(endpoint,command,{...context,conversation_id:cid},legacy);
   check(response.data?.tool_receipt?.status==='read_complete'&&!response.data.tool_receipt.changes_applied&&(await observationState('radar')).fingerprint===saved.fingerprint,endpoint+' Radar identical command reports the existing policy without another write');
   response=await post(endpoint,'Did that work?',{...context,conversation_id:cid},route({operation:'diagnose',ids:[]}));
   check(response.data?.tool_receipt?.capability==='radar.gateway.manage'&&/policy|Gateway/.test(response.data.answer)&&/enforcement|integration/.test(response.data.answer),endpoint+' Radar follow-up diagnosis preserves saved-policy scope: '+response.body);
  }
  for(const mode of ['bad_metadata','foreign_property']){
   await observationState('radar',{},true);
   response=await post(endpoint,'Read Radar policy evidence',context,{...route(planFor(mode==='bad_metadata'?'contact_policies':'scoped_rules')),'X-Fixture-Radar-Mode':mode});
   check(response.data?.tool_receipt?.status===(mode==='foreign_property'?'blocked':'failed')&&!JSON.stringify(response.data).includes('SECRET')&&!response.data.answer.includes('FOREIGN'),endpoint+' Radar '+mode+' cannot become a verified policy summary: '+response.body);
  }
  await observationState('radar',{},true);
  response=await post(endpoint,'Block agent 101',context,{...legacy,'X-Fixture-Radar-Mode':'unverified_write'});
  check(response.data?.tool_receipt?.reason==='outcome_unverified'&&response.data.answer.includes('Effects may already exist'),endpoint+' Radar failed saved-state verification records uncertainty without claiming success: '+response.body);
  await observationState('radar',{},true);
 }
 for(const endpoint of ['chat-v236.php?agent=7','chat-stream-v121.php','chat.php']){
  const context={...named,conversation_id:longfellowConversation,input_mode:'voice'},baseHeaders={'X-Fixture-Tracky-Ready':'1'};
  const route=plan=>({...baseHeaders,'X-Fixture-Participant-Owner-Plan':JSON.stringify(plan)}),planFor=view=>({operation:'tracky_read',ids:['tracky.context.read'],view});
  await observationState('tracky',{},true);const before=await observationState('tracky',baseHeaders);
  for(const [view,selectors,marker] of [['current',{},'Fixture quiet room'],['health',{},'Fixture Home'],['location',{entity:'keys'},'office'],['presence',{entity:'office'},'alex'],['last_seen',{entity_id:'object:keys'},'Fixture keys observed'],['changes',{},'Fixture recent health alert'],['evidence',{entity:'keys'},'Fixture keys observed']]){
   response=await post(endpoint,'Read my requested stored Tracky observations',context,route({...planFor(view),...selectors}));
   check(response.data?.tool_receipt?.capability==='tracky.context.read'&&response.data.tool_receipt.status==='read_complete'&&!response.data.tool_receipt.changes_applied&&response.data.answer.toLowerCase().includes(marker.toLowerCase()),endpoint+' Tracky '+view+' uses exact stored evidence: '+response.body);
   check(!JSON.stringify(response.data).includes('SECRET')&&!response.data.tracky?.active_perception?.attempted,endpoint+' Tracky '+view+' cannot expose raw perception or trigger sensing');
  }
  response=await post(endpoint,'Where is my absent object?',context,route({...planFor('location'),entity:'absent object'}));check(response.data?.tool_receipt?.status==='no_matches'&&!response.data.answer.includes('garage'),endpoint+' Tracky unknown object cannot fall back to another object location');
  response=await post(endpoint,'Read selected stored site',context,route({...planFor('current'),site_id:'foreign-site'}));check(response.data?.tool_receipt?.status==='no_matches'&&!JSON.stringify(response.data).includes('SECRET'),endpoint+' Tracky foreign site selection stays empty');
  response=await post(endpoint,'Read Tracky',context,{...route(planFor('current')),'X-Fixture-Observation-Denied':'1'});check(response.data?.tool_receipt?.status==='blocked'&&!response.data.answer.includes('Fixture quiet room'),endpoint+' Tracky entitlement revocation blocks stored evidence');
  response=await post(endpoint,'Can you use Agent Eyes?',context,route({operation:'inspect',ids:['tracky.context.read','tracky.perception.request']}));check(response.data?.tool_receipt?.capability==='capabilities.inspect'&&!response.data.answer.includes('Fixture quiet room'),endpoint+' Tracky ability question describes tools without returning observations');
  response=await post(endpoint,'Where are my keys?',context,route({operation:'continue',ids:[]}));check(response.data?.tool_receipt?.capability==='tracky.context.read'&&response.data.answer.toLowerCase().includes('office'),endpoint+' Tracky legacy location uses the shared passive read: '+response.body);
  check((await observationState('tracky')).fingerprint===before.fingerprint,endpoint+' Tracky stored reads do not capture, ingest, refresh or initialize schema');
  for(const [mode,marker] of [['missing_observation','observation time unknown'],['future','future timestamp; not current'],['stale','stale stored observation'],['reconciling','continuity reconciling']]){
   await observationState('tracky',{},true);
   response=await post(endpoint,'Read stored context',context,{...route(planFor('current')),'X-Fixture-Tracky-Mode':mode});
   check(response.data?.tool_receipt?.status==='read_complete'&&response.data.answer.includes(marker)&&!response.data.tracky?.active_perception?.attempted,endpoint+' Tracky '+mode+' cannot masquerade as current sensing: '+response.body);
  }
  await observationState('tracky',{},true);
  response=await post(endpoint,'Read stored context',context,{...route(planFor('current')),'X-Fixture-Tracky-Mode':'multi_site'});check(response.data?.tool_receipt?.status==='clarification_required'&&!response.data.answer.includes('Fixture quiet room'),endpoint+' Tracky multiple sites require a selection instead of the latest site');
  await observationState('tracky',{},true);
  response=await post(endpoint,'Read stored context',context,{...route(planFor('current')),'X-Fixture-Tracky-Mode':'scene_revoked'});check(!JSON.stringify(response.data).includes('cup')&&!response.data.tracky?.active_perception?.attempted,endpoint+' Tracky revoked scene is omitted from canvas and Chat evidence');
 }
 const selectedChat=(await post('chat-v236.php','Can you create reminders?')).data;
 const newerChat=(await post('chat-v236.php','',{action:'new'})).data;
 check(selectedChat.ok&&newerChat.ok&&newerChat.conversation_id>selectedChat.conversation_id,'prepare an older active Chat and a newer Chat');
 const activity=await post('chat-v236.php','',{action:'activity',after_id:0,conversation_id:selectedChat.conversation_id});
 check(activity.data.ok&&activity.data.latest_id===0&&activity.data.conversation_id===selectedChat.conversation_id
  &&activity.data.latest_message_id===selectedChat.assistant_message_id,'zero-cursor activity returns messages for the exact open Chat');
 const wrongAgent=await post('chat-v236.php?agent=7','',{action:'activity',conversation_id:selectedChat.conversation_id});
 check(wrongAgent.r.status===400&&!wrongAgent.data.ok,'activity rejects a Chat outside the selected Agent');
 const wrongOwner=await post('chat-v236.php','',{action:'activity',conversation_id:selectedChat.conversation_id},{'X-Fixture-User':'2'});
 check(wrongOwner.r.status===400&&!wrongOwner.data.ok,'activity rejects another owner’s Chat');
 console.log(`CAPABILITY_CHAT_API=PASS (${checks} cases; production typed/voice/stream/legacy endpoints, fixture model)`);
}catch(error){console.error(error);console.error(logs.slice(-4000));throw error;}finally{const exited=child.exitCode!==null||child.signalCode!==null?Promise.resolve():new Promise(r=>child.once('exit',r));child.kill();await exited;fs.rmSync(tmp,{recursive:true,force:true});}
