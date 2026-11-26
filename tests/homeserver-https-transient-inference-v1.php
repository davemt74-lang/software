<?php
declare(strict_types=1);

// Disposable SQLite only. Exercise the production relay and canonical executor;
// the adapter translates SQL syntax and supplies deterministic interleavings.
final class TransientRelaySQLite extends PDO
{
    public bool $denyDelete=false;
    public bool $denyInsert=false;
    public ?array $metadataFailure=null;
    public mixed $beforeOwnerLock=null;
    public ?array $autoResponse=null;

    public function __construct(){parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}
    public function exec(string $statement): int|false
    {
        if(str_contains($statement,'CREATE TABLE IF NOT EXISTS homeserver_'))return 0;
        return parent::exec($statement);
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false
    {
        if($query==='SELECT transient_context FROM homeserver_https_requests WHERE 1=0'&&$this->metadataFailure!==null){
            $error=new PDOException((string)$this->metadataFailure[2]);$error->errorInfo=$this->metadataFailure;throw $error;
        }
        return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        if($this->denyInsert&&str_contains($query,'INSERT INTO homeserver_https_requests('))throw new RuntimeException('Fixture queue insertion unavailable.');
        if($this->denyDelete&&str_contains($query,'DELETE FROM homeserver_https_requests WHERE public_id='))
            throw new RuntimeException('Fixture cleanup unavailable.');
        if($this->beforeOwnerLock!==null&&str_contains($query,'FROM users WHERE id=? FOR UPDATE')){
            $callback=$this->beforeOwnerLock;$this->beforeOwnerLock=null;$callback($this);
        }
        $query=str_replace('DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND)',"datetime(CURRENT_TIMESTAMP,'+' || ? || ' seconds')",$query);
        $query=str_replace(["IF(transient_context=1,'{}',payload_json)",' FOR UPDATE','UTC_TIMESTAMP()'],
            ["CASE WHEN transient_context=1 THEN '{}' ELSE payload_json END",'','CURRENT_TIMESTAMP'],$query);
        return parent::prepare($query,$options);
    }
    public function commit(): bool
    {
        $committed=parent::commit();
        if($this->autoResponse!==null){
            $response=$this->autoResponse;$this->autoResponse=null;
            $id=(string)$this->query("SELECT public_id FROM homeserver_https_requests WHERE status='queued' ORDER BY id DESC LIMIT 1")->fetchColumn();
            $session=homeserver_https_v1300_session(1);
            homeserver_https_v1300_poll($session,[]);
            homeserver_https_v1300_poll($session,['results'=>[['request_id'=>$id]+$response]]);
        }
        return $committed;
    }
}

function db(): PDO{return $GLOBALS['pdo'];}
function table_exists(string $table): bool
{
    $q=db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);return (bool)$q->fetchColumn();
}
function homeserver_vp3_ensure_schema(?PDO $pdo=null): void{}
function homeserver_vp3_decrypt(string $value): string{return $value;}
function homeserver_execution_v220_can_route(int $owner,string $operation): bool
{
    $GLOBALS['route_calls'][]=[$owner,$operation];return $GLOBALS['route_allowed'];
}
const VP3_HOMESERVER_RELEASE_VERSION='fixture';
require __DIR__.'/../includes/homeserver-https-relay-v1300.php';
require __DIR__.'/../includes/homeserver-local-execution-v230.php';

