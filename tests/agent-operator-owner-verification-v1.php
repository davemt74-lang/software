<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once __DIR__.'/../includes/agent-operator-owner-phone-v1.php';
$dsn=getenv('VP3_OWNER_PHONE_TEST_DSN')?:'sqlite::memory:';
if(!in_array($dsn,['sqlite::memory:','mysql:host=127.0.0.1;port=3306;dbname=operator_owner_ci'],true))throw new RuntimeException('Dedicated test database only.');
$db=new PDO($dsn,getenv('VP3_OWNER_PHONE_TEST_USER')?:null,getenv('VP3_OWNER_PHONE_TEST_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$mysql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
if(!$mysql)$db->exec('PRAGMA foreign_keys=ON');
$db->exec('CREATE TABLE IF NOT EXISTS users(id '.($mysql?'INT UNSIGNED':'INTEGER').' PRIMARY KEY,is_active INTEGER NOT NULL,password_hash VARCHAR(255) NOT NULL)'.($mysql?' ENGINE=InnoDB':''));
$db->exec("INSERT INTO users VALUES(101,1,'a'),(102,1,'b'),(103,1,'c'),(104,1,'d'),(105,1,'e')");
VP3OperatorOwnerPhoneV1::install($db);VP3OperatorOwnerPhoneV1::install($db);
function has_permission(string $p,array $user):bool{return $p==='account.access';}
function vp3_participant_scope_v1():mixed{return null;}
function loginv(int $id,string $hash):array{$_SESSION=['user_id'=>$id,'auth_password_fingerprint'=>hash('sha256',$id.':'.$hash)];return ['id'=>$id];}
$n=0;
function okv(bool $b,string $label):void{global $n;if(!$b)throw new RuntimeException('FAIL '.$label);echo 'ok '.(++$n).' - '.$label."\n";}
function nov(callable $f,string $label):void{try{$f();}catch(DomainException){okv(true,$label);return;}throw new RuntimeException('Unexpected authorization: '.$label);}
$now=1800000000;$clock=static function()use(&$now):int{return $now;};
$settings=['enabled'=>true,'account_sid'=>'AC'.str_repeat('a',32),'service_sid'=>'VA'.str_repeat('b',32),
    'api_key_sid'=>'SK'.str_repeat('c',32),'api_key_secret'=>'synthetic-fixture-key-only','rate_key_hex'=>hash('sha256','fixture-rate-key')];
$number='+16025550100';$sid='VE'.str_repeat('d',32);$mode='pending';$calls=[];$currentPhone=$number;
$transport=static function(string $url,array $fields)use(&$calls,&$mode,&$now,&$currentPhone,$settings,$sid,$db):array{
    $calls[]=str_ends_with($url,'/Verifications')?'start':'check';
    if($mode==='lost')throw new RuntimeException('fixture response lost');
    $isStart=str_ends_with($url,'/Verifications');
    if($isStart){
        if($fields!==['To'=>$currentPhone,'Channel'=>'sms'])throw new RuntimeException('Unexpected start fields.');
    }elseif($url!=='https://verify.twilio.com/v2/Services/'.$settings['service_sid'].'/VerificationCheck'
        ||array_keys($fields)!==['VerificationSid','Code']||$fields['VerificationSid']!==$sid)throw new RuntimeException('Unexpected check fields.');
    $r=['account_sid'=>$settings['account_sid'],'service_sid'=>$settings['service_sid'],'to'=>$currentPhone,'channel'=>'sms',
        'sid'=>$sid,'status'=>$mode==='approved'?'approved':'pending','date_created'=>gmdate('Y-m-d\TH:i:s\Z',$now)];
    if($mode==='wrong-sid'){$r['sid']='VE'.str_repeat('e',32);$r['status']='approved';}
    if($mode==='wrong-account')$r['account_sid']='AC'.str_repeat('0',32);
    if($mode==='old')$r['date_created']=gmdate('Y-m-d\TH:i:s\Z',$now-120);
    if($mode==='wrong-phone'){$r['to']='+16025550199';$r['status']='approved';}
    if($mode==='late'){$now+=601;$r['status']='approved';}
    if($mode==='change-number'){
        VP3OperatorOwnerPhoneV1::saveNumber($db,['id'=>103],['phone_e164'=>'+16025550177','expected_revision'=>'1'],$now);
        $r['status']='approved';
    }
    return $r;
};
$provider=new VP3OwnerPhoneVerifyProviderV1($settings,$transport);
$v=new VP3OwnerPhoneVerificationV1($db,$provider,$clock);
okv(VP3OwnerPhoneVerificationV1::ready($db),'idempotent verification schema');
nov(fn()=>new VP3OwnerPhoneVerifyProviderV1(array_replace($settings,['enabled'=>false]),$transport),'disabled provider is rejected');
$u=loginv(101,'a');
nov(fn()=>$v->start($u,'1'),'no unsaved number may receive SMS');
VP3OperatorOwnerPhoneV1::saveNumber($db,$u,['phone_e164'=>$number,'expected_revision'=>'0'],$now);
okv($v->status($u,'1')['state']==='unverified','saved number is unverified');
nov(fn()=>$v->start($u,'2'),'stale phone revision denied');
nov(fn()=>$v->check($u,'1','123456'),'no code check before send');
okv(count($calls)===0,'no provider effects without approved start');
$r=$v->start($u,'1');
okv($r['state']==='pending'&&$r['can_check']&&!$r['verified']&&!$r['execution_enabled'],'exact pending provider receipt');
nov(fn()=>$v->start($u,'1'),'no resend within challenge lifetime');
nov(fn()=>$v->check($u,'1','abc'),'invalid code rejected before provider');
okv($v->check($u,'1','000001')['state']==='pending','wrong code can be retried while bounded');
$mode='approved';$r=$v->check($u,'1','123456');
okv($r['verified']&&$r['verified_epoch']===$now&&!$r['execution_enabled'],'exact approved receipt verifies possession only');
okv(count($calls)===3,'one start and two checks only');
nov(fn()=>$v->check($u,'1','123456'),'approved code cannot be checked again');
nov(fn()=>$v->start($u,'1'),'verified number cannot trigger another SMS');
okv(!VP3OperatorOwnerPhoneV1::read($db,$u)['execution_enabled'],'base preferences remain non-executable');
$u2=loginv(102,'b');
VP3OperatorOwnerPhoneV1::saveNumber($db,$u2,['phone_e164'=>$number,'expected_revision'=>'0'],$now);
nov(fn()=>$v->start($u2,'1'),'destination cooldown crosses accounts');
nov(fn()=>$v->status(['id'=>101],'1'),'cannot read another owner verification');
$u=loginv(101,'a');VP3OperatorOwnerPhoneV1::saveNumber($db,$u,['phone_e164'=>'+16025550101','expected_revision'=>'1'],$now);
okv(!$v->status($u,'2')['verified'],'number replacement invalidates possession');
nov(fn()=>$v->start($u,'2'),'number replacement cannot bypass account send cooldown');
$now+=660;$currentPhone='+16025550101';$mode='pending';
okv($v->start($u,'2')['state']==='pending','explicit new verification after cooldown');
$mode='wrong-sid';
okv($v->check($u,'2','123456')['state']==='unresolved','mismatched approved SID cannot verify');
nov(fn()=>$v->check($u,'2','123456'),'uncertain check cannot be repeated');
$now+=660;$mode='pending';
$v->start($u,'2');
for($i=0;$i<4;$i++)okv($v->check($u,'2','111111')['state']==='pending','bounded pending code attempt');
okv($v->check($u,'2','111111')['state']==='exhausted','fifth wrong code exhausts local attempts');
nov(fn()=>$v->check($u,'2','111111'),'sixth attempt denied');
$now+=660;
nov(fn()=>$v->start($u,'2'),'daily owner budget survives number changes');
$u3=loginv(103,'c');$currentPhone='+16025550103';
VP3OperatorOwnerPhoneV1::saveNumber($db,$u3,['phone_e164'=>$currentPhone,'expected_revision'=>'0'],$now);
$mode='lost';okv($v->start($u3,'1')['state']==='unresolved','lost SMS receipt never assumed pending');
nov(fn()=>$v->start($u3,'1'),'lost send not automatically replayed');
$now+=660;$mode='pending';$v->start($u3,'1');
$mode='change-number';
nov(fn()=>$v->check($u3,'1','123456'),'number changed during provider approval cannot verify replacement');
okv($v->status($u3,'2')['state']==='unverified','replacement remains unverified after old approval');
$u4=loginv(104,'d');$currentPhone='+16025550104';
VP3OperatorOwnerPhoneV1::saveNumber($db,$u4,['phone_e164'=>$currentPhone,'expected_revision'=>'0'],$now);
$mode='old';okv($v->start($u4,'1')['state']==='unresolved','old provider challenge cannot be adopted');
$now+=660;$mode='pending';$v->start($u4,'1');$mode='wrong-phone';
okv($v->check($u4,'1','123456')['state']==='unresolved','approval for another number rejected');
$u5=loginv(105,'e');$currentPhone='+16025550105';
VP3OperatorOwnerPhoneV1::saveNumber($db,$u5,['phone_e164'=>$currentPhone,'expected_revision'=>'0'],$now);
$mode='wrong-account';okv($v->start($u5,'1')['state']==='unresolved','wrong provider account rejected');
$now+=660;$mode='pending';$v->start($u5,'1');$mode='late';
okv(!$v->check($u5,'1','123456')['verified'],'late approved response cannot verify expired challenge');
$rows=$db->query('SELECT * FROM phone_operator_owner_verify_sends_v1')->fetchAll(PDO::FETCH_ASSOC);
okv(count($rows)===9,'durable send budget includes ambiguous effects');
$serialized=json_encode($rows,JSON_THROW_ON_ERROR);
okv(!str_contains($serialized,'+1602555')&&!str_contains($serialized,'123456'),'rate evidence stores no raw phone or OTP');
$receipt=$db->query('SELECT * FROM phone_operator_owner_verifications_v1 WHERE owner_user_id=105')->fetch(PDO::FETCH_ASSOC);
okv(!array_key_exists('code',$receipt)&&!array_key_exists('phone_e164',$receipt),'challenge stores no code or raw phone');
echo "PASS: $n owner phone verification checks\n";
