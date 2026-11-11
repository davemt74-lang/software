import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';

// Real production endpoints, owner authorization, review persistence, domain
// kernels and readback. Only host infrastructure and remote inference are fake.
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-meeting-action-api-'));
fs.cpSync('includes',path.join(tmp,'includes'),{recursive:true});
fs.mkdirSync(path.join(tmp,'api'));
for(const name of ['chat-v236.php','chat-stream-v121.php','chat.php'])
 fs.copyFileSync('api/'+name,path.join(tmp,'api',name));
for(const name of ['agent-runtime-routing-v420.php','ai-settings.php','agent-scheduling-v430.php'])
 fs.writeFileSync(path.join(tmp,'includes',name),'<?php // Controlled host infrastructure.');
const runtimeRouting=fs.readFileSync('includes/agent-runtime-routing-v420.php','utf8');
fs.writeFileSync(path.join(tmp,'includes/agent-runtime-routing-v420.php'),'<?php\n'
 +runtimeRouting.slice(runtimeRouting.indexOf('function vp3_agent_runtime_preference_readonly_v420'),runtimeRouting.indexOf('function vp3_agent_runtime_cloud_state_v420')));
for(const name of ['phone-agent-schema-v1.php','workflow-agent-schema-v1.php','record-agent-schema-v1.php',
 'media-agent-schema-v1.php','app-agent-remote-v1.php','system-app-agent-schema-v1.php','app-operation-schema-v1.php'])
 fs.copyFileSync('tests/fixtures/'+name,path.join(tmp,'includes',name));
const workflowFixture=path.join(tmp,'includes/workflow-agent-schema-v1.php');
fs.writeFileSync(workflowFixture,fs.readFileSync(workflowFixture,'utf8').replace('INSERT OR IGNORE INTO users VALUES','INSERT OR IGNORE INTO users(id,is_active) VALUES'));
fs.copyFileSync('tests/fixtures/meeting-prep-calendar-fixture.php',path.join(tmp,'meeting-prep-calendar-fixture.php'));
fs.copyFileSync('tests/fixtures/meeting-chat-actions-api-state.php',path.join(tmp,'meeting-state.php'));
// The strict permission reader is production code. Supply its real catalog
// functions alongside the general HTTP fixture's lightweight permission facade.
const personal=fs.readFileSync('includes/personal-capabilities-v242.php','utf8');
const catalog=personal.slice(personal.indexOf('function personal_capability_catalog_v242'),personal.indexOf('function personal_capability_seeded_v242'));
let bootstrap=fs.readFileSync('tests/fixtures/capability-chat-bootstrap.php','utf8');
bootstrap=bootstrap.replace("return ['id'=>$id,'is_active'=>1];","return ['id'=>$id,'is_active'=>1,'display_name'=>'Fixture owner','role'=>'member'];");
bootstrap=bootstrap.replace("if($query!=='Help me with that')return 'Fixture model response.';",`if(isset($_SERVER['HTTP_X_FIXTURE_HISTORY_CAPTURE'])){file_put_contents(__DIR__.'/../meeting-model-history.jsonl',json_encode(['case'=>$_SERVER['HTTP_X_FIXTURE_HISTORY_CAPTURE'],'query'=>$query,'history'=>$history],JSON_THROW_ON_ERROR)."\\n",FILE_APPEND);return 'Fixture context-aware discussion.';}
    if($query!=='Help me with that')return 'Fixture model response.';`);
bootstrap=bootstrap.replace("if(str_starts_with($prompt,'VP3_TRANSCRIPT_PLAN_V1')){",`if(str_starts_with($prompt,'VP3_TRANSCRIPT_PLAN_V1')){
        if(isset($_SERVER['HTTP_X_FIXTURE_HISTORY_CAPTURE']))return ['ok'=>true,'answer'=>'{"operation":"none"}'];`);
