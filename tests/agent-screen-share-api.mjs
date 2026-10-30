import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-screen-api-'));
for(const name of ['api','includes','sessions','budgets'])fs.mkdirSync(path.join(tmp,name));
for(const name of ['api/agent-screen-share-v1.php','includes/agent-screen-share-v1.php'])fs.copyFileSync(name,path.join(tmp,name));
fs.writeFileSync(path.join(tmp,'includes/bootstrap.php'),`<?php
session_start();
function current_user(){return isset($_SERVER['HTTP_X_FIXTURE_ANONYMOUS'])?null:['id'=>(int)($_SERVER['HTTP_X_FIXTURE_USER']??1)];}
function ai_v100_ensure_runtime_dir(){return __DIR__.'/../budgets';}
function csrf_token(){return 'fixture';}
function has_permission(...$args){return !isset($_SERVER['HTTP_X_FIXTURE_DENIED']);}
function ai_generate_chat_response($prompt,$history,$context,$user,$scope,$control,$images){
 if(count($images)!==1||!str_starts_with($images[0],'data:image/jpeg;base64,'))throw new RuntimeException('Missing actual image');
 if(isset($_SERVER['HTTP_X_FIXTURE_PROVIDER_FAIL']))return ['ok'=>false,'error'=>'Provider unavailable'];
 return ['ok'=>true,'answer'=>isset($_SERVER['HTTP_X_FIXTURE_BAD_MODEL'])?'invalid':json_encode(['summary'=>'Product comparison','visible_text'=>'Product A','site_hint'=>'Example store','page_title'=>'Product A'])];
}
`);
const reserve=net.createServer();await new Promise(r=>reserve.listen(0,'127.0.0.1',r));const port=reserve.address().port;await new Promise(r=>reserve.close(r));
const child=spawn(process.env.VP3_PHP_BINARY||'php',['-d',`session.save_path=${tmp}/sessions`,'-S',`127.0.0.1:${port}`,'-t',tmp],{stdio:['ignore','ignore','pipe']});let logs='';child.stderr.on('data',x=>logs+=x);const url=`http://127.0.0.1:${port}/api/agent-screen-share-v1.php`;
const input={owner_key:'1',csrf_token:'fixture',image:'data:image/jpeg;base64,'+fs.readFileSync('tests/fixtures/tv-frame.b64','utf8').trim(),surface:'window',captured_at:new Date().toISOString()};
let checks=0;const check=async(name,fn)=>{await fn();checks++;console.log('PASS '+name);};
async function post(body=input,headers={}){const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json',...headers},body:typeof body==='string'?body:JSON.stringify(body)});return {r,data:await r.json()};}
try{
 for(let i=0;i<50;i++){try{await fetch(url);break;}catch{await new Promise(r=>setTimeout(r,50));}}
 await check('endpoint rejects non-POST and anonymous callers',async()=>{assert.equal((await fetch(url)).status,405);assert.equal((await post(input,{'X-Fixture-Anonymous':'1'})).r.status,403);});
 await check('endpoint enforces CSRF, Chat permission and current account',async()=>{assert.equal((await post({...input,csrf_token:'wrong'})).r.status,419);assert.equal((await post(input,{'X-Fixture-Denied':'1'})).r.status,403);assert.equal((await post({...input,owner_key:'2'})).r.status,409);});
 await check('endpoint validates request shape and frame freshness',async()=>{assert.equal((await post('{')).r.status,422);assert.equal((await post({...input,image:'https://example.com/frame'})).r.status,422);assert.equal((await post({...input,captured_at:'2000-01-01T00:00:00Z'})).r.status,409);assert.equal((await post({...input,tool:'execute'})).r.status,422);});
 await check('actual endpoint analyzes one image and returns text-only account-bound evidence',async()=>{const {r,data}=await post();assert.equal(r.status,200);assert.equal(data.observation.owner_key,'1');assert.equal(data.observation.summary,'Product comparison');assert.equal(data.raw_images_stored,false);assert.equal(JSON.stringify(data).includes('base64'),false);
  const cookie=r.headers.get('set-cookie').split(';')[0];const again=await post(input,{'Cookie':cookie});assert.equal(again.r.status,429);assert.match(again.data.error,/limited/,'Completed provider request must release the in-flight lease');assert.equal((await post()).r.status,429,'A new cookie cannot bypass the account budget');
 });
 await check('provider and malformed model failures remain visible',async()=>{assert.equal((await post({...input,owner_key:'2'},{'X-Fixture-Provider-Fail':'1','X-Fixture-User':'2'})).r.status,503);assert.equal((await post({...input,owner_key:'3'},{'X-Fixture-Bad-Model':'1','X-Fixture-User':'3'})).r.status,502);});
 await check('screen pixels and model observations never enter PHP session storage',async()=>{const bytes=fs.readdirSync(path.join(tmp,'sessions')).map(n=>fs.readFileSync(path.join(tmp,'sessions',n),'utf8')).join('');assert.equal(bytes.includes('base64'),false);assert.equal(bytes.includes('Product A'),false);const budgets=fs.readdirSync(path.join(tmp,'budgets')).map(n=>fs.readFileSync(path.join(tmp,'budgets',n),'utf8')).join('');assert.ok(budgets.includes('count'));assert.equal(budgets.includes('base64'),false);assert.equal(budgets.includes('Product A'),false);});
 console.log(`AGENT_SCREEN_SHARE_API=PASS (${checks} cases)`);
}catch(error){console.error(logs.slice(-3000));throw error;}finally{child.kill();await new Promise(r=>child.once('exit',r));fs.rmSync(tmp,{recursive:true,force:true});}
