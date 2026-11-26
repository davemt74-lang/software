import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import net from 'node:net';
import {spawn} from 'node:child_process';

// Exercise the real endpoint, PHP sessions, current_user and permission reads.
// Only the already-covered relay I/O is replaced to interleave authority changes.
const root=process.cwd(),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'vp3-inventory-api-'));
const php=process.env.VP3_PHP_BINARY||'php';
for(const dir of ['api','includes','sessions'])fs.mkdirSync(path.join(tmp,dir));
fs.copyFileSync('api/homeserver-model-inventory-v1.php',path.join(tmp,'api/homeserver-model-inventory-v1.php'));
fs.writeFileSync(path.join(tmp,'includes/bootstrap.php'),`<?php
declare(strict_types=1);
session_start();
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER,password_hash TEXT,email TEXT,display_name TEXT,role TEXT,avatar_path TEXT,last_login_at TEXT)');
$pdo->exec('CREATE TABLE permissions(permission_key TEXT)');
$pdo->exec('CREATE TABLE role_permissions(role TEXT,permission_key TEXT)');
$pdo->exec("INSERT INTO role_permissions VALUES('fan','account.access'),('fan','chat.access')");
foreach([1,2] as $id)$pdo->prepare("INSERT INTO users VALUES(?,1,?,?,?,'fan','','')")->execute([$id,'fixture-password-'.$id,'fixture'.$id.'@example.test','Fixture '.$id]);
function db():PDO{return $GLOBALS['pdo'];}
require getenv('VP3_INVENTORY_ROOT').'/includes/functions.php';
require getenv('VP3_INVENTORY_ROOT').'/includes/permissions.php';
require getenv('VP3_INVENTORY_ROOT').'/includes/auth.php';
`);
fs.writeFileSync(path.join(tmp,'boot.php'),`<?php
require __DIR__.'/includes/bootstrap.php';
$id=($_GET['owner']??'1')==='2'?2:1;
$_SESSION['user_id']=$id;$_SESSION['auth_password_fingerprint']=auth_password_fingerprint($id,'fixture-password-'.$id);
header('Content-Type: application/json');echo json_encode(['csrf'=>csrf_token()]);
`);
fs.writeFileSync(path.join(tmp,'includes/homeserver-model-inventory-read-v1.php'),`<?php
function vp3_homeserver_inventory_read_v1(int $owner):array{
    $mode=$_SERVER['HTTP_X_FIXTURE_MODE']??'';
    file_put_contents(getenv('VP3_INVENTORY_LOG'),json_encode(['owner'=>$owner,'session_active'=>session_status()===PHP_SESSION_ACTIVE,'mode'=>$mode])."\n",FILE_APPEND|LOCK_EX);
    if($mode==='provider_error')throw new RuntimeException('PRIVATE_PROVIDER_SQLSTATE',503);
    if($mode==='pairing_error')throw new RuntimeException('PRIVATE_PAIRING_SECRET',409);
    if($mode==='password')db()->exec("UPDATE users SET password_hash='replacement-password' WHERE id=1");
    if($mode==='disable')db()->exec('UPDATE users SET is_active=0 WHERE id=1');
    if($mode==='revoke_chat')db()->exec("DELETE FROM role_permissions WHERE permission_key='chat.access'");
    if($mode==='revoke_account')db()->exec("DELETE FROM role_permissions WHERE permission_key='account.access'");
    if($mode==='demote')db()->exec("UPDATE users SET role='investor' WHERE id=1");
    if(in_array($mode,['logout','switch','same_session_switch','rotate_csrf'],true)){
        // Another request changes persisted session state while this request
        // retains its old in-memory identity and original incoming cookie.
        $prior=$_SESSION;$original=session_id();session_start();
        if($mode==='logout')unset($_SESSION['user_id'],$_SESSION['auth_password_fingerprint']);
        if($mode==='rotate_csrf')$_SESSION['csrf_token']='replacement-csrf';
        if(in_array($mode,['switch','same_session_switch'],true)){
            if($mode==='switch')session_regenerate_id(true);
            $_SESSION['user_id']=2;$_SESSION['auth_password_fingerprint']=auth_password_fingerprint(2,'fixture-password-2');
        }
        session_write_close();header_remove('Set-Cookie');session_id($original);$_SESSION=$prior;
    }
    return ['version'=>'vp3.local-model-inventory.v1','state'=>'reported','configured_model'=>'private-owner-'.$owner.':latest',
        'configured_model_reported'=>true,'truncated'=>false,'models'=>[['model'=>'private-owner-'.$owner.':latest','configured_default'=>true,
        'execution_verified'=>false,'routing_eligible'=>false]]];
}
`);
const reservation=net.createServer();
await new Promise(resolve=>reservation.listen(0,'127.0.0.1',resolve));
const port=reservation.address().port;
await new Promise(resolve=>reservation.close(resolve));
const logFile=path.join(tmp,'relay.log');
const child=spawn(php,['-d','display_errors=0','-d','session.save_path='+path.join(tmp,'sessions'),'-S',`127.0.0.1:${port}`,'-t',tmp],{
    env:{...process.env,VP3_INVENTORY_ROOT:root,VP3_INVENTORY_LOG:logFile},stdio:['ignore','ignore','pipe']});