bootstrap+=`\nif(!defined('STONEFELLOW_PERSONAL_CAPABILITIES_SEED'))define('STONEFELLOW_PERSONAL_CAPABILITIES_SEED','personal_capabilities_seed_v242');\n${catalog}
function user_roles():array{return ['member'=>'Member','admin'=>'Admin'];}
function valid_role(string $role):bool{return isset(user_roles()[$role]);}
function agent_brain_tool_prompt(array $user):string{return 'Fixture Brain tool catalog.';}
function meeting_action_api_schema(PDO $pdo):void{
 if(!column_exists('users','role'))$pdo->exec("ALTER TABLE users ADD COLUMN role TEXT DEFAULT 'member'");
 if(!column_exists('users','display_name'))$pdo->exec("ALTER TABLE users ADD COLUMN display_name TEXT DEFAULT 'Fixture owner'");
 $pdo->exec("CREATE TABLE IF NOT EXISTS permissions(permission_key TEXT);
 CREATE TABLE IF NOT EXISTS role_permissions(role TEXT,permission_key TEXT);
 CREATE TABLE IF NOT EXISTS settings(setting_key TEXT,setting_value TEXT);
 CREATE TABLE IF NOT EXISTS user_account_types(user_id INTEGER,role TEXT,assigned_explicitly_at TEXT);
 CREATE TABLE IF NOT EXISTS agent_compute_preferences(user_id INTEGER PRIMARY KEY,preference TEXT);
 INSERT OR IGNORE INTO agent_compute_preferences(user_id,preference) VALUES(1,'vp3_cloud'),(2,'vp3_cloud');
 CREATE TABLE IF NOT EXISTS agent_chat_archive(id INTEGER,user_agent_id INTEGER);
 CREATE INDEX IF NOT EXISTS idx_agent_archive_agent_created_v410 ON agent_chat_archive(user_agent_id);
 CREATE INDEX IF NOT EXISTS idx_agent_memory_agent_type_v410 ON agent_memory_items(user_agent_id,memory_type);
 CREATE INDEX IF NOT EXISTS idx_agent_memory_agent_occurrence_v410 ON agent_memory_items(user_agent_id,occurrence_count)");
 if(!$pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn()){
  $pdo->exec("INSERT INTO settings VALUES('personal_capabilities_seed_v242','1');
  INSERT INTO permissions VALUES('agent_brain.access');
  INSERT INTO role_permissions VALUES('member','account.access'),('member','chat.access'),('member','agent_brain.access')");
 }
 workflow_agent_fixture_v1($pdo);
 $pdo->exec("UPDATE agent_workflow_runs SET dedupe_key='fixture-run-'||id WHERE dedupe_key=''");
 foreach(['capability_key TEXT','action_key TEXT','action_type TEXT','label TEXT','summary TEXT','attempt_count INTEGER DEFAULT 0','result_summary TEXT','result_json TEXT','error_class TEXT','started_at TEXT','completed_at TEXT','updated_at TEXT'] as $c){
  $name=explode(' ',$c)[0];if(!column_exists('agent_workflow_actions',$name))$pdo->exec('ALTER TABLE agent_workflow_actions ADD COLUMN '.$c);
 }
 foreach(['receipt_key TEXT','result_json TEXT'] as $c){$name=explode(' ',$c)[0];if(!column_exists('agent_workflow_receipts',$name))$pdo->exec('ALTER TABLE agent_workflow_receipts ADD COLUMN '.$c);}
 $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_agent_workflow_owner_dedupe ON agent_workflow_runs(owner_user_id,dedupe_key);
 CREATE UNIQUE INDEX IF NOT EXISTS uq_agent_workflow_action_sequence ON agent_workflow_actions(run_id,sequence_no);
 CREATE UNIQUE INDEX IF NOT EXISTS uq_agent_job_owner_receipt ON agent_workflow_receipts(owner_user_id,receipt_key)');
 if(table_exists('video_meetings')){
  foreach(["description TEXT DEFAULT ''","timezone TEXT DEFAULT 'UTC'","cancelled_at TEXT"] as $c){$name=explode(' ',$c)[0];if(!column_exists('video_meetings',$name))$pdo->exec('ALTER TABLE video_meetings ADD COLUMN '.$c);}
  $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_access_settings(meeting_id INTEGER PRIMARY KEY,guest_access_mode TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
  UPDATE video_meeting_agenda_items SET fingerprint='fixture-agenda-'||id WHERE fingerprint='';
  CREATE UNIQUE INDEX IF NOT EXISTS uq_meeting_agenda_fingerprint ON video_meeting_agenda_items(meeting_id,fingerprint)");
 }
}
meeting_action_api_schema(db());\n`;
fs.writeFileSync(path.join(tmp,'includes/bootstrap.php'),bootstrap);
fs.writeFileSync(path.join(tmp,'includes/ai-stream-v121.php'),`<?php
function ai_v121_stream_chat_response($query,$history,$context,$user,$onDelta){
 $answer=chat_remote_answer($query,$history,$context,$user);$onDelta($answer);
 return ['ok'=>true,'answer'=>$answer,'usage'=>[]];
}`);

