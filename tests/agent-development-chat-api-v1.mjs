import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';

const root = fs.mkdtempSync(path.join(os.tmpdir(),'vp3-development-chat-api-'));
const php = process.env.VP3_PHP_BINARY || 'php';
fs.cpSync('includes',path.join(root,'includes'),{recursive:true});
fs.mkdirSync(path.join(root,'api'));
fs.copyFileSync('api/agent-development-chat-v1.php',path.join(root,'api/agent-development-chat-v1.php'));
for (const name of ['image-generation-v1.php','workflow-agent-schema-v1.php']) {
  fs.copyFileSync('tests/fixtures/'+name,path.join(root,'includes',name));
}
fs.writeFileSync(path.join(root,'includes/bootstrap.php'),`<?php
require __DIR__.'/image-generation-v1.php';
function vp3_participant_scope_v1(): ?array { return isset($_SERVER['HTTP_X_FIXTURE_PARTICIPANT']) ? ['participant_id'=>3] : null; }
`);
// Exercise the real controller and Agent/Chat binding independently of the development ledger.
fs.writeFileSync(path.join(root,'includes/agent-development-v1.php'),`<?php
function vp3_dev_is_admin_v1(array $user): bool { return !isset($_SERVER['HTTP_X_FIXTURE_NOT_ADMIN']); }
function vp3_dev_schema_ready_v1(PDO $pdo): bool { return !isset($_SERVER['HTTP_X_FIXTURE_UPGRADE']); }
function vp3_dev_control_v1(PDO $pdo,int $owner): array { return ['mode'=>'code_stage','epoch'=>2]; }
function vp3_dev_stage_current_v1(array $row): bool { return true; }
function vp3_dev_list_v1(PDO $pdo,int $owner,?int $conversationId=null): array {
    if ($owner !== 1 || $conversationId !== 11) throw new RuntimeException('unexpected_scope');
    return [['id'=>str_repeat('a',32),'mode'=>'code_stage','status'=>'staged','request'=>'PRIVATE REQUEST',
        'head_sha'=>'PRIVATE SOURCE','preview_url'=>'PRIVATE PREVIEW','summary'=>'PRIVATE SUMMARY']];
}
function vp3_dev_create_v1(PDO $pdo,array $user,string $request,int $epoch,?int $conversationId=null): array {
    if ($epoch !== 2) throw new DomainException('The automation level changed.',409);
    if ($request !== 'Explicit request' || $conversationId !== 11 || (int)$user['id'] !== 1) throw new RuntimeException('unexpected_request');
    return ['id'=>str_repeat('b',32),'status'=>'queued','mode'=>'code_stage','request'=>'PRIVATE REQUEST'];
}
`);

const listener = net.createServer();
await new Promise(resolve => listener.listen(0,'127.0.0.1',resolve));
const port = listener.address().port;
await new Promise(resolve => listener.close(resolve));
const server = spawn(php,['-S',`127.0.0.1:${port}`,'-t',root],{stdio:['ignore','ignore','pipe']});
let logs = '';
server.stderr.on('data',chunk => { logs += chunk; });
const endpoint = `http://127.0.0.1:${port}/api/agent-development-chat-v1.php`;
const request = async (body = {},headers = {},method = 'POST',raw = null) => {
  const response = await fetch(endpoint,{
    method,headers:{'Content-Type':'application/json',...headers},
    ...(method === 'POST' ? {body:raw ?? JSON.stringify({action:'status',owner_key:'1',agent_id:7,conversation_id:11,csrf_token:'fixture',...body})} : {}),
  });
  const text = await response.text();
  try { return {status:response.status,headers:response.headers,data:JSON.parse(text),text}; }
  catch { throw Error(text+'\n'+logs.slice(-2000)); }
};

try {
  let ready = false;
  for (let i=0;i<80;i++) {
    try { ready = (await request({}, {}, 'GET')).status === 405; if (ready) break; } catch (_error) {}
    await new Promise(resolve => setTimeout(resolve,25));
  }
  assert.ok(ready,logs);

  const status = await request();
  assert.equal(status.status,200);
  assert.match(status.headers.get('cache-control'),/private.*no-store/);
  assert.deepEqual(status.data,{
    ok:true,owner_key:'1',agent_id:7,conversation_id:11,
    revisions:[{id:'a'.repeat(32),status:'staged',mode:'code_stage',preview_ready:true}],mode:'code_stage',epoch:2,
  });
  assert.doesNotMatch(status.text,/PRIVATE/);
  assert.deepEqual((await request({conversation_id:0})).data.revisions,[]);
  assert.equal((await request({}, {'X-Fixture-Not-Admin':'1'})).status,403);
  assert.equal((await request({}, {'X-Fixture-Anonymous':'1'})).status,403);
  assert.equal((await request({}, {'X-Fixture-Denied':'1'})).status,403);
  assert.equal((await request({}, {'X-Fixture-Participant':'1'})).status,403);
  assert.equal((await request({owner_key:'2'})).status,409);
  assert.equal((await request({}, {'X-Fixture-User':'2'})).status,409);
  assert.equal((await request({csrf_token:'bad'})).status,419);
  assert.equal((await request({agent_id:8})).status,404);
  assert.equal((await request({agent_id:9,conversation_id:13})).status,404);
  assert.equal((await request({agent_id:0,conversation_id:11})).status,404);
  assert.equal((await request({conversation_id:'11'})).status,422);
  assert.equal((await request({action:'publish'})).status,422);
  assert.equal((await request({agent_context:{voice_speaker:{participant_id:3}}})).status,422);
  assert.equal((await request({}, {}, 'POST','{')).status,422);
  assert.equal((await request({}, {}, 'POST',' '.repeat(16385))).status,413);
  assert.equal((await request({}, {'X-Fixture-Upgrade':'1'})).status,503);

  const create = {action:'create',request:'Explicit request',expected_epoch:2};
  assert.equal((await request({...create,expected_epoch:1})).status,409);
  assert.equal((await request({...create,conversation_id:0})).status,422);
  assert.equal((await request({...create,input_mode:'voice'})).status,422);
  const created = await request(create);
  assert.equal(created.status,200);
  assert.deepEqual(created.data.revision,{id:'b'.repeat(32),status:'queued',mode:'code_stage',preview_ready:false});
  assert.doesNotMatch(created.text,/PRIVATE/);
  console.log('AGENT_DEVELOPMENT_CHAT_API_V1=PASS (real HTTP authority, scoped Chat, CSRF, typed request shape, stale mode and minimal receipt)');
} finally {
  server.kill();
  await new Promise(resolve => server.once('close',resolve));
  fs.rmSync(root,{recursive:true,force:true});
}