function htCheck(bool $value,string $message): void
{
    if(!$value)throw new RuntimeException($message);$GLOBALS['checks']++;echo 'PASS '.$message.PHP_EOL;
}
function htThrows(callable $work,string $contains): Throwable
{
    $caught=null;try{$work();}catch(Throwable $error){$caught=$error;}
    htCheck($caught!==null&&str_contains($caught->getMessage(),$contains),'rejects '.$contains);
    return $caught;
}
function htReset(bool $migrated=true): TransientRelaySQLite
{
    $pdo=new TransientRelaySQLite();$GLOBALS['pdo']=$pdo;$GLOBALS['route_allowed']=true;$GLOBALS['route_calls']=[];
    $pdo->exec('CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER NOT NULL DEFAULT 1)');$pdo->exec('INSERT INTO users(id) VALUES(1),(2)');
    $pdo->exec('CREATE TABLE homeserver_connections(user_id INTEGER PRIMARY KEY,status TEXT,homeserver_token_enc TEXT,installed_version TEXT,last_seen_at TEXT,last_checked_at TEXT,last_error TEXT,capabilities_json TEXT)');
    $pdo->exec('CREATE TABLE homeserver_https_sessions(user_id INTEGER PRIMARY KEY,device_id TEXT,session_token_hash TEXT,status TEXT,installed_version TEXT,capabilities_json TEXT,last_seen_at TEXT)');
    $pdo->exec('CREATE TABLE homeserver_https_requests(id INTEGER PRIMARY KEY,public_id TEXT UNIQUE,user_id INTEGER,device_id TEXT,operation TEXT,payload_json TEXT,'.($migrated?'transient_context INTEGER NOT NULL DEFAULT 0,':'').'status TEXT,response_status INTEGER,response_json TEXT,expires_at TEXT,delivered_at TEXT,completed_at TEXT)');
    $pdo->exec('CREATE TABLE homeserver_execution_receipts(id INTEGER PRIMARY KEY,user_id INTEGER,request_id TEXT,domain_key TEXT,operation_key TEXT,route_key TEXT,status_key TEXT,fallback_used INTEGER,failure_class TEXT,duration_ms INTEGER,result_meta_json TEXT)');
    foreach([1,2] as $owner){
        $pdo->prepare("INSERT INTO homeserver_https_sessions(user_id,device_id,session_token_hash,status,installed_version,last_seen_at) VALUES(?,?,?,'active','fixture',CURRENT_TIMESTAMP)")
            ->execute([$owner,'hs-'.str_repeat((string)$owner,24),hash('sha256','session-'.$owner)]);
        $pdo->prepare("INSERT INTO homeserver_connections(user_id,status,homeserver_token_enc) VALUES(?,'paired','fixture-token')")->execute([$owner]);
    }
    return $pdo;
}
function htRow(string $id): ?array
{
    $q=db()->prepare('SELECT * FROM homeserver_https_requests WHERE public_id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function htComplete(array $session,string $id,array $payload,bool $ok=true): void
{
    homeserver_https_v1300_poll($session,['results'=>[['request_id'=>$id,'ok'=>$ok,'status'=>$ok?200:503,'payload'=>$payload]]]);
}
function htCount(): int{return (int)db()->query('SELECT COUNT(*) FROM homeserver_https_requests')->fetchColumn();}
function htExpire(string $id): void{db()->prepare("UPDATE homeserver_https_requests SET expires_at='2000-01-01 00:00:00' WHERE public_id=?")->execute([$id]);}

$GLOBALS['checks']=0;$sentinel='PRIVATE_TRANSIENT_CONTEXT_3e52';$payload=['messages'=>[['role'=>'user','content'=>$sentinel]]];
$pdo=htReset(false);$session=homeserver_https_v1300_session(1);
$ordinary=homeserver_https_v1300_queue(1,'agent.infer.local',$payload);
$delivered=homeserver_https_v1300_poll($session,[]);
htCheck($delivered['requests'][0]['payload']===$payload&&str_contains(htRow($ordinary)['payload_json'],$sentinel),'ordinary pre-migration request retains its existing delivery/history');
htComplete($session,$ordinary,['reply'=>'ordinary']);
htCheck(homeserver_https_v1300_wait($ordinary,1000)===['reply'=>'ordinary']&&htRow($ordinary)!==null,'ordinary response consumption retains existing history');
htThrows(fn()=>homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true),'database upgrade');
htThrows(fn()=>homeserver_execution_v230_execute(1,'agent.infer.local',$payload,null,true),'Transient HomeServer inference is unavailable');
htCheck(htCount()===1,'missing transient migration never queues another private request');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_SILENT);
htCheck(!homeserver_https_v1300_transient_ready($pdo),'known missing column remains an upgrade requirement with silent PDO errors');
$pdo=htReset();$pdo->exec('DROP TABLE homeserver_https_requests');
htCheck(!homeserver_https_v1300_transient_ready($pdo),'known missing request table remains an upgrade requirement');
$pdo->metadataFailure=['42S02',1146,"Table 'fixture.homeserver_https_requests' doesn't exist"];
htCheck(!homeserver_https_v1300_transient_ready($pdo),'known MySQL missing request table remains an upgrade requirement');
$pdo->metadataFailure=['42S22',1054,"Unknown column 'transient_context' in 'field list'"];
htCheck(!homeserver_https_v1300_transient_ready($pdo),'known MySQL missing transient column remains an upgrade requirement');

