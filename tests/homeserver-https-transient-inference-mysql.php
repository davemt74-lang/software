<?php
declare(strict_types=1);

// Explicit MySQL only: every run creates and drops its own random database.
// Never print connection arguments, credentials, raw driver errors or context.
ini_set('zend.exception_ignore_args','1');
set_exception_handler(static function(Throwable $error): void{
    $state=$error instanceof PDOException?(string)($error->errorInfo[0]??''):'';
    fwrite(STDERR,json_encode(['fixture_failure'=>true,'error_class'=>get_class($error),
        'public_code'=>is_int($error->getCode())?$error->getCode():0,
        'sql_state'=>preg_match('/\A[A-Z0-9]{5}\z/D',$state)?$state:'',
        'driver_code'=>$error instanceof PDOException?(int)($error->errorInfo[1]??0):0,
        'file'=>basename($error->getFile()),'line'=>$error->getLine()],JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
});
$dsn=(string)(getenv('VP3_TRANSIENT_MYSQL_DSN')?:getenv('VP3_MYSQL_TEST_DSN'));
if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('An explicit disposable MySQL relay fixture DSN is required.');
$databaseUser=(string)(getenv('VP3_TRANSIENT_MYSQL_USER')?:getenv('VP3_MYSQL_TEST_USER')?:'root');
$password=(string)(getenv('VP3_TRANSIENT_MYSQL_PASSWORD')?:getenv('VP3_MYSQL_TEST_PASSWORD'));
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$mode=(string)($argv[1]??'');$worker=str_starts_with($mode,'--');
if($worker&&!in_array($mode,['--enqueue','--poll','--wait','--complete'],true))throw new RuntimeException('Invalid native relay worker mode.');
$database=$worker?(string)($argv[2]??''):'hs_transient_native_'.bin2hex(random_bytes(6));
if(!preg_match('/\Ahs_transient_native_[a-f0-9]{12}\z/D',$database))throw new RuntimeException('Invalid disposable database name.');
$admin=new PDO($dsn,$databaseUser,$password,$options);
$parts=array_values(array_filter(explode(';',substr($dsn,6)),static fn(string $part):bool=>preg_match('/\A\s*dbname\s*=/i',$part)!==1));
$fixtureDsn='mysql:'.implode(';',$parts).';dbname='.$database;
$created=false;$children=[];$gate='';$pdo=null;
$sentinel='PRIVATE_TRANSIENT_NATIVE_7a31';
$payload=['messages'=>[['role'=>'user','content'=>$sentinel]]];

function db(): PDO{return $GLOBALS['pdo'];}
function table_exists(string $table): bool
{
    $q=db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $q->execute([$table]);return $q->fetchColumn()!==false;
}
function homeserver_vp3_ensure_schema(?PDO $pdo=null): void{}
function homeserver_vp3_decrypt(string $value): string{return $value;}
function homeserver_execution_v220_can_route(int $owner,string $operation): bool{return $owner===1&&$operation==='agent.infer.local';}
const VP3_HOMESERVER_RELEASE_VERSION='native-fixture';
require dirname(__DIR__).'/includes/homeserver-https-relay-v1300.php';
require dirname(__DIR__).'/includes/homeserver-local-execution-v230.php';
require dirname(__DIR__).'/includes/homeserver-transient-schema-v1.php';

function htnCheck(bool $ok,string $label): void
{
    if(!$ok){fwrite(STDERR,'FAIL '.$label.PHP_EOL);throw new RuntimeException('A native relay assertion failed.');}
    $GLOBALS['nativeChecks']=($GLOBALS['nativeChecks']??0)+1;echo 'PASS '.$label.PHP_EOL;
}
function htnWait(callable $ready): void
{
    $deadline=microtime(true)+20;
    while(!$ready()){if(microtime(true)>$deadline)throw new RuntimeException('A native relay barrier timed out.');usleep(10000);}
}
function htnRow(string $id): ?array
{
    $q=db()->prepare('SELECT * FROM homeserver_https_requests WHERE public_id=?');$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC)?:null;
}
function htnCount(): int{return (int)db()->query('SELECT COUNT(*) FROM homeserver_https_requests')->fetchColumn();}
function htnComplete(array $session,string $id,array $payload,bool $ok=true): void
{
    homeserver_https_v1300_poll($session,['results'=>[['request_id'=>$id,'ok'=>$ok,'status'=>$ok?200:503,'payload'=>$payload]]]);
}
function htnReset(): array
{
    db()->exec("DELETE FROM homeserver_https_requests;
        UPDATE users SET is_active=1;
        UPDATE homeserver_https_sessions SET status='active',last_seen_at=UTC_TIMESTAMP();
        UPDATE homeserver_connections SET status='paired'");
    return homeserver_https_v1300_session(1);
}

try{
    if(!$worker){$admin->exec('CREATE DATABASE `'.$database.'`');$created=true;}
    $pdo=new PDO($fixtureDsn,$databaseUser,$password,$options);$GLOBALS['pdo']=$pdo;
    if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('Disposable database binding could not be verified.');
    $pdo->exec("SET SESSION time_zone='+00:00'");
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=15');

    if($worker){
        $gate=(string)($argv[3]??'');$index=(string)($argv[4]??'');
        if(!is_dir($gate)||!preg_match('/\A[1-9][0-9]*\z/D',$index))throw new RuntimeException('Invalid native relay process barrier.');
        $config=json_decode((string)file_get_contents($gate.'/config-'.$index.'.json'),true,16,JSON_THROW_ON_ERROR);
        $snapshot=homeserver_https_v1300_session(1);
        file_put_contents($gate.'/ready-'.$index,json_encode(['connection_id'=>(int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn()],JSON_THROW_ON_ERROR));
        htnWait(static fn()=>is_file($gate.'/go-'.$index));
        if($mode==='--complete'){
            $requests=[];
            htnWait(static function()use($snapshot,&$requests): bool{
                $requests=homeserver_https_v1300_poll($snapshot,[])['requests'];return count($requests)>0;
            });
            if(count($requests)!==1||$requests[0]['payload']!==$GLOBALS['payload'])throw new RuntimeException('Native relay completion received unexpected context.');
            htnComplete($snapshot,$requests[0]['request_id'],['reply'=>$sentinel,'provider'=>$sentinel,'model'=>$sentinel]);
            echo json_encode(['completed'=>true],JSON_THROW_ON_ERROR).PHP_EOL;exit;
        }
        try{
            if($mode==='--enqueue')homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$snapshot);
            elseif($mode==='--poll')htnComplete($snapshot,(string)$config['request_id'],['reply'=>$sentinel]);
            else homeserver_https_v1300_wait_transient((string)$config['request_id'],1,$snapshot,1000);
            echo json_encode(['accepted'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
        }catch(RuntimeException $error){
            if(str_contains((string)$error,$sentinel))throw new RuntimeException('Native relay failure exposed context.');
            echo json_encode(['accepted'=>false,'public_code'=>(int)$error->getCode()],JSON_THROW_ON_ERROR).PHP_EOL;
        }
        exit;
    }

    $pdo->exec('CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,is_active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO users(id) VALUES(1),(2)');
    $pdo->exec('CREATE TABLE homeserver_connections(user_id INT UNSIGNED PRIMARY KEY,status VARCHAR(20),homeserver_token_enc TEXT,
        installed_version VARCHAR(64),last_seen_at DATETIME,last_checked_at DATETIME,last_error TEXT,capabilities_json TEXT) ENGINE=InnoDB');
    homeserver_https_v1300_ensure_schema($pdo);
    htnCheck(homeserver_https_v1300_transient_ready($pdo),'new installation creates the native transient column');
    $indexColumns=$pdo->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='homeserver_https_requests' AND INDEX_NAME='idx_homeserver_https_transient_expiry' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN);
    htnCheck($indexColumns===['transient_context','expires_at','id'],'new installation creates the native bounded expiry index');
    // Reproduce an existing installation, then execute the checked-in migration.
    $pdo->exec('ALTER TABLE homeserver_https_requests DROP INDEX idx_homeserver_https_transient_expiry,DROP COLUMN transient_context');
    foreach([1,2] as $owner){
        $pdo->prepare("INSERT INTO homeserver_https_sessions(user_id,device_id,session_token_hash,status,last_seen_at) VALUES(?,?,?,'active',UTC_TIMESTAMP())")
            ->execute([$owner,'hs-'.str_repeat((string)$owner,24),hash('sha256','native-session-'.$owner)]);
        $pdo->prepare("INSERT INTO homeserver_connections(user_id,status,homeserver_token_enc) VALUES(?,'paired','fixture-authorization')")->execute([$owner]);
    }
    $session=homeserver_https_v1300_session(1);
    htnCheck(!homeserver_https_v1300_transient_ready($pdo),'actual MySQL missing column is a known pre-upgrade schema');
    $ordinary=homeserver_https_v1300_queue(1,'agent.infer.local',$payload);
    $result=homeserver_https_v1300_poll($session,[]);htnComplete($session,$ordinary,['reply'=>'ordinary']);
    htnCheck($result['requests'][0]['payload']===$payload&&homeserver_https_v1300_wait($ordinary,1000)===['reply'=>'ordinary']
        &&str_contains(htnRow($ordinary)['payload_json'],$sentinel),'ordinary native requests preserve pre-upgrade delivery and durable history');
    $denied=false;try{homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);}catch(RuntimeException $error){$denied=str_contains($error->getMessage(),'database upgrade');}
    htnCheck($denied&&htnCount()===1,'native opt-in fails before storing context when the column is absent');
    $migration=(string)file_get_contents(dirname(__DIR__).'/upgrade-homeserver-transient-inference-v1.sql');
    for($pass=1;$pass<=2;$pass++){
        foreach(explode(';',$migration) as $sql){
            if(trim($sql)==='')continue;$statement=$pdo->query($sql);
            do{if($statement->columnCount()>0)$statement->fetchAll();}while($statement->nextRowset());$statement->closeCursor();
        }
        htnCheck(homeserver_https_v1300_transient_ready($pdo),'checked-in migration is valid and idempotent on native MySQL pass '.$pass);
    }
    $column=$pdo->query("SELECT COLUMN_DEFAULT,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='homeserver_https_requests' AND COLUMN_NAME='transient_context'")->fetch();
    $indexColumns=$pdo->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='homeserver_https_requests' AND INDEX_NAME='idx_homeserver_https_transient_expiry' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN);
    htnCheck((string)$column['COLUMN_DEFAULT']==='0'&&$column['IS_NULLABLE']==='NO'&&$indexColumns===['transient_context','expires_at','id']
        &&(int)htnRow($ordinary)['transient_context']===0,'migration preserves ordinary rows with default zero and the correct native index');
    $pdo->exec('ALTER TABLE homeserver_https_requests DROP INDEX idx_homeserver_https_transient_expiry,DROP COLUMN transient_context');
    $pdo->beginTransaction();$denied=false;
    try{homeserver_transient_schema_ensure_v1($pdo);}catch(RuntimeException $error){$denied=$error->getCode()===503;}
    htnCheck($denied&&$pdo->inTransaction()&&!homeserver_https_v1300_transient_ready($pdo),
        'the actual upgrade helper rejects a caller transaction before DDL without committing or changing its schema');
    $pdo->rollBack();
    for($pass=1;$pass<=2;$pass++){
        homeserver_transient_schema_ensure_v1($pdo);
        $indexColumns=$pdo->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
            AND TABLE_NAME='homeserver_https_requests' AND INDEX_NAME='idx_homeserver_https_transient_expiry' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN);
        htnCheck(homeserver_https_v1300_transient_ready($pdo)&&$indexColumns===['transient_context','expires_at','id']
            &&(int)htnRow($ordinary)['transient_context']===0&&!$pdo->inTransaction(),
            'the actual upgrade helper installs native schema and is idempotent on pass '.$pass);
    }

    $session=htnReset();$id=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);
    $result=homeserver_https_v1300_poll($session,[]);
    htnCheck($result['requests'][0]['payload']===$payload&&htnRow($id)['payload_json']==='{}','native flagged delivery returns messages and clears persisted prompt');
    htnComplete($session,$id,['reply'=>$sentinel]);
    htnCheck(homeserver_https_v1300_wait_transient($id,1,$session,1000)===['reply'=>$sentinel]&&htnRow($id)===null,'native scoped consumption physically deletes the transient response');
    $id=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);htnComplete($session,$id,['error'=>$sentinel],false);
    $denied=false;try{homeserver_https_v1300_wait_transient($id,1,$session,1000);}catch(RuntimeException $error){$denied=!str_contains((string)$error,$sentinel)&&$error->getCode()===503;}
    htnCheck($denied&&htnRow($id)===null,'native failed inference removes context and sanitizes remote errors');
    foreach(['queued','delivered','completed','failed','expired'] as $status){
        $id=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);
        $pdo->prepare("UPDATE homeserver_https_requests SET status=?,response_json=?,expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE public_id=?")
            ->execute([$status,json_encode(['reply'=>$sentinel]),$id]);
        htnCheck(homeserver_https_v1300_scrub_transient($pdo,1)===1&&htnRow($id)===null,'native expiry physically removes orphan content in '.$status.' state');
    }
    foreach(['queued','delivered','completed','failed'] as $status){
        $id=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);
        $pdo->prepare('UPDATE homeserver_https_requests SET status=?,response_json=? WHERE public_id=?')->execute([$status,json_encode(['reply'=>$sentinel]),$id]);
    }
    $ordinary=homeserver_https_v1300_queue(1,'system.ping',['keep'=>'ordinary-history']);homeserver_https_v1300_revoke(1);
    htnCheck(htnCount()===1&&htnRow($ordinary)['status']==='expired','native revoke deletes all transient states while preserving ordinary history');

    $session=htnReset();$ids=[];
    foreach(['queued','delivered','completed','failed'] as $status){
        $id=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);$ids[]=$id;
        $pdo->prepare('UPDATE homeserver_https_requests SET status=?,response_json=? WHERE public_id=?')->execute([$status,json_encode(['reply'=>$sentinel]),$id]);
    }
    $other=homeserver_https_v1300_queue(2,'agent.infer.local',$payload,true,homeserver_https_v1300_session(2));
    $ordinary=homeserver_https_v1300_queue(1,'system.ping',['keep'=>'ordinary-history']);$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
    $posted=array_map(static fn(string $id):array=>['request_id'=>$id,'ok'=>true,'payload'=>['reply'=>$GLOBALS['sentinel']]],$ids);
    $result=homeserver_https_v1300_poll($session,['results'=>$posted]);
    htnCheck(count($result['requests'])===1&&$result['requests'][0]['request_id']===$ordinary&&!str_contains(json_encode($result),$sentinel)
        &&htnCount()===2&&htnRow($other)!==null,'native inactive poll rejects and purges private context while keeping ordinary delivery and other owners');

    $gate=sys_get_temp_dir().'/hs-transient-native-'.bin2hex(random_bytes(8));mkdir($gate,0700);$index=0;
    $start=static function(string $mode,array $config=[])use($database,$gate,&$children,&$index):array{
        $id=++$index;file_put_contents($gate.'/config-'.$id.'.json',json_encode($config,JSON_THROW_ON_ERROR));
        $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,$mode,$database,$gate,(string)$id],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('An independent native relay process could not start.');
        fclose($pipes[0]);$children[$id]=['process'=>$process,'stdout'=>$pipes[1],'stderr'=>$pipes[2]];
        htnWait(static fn()=>is_file($gate.'/ready-'.$id));
        return [$id,json_decode((string)file_get_contents($gate.'/ready-'.$id),true,16,JSON_THROW_ON_ERROR)];
    };
    $finish=static function(int $id)use(&$children):array{
        $child=$children[$id];$status=[];
        htnWait(static function()use($child,&$status):bool{$status=proc_get_status($child['process']);return !$status['running'];});
        $stdout=stream_get_contents($child['stdout']);$stderr=stream_get_contents($child['stderr']);
        fclose($child['stdout']);fclose($child['stderr']);$closed=proc_close($child['process']);unset($children[$id]);
        $exit=(int)($status['exitcode']??$closed);if($exit<0)$exit=$closed;
        if($exit!==0||trim($stderr)!=='')throw new RuntimeException('An independent native relay process failed.');
        $result=json_decode(trim($stdout),true,16,JSON_THROW_ON_ERROR);
        if(!is_array($result))throw new RuntimeException('Invalid native relay process evidence.');return $result;
    };
    $session=htnReset();[$child]=$start('--complete');file_put_contents($gate.'/go-'.$child,'go');
    $fallbacks=0;$result=homeserver_execution_v230_execute(1,'agent.infer.local',$payload,static function()use(&$fallbacks):array{$fallbacks++;return [];},true);
    $completed=$finish($child);
    $receipt=$pdo->query('SELECT result_meta_json FROM homeserver_execution_receipts ORDER BY id DESC LIMIT 1')->fetchColumn();
    htnCheck($completed===['completed'=>true]&&$result['result']['reply']===$sentinel&&$fallbacks===0&&htnCount()===0
        &&json_decode((string)$receipt,true)===['transient_context'=>true],'native canonical execution uses the existing relay and stores only fixed receipt provenance');

    foreach(['inactive','revoked'] as $change){
        foreach(['--enqueue','--poll','--wait'] as $workerMode){
            $session=htnReset();$requestId='';
            if($workerMode!=='--enqueue'){
                $requestId=homeserver_https_v1300_queue(1,'agent.infer.local',$payload,true,$session);
                if($workerMode==='--wait')htnComplete($session,$requestId,['reply'=>$sentinel]);
            }
            // The child captures active session authority before the competing
            // transaction changes the account or revokes that exact session.
            [$child,$ready]=$start($workerMode,['request_id'=>$requestId]);
            $pdo->beginTransaction();$pdo->query('SELECT id FROM users WHERE id=1 FOR UPDATE')->fetch();
            if($change==='inactive')$pdo->exec('UPDATE users SET is_active=0 WHERE id=1');
            else homeserver_https_v1300_revoke(1);
            file_put_contents($gate.'/go-'.$child,'go');
            htnWait(static function()use($admin,$ready):bool{
                $q=$admin->prepare("SELECT 1 FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=? AND trx_state='LOCK WAIT'");
                $q->execute([$ready['connection_id']]);return $q->fetchColumn()!==false;
            });
            htnCheck(proc_get_status($children[$child]['process'])['running'],'native '.$workerMode.' waits on the shared owner lock before '.$change.' authorization changes commit');
            $pdo->commit();$outcome=$finish($child);
            $expectedCode=$workerMode==='--poll'&&$change==='revoked'?410:403;
            // Inactive poll keeps ordinary heartbeat behavior, with no flagged
            // request accepted or returned; its successful empty poll is valid.
            $expected=$workerMode==='--poll'&&$change==='inactive'?['accepted'=>true]:['accepted'=>false,'public_code'=>$expectedCode];
            htnCheck($outcome===$expected&&htnCount()===0&&!$pdo->inTransaction(),
                'native '.$workerMode.' rechecks committed '.$change.' authority and leaves no transient prompt or response');
        }
    }
    echo 'HOMESERVER_HTTPS_TRANSIENT_INFERENCE_MYSQL=PASS ('.$GLOBALS['nativeChecks'].' cases)'.PHP_EOL;
}finally{
    if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    foreach($children as $child){
        if(is_resource($child['process']))proc_terminate($child['process']);
        foreach(['stdout','stderr'] as $pipe)if(is_resource($child[$pipe]))fclose($child[$pipe]);
        if(is_resource($child['process']))proc_close($child['process']);
    }
    if(!$worker&&$gate!==''){
        foreach(glob($gate.'/*')?:[] as $file)unlink($file);
        if(is_dir($gate))rmdir($gate);
    }
    if($created)$admin->exec('DROP DATABASE `'.$database.'`');
}
