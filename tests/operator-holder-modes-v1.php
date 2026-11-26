<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-operator-admission-v1.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE crm_contacts(id INTEGER,owner_user_id INTEGER,connected_phone_id TEXT,status TEXT)');
$pdo->exec('CREATE TABLE vp3_phone_devices_v1(id TEXT,owner_user_id INTEGER,device_status TEXT,expires_at INTEGER)');
$pdo->exec('CREATE TABLE vp3_phone_communication_profiles_v1(device_id TEXT,owner_user_id INTEGER,phone_revision INTEGER,phone_e164 TEXT,owner_calls INTEGER,holder_calls INTEGER,agent_call_consent INTEGER,holder_proactive INTEGER)');
$now=100;
$phone='+12025550100';
$pdo->exec("INSERT INTO crm_contacts VALUES(3,1,'device','active')");
$pdo->exec("INSERT INTO vp3_phone_devices_v1 VALUES('device',1,'approved',110)");
$pdo->prepare("INSERT INTO vp3_phone_communication_profiles_v1 VALUES('device',1,1,?,1,1,3,1)")->execute([$phone]);
$assert=function(array $modes,bool $scheduled,bool $spontaneous):void{
    if($modes!==['scheduled'=>$scheduled,'spontaneous'=>$spontaneous])throw new RuntimeException('Holder mode projection mismatch');
};
$read=fn()=>vp3_operator_holder_modes_v1($pdo,1,3,$phone,$now);
$assert($read(),true,true);
$assert(vp3_operator_holder_modes_v1($pdo,2,3,$phone,$now),false,false);
$assert(vp3_operator_holder_modes_v1($pdo,1,3,$phone,110),false,false);
$assert(vp3_operator_holder_modes_v1($pdo,1,3,'+12025550101',$now),false,false);
$pdo->exec('UPDATE vp3_phone_communication_profiles_v1 SET holder_proactive=0');
$assert($read(),true,false);
$pdo->exec("UPDATE crm_contacts SET connected_phone_id='replacement'");
$assert($read(),false,false);
echo "PASS exact contact, account, number, expiration, mode and replacement checks\n";