$pdo=htReset();$session=homeserver_https_v1300_session(1);
$ordinary=homeserver_https_v1300_queue(1,'agent.infer.local',$payload);
homeserver_https_v1300_poll($session,[]);htComplete($session,$ordinary,['reply'=>'ordinary']);
htCheck(homeserver_https_v1300_wait($ordinary,1000)===['reply'=>'ordinary']
    &&str_contains(htRow($ordinary)['payload_json'],$sentinel)&&htRow($ordinary)['transient_context']===0,
    'migrated ordinary requests keep their prompt and response history with the default flag');

$pdo=htReset();$session=homeserver_https_v1300_session(1);
htThrows(fn()=>homeserver_https_v1300_queue(1,'files.read',$payload,true),'requires agent.infer.local');
htThrows(fn()=>homeserver_execution_v230_execute(1,'agent.chat',$payload,null,true),'requires agent.infer.local');
htCheck(htCount()===0,'transient opt-in cannot broaden to another operation');
$id=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);
htThrows(fn()=>homeserver_https_v1300_wait($id,1000),'scoped consumer');
$delivered=homeserver_https_v1300_poll($session,[]);
htCheck($delivered['requests'][0]['payload']===$payload&&htRow($id)['payload_json']==='{}','transient delivery uses the same messages then removes the stored prompt');
htComplete($session,$id,['reply'=>$sentinel]);
htCheck(str_contains(htRow($id)['response_json'],$sentinel),'completed transient response exists only while awaiting its consumer');
htCheck(homeserver_https_v1300_wait_transient($id,1,$session,1000)===['reply'=>$sentinel]&&htRow($id)===null,'transient success consumes and physically removes its response');

$pdo=htReset();$session=homeserver_https_v1300_session(1);$fallbacks=0;
$pdo->autoResponse=['ok'=>true,'status'=>200,'payload'=>['reply'=>$sentinel,'provider'=>$sentinel,'model'=>$sentinel]];
$result=homeserver_execution_v230_execute(1,'agent.infer.local',$payload,function()use(&$fallbacks){$fallbacks++;return [];},true);
htCheck($result['result']['reply']===$sentinel&&htCount()===0&&$GLOBALS['route_calls']===[[1,'agent.infer.local']],'canonical transient execution preserves route checks and cleans its actual relay result');
htCheck($result['execution']['result_meta']===['transient_context'=>true]
    &&!str_contains((string)$pdo->query('SELECT result_meta_json FROM homeserver_execution_receipts')->fetchColumn(),$sentinel),'transient receipts retain fixed provenance without remote metadata or context');
$pdo->autoResponse=['ok'=>false,'status'=>503,'payload'=>['error'=>$sentinel]];
$error=htThrows(fn()=>homeserver_execution_v230_execute(1,'agent.infer.local',$payload,function()use(&$fallbacks){$fallbacks++;return [];},true),'transient inference failed');
htCheck(!str_contains($error->getMessage(),$sentinel)&&htCount()===0&&$fallbacks===0,'transient failure removes content and never invokes a fallback or echoes remote error text');
$GLOBALS['route_allowed']=false;
htThrows(fn()=>homeserver_execution_v230_execute(1,'agent.infer.local',$payload,function()use(&$fallbacks){$fallbacks++;return [];},true),'capability is unavailable');
htCheck(htCount()===0&&$fallbacks===0,'denied canonical route persists no transient prompt and starts no fallback');

$pdo=htReset();$session=homeserver_https_v1300_session(1);
$id=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);
htThrows(fn()=>homeserver_https_v1300_wait_transient($id,1,$session,1000),'timed out');
htComplete($session,$id,['reply'=>$sentinel]);
htCheck(htRow($id)===null,'timeout removes the prompt and a late result cannot resurrect it');

$pdo=htReset();$session=homeserver_https_v1300_session(1);
$ids=[];foreach(['queued','delivered','completed','failed','expired'] as $status){
    $id=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);
    $pdo->prepare('UPDATE homeserver_https_requests SET status=?,response_json=? WHERE public_id=?')->execute([$status,json_encode(['reply'=>$sentinel]),$id]);
    htExpire($id);$ids[]=$id;
    htCheck(homeserver_https_v1300_scrub_transient($pdo,1)===1&&htRow($id)===null,'expiry sweep removes orphan content in '.$status.' state');
}
$id=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);
$other=homeserver_https_v1300_queue(2,'agent.infer.local',$payload,true,homeserver_https_v1300_session(2));
htExpire($id);htExpire($other);
htCheck(homeserver_https_v1300_scrub_transient($pdo,1)===1&&htCount()===1,'orphan expiry cleanup is globally bounded and physically removes content');
htCheck(homeserver_https_v1300_scrub_transient($pdo,100)===1&&htCount()===0,'global cleanup reaches inactive owners without requiring a live client');
$id=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);htExpire($id);
htComplete($session,$id,['reply'=>$sentinel]);
htCheck(htRow($id)===null,'expiry is enforced before posted results and cannot be converted back to completed');

