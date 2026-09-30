<?php
declare(strict_types=1);
$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');$user=(string)getenv('VP3_TEST_MYSQL_USER');$pass=(string)getenv('VP3_TEST_MYSQL_PASS');
if($dsn==='')throw new RuntimeException('VP3_TEST_MYSQL_DSN is required.');
$testPdo=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): ?PDO {global $testPdo;return $testPdo;}
function table_exists(string $table): bool {global $testPdo;$s=$testPdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$s->execute([$table]);return (int)$s->fetchColumn()>0;}
function subscription_has_entitlement(?array $user,string $key): bool {return $key==='apps.premium.demo';}

$testPdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach(['vp3_system_app_events','vp3_system_app_ownership','vp3_system_app_catalog','users'] as $table)$testPdo->exec('DROP TABLE IF EXISTS `'.$table.'`');
$testPdo->exec('SET FOREIGN_KEY_CHECKS=1');
$testPdo->exec("CREATE TABLE users (id INT UNSIGNED NOT NULL PRIMARY KEY,email VARCHAR(190) NOT NULL,display_name VARCHAR(160) NULL,role VARCHAR(40) NOT NULL DEFAULT 'user') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("INSERT INTO users(id,email,display_name,role) VALUES (1,'owner@example.com','Owner','user'),(2,'other@example.com','Other','user')");
require dirname(__DIR__).'/includes/system-apps-v100.php';

vp3_system_apps_ensure_schema_v100($testPdo);
if(!vp3_system_apps_schema_ready_v100($testPdo))throw new RuntimeException('System Apps schema not ready.');
$userRow=['id'=>1,'role'=>'user','email'=>'owner@example.com','display_name'=>'Owner'];
$catalog=vp3_system_apps_catalog_v100($userRow,$testPdo);
if($catalog['contract']!=='vp3.system-app-catalog.v1')throw new RuntimeException('Catalog contract mismatch.');
if($catalog['catalog_authority']!=='vp3_cloud'||$catalog['runtime_authority']!=='homeserver')throw new RuntimeException('Authority boundary mismatch.');
if($catalog['counts']['total']!==3||$catalog['counts']['owned']!==0||$catalog['counts']['available']!==3)throw new RuntimeException('Initial catalog projection mismatch.');

$owned=vp3_system_apps_acquire_v100(1,'vp3.notes','self_service',null,$testPdo);
if(empty($owned['owned'])||$owned['ownership_status']!=='active')throw new RuntimeException('Ownership was not created.');
$ownedAgain=vp3_system_apps_acquire_v100(1,'vp3.notes','self_service',null,$testPdo);
$count=(int)$testPdo->query("SELECT COUNT(*) FROM vp3_system_app_ownership WHERE user_id=1")->fetchColumn();
if($count!==1)throw new RuntimeException('Ownership acquisition is not idempotent.');
$events=(int)$testPdo->query("SELECT COUNT(*) FROM vp3_system_app_events WHERE user_id=1 AND event_type='app.ownership.acquired'")->fetchColumn();
if($events!==2)throw new RuntimeException('Ownership event ledger did not record acquisition attempts.');

if(!vp3_system_apps_revoke_v100(1,'vp3.notes','test',$testPdo))throw new RuntimeException('Ownership revoke failed.');
$after=vp3_system_apps_catalog_v100($userRow,$testPdo);
$notes=array_values(array_filter($after['apps'],static fn(array $a):bool=>$a['app_key']==='vp3.notes'))[0]??null;
if(!$notes||$notes['owned']||!$notes['eligible'])throw new RuntimeException('Revoked included app projection mismatch.');
$reacquired=vp3_system_apps_acquire_v100(1,'vp3.notes','self_service',null,$testPdo);
if(empty($reacquired['owned']))throw new RuntimeException('Reacquisition failed.');

$testPdo->prepare("INSERT INTO vp3_system_app_catalog(app_key,name,category,description,current_version,acquisition_mode,required_entitlement,homeserver_catalog_key,is_active,sort_order) VALUES (?,?,?,?,?,'entitlement',?,?,1,?)")
  ->execute(['vp3.premium','Premium','Business','Entitled app','1.0.0','apps.premium.demo','vp3.premium',90]);
$premium=vp3_system_apps_acquire_v100(1,'vp3.premium','self_service',null,$testPdo);
if(empty($premium['owned']))throw new RuntimeException('Entitlement-backed acquisition failed.');
$testPdo->prepare("UPDATE vp3_system_app_catalog SET required_entitlement='apps.denied' WHERE app_key='vp3.premium'")->execute();
vp3_system_apps_revoke_v100(1,'vp3.premium','test',$testPdo);
try{vp3_system_apps_acquire_v100(1,'vp3.premium','self_service',null,$testPdo);throw new RuntimeException('Denied entitlement acquired app.');}
catch(RuntimeException $e){if($e->getMessage()==='Denied entitlement acquired app.')throw $e;}

$other=vp3_system_apps_catalog_v100(['id'=>2,'role'=>'user'],$testPdo);
if($other['counts']['owned']!==0)throw new RuntimeException('Ownership leaked between users.');
$cap=vp3_system_apps_capability_v100();
if(empty($cap['durable_user_ownership'])||!empty($cap['homeserver_installation'])||!empty($cap['hosting_binding']))throw new RuntimeException('Capability boundary mismatch.');
echo "System Apps V1 Section 10 catalog and ownership MySQL: PASS\n";
