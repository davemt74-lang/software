import assert from 'node:assert/strict';
import {execFileSync,spawn} from 'node:child_process';
import {randomUUID} from 'node:crypto';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {localPort} from './fixtures/phone-site-v1.mjs';
const source=fileURLToPath(new URL('..',import.meta.url)),root=await fs.mkdtemp(path.join(os.tmpdir(),'vp3-source-http-'));
const php=process.env.VP3_PHP_BINARY||'php',env={...process.env,VP3_CANVAS_RECOVERY_SOURCE:source,VP3_CANVAS_RECOVERY_DB:path.join(root,'data.sqlite')};
for(const name of ['VP3_SECTION7_MYSQL_DSN','VP3_CLOUD_SCHEDULE_DSN','VP3_IMAGE_TEST_DSN','VP3_TEST_MYSQL_DSN'])delete env[name];
let server,logs='';
try{
  const fixture=JSON.parse(execFileSync(php,[path.join(source,'tests/fixtures/coding-canvas-source-http-v1.php')],{env,encoding:'utf8'})).source;
  await fs.mkdir(path.join(root,'api'));await fs.mkdir(path.join(root,'includes'));
  await fs.copyFile(path.join(source,'api/coding-canvas-v1.php'),path.join(root,'api/coding-canvas-v1.php'));
  await fs.copyFile(path.join(source,'tests/fixtures/coding-canvas-source-http-v1.php'),path.join(root,'includes/bootstrap.php'));
  for(const file of ['coding-canvas-jobs-v1.php','coding-canvas-command-grants-v1.php','coding-canvas-email-v1.php'])await fs.writeFile(path.join(root,'includes',file),'<?php require_once getenv("VP3_CANVAS_RECOVERY_SOURCE")."/includes/'+file+'";');
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
  const id=fixture.canvas.id,opened=await call(200,{canvas_id:id,revision:1});
  assert.equal(opened.source_editor.ready,true);assert.equal(opened.source_editor.can_edit,true);
  const edit={action:'save_source',canvas_id:id,revision:1,source_hash:opened.revision.source_hash,request_id:randomUUID(),
    expected_draft_revision:1,expected_active_revision:null,artifact:{...opened.revision.artifact,html:'<h1>Owner edited source</h1>'}};
  const saved=await call(200,edit);assert.equal(saved.revision.id,2);assert.equal(saved.source_saved,true);
  assert.equal(saved.revision.test_passed,false);assert.equal(saved.canvas.active_revision,null);assert.deepEqual(saved.state,opened.state);
  assert.equal((await call(200,edit)).revision.id,2,'An actual HTTP replay recovers the same manual revision');
  await call(409,{...edit,artifact:opened.revision.artifact});
  await call(409,{...edit,request_id:randomUUID()});
  await call(422,{...edit,request_id:randomUUID(),expected_draft_revision:2,artifact:{html:'<script>bad()</script>',css:'',js:''}});
  await call(422,{...edit,request_id:randomUUID(),expected_draft_revision:2,commands:['gmail']});
  await call(422,{...edit,expected_draft_revision:undefined});
  await call(419,{...edit,csrf_token:'wrong'});
  await call(401,edit,{'X-Fixture-Anonymous':'1'});
  await call(403,edit,{'X-Fixture-Denied':'1'});
  await call(403,edit,{'X-Fixture-User':'2'});
  await call(403,{...edit,conversation_id:12});
  const large={...edit,request_id:randomUUID(),revision:2,source_hash:saved.revision.source_hash,expected_draft_revision:2,
    artifact:{html:'<p>Escaped source</p>',css:'',js:'\u0001'.repeat(20000)}};
  assert.ok(Buffer.byteLength(JSON.stringify(large))>98304);
  assert.equal((await call(200,large)).revision.id,3,'The bounded source endpoint accepts JSON escaping overhead within native artifact limits');
  await call(413,{action:'list',padding:'x'.repeat(100000)});
  await call(413,{...large,padding:'x'.repeat(400000)});
  const current=await call(200,{canvas_id:id,revision:2});
  const code=current.revision.artifact.js,start=Buffer.byteLength(code.slice(0,code.indexOf('🎨')));
  const context={language:'js',source_hash:current.revision.source_hash,start_byte:start,end_byte:start+4};
  const requested=await call(200,{action:'repair',canvas_id:id,revision:2,request_id:randomUUID(),prompt:'Keep the marker and clarify the heading.',edit_context:context});
  assert.equal(requested.job.state,'queued');assert.equal(Object.hasOwn(requested.job,'edit_context'),false);
  const brief=await call(200,{action:'saved_request',run_id:requested.job.run_id});
  assert.deepEqual(brief.request.edit_context,context);
  assert.equal((await call(200,{action:'job',run_id:requested.job.run_id})).job.state,'queued','Inspecting a selected-code request never dispatches inference');
  assert.doesNotMatch(logs,/PHP (?:Fatal error|Warning|Parse error)/);
  console.log('Canvas source HTTP passed: real controller, immutable manual saves, exact replay, stale/CSRF/account/Agent/Chat guards, source bounds and selected-code admission without inference.');
}catch(error){console.error(logs.slice(-4500));throw error;}
finally{server?.kill('SIGTERM');if(server&&server.exitCode===null)await new Promise(resolve=>server.once('exit',resolve));await fs.rm(root,{recursive:true,force:true});}
