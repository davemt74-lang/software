import assert from 'node:assert/strict';
import {createHmac} from 'node:crypto';
import {spawn} from 'node:child_process';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import {chromium} from 'playwright';

const root=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-chat-read-api-'));
const php=process.env.VP3_PHP_BINARY||'php';
const binding=(owner=1,session='read-owner-a')=>createHmac('sha256','fixture')
  .update(`working-attention|${owner}|${session}`).digest('hex');
fs.cpSync('includes',path.join(root,'includes'),{recursive:true});
fs.mkdirSync(path.join(root,'api'));
fs.copyFileSync('api/agent-chat-read-v1.php',path.join(root,'api/agent-chat-read-v1.php'));
for(const name of ['image-generation-v1.php','workflow-agent-schema-v1.php']){
  fs.copyFileSync('tests/fixtures/'+name,path.join(root,'includes',name));
}
fs.writeFileSync(path.join(root,'includes/bootstrap.php'),`<?php
session_id($_SERVER['HTTP_X_FIXTURE_SESSION']??'read-owner-a');
require __DIR__.'/image-generation-v1.php';
require_once __DIR__.'/agent-chat-read-v1.php';
function vp3_participant_scope_v1(): ?array{return isset($_SERVER['HTTP_X_FIXTURE_PARTICIPANT'])?['participant_id'=>3]:null;}
`);
fs.writeFileSync(path.join(root,'fixture.php'),`<?php
require __DIR__.'/includes/bootstrap.php';
if(($_GET['action']??'')==='init'){
  db()->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id) VALUES (42,1,NULL),(43,2,NULL);
    INSERT INTO chat_messages(id,conversation_id,role,message) VALUES
      (1,42,'assistant','Historical reply'),(2,42,'assistant','')");
  vp3_chat_read_ensure_schema_v1(db());
  db()->exec("INSERT INTO chat_messages(id,conversation_id,role,message) VALUES
    (3,42,'assistant','Typed reply'),(4,42,'assistant','Worker report'),(5,42,'assistant','Profile post'),
    (6,42,'user','Owner turn'),(7,42,'system','Internal placeholder'),(8,43,'assistant','Foreign owner reply'),
    (9,11,'assistant','Named Agent reply')");
}elseif(($_GET['action']??'')==='finish'){
  db()->exec("UPDATE chat_messages SET message='Completed stream' WHERE id=2");
}elseif(($_GET['action']??'')==='upgrade'){
  vp3_chat_read_ensure_schema_v1(db());
}
header('Content-Type: application/json');
echo json_encode(['receipts'=>table_exists('agent_chat_read_receipts_v1')
  ?(int)db()->query('SELECT COUNT(*) FROM agent_chat_read_receipts_v1')->fetchColumn():0]);
`);
const listener=net.createServer();
await new Promise(resolve=>listener.listen(0,'127.0.0.1',resolve));
const port=listener.address().port;
await new Promise(resolve=>listener.close(resolve));
const server=spawn(php,['-S',`127.0.0.1:${port}`,'-t',root],{
  env:{...process.env,VP3_IMAGE_TEST_DSN:'sqlite:'+path.join(root,'read.sqlite')},
  stdio:['ignore','ignore','pipe']
});
let logs='';server.stderr.on('data',chunk=>logs+=chunk);
const base=`http://127.0.0.1:${port}`;
const request=async({method='GET',ids='3,4,5',agent=0,conversation=42,headers={},body,raw}={})=>{
  const query=new URLSearchParams({agent_id:String(agent),conversation_id:String(conversation),message_ids:ids});
  const response=await fetch(base+'/api/agent-chat-read-v1.php'+(method==='GET'?'?'+query:''),{
    method,headers:{'Content-Type':'application/json','X-VP3-Working-Attention':binding(),...headers},
    ...(method==='POST'?{body:raw??JSON.stringify(body??{
      action:'acknowledge',agent_id:agent,conversation_id:conversation,message_ids:[3],csrf_token:'fixture'
    })}:{})
  });
  const text=await response.text();
  let data;try{data=JSON.parse(text);}catch{throw Error(text+'\n'+logs.slice(-2000));}
  return {status:response.status,data,headers:response.headers};
};
let checks=0;
const check=(condition,message)=>{assert.ok(condition,message);checks++;console.log('PASS '+message);};
try{
  let ready=false;
  for(let attempt=0;attempt<80;attempt++){
    try{const response=await fetch(base+'/fixture.php');if(response.ok){ready=true;break;}}catch{}
    await new Promise(resolve=>setTimeout(resolve,25));
  }
  assert.ok(ready,logs);
  check((await request()).status===503,'the production HTTP API requires the installed read-state schema');
  await fetch(base+'/fixture.php?action=init');
  let result=await request({ids:'1'});
  check(result.status===200&&result.data.unread_message_ids.length===0,'the actual API treats completed historical posts as read');
  result=await request({ids:'5,3,4'});
  check(result.status===200&&JSON.stringify(result.data.message_ids)==='[5,3,4]'
    &&JSON.stringify(result.data.unread_message_ids)==='[5,3,4]','new typed, worker and Profile posts share exact persisted unread state');
  check(result.headers.get('cache-control').includes('no-store'),'private read state is never cacheable');
  const before=await(await fetch(base+'/fixture.php')).json();
  for(const headers of [
    {'X-VP3-Working-Attention':''},
    {'X-Fixture-User':'2','X-Fixture-Session':'read-owner-b'},
    {'X-Fixture-Session':'read-owner-a-rotated'}
  ]){
    check((await request({headers})).status===403&&(await request({method:'POST',headers})).status===403,
      'an absent binding, account switch or rotated session rejects reads and acknowledgements');
  }
  check((await request({method:'POST',body:{action:'acknowledge',agent_id:0,conversation_id:42,message_ids:[3],csrf_token:'wrong'}})).status===419,
    'an acknowledgement requires the current CSRF token');
  const after=await(await fetch(base+'/fixture.php')).json();
  check(after.receipts===before.receipts,'rejected HTTP requests leave every read receipt unchanged');
  result=await request({method:'POST',body:{action:'acknowledge',agent_id:0,conversation_id:42,message_ids:[5],csrf_token:'fixture'}});
  check(result.status===200&&JSON.stringify(result.data.message_ids)==='[5]'&&result.data.unread_message_ids.length===0,
    'an acknowledgement returns only the accepted exact message IDs');
  check(JSON.stringify((await request()).data.unread_message_ids)==='[3,4]','acknowledging a later visible post leaves earlier offscreen posts unread');
  await request({method:'POST',body:{action:'acknowledge',agent_id:0,conversation_id:42,message_ids:[5],csrf_token:'fixture'}});
  check((await(await fetch(base+'/fixture.php')).json()).receipts===before.receipts+1,'another tab replaying an acknowledgement creates no duplicate receipt');
  for(const ids of ['2','6','7','8','9','999']){
    check((await request({ids})).status===422,'the actual API rejects unfinished, user, system, foreign or other-Agent message '+ids);
  }
  check((await request({agent:7})).status===404&&(await request({conversation:11,ids:'9'})).status===404,
    'the exact Agent namespace is required for both System and named Agent Chats');
  check((await request({headers:{'X-Fixture-User':'2','X-Fixture-Session':'read-owner-b','X-VP3-Working-Attention':binding(2,'read-owner-b')}})).status===404,
    'a correctly bound second owner still cannot read the first owner Chat');
  check((await request({headers:{'X-Fixture-Participant':'1'}})).status===403,'a participant scope cannot inherit owner read state');
  check((await request({method:'POST',raw:'{bad json'})).status===422,'malformed JSON is rejected without an acknowledgement');
  check((await request({method:'POST',body:{action:'acknowledge',agent_id:0,conversation_id:42,message_ids:[3,8],csrf_token:'fixture'}})).status===422
    &&JSON.stringify((await request()).data.unread_message_ids)==='[3,4]','a mixed valid and foreign batch fails atomically');
  await fetch(base+'/fixture.php?action=finish');
  check(JSON.stringify((await request({ids:'2'})).data.unread_message_ids)==='[2]',
    'a stream that completes after rollout is unread even when its placeholder had an older ID');
  await fetch(base+'/fixture.php?action=upgrade');
  check(JSON.stringify((await request({ids:'2,3,4,5'})).data.unread_message_ids)==='[2,3,4]',
    'repeated upgrades preserve sparse receipts and future unread posts');

  fs.copyFileSync('chat-message-unread-v1.js',path.join(root,'chat-message-unread-v1.js'));
  fs.writeFileSync(path.join(root,'index.html'),`<!doctype html><meta charset="utf-8"><style>
    body{margin:0;font:16px sans-serif}#chatThread{height:180px;overflow:auto}
    .message{min-height:210px;padding:8px}.message:last-child{min-height:80px}.message-role{height:22px}
    #cover{position:fixed;inset:0;z-index:100;background:white}[hidden]{display:none!important}
  </style><section id="chatThread" data-view="chat">
    <article class="message assistant" data-chat-message-id="3" data-chat-conversation-id="42" data-chat-message-role="assistant">
      <div class="message-role">Agent</div><div class="message-text">Typed reply</div></article>
    <article class="message assistant" data-chat-message-id="4" data-chat-conversation-id="42" data-chat-message-role="assistant">
      <div class="message-role">Agent</div><div class="message-text">Worker report</div></article>
  </section><div id="cover"></div><script>
    window.STONEFELLOW_CHAT={userId:1,agentId:0,csrf:'fixture',workingAttentionBinding:${JSON.stringify(binding())}};
    window.VP3_CHAT_MESSAGE_UNREAD_V1={endpoint:'/api/agent-chat-read-v1.php'};
    window.STONEFELLOW_CHAT_CONTINUITY_V87={isReady:()=>true,isRestoring:()=>false,agentId:()=>0,conversationId:()=>42};
  </script><script src="/chat-message-unread-v1.js"></script>`);
  const browser=await chromium.launch({headless:true,
    ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH?{executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH}:{})});
  try{
    const page=await browser.newPage();
    const errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.goto(base+'/index.html');
    const earlier=page.locator('[data-chat-message-id="3"] [data-chat-message-new]');
    const visible=page.locator('[data-chat-message-id="4"] [data-chat-message-new]');
    await earlier.waitFor();
    await visible.waitFor();
    await page.evaluate(()=>{
      const thread=document.getElementById('chatThread');
      thread.scrollTop=thread.scrollHeight;
      document.getElementById('cover').hidden=true;
    });
    await visible.waitFor({state:'detached'});
    check(await earlier.count()===1&&JSON.stringify((await request({ids:'3,4'})).data.unread_message_ids)==='[3]',
      'the real Chromium badge acknowledges its one visible post through the production PHP API and leaves its offscreen sibling unread');
    check(errors.length===0,'the read widget and production HTTP receipt flow finish without browser errors');
  }finally{await browser.close();}
  console.log(`AGENT_CHAT_READ_API=PASS (${checks} cases; production HTTP endpoint and SQLite receipts)`);
}finally{
  server.kill();
  await new Promise(resolve=>server.once('exit',resolve));
  fs.rmSync(root,{recursive:true,force:true});
}