$pdo=htReset();$session=homeserver_https_v1300_session(1);
$id=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);
htThrows(fn()=>homeserver_https_v1300_wait_transient($id,2,homeserver_https_v1300_session(2),1000),'was not found');
htCheck(htRow($id)!==null,'another owner cannot consume or delete the transient request');
$stale=$session;$stale['session_token_hash']=hash('sha256','wrong-session');
htThrows(fn()=>homeserver_https_v1300_wait_transient($id,1,$stale,1000),'authorization changed');
htCheck(htRow($id)!==null,'a mismatched session cannot consume or delete the transient request');
$pdo->beforeOwnerLock=static function(PDO $db): void{$db->exec("UPDATE homeserver_https_sessions SET status='revoked' WHERE user_id=1");};
htThrows(fn()=>homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session),'authorization changed');
htCheck(htCount()===1,'enqueue rechecks session revocation after its initial snapshot before storing any prompt');

$pdo=htReset();$session=homeserver_https_v1300_session(1);
$pdo->beforeOwnerLock=static function(PDO $db): void{$db->exec('UPDATE users SET is_active=0 WHERE id=1');};
$error=htThrows(fn()=>homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session),'account authorization changed');
htCheck($error->getCode()===403&&htCount()===0,'enqueue rechecks account deactivation after its initial snapshot before storing a prompt');
$id=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);htComplete($session,$id,['reply'=>$sentinel]);
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
htThrows(fn()=>homeserver_https_v1300_wait_transient($id,1,$session,1000),'account authorization changed');
htCheck(htRow($id)===null,'deactivation prevents response consumption and removes the owned transient response');

$pdo=htReset();$session=homeserver_https_v1300_session(1);$inactiveIds=[];
foreach(['queued','delivered','completed','failed'] as $status){
    $id=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);$inactiveIds[]=$id;
    $pdo->prepare('UPDATE homeserver_https_requests SET status=?,response_json=? WHERE public_id=?')->execute([$status,json_encode(['reply'=>$sentinel]),$id]);
}
$other=homeserver_https_v1300_queue(2,'agent.infer.local',$payload,true,homeserver_https_v1300_session(2));
$ordinary=homeserver_https_v1300_queue(1,'agent.infer.local',['messages'=>[['content'=>'ordinary-history']]]);
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
$results=array_map(static fn(string $id): array=>['request_id'=>$id,'ok'=>true,'status'=>200,'payload'=>['reply'=>$sentinel]],$inactiveIds);
$delivered=homeserver_https_v1300_poll($session,['results'=>$results]);
htCheck(count($delivered['requests'])===1&&$delivered['requests'][0]['request_id']===$ordinary
    &&!str_contains(json_encode($delivered),$sentinel),'inactive poll cannot deliver transient prompts and keeps ordinary delivery semantics');
htCheck((int)$pdo->query('SELECT COUNT(*) FROM homeserver_https_requests WHERE user_id=1 AND transient_context=1')->fetchColumn()===0
    &&htRow($other)!==null,'inactive poll purges all of that owner’s transient states without touching another owner');
htComplete($session,$ordinary,['reply'=>'ordinary-result']);
htCheck(homeserver_https_v1300_wait($ordinary,1000)===['reply'=>'ordinary-result']&&htRow($ordinary)!==null,'inactive account correction preserves ordinary result acceptance and history');
htThrows(fn()=>homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session),'account authorization changed');

$pdo=htReset();$session=homeserver_https_v1300_session(1);
$id=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);
$pdo->metadataFailure=['42000',1142,'SELECT command denied '.$sentinel];
$error=htThrows(fn()=>homeserver_https_v1300_poll($session,[]),'metadata is unavailable');
htCheck($error->getCode()===503&&$error->getPrevious()===null&&!str_contains((string)$error,$sentinel)
    &&htRow($id)['status']==='queued'&&htRow($id)['response_json']===null,'denied metadata read fails closed without delivery or leaking its driver error');