const reserve=net.createServer();await new Promise(r=>reserve.listen(0,'127.0.0.1',r));
const port=reserve.address().port;await new Promise(r=>reserve.close(r));
const child=spawn(process.env.VP3_PHP_BINARY||'php',['-S',`127.0.0.1:${port}`,'-t',tmp],{stdio:['ignore','ignore','pipe']});
let logs='';child.stderr.on('data',x=>logs+=x);
const base=`http://127.0.0.1:${port}`;
let checks=0;
let typedConfirmation=null;
function check(ok,label){assert.ok(ok,label);checks++;console.log('PASS '+label.split(': {')[0]);}
const headers={'X-Fixture-Brain-Ready':'1','X-Fixture-Model-Fail':'1','X-Fixture-Owner-Trap':'1'};
async function post(endpoint,message,extra={},overrides={}){
 const requestHeaders=Object.fromEntries(Object.entries({'Content-Type':'application/json',...headers,...overrides}).filter(([,v])=>v!==null));
 const response=await fetch(base+'/api/'+endpoint,{method:'POST',headers:requestHeaders,
  body:JSON.stringify({action:'send',message,csrf_token:'fixture',knowledge_scope:'off',user_agent_id:7,...extra})});
 const body=await response.text();let data;
 try{const events=body.trim().split('\n').map(JSON.parse);data=endpoint.includes('stream')?(events.find(e=>e.type==='done')?.data??events.find(e=>e.type==='error')):events[0];}
 catch{throw Error(body+'\n'+logs.slice(-2500));}
 return {response,data,body};
}
const state=async(action='state')=>await(await fetch(base+'/meeting-state.php?action='+action)).json();
const modelHistory=label=>fs.existsSync(path.join(tmp,'meeting-model-history.jsonl'))
 ?fs.readFileSync(path.join(tmp,'meeting-model-history.jsonl'),'utf8').trim().split('\n').filter(Boolean).map(JSON.parse).find(row=>row.case===label)?.history:null;
