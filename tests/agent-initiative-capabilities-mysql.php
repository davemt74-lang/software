<?php
declare(strict_types=1);

// Never expose connection arguments, environment values, driver messages or
// uncaught exception arguments in CI, including failures in child processes.
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

$dsn=(string)(getenv('VP3_SECTION7_MYSQL_DSN')?:getenv('VP3_MYSQL_TEST_DSN'));
if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('An explicit disposable MySQL capability fixture DSN is required.');
$databaseUser=(string)(getenv('VP3_SECTION7_MYSQL_USER')?:getenv('VP3_MYSQL_TEST_USER')?:'root');
$password=(string)(getenv('VP3_SECTION7_MYSQL_PASSWORD')?:getenv('VP3_MYSQL_TEST_PASSWORD'));
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false];
$mode=(string)($argv[1]??'');$worker=str_starts_with($mode,'--');
if($worker&&!in_array($mode,['--writer','--hold-product'],true))throw new RuntimeException('Invalid native worker mode.');
$database=$worker?(string)($argv[2]??''):'initiative_capabilities_'.bin2hex(random_bytes(6));
if(!preg_match('/\Ainitiative_capabilities_[a-f0-9]{12}\z/D',$database))throw new RuntimeException('Invalid disposable database name.');
$admin=new PDO($dsn,$databaseUser,$password,$options);
$connectionParts=array_values(array_filter(explode(';',substr($dsn,6)),
    static fn(string $part):bool=>preg_match('/\A\s*dbname\s*=/i',$part)!==1));
$fixtureDsn='mysql:'.implode(';',$connectionParts).';dbname='.$database;
$created=false;$children=[];$gate='';$pdo=null;

function db(): PDO{return $GLOBALS['pdo'];}
function table_exists(string $table): bool
{
    $q=db()->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $q->execute([$table]);return $q->fetchColumn()!==false;
}
function column_exists(string $table,string $column): bool
{
    $q=db()->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $q->execute([$table,$column]);return $q->fetchColumn()!==false;
}
function setting(string $key,mixed $default=''): mixed{return $default;}
function url(string $path): string{return $path;}
function has_permission(string $permission,array $user): bool{return true;}
// This fixture targets physical source locks. The full pipeline separately
// verifies current Initiative policy; this domain still runs its real live
// owner, conversation and System-only Commerce authorization checks.
function vp3_initiative_capability_scope_v1(PDO $pdo,array $scope,array $binding): array{return $scope;}
function ai_generate_chat_response(...$arguments): array
{
    $GLOBALS['capabilityNativeModelCalls']=($GLOBALS['capabilityNativeModelCalls']??0)+1;
    throw new LogicException('A native source-lock proof cannot call a model.');
}

/** Load the actual production functions without unrelated application bootstrap. */
function capability_native_production_function(string $file,string $name): void
{
    $tokens=token_get_all(file_get_contents(dirname(__DIR__).'/includes/'.$file));
    for($i=0;$i<count($tokens);$i++){
        if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION)continue;
        $j=$i+1;while(is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;
        if(!is_array($tokens[$j])||$tokens[$j][0]!==T_STRING||$tokens[$j][1]!==$name)continue;
        $body='';$braces=0;$opened=false;
        for($j=$i;$j<count($tokens);$j++){
            $part=is_array($tokens[$j])?$tokens[$j][1]:$tokens[$j];$body.=$part;
            if($part==='{'){$opened=true;$braces++;}
            elseif($part==='}'&&--$braces===0&&$opened){eval($body);return;}
        }
    }
    throw new RuntimeException('A required production fixture function is unavailable.');
}
function capability_native_wait(callable $ready,string $label): void
{
    $deadline=microtime(true)+20;
    while(!$ready()){
        if(microtime(true)>$deadline)throw new RuntimeException($label);
        usleep(10000);
    }
}
function capability_native_check(bool $ok,string $label): void
{
    if(!$ok){fwrite(STDERR,'FAIL '.$label.PHP_EOL);throw new RuntimeException('A native capability assertion failed.');}
    $GLOBALS['capabilityNativeChecks']=($GLOBALS['capabilityNativeChecks']??0)+1;
    echo 'PASS '.$label.PHP_EOL;
}
function capability_native_scope(): array
{
    return ['user'=>['id'=>1,'is_active'=>1],'principal'=>['kind'=>'system','agent_id'=>0,'owner_user_id'=>0,'viewer_user_id'=>1],
        'agent_id'=>0,'conversation_id'=>7];
}
function capability_native_binding(): array{return ['enabled'=>true,'agent_id'=>0,'conversation_id'=>7];}
function capability_native_evidence(): array
{
    $coverage=[];
    return vp3_initiative_product_capabilities_evidence_v1(db(),capability_native_scope(),capability_native_binding(),
        new DateTimeImmutable('now',new DateTimeZone('UTC')),['product-context:101'],$coverage);
}
function capability_native_read(array $evidence): array
{
    return vp3_initiative_product_capabilities_read_v1(db(),capability_native_scope(),capability_native_binding(),
        'products.content.prepare',['primary'=>'product-context:101','refs'=>['product-context:101']],$evidence);
}
function capability_native_write(PDO $connection,string $kind,string $tag): void
{
    match($kind){
        'product'=>$connection->prepare('UPDATE agent_commerce_products_v800 SET description=? WHERE id=101')->execute(['Independent product edit '.$tag]),
        'media'=>$connection->prepare('UPDATE user_media_assets SET title=? WHERE id=301')->execute(['Independent media edit '.$tag]),
        'binding_delete'=>$connection->exec("DELETE FROM agent_commerce_product_bindings_v800 WHERE product_id=101 AND binding_type='event' AND binding_id=51"),
        'binding_insert'=>$connection->exec("INSERT INTO agent_commerce_product_bindings_v800(product_id,binding_type,binding_id) VALUES(101,'native_new',991)"),
        default=>throw new LogicException('Unknown native source writer.'),
    };
}

