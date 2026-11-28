import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';

const root=path.resolve(new URL('..',import.meta.url).pathname);
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-profile-radar-api-'));
fs.mkdirSync(path.join(tmp,'api'));fs.mkdirSync(path.join(tmp,'includes'));
fs.copyFileSync(path.join(root,'api/agent-radar.php'),path.join(tmp,'api/agent-radar.php'));
fs.copyFileSync(path.join(root,'tests/fixtures/profile-radar-api-v1.php'),path.join(tmp,'includes/bootstrap.php'));
fs.writeFileSync(path.join(tmp,'session.php'),`<?php
require __DIR__.'/includes/bootstrap.php';
$action=$_GET['action']??'state';
if($action==='login')$_SESSION=['user_id'=>1,'account_allowed'=>true,'profile_allowed'=>true];
if($action==='logout')$_SESSION=[];
if($action==='switch')$_SESSION['user_id']=2;
if($action==='revoke')$_SESSION['profile_allowed']=false;
if($action==='destroy'){$_SESSION=[];session_destroy();}
header('Content-Type: application/json');echo json_encode(['ok'=>true,'user_id'=>$_SESSION['user_id']??0]);
`);
const reservation=net.createServer();await new Promise(resolve=>reservation.listen(0,'127.0.0.1',resolve));
const port=reservation.address().port;await new Promise(resolve=>reservation.close(resolve));
const child=spawn(process.env.VP3_PHP_BINARY||'php',['-d','display_errors=0','-S',`127.0.0.1:${port}`,'-t',tmp],
  {detached:true,env:{...process.env,PHP_CLI_SERVER_WORKERS:'4',VP3_RADAR_API_FIXTURE:tmp},stdio:['ignore','ignore','pipe']});
let logs='',checks=0,cookie='';child.stderr.on('data',data=>logs+=data);
const base=`http://127.0.0.1:${port}`,entered=path.join(tmp,'entered'),release=path.join(tmp,'release');
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function session(action){
  const response=await fetch(base+'/session.php?action='+action,{headers:{Cookie:cookie},signal:AbortSignal.timeout(2500)});
  const set=response.headers.get('set-cookie');if(set)cookie=set.split(';')[0];return response.json();
}
async function request(options={}){
  const response=await fetch(base+'/api/agent-radar.php',{...options,headers:{Cookie:cookie,...options.headers},signal:AbortSignal.timeout(6000)});
  const text=await response.text();return {response,text,data:JSON.parse(text)};
}
try{
  let ready=false;for(let n=0;n<60;n++){try{await session('state');ready=true;break;}catch{await sleep(50);}}
  assert.ok(ready,logs);await session('login');
  let result=await request();
  check(result.response.status===200&&result.data.radar.owner_user_id===1,'authorized owner reads the current private Radar');
  check(result.response.headers.get('cache-control')==='no-store','private Radar response is never cacheable');
  result=await request({method:'POST'});
  check(result.response.status===405&&result.response.headers.get('allow')==='GET','non-read methods cannot trigger relationship refresh');
  result=await request({headers:{'X-Fixture-Fault':'storage'}});
  check(result.response.status===503&&!result.text.includes('SECRET')&&result.data.ok===false,'storage failures return a safe retryable response');

  for(const action of ['logout','switch','revoke','destroy']){
    await session('login');fs.rmSync(entered,{force:true});fs.rmSync(release,{force:true});
    const pending=request({headers:{'X-Fixture-Pause':'1'}});
    for(let n=0;n<100&&!fs.existsSync(entered);n++)await sleep(10);
    assert.ok(fs.existsSync(entered),'Radar read entered its expensive domain phase');
    check(JSON.parse(fs.readFileSync(entered,'utf8')).session_status===1,`${action}: expensive Radar work releases the PHP session lock`);
    // This is another worker using the same real session cookie. It must finish
    // while the original read is still paused behind its explicit test barrier.
    await session(action);fs.writeFileSync(release,'1');result=await pending;
    check(result.response.status===403&&!result.text.includes('PRIVATE_OWNER_'),`${action}: a changed browser session withholds the old owner snapshot`);
    check(!result.response.headers.has('set-cookie'),`${action}: revalidation cannot replace a newer browser cookie`);
  }
  await session('login');result=await request();
  check(result.response.status===200&&result.data.radar.owner_user_id===1,'fresh authorized reads recover after the access-change cases');
  console.log(`PROFILE_RADAR_API_V1=PASS (${checks} checks; real concurrent PHP sessions)`);
}catch(error){console.error(logs.slice(-2500));throw error;}
finally{
  fs.writeFileSync(release,'1');
  try{process.kill(-child.pid,'SIGTERM');}catch{}
  await Promise.race([new Promise(resolve=>child.once('exit',resolve)),sleep(1000)]);
  fs.rmSync(tmp,{recursive:true,force:true});
}
