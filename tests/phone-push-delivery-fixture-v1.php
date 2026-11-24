<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/phone-push-delivery-v1.php';
require_once dirname(__DIR__).'/includes/permissions.php';

function db():PDO{return $GLOBALS['ppd_db'];}
if(!function_exists('table_exists')){function table_exists(string $table):bool{return schema_metadata_table_exists(db(),$table);}}
if(!function_exists('column_exists')){function column_exists(string $table,string $column):bool{return schema_metadata_column_exists(db(),$table,$column);}}

function ppd_connect(string $dsn):PDO
{
    $pdo=new PDO($dsn,(string)(getenv('VP3_TEST_MYSQL_USER')?:'root'),(string)getenv('VP3_TEST_MYSQL_PASS'),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){$pdo->exec('PRAGMA foreign_keys=ON');$pdo->exec('PRAGMA busy_timeout=15000');}
    return $pdo;
}

function ppd_schema(PDO $pdo):void
{
    $o=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $pdo->exec("CREATE TABLE users(id INT UNSIGNED PRIMARY KEY,display_name VARCHAR(120) NOT NULL,is_active SMALLINT NOT NULL,role VARCHAR(32) NOT NULL)".$o);
    $pdo->exec("CREATE TABLE permissions(permission_key VARCHAR(64) PRIMARY KEY)".$o);
    $pdo->exec("CREATE TABLE role_permissions(role VARCHAR(32),permission_key VARCHAR(64),PRIMARY KEY(role,permission_key))".$o);
    $pdo->exec("INSERT INTO users VALUES(1,'Owner',1,'fan'),(2,'Other owner',1,'fan'),(3,'Inactive',0,'fan')");
    $pdo->exec("INSERT INTO permissions VALUES('chat.access'),('account.access')");
    $pdo->exec("INSERT INTO role_permissions VALUES('fan','chat.access'),('fan','account.access')");
    vp3_phone_schema_v1($pdo);
    // Explicitly reproduce the pre-delivery upgrade, including when the parent
    // Phone installer has already integrated the additive delivery migration.
    $pdo->exec('DROP TABLE IF EXISTS vp3_phone_push_outbox_v1');
    $pdo->exec('DROP TABLE IF EXISTS vp3_phone_push_delivery_config_v1');
    $pdo->exec("CREATE TABLE push_fixture_sources(owner_user_id INT UNSIGNED NOT NULL,device_id CHAR(32) NOT NULL,
        source_type VARCHAR(16) NOT NULL,source_id VARCHAR(96) NOT NULL,revision BIGINT NOT NULL,
        expires_epoch BIGINT NOT NULL,current_source SMALLINT NOT NULL DEFAULT 1,
        PRIMARY KEY(owner_user_id,device_id,source_type,source_id,revision))".$o);
    $pdo->exec("CREATE TABLE push_fixture_sends(event_ref CHAR(32) PRIMARY KEY,sends INT NOT NULL)".$o);
}

