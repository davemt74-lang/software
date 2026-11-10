import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const source = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const root = await fs.mkdtemp(path.join(os.tmpdir(),'vp3-canvas-settings-api-'));
const probe = net.createServer();
await new Promise(resolve => probe.listen(0,'127.0.0.1',resolve));
const port = probe.address().port;
await new Promise(resolve => probe.close(resolve));
const quoted = value => `'${value.replaceAll('\\','\\\\').replaceAll("'","\\'")}'`;
await fs.mkdir(path.join(root,'api'));
await fs.mkdir(path.join(root,'includes'));
await fs.writeFile(path.join(root,'ready.txt'),'ready');
await fs.copyFile(path.join(source,'api/initiative-canvas-settings-v1.php'),path.join(root,'api/initiative-canvas-settings-v1.php'));
await fs.writeFile(path.join(root,'includes/agent-initiative-canvas-policy-v1.php'),`<?php require_once ${quoted(path.join(source,'includes/agent-initiative-canvas-policy-v1.php'))};`);
await fs.writeFile(path.join(root,'includes/bootstrap.php'),`<?php
// Infrastructure fixture only. The actual endpoint and policy setter execute.
ob_start();try{require ${quoted(path.join(source,'tests/initiative-canvas-settings-v1.php'))};}finally{ob_end_clean();}
$binding=vp3_initiative_policy_v1($one,17);$binding['enabled']=true;$binding['allow_auto_drafts']=false;$binding['revision']=str_repeat('a',32);
vp3_initiative_in_scope_v1($one,17,static fn()=>agent_brain_v122_upsert_system_memory($one,'initiative_policy','owner-opt-in','Fixture policy.',$binding));
if(isset($_SERVER['HTTP_X_FIXTURE_PARTICIPANT']))$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant'=>8];
`);
const server = spawn(process.env.PHP_BINARY || 'php',['-S',`127.0.0.1:${port}`,'-t',root],{cwd:source,stdio:['ignore','pipe','pipe']});
let logs = '';
server.stderr.on('data',chunk => { logs += chunk; });
server.stdout.on('data',chunk => { logs += chunk; });
const base = `http://127.0.0.1:${port}`;
let assertions = 0;
const check = (actual, expected) => { assert.equal(actual,expected); assertions++; };
async function call({method='GET',agent=17,headers={},body}={}) {
  const url = base+'/api/initiative-canvas-settings-v1.php'+(method === 'GET' ? `?agent_id=${agent}` : '');
  const response = await fetch(url,{method,headers:{'Content-Type':'application/json',...headers},...(body === undefined ? {} : {body:JSON.stringify(body)})});
  const text = await response.text();
  let data;
  try { data=JSON.parse(text); } catch { throw new Error(`Invalid endpoint JSON (${response.status}): ${text.slice(0,500)}\n${logs.slice(-2000)}`); }
  return {response,data};
}
const valid = {agent_id:17,enabled:true,revision:'a'.repeat(32),csrf_token:'fixture'};
try {
  let ready = false;
  for (let attempt=0;attempt<60;attempt++) {
    try { if ((await fetch(base+'/ready.txt')).ok) { ready=true;break; } } catch {}
    await new Promise(resolve => setTimeout(resolve,50));
  }
  assert.ok(ready,logs);
  const read = await call();
  check(read.response.status,200);
  check(read.response.headers.get('cache-control'),'no-store');
  check(read.data.state.owner_user_id,1);
  check(read.data.state.agent_id,17);
  check(read.data.state.enabled,false);
  const saved = await call({method:'POST',body:valid});
  check(saved.response.status,200);
  check(saved.data.state.enabled,true);
  check(saved.data.state.revision === valid.revision,false);
  check((await call({headers:{'X-Fixture-Anonymous':'1'}})).response.status,401);
  check((await call({headers:{'X-Fixture-User':'2'}})).response.status,403);
  check((await call({agent:19})).response.status,403);
  check((await call({headers:{'X-Fixture-Participant':'1'}})).response.status,403);
  check((await call({method:'POST',headers:{'X-Fixture-Participant':'1'},body:valid})).response.status,403);
  check((await call({method:'POST',body:{...valid,csrf_token:'different'}})).response.status,419);
  check((await call({method:'POST',body:{...valid,revision:'b'.repeat(32)}})).response.status,409);
  for (const body of [
    {...valid,enabled:'true'}, {...valid,enabled:1}, {...valid,revision:''},
    {...valid,agent_id:-1}, {...valid,agent_id:1.5}, {...valid,agent_id:'017'},
    {...valid,owner_user_id:2}, {...valid,conversation_id:170}, {...valid,origin:{authorization:'auto_drafts'}}
  ]) check((await call({method:'POST',body})).response.status,422);
  const method = await call({method:'DELETE'});
  check(method.response.status,405);
  check(method.response.headers.get('allow'),'GET, POST');
  console.log(`INITIATIVE_CANVAS_SETTINGS_API=PASS (${assertions} assertions; actual HTTP controller, CSRF, identity, strict fields and stale settings)`);
} finally {
  server.kill('SIGTERM');
  await fs.rm(root,{recursive:true,force:true});
}
