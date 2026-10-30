import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';
const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-reminders-api-'));
for(const name of ['api','includes'])fs.mkdirSync(path.join(tmp,name));
for(const name of ['api/agent-reminders-v1.php','includes/agent-reminders-v1.php'])fs.copyFileSync(name,path.join(tmp,name));
fs.writeFileSync(path.join(tmp,'includes/bootstrap.php'),`<?php
require_once __DIR__.'/agent-reminders-v1.php';
function db(){static $pdo;if(!$pdo){$pdo=new PDO('sqlite:'.__DIR__.'/../state.sqlite');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);reminder_schema_v1($pdo);$pdo->exec('CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,is_active INTEGER)');$pdo->exec('INSERT OR IGNORE INTO users VALUES(1,1)');}return $pdo;}
function table_exists($table){$q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return(bool)$q->fetchColumn();}
function current_user(){return isset($_SERVER['HTTP_X_FIXTURE_ANONYMOUS'])?null:['id'=>(int)($_SERVER['HTTP_X_FIXTURE_USER']??1)];}
function csrf_token(){return 'fixture';}function has_permission(...$args){return !isset($_SERVER['HTTP_X_FIXTURE_DENIED']);}function url($path){return $path;}
`);
const reserve=net.createServer();await new Promise(r=>reserve.listen(0,'127.0.0.1',r));const port=reserve.address().port;await new Promise(r=>reserve.close(r));
const child=spawn(process.env.VP3_PHP_BINARY||'php',['-S',`127.0.0.1:${port}`,'-t',tmp],{stdio:['ignore','ignore','pipe']});let logs='';child.stderr.on('data',x=>logs+=x);const url=`http://127.0.0.1:${port}/api/agent-reminders-v1.php`;
const input={owner_key:'1',csrf_token:'fixture',action:'claim'};let checks=0;
const check=async(name,fn)=>{await fn();checks++;console.log('PASS '+name);};
async function post(body=input,headers={}){const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json',...headers},body:typeof body==='string'?body:JSON.stringify(body)});return {r,data:await r.json()};}
try{
 for(let i=0;i<50;i++){try{await fetch(url);break;}catch{await new Promise(r=>setTimeout(r,50));}}
 await check('delivery endpoint requires POST and login',async()=>{assert.equal((await fetch(url)).status,405);assert.equal((await post(input,{'X-Fixture-Anonymous':'1'})).r.status,401);});
 await check('delivery enforces CSRF, owner and Chat permission',async()=>{assert.equal((await post({...input,csrf_token:'wrong'})).r.status,419);assert.equal((await post({...input,owner_key:'2'})).r.status,409);assert.equal((await post(input,{'X-Fixture-Denied':'1'})).r.status,403);});
 await check('browser cannot submit a model mutation through delivery API',async()=>{assert.equal((await post({...input,action:'chat',message:'create reminder'})).r.status,422);assert.equal((await post({...input,action:'delete',id:1})).r.status,422);});
 await check('malformed and oversized requests rejected',async()=>{assert.equal((await post('{')).r.status,422);assert.equal((await post('x'.repeat(12001))).r.status,413);assert.equal((await post({...input,action:'ack',id:1,token:'wrong'})).r.status,422);});
 await check('authenticated delivery poll succeeds with an empty ledger',async()=>{const {r,data}=await post();assert.equal(r.status,200);assert.equal(data.item,null);});
 console.log(`AGENT_REMINDERS_API=PASS (${checks} cases)`);
}finally{child.kill();await new Promise(r=>child.once('exit',r));fs.rmSync(tmp,{recursive:true,force:true});if(logs.includes('Fatal error'))throw Error(logs);}
