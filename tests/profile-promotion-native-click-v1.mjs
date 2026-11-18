import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn,spawnSync} from 'node:child_process';

const root=process.cwd(),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-native-click-http-'));
const php=process.env.VP3_PHP_BINARY||'php',config=path.join(tmp,'config.json');
const env={...process.env,VP3_PA3_ROOT:root,VP3_PA3_CONFIG:config,VP3_NATIVE_CLICK_ROOT:tmp};
const driver=path.resolve('tests/fixtures/profile-promotion-native-click-v1-db.php');
function invoke(action){const r=spawnSync(php,[driver,action],{env,encoding:'utf8'});assert.equal(r.status,0,r.stderr||r.stdout);return r.stdout;}
const fixture=JSON.parse(invoke('init'));
fs.mkdirSync(path.join(tmp,'includes'),{recursive:true});
for(const file of fs.readdirSync('includes'))if(file!=='bootstrap.php')fs.symlinkSync(path.resolve('includes',file),path.join(tmp,'includes',file));
fs.copyFileSync('profile-promotion-click.php',path.join(tmp,'profile-promotion-click.php'));
fs.writeFileSync(path.join(tmp,'includes/bootstrap.php'),`<?php
define('STONEFELLOW_ROOT',dirname(__DIR__));
require getenv('VP3_PA3_ROOT').'/tests/fixtures/profile-promotion-native-click-v1.php';
session_start();
`);
fs.writeFileSync(path.join(tmp,'issue.php'),`<?php
require __DIR__.'/includes/bootstrap.php';
pa3_login((int)($_GET['visitor']??2));
$key=str_repeat('a',32);$record=profile_product_promotion_key_record_v1(db(),$key);
header('Content-Type: application/json');
echo json_encode(['url'=>profile_product_promotion_click_url_v1($record['card'],'profile_agent',(int)($_GET['conversation']??11))],JSON_THROW_ON_ERROR);
`);
fs.writeFileSync(path.join(tmp,'login.php'),`<?php
require __DIR__.'/includes/bootstrap.php';
$secret=$_SESSION['vp3_promotion_click_secret_v1']??null;
pa3_login((int)($_GET['visitor']??3));
$_SESSION['vp3_promotion_click_secret_v1']=$secret;
echo 'ok';
`);
const reservation=net.createServer();await new Promise(resolve=>reservation.listen(0,'127.0.0.1',resolve));
const port=reservation.address().port;await new Promise(resolve=>reservation.close(resolve));
const base=`http://127.0.0.1:${port}`;
const server=spawn(php,['-d','display_errors=0','-S',`127.0.0.1:${port}`,'-t',tmp],{env,stdio:['ignore','ignore','pipe']});
let logs='',checks=0;server.stderr.on('data',data=>logs+=data);
const jar=new Map([['sf_profile_visitor','a'.repeat(64)],['sf_profile_conversation','b'.repeat(64)]]);
async function request(url,{method='GET',cookies=jar,headers={}}={}){
  const response=await fetch(base+url,{method,redirect:'manual',headers:{Cookie:[...cookies].map(([key,value])=>`${key}=${value}`).join('; '),...headers}});
  if(cookies===jar)for(const cookie of response.headers.getSetCookie()){const [pair]=cookie.split(';'),at=pair.indexOf('=');jar.set(pair.slice(0,at),pair.slice(at+1));}
  return response;
}
async function issue(query=''){return (await (await request('/issue.php'+query)).json()).url;}
const state=()=>JSON.parse(invoke('state'));
function check(ok,label){assert.ok(ok,label);checks++;console.log('PASS '+label);}
function untracked(response){return response.status===303&&response.headers.get('location')==='/fixture-owner/product/field-guide';}
try{
  let ready=false;for(let i=0;i<80;i++){try{await request('/missing');ready=true;break;}catch{await new Promise(resolve=>setTimeout(resolve,30));}}
  assert.ok(ready,logs);
  let link=await issue();
  check(untracked(await request(link,{method:'HEAD'}))&&state().length===0,'HEAD follows the product route without creating native click proof');
  check(untracked(await request(link,{headers:{Purpose:'prefetch'}}))&&state().length===0,'prefetch cannot create native click proof');
  check(untracked(await request(link,{cookies:new Map(jar).set('PHPSESSID','different-fixture-session')}))&&state().length===0,'a different PHP session cannot claim a first-party click');
  check(untracked(await request(link,{cookies:new Map(jar).set('sf_profile_conversation','c'.repeat(64))}))&&state().length===0,'current native browser credential is checked at the real redirect endpoint');
  const tampered=new URL(base+link);tampered.searchParams.set('conversation','12');
  check(untracked(await request(tampered.pathname+tampered.search))&&state().length===0,'tampered public conversation is rejected before attribution');
  const privateLink=await issue('?conversation=12');
  check(untracked(await request(privateLink))&&state().length===0,'a legitimately signed private-only Chat number cannot become a public association');
  link=await issue();let response=await request(link),rows=state();
  check(response.status===303&&new URL(response.headers.get('location'),base).searchParams.get('promotion_click')===rows[0]?.click_id&&rows.length===1,'successful native redirect returns exactly its durable accepted click token');
  const proof=JSON.parse(rows[0].native_context_json_v1);
  check(proof.public_conversation_id===fixture.cid&&proof.public_conversation_id===11&&proof.visitor_user_id===2&&proof.publication_id===fixture.key,'HTTP acceptance stores the exact public binding despite equal private Chat numbers');
  check((await response.text())===''&&!response.headers.get('location').includes(proof.credential_hash),'redirect exposes only the opaque click token, without native credentials');
  response=await request(link);
  check(response.status===303&&JSON.stringify(state())===JSON.stringify(rows),'HTTP replay preserves one immutable click and proof');
  await request('/login.php?visitor=3');
  check(untracked(await request(link))&&JSON.stringify(state())===JSON.stringify(rows),'changed signed-in visitor cannot replay an otherwise valid session-signed link');
  const ownerLink=await issue('?visitor=1');
  check(untracked(await request(ownerLink))&&JSON.stringify(state())===JSON.stringify(rows),'owner preview produces no accepted native click');
  link=await issue();invoke('revoke');
  check(untracked(await request(link))&&JSON.stringify(state())===JSON.stringify(rows),'current Profile Chat permission revocation withholds native acceptance');
  invoke('pause');response=await request(link);
  check(response.status===404&&JSON.stringify(state())===JSON.stringify(rows),'paused publication cannot obtain a new tracked redirect');
  console.log(`PROFILE_PROMOTION_NATIVE_CLICK_HTTP_V1=PASS driver=${fixture.driver} checks=${checks}`);
}catch(error){console.error(logs.slice(-5000));throw error;}finally{
  if(server.exitCode===null&&server.signalCode===null){const stopped=new Promise(resolve=>server.once('exit',resolve));server.kill();await stopped;}
  const cleanup=spawnSync(php,[path.resolve('tests/fixtures/profile-conversation-checkout-pa5-db.php'),'cleanup',config],{env,encoding:'utf8'});
  assert.equal(cleanup.status,0,cleanup.stderr);fs.rmSync(tmp,{recursive:true,force:true});
}