try{
    if(!$worker){$admin->exec('CREATE DATABASE `'.$database.'`');$created=true;}
    $pdo=new PDO($fixtureDsn,$databaseUser,$password,$options);$GLOBALS['pdo']=$pdo;
    if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$database)throw new RuntimeException('Disposable database binding could not be verified.');
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=1');
    require_once dirname(__DIR__).'/includes/participant-updates-v1.php';
    capability_native_production_function('agent-capability-chat-v1.php','vp3_capability_live_user_v1');
    capability_native_production_function('agent-initiative-followthrough-v1.php','vp3_initiative_followthrough_begin_v1');
    capability_native_production_function('agent-initiative-followthrough-v1.php','vp3_initiative_followthrough_transaction_v1');
    require_once dirname(__DIR__).'/includes/agent-initiative-product-context-v1.php';

    if($worker){
        $gate=(string)($argv[3]??'');$index=(string)($argv[4]??'');
        if(!is_dir($gate)||!preg_match('/\A[1-9][0-9]*\z/D',$index))throw new RuntimeException('Invalid native process barrier.');
        $config=json_decode((string)file_get_contents($gate.'/config-'.$index.'.json'),true,16,JSON_THROW_ON_ERROR);
        if($mode==='--hold-product'){
            $pdo->beginTransaction();
            $pdo->exec('UPDATE agent_commerce_products_v800 SET price_cents=price_cents+1 WHERE id=101');
            file_put_contents($gate.'/ready-'.$index,'locked');
            capability_native_wait(static fn()=>is_file($gate.'/release-'.$index),'Native held-source release timed out.');
            $pdo->rollBack();
            echo json_encode(['rolled_back'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
            exit;
        }
        file_put_contents($gate.'/ready-'.$index,'ready');
        capability_native_wait(static fn()=>is_file($gate.'/go-'.$index),'Native source writer start timed out.');
        file_put_contents($gate.'/entered-'.$index,'entered');
        $blocked=false;$driver=0;
        try{capability_native_write($pdo,(string)$config['write'],(string)$config['tag']);}
        catch(PDOException $error){
            $driver=(int)($error->errorInfo[1]??0);
            if($driver!==1205)throw $error;
            $blocked=true;
        }
        file_put_contents($gate.'/attempt-'.$index,json_encode(['blocked'=>$blocked,'driver_code'=>$driver],JSON_THROW_ON_ERROR));
        if($blocked){
            capability_native_wait(static fn()=>is_file($gate.'/retry-'.$index),'Native source writer release timed out.');
            capability_native_write($pdo,(string)$config['write'],(string)$config['tag']);
        }
        echo json_encode(['blocked'=>$blocked,'driver_code'=>$driver,'completed'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
        exit;
    }

    $pdo->exec('CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,is_active TINYINT NOT NULL);
        INSERT INTO users VALUES(1,1),(2,1);
        CREATE TABLE user_agents(id BIGINT UNSIGNED PRIMARY KEY);
        CREATE TABLE chat_conversations(id INT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED NOT NULL,user_agent_id BIGINT UNSIGNED NULL);
        INSERT INTO chat_conversations VALUES(7,1,NULL);
        CREATE TABLE user_media_assets(id BIGINT UNSIGNED PRIMARY KEY,user_id INT UNSIGNED NOT NULL,
          media_type VARCHAR(12),title VARCHAR(190),mime_type VARCHAR(120),file_path VARCHAR(250),file_size BIGINT,
          duration_seconds DOUBLE,source VARCHAR(80),metadata_json TEXT,created_at DATETIME,updated_at DATETIME);
        INSERT INTO user_media_assets VALUES(301,1,\'photo\',\'Recorded owned media\',\'image/png\',\'private/not-read.png\',512,0,\'upload\',\'{}\',NOW(),NOW());
        CREATE TABLE capability_native_commit_markers(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tag VARCHAR(80),source_revision CHAR(64)) ENGINE=InnoDB');
    agent_commerce_ensure_schema_v800($pdo);
    $pdo->exec("INSERT INTO agent_commerce_products_v800(id,owner_user_id,product_key,title,description,product_type,fulfillment_type,payment_mode,price_cents,currency,is_active,metadata_json)
        VALUES(101,1,'native-owned','Native owned product','Recorded product description','service','virtual','full',12000,'usd',1,'{\"source_asset_id\":\"301\"}')");
    $gate=sys_get_temp_dir().'/vp3-capability-native-'.bin2hex(random_bytes(8));mkdir($gate,0700);
    $index=0;
    $start=static function(string $workerMode,array $config)use($database,$gate,&$children,&$index): int{
        $id=++$index;
        file_put_contents($gate.'/config-'.$id.'.json',json_encode($config,JSON_THROW_ON_ERROR));
        $pipes=[];$process=proc_open([PHP_BINARY,__FILE__,$workerMode,$database,$gate,(string)$id],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('An independent native source writer could not start.');
        fclose($pipes[0]);$children[$id]=['process'=>$process,'stdout'=>$pipes[1],'stderr'=>$pipes[2]];
        capability_native_wait(static fn()=>is_file($gate.'/ready-'.$id),'An independent native source writer did not become ready.');
        return $id;
    };
    $finish=static function(int $id)use(&$children): array{
        $child=$children[$id];$status=[];
        capability_native_wait(static function()use($child,&$status): bool{$status=proc_get_status($child['process']);return !$status['running'];},'An independent native worker did not finish.');
        $stdout=stream_get_contents($child['stdout']);$stderr=stream_get_contents($child['stderr']);
        fclose($child['stdout']);fclose($child['stderr']);$closed=proc_close($child['process']);unset($children[$id]);
        $exit=(int)($status['exitcode']??$closed);if($exit<0)$exit=$closed;
        if($exit!==0||trim($stderr)!=='')throw new RuntimeException('An independent native worker failed.');
        $value=json_decode(trim($stdout),true,16,JSON_THROW_ON_ERROR);
        if(!is_array($value))throw new RuntimeException('An independent native worker returned invalid evidence.');
        return $value;
    };
    $reset=static function()use($pdo): void{
        $pdo->exec("UPDATE agent_commerce_products_v800 SET description='Recorded product description',price_cents=12000 WHERE id=101;
            UPDATE user_media_assets SET title='Recorded owned media' WHERE id=301;
            DELETE FROM agent_commerce_product_bindings_v800 WHERE product_id=101;
            INSERT INTO agent_commerce_product_bindings_v800(product_id,binding_type,binding_id) VALUES(101,'event',51),(101,'digital',61)");
    };

    foreach(['product','media','binding_delete','binding_insert'] as $kind){
        $reset();$evidence=capability_native_evidence();$expected=$evidence['product-context:101']['revision'];
        $id=$start('--writer',['write'=>$kind,'tag'=>$kind]);$attempt=[];
        $value=vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$gate,$id,$kind,$evidence,$expected,&$attempt): string{
            $read=capability_native_read($evidence);
            if(($read['records'][0]['source_revision']??null)!==$expected)throw new RuntimeException('Native source revision did not match.');
            $pdo->prepare('INSERT INTO capability_native_commit_markers(tag,source_revision) VALUES(?,?)')->execute([$kind,$expected]);
            file_put_contents($gate.'/go-'.$id,'go');
            capability_native_wait(static fn()=>is_file($gate.'/attempt-'.$id),'The competing native writer did not finish its lock probe.');
            $attempt=json_decode((string)file_get_contents($gate.'/attempt-'.$id),true,16,JSON_THROW_ON_ERROR);
            capability_native_check($attempt===['blocked'=>true,'driver_code'=>1205],
                'actual '.$kind.' writer is blocked by production source reads until the final SERIALIZABLE commit');
            return $expected;
        });
        file_put_contents($gate.'/retry-'.$id,'retry');$writer=$finish($id);
        $q=$pdo->prepare('SELECT source_revision FROM capability_native_commit_markers WHERE tag=?');$q->execute([$kind]);
        capability_native_check($writer===['blocked'=>true,'driver_code'=>1205,'completed'=>true]
            &&$value===$expected&&$q->fetchColumn()===$expected&&!$pdo->inTransaction(),
            'the independent '.$kind.' writer succeeds only after one guarded source-bound final commit');
    }

    $reset();$stale=capability_native_evidence();$before=(int)$pdo->query('SELECT COUNT(*) FROM capability_native_commit_markers')->fetchColumn();
    $id=$start('--writer',['write'=>'product','tag'=>'before-final-guard']);file_put_contents($gate.'/go-'.$id,'go');
    capability_native_wait(static fn()=>is_file($gate.'/attempt-'.$id),'The pre-commit source edit did not finish.');$edited=$finish($id);
    $attempts=0;$code=0;
    try{
        vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$stale,&$attempts): void{
            ++$attempts;
            $pdo->exec("INSERT INTO capability_native_commit_markers(tag,source_revision) VALUES('must-rollback-stale','unused')");
            capability_native_read($stale);
        });
    }catch(RuntimeException $error){$code=(int)$error->getCode();}
    capability_native_check($edited===['blocked'=>false,'driver_code'=>0,'completed'=>true]&&$code===409&&$attempts===1
        &&!$pdo->inTransaction()&&(int)$pdo->query('SELECT COUNT(*) FROM capability_native_commit_markers')->fetchColumn()===$before,
        'a source changed before the final transaction rejects stale work and rolls back its attempted commit marker without retry');

    $reset();$fresh=capability_native_evidence();
    capability_native_check(strtoupper((string)$pdo->query('SELECT @@transaction_isolation')->fetchColumn())==='READ-COMMITTED',
        'the production one-shot transaction leaves the session isolation at its prior READ COMMITTED setting');
    $pdo->beginTransaction();capability_native_read($fresh);
    $id=$start('--writer',['write'=>'product','tag'=>'ordinary-read-committed']);file_put_contents($gate.'/go-'.$id,'go');
    capability_native_wait(static fn()=>is_file($gate.'/attempt-'.$id),'The ordinary transaction writer did not finish.');
    $ordinary=json_decode((string)file_get_contents($gate.'/attempt-'.$id),true,16,JSON_THROW_ON_ERROR);
    if($ordinary['blocked'])file_put_contents($gate.'/retry-'.$id,'retry');
    $pdo->commit();$ordinaryWriter=$finish($id);
    capability_native_check($ordinary===['blocked'=>false,'driver_code'=>0]&&$ordinaryWriter['completed']===true,
        'the next unrelated transaction really permits an independent source update instead of inheriting SERIALIZABLE locks');

    $reset();$fresh=capability_native_evidence();$before=(int)$pdo->query('SELECT COUNT(*) FROM capability_native_commit_markers')->fetchColumn();
    $id=$start('--hold-product',[]);$attempts=0;$driver=0;
    try{
        vp3_initiative_followthrough_transaction_v1($pdo,static function()use($pdo,$fresh,&$attempts): void{
            ++$attempts;
            $pdo->exec("INSERT INTO capability_native_commit_markers(tag,source_revision) VALUES('must-rollback-timeout','unused')");
            capability_native_read($fresh);
        });
    }catch(PDOException $error){$driver=(int)($error->errorInfo[1]??0);}
    finally{file_put_contents($gate.'/release-'.$id,'release');}
    $held=$finish($id);
    capability_native_check($driver===1205&&$attempts===1&&!$pdo->inTransaction()&&$held===['rolled_back'=>true]
        &&(int)$pdo->query('SELECT COUNT(*) FROM capability_native_commit_markers')->fetchColumn()===$before,
        'actual InnoDB1205 during a canonical product read rolls back every attempted marker and is not automatically retried');
    capability_native_check(strtoupper((string)$pdo->query('SELECT @@transaction_isolation')->fetchColumn())==='READ-COMMITTED',
        'a source-lock timeout also leaves the session isolation unchanged after rollback');
    capability_native_check(($GLOBALS['capabilityNativeModelCalls']??0)===0,
        'source guards, native concurrency probes and database-only retries invoke no inference provider');
    echo 'AGENT_INITIATIVE_CAPABILITIES_MYSQL=PASS ('.$GLOBALS['capabilityNativeChecks'].' cases)'.PHP_EOL;
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