try{
 for(let i=0;i<60;i++){try{const r=await fetch(base+'/meeting-state.php');if(r.ok)break;}catch{}await new Promise(r=>setTimeout(r,50));}
 await state('seed');
 for(const [mode,endpoint] of [['typed','chat-v236.php?agent=7'],['spoken','chat-stream-v121.php'],['legacy','chat.php']]){
  const created=await post('chat-v236.php?agent=7','',{action:'new'});
  const args={conversation_id:created.data.conversation_id};
  const prep=await post(endpoint,'Help me prep for my next meeting',args);
  check(prep.data?.private_meeting_prep===true,mode+' begins with the canonical exact meeting preparation: '+prep.body);
  const before=await state();
  const reviewArgs=mode!=='legacy'?{...args,idempotency_key:mode+'-meeting-task-review'}:args;
  const review=await post(endpoint,'Turn that follow-up into a task',reviewArgs);
  const card=review.data?.meeting_action;
  check(card?.status==='approval_pending'&&Number.isInteger(card.plan_id)&&/^[a-f0-9]{64}$/.test(card.plan_hash),mode+' gets a frozen inline action review: '+review.body);
  check((await state()).tasks.length===before.tasks.length,mode+' review does not mutate the task store');
  check(card.owner_user_id===1&&card.agent_id===7&&card.conversation_id===args.conversation_id,
   mode+' review is bound to its actual owner, Agent and conversation');
  if(mode==='typed'){
   const repeat=await post(endpoint,'Turn that follow-up into a task',reviewArgs);
   check(repeat.data?.replayed===true&&repeat.data.assistant_message_id===review.data.assistant_message_id
    &&repeat.data.meeting_action?.plan_id===card.plan_id,'a repeated typed request returns the original review without creating another plan');
  }
  if(mode==='spoken'){
   const replay=await post(endpoint,'Turn that follow-up into a task',reviewArgs);
   check(replay.data?.replayed===true&&replay.data.assistant_message_id===review.data.assistant_message_id
    &&replay.data.meeting_action?.plan_id===card.plan_id,'a lost spoken review response replays the same frozen plan');
   const wrongReplay=await post(endpoint,'Cancel the meeting',reviewArgs);
   check(wrongReplay.data?.type==='error'||wrongReplay.response.status>=400,'a spoken review idempotency key cannot authorize another request');
  }
  const approveMessage=mode==='legacy'?card.confirmation_text:'Yes';
  const approveArgs=mode==='typed'?{...args,idempotency_key:'typed-meeting-task-confirm'}:args;
  const approved=await post(endpoint,approveMessage,approveArgs);
  check(approved.data?.meeting_action?.status==='completed',mode+' confirms the same reviewed task through the real endpoint: '+approved.body);
  const after=await state();
  const task=after.tasks.at(-1);
  check(after.tasks.length===before.tasks.length+1&&task.user_id===1&&task.user_agent_id===7
   &&task.subject==='Assign an owner for the revised delivery plan',mode+' saves one exact canonical Brain task with the correct owner and Agent');
  check(after.receipts.length===before.receipts.length+1,mode+' commits a completed canonical workflow receipt');
  if(mode==='typed')typedConfirmation={args:approveArgs,plan:card,approved:approved.data};
  const retry=await post(endpoint,card.confirmation_text,args);
  check(retry.data?.meeting_action?.status==='completed'&&(await state()).tasks.length===after.tasks.length,
   mode+' repeats a completed confirmation without creating another task: '+retry.body);
  const loaded=await post('chat-v236.php?agent=7','',{...args,action:'load'});
  check(loaded.data?.messages?.some(m=>JSON.parse(m.context_json||'{}').meeting_action?.status==='completed'),
   mode+' completed review remains visible through ordinary Chat history');
  const discussed=await post(endpoint,'Help me with that',args,
   {'X-Fixture-Owner-Trap':null,'X-Fixture-Model-Fail':null,'X-Fixture-History-Capture':mode+'-completed'});
  const completedModelHistory=JSON.stringify(modelHistory(mode+'-completed'));
  check(discussed.data?.ok===true&&completedModelHistory.includes('Assign an owner for the revised delivery plan')
   &&completedModelHistory.includes('completed'),mode+' next conversation model receives the authorized completed task and actual status: '+JSON.stringify({response:discussed.data,history:modelHistory(mode+'-completed')}));
 }
 const afterTypedConversation=await post('chat-v236.php?agent=7','Explain a simple concept',
  {conversation_id:typedConfirmation.args.conversation_id},{'X-Fixture-Owner-Trap':null});
 check(afterTypedConversation.data?.ok===true,'the typed confirmation can be followed by an ordinary conversation turn');
 const beforeTypedReplay=await state();
 const typedReplay=await post('chat-v236.php?agent=7','Yes',typedConfirmation.args);
 check(typedReplay.data?.replayed===true&&typedReplay.data.assistant_message_id===typedConfirmation.approved.assistant_message_id
  &&typedReplay.data.meeting_action?.plan_id===typedConfirmation.plan.plan_id&&(await state()).tasks.length===beforeTypedReplay.tasks.length,
  'a lost typed plain-yes confirmation replays its original result after an unrelated turn: '+typedReplay.body);
 await fetch(base+'/meeting-state.php?action=mutate_approval&message='+typedConfirmation.approved.user_message_id);
 const beforeTamper=await state();
 const tampered=await post('chat-v236.php?agent=7',typedConfirmation.plan.confirmation_text,{conversation_id:typedConfirmation.args.conversation_id});
 check((tampered.response.status>=400||tampered.data?.meeting_action?.status==='stale')&&(await state()).tasks.length===beforeTamper.tasks.length,
  'a changed canonical approval message invalidates its completed receipt: '+tampered.body);
 const localChat=(await post('chat-v236.php?agent=7','',{action:'new'})).data.conversation_id;
 const localArgs={conversation_id:localChat};
 await post('chat-v236.php?agent=7','Help me prep for my next meeting',localArgs);
 const localReview=await post('chat-v236.php?agent=7','Turn that follow-up into a task',localArgs);
 check(localReview.data?.meeting_action?.status==='approval_pending','local-only processing test starts from a real reviewed follow-up');
 await state('compute_local');
 const privateDiscussion=await post('chat-v236.php?agent=7','Help me with that',localArgs,
  {'X-Fixture-Owner-Trap':null,'X-Fixture-Model-Fail':null,'X-Fixture-History-Capture':'local-only'});
 const localModelHistory=JSON.stringify(modelHistory('local-only')??null);
 check(privateDiscussion.data?.ok===true&&!localModelHistory.includes('Assign an owner for the revised delivery plan')
  &&localModelHistory.includes('unavailable to Cloud AI processing'),
  'HomeServer-only meeting policy excludes private reviewed details from the Cloud conversation model: '+privateDiscussion.body);
 const privateTranscript=await post('chat-v236.php?agent=7','Read the selected transcript',localArgs,
  {'X-Fixture-Owner-Trap':null,'X-Fixture-Model-Fail':null,'X-Fixture-History-Capture':'local-only-transcript'});
 check(privateTranscript.data?.tool_receipt?.capability==='transcripts.read'
  &&privateTranscript.data.tool_receipt.status!=='read_complete'&&!modelHistory('local-only-transcript')
  &&!privateTranscript.body.includes('The revised delivery plan needs an owner by Friday.'),
  'an explicit read of the HomeServer-only transcript remains protected: '+privateTranscript.body);
 await state('compute_cloud');
 const cancellationChat=(await post('chat-v236.php?agent=7','',{action:'new'})).data.conversation_id;
 const cancellationArgs={conversation_id:cancellationChat};
 const crossChatReview=await post('chat-v236.php?agent=7','Turn that follow-up into a task',cancellationArgs);
 check(crossChatReview.data?.meeting_action?.status==='approval_pending','a new Chat uses the most recent exact prep from the same owner and Agent: '+crossChatReview.body);
 const beforeCancel=await state();
 const cancelled=await post('chat-v236.php?agent=7','Cancel meeting action '+crossChatReview.data.meeting_action.plan_id,cancellationArgs);
 check(cancelled.data?.meeting_action?.status==='cancelled'&&(await state()).tasks.length===beforeCancel.tasks.length,
  'cancelling a reviewed meeting task leaves the canonical task store unchanged');
 const cancelledRetry=await post('chat-stream-v121.php',crossChatReview.data.meeting_action.confirmation_text,cancellationArgs);
 check(cancelledRetry.data?.meeting_action?.status==='cancelled'&&(await state()).tasks.length===beforeCancel.tasks.length,
  'a cancelled review cannot later create the task');
 const reminderReview=await post('chat-stream-v121.php','Remind me 15 minutes before the meeting',cancellationArgs);
 check(reminderReview.data?.meeting_action?.status==='approval_pending','spoken Chat prepares an exact meeting-relative reminder: '+reminderReview.body);
 const beforeReminder=await state();
 const reminder=await post('chat-v236.php?agent=7',reminderReview.data.meeting_action.confirmation_text,cancellationArgs);
 check(reminder.data?.meeting_action?.status==='completed','typed confirmation applies the same spoken reminder review: '+reminder.body);
 const afterReminder=await state();
 check(afterReminder.reminders.length===beforeReminder.reminders.length+1
  &&afterReminder.reminders.at(-1).owner_user_id===1&&afterReminder.reminders.at(-1).conversation_id===cancellationChat
  &&afterReminder.reminders.at(-1).title==='Meeting: Delivery review',
  'the meeting reminder is saved in the existing canonical reminder scheduler');
 const reminderRetry=await post('chat.php',reminderReview.data.meeting_action.confirmation_text,cancellationArgs);
 check(reminderRetry.data?.meeting_action?.status==='completed'&&(await state()).reminders.length===afterReminder.reminders.length,
  'a legacy Chat retry reads the saved reminder receipt without another occurrence');
 const interruptedReview=await post('chat-v236.php?agent=7','Turn that follow-up into a task',cancellationArgs);
 check(interruptedReview.data?.meeting_action?.status==='approval_pending','prepare a review before an unrelated turn');
 const unrelated=await post('chat-v236.php?agent=7','Explain a simple concept',cancellationArgs,{'X-Fixture-Owner-Trap':null});
 check(unrelated.data?.ok===true,'an unrelated normal conversation turn completes');
 const beforeInterrupted=await state();
 const interruptedConfirm=await post('chat-v236.php?agent=7',interruptedReview.data.meeting_action.confirmation_text,cancellationArgs);
 check(interruptedConfirm.response.status>=400&&(await state()).tasks.length===beforeInterrupted.tasks.length,
  'a previous review cannot be confirmed after a different assistant question');
 const agendaReview=await post('chat-v236.php?agent=7','Add Review implementation owners to the meeting agenda',cancellationArgs);
 check(agendaReview.data?.meeting_action?.status==='approval_pending','a concrete agenda addition gets an inline review: '+agendaReview.body);
 const beforeAgenda=await state();
 const agendaSaved=await post('chat-stream-v121.php',agendaReview.data.meeting_action.confirmation_text,cancellationArgs);
 check(agendaSaved.data?.meeting_action?.status==='completed','spoken confirmation adds the exact item through the existing meeting agenda service: '+agendaSaved.body);
 const afterAgenda=await state();
 check(afterAgenda.agenda.length===beforeAgenda.agenda.length+1&&afterAgenda.agenda.at(-1).item_text==='Review implementation owners'
  &&afterAgenda.agenda.at(-1).item_type==='discussion','the actual meeting agenda contains exactly the reviewed new item');
 const staleChat=(await post('chat-v236.php?agent=7','',{action:'new'})).data.conversation_id;
 const staleArgs={conversation_id:staleChat};
 await post('chat-v236.php?agent=7','Help me prep for my next meeting',staleArgs);
 const staleReview=await post('chat-v236.php?agent=7','Turn that follow-up into a task',staleArgs);
 check(staleReview.data?.meeting_action?.status==='approval_pending','prepare a source-bound task for stale-source checks: '+staleReview.body);
 const beforeStale=await state();await state('edit');
 const staleConfirm=await post('chat-v236.php?agent=7',staleReview.data.meeting_action.confirmation_text,staleArgs);
 check((staleConfirm.response.status>=400||staleConfirm.data?.meeting_action?.status==='stale')&&(await state()).tasks.length===beforeStale.tasks.length,
  'editing the exact source after review prevents the old approval from creating a task: '+staleConfirm.body);
 await post('chat-v236.php?agent=7','Help me prep for my next meeting',staleArgs);
 const fresh=await post('chat-v236.php?agent=7','Turn that follow-up into a task',staleArgs);
 check(fresh.data?.meeting_action?.status==='approval_pending','prepare current source after the edit: '+fresh.body);
 const beforeRevoke=await state();await state('revoke');
 const revoked=await post('chat-stream-v121.php',fresh.data.meeting_action.confirmation_text,staleArgs);
 check((revoked.response.status>=400||revoked.data?.type==='error'||revoked.data?.meeting_action?.status==='stale')
  &&(await state()).tasks.length===beforeRevoke.tasks.length,'revoked meeting data permission prevents approval in spoken Chat: '+revoked.body);
 const cancelledRevoked=await post('chat-v236.php?agent=7','',{...cancellationArgs,action:'load'});
 const cancelledRow=cancelledRevoked.data?.messages?.find(m=>Number(m.id)===crossChatReview.data.assistant_message_id);
 const cancelledContext=cancelledRow?JSON.parse(cancelledRow.context_json||'{}'):{};
 check(cancelledRow&&!JSON.stringify(cancelledRow).includes('Assign an owner for the revised delivery plan')
  &&(!cancelledContext.meeting_action||cancelledContext.meeting_action.status==='stale'&&cancelledContext.meeting_action.details?.length===0),
  'cancelled action history withdraws private source details after access revocation');
 const revokedDiscussion=await post('chat-stream-v121.php','Help me with that',staleArgs,
  {'X-Fixture-Owner-Trap':null,'X-Fixture-Model-Fail':null,'X-Fixture-History-Capture':'revoked'});
 check(revokedDiscussion.data?.ok===true&&!JSON.stringify(modelHistory('revoked')).includes('Updated follow-up after review'),
  'revoked source details are excluded from the next spoken conversation model request');
 await state('restore');
 const wrongAgent=await post('chat-v236.php?agent=8',fresh.data.meeting_action.confirmation_text,{...staleArgs,user_agent_id:8});
 const wrongOwner=await post('chat-v236.php?agent=7',fresh.data.meeting_action.confirmation_text,staleArgs,{'X-Fixture-User':'2'});
 check(wrongAgent.response.status>=400&&wrongOwner.response.status>=400&&(await state()).tasks.length===beforeRevoke.tasks.length,
  'an action cannot cross owner or Agent conversation scope');
 await post('chat-v236.php?agent=7','Help me prep for my next meeting',staleArgs);
 const tomorrow=new Date(Date.now()+86400000).toISOString().slice(0,10);
 const beforeNotices=await state();
 const noticeRequest=await post('chat-v236.php?agent=7',`Move the meeting to ${tomorrow} at 13:00 UTC and notify attendees`,staleArgs);
 const afterNotices=await state();
 check(!noticeRequest.data?.meeting_action&&/meeting editor/i.test(noticeRequest.data?.answer||'')
  &&afterNotices.meeting.start_at_utc===beforeNotices.meeting.start_at_utc,'a request requiring outbound attendee notices cannot silently make only a local meeting change');
 const moveReview=await post('chat-v236.php?agent=7',`Move the meeting to ${tomorrow} at 13:00 UTC`,staleArgs);
 check(moveReview.data?.meeting_action?.status==='approval_pending','rescheduling the exact one-time meeting gets a frozen review: '+moveReview.body);
 const moveSaved=await post('chat-stream-v121.php',moveReview.data.meeting_action.confirmation_text,staleArgs);
 check(moveSaved.data?.meeting_action?.status==='completed','spoken confirmation uses the native Calendar and video meeting sync: '+moveSaved.body);
 const moved=await state();
 check(moved.meeting.start_at_utc===tomorrow+' 13:00:00'&&moved.meeting.start_at_utc===moved.calendar.start_at_utc
  &&moved.meeting.end_at_utc===moved.calendar.end_at_utc,'the linked Calendar and meeting retain the same verified rescheduled times');
 const cancelMeetingReview=await post('chat.php','Cancel meeting #7912',staleArgs);
 check(cancelMeetingReview.data?.meeting_action?.status==='approval_pending','legacy Chat reviews cancellation of the exact current meeting: '+cancelMeetingReview.body);
 const cancelMeetingSaved=await post('chat-v236.php?agent=7',cancelMeetingReview.data.meeting_action.confirmation_text,staleArgs);
 const cancelledMeeting=await state();
 check(cancelMeetingSaved.data?.meeting_action?.status==='completed'&&cancelledMeeting.meeting.status==='cancelled'
  &&cancelledMeeting.calendar.status==='cancelled','typed confirmation cancels the linked native meeting and Calendar together: '+cancelMeetingSaved.body);
 console.log(`MEETING_CHAT_ACTION_API=PASS (${checks} cases; production typed, spoken and legacy endpoints)`);
}catch(error){console.error(logs.slice(-5000));throw error;}
finally{const exited=child.exitCode!==null||child.signalCode!==null?Promise.resolve():new Promise(r=>child.once('exit',r));child.kill();await exited;fs.rmSync(tmp,{recursive:true,force:true});}