$error=htThrows(fn()=>htComplete($session,$id,['reply'=>$sentinel]),'metadata is unavailable');
htCheck($error->getCode()===503&&htRow($id)['status']==='queued'&&htRow($id)['response_json']===null,'denied metadata read cannot downgrade a flagged result into retained legacy history');
$pdo->metadataFailure=null;homeserver_https_v1300_poll($session,[]);
$pdo->metadataFailure=['HY000',2006,'Database connection lost '.$sentinel];
$error=htThrows(fn()=>htComplete($session,$id,['reply'=>$sentinel]),'metadata is unavailable');
htCheck($error->getCode()===503&&!str_contains((string)$error,$sentinel)
    &&htRow($id)['status']==='delivered'&&htRow($id)['payload_json']==='{}'&&htRow($id)['response_json']===null,'metadata outage cannot retain a fresh response after transient delivery');
$pdo->metadataFailure=null;htExpire($id);homeserver_https_v1300_scrub_transient($pdo);
htCheck(htRow($id)===null,'restored metadata access permits the normal expiry cleanup');

$pdo=htReset();$session=homeserver_https_v1300_session(1);
foreach(['queued','delivered','completed','failed'] as $status){
    $id=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);
    $pdo->prepare('UPDATE homeserver_https_requests SET status=?,response_json=? WHERE public_id=?')->execute([$status,json_encode(['reply'=>$sentinel]),$id]);
}
$ordinary=homeserver_https_v1300_queue(1,'agent.infer.local',['messages'=>[['content'=>'ordinary-history']]]);
homeserver_https_v1300_revoke(1);
htCheck(htCount()===1&&htRow($ordinary)['status']==='expired'&&str_contains(htRow($ordinary)['payload_json'],'ordinary-history'),'revoke deletes transient rows of every status and preserves ordinary request history');
htThrows(fn()=>htComplete($session,$id,['reply'=>$sentinel]),'revoked');
htCheck(htCount()===1,'a stale authenticated poll cannot restore revoked transient responses');

$pdo=htReset();$session=homeserver_https_v1300_session(1);
$id=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);htComplete($session,$id,['reply'=>$sentinel]);
$pdo->denyDelete=true;
htThrows(fn()=>homeserver_https_v1300_wait_transient($id,1,$session,1000),'cleanup unavailable');
$pdo->denyDelete=false;htExpire($id);homeserver_https_v1300_scrub_transient($pdo);
htCheck(htCount()===0,'cleanup failure prevents success and an expiry sweep can still remove the orphan');

// Optional private-draft attempt reservations must be atomic with the real queue.
$pdo=htReset();$session=homeserver_https_v1300_session(1);
$pdo->exec('CREATE TABLE fixture_private_draft(id INTEGER PRIMARY KEY,attempted INTEGER NOT NULL DEFAULT 0)');$pdo->exec('INSERT INTO fixture_private_draft(id) VALUES(1)');
$inside=false;$reserve=static function()use($pdo,&$inside):bool{
    $inside=$pdo->inTransaction();$q=$pdo->prepare('UPDATE fixture_private_draft SET attempted=1 WHERE id=1 AND attempted=0');$q->execute();return $q->rowCount()===1;
};
$id=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session,$reserve);
htCheck($inside&&htCount()===1&&(int)$pdo->query('SELECT attempted FROM fixture_private_draft')->fetchColumn()===1,'private dispatch reservation and transient prompt commit in one queue transaction');
htThrows(fn()=>homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session,$reserve),'authorization changed');
htCheck(htCount()===1,'consumed private dispatch reservation cannot queue another attempt');
$pdo->exec('UPDATE fixture_private_draft SET attempted=0');$pdo->denyInsert=true;
htThrows(fn()=>homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session,$reserve),'queue insertion unavailable');
$pdo->denyInsert=false;
htCheck((int)$pdo->query('SELECT attempted FROM fixture_private_draft')->fetchColumn()===0&&htCount()===1,'failed queue insert rolls back the unused private attempt reservation');
$pdo=htReset();$callbackCalls=0;$fallbacks=0;$pdo->autoResponse=['ok'=>true,'status'=>200,'payload'=>['reply'=>'A private draft']];
$result=homeserver_execution_v230_execute(1,'agent.infer.local',$payload,static function()use(&$fallbacks){$fallbacks++;return [];},true,
    static function()use(&$callbackCalls):bool{$callbackCalls++;return db()->inTransaction();});
