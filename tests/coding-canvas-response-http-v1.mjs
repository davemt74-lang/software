import assert from 'node:assert/strict';
import {execFileSync,spawn} from 'node:child_process';
import {randomUUID} from 'node:crypto';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {localPort} from './fixtures/phone-site-v1.mjs';
const source=fileURLToPath(new URL('..',import.meta.url)),root=await fs.mkdtemp(path.join(os.tmpdir(),'vp3-response-http-'));
const php=process.env.VP3_PHP_BINARY||'php',env={...process.env,VP3_CANVAS_RECOVERY_SOURCE:source,VP3_CANVAS_RECOVERY_DB:path.join(root,'data.sqlite')};
for(const name of ['VP3_SECTION7_MYSQL_DSN','VP3_CLOUD_SCHEDULE_DSN','VP3_IMAGE_TEST_DSN','VP3_TEST_MYSQL_DSN'])delete env[name];
let server,logs='';
try{
  const fixture=JSON.parse(execFileSync(php,[path.join(source,'tests/fixtures/coding-canvas-response-http-v1.php')],{env,encoding:'utf8'}));
  await fs.mkdir(path.join(root,'api'));await fs.mkdir(path.join(root,'includes'));
  await fs.copyFile(path.join(source,'api/coding-canvas-v1.php'),path.join(root,'api/coding-canvas-v1.php'));
  await fs.copyFile(path.join(source,'tests/fixtures/coding-canvas-response-http-v1.php'),path.join(root,'includes/bootstrap.php'));
  for(const file of ['coding-canvas-jobs-v1.php','coding-canvas-command-grants-v1.php','coding-canvas-email-v1.php','coding-canvas-response-v1.php'])await fs.writeFile(path.join(root,'includes',file),'<?php require_once getenv("VP3_CANVAS_RECOVERY_SOURCE")."/includes/'+file+'";');
  await fs.writeFile(path.join(root,'ready.txt'),'ready');
  const port=await localPort(),base='http://127.0.0.1:'+port;
  server=spawn(php,['-d','display_errors=0','-d','log_errors=1','-S','127.0.0.1:'+port,'-t',root],{env,stdio:['ignore','ignore','pipe']});
  server.stderr.on('data',chunk=>{logs+=chunk;});
  for(let n=0;;n++){try{if((await fetch(base+'/ready.txt')).ok)break;}catch{}if(n>60)throw Error('Source HTTP fixture did not start');await new Promise(resolve=>setTimeout(resolve,50));}
  async function call(status,body,headers={}){
    const response=await fetch(base+'/api/coding-canvas-v1.php',{method:'POST',headers:{'Content-Type':'application/json',...headers},
      body:JSON.stringify({action:'open',csrf_token:'fixture',agent_id:7,conversation_id:11,...body})});
    const text=await response.text();assert.equal(response.status,status,text);const value=JSON.parse(text);assert.equal(value.ok,status===200,text);
    assert.equal(response.headers.get('cache-control'),'no-store');return value;
  }
  const run=fixture.run;
  const view=await call(200,{action:'job',run_id:run});
  assert.equal(view.job.state,'failed');assert.equal(view.job.live_source_available,true);
  assert.doesNotMatch(JSON.stringify(view),/Recovered response/,'Public progress excludes the received source');
  const received=await call(200,{action:'received_source',run_id:run});
  assert.equal(received.received_source.text,fixture.text);assert.equal(received.received_source.can_recover,true);
  await call(419,{action:'received_source',run_id:run,csrf_token:'wrong'});
  await call(401,{action:'received_source',run_id:run},{'X-Fixture-Anonymous':'1'});
  await call(403,{action:'received_source',run_id:run},{'X-Fixture-User':'2'});
  await call(403,{action:'received_source',run_id:run,conversation_id:12});
  await call(403,{action:'received_source',run_id:run},{'X-Fixture-Phone':'1'});
  await call(403,{action:'recover_source',run_id:run,request_id:randomUUID()},{'X-Fixture-Phone':'1'});
  const saved=await call(200,{action:'recover_source',run_id:run,request_id:randomUUID(),artifact:{html:'<h1>Injected</h1>',css:'',js:''}});
  assert.deepEqual(saved.revision.artifact,fixture.artifact,'Only the server-retained response is imported');
  assert.equal(saved.revision.test_passed,false);assert.equal(saved.canvas.active_revision,fixture.before.canvas.active_revision);
  assert.deepEqual(saved.state,fixture.before.state);assert.equal(saved.source_editor.can_edit,true);
  assert.equal((await call(200,{action:'recover_source',run_id:run,request_id:randomUUID()})).revision.id,saved.revision.id,'A response lost across reload has the same imported draft');
  assert.equal((await call(200,{action:'received_source',run_id:run})).received_source.can_recover,false);
  assert.equal((await call(200,{action:'job',run_id:run})).job.state,'failed','Manual import does not fabricate provider success');
  await call(422,{action:'recover_source',run_id:run,request_id:'invalid'});
  assert.doesNotMatch(logs,/PHP (?:Fatal error|Warning|Parse error)/);
  console.log('Canvas provider response HTTP passed: real controller, private retained code, no-store/CSRF/account/Agent/Chat/phone guards, loss-safe draft import and unchanged active app/data without inference.');
}catch(error){console.error(logs.slice(-4500));throw error;}
finally{server?.kill('SIGTERM');if(server&&server.exitCode===null)await new Promise(resolve=>server.once('exit',resolve));await fs.rm(root,{recursive:true,force:true});}
