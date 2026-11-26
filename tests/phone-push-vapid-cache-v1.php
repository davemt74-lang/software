<?php
declare(strict_types=1);

require_once __DIR__.'/phone-push-delivery-fixture-v1.php';
require_once dirname(__DIR__).'/includes/phone-push-vapid-cache-v1.php';

function ppvc_vapid(PDO $pdo,string $key):array
{
    $p=vp3_phone_push_config_v1($pdo);$c=vp3_phone_push_delivery_config_v1($pdo);
    return json_decode(ai_decrypt_secret_with_key($p['private_cipher'],$key),true,8,JSON_THROW_ON_ERROR)
        +['subject'=>$c['subject'],'config_revision'=>(int)$c['revision']];
}

/** This non-signing opaque fixture is never sent. The separate transport suite
 * verifies real vendor ES256/JWT signatures. Count only committed cache mints.
 */
function ppvc_factory(PDO $pdo):callable
{
    return static function(array $vapid,string $origin,int $now)use($pdo):array{
        if(!vp3_phone_transaction_active_v1($pdo))throw new LogicException('Minting must be serialized.');
        vp3_phone_sql_v1($pdo,'UPDATE push_vapid_fixture SET calls=calls+1 WHERE id=1',[]);
        $n=(int)$pdo->query('SELECT calls FROM push_vapid_fixture WHERE id=1')->fetchColumn();
        $payload=vp3_phone_push_b64_v1(json_encode(['aud'=>$origin,'sub'=>$vapid['subject'],'exp'=>$now+43200,'fixture'=>$n],JSON_THROW_ON_ERROR));
        return ['authorization'=>'vapid t=eyJhbGciOiJFUzI1NiJ9.'.$payload.'.'.vp3_phone_push_b64_v1(str_repeat('s',64)).', k='.$vapid['public_key'],
            'issued_epoch'=>$now,'expires_epoch'=>$now+43200];
    };
}

function ppvc_reject(string $reason,callable $fn,string $label):PhonePushVapidCacheUnavailableV1
{
    try{$fn();}catch(PhonePushVapidCacheUnavailableV1 $e){ppd_check($e->reason===$reason&&$e->getCode()===503,$label);return $e;}
    throw new RuntimeException('Expected cache rejection: '.$label);
}

