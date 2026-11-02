import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-capability-chat-'));
fs.cpSync('includes',path.join(tmp,'includes'),{recursive:true});fs.mkdirSync(path.join(tmp,'api'));
for(const name of ['chat-v236.php','chat-stream-v121.php','chat.php','task-notes-v1.php','participant-updates-v1.php'])fs.copyFileSync('api/'+name,path.join(tmp,'api',name));
for(const name of ['agent-runtime-routing-v420.php','ai-settings.php'])fs.writeFileSync(path.join(tmp,'includes',name),'<?php // Controlled infrastructure fixture; implementation supplied by fixture bootstrap.');
fs.copyFileSync('tests/fixtures/capability-chat-bootstrap.php',path.join(tmp,'includes/bootstrap.php'));
fs.copyFileSync('tests/fixtures/phone-agent-schema-v1.php',path.join(tmp,'includes/phone-agent-schema-v1.php'));
fs.copyFileSync('tests/fixtures/workflow-agent-schema-v1.php',path.join(tmp,'includes/workflow-agent-schema-v1.php'));
fs.copyFileSync('tests/fixtures/record-agent-schema-v1.php',path.join(tmp,'includes/record-agent-schema-v1.php'));
fs.copyFileSync('tests/fixtures/media-agent-schema-v1.php',path.join(tmp,'includes/media-agent-schema-v1.php'));
fs.copyFileSync('tests/fixtures/app-agent-remote-v1.php',path.join(tmp,'includes/app-agent-remote-v1.php'));
fs.copyFileSync('tests/fixtures/system-app-agent-schema-v1.php',path.join(tmp,'includes/system-app-agent-schema-v1.php'));
fs.copyFileSync('tests/fixtures/app-operation-schema-v1.php',path.join(tmp,'includes/app-operation-schema-v1.php'));
fs.writeFileSync(path.join(tmp,'setup.php'),`<?php require __DIR__.'/includes/bootstrap.php';$pdo=db();header('Content-Type: application/json');vp3_participant_updates_schema_v1($pdo);
$pdo->exec("CREATE TABLE IF NOT EXISTS studio_participants(id INTEGER PRIMARY KEY,owner_user_id INTEGER,display_name TEXT,is_active INTEGER DEFAULT 1,recognition_consent INTEGER DEFAULT 1,cloning_consent INTEGER DEFAULT 0,linked_user_id INTEGER,relationship_scope TEXT,profile_key TEXT,recognition_scope TEXT DEFAULT 'private'); INSERT OR IGNORE INTO studio_participants(id,owner_user_id,display_name,linked_user_id,relationship_scope,profile_key) VALUES(701,1,'David',1,'self','david'),(702,1,'Alex',2,'contact','alex'),(703,2,'Foreign',2,'self','foreign'); CREATE TABLE IF NOT EXISTS studio_participant_voices(participant_id INTEGER,provider TEXT,recognition_enabled INTEGER,clone_enabled INTEGER,recognition_verified INTEGER,clone_verified INTEGER,recognition_provider_speaker_id TEXT,clone_provider_voice_id TEXT); INSERT OR IGNORE INTO agent_task_lists_v1(id,owner_user_id,agent_scope_id,name,name_key,created_at) VALUES(601,1,7,'Prep','b10b18f906fcd1ca87847667a5011cd5974171625d4173caf48507d49d5d68ee','2026-11-02'),(602,1,7,'PRIVATE owner budget','e58ba5ff2d81250ecbf1cbfecd6c237a301a2b58ab331ca54d9d053ae337bfe7','2026-11-02');INSERT OR IGNORE INTO agent_task_list_items_v1(list_id,task_id,item_key,created_at) VALUES(601,210,'pizza','2026-11-02'),(602,220,'private','2026-11-02'); UPDATE agent_memory_items SET subject='PRIVATE-only owner task' WHERE id=220;");
echo json_encode(['ok'=>true]);`);
fs.writeFileSync(path.join(tmp,'state.php'),`<?php require __DIR__.'/includes/bootstrap.php';header('Content-Type: application/json');echo json_encode(['prompt'=>is_file(__DIR__.'/participant-prompt.txt')?file_get_contents(__DIR__.'/participant-prompt.txt'):'','tasks'=>db()->query('SELECT id,subject,metadata_json FROM agent_memory_items')->fetchAll()]);`);
const reserve=net.createServer();await new Promise(r=>reserve.listen(0,'127.0.0.1',r));const port=reserve.address().port;await new Promise(r=>reserve.close(r));
const child=spawn(process.env.VP3_PHP_BINARY||'php',['-S',`127.0.0.1:${port}`,'-t',tmp],{stdio:['ignore','ignore','pipe']});let logs='';child.stderr.on('data',x=>logs+=x);
const base=`http://127.0.0.1:${port}`;let checks=0;
const headers={'Content-Type':'application/json','X-Fixture-Brain-Ready':'1','X-Fixture-Record-Ready':'1'};
async function post(endpoint,body,extra={}){const r=await fetch(base+'/api/'+endpoint,{method:'POST',headers:{...headers,...extra},body:JSON.stringify({csrf_token:'fixture',agent_id:7,...body})});const text=await r.text();let data;try{data=endpoint.includes('stream')?text.trim().split('\n').map(x=>JSON.parse(x)).find(x=>x.type==='done')?.data:JSON.parse(text);}catch{throw Error(text+'\n'+logs.slice(-1200));}return {status:r.status,data,text};}
const check=(ok,why)=>{assert.ok(ok,why);checks++;console.log('PASS '+why);};
try{
 for(let i=0;i<60;i++){try{await fetch(base+'/state.php',{headers});break;}catch{await new Promise(r=>setTimeout(r,50));}}
 let r=await post('participant-updates-v1.php',{action:'settings'});check(r.status===409,'new schema is installed only by upgrade');
 await fetch(base+'/setup.php',{headers});
 r=await post('participant-updates-v1.php',{action:'settings'});check(r.status===200&&!r.data.settings.enabled&&r.data.profiles.length===2,'real settings endpoint returns owned profiles and opt-in defaults');
 let revision=r.data.settings.revision;
 const configure={action:'configure',enabled:true,offer_updates:true,self_participant_id:701,participant_id:702,grants:[{kind:'task_list',id:601,access:'edit'}]};
 r=await post('participant-updates-v1.php',{...configure,revision});check(r.status===200,'explicit owner settings and selected shared list persist');revision=r.data.settings.revision;
 r=await post('participant-updates-v1.php',{action:'show',participant_id:702});check(r.data.updates.lists.length===1&&!r.text.includes('PRIVATE')&&r.data.updates.tasks.length===1,'shared update endpoint excludes private records');
 r=await post('participant-updates-v1.php',{action:'show',participant_id:703});check(r.status===403,'foreign owner participant denied');
 r=await post('participant-updates-v1.php',{action:'show',participant_id:702,agent_id:8});check(r.status===200&&!r.data.updates.lists.length,'other Agent cannot inherit grants');
 r=await post('task-notes-v1.php',{note:{kind:'task_list',id:601,participant_id:702}});check(r.status===200&&r.data.note.read_only&&r.data.note.items.length===1,'shared sticky note rehydrates canonical records as read only');
 r=await post('task-notes-v1.php',{note:{kind:'task_list',id:602,participant_id:702}});check(r.status===403&&!r.text.includes('PRIVATE'),'private sticky note cannot be fetched in guest scope');
 r=await post('participant-updates-v1.php',{...configure,revision,grants:[{kind:'task_list',id:999,access:'edit'}]});check(r.status===403,'forged resource grant cannot be saved');
 const guest={participant_id:702,source:'local_voice_similarity',confidence:.96,authentication_authority:false};
 for(const endpoint of ['chat.php','chat-v236.php?agent=7','chat-stream-v121.php?agent=7']){
  r=await post(endpoint,{action:'send',message:'Hello Longfellow, show my updates',agent_id:7,input_mode:'voice',knowledge_scope:'off',agent_context:{user_agent_id:7,voice_speaker:guest}},{'X-Fixture-Owner-Trap':'1'});
  check(r.status===200&&r.data?.answer?.includes('Fixture pizza task 210')&&!r.text.includes('PRIVATE')&&r.data.sticky_notes[0].participant_id===702,endpoint+' resolves guest before owner calendar, Brain, history or Knowledge');
  r=await post(endpoint,{action:'send',message:'Show my tasks',agent_id:7,input_mode:'voice',knowledge_scope:'off',agent_context:{user_agent_id:7}},{'X-Fixture-Owner-Trap':'1'});check(r.status===200&&r.data?.answer?.includes('Who is speaking'),endpoint+' blocks unknown voice before owner retrieval');
 }
 const state=await (await fetch(base+'/state.php',{headers})).json();check(!state.prompt.includes('PRIVATE')&&!state.prompt.includes('SECRET'),'real interpreter prompt excludes owner private records and other Agent records');
 r=await post('chat.php',{action:'send',message:'Add sauce to prep',agent_id:7,input_mode:'voice',knowledge_scope:'off',agent_context:{user_agent_id:7,voice_speaker:guest}},{'X-Fixture-Participant-Plan':'{"operation":"add","id":601,"title":"Sauce"}'});check(r.data.tool_receipt?.changes_applied,'guest voice creates a canonical task only in editable shared list');
 r=await post('participant-updates-v1.php',{...configure,revision,grants:[]});revision=r.data.settings.revision;
 r=await post('task-notes-v1.php',{note:{kind:'task_list',id:601,participant_id:702}});check(r.status===403,'refresh after revocation cannot return a cached shared note');
 r=await post('participant-updates-v1.php',{action:'settings',csrf_token:'bad'});check(r.status===419,'settings rejects invalid CSRF');
 r=await post('participant-updates-v1.php',{action:'settings'},{'X-Fixture-Anonymous':'1'});check(r.status===401,'settings rejects anonymous requests');
 r=await post('participant-updates-v1.php',{action:'settings'},{'X-Fixture-Denied':'1'});check(r.status===403,'live permission denial blocks sharing reads');
 check((await fetch(base+'/api/participant-updates-v1.php',{headers})).status===405,'settings is POST only');
 console.log(`PARTICIPANT_UPDATES_API=PASS (${checks} cases; production endpoints and canonical persistence, fixture infrastructure/model)`);
}finally{child.kill();fs.rmSync(tmp,{recursive:true,force:true});}