let logs='',checks=0;child.stderr.on('data',value=>logs+=value);
const base=`http://127.0.0.1:${port}`;
const check=(ok,label)=>{assert.ok(ok,label);checks++;console.log('PASS '+label);};
const calls=()=>fs.existsSync(logFile)?fs.readFileSync(logFile,'utf8').trim().split('\n').filter(Boolean).map(JSON.parse):[];
async function boot(owner=1){
    const response=await fetch(base+'/boot.php?owner='+owner);
    assert.equal(response.status,200,logs);
    return {cookie:response.headers.getSetCookie().map(value=>value.split(';')[0]).join('; '),...(await response.json())};
}
async function request(actor,fields={},method='POST',mode='',query=''){
    const response=await fetch(base+'/api/homeserver-model-inventory-v1.php'+query,{
        method,headers:{Cookie:actor?.cookie||'','Content-Type':'application/x-www-form-urlencoded','X-Fixture-Mode':mode},
        ...(['GET','HEAD'].includes(method)?{}:{body:new URLSearchParams({csrf_token:actor?.csrf||'',...fields})})});
    const raw=await response.text();let data;
    try{data=raw?JSON.parse(raw):null;}catch{throw new Error(raw+'\n'+logs.slice(-4000));}
    return {response,status:response.status,data,raw};
}
try{
    let ready=false;
    for(let i=0;i<80;i++){
        try{const response=await fetch(base+'/boot.php');if(response.status===200){ready=true;break;}}catch{}
        await new Promise(resolve=>setTimeout(resolve,25));
    }
    assert.ok(ready,logs.slice(-4000));
    let actor=await boot(),r=await request(actor);
    check(r.status===200&&r.data.ok&&r.data.inventory.configured_model==='private-owner-1:latest','current account receives its inventory');
    check(r.response.headers.get('cache-control')==='no-store, private'&&r.response.headers.get('x-content-type-options')==='nosniff','private response is not cacheable and uses JSON safety headers');
    check(calls().length===1&&calls()[0].owner===1&&!calls()[0].session_active,'relay receives only the authenticated owner after releasing the session lock');
    check(r.response.headers.getSetCookie().length===0,'normal delivery recheck does not rewrite the browser cookie');
    r=await request(actor);
    check(r.status===429&&r.response.headers.get('retry-after')==='15'&&calls().length===1,'same-session rapid refresh is throttled without another relay call');
    actor=await boot(2);r=await request(actor);
    check(r.status===200&&r.data.inventory.configured_model==='private-owner-2:latest'&&calls().at(-1).owner===2,'another account receives only its own inventory');
    const before=calls().length;
    actor=await boot();
    for(const method of ['GET','PUT','DELETE']){
        r=await request(actor,{},method);
        check(r.status===405&&r.response.headers.get('allow')==='POST',method+' cannot refresh inventory');
    }
    for(const csrf of ['', 'wrong']){
        r=await request(actor,{csrf_token:csrf});check(r.status===419,'missing or incorrect CSRF rejects refresh');
    }
    r=await request(actor,{owner:'2'});check(r.status===422,'request-selected owner is rejected');
    r=await request(actor,{},'POST','','?owner=2');check(r.status===422,'query-selected owner is rejected');
    r=await request(null);check(r.status===403,'anonymous request cannot refresh inventory');
    check(calls().length===before,'rejected requests perform no relay I/O');
    for(const mode of ['logout','switch','same_session_switch','password','disable','revoke_chat','revoke_account','demote','rotate_csrf']){
        actor=await boot();const count=calls().length;r=await request(actor,{},'POST',mode);
        check(calls().length===count+1,'authority test reaches relay boundary: '+mode);
        check(r.status===403&&r.data.ok===false&&!r.raw.includes('private-owner-'),'changed authority denies delayed inventory: '+mode);
        check(r.response.headers.getSetCookie().length===0,'delayed recheck preserves the newer browser cookie: '+mode);
    }
    for(const [mode,status] of [['provider_error',503],['pairing_error',409]]){
        actor=await boot();r=await request(actor,{},'POST',mode);
        check(r.status===status&&r.data.ok===false&&!/PRIVATE_|SQLSTATE|private-owner-/.test(r.raw),'relay failure returns only a safe public message: '+mode);
    }
    check(calls().every(call=>call.session_active===false),'all relay calls release the browser session lock');
    console.log('HOMESERVER_MODEL_INVENTORY_API_V1=PASS checks='+checks);
}finally{
    child.kill('SIGTERM');if(child.exitCode===null)await new Promise(resolve=>child.once('exit',resolve));
    fs.rmSync(tmp,{recursive:true,force:true});
}
