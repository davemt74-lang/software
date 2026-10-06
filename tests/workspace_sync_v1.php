<?php
declare(strict_types=1);
$dsn=getenv('VP3_WORKSPACE_MYSQL_DSN')?:'sqlite::memory:';
$pdo=new PDO($dsn,'root',getenv('VP3_WORKSPACE_MYSQL_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$mysql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
function db():PDO{return $GLOBALS['pdo'];}
function table_exists(string $table):bool{
 $pdo=db();if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){$s=$pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$s->execute([$table]);return (bool)$s->fetchColumn();}
 $s=$pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->execute([$table]);return (bool)$s->fetchColumn();
}
function column_exists(string $table,string $column):bool{
 $pdo=db();if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')return in_array($column,array_column($pdo->query('PRAGMA table_info(`'.$table.'`)')->fetchAll(),'name'),true);
 $s=$pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$s->execute([$table,$column]);return (bool)$s->fetchColumn();
}
class HomeServerHttpsSessionError extends RuntimeException{}
require __DIR__.'/../includes/homeserver-workspace-sync-v1.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function rejected(callable $call,string $message):void{try{$call();}catch(Throwable $e){return;}throw new RuntimeException($message);}
if(in_array('--blocked-sync',$argv,true)){
    $snapshot=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
    file_put_contents($argv[3],'ready');
    try{workspace_sync_exchange_v1($snapshot,['action'=>'prepare','dataset'=>'profile']);exit(1);}
    catch(HomeServerHttpsSessionError $e){check($e->getCode()===410,'Concurrent revoke must win');echo 'revoked-after-lock';exit;}
}
$tail=$mysql?' ENGINE=InnoDB':'';
$pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,email VARCHAR(190),display_name VARCHAR(120),password_hash VARCHAR(255),is_active INTEGER)'.$tail);
$pdo->exec("INSERT INTO users VALUES(1,'one@example.invalid','Owner one','NEVER_EXPORT',1),(2,'two@example.invalid','Owner two','NEVER_EXPORT',1)");
$pdo->exec('CREATE TABLE homeserver_https_sessions(user_id INTEGER PRIMARY KEY,device_id VARCHAR(50),session_token_hash CHAR(64),status VARCHAR(20))'.$tail);
$session=['user_id'=>1,'device_id'=>'hs-'.str_repeat('a',24),'session_token_hash'=>str_repeat('b',64),'status'=>'active'];
$pdo->prepare('INSERT INTO homeserver_https_sessions VALUES(?,?,?,?)')->execute(array_values($session));
$pdo->exec('CREATE TABLE crm_contacts(id INTEGER PRIMARY KEY,owner_user_id INTEGER,name VARCHAR(100),notes TEXT)'.$tail);
$pdo->exec("INSERT INTO crm_contacts VALUES(1,1,'My contact','owned'),(2,2,'OTHER CONTACT','never')");
$pdo->exec('CREATE TABLE crm_leads(id INTEGER PRIMARY KEY,contact_id INTEGER,title VARCHAR(100))'.$tail);
$pdo->exec("INSERT INTO crm_leads VALUES(1,1,'My lead'),(2,2,'OTHER LEAD')");
$pdo->exec('CREATE TABLE crm_tasks(id INTEGER PRIMARY KEY,lead_id INTEGER,title VARCHAR(100))'.$tail);
$pdo->exec("INSERT INTO crm_tasks VALUES(1,1,'My task'),(2,2,'OTHER TASK')");
$pdo->exec('CREATE TABLE agent_commerce_products_v800(id INTEGER PRIMARY KEY,owner_user_id INTEGER,title VARCHAR(100),metadata_json TEXT)'.$tail);
$metadata=json_encode(['sku'=>'MY-SKU','access_token'=>'NEVER_EXPORT','options'=>['api_key'=>'NEVER_EXPORT','color'=>'blue']],JSON_THROW_ON_ERROR);
$pdo->prepare('INSERT INTO agent_commerce_products_v800 VALUES(1,1,?,?)')->execute(['My product',$metadata]);
$pdo->exec("INSERT INTO agent_commerce_products_v800 VALUES(2,2,'OTHER PRODUCT','{}')");
$pdo->exec('CREATE TABLE personal_workspace_notes(id INTEGER PRIMARY KEY,owner_user_id INTEGER,content TEXT)'.$tail);
$pdo->exec("INSERT INTO personal_workspace_notes VALUES(1,1,'My full note'),(2,2,'OTHER NOTE')");
$pdo->exec('CREATE TABLE personal_workspace_note_parts(id INTEGER PRIMARY KEY,note_id INTEGER,content TEXT,FOREIGN KEY(note_id) REFERENCES personal_workspace_notes(id))'.$tail);
$pdo->exec("INSERT INTO personal_workspace_note_parts VALUES(1,1,'My child'),(2,2,'OTHER CHILD')");
$pdo->exec('CREATE TABLE provider_credentials(id INTEGER PRIMARY KEY,owner_user_id INTEGER,content TEXT)'.$tail);
$pdo->exec("INSERT INTO provider_credentials VALUES(1,1,'NEVER_EXPORT')");
$catalog=workspace_sync_exchange_v1($session,['action'=>'catalog']);
check($catalog['account_id']==='1'&&count($catalog['datasets'])===16,'Complete owned-workspace catalog');
foreach(['profile','contacts','crm','products','workspace_other'] as $dataset){
 $manifest=workspace_sync_exchange_v1($session,['action'=>'prepare','dataset'=>$dataset]);
 $pulled=workspace_sync_exchange_v1($session,['action'=>'pull','dataset'=>$dataset,'revision'=>$manifest['revision'],'offset'=>0]);
 $raw=base64_decode($pulled['chunk'],true);
 check(!str_contains($raw,'OTHER ')&&!str_contains($raw,'two@example')&&!str_contains($raw,'NEVER_EXPORT'),'Account/secret isolation '.$dataset);
 if($dataset==='crm')check(str_contains($raw,'My task')&&str_contains($raw,'My lead'),'Nested ownership chain');
 if($dataset==='workspace_other')check(str_contains($raw,'My full note')&&str_contains($raw,'My child'),'Auto-discovered own data and FK children');
 if($dataset==='products')check(str_contains($raw,'MY-SKU')&&str_contains($raw,'blue'),'Product fields preserved');
}
// Original uploads are scoped through their owned record, never a client path.
$uploads=dirname(__DIR__).'/uploads';if(!is_dir($uploads))mkdir($uploads,0700,true);
$file=$uploads.'/workspace-test-'.bin2hex(random_bytes(6)).'.bin';
$assetBytes=str_repeat("%PDF fixture\n",100000);file_put_contents($file,$assetBytes);
try{
    $pdo->exec('CREATE TABLE knowledge_items(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,title VARCHAR(100),content_text TEXT,file_path TEXT)'.$tail);
    $relative='uploads/'.basename($file);
    $pdo->prepare('INSERT INTO knowledge_items VALUES(1,1,?,?,?)')->execute(['Original file',str_repeat('完整 💡 ',17000),$relative]);
    $manifest=workspace_sync_exchange_v1($session,['action'=>'prepare','dataset'=>'knowledge']);
    $snapshot=workspace_sync_read_v1($pdo,1,'cloud','knowledge');$data=json_decode($snapshot['body_json'],true,512,JSON_THROW_ON_ERROR);
    check(count($data['files'])===1,'Owned uploaded file exported');$asset=$data['files'][0];$copied='';
    for($offset=0;$offset<strlen($assetBytes);$offset+=VP3_WORKSPACE_ASSET_CHUNK){
        $part=workspace_sync_exchange_v1($session,['action'=>'asset','dataset'=>'knowledge','revision'=>$manifest['revision'],'asset_id'=>$asset['asset_id'],'offset'=>$offset]);
        $copied.=base64_decode($part['chunk'],true);
    }
    check($copied===$assetBytes&&$asset['sha256']===hash('sha256',$copied),'Full original file and checksum');
    rejected(fn()=>workspace_sync_exchange_v1($session,['action'=>'asset','dataset'=>'products','revision'=>$manifest['revision'],'asset_id'=>$asset['asset_id'],'offset'=>0]),'Cross-dataset file leak');
    check(workspace_sync_asset_path_v1('../includes/bootstrap.php')===null&&workspace_sync_asset_path_v1('https://outside.invalid/file')===null,'File path scope');
}finally{unlink($file);}
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
rejected(fn()=>workspace_sync_exchange_v1($session,['action'=>'catalog']),'Inactive owner allowed');
$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
$payload=['contract'=>VP3_WORKSPACE_SYNC_V1,'source'=>'homeserver','dataset'=>'knowledge','records'=>[['table'=>'knowledge_items','source_id'=>'1','data'=>['title'=>'Complete document','content'=>str_repeat('完整 💡 ',17000)]]]];
$raw=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$revision=hash('sha256',$raw);
$chunks=[];for($offset=0;$offset<strlen($raw);$offset+=VP3_WORKSPACE_SYNC_CHUNK)$chunks[]=['action'=>'push','dataset'=>'knowledge','revision'=>$revision,'offset'=>$offset,'total_bytes'=>strlen($raw),'chunk'=>base64_encode(substr($raw,$offset,VP3_WORKSPACE_SYNC_CHUNK))];
check(count($chunks)>3,'Full documents span multiple packets');
$first=workspace_sync_exchange_v1($session,$chunks[0]);check(!$first['committed'],'Incomplete transfer never becomes a replica');
check(workspace_sync_read_v1($pdo,1,'homeserver','knowledge')===null,'No partial document visibility');
workspace_sync_exchange_v1($session,$chunks[0]); // lost receipt retry
foreach(array_reverse(array_slice($chunks,1)) as $chunk)$receipt=workspace_sync_exchange_v1($session,$chunk);
check($receipt['committed']&&$receipt['revision']===$revision,'Out-of-order/replayed packet receipt');
check(workspace_sync_read_v1($pdo,1,'homeserver','knowledge')['body_json']===$raw,'Exact UTF-8 full document');
check(workspace_sync_exchange_v1($session,$chunks[count($chunks)-1])['committed'],'Lost final receipt replay');
$empty=json_encode(['contract'=>VP3_WORKSPACE_SYNC_V1,'source'=>'homeserver','dataset'=>'knowledge','records'=>[]],JSON_THROW_ON_ERROR);
$receipt=workspace_sync_exchange_v1($session,['action'=>'push','dataset'=>'knowledge','revision'=>hash('sha256',$empty),'offset'=>0,'total_bytes'=>strlen($empty),'chunk'=>base64_encode($empty)]);
check($receipt['committed']&&workspace_sync_read_v1($pdo,1,'homeserver','knowledge')['record_count']==0,'Authoritative delete replaces replica');
$before=workspace_sync_read_v1($pdo,1,'homeserver','knowledge');$bad=$chunks[0];$bad['revision']=str_repeat('c',64);
foreach($chunks as $bad){$bad['revision']=str_repeat('c',64);if($bad['offset']+VP3_WORKSPACE_SYNC_CHUNK<strlen($raw))workspace_sync_exchange_v1($session,$bad);else rejected(fn()=>workspace_sync_exchange_v1($session,$bad),'Corrupt checksum accepted');}
check(workspace_sync_read_v1($pdo,1,'homeserver','knowledge')===$before,'Checksum failure preserves previous dataset');
rejected(fn()=>workspace_sync_exchange_v1($session,['action'=>'prepare','dataset'=>'users; DROP TABLE users']),'Client SQL allowed');
if($mysql){
    $snapshotFile=tempnam(sys_get_temp_dir(),'ws-snapshot-');$ready=tempnam(sys_get_temp_dir(),'ws-ready-');unlink($ready);
    file_put_contents($snapshotFile,json_encode($session,JSON_THROW_ON_ERROR));
    $pdo->beginTransaction();$pdo->query('SELECT id FROM users WHERE id=1 FOR UPDATE')->fetchAll();
    $pipes=[];$process=proc_open(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --blocked-sync '.escapeshellarg($snapshotFile).' '.escapeshellarg($ready),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $deadline=microtime(true)+5;while(!is_file($ready)&&microtime(true)<$deadline)usleep(20000);
    check(is_file($ready),'Concurrent poll fixture started');
    $pdo->exec("UPDATE homeserver_https_sessions SET status='revoked' WHERE user_id=1");$pdo->commit();
    fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    check(proc_close($process)===0&&$output==='revoked-after-lock','Concurrent revoke blocked late workspace writes: '.$errors);
    unlink($snapshotFile);unlink($ready);
}
$pdo->exec("UPDATE homeserver_https_sessions SET status='revoked' WHERE user_id=1");
try{workspace_sync_exchange_v1($session,['action'=>'prepare','dataset'=>'profile']);throw new LogicException('Stale session allowed');}catch(HomeServerHttpsSessionError $e){check($e->getCode()===410,'Fresh authority revocation');}
check(!$pdo->inTransaction(),'Failure always rolls back');
echo "Workspace complete records, nested ownership, products, generic account data, chunk recovery, deletes, hashes and revoked sessions PASS\n";
