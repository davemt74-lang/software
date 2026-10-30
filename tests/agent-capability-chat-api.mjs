import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-capability-chat-'));
fs.cpSync('includes',path.join(tmp,'includes'),{recursive:true});fs.mkdirSync(path.join(tmp,'api'));
for(const name of ['chat-v236.php','chat-stream-v121.php','chat.php'])fs.copyFileSync('api/'+name,path.join(tmp,'api',name));
for(const name of ['user-agent-system-v236.php','agent-runtime-routing-v420.php','ai-settings.php'])fs.writeFileSync(path.join(tmp,'includes',name),'<?php // Controlled infrastructure fixture; implementation supplied by fixture bootstrap.');
fs.copyFileSync('tests/fixtures/capability-chat-bootstrap.php',path.join(tmp,'includes/bootstrap.php'));
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
 response=await post('chat-stream-v121.php','Is HomeServer connected?');check(response.data?.ok===true&&response.data.answer.includes('HomeServer is not paired'),'streaming owner Chat performs authenticated HomeServer inspection: '+response.body);
 const before=await state();response=await post('chat-v236.php','Can you create reminders?',{conversation_id:1},{'X-Fixture-User':'2'});check(response.data?.ok===false&&(await state()).messages===before.messages,'foreign conversation fails before discovery or message insertion');
 response=await post('chat-v236.php','Can you create reminders?',{csrf_token:'wrong'});check(response.r.status===419,'existing CSRF boundary remains active');
 response=await post('chat-v236.php','Can you create reminders?',{}, {'X-Fixture-Denied':'1'});check(response.r.status===403,'existing account permission boundary remains active');
 console.log(`CAPABILITY_CHAT_API=PASS (${checks} cases; production typed/voice/stream/legacy endpoints, fixture model)`);
}finally{child.kill();await new Promise(r=>child.once('exit',r));fs.rmSync(tmp,{recursive:true,force:true});}
