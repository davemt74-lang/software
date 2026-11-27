import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn,spawnSync} from 'node:child_process';
const root=process.cwd(),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-profile-phone-'));
fs.mkdirSync(path.join(tmp,'api'));fs.mkdirSync(path.join(tmp,'includes'));
fs.copyFileSync('api/profile-phone-v1.php',path.join(tmp,'api/profile-phone-v1.php'));
fs.symlinkSync(path.join(root,'includes/profile-phone-v1.php'),path.join(tmp,'includes/profile-phone-v1.php'));
fs.writeFileSync(path.join(tmp,'includes/agent-observation-authority-v1.php'),'<?php // Authority is controlled by the isolated fixture.');
fs.writeFileSync(path.join(tmp,'includes/bootstrap.php'),`<?php
 define('VP3_PROFILE_PHONE_TEST_HELPERS_ONLY',true);
 require (string)getenv('VP3_PROFILE_PHONE_TEST_ROOT').'/tests/profile-phone-v1.php';
 function db(): PDO{return $GLOBALS['pdo'];}
 function current_user(): ?array{$id=(int)($_SERVER['HTTP_X_FIXTURE_USER']??0);return $id>0?profile_user_row(db(),$id):null;}
 function has_permission(string $permission,array $user):bool{return !empty($user['is_active']);}
 function personal_capability_has_v242(string $permission,array $user):bool{return ($_SERVER['HTTP_X_FIXTURE_DENY']??'')!=='1';}
 function csrf_token():string{return 'profile-phone-fixture';}
 function profile_username_valid(string $name):bool{return preg_match('/^[a-z][a-z0-9_-]{1,59}$/D',$name)===1;}
 function profile_by_username(PDO $pdo,string $name):?array{return $name==='dave'?($pdo->query('SELECT * FROM user_profiles WHERE user_id=1')->fetch()?:null):null;}
`);
const env={...process.env,VP3_PROFILE_PHONE_TEST_DSN:'sqlite:'+path.join(tmp,'state.db'),VP3_PROFILE_PHONE_TEST_ROOT:root};
const php=process.env.VP3_PHP_BINARY||'php';
const install=spawnSync(php,['-r',`define('VP3_PROFILE_PHONE_TEST_HELPERS_ONLY',true);require 'tests/profile-phone-v1.php';vp3_profile_phone_install_v1($pdo);`],{env,encoding:'utf8'});assert.equal(install.status,0,install.stderr);
const reserve=net.createServer();await new Promise(r=>reserve.listen(0,'127.0.0.1',r));const port=reserve.address().port;await new Promise(r=>reserve.close(r));
const child=spawn(php,['-d','display_errors=0','-S',`127.0.0.1:${port}`,'-t',tmp],{env,stdio:['ignore','ignore','pipe']});let logs='';child.stderr.on('data',d=>logs+=d);
const base=`http://127.0.0.1:${port}/api/profile-phone-v1.php`;
let checks=0;const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
async function request({method='GET',query='',user=1,owner='1',body,deny=false,raw}={}){
 const r=await fetch(base+query,{method,headers:{'X-Fixture-User':String(user),'X-VP3-Profile-Owner':owner,'X-Fixture-Deny':deny?'1':'0','Content-Type':'application/json'},...(method==='POST'?{body:raw??JSON.stringify(body)}:{})});return {status:r.status,data:await r.json(),headers:r.headers};
}
try{
 let ready=false;for(let i=0;i<100;i++){try{if((await request()).status===200){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,20));}assert.ok(ready,logs);
 let r=await request();check(r.status===200&&r.data.owner_user_id===1&&r.data.numbers.length===1,'owner can load only eligible assigned lines');
 r=await request({user:0});check(r.status===403,'anonymous caller cannot read owner settings');
 r=await request({user:2,owner:'1'});check(r.status===403,'account-switch header cannot read prior owner settings');
 r=await request({deny:true});check(r.status===403,'Profile permission denial blocks owner settings');
 const input={enabled:true,number_id:11,revision:0,csrf_token:'profile-phone-fixture'};
 r=await request({method:'POST',body:{...input,csrf_token:'bad'}});check(r.status===419,'owner write requires current CSRF token');
 r=await request({method:'POST',body:input,user:0});check(r.status===403,'anonymous request cannot publish a line');
 r=await request({method:'POST',body:{...input,number_id:12}});check(r.status===409,'HTTP cannot publish another owner line');
 r=await request({method:'POST',body:{...input,to_number:'+16025550999'}});check(r.status===422,'HTTP rejects a caller-supplied destination');
 r=await request({method:'POST',raw:'{invalid'});check(r.status===422,'invalid JSON receives typed failure');
 r=await request({method:'POST',raw:' '.repeat(2050)});check(r.status===413,'oversized settings bodies are bounded');
 r=await request({method:'POST',body:input});check(r.status===200&&r.data.active&&r.data.revision===1,'exact owner approval saves a current public line');
 r=await request({method:'POST',body:input});check(r.status===409,'repeated stale save cannot overwrite settings');
 r=await request({user:0,owner:'',query:'?action=public&username=dave'});
 check(r.status===200&&r.data.href==='tel:+16025550101'&&!('numbers' in r.data)&&!('owner_user_id' in r.data),'anonymous public response exposes only the explicitly published line');
 check(r.headers.get('cache-control')==='no-store, private'&&r.headers.get('x-content-type-options')==='nosniff','public calling is uncached and typed');
 r=await request({user:0,query:'?action=public&username=dave&number_id=12'});check(r.status===404,'public query cannot override the chosen line');
 r=await request({user:0,query:'?action=public&username=missing'});check(r.status===404,'unknown profile has no published phone');
 r=await request({method:'POST',body:{enabled:false,number_id:0,revision:1,csrf_token:'profile-phone-fixture'}});check(r.status===200&&!r.data.active,'owner can revoke the public action');
 r=await request({user:0,query:'?action=public&username=dave'});check(!r.data.enabled&&r.data.href==='','next public read immediately reflects revocation');
 console.log(`PROFILE_PHONE_API_V1=PASS ${checks} checks`);
}finally{child.kill('SIGTERM');await new Promise(r=>child.exitCode!==null?r():child.once('exit',r));fs.rmSync(tmp,{recursive:true,force:true});}
