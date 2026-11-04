import assert from 'node:assert/strict';
import {execFileSync, spawn} from 'node:child_process';
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
require_once ${JSON.stringify(root+'/includes/schema-metadata.php')};
function current_user(): ?array {
    return isset($_SERVER['HTTP_X_FIXTURE_USER'])?['id'=>(int)$_SERVER['HTTP_X_FIXTURE_USER']]:null;
}
function has_permission(string $permission,array $user): bool {
    return ($_SERVER['HTTP_X_FIXTURE_DENIED']??'')!==$permission;
}
function personal_capability_has_v242(string $permission,?array $user=null): bool {
    return $user !== null && has_permission($permission,$user);
}
function table_exists(string $table): bool { return schema_metadata_table_exists(db(),$table); }
function csrf_token(): string { return 'fixture-csrf'; }
function db(): PDO {
    static $pdo=null;
    if($pdo)return $pdo;
    $pdo=new PDO('sqlite:'.STONEFELLOW_ROOT.'/fixture.sqlite');
    $pdo->exec('PRAGMA foreign_keys=ON');
    if(!vp3_gelato_schema_ready_v1($pdo)){
        $pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY)');
        $pdo->exec('INSERT INTO users VALUES (11),(12)');
        $pdo->exec('CREATE TABLE artist_transcript_folders_v177 (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_by_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            folder_name VARCHAR(80) NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(created_by_user_id, folder_name)
        )');
        vp3_gelato_schema_v1($pdo);
        vp3_gelato_knowledge_schema_v1($pdo);
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
const php=process.env.VP3_PHP_BINARY||'php';
const child=spawn(php,['-S','127.0.0.1:'+port,'-t',directory],
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
function folderCount() {
    const sql = 'SELECT COUNT(*) FROM artist_transcript_folders_v177';
    return Number(execFileSync(php,['-r',`$pdo=new PDO('sqlite:'.$argv[1]); echo $pdo->query('${sql}')->fetchColumn();`,
        path.join(directory,'fixture.sqlite')],{encoding:'utf8'}));
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
    assert.equal(owner.body.canCreateKnowledgeFolder,true);
    assert.equal(owner.body.connections[0].knowledgeFolder,null);
    assert.equal(folderCount(),0,'GET provisions no folder for a legacy connection');
    assert.ok(!JSON.stringify(owner.body).includes('fixture-private-ciphertext'));
    assert.deepEqual((await request(12)).body.connections,[]);

    const createFolder={action:'createKnowledgeFolder',connectionId:connection,csrf_token:'fixture-csrf'};
    assert.equal((await request(11,{...createFolder,csrf_token:'bad'})).status,419);
    assert.equal((await request(11,createFolder,'personal_knowledge.access')).status,403);
    assert.equal((await request(11,createFolder,'personal_knowledge.manage')).status,403);
    assert.equal((await request(12,createFolder)).status,404);
    assert.equal(folderCount(),0,'Denied requests cannot provision a folder');
    const created=await request(11,createFolder);
    assert.equal(created.body.ok,true);
    const folder=created.body.knowledgeFolder;
    assert.ok(Number.isSafeInteger(folder.id) && folder.id > 0);
    assert.equal(folder.name,'Food Monster');
    assert.deepEqual((await request(11,createFolder)).body.knowledgeFolder,folder);
    assert.equal(folderCount(),1,'Retrying folder creation reuses the same folder');
    assert.deepEqual((await request(11)).body.connections[0].knowledgeFolder,folder);
    const withoutManage=(await request(11,null,'personal_knowledge.manage')).body;
    assert.equal(withoutManage.canCreateKnowledgeFolder,false);
    assert.deepEqual(withoutManage.connections[0].knowledgeFolder,folder);
    const withoutAccess=(await request(11,null,'personal_knowledge.access')).body;
    assert.equal(withoutAccess.canCreateKnowledgeFolder,false);
    assert.equal(withoutAccess.connections[0].knowledgeFolder,null);
    assert.deepEqual((await request(12)).body.connections,[]);

    assert.equal((await request(11,{action:'disconnect',connectionId:connection})).status,419);
    const disconnect={action:'disconnect',connectionId:connection,csrf_token:'fixture-csrf'};
    assert.equal((await request(12,disconnect)).status,404);
    assert.equal((await request(11,{...disconnect,owner_user_id:12})).status,400);
    assert.equal((await request(11,{action:'connect',csrf_token:'fixture-csrf',origin:'http://localhost',code:'fake'})).status,422);
    assert.equal((await request(11,{action:'connect',csrf_token:'fixture-csrf',origin:'x'.repeat(4096)})).status,413);
    assert.deepEqual(await request(11,disconnect),{status:200,cache:'no-store, private',body:{ok:true,revokedRemotely:false}});
    assert.deepEqual((await request(11)).body.connections,[]);
    assert.equal(folderCount(),1,'Disconnect must preserve saved Knowledge');
    console.log('Connected Gelato API private authorization, Knowledge and revocation checks passed.');
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
