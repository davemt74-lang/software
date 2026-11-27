import assert from 'node:assert/strict';
import {execFileSync,spawn} from 'node:child_process';
import {randomUUID} from 'node:crypto';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {localPort} from './fixtures/phone-site-v1.mjs';

const source=fileURLToPath(new URL('..',import.meta.url)),root=await fs.mkdtemp(path.join(os.tmpdir(),'vp3-canvas-recovery-'));
const php=process.env.VP3_PHP_BINARY||'php';
const env={...process.env,VP3_CANVAS_RECOVERY_SOURCE:source,VP3_CANVAS_RECOVERY_DB:path.join(root,'data.sqlite')};
delete env.VP3_SECTION7_MYSQL_DSN;delete env.VP3_CLOUD_SCHEDULE_DSN;delete env.VP3_IMAGE_TEST_DSN;
let server,logs='';
try{
  const fixture=JSON.parse(execFileSync(php,[path.join(source,'tests/fixtures/coding-canvas-recovery-http-v1.php')],{env,encoding:'utf8'}));
  await fs.mkdir(path.join(root,'api'));await fs.mkdir(path.join(root,'includes'));
  await fs.copyFile(path.join(source,'api/coding-canvas-v1.php'),path.join(root,'api/coding-canvas-v1.php'));
  await fs.copyFile(path.join(source,'tests/fixtures/coding-canvas-recovery-http-v1.php'),path.join(root,'includes/bootstrap.php'));
  for(const name of ['coding-canvas-jobs-v1.php','coding-canvas-command-grants-v1.php']){
    await fs.writeFile(path.join(root,'includes',name),'<?php require_once getenv("VP3_CANVAS_RECOVERY_SOURCE")."/includes/'+name+'";');
  }
  await fs.writeFile(path.join(root,'ready.txt'),'ready');
  const port=await localPort(),base='http://127.0.0.1:'+port;
  server=spawn(php,['-d','display_errors=0','-d','log_errors=1','-S','127.0.0.1:'+port,'-t',root],{env,stdio:['ignore','ignore','pipe']});
  server.stderr.on('data',chunk=>{logs+=chunk;});
  for(let i=0;;i++){
    try{if((await fetch(base+'/ready.txt')).ok)break;}catch{}
    if(i>60)throw Error('Canvas recovery HTTP server did not start.');
    await new Promise(resolve=>setTimeout(resolve,50));
  }
  async function call(expected,body,headers={}){
    const response=await fetch(base+'/api/coding-canvas-v1.php',{method:'POST',headers:{'Content-Type':'application/json',...headers},
      body:JSON.stringify({csrf_token:'fixture',agent_id:7,conversation_id:11,...body})});
    const text=await response.text();assert.equal(response.status,expected,text);
    assert.equal(response.headers.get('cache-control'),'no-store');
    const data=JSON.parse(text);assert.equal(data.ok,expected===200,text);return data;
  }
  const recovered=await call(200,{action:'recover',request_id:fixture.request_id});
  assert.equal(recovered.job.run_id,fixture.completed);assert.equal(recovered.job.state,'ready');assert.equal(recovered.job.can_retry,false);
  assert.equal((await call(200,{action:'recover',request_id:randomUUID()})).job,null);
  assert.equal((await call(200,{action:'resume'})).job.state,'stopped','A reload retains the latest terminal run');
  const request=await call(200,{action:'saved_request',run_id:fixture.failed});
  assert.equal(request.request.prompt,fixture.retry_prompt);
  const requestId=randomUUID();
  const retried=await call(200,{action:'retry',run_id:fixture.failed,request_id:requestId,prompt:'This injected brief must be ignored'});
  assert.equal(retried.job.state,'queued');assert.notEqual(retried.job.run_id,fixture.failed);
  assert.equal((await call(200,{action:'retry',run_id:fixture.failed,request_id:requestId})).job.run_id,retried.job.run_id);
  assert.equal((await call(200,{action:'recover',request_id:requestId})).job.run_id,retried.job.run_id);
  assert.equal((await call(200,{action:'saved_request',run_id:retried.job.run_id})).request.prompt,fixture.retry_prompt);
  await call(409,{action:'retry',run_id:fixture.completed,request_id:randomUUID()});
  await call(403,{action:'retry',run_id:fixture.failed,request_id:randomUUID(),conversation_id:12});
  await call(419,{action:'retry',run_id:fixture.failed,request_id:randomUUID(),csrf_token:'wrong'});
  await call(422,{action:'recover',request_id:'not-a-native-id'});
  await call(422,{action:'retry',run_id:fixture.failed});
  await call(401,{action:'resume'},{'X-Fixture-Anonymous':'1'});
  await call(403,{action:'saved_request',run_id:fixture.failed},{'X-Fixture-User':'2'});
  await call(403,{action:'retry',run_id:fixture.failed,request_id:randomUUID()},{'X-Fixture-Denied':'1'});
  const pending=await call(200,{action:'job',run_id:retried.job.run_id});
  assert.equal(pending.job.state,'queued','Read-only recovery never dispatches a queued generation');
  assert.equal(Object.hasOwn(pending.job,'prompt'),false,'Progress excludes the private build instruction');
  assert.doesNotMatch(logs,/PHP (?:Fatal error|Warning|Parse error)/);
  console.log('Canvas recovery HTTP passed: canonical controller, exact lookup, terminal reload, explicit idempotent retry, saved brief, CSRF, invalid input, account/Agent/Chat boundaries and no generation on reads.');
}catch(error){console.error(logs.slice(-4500));throw error;}
finally{
  server?.kill('SIGTERM');
  if(server&&server.exitCode===null)await new Promise(resolve=>server.once('exit',resolve));
  await fs.rm(root,{recursive:true,force:true});
}