function ppvc_parallel(string $origin,int $now):array
{
    $children=[];
    try{
        for($i=0;$i<2;$i++){
            $process=proc_open([PHP_BINARY,'-d','display_errors=stderr',__FILE__,'client'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            if(!is_resource($process))throw new RuntimeException('Cannot start isolated cache client.');
            $children[]=[$process,$pipes];stream_set_timeout($pipes[1],20);
            fwrite($pipes[0],json_encode(['origin'=>$origin,'now'=>$now],JSON_THROW_ON_ERROR)."\n");
        }
        foreach($children as [,$p])if(trim((string)fgets($p[1]))!=='READY')throw new RuntimeException('Cache client barrier failed: '.stream_get_contents($p[2]));
        foreach($children as [,$p])fwrite($p[0],"GO\n");
        $out=[];
        foreach($children as [,$p]){
            $r=json_decode((string)fgets($p[1]),true);
            if(!is_array($r))throw new RuntimeException('Cache client failed: '.stream_get_contents($p[2]));
            $out[]=$r;
        }
        return $out;
    }finally{foreach($children as [$process,$p]){foreach($p as $pipe)fclose($pipe);proc_close($process);}}
}

if(($argv[1]??'')==='client'){
    $GLOBALS['ppd_db']=$pdo=ppd_connect((string)getenv('VP3_PUSH_VAPID_DSN'));
    $key=hex2bin((string)getenv('VP3_PUSH_VAPID_KEY'));$job=json_decode((string)fgets(STDIN),true,8,JSON_THROW_ON_ERROR);
    echo "READY\n";fflush(STDOUT);if(trim((string)fgets(STDIN))!=='GO')exit(2);
    $header=vp3_phone_push_vapid_cache_get_v1($pdo,ppvc_vapid($pdo,$key),$job['origin'],$job['now'],ppvc_factory($pdo),$key);
    // No raw token, key, endpoint or subject in process output.
    echo json_encode(['hash'=>hash('sha256',$header['authorization']),'issued_epoch'=>$header['issued_epoch']],JSON_THROW_ON_ERROR)."\n";exit;
}

$server=null;$schema=null;$file=null;$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');
if($dsn!==''){
    if(!str_starts_with($dsn,'mysql:'))throw new RuntimeException('Expected isolated MySQL DSN.');
    $serverDsn=preg_replace('/(?:^mysql:|;)dbname=[^;]*(?:;|$)/',str_starts_with(substr($dsn,6),'dbname=')?'mysql:':';',$dsn);
    $server=ppd_connect($serverDsn);$schema='vp3_vapid_cache_test_'.bin2hex(random_bytes(8));
    $server->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $dsn=rtrim($serverDsn,';').';dbname='.$schema;
}else{$file=tempnam(sys_get_temp_dir(),'vp3_vapid_cache_');$dsn='sqlite:'.$file;}

try{
    $GLOBALS['ppd_db']=$pdo=ppd_connect($dsn);$now=time();$key=random_bytes(32);$admin=['role'=>'admin'];
    putenv('VP3_PUSH_VAPID_DSN='.$dsn);putenv('VP3_PUSH_VAPID_KEY='.bin2hex($key));
    ppd_schema($pdo);vp3_phone_push_delivery_install_v1($pdo);
    $pdo->exec('DROP TABLE IF EXISTS vp3_phone_push_vapid_cache_v1');
    ppd_check(!vp3_phone_push_vapid_cache_ready_v1($pdo),'cache schema is explicit and readiness does not create it');
    vp3_phone_push_vapid_cache_install_v1($pdo);vp3_phone_push_vapid_cache_install_v1($pdo);
    ppd_check(vp3_phone_push_vapid_cache_ready_v1($pdo),'cache installer replays without foreign-key coupling to optional delivery tables');
    $pdo->exec('CREATE TABLE push_vapid_fixture(id INT PRIMARY KEY,calls INT NOT NULL)'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' ENGINE=InnoDB':''));
    $pdo->exec('INSERT INTO push_vapid_fixture VALUES(1,0)');
    vp3_phone_push_configure_v1($pdo,$admin,true,$now,$key);
    vp3_phone_push_delivery_configure_v1($pdo,$admin,['enabled'=>true,'subject'=>'mailto:ops@example.test','revision'=>1],$now,$key);
    $vapid=ppvc_vapid($pdo,$key);$factory=ppvc_factory($pdo);$origin='https://web.push.apple.com';
    $get=static fn(array $v,string $o,int $t):array=>vp3_phone_push_vapid_cache_get_v1($pdo,$v,$o,$t,$factory,$key);
    $calls=static fn():int=>(int)$pdo->query('SELECT calls FROM push_vapid_fixture WHERE id=1')->fetchColumn();
    $h1=$get($vapid,$origin,$now);
    ppd_check(!vp3_phone_transaction_active_v1($pdo)&&$h1['issued_epoch']===$now&&$calls()===1,'local signing is serialized but cache returns after releasing the database transaction');
    $h2=$get($vapid,$origin,$now+30);
    ppd_check($h1===$h2&&$calls()===1,'repeated calls reuse byte-identical authorization rather than minting each notification');
    $stored=$pdo->query('SELECT * FROM vp3_phone_push_vapid_cache_v1')->fetch(PDO::FETCH_ASSOC);
    ppd_check(!str_contains(json_encode($stored),$h1['authorization'])&&!str_contains($stored['header_cipher'],$vapid['private_key']),
        'authorization is encrypted at rest and the cache contains no VAPID private key');
    $plain=json_decode(ai_decrypt_secret_with_key($stored['header_cipher'],$key),true,12,JSON_THROW_ON_ERROR);
    ppd_check($plain['origin']===$origin&&$plain['key_id']===hash('sha256',$vapid['public_key'])
        &&$plain['subject_hash']===hash('sha256',$vapid['subject'])&&$plain['config_revision']===$vapid['config_revision'],
        'authenticated ciphertext binds origin, public key, subject and prepared configuration revision');
    $r=ppvc_parallel($origin,$now+40);
    ppd_check($r[0]['hash']===hash('sha256',$h1['authorization'])&&$r[0]===$r[1]&&$calls()===1,'separate cron processes reuse the same persisted authorization');
    $r=ppvc_parallel('https://region1.push.apple.com',$now+50);
    ppd_check($r[0]===$r[1]&&$calls()===2,'parallel first use of an origin mints and commits only one token');
    $mozilla=$get($vapid,'https://updates.push.services.mozilla.com',$now+60);
    ppd_check($mozilla['authorization']!==$h1['authorization']&&$calls()===3,'different provider origins have distinct authorization audiences');
    ppvc_reject('vapid_cache_invalid_scope',fn()=>$get($vapid,'https://web.push.apple.com/not-an-origin',$now+70),'a subscription endpoint is not accepted as a token audience');
    ppvc_reject('vapid_cache_invalid_scope',fn()=>$get($vapid,'https://attacker.example',$now+70),'unrecognized provider audience is rejected before signing');
    ppvc_reject('vapid_cache_configuration_changed',fn()=>$get(array_replace($vapid,['config_revision'=>1]),$origin,$now+70),'stale prepared configuration cannot use cached authorization');
    ppvc_reject('vapid_cache_configuration_changed',fn()=>$get(array_replace($vapid,['subject'=>'mailto:other@example.test']),$origin,$now+70),'wrong subject cannot reuse a valid token');
    ppvc_reject('vapid_cache_key_unavailable',fn()=>vp3_phone_push_vapid_cache_get_v1($pdo,$vapid,$origin,$now+70,$factory,random_bytes(32)),'lost master key does not replace or expose cached authorization');
    ppvc_reject('vapid_cache_key_unavailable',fn()=>$get(array_replace($vapid,['private_key'=>vp3_phone_push_b64_v1(random_bytes(32))]),$origin,$now+70),'wrong prepared private key cannot poison the cache');
    $before=$calls();
    vp3_phone_push_delivery_configure_v1($pdo,$admin,['enabled'=>false,'subject'=>$vapid['subject'],'revision'=>2],$now+80,$key);
    ppvc_reject('vapid_cache_configuration_changed',fn()=>$get($vapid,$origin,$now+80),'disabled configuration cannot use a previously valid token');
    vp3_phone_push_delivery_configure_v1($pdo,$admin,['enabled'=>true,'subject'=>$vapid['subject'],'revision'=>3],$now+81,$key);
    $vapid=ppvc_vapid($pdo,$key);$rebound=$get($vapid,$origin,$now+82);
    ppd_check($rebound===$h1&&$calls()===$before,'disable and re-enable rebind the same token without violating the hourly refresh limit');
    $saved=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_push_vapid_cache_v1 WHERE origin=?',[$origin])->fetch(PDO::FETCH_ASSOC);
    $bound=json_decode(ai_decrypt_secret_with_key($saved['header_cipher'],$key),true,12,JSON_THROW_ON_ERROR);
    ppd_check((int)$saved['config_revision']===$vapid['config_revision']&&$bound['config_revision']===$vapid['config_revision']
        &&$bound['issued_epoch']===$now,'revision rebind preserves token issuance and authenticates the new revision');
    vp3_phone_push_delivery_configure_v1($pdo,$admin,['enabled'=>true,'subject'=>'mailto:new-contact@example.test','revision'=>$vapid['config_revision']],$now+90,$key);
    $vapid=ppvc_vapid($pdo,$key);
    $deferred=ppvc_reject('vapid_refresh_deferred',fn()=>$get($vapid,$origin,$now+100),'subject changes cannot force a fresh JWT inside the first hour');
    ppd_check($deferred->retry_after===3500&&$calls()===$before,'deferral is explicitly pre-network with a bounded retry delay');
    $fresh=$get($vapid,$origin,$now+3600);
    ppd_check($fresh['authorization']!==$h1['authorization']&&$fresh['issued_epoch']===$now+3600&&$calls()===$before+1,'new subject is signed only after the hourly boundary');
    $near=$get($vapid,$origin,$fresh['expires_epoch']-301);
    ppd_check($near===$fresh&&$calls()===$before+1,'healthy token remains unchanged until its five-minute renewal margin');
    $renewed=$get($vapid,$origin,$fresh['expires_epoch']-300);
    ppd_check($renewed['authorization']!==$fresh['authorization']&&$renewed['issued_epoch']===$fresh['expires_epoch']-300&&$calls()===$before+2,'twelve-hour token renews before expiry and well outside the hourly limit');
    $saved=vp3_phone_sql_v1($pdo,'SELECT * FROM vp3_phone_push_vapid_cache_v1 WHERE origin=?',[$origin])->fetch(PDO::FETCH_ASSOC);
    vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_push_vapid_cache_v1 SET header_cipher=? WHERE origin=?',['corrupt fixture ciphertext',$origin]);
    $before=$calls();
    ppvc_reject('vapid_cache_unavailable',fn()=>$get($vapid,$origin,$renewed['issued_epoch']+1),'corrupt cached credentials fail closed instead of minting a new token inside the hour');
    ppd_check($calls()===$before,'cache corruption never falls back to per-notification signing');
    vp3_phone_sql_v1($pdo,'UPDATE vp3_phone_push_vapid_cache_v1 SET header_cipher=? WHERE origin=?',[$saved['header_cipher'],$origin]);
    $badFactory=static fn(array $v,string $o,int $t):array=>['authorization'=>$fresh['authorization'],'issued_epoch'=>$t,'expires_epoch'=>$t+86401];
    ppvc_reject('vapid_signing_unavailable',fn()=>vp3_phone_push_vapid_cache_get_v1($pdo,$vapid,'https://badexpiry.push.apple.com',$now+1000,$badFactory,$key),'factory output cannot exceed the24-hour JWT lifetime');
    $badFactory=static fn(array $v,string $o,int $t):array=>['authorization'=>$fresh['authorization']."\r\nX:1",'issued_epoch'=>$t,'expires_epoch'=>$t+43200];
    ppvc_reject('vapid_signing_unavailable',fn()=>vp3_phone_push_vapid_cache_get_v1($pdo,$vapid,'https://badheader.push.apple.com',$now+1000,$badFactory,$key),'factory output cannot inject arbitrary HTTP headers');
    $fill=$now+100000;
    for($i=0;$i<128;$i++)$get($vapid,'https://bounded'.$i.'.push.apple.com',$fill);
    ppd_check((int)$pdo->query('SELECT COUNT(*) FROM vp3_phone_push_vapid_cache_v1')->fetchColumn()===128,'expired cleanup and the site cache limit keep active storage bounded');
    $before=$calls();
    ppvc_reject('vapid_cache_capacity',fn()=>$get($vapid,'https://overflow.push.apple.com',$fill),'a129th active origin is deferred without evicting reusable tokens');
    $get($vapid,'https://bounded0.push.apple.com',$fill+1);
    ppd_check($calls()===$before,'capacity leaves existing healthy authorization usable without re-signing');
    $get($vapid,'https://afterexpiry.push.apple.com',$fill+43201);
    ppd_check((int)$pdo->query('SELECT COUNT(*) FROM vp3_phone_push_vapid_cache_v1')->fetchColumn()===1,'expired cache entries are removed before accepting a new origin');
    $version=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?$pdo->query('SELECT VERSION()')->fetchColumn():$pdo->query('SELECT sqlite_version()')->fetchColumn();
    echo 'PASS VAPID cache '.$GLOBALS['ppd_checks'].' checks; driver='.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).'; version='.$version."\n";
}finally{
    unset($GLOBALS['ppd_db']);$pdo=null;
    if($server&&$schema)$server->exec('DROP DATABASE `'.$schema.'`');
    if($file!==null)@unlink($file);
    putenv('VP3_PUSH_VAPID_DSN');putenv('VP3_PUSH_VAPID_KEY');
}
