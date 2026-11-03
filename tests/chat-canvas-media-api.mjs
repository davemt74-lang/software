import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-image-api-'));fs.cpSync('includes',path.join(tmp,'includes'),{recursive:true});fs.mkdirSync(path.join(tmp,'api'));
fs.copyFileSync('api/chat-canvas-media-v1.php',path.join(tmp,'api/chat-canvas-media-v1.php'));fs.copyFileSync('media-library-file-v86.php',path.join(tmp,'media-library-file-v86.php'));
fs.copyFileSync('tests/fixtures/image-generation-v1.php',path.join(tmp,'includes/image-fixture.php'));fs.copyFileSync('tests/fixtures/workflow-agent-schema-v1.php',path.join(tmp,'includes/workflow-agent-schema-v1.php'));
fs.writeFileSync(path.join(tmp,'includes/bootstrap.php'),`<?php define('STONEFELLOW_ROOT',dirname(__DIR__));$GLOBALS['imageFixtureFile']=dirname(__DIR__).'/image.sqlite';$GLOBALS['noImageProvider']=true;require __DIR__.'/image-fixture.php';`);
fs.writeFileSync(path.join(tmp,'seed.php'),`<?php require __DIR__.'/includes/bootstrap.php';$GLOBALS['noImageProvider']=false;$u=['id'=>1,'is_active'=>1];$p=['kind'=>'user_agent','owner_user_id'=>1,'agent_id'=>7];$g=vp3_image_queue_v1(db(),$u,$p,image_fixture_plan_v1(),image_fixture_turn_v1());if(($_GET['mode']??'')==='complete')$g=vp3_image_tick_v1(db(),$u,vp3_image_row_v1(db(),$u,$g['id']),function($url,$body,$key){file_put_contents(__DIR__.'/provider-calls.txt','.',FILE_APPEND);return image_fixture_response_v1($body['size']);});header('Content-Type: application/json');echo json_encode($g);`);
const server=net.createServer();await new Promise(r=>server.listen(0,'127.0.0.1',r));const port=server.address().port;await new Promise(r=>server.close(r));
const child=spawn(process.env.VP3_PHP_BINARY||'php',['-S',`127.0.0.1:${port}`,'-t',tmp],{stdio:['ignore','ignore','pipe']});let logs='';child.stderr.on('data',v=>logs+=v);
const base=`http://127.0.0.1:${port}`;const endpoint=base+'/api/chat-canvas-media-v1.php';let checks=0;
async function post(body,headers={}){const r=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json',...headers},body:JSON.stringify({csrf_token:'fixture',conversation_id:11,agent_id:7,...body})});const raw=await r.text();let data;try{data=JSON.parse(raw);}catch(_){throw Error(raw+'\n'+logs.slice(-2000));}return {r,data,raw};}
const callCount=()=>fs.existsSync(path.join(tmp,'provider-calls.txt'))?fs.readFileSync(path.join(tmp,'provider-calls.txt'),'utf8').length:0;
async function check(label,fn){await fn();checks++;console.log('PASS '+label);}
try{
 for(let i=0;i<100;i++){try{await fetch(base+'/');break;}catch(_){await new Promise(r=>setTimeout(r,30));}}
 let g=await(await fetch(base+'/seed.php?mode=complete')).json();assert.equal(g.status,'completed');const id=g.id,asset=g.asset.id;
 await check('production media API enforces POST, authentication, permission and CSRF',async()=>{
  assert.equal((await fetch(endpoint)).status,405);
  assert.equal((await post({action:'get',generation_id:id},{'X-Fixture-Anonymous':'1'})).r.status,401);
  assert.equal((await post({action:'get',generation_id:id},{'X-Fixture-Denied':'1'})).r.status,403);
  assert.equal((await post({action:'get',generation_id:id,csrf_token:'wrong'})).r.status,419);
 });
 await check('fresh API requests restore a confirmed image without invoking OpenAI',async()=>{const before=callCount();for(const action of ['get','tick','get']){const d=await post({action,generation_id:id});assert.equal(d.data.generation.status,'completed');assert.equal(d.data.generation.asset.id,asset);assert.equal(d.raw.includes('fixture-image-secret'),false);}assert.equal(callCount(),before);});
 await check('generation cards are bound to their owner, Agent and conversation',async()=>{
  assert.equal((await post({action:'get',generation_id:id,conversation_id:13,agent_id:9},{'X-Fixture-User':'2'})).r.status,404);
  assert.equal((await post({action:'get',generation_id:id,conversation_id:12,agent_id:8})).r.status,404);
  assert.equal((await post({action:'get',generation_id:id,conversation_id:11,agent_id:8})).data.ok,false);
 });
 await check('private asset batch lookup cannot disclose another owner image',async()=>{
  const own=await post({action:'assets',asset_ids:[asset]});assert.equal(own.data.assets[0].id,asset);assert.equal(own.data.assets[0].available,true);
  const foreign=await post({action:'assets',asset_ids:[asset],conversation_id:13,agent_id:9},{'X-Fixture-User':'2'});assert.deepEqual(foreign.data.assets,[]);
 });
 await check('the existing authenticated file route serves actual saved PNG bytes',async()=>{const own=await fetch(base+'/media-library-file-v86.php?id='+asset);assert.equal(own.status,200);assert.match(own.headers.get('content-type'),/image\/png/);assert.equal(own.headers.get('x-content-type-options'),'nosniff');const bytes=new Uint8Array(await own.arrayBuffer());assert.deepEqual([...bytes.slice(0,8)],[137,80,78,71,13,10,26,10]);assert.equal((await fetch(base+'/media-library-file-v86.php?id='+asset,{headers:{'X-Fixture-User':'2'}})).status,404);});
 await check('malformed references and oversized batches fail before reading media',async()=>{assert.equal((await post({action:'assets',asset_ids:Array.from({length:13},(_,i)=>i+1)})).r.status,422);assert.equal((await post({action:'assets',asset_ids:['1']})).r.status,422);assert.equal((await post({action:'get',generation_id:'../../secret'})).r.status,422);});
 g=await(await fetch(base+'/seed.php')).json();
 await check('a queued request can be cancelled before any paid provider call',async()=>{const before=callCount();let r=await post({action:'cancel',generation_id:g.id});assert.equal(r.r.status,422);r=await post({action:'cancel',generation_id:g.id,confirmed:true});assert.equal(r.data.generation.status,'cancelled');assert.equal((await post({action:'tick',generation_id:g.id})).data.generation.status,'cancelled');assert.equal(callCount(),before);});
 g=await(await fetch(base+'/seed.php')).json();
 await check('disabled provider settings stop the canonical job without an outbound image call',async()=>{const before=callCount();const r=await post({action:'tick',generation_id:g.id});assert.equal(r.data.generation.status,'failed');assert.match(r.data.generation.message,/disabled|unavailable/);assert.equal(callCount(),before);});
 console.log(`CHAT_CANVAS_MEDIA_API=PASS (${checks} cases; production API, canonical job and real private file route)`);
}catch(e){console.error(logs.slice(-3000));throw e;}finally{child.kill();await new Promise(r=>child.exitCode!==null?r():child.once('exit',r));fs.rmSync(tmp,{recursive:true,force:true});}
