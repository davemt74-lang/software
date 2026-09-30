<?php
declare(strict_types=1);
$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');$user=(string)getenv('VP3_TEST_MYSQL_USER');$pass=(string)getenv('VP3_TEST_MYSQL_PASS');
if($dsn==='')throw new RuntimeException('VP3_TEST_MYSQL_DSN is required.');
$testPdo=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): ?PDO {global $testPdo;return $testPdo;}
function table_exists(string $table): bool {global $testPdo;$s=$testPdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$s->execute([$table]);return (int)$s->fetchColumn()>0;}
function subscription_has_entitlement(?array $user,string $key): bool {return true;}

$testPdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach(['vp3_system_app_installations','vp3_system_app_events','vp3_system_app_ownership','vp3_system_app_catalog','users'] as $table)$testPdo->exec('DROP TABLE IF EXISTS `'.$table.'`');
$testPdo->exec('SET FOREIGN_KEY_CHECKS=1');
$testPdo->exec("CREATE TABLE users (id INT UNSIGNED NOT NULL PRIMARY KEY,email VARCHAR(190) NOT NULL,display_name VARCHAR(160) NULL,role VARCHAR(40) NOT NULL DEFAULT 'user') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("INSERT INTO users(id,email,display_name,role) VALUES (1,'owner@example.com','Owner','user'),(2,'other@example.com','Other','user')");
require dirname(__DIR__).'/includes/system-apps-v110.php';

vp3_system_apps_ensure_schema_v110($testPdo);
if(!vp3_system_apps_schema_ready_v110($testPdo))throw new RuntimeException('System Apps v1.10 schema not ready.');

$userRow=['id'=>1,'role'=>'user','email'=>'owner@example.com','display_name'=>'Owner'];
vp3_system_apps_acquire_v100(1,'vp3.notes','self_service',null,$testPdo);
vp3_system_apps_acquire_v100(1,'vp3.inventory','self_service',null,$testPdo);

$calls=[];
$remote=function(int $userId,string $operation,array $payload) use (&$calls): array {
    $calls[]=[$userId,$operation,$payload];
    if($operation==='apps.system.install'){
        $key=(string)$payload['app_key'];
        return [
          'contract'=>'vp3.system-app-installation.v1',
          'catalog_version'=>'2026.09.29.1',
          'package'=>[
            'key'=>$key,'version'=>'1.0.0','package_sha256'=>str_repeat('a',64),
            'installed'=>true,'current'=>true,'update_available'=>false,'state'=>'running',
          ],
          'installed'=>true,'current'=>true,'update_available'=>false,'state'=>'running',
          'changed'=>true,'reason'=>'',
        ];
    }
    if($operation==='apps.system.reconcile'){
        $items=[];
        foreach($payload['app_keys'] as $key){
            $isNotes=$key==='vp3.notes';
            $items[]=[
              'contract'=>'vp3.system-app-installation.v1',
              'catalog_version'=>'2026.09.29.1',
              'package'=>[
                'key'=>$key,'version'=>'1.0.0','package_sha256'=>str_repeat($isNotes?'b':'c',64),
                'installed'=>$isNotes,'current'=>false,'update_available'=>$isNotes,'state'=>$isNotes?'running':'available',
              ],
              'installed'=>$isNotes,'current'=>false,'update_available'=>$isNotes,'state'=>$isNotes?'running':'available',
            ];
        }
        return ['contract'=>'vp3.system-app-reconciliation.v1','runtime_authority'=>'homeserver','items'=>$items];
    }
    throw new RuntimeException('Unexpected remote op '.$operation);
};

$installed=vp3_system_apps_install_v110(1,'vp3.notes',$remote,$testPdo);
if(empty($installed['homeserver']['installed'])||empty($installed['homeserver']['current']))throw new RuntimeException('Installed projection was not persisted.');
if($installed['homeserver']['state']!=='running'||$installed['homeserver']['installed_version']!=='1.0.0')throw new RuntimeException('HomeServer install state mismatch.');
if($calls[0][1]!=='apps.system.install'||$calls[0][2]['app_key']!=='vp3.notes')throw new RuntimeException('Install remote contract mismatch.');

try{vp3_system_apps_install_v110(2,'vp3.notes',$remote,$testPdo);throw new RuntimeException('Unowned app installation was allowed.');}
catch(RuntimeException $e){if($e->getMessage()==='Unowned app installation was allowed.')throw $e;}

$reconciled=vp3_system_apps_reconcile_v110(1,$remote,$testPdo);
if($reconciled['count']!==2)throw new RuntimeException('Reconciliation did not include all owned apps.');
$catalog=vp3_system_apps_catalog_v110($userRow,$testPdo);
$byKey=[];foreach($catalog['apps'] as $item)$byKey[$item['app_key']]=$item;
if(empty($byKey['vp3.notes']['homeserver']['installed'])||empty($byKey['vp3.notes']['homeserver']['update_available']))throw new RuntimeException('Reconciled installed/update state missing.');
if(!empty($byKey['vp3.inventory']['homeserver']['installed'])||$byKey['vp3.inventory']['homeserver']['state']!=='available')throw new RuntimeException('Uninstalled HomeServer projection mismatch.');
if($catalog['counts']['installed']!==1||$catalog['counts']['updates']!==1)throw new RuntimeException('Install/update counts mismatch.');

$mismatch=function(int $userId,string $operation,array $payload): array {
    return ['package'=>['key'=>'vp3.inventory','installed'=>true,'current'=>true,'state'=>'running'],'installed'=>true,'current'=>true,'state'=>'running'];
};
try{vp3_system_apps_install_v110(1,'vp3.notes',$mismatch,$testPdo);throw new RuntimeException('Mismatched remote app identity was accepted.');}
catch(RuntimeException $e){if($e->getMessage()==='Mismatched remote app identity was accepted.')throw $e;}

$projection=vp3_system_apps_install_projection_v110(1,(int)$byKey['vp3.notes']['id'],$testPdo);
if($projection['error']==='')throw new RuntimeException('Failed remote install did not persist an error projection.');

$events=$testPdo->query("SELECT event_type FROM vp3_system_app_events WHERE user_id=1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
foreach(['app.install.requested','app.install.completed','app.install.failed'] as $event)if(!in_array($event,$events,true))throw new RuntimeException('Missing install lifecycle event '.$event);

$cap=vp3_system_apps_capability_v110();
if(empty($cap['homeserver_installation'])||empty($cap['durable_install_projection'])||!empty($cap['hosting_binding']))throw new RuntimeException('Section 11 capability boundary mismatch.');
echo "System Apps V1 Section 11 HomeServer install MySQL: PASS\n";