htCheck($callbackCalls===1&&$fallbacks===0&&$result['result']['reply']==='A private draft'&&htCount()===0,'executor forwards optional reservation through remote operation to the physical queue');

require_once __DIR__.'/../includes/agent-inference-budget-v1.php';
$pdo=htReset();$session=homeserver_https_v1300_session(1);$pdo->exec('CREATE TABLE fixture_private_draft(id INTEGER PRIMARY KEY,attempted INTEGER NOT NULL DEFAULT 0)');$pdo->exec('INSERT INTO fixture_private_draft(id) VALUES(1)');
htThrows(fn()=>vp3_ai_with_budget_v1(2,static fn()=>homeserver_https_v1300_queue(1,'agent.infer.local',$GLOBALS['payload'],true,null,
    static function():bool{db()->exec('UPDATE fixture_private_draft SET attempted=1');$GLOBALS['VP3_AI_INFERENCE_BUDGET_V1']['deadline']=0.0;return true;})),'time budget was exhausted');
htCheck(htCount()===0&&(int)$pdo->query('SELECT attempted FROM fixture_private_draft')->fetchColumn()===0,'budget consumed by queue locks rolls back reservation before dispatch');
$id=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);$start=microtime(true);
$GLOBALS['VP3_AI_INFERENCE_BUDGET_V1']=['deadline'=>hrtime(true)/1e9+0.02];
try{htThrows(fn()=>homeserver_https_v1300_wait_transient($id,1,$session),'timed out');}finally{unset($GLOBALS['VP3_AI_INFERENCE_BUDGET_V1']);}
htCheck(microtime(true)-$start<0.5&&htCount()===0,'transient wait respects remaining inference budget and removes exact expired context');

echo 'HOMESERVER_HTTPS_TRANSIENT_INFERENCE_V1=PASS checks='.$GLOBALS['checks'].PHP_EOL;


require_once __DIR__.'/../includes/homeserver-model-inventory-read-v1.php';
$inventory=['version'=>'vp3.local-model-inventory.v1','provider'=>'ollama','enabled'=>true,
    'configured_model'=>'local:latest','discovery_state'=>'reported','truncated'=>false,
    'models'=>[['model'=>'local:latest','routing_eligible'=>true,'execution_verified'=>true]]];
$pdo=htReset();
$foreign=homeserver_https_v1300_queue(2,'inference.status',[]);
$pdo->autoResponse=['ok'=>true,'status'=>200,'payload'=>['model_inventory'=>$inventory,'secret'=>'private']];
$view=vp3_homeserver_inventory_read_v1(1);
htCheck($view['state']==='reported'&&$view['configured_model_reported']&&!$view['models'][0]['routing_eligible']
    &&!array_key_exists('secret',$view)&&htCount()===1&&htRow($foreign)!==null,'inventory consumes only its scoped response and strips untrusted claims');
$pdo->autoResponse=['ok'=>true,'status'=>200,'payload'=>['model'=>'legacy']];
htCheck(vp3_homeserver_inventory_read_v1(1)['state']==='unsupported'&&htCount()===1,'legacy inference status is unsupported without fallback');
$pdo->autoResponse=['ok'=>false,'status'=>503,'payload'=>['detail'=>'PRIVATE_PROVIDER_ERROR']];
$error=htThrows(fn()=>vp3_homeserver_inventory_read_v1(1),'could not be refreshed');
htCheck(!str_contains((string)$error,'PRIVATE_PROVIDER_ERROR')&&htCount()===1,'failed inventory response is sanitized and cleaned');
$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
htThrows(fn()=>vp3_homeserver_inventory_read_v1(1),'authorization changed');
htCheck(htCount()===1,'inactive account cannot queue inventory');
$pdo->exec('UPDATE users SET is_active=1 WHERE id=1');
homeserver_https_v1300_revoke(1);
htThrows(fn()=>vp3_homeserver_inventory_read_v1(1),'pairing is unavailable');
htCheck(htCount()===1,'revoked pairing cannot queue inventory');
$pdo=htReset();
$pdo->denyDelete=true;
$pdo->autoResponse=['ok'=>true,'status'=>200,'payload'=>['model_inventory'=>$inventory]];
htThrows(fn()=>vp3_homeserver_inventory_read_v1(1),'could not be refreshed');
htCheck(htCount()===1,'cleanup failure never returns inventory success');
$pdo->denyDelete=false;
echo 'HOMESERVER_MODEL_INVENTORY_READ_V1=PASS'.PHP_EOL;
