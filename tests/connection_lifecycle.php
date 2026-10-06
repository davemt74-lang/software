<?php
declare(strict_types=1);
// Production relay against real SQLite/PDO and, in CI, MySQL row locks.
final class RelaySQLite extends PDO {
    public function exec(string $query): int|false {
        if(str_contains($query,'CREATE TABLE IF NOT EXISTS homeserver_https_'))return 0; // Explicit equivalent fixture below.
        return parent::exec($query);
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        return parent::prepare(str_replace([' FOR UPDATE','UTC_TIMESTAMP()'],['','CURRENT_TIMESTAMP'],$query),$options);
    }
}
$dsn=getenv('VP3_CONNECTION_MYSQL_DSN')?:'sqlite::memory:';
$mysql=str_starts_with($dsn,'mysql:');
$pdo=$mysql?new PDO($dsn,'root',getenv('VP3_CONNECTION_MYSQL_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC])
    :new RelaySQLite($dsn,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): PDO {return $GLOBALS['pdo'];}
function table_exists(string $table): bool {return true;}
function homeserver_vp3_ensure_schema(?PDO $pdo=null): void {}
function homeserver_vp3_decrypt(string $value): string {return $value;}
const VP3_HOMESERVER_RELEASE_VERSION='2.2';
require __DIR__.'/../includes/homeserver-https-relay-v1300.php';
function check(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
if(in_array('--blocked-poll',$argv,true)){
    $snapshot=json_decode(file_get_contents($argv[2]),true);
    file_put_contents($argv[3],'ready');
    try{homeserver_https_v1300_poll($snapshot,[]);echo 'unexpected-success';exit(1);}
    catch(HomeServerHttpsSessionError $e){check($e->getCode()===410,'Revocation after lock must produce 410');echo 'revoked-after-lock';exit;}
}
foreach(['homeserver_https_requests','homeserver_https_sessions','homeserver_connections','users'] as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
$pdo->exec($mysql?'CREATE TABLE users(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB':'CREATE TABLE users(id INTEGER PRIMARY KEY)');
$pdo->exec('INSERT INTO users VALUES(1),(2)');
$pdo->exec('CREATE TABLE homeserver_connections(user_id INTEGER PRIMARY KEY,status VARCHAR(20),homeserver_token_enc TEXT,installed_version VARCHAR(64),last_seen_at DATETIME,last_checked_at DATETIME,last_error TEXT,capabilities_json TEXT)');
if($mysql)homeserver_https_v1300_ensure_schema($pdo);
else{
 $pdo->exec('CREATE TABLE homeserver_https_sessions(user_id INTEGER PRIMARY KEY,device_id TEXT,session_token_hash TEXT,status TEXT,installed_version TEXT,capabilities_json TEXT,last_seen_at DATETIME)');
 $pdo->exec('CREATE TABLE homeserver_https_requests(id INTEGER PRIMARY KEY,public_id TEXT,user_id INTEGER,device_id TEXT,operation TEXT,payload_json TEXT,status TEXT,response_status INTEGER,response_json TEXT,expires_at DATETIME,delivered_at DATETIME,completed_at DATETIME)');
}
$device='hs-'.str_repeat('a',24);$token=str_repeat('S',64);$hash=hash('sha256',$token);
$pdo->prepare("INSERT INTO homeserver_https_sessions(user_id,device_id,session_token_hash,status,installed_version) VALUES(?,?,?,'active','old')")->execute([1,$device,$hash]);
$pdo->exec("INSERT INTO homeserver_connections(user_id,status,homeserver_token_enc,last_error) VALUES(1,'paired','fixture-authorization','')");
$id='aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
$pdo->prepare("INSERT INTO homeserver_https_requests(public_id,user_id,device_id,operation,payload_json,status,expires_at) VALUES(?,?,?,'system.ping','{}','queued',?)")->execute([$id,1,$device,gmdate('Y-m-d H:i:s',time()+120)]);
$snapshot=homeserver_https_v1300_authenticate($token,$device);
$result=homeserver_https_v1300_poll($snapshot,['version'=>'fixture','capabilities'=>['test'=>true]]);
check(count($result['requests'])===1,'Canonical request delivery must work');
check($result['requests'][0]['bearer_token']==='fixture-authorization','Delivery retains local authorization');
homeserver_https_v1300_poll($snapshot,['results'=>[['request_id'=>$id,'ok'=>true,'status'=>200,'payload'=>['pong'=>true]]]]);
check($pdo->query('SELECT status FROM homeserver_https_requests')->fetchColumn()==='completed','Receipt must commit');
echo "PASS normal authenticated heartbeat, delivery and receipt remain intact\n";

$pdo->exec("UPDATE homeserver_https_requests SET status='delivered',response_json=NULL");
homeserver_https_v1300_revoke(1);
try{homeserver_https_v1300_poll($snapshot,['version'=>'stale','results'=>[['request_id'=>$id,'ok'=>true]]]);throw new LogicException('Stale poll was accepted');}
catch(HomeServerHttpsSessionError $e){check($e->getCode()===410,'Explicit revocation produces Gone');}
check($pdo->query('SELECT status FROM homeserver_https_sessions')->fetchColumn()==='revoked','Old poll must not resurrect authority');
check($pdo->query('SELECT status FROM homeserver_https_requests')->fetchColumn()==='expired','Revoked receipt must not complete');
check(!$pdo->inTransaction(),'Failed auth must rollback');
echo "PASS authentication before revocation cannot resurrect the session or complete queued commands\n";

$newHash=hash('sha256',str_repeat('N',64));
$pdo->prepare("UPDATE homeserver_https_sessions SET status='active',session_token_hash=?,installed_version='replacement'")->execute([$newHash]);
try{homeserver_https_v1300_poll($snapshot,['version'=>'stale']);throw new LogicException('Replacement was overwritten');}
catch(HomeServerHttpsSessionError $e){check($e->getCode()===401,'Replaced session produces ordinary auth rejection');}
check($pdo->query('SELECT installed_version FROM homeserver_https_sessions')->fetchColumn()==='replacement','Replacement metadata must survive');
echo "PASS replaced session rejects stale polling without overwriting new heartbeat or capabilities\n";

if($mysql){
 $snapshot=homeserver_https_v1300_authenticate(str_repeat('N',64),$device);
 $file=tempnam(sys_get_temp_dir(),'relay-snapshot-');$ready=$file.'.ready';file_put_contents($file,json_encode($snapshot));
 $pdo->beginTransaction();$pdo->query('SELECT id FROM users WHERE id=1 FOR UPDATE')->fetch();
 $process=proc_open([PHP_BINARY,__FILE__,'--blocked-poll',$file,$ready],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 try{
  $deadline=microtime(true)+10;while(!is_file($ready)){if(microtime(true)>$deadline)throw new RuntimeException('Child failed to start');usleep(10000);}
  usleep(150000);check(proc_get_status($process)['running'],'Poll must wait while account authority is locked');
  $pdo->exec("UPDATE homeserver_https_sessions SET status='revoked' WHERE user_id=1");$pdo->commit();
  $output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);
  check($output==='revoked-after-lock','Blocked poll must re-read authority: '.$output.$errors);
  echo "PASS real parallel MySQL poll waits for account lock and then rejects committed revocation\n";
 }finally{if($pdo->inTransaction())$pdo->rollBack();foreach($pipes as $pipe)fclose($pipe);proc_close($process);unlink($file);if(is_file($ready))unlink($ready);}
}
