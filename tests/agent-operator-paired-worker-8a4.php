<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/agent-operator-worker-http-8a4.php';
$dsn=getenv('VP3_WORKER_TEST_DSN')?:'sqlite::memory:';
if(!in_array($dsn,['sqlite::memory:','mysql:host=127.0.0.1;port=3306;dbname=operator_worker_ci'],true))throw new RuntimeException('Dedicated test database required.');
$db=new PDO($dsn,getenv('VP3_WORKER_TEST_USER')?:null,getenv('VP3_WORKER_TEST_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
$suffix=$mysql?' ENGINE=InnoDB':'';
$db->exec('CREATE TABLE users(id BIGINT PRIMARY KEY,is_active INTEGER NOT NULL)'.$suffix);
$db->exec('CREATE TABLE homeserver_https_sessions(user_id BIGINT PRIMARY KEY,device_id VARCHAR(100) NOT NULL UNIQUE,session_token_hash CHAR(64) NOT NULL,status VARCHAR(20) NOT NULL)'.$suffix);
$db->exec('CREATE TABLE homeserver_connections(user_id BIGINT PRIMARY KEY,device_id VARCHAR(100) NOT NULL,status VARCHAR(20) NOT NULL)'.$suffix);
VP3OperatorPairedWorker8A4::install($db);VP3OperatorPairedWorker8A4::install($db);
$n=0;
function ok8c(bool $v,string $name):void{global $n;if(!$v)throw new RuntimeException('FAIL '.$name);echo 'ok '.(++$n).' - '.$name."\n";}
function no8c(callable $f,string $name):void{try{$f();}catch(DomainException $e){ok8c(true,$name);return;}throw new RuntimeException('Unexpected authorization: '.$name);}
function setup8c(PDO $db,int $owner,string $device,string $bearer):void{
    $db->prepare('INSERT INTO users VALUES(?,1)')->execute([$owner]);
    $db->prepare("INSERT INTO homeserver_https_sessions VALUES(?,?,?,'active')")->execute([$owner,$device,hash('sha256',$bearer)]);
    $db->prepare("INSERT INTO homeserver_connections VALUES(?,?,'paired')")->execute([$owner,$device]);
}
function wire8c(array $c,int $t,string $action='register',int $seq=1,int $gen=0,string $boot='a'):array{
    $p=['protocol'=>'vp3.operator.worker.v1','action'=>$action,'credential_id'=>$c['credential_id'],'owner_id'=>$c['owner_id'],
        'device_id'=>$c['device_id'],'worker_id'=>$c['worker_id'],'boot_id'=>str_repeat($boot,32),'sequence'=>$seq,
        'generation'=>$gen,'nonce'=>str_repeat('f',32),'sent_at'=>$t,'capabilities'=>$action==='retire'?[]:$c['capabilities']];
    ksort($p,SORT_STRING);$raw=json_encode($p,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    return [$raw,hash_hmac('sha256',"vp3.operator.worker.v1/request\0".$raw,hex2bin($c['secret_hex']))];
}
$now=1800000000;$d='hs-'.str_repeat('a',24);$d2='hs-'.str_repeat('b',24);$token=str_repeat('A',64);$token2=str_repeat('B',64);
setup8c($db,501,$d,$token);setup8c($db,502,$d2,$token2);
$key=hash('sha256','only-a-test-master',true);$s=new VP3OperatorPairedWorker8A4($db,$key);
no8c(fn()=>$s->fetchCredential($d,$token,$now),'pairing alone does not grant credential');
no8c(fn()=>$s->grant(501,$d2,$now),'wrong paired device');
no8c(fn()=>$s->grant(503,$d,$now),'missing owner');
$g=$s->grant(501,$d,$now);
ok8c($g['created'] && !isset($g['secret_hex']) && !$g['execution_enabled'],'explicit grant returns no secret');
ok8c($s->grant(501,$d,$now)['credential_id']===$g['credential_id'],'grant idempotent');
$c=$s->fetchCredential($d,$token,$now);
ok8c($c['owner_id']===501 && $c['credential_id']===$g['credential_id'] && !$c['execution_enabled'] && !$c['live_certified'],'current paired device credential');
ok8c($s->fetchCredential($d,$token,$now)===$c,'lost delivery retrieves same secret');
no8c(fn()=>$s->fetchCredential($d2,$token,$now),'different device cannot retrieve');
no8c(fn()=>$s->fetchCredential($d,$token2,$now),'different account token cannot retrieve');
no8c(fn()=>$s->fetchCredential($d,'invalid',$now),'malformed bearer');
$encrypted=$db->query('SELECT encrypted_secret FROM operator_paired_workers_8a4 WHERE owner_id=501')->fetchColumn();
ok8c(is_string($encrypted)&&$encrypted!==$c['secret_hex']&&$encrypted!==base64_encode(hex2bin($c['secret_hex'])),'secret encrypted at rest');
$wrong=new VP3OperatorPairedWorker8A4($db,hash('sha256','other-test-master',true));
no8c(fn()=>$wrong->fetchCredential($d,$token,$now),'wrong encryption key fails closed');
[$body,$mac]=wire8c($c,$now);
$server=['HTTPS'=>'on','REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json','CONTENT_LENGTH'=>(string)strlen($body),'HTTP_X_VP3_OPERATOR_SIGNATURE'=>$mac];
$dispatch=fn(array $h,string $b,int $t,bool $cred=false)=>VP3OperatorWorkerHTTP8A4::dispatch($h,$b,$s,$t,$cred);
ok8c($dispatch(array_replace($server,['HTTPS'=>'off','HTTP_X_FORWARDED_PROTO'=>'https']),$body,$now)['status']===403,'reject untrusted forwarded TLS');
ok8c($dispatch(array_replace($server,['REQUEST_METHOD'=>'GET']),$body,$now)['status']===405,'method restriction');
ok8c($dispatch(array_replace($server,['CONTENT_TYPE'=>'text/plain']),$body,$now)['status']===415,'content type restriction');
ok8c($dispatch(array_replace($server,['CONTENT_LENGTH'=>'4097']),$body,$now)['status']===413,'declared size cap');
ok8c($dispatch($server,str_repeat('x',4097),$now)['status']===413,'actual size cap');
ok8c($dispatch(array_replace($server,['CONTENT_LENGTH'=>'3']),$body,$now)['status']===400,'exact length');
ok8c($dispatch(array_replace($server,['HTTP_X_VP3_OPERATOR_SIGNATURE'=>str_repeat('0',64)]),$body,$now)['status']===401,'bad signature');
$r=$dispatch($server,$body,$now);
ok8c($r['status']===200 && hash_equals($r['signature'],hash_hmac('sha256',"vp3.operator.worker.v1/response\0".$r['body'],hex2bin($c['secret_hex']))),'signed Cloud registration receipt');
$v=json_decode($r['body'],true,8,JSON_THROW_ON_ERROR);
ok8c($v['execution_enabled']===false && $v['live_certified']===false && $v['generation']===1,'no execution or live certification');
ok8c($dispatch($server,$body,$now+2)===$r,'exact authenticated response replay');
$credServer=['HTTPS'=>'on','REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json','CONTENT_LENGTH'=>'2','HTTP_AUTHORIZATION'=>'Bearer '.$token,'HTTP_X_HOMESERVER_DEVICE'=>$d];
ok8c(json_decode($dispatch($credServer,'{}',$now,true)['body'],true)===$c,'machine credential route');
ok8c($dispatch(array_replace($credServer,['HTTP_AUTHORIZATION'=>'Bearer '.$token2]),'{}',$now,true)['status']===401,'machine route rejects wrong token');
ok8c($dispatch($credServer,'[]',$now,true)['status']===401,'machine route no caller-selected owner');
$db->exec("UPDATE homeserver_https_sessions SET status='revoked' WHERE user_id=501");
ok8c($dispatch($server,$body,$now+3)['status']===401,'revocation blocks cached receipt immediately');
no8c(fn()=>$s->fetchCredential($d,$token,$now+3),'revoked pairing blocks retrieval');
$db->exec("UPDATE homeserver_https_sessions SET status='active' WHERE user_id=501");
$db->exec("UPDATE users SET is_active=0 WHERE id=501");
ok8c($dispatch($server,$body,$now+4)['status']===401,'disabled owner blocks registration');
$db->exec("UPDATE users SET is_active=1 WHERE id=501");
$db->exec("UPDATE homeserver_connections SET status='disconnected' WHERE user_id=501");
ok8c($dispatch($server,$body,$now+4)['status']===401,'disconnected account blocks registration');
$db->exec("UPDATE homeserver_connections SET status='paired' WHERE user_id=501");
$newToken=str_repeat('C',64);
$db->prepare('UPDATE homeserver_https_sessions SET session_token_hash=? WHERE user_id=501')->execute([hash('sha256',$newToken)]);
ok8c($dispatch($server,$body,$now+4)['status']===401,'re-pair cannot inherit old worker');
no8c(fn()=>$s->grant(501,$d,$now+4),'changed pairing needs explicit revocation');
no8c(fn()=>$s->fetchCredential($d,$newToken,$now+4),'new session cannot fetch old secret');
ok8c($s->revoke(501,$now+5),'explicit durable revocation');
ok8c($db->query('SELECT encrypted_secret FROM operator_paired_workers_8a4 WHERE owner_id=501')->fetchColumn()==='','revocation erases encrypted secret');
ok8c(!$s->revoke(501,$now+5),'revoke idempotent');
$new=$s->grant(501,$d,$now+6);$c2=$s->fetchCredential($d,$newToken,$now+6);
ok8c($new['created'] && $c2['credential_id']!==$c['credential_id'] && $c2['secret_hex']!==$c['secret_hex'],'rotation uses new independent credential');
[$body2,$mac2]=wire8c($c2,$now+6,'register',1,0,'b');
$r2=$s->receive($body2,$mac2,$now+6);
ok8c(json_decode($r2['body'],true)['generation']===1,'new credential registers after explicit revocation');
no8c(fn()=>$s->receive($body,$mac,$now+6),'old key cannot register after rotation');
[$hb,$hm]=wire8c($c2,$now+7,'heartbeat',2,1,'b');
ok8c(json_decode($s->receive($hb,$hm,$now+7)['body'],true)['lease_until']===$now+97,'authenticated heartbeat');
$other=$s->grant(502,$d2,$now+7);$c3=$s->fetchCredential($d2,$token2,$now+7);
ok8c($c3['owner_id']===502 && $c3['secret_hex']!==$c2['secret_hex'],'independent per-owner secret');
$g3=$db->query('SELECT encrypted_secret FROM operator_paired_workers_8a4 WHERE owner_id=502')->fetchColumn();
$db->prepare('UPDATE operator_paired_workers_8a4 SET encrypted_secret=? WHERE owner_id=501')->execute([$g3]);
no8c(fn()=>$s->fetchCredential($d,$newToken,$now+7),'ciphertext cannot be swapped between owner bindings');
ok8c($s->revoke(501,$now+8),'revocation works even when credential ciphertext cannot decrypt');
echo "PASS: $n paired worker HTTP and credential checks\n";
