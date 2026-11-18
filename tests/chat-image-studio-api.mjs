import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import {spawn} from 'node:child_process';
import {createHmac} from 'node:crypto';
import {fileURLToPath} from 'node:url';

const source = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const root = await fs.mkdtemp(path.join(os.tmpdir(),'vp3-image-studio-http-'));
const probe = net.createServer();
await new Promise(resolve => probe.listen(0,'127.0.0.1',resolve));
const port = probe.address().port;
await new Promise(resolve => probe.close(resolve));
const quote = value => "'" + value.replaceAll('\\','\\\\').replaceAll("'","\\'") + "'";
const endpoint = '/api/chat-image-studio-v1.php';
await fs.mkdir(path.join(root,'api'));
await fs.mkdir(path.join(root,'includes'));
await fs.writeFile(path.join(root,'ready.txt'),'ready');
await fs.copyFile(path.join(source,endpoint.slice(1)),path.join(root,endpoint.slice(1)));
await fs.writeFile(path.join(root,'includes/chat-image-studio-v1.php'),
  `<?php require_once ${quote(path.join(source,'includes/chat-image-studio-v1.php'))};`);
await fs.writeFile(path.join(root,'includes/bootstrap.php'),`<?php
define('STONEFELLOW_ROOT',${quote(root)});
$GLOBALS['imageFixtureFile']=${quote(path.join(root,'fixture.sqlite'))};
require ${quote(path.join(source,'tests/fixtures/image-generation-v1.php'))};
require ${quote(path.join(source,'includes/agent-brain-v82.php'))};
if(!table_exists('agent_image_generations_v1')){
    foreach([[1,7,11,'Current image prompt'],[1,8,12,'OTHER AGENT SECRET'],[2,9,13,'FOREIGN OWNER SECRET']] as [$owner,$agent,$chat,$title]){
        $user=['id'=>$owner,'is_active'=>1];
        $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(?,?,'user',?)")->execute([$chat,$owner,'Create an image']);
        $turn=(int)$pdo->lastInsertId();
        $generation=vp3_image_queue_v1($pdo,$user,['kind'=>'user_agent','owner_user_id'=>$owner,'agent_id'=>$agent],
            array_replace(image_fixture_plan_v1(),['title'=>$title]),['conversation_id'=>$chat,'chat_user_message_id'=>$turn]);
        agent_job_cancel_v1900($pdo,$user,$generation['job_id']);
    }
}
if(isset($_SERVER['HTTP_X_FIXTURE_PARTICIPANT']))$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant'=>8];
`);
const server = spawn(process.env.VP3_PHP_BINARY || process.env.PHP_BINARY || 'php',['-S',`127.0.0.1:${port}`,'-t',root],
  {cwd:source,stdio:['ignore','pipe','pipe']});
let logs = '';
server.stdout.on('data',chunk => { logs += chunk; });
server.stderr.on('data',chunk => { logs += chunk; });
const base = `http://127.0.0.1:${port}`;
const binding = user => createHmac('sha256','fixture').update(`working-attention|${user}|`).digest('hex');
const valid = {action:'history',owner_key:'1',agent_id:7,conversation_id:11,csrf_token:'fixture'};
let assertions = 0;
const check = (actual,expected) => { assert.equal(actual,expected);assertions++; };
async function call({method='POST',headers={},body=valid}={}) {
  const response = await fetch(base+endpoint,{method,headers:{'Content-Type':'application/json',
    'X-VP3-Working-Attention':binding(1),...headers},
    ...(['GET','HEAD'].includes(method)?{}:{body:typeof body==='string'?body:JSON.stringify(body)})});
  const text = await response.text();
  try { return {response,data:JSON.parse(text)}; }
  catch { throw new Error(`Invalid endpoint JSON (${response.status}): ${text.slice(0,500)}\n${logs.slice(-2000)}`); }
}
try {
  let ready = false;
  for (let attempt=0;attempt<60;attempt++) {
    try { if ((await fetch(base+'/ready.txt')).ok) { ready=true;break; } } catch {}
    await new Promise(resolve => setTimeout(resolve,50));
  }
  assert.ok(ready,logs);
  const read = await call();
  check(read.response.status,200);
  check(read.response.headers.get('cache-control'),'private, no-store');
  check(read.data.ok,true);
  check(read.data.state.contract,'vp3.image-studio.v1');
  check(read.data.state.owner_user_id,1);
  check(read.data.state.agent_id,7);
  check(read.data.state.conversation_id,11);
  check(read.data.state.working_attention_binding,binding(1));
  check(read.data.state.generations.length,1);
  check(read.data.state.generations[0].title,'Current image prompt');
  check(read.data.state.generations[0].count,1);
  check(read.data.state.generations[0].completed_count,0);
  check(read.data.state.brainstorm,null);
  check(JSON.stringify(read.data).includes('SECRET'),false);
  check(read.data.state.sources.some(item => Object.hasOwn(item,'text')),false);
  check((await call({headers:{'X-Fixture-Anonymous':'1'}})).response.status,401);
  check((await call({headers:{'X-Fixture-User':'2'}})).response.status,403);
  check((await call({headers:{'X-Fixture-User':'2','X-VP3-Working-Attention':binding(2)},body:{...valid,owner_key:'2'}})).response.status,403);
  check((await call({headers:{'X-Fixture-Participant':'1'}})).response.status,403);
  check((await call({headers:{'X-Fixture-Denied':'1'}})).response.status,403);
  check((await call({body:{...valid,owner_key:'2'}})).response.status,403);
  check((await call({body:{...valid,agent_id:8}})).response.status,403);
  check((await call({body:{...valid,conversation_id:12}})).response.status,403);
  check((await call({headers:{'X-VP3-Working-Attention':''}})).response.status,403);
  check((await call({body:{...valid,csrf_token:'different'}})).response.status,419);
  const brainstorm = {...valid,action:'brainstorm',request_id:'a'.repeat(32),source_revision:'b'.repeat(64),brief:'Develop a cover'};
  check((await call({body:brainstorm})).response.status,409);
  for (const body of [
    {...valid,conversation_id:0},{...valid,agent_id:-1},{...valid,agent_id:'7'},
    {...valid,before:0},{...valid,before:'63'},{...valid,foreign_owner:2},
    {...valid,action:'generate'},{...brainstorm,request_id:'invalid'},
    {...brainstorm,brief:'x'.repeat(501)},{...brainstorm,source_revision:'invalid'},
    {...brainstorm,brief:1},'{invalid',
  ]) check((await call({body})).response.status,422);
  check((await call({body:' '.repeat(8193)})).response.status,413);
  check((await call({method:'GET'})).response.status,405);
  check((await call({method:'DELETE'})).response.status,405);
  const again = await call();
  check(again.data.state.source_revision,read.data.state.source_revision);
  check(again.data.state.brainstorm,null);
  check(again.data.state.generations.length,1);
  console.log(`IMAGE_STUDIO_API=PASS (${assertions} assertions through the actual authenticated endpoint)`);
} finally {
  server.kill('SIGTERM');
  await fs.rm(root,{recursive:true,force:true});
}
