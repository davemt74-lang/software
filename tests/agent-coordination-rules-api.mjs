import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import {spawn} from 'node:child_process';
import {createHmac} from 'node:crypto';
import {fileURLToPath} from 'node:url';

const source = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const root = await fs.mkdtemp(path.join(os.tmpdir(),'vp3-coordination-rules-http-'));
const probe = net.createServer();
await new Promise(resolve => probe.listen(0,'127.0.0.1',resolve));
const port = probe.address().port;
await new Promise(resolve => probe.close(resolve));
const quoted = value => "'" + value.replaceAll("\\","\\\\").replaceAll("'","\\'") + "'";
const endpoint = '/api/agent-coordination-rules-v1.php';
await fs.mkdir(path.join(root,'api'));
await fs.mkdir(path.join(root,'includes'));
await fs.writeFile(path.join(root,'ready.txt'),'ready');
await fs.copyFile(path.join(source,endpoint.slice(1)),path.join(root,endpoint.slice(1)));
for (const file of ['agent-coordination-rules-v1.php','agent-catch-up-v1.php']) {
  await fs.writeFile(path.join(root,'includes',file),`<?php require_once ${quoted(path.join(source,'includes',file))};`);
}
await fs.writeFile(path.join(root,'includes/bootstrap.php'),`<?php
define('VP3_EMAIL_CANVAS_PIPELINE_FIXTURE',true);
ob_start();try{require ${quoted(path.join(source,'tests/agent-initiative-canvas-pipeline-v1.php'))};}finally{ob_end_clean();}
if(isset($_SERVER['HTTP_X_FIXTURE_PARTICIPANT']))$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant'=>8];
`);
const server = spawn(process.env.PHP_BINARY || 'php',['-S',`127.0.0.1:${port}`,'-t',root],{cwd:source,stdio:['ignore','pipe','pipe']});
let logs = '';
server.stdout.on('data',chunk=>{logs+=chunk;});
server.stderr.on('data',chunk=>{logs+=chunk;});
const base = `http://127.0.0.1:${port}`;
const binding = user => createHmac('sha256','fixture').update(`working-attention|${user}|`).digest('hex');
let assertions = 0;
const check = (actual, expected) => { assert.equal(actual,expected); assertions++; };
async function call({method='GET',agent=7,chat=11,headers={},body}={}) {
  const query = method === 'GET' ? `?agent_id=${agent}&conversation_id=${chat}` : '';
  const response = await fetch(base+endpoint+query,{method,headers:{
    'Content-Type':'application/json','X-VP3-Working-Attention':binding(1),...headers,
  },...(body === undefined ? {} : {body:typeof body==='string'?body:JSON.stringify(body)})});
  const text = await response.text();
  try { return {response,data:JSON.parse(text)}; }
  catch { throw new Error(`Invalid endpoint JSON (${response.status}): ${text.slice(0,500)}\n${logs.slice(-2000)}`); }
}
try {
  let ready = false;
  for (let attempt=0;attempt<60;attempt++) {
    try { if ((await fetch(base+'/ready.txt')).ok) { ready=true;break; } } catch {}
    await new Promise(resolve=>setTimeout(resolve,50));
  }
  assert.ok(ready,logs);
  const read = await call();
  check(read.response.status,200);
  check(read.response.headers.get('cache-control'),'no-store');
  check(read.data.ok,true);
  check(read.data.state.owner_user_id,1);
  check(read.data.state.agent_id,7);
  check(read.data.state.conversation_id,11);
  check(read.data.state.rules.planning.pending_confirmation,'ask');
  const valid = {agent_id:7,conversation_id:11,csrf_token:'fixture',revision:read.data.state.revision,
    rules:{planning:{pending_confirmation:'watch'}}};
  const saved = await call({method:'POST',body:valid});
  check(saved.response.status,200);
  check(saved.data.state.rules.planning.pending_confirmation,'watch');
  check(saved.data.state.rules.gmail.needs_attention,'ask');
  check(saved.data.state.revision === valid.revision,false);
  check((await call({headers:{'X-Fixture-Anonymous':'1'}})).response.status,401);
  check((await call({headers:{'X-Fixture-User':'2'}})).response.status,403);
  check((await call({headers:{'X-Fixture-User':'2','X-VP3-Working-Attention':binding(2)}})).response.status,403);
  check((await call({headers:{'X-Fixture-Participant':'1'}})).response.status,403);
  check((await call({agent:8})).response.status,403);
  check((await call({chat:12})).response.status,403);
  check((await call({chat:0})).response.status,422);
  check((await call({method:'POST',headers:{'X-VP3-Working-Attention':''},body:valid})).response.status,403);
  check((await call({method:'POST',headers:{'X-Fixture-Participant':'1'},body:valid})).response.status,403);
  check((await call({method:'POST',body:{...valid,csrf_token:'different'}})).response.status,419);
  check((await call({method:'POST',body:{...valid,revision:'b'.repeat(64)}})).response.status,409);
  for (const body of [
    {...valid,rules:{gmail:{important:'send'}}},
    {...valid,rules:[]}, {...valid,rules:1},
    {...valid,agent_id:-1}, {...valid,agent_id:1.5}, {...valid,agent_id:'007'},
    {...valid,owner_user_id:2}, '{invalid',
  ]) check((await call({method:'POST',body})).response.status,422);
  check((await call({method:'POST',body:' '.repeat(8193)})).response.status,413);
  const method = await call({method:'DELETE'});
  check(method.response.status,405);
  check(method.response.headers.get('allow'),'GET, POST');
  console.log(`COORDINATION_RULES_API=PASS (${assertions} assertions through the actual settings endpoint)`);
} finally {
  server.kill('SIGTERM');
  await fs.rm(root,{recursive:true,force:true});
}
