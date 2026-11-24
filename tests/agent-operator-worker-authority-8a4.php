<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/agent-operator-worker-authority-8a4.php';
if (PHP_SAPI!=='cli') throw new RuntimeException('CLI only.');
$dsn=getenv('VP3_WORKER_TEST_DSN')?:'sqlite::memory:';
if ($dsn!=='sqlite::memory:' && !str_starts_with($dsn,'mysql:host=127.0.0.1;port=3306;dbname=operator_worker_ci')) throw new RuntimeException('Dedicated test database only.');
$db=new PDO($dsn,getenv('VP3_WORKER_TEST_USER')?:null,getenv('VP3_WORKER_TEST_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
VP3OperatorWorkerAuthority8A4::install($db);VP3OperatorWorkerAuthority8A4::install($db);
$n=0;
function check8a4(bool $v,string $name): void {global $n;if (!$v) throw new RuntimeException('FAIL: '.$name);++$n;echo "ok $n - $name\n";}
function reject8a4(callable $f,string $name): void {try {$f();} catch (DomainException $e) {check8a4(true,$name);return;}throw new RuntimeException('Accepted invalid request: '.$name);}
function id8a4(string $ch): string{return str_repeat($ch,32);}
function canon8a4(array $p):string{ksort($p,SORT_STRING);return json_encode($p,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);}
function frame8a4(array $p,string $key):array{$raw=canon8a4($p);return [$raw,hash_hmac('sha256',"vp3.operator.worker.v1/request\0".$raw,$key)];}
function send8a4(VP3OperatorWorkerAuthority8A4 $a,array $p,string $key,int $now):array{[$raw,$mac]=frame8a4($p,$key);return $a->receive($raw,$mac,$now);}
function req8a4(array $c,string $boot,int $now,string $action='register',int $seq=1,int $gen=0,?array $caps=null):array{
    return ['action'=>$action,'boot_id'=>$boot,'capabilities'=>$caps??$c['capabilities'],'credential_id'=>$c['credential_id'],
        'device_id'=>$c['device_id'],'generation'=>$gen,'nonce'=>id8a4('4'),'owner_id'=>$c['owner_id'],
        'protocol'=>'vp3.operator.worker.v1','sent_at'=>$now,'sequence'=>$seq,'worker_id'=>$c['worker_id']];
}
$t=1800000000;
$c=['credential_id'=>id8a4('1'),'owner_id'=>1,'device_id'=>id8a4('2'),'worker_id'=>'fixture-worker',
    'secret'=>implode('',array_map('chr',range(0,31))),'capabilities'=>['session.recover','session.simulate'],'route'=>'homeserver'];
$d=array_replace($c,['credential_id'=>id8a4('5'),'device_id'=>id8a4('6'),'worker_id'=>'fixture-cloud','secret'=>hash('sha256','cloud-fixture',true),'route'=>'cloud']);
$e=array_replace($c,['credential_id'=>id8a4('7'),'owner_id'=>2,'device_id'=>id8a4('8'),'secret'=>hash('sha256','other-fixture',true)]);
$a=new VP3OperatorWorkerAuthority8A4($db,[$c,$d,$e]);
$p=req8a4($c,id8a4('3'),$t);
[$raw,$mac]=frame8a4($p,$c['secret']);
check8a4($mac==='9dd194caff6cf5485f5b2e7440f683d0e948f658c0a57894b7dc5f33c15d5daf','Python wire HMAC vector');
check8a4(hash('sha256',$raw)==='5146efef3ba073df025789d2cf63cd0506dd16e6fb21e28cb33cd71681e51532','Python wire digest vector');
reject8a4(fn()=>$a->receive($raw,id8a4('0').id8a4('0'),$t),'invalid MAC');
reject8a4(fn()=>$a->receive(' '.$raw,$mac,$t),'noncanonical bytes');
$dup=str_replace('"action":"register"','"action":"register","action":"register"',$raw);
reject8a4(fn()=>$a->receive($dup,hash_hmac('sha256',"vp3.operator.worker.v1/request\0".$dup,$c['secret']),$t),'duplicate JSON key');
reject8a4(fn()=>send8a4($a,array_replace($p,['owner_id'=>2]),$c['secret'],$t),'cross-owner identity');
reject8a4(fn()=>send8a4($a,array_replace($p,['capabilities'=>['call.dial']]),$c['secret'],$t),'live capability forbidden');
reject8a4(fn()=>send8a4($a,array_replace($p,['capabilities'=>['session.simulate','session.recover']]),$c['secret'],$t),'unordered capabilities');
reject8a4(fn()=>send8a4($a,array_replace($p,['sequence'=>1.5]),$c['secret'],$t),'invalid integer');
reject8a4(fn()=>send8a4($a,$p,$c['secret'],$t+31),'stale request');
reject8a4(fn()=>send8a4($a,$p,$c['secret'],$t-6),'future request');
check8a4($a->select(1,$t)===null,'no unregistered worker');
$r=$a->receive($raw,$mac,$t);$v=json_decode($r['body'],true,8,JSON_THROW_ON_ERROR);
check8a4($v['generation']===1 && $v['lease_until']===$t+90 && $v['execution_enabled']===false && $v['live_certified']===false,'registration cannot execute');
check8a4(hash_equals($r['signature'],hash_hmac('sha256',"vp3.operator.worker.v1/response\0".$r['body'],$c['secret'])),'directional response HMAC');
check8a4($a->receive($raw,$mac,$t+3)===$r,'exact lost response replay');
reject8a4(fn()=>send8a4($a,array_replace($p,['nonce'=>id8a4('9')]),$c['secret'],$t+3),'changed duplicate sequence');
reject8a4(fn()=>send8a4($a,req8a4($c,id8a4('9'),$t+4),$c['secret'],$t+4),'active boot cannot be displaced');
reject8a4(fn()=>send8a4($a,req8a4($c,id8a4('3'),$t+4,'heartbeat',3,1),$c['secret'],$t+4),'sequence gap');
reject8a4(fn()=>send8a4($a,req8a4($c,id8a4('3'),$t+4,'heartbeat',2,2),$c['secret'],$t+4),'generation mismatch');
$h=req8a4($c,id8a4('3'),$t+5,'heartbeat',2,1);
check8a4(json_decode(send8a4($a,$h,$c['secret'],$t+5)['body'],true)['lease_until']===$t+95,'heartbeat lease renewed');
reject8a4(fn()=>send8a4($a,$h,$c['secret'],$t+4),'authority clock rollback');
send8a4($a,req8a4($d,id8a4('a'),$t+5),$d['secret'],$t+5);
send8a4($a,req8a4($e,id8a4('b'),$t+5),$e['secret'],$t+5);
check8a4($a->select(1,$t+5)['route']==='homeserver','HomeServer preferred');
check8a4($a->select(2,$t+5)['worker_id']==='fixture-worker' && $a->select(3,$t+5)===null,'owner isolation');
$one=$a->claimSimulation(1,id8a4('a'),$t+5);
check8a4($one['route']==='homeserver' && $one['execution_enabled']===false,'exclusive simulated claim');
check8a4($a->claimSimulation(1,id8a4('a'),$t+6)===['state'=>'held','duplicate'=>true,'execution_enabled'=>false],'duplicate occurrence no new claim');
$two=$a->claimSimulation(1,id8a4('b'),$t+6);
check8a4($two['route']==='cloud','busy HomeServer falls back to cloud');
check8a4($a->claimSimulation(1,id8a4('c'),$t+6)===null,'no worker can be double booked');
check8a4(!$a->finishSimulation(2,id8a4('a'),$one['claim_id'],$t+6),'cross-owner finish blocked');
check8a4(!$a->finishSimulation(1,id8a4('a'),id8a4('0'),$t+6),'wrong claim blocked');
check8a4($a->finishSimulation(1,id8a4('a'),$one['claim_id'],$t+6),'exact completion');
check8a4($a->claimSimulation(1,id8a4('a'),$t+6)['state']==='finished','completed occurrence never retried');
$three=$a->claimSimulation(1,id8a4('c'),$t+6);
check8a4($three['route']==='homeserver','finished worker released');
reject8a4(fn()=>$a->revoke(2,$c['credential_id'],$t+7),'cross-owner revoke blocked');
$a->revoke(1,$c['credential_id'],$t+7);
check8a4($a->claimSimulation(1,id8a4('c'),$t+7)['state']==='uncertain','revocation fences claim');
check8a4(!$a->finishSimulation(1,id8a4('c'),$three['claim_id'],$t+7),'late finish rejected');
reject8a4(fn()=>send8a4($a,req8a4($c,id8a4('3'),$t+7,'heartbeat',3,1),$c['secret'],$t+7),'revoked registration rejected');
$a=new VP3OperatorWorkerAuthority8A4($db,[$c,$d,$e]);
reject8a4(fn()=>send8a4($a,req8a4($c,id8a4('f'),$t+7),$c['secret'],$t+7),'revocation persists across authority instances');
check8a4($a->claimSimulation(1,id8a4('b'),$t+36)['state']==='uncertain','expired claim never retried');
check8a4($a->select(1,$t+36)['route']==='cloud','stale claim releases worker for a different synthetic occurrence');
$rot=array_replace($c,['credential_id'=>id8a4('9'),'secret'=>hash('sha256','rotated-fixture',true)]);
$a=new VP3OperatorWorkerAuthority8A4($db,[$rot,$d,$e]);
send8a4($a,req8a4($rot,id8a4('c'),$t+37),$rot['secret'],$t+37);
check8a4($a->select(1,$t+37)['route']==='homeserver','explicitly revoked credential can rotate');
$wrong=array_replace($d,['secret'=>hash('sha256','unapproved-swap',true)]);
$b=new VP3OperatorWorkerAuthority8A4($db,[$rot,$wrong,$e]);
reject8a4(fn()=>send8a4($b,req8a4($wrong,id8a4('a'),$t+38,'heartbeat',2,1),$wrong['secret'],$t+38),'in-place secret swap rejected');
$alias=array_replace($e,['credential_id'=>id8a4('0'),'secret'=>hash('sha256','alias-fixture',true)]);
$b=new VP3OperatorWorkerAuthority8A4($db,[$alias,$d]);
reject8a4(fn()=>send8a4($b,req8a4($alias,id8a4('d'),$t+38),$alias['secret'],$t+38),'unrevoked identity cannot rotate');
$ret=req8a4($rot,id8a4('c'),$t+39,'retire',2,1,[]);
check8a4(json_decode(send8a4($a,$ret,$rot['secret'],$t+39)['body'],true)['lease_until']===0,'retirement clears lease');
check8a4($a->select(1,$t+39)['route']==='cloud','retired worker excluded');
reject8a4(fn()=>send8a4($a,req8a4($rot,id8a4('c'),$t+40),$rot['secret'],$t+40),'old boot cannot return');
$new=send8a4($a,req8a4($rot,id8a4('e'),$t+40),$rot['secret'],$t+40);
check8a4(json_decode($new['body'],true)['generation']===2,'new boot higher generation');
check8a4($a->select(1,$t+131)===null,'expired registration excluded');
echo "PASS: $n non-billable worker authority checks\n";