function ppd_row(PDO $pdo,array $device):array
{return vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_devices_v1 WHERE id=?',[$device['device']['id']])->fetch(PDO::FETCH_ASSOC);}

function ppd_make(PDO $pdo,int $owner,string $name):array
{
    $cmd=static fn():array=>['request_id'=>bin2hex(random_bytes(16))];
    $invite=vp3_phone_owner_v1($pdo,$owner,'invite',$cmd())['invite'];
    $r=vp3_phone_device_v1($pdo,'','claim',['invite'=>$invite,'name'=>$name,'claim_key'=>bin2hex(random_bytes(32))]);
    $r['device']=vp3_phone_owner_v1($pdo,$owner,'approve',$cmd()+['device_id'=>$r['device']['id'],'generation'=>$r['device']['generation'],'role'=>'owner','allow_location'=>false])['device'];
    return $r;
}

function ppd_subscribe(PDO $pdo,array $device,int $now,string $key,?array $sub=null):array
{
    $sub??=['endpoint'=>'https://web.push.apple.com/push_'.bin2hex(random_bytes(16)),
        'expirationTime'=>null,'keys'=>['p256dh'=>vp3_phone_push_keypair_v1()['public_key'],'auth'=>vp3_phone_push_b64_v1(random_bytes(16))]];
    vp3_phone_transaction_v1($pdo,static function()use($pdo,$device,$now,$key,$sub):array{
        $d=ppd_row($pdo,$device);vp3_phone_account_v1($pdo,(int)$d['owner_user_id'],true);
        $d=vp3_phone_row_v1($pdo,(int)$d['owner_user_id'],$d['id']);$old=vp3_phone_push_row_v1($pdo,$d,true);
        return vp3_phone_push_holder_v1($pdo,$d,'push_register',['device_id'=>$d['id'],
            'subscription_revision'=>(int)($old['revision']??0),'confirmed'=>true,
            'key_id'=>hash('sha256',vp3_phone_push_config_v1($pdo)['public_key']),'subscription'=>$sub],$now,$key);
    });
    return $sub;
}

function ppd_source(PDO $pdo,array $device,int $now,string $type='private_chat',?string $id=null,int $ttl=3600,int $revision=1):array
{
    $d=ppd_row($pdo,$device);$id??=bin2hex(random_bytes(16));
    vp3_phone_sql_v1($pdo,'INSERT INTO push_fixture_sources(owner_user_id,device_id,source_type,source_id,revision,expires_epoch) VALUES(?,?,?,?,?,?)',
        [(int)$d['owner_user_id'],$d['id'],$type,$id,$revision,$now+$ttl]);
    return ['type'=>$type,'id'=>$id,'revision'=>$revision,'expires_epoch'=>$now+$ttl];
}

function ppd_source_current(PDO $pdo,array $d,array $source,int $now):bool
{
    return (bool)vp3_phone_sql_v1($pdo,'SELECT 1 FROM push_fixture_sources WHERE owner_user_id=? AND device_id=? AND source_type=? AND source_id=? AND revision=? AND expires_epoch>? AND current_source=1',
        [(int)$d['owner_user_id'],$d['id'],$source['type'],$source['id'],$source['revision'],$now])->fetchColumn();
}

function ppd_enqueue(PDO $pdo,array $device,array $source,int $now,string $key):array
{return vp3_phone_push_delivery_enqueue_v1($pdo,ppd_row($pdo,$device),$source,$now,'ppd_source_current',$key);}

function ppd_disable(PDO $pdo,array $device,int $now,string $key,bool $hook=true):void
{
    vp3_phone_transaction_v1($pdo,static function()use($pdo,$device,$now,$key,$hook):array{
        $d=ppd_row($pdo,$device);vp3_phone_account_v1($pdo,(int)$d['owner_user_id'],true);
        $d=vp3_phone_row_v1($pdo,(int)$d['owner_user_id'],$d['id']);$s=vp3_phone_push_row_v1($pdo,$d,true);
        $r=vp3_phone_push_holder_v1($pdo,$d,'push_disable',['device_id'=>$d['id'],'subscription_revision'=>(int)$s['revision'],'confirmed'=>true],$now,$key);
        if($hook)vp3_phone_push_delivery_cancel_device_v1($pdo,$d,$now);
        return $r;
    });
}

function ppd_accepted():array
{return ['state'=>'accepted','code'=>'provider_accepted','http_status'=>201,'retry_after'=>0,'dispatched'=>true];}

function ppd_run(PDO $pdo,int $now,string $key,?callable $transport=null,int $limit=25):array
{
    return vp3_phone_push_delivery_run_v1($pdo,$limit,'ppd_source_current',$transport??static fn()=>ppd_accepted(),$now,$key,static fn():int=>$now);
}

function ppd_check(bool $ok,string $label):void
{
    if(!$ok)throw new RuntimeException($label);
    $GLOBALS['ppd_checks']=($GLOBALS['ppd_checks']??0)+1;echo 'ok - '.$label."\n";
}

function ppd_reject(int $code,callable $fn,string $label):void
{
    try{$fn();}catch(RuntimeException $e){ppd_check($e->getCode()===$code,$label.' ('.$e->getCode().')');return;}
    throw new RuntimeException('Expected rejection: '.$label);
}

/** Two actual PHP processes, each with native independent PDO and a start barrier. */
function ppd_parallel(array $jobs):array
{
    $children=[];
    try{
        foreach($jobs as $job){
            $process=proc_open([PHP_BINARY,'-d','display_errors=stderr',__DIR__.'/phone-push-delivery-v1.php','client'],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Cannot start push fixture child.');
            $children[]=[$process,$pipes];stream_set_timeout($pipes[1],25);
            fwrite($pipes[0],json_encode($job,JSON_THROW_ON_ERROR)."\n");
        }
        foreach($children as [,$pipes])if(trim((string)fgets($pipes[1]))!=='READY')throw new RuntimeException('Push fixture child failed to reach barrier: '.stream_get_contents($pipes[2]));
        foreach($children as [,$pipes])fwrite($pipes[0],"GO\n");
        $out=[];
        foreach($children as [,$pipes]){
            $line=(string)fgets($pipes[1]);$decoded=json_decode($line,true);
            if(!is_array($decoded))throw new RuntimeException('Push fixture child failed: '.$line.' '.stream_get_contents($pipes[2]));
            $out[]=$decoded;
        }
        return $out;
    }finally{
        foreach($children as [$process,$pipes]){foreach($pipes as $pipe)fclose($pipe);proc_close($process);}
    }
}
