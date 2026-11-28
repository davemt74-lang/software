<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/profile-phone-v1.php';
$pdo=new PDO(getenv('VP3_PROFILE_PHONE_TEST_DSN')?:'sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$GLOBALS['voice']=true;$GLOBALS['permission']=true;
if(!$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn())$pdo->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,is_active INTEGER);INSERT INTO users VALUES(1,1),(2,1);
 CREATE TABLE user_agents(id INTEGER PRIMARY KEY,owner_user_id INTEGER,is_active INTEGER,display_name TEXT);INSERT INTO user_agents VALUES(7,1,1,'Owner Agent'),(8,2,1,'Other Agent');
 CREATE TABLE user_profiles(user_id INTEGER,is_active INTEGER,is_public INTEGER,profile_agent_enabled INTEGER,profile_agent_id INTEGER);
 INSERT INTO user_profiles VALUES(1,1,1,1,7);
 CREATE TABLE phone_cloud_numbers_v100(id INTEGER PRIMARY KEY,agent_id INTEGER,owner_user_id INTEGER,provider TEXT,phone_e164 TEXT,voice_enabled INTEGER);
 INSERT INTO phone_cloud_numbers_v100 VALUES(11,7,1,'twilio','+16025550101',1),(12,8,2,'twilio','+16025550202',1),(13,7,1,'twilio','+16025550303',0),(14,7,1,'twilio','javascript:bad',1);");
function vp3_phone_schema_ready_v100(PDO $pdo):bool{return true;}
function vp3_phone_package_v100(PDO $pdo,int $owner):array{return ['included'=>true,'voice'=>$GLOBALS['voice']];}
function vp3_phone_workspace_lock_v1(PDO $pdo):string{return '';}
function vp3_phone_workspace_atomic_v1(PDO $pdo,callable $fn):mixed{$pdo->beginTransaction();try{$r=$fn();$pdo->commit();return $r;}catch(Throwable $e){$pdo->rollBack();throw $e;}}
function profile_user_row(PDO $pdo,int $owner):?array{$q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$owner]);return $q->fetch()?:null;}
function profile_active_agent(PDO $pdo,array $profile):?array{$q=$pdo->prepare('SELECT * FROM user_agents WHERE id=? AND owner_user_id=? AND is_active=1');$q->execute([$profile['profile_agent_id'],$profile['user_id']]);return $q->fetch()?:null;}
function vp3_observation_personal_permission_v1(PDO $pdo,array $user,string $key):bool{return $GLOBALS['permission'];}
if(defined('VP3_PROFILE_PHONE_TEST_HELPERS_ONLY'))return;
$checks=0;
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;echo 'PASS '.$message.PHP_EOL;}
function deny(callable $fn,int $code,string $message):void{try{$fn();}catch(Throwable $e){check($e->getCode()===$code,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
$profile=$pdo->query('SELECT * FROM user_profiles')->fetch();$owner=['id'=>1];
check(vp3_profile_phone_public_v1($pdo,$profile)['enabled']===false,'uninstalled calling does not break public chat');
check(!$pdo->query("SELECT name FROM sqlite_master WHERE name='profile_phone_settings_v1'")->fetchColumn(),'public reads never create schema');
vp3_profile_phone_install_v1($pdo);vp3_profile_phone_install_v1($pdo);
check(vp3_profile_phone_ready_v1($pdo),'repeatable explicit upgrade installs calling');
check(vp3_profile_phone_owner_v1($pdo,$owner)['enabled']===false,'calling is off until owner publishes a line');
check(array_column(vp3_profile_phone_numbers_v1($pdo,1),'id')===[11],'choices contain only current owned voice lines with valid numbers');
deny(fn()=>vp3_profile_phone_save_v1($pdo,$owner,['enabled'=>true,'number_id'=>12,'revision'=>0]),409,'foreign line cannot be published');
deny(fn()=>vp3_profile_phone_save_v1($pdo,$owner,['enabled'=>true,'number_id'=>13,'revision'=>0]),409,'voice-disabled line cannot be published');
deny(fn()=>vp3_profile_phone_save_v1($pdo,$owner,['enabled'=>true,'number_id'=>11,'revision'=>0,'phone_e164'=>'+16025550999']),422,'client destination cannot replace assigned Twilio number');
deny(fn()=>vp3_profile_phone_save_v1($pdo,$owner,['enabled'=>'true','number_id'=>11,'revision'=>0]),422,'settings require explicit boolean enable');
$saved=vp3_profile_phone_save_v1($pdo,$owner,['enabled'=>true,'number_id'=>11,'revision'=>0]);
check($saved['enabled']&&$saved['active']&&$saved['revision']===1,'owner can approve one current assigned line');
$public=vp3_profile_phone_public_v1($pdo,$profile);
check($public===['contract'=>VP3_PROFILE_PHONE_V1,'enabled'=>true,'number'=>'+16025550101','href'=>'tel:+16025550101'],'public response contains only the approved number and native dial URL');
check(count($pdo->query('SELECT * FROM profile_phone_settings_v1')->fetchAll())===1,'public reads create no calls, conversations or duplicate settings');
deny(fn()=>vp3_profile_phone_save_v1($pdo,$owner,['enabled'=>false,'number_id'=>0,'revision'=>0]),409,'stale tab cannot overwrite a newer approval');
foreach(['is_active','is_public','profile_agent_enabled'] as $field){$changed=$profile;$changed[$field]=0;check(!vp3_profile_phone_public_v1($pdo,$changed)['enabled'],$field.' revocation immediately removes the public line');}
$GLOBALS['permission']=false;check(!vp3_profile_phone_public_v1($pdo,$profile)['enabled'],'revoked Profile access hides the line');$GLOBALS['permission']=true;
$GLOBALS['voice']=false;check(!vp3_profile_phone_public_v1($pdo,$profile)['enabled'],'lost voice allowance hides the line');$GLOBALS['voice']=true;
$pdo->exec('UPDATE user_agents SET is_active=0 WHERE id=7');check(!vp3_profile_phone_public_v1($pdo,$profile)['enabled'],'inactive Agent hides the line');$pdo->exec('UPDATE user_agents SET is_active=1 WHERE id=7');
$pdo->exec('UPDATE phone_cloud_numbers_v100 SET owner_user_id=2 WHERE id=11');check(!vp3_profile_phone_public_v1($pdo,$profile)['enabled'],'reassignment removes the prior owner public number');$pdo->exec('UPDATE phone_cloud_numbers_v100 SET owner_user_id=1 WHERE id=11');
$pdo->exec("UPDATE phone_cloud_numbers_v100 SET phone_e164='+16025550404' WHERE id=11");
check(!vp3_profile_phone_public_v1($pdo,$profile)['enabled'],'number replacement requires explicit new owner approval');
check(!vp3_profile_phone_owner_v1($pdo,$owner)['active'],'owner sees a changed-assignment warning');
$saved=vp3_profile_phone_save_v1($pdo,$owner,['enabled'=>true,'number_id'=>11,'revision'=>1]);
check(vp3_profile_phone_public_v1($pdo,$profile)['number']==='+16025550404','explicit reapproval publishes the changed line');
$GLOBALS['voice']=false;$saved=vp3_profile_phone_save_v1($pdo,$owner,['enabled'=>false,'number_id'=>0,'revision'=>2]);
check(!$saved['enabled']&&!vp3_profile_phone_public_v1($pdo,$profile)['enabled'],'owner can disable calling even after losing voice eligibility');
echo 'PROFILE_PHONE_V1=PASS '.$checks.' checks'.PHP_EOL;
