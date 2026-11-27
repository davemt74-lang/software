import assert from 'node:assert/strict';
import {execFileSync,spawn} from 'node:child_process';
import {randomUUID} from 'node:crypto';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {localPort} from './fixtures/phone-site-v1.mjs';
const source=fileURLToPath(new URL('..',import.meta.url)),root=await fs.mkdtemp(path.join(os.tmpdir(),'vp3-workflow-http-'));
const php=process.env.VP3_PHP_BINARY||'php',env={...process.env,VP3_CANVAS_RECOVERY_SOURCE:source,VP3_CANVAS_RECOVERY_DB:path.join(root,'data.sqlite')};
for(const name of ['VP3_SECTION7_MYSQL_DSN','VP3_CLOUD_SCHEDULE_DSN','VP3_IMAGE_TEST_DSN','VP3_TEST_MYSQL_DSN'])delete env[name];
let server,logs='';
try{
  const fixture=JSON.parse(execFileSync(php,[path.join(source,'tests/fixtures/coding-canvas-workflow-http-v1.php')],{env,encoding:'utf8'}));
  await fs.mkdir(path.join(root,'api'));await fs.mkdir(path.join(root,'includes'));
  await fs.copyFile(path.join(source,'api/coding-canvas-v1.php'),path.join(root,'api/coding-canvas-v1.php'));
  await fs.copyFile(path.join(source,'tests/fixtures/coding-canvas-workflow-http-v1.php'),path.join(root,'includes/bootstrap.php'));
  for(const file of ['coding-canvas-jobs-v1.php','coding-canvas-command-grants-v1.php','coding-canvas-email-v1.php','coding-canvas-response-v1.php','coding-canvas-checks-v1.php'])await fs.writeFile(path.join(root,'includes',file),'<?php require_once getenv("VP3_CANVAS_RECOVERY_SOURCE")."/includes/'+file+'";');
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
  const id=fixture.canvas.id,revision=fixture.revision.id;
  const opened=await call(200,{canvas_id:id,revision});
  assert.equal(opened.workflow_testing.ready,true);assert.equal(opened.workflow_testing.can_edit,true);
  assert.equal(opened.revision.workflow_checks.length,1);assert.deepEqual(opened.state.value,{count:17,kept:true});
  const phone=await call(200,{canvas_id:id,revision},{'X-Fixture-Phone':'1'});
  assert.equal(Object.hasOwn(phone.revision,'workflow_checks'),false,'A delivered phone app does not receive private owner test definitions');
  assert.equal(Object.hasOwn(phone.revision,'workflow_result'),false);
  await call(409,{action:'test',canvas_id:id,revision,state_revision:opened.state.revision});
  const begin={action:'begin_check',canvas_id:id,revision,request_id:randomUUID(),source_hash:opened.revision.source_hash,state_revision:opened.state.revision};
  await call(419,{...begin,csrf_token:'wrong'});
  await call(403,begin,{'X-Fixture-Phone':'1'});
  await call(403,begin,{'X-Fixture-User':'2'});
  await call(403,{...begin,conversation_id:12});
  await call(409,{...begin,source_hash:'b'.repeat(64)});
  const running=await call(200,begin);assert.equal(running.revision.workflow_result.status,'running');
  assert.deepEqual((await call(200,begin)).revision.workflow_result,running.revision.workflow_result,'A lost begin response recovers the same test request');
  const finish={action:'finish_check',canvas_id:id,revision,request_id:begin.request_id,
    result:{passed:0,failed_case:0,step:1,reason:'handler_missing',email_fixture_checked:false}};
  await call(422,{...finish,result:{...finish.result,reason:'<script>untrusted</script>'}});
  const failed=await call(200,finish);assert.equal(failed.revision.workflow_result.status,'failed');assert.equal(failed.revision.test_passed,false);
  assert.deepEqual(failed.state,opened.state);assert.equal(failed.canvas.active_revision,1);
  const repair={action:'check_repair',canvas_id:id,revision,request_id:begin.request_id,repair_request_id:randomUUID()};
  const job=(await call(200,repair)).job;
  assert.equal((await call(200,{...repair,repair_request_id:randomUUID()})).job.run_id,job.run_id,'One failed report admits one repair across a lost response');
  assert.equal(job.state,'queued');await call(200,{action:'stop',run_id:job.run_id});
  const again={...begin,request_id:randomUUID()};await call(200,again);
  const passed=await call(200,{...finish,request_id:again.request_id,result:{passed:1,failed_case:null,step:0,reason:'none',email_fixture_checked:false}});
  assert.equal(passed.revision.test_passed,true);assert.equal(passed.canvas.active_revision,1);assert.deepEqual(passed.state,opened.state);
  assert.equal((await call(200,{...finish,request_id:again.request_id,result:{passed:1,failed_case:null,step:0,reason:'none',email_fixture_checked:false}})).revision.test_passed,true);
  await call(409,{...finish,request_id:again.request_id});
  const third={...begin,request_id:randomUUID()};await call(200,third);
  await call(200,{action:'save_state',canvas_id:id,revision:1,state_revision:opened.state.revision,state:{count:18,kept:true}});
  await call(409,{...finish,request_id:third.request_id,result:{passed:1,failed_case:null,step:0,reason:'none',email_fixture_checked:false}});
  const save={action:'save_checks',canvas_id:id,revision,source_hash:opened.revision.source_hash,request_id:randomUUID(),
    expected_active_revision:1,expected_draft_revision:revision,workflow_checks:opened.revision.workflow_checks};
  await call(422,{...save,artifact:opened.revision.artifact});
  await call(422,{...save,commands:['gmail']});
  await call(422,{...save,workflow_checks:Array(4).fill(opened.revision.workflow_checks[0])});
  assert.doesNotMatch(logs,/PHP (?:Fatal error|Warning|Parse error)/);
  console.log('Canvas workflow HTTP passed: real controller, bounded plans, exact begin/finish recovery, one repair admission, stale state and owner/Chat/CSRF/phone guards with active app/data preserved.');
}catch(error){console.error(logs.slice(-4500));throw error;}
finally{server?.kill('SIGTERM');if(server&&server.exitCode===null)await new Promise(resolve=>server.once('exit',resolve));await fs.rm(root,{recursive:true,force:true});}
