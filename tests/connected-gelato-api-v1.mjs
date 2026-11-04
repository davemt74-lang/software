import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {copyFileSync, mkdirSync, mkdtempSync, rmSync, writeFileSync} from 'node:fs';
import net from 'node:net';
import {tmpdir} from 'node:os';
import path from 'node:path';

const directory=mkdtempSync(path.join(tmpdir(),'vp3-gelato-api-'));
const root=process.cwd();
mkdirSync(path.join(directory,'api'));
mkdirSync(path.join(directory,'includes'));
copyFileSync('api/connected-gelato-v1.php',path.join(directory,'api/connected-gelato-v1.php'));
writeFileSync(path.join(directory,'includes/connected-gelato-v1.php'),
    '<?php require_once '+JSON.stringify(root+'/includes/connected-gelato-v1.php')+';');
const connection='a'.repeat(32);
writeFileSync(path.join(directory,'includes/bootstrap.php'),`<?php
define('STONEFELLOW_ROOT',dirname(__DIR__));
require_once __DIR__.'/connected-gelato-v1.php';
function current_user(): ?array {
    return isset($_SERVER['HTTP_X_FIXTURE_USER'])?['id'=>(int)$_SERVER['HTTP_X_FIXTURE_USER']]:null;
}
function has_permission(string $permission,array $user): bool {
    return ($_SERVER['HTTP_X_FIXTURE_DENIED']??'')!==$permission;
}
function csrf_token(): string { return 'fixture-csrf'; }
function db(): PDO {
    static $pdo=null;
    if($pdo)return $pdo;
    $pdo=new PDO('sqlite:'.STONEFELLOW_ROOT.'/fixture.sqlite');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY)');
    if(!vp3_gelato_schema_ready_v1($pdo)){
        vp3_gelato_schema_v1($pdo);
        $pdo->prepare('INSERT INTO vp3_gelato_connections_v1 VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(['${connection}',11,'e2425066-4541-4130-8977-e5a271eb41f0','${'b'.repeat(32)}','http://localhost',
                'Test','Food Monster','["menu"]','[]','fixture-private-ciphertext','2026-11-04T00:00:00Z']);
    }
    return $pdo;
}
`);

const reserve=net.createServer();
await new Promise(resolve=>reserve.listen(0,'127.0.0.1',resolve));
const port=reserve.address().port;
await new Promise(resolve=>reserve.close(resolve));
const child=spawn(process.env.VP3_PHP_BINARY||'php',['-S','127.0.0.1:'+port,'-t',directory],
    {stdio:['ignore','ignore','pipe']});
let logs='';
child.stderr.on('data',chunk=>{logs+=chunk;});
const url='http://127.0.0.1:'+port+'/api/connected-gelato-v1.php';
async function request(user, body=null, denied='') {
    const headers={'Content-Type':'application/json','X-Fixture-Denied':denied};
    if(user!==null)headers['X-Fixture-User']=String(user);
    const response=await fetch(url,{method:body===null?'GET':'POST',headers,body:body===null?undefined:JSON.stringify(body)});
    return {status:response.status,cache:response.headers.get('Cache-Control'),body:await response.json()};
}

try {
    for(let attempt=0;attempt<50;attempt++){
        try{await request(null);break;}catch{await new Promise(resolve=>setTimeout(resolve,50));}
    }
    assert.equal((await request(null)).status,403);
    assert.equal((await request(11,null,'account.access')).status,403);
    assert.equal((await request(11,null,'chat.access')).status,403);
    const owner=await request(11);
    assert.equal(owner.status,200);
    assert.match(owner.cache,/no-store/);
    assert.equal(owner.body.connections[0].agentName,'Food Monster');
    assert.ok(!JSON.stringify(owner.body).includes('fixture-private-ciphertext'));
    assert.deepEqual((await request(12)).body.connections,[]);
    assert.equal((await request(11,{action:'disconnect',connectionId:connection})).status,419);
    const disconnect={action:'disconnect',connectionId:connection,csrf_token:'fixture-csrf'};
    assert.equal((await request(12,disconnect)).status,404);
    assert.equal((await request(11,{...disconnect,owner_user_id:12})).status,400);
    assert.equal((await request(11,{action:'connect',csrf_token:'fixture-csrf',origin:'http://localhost',code:'fake'})).status,422);
    assert.equal((await request(11,{action:'connect',csrf_token:'fixture-csrf',origin:'x'.repeat(4096)})).status,413);
    assert.deepEqual(await request(11,disconnect),{status:200,cache:'no-store, private',body:{ok:true,revokedRemotely:false}});
    assert.deepEqual((await request(11)).body.connections,[]);
    console.log('Connected Gelato API private authorization and revocation checks passed.');
} catch(error) {
    console.error(logs.slice(-3000));
    throw error;
} finally {
    if(child.exitCode===null&&child.signalCode===null){
        const exited=new Promise(resolve=>child.once('exit',resolve));
        child.kill();
        await exited;
    }
    rmSync(directory,{recursive:true,force:true});
}
