<?php
declare(strict_types=1);
$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');$user=(string)getenv('VP3_TEST_MYSQL_USER');$pass=(string)getenv('VP3_TEST_MYSQL_PASS');
if($dsn==='')throw new RuntimeException('VP3_TEST_MYSQL_DSN is required.');
$testPdo=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): ?PDO {global $testPdo;return $testPdo;}
function table_exists(string $table): bool {$s=db()->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$s->execute([$table]);return (int)$s->fetchColumn()>0;}
function setting(string $key,string $default=''): string {return $default;}
function subscription_current_for_user_id(int $userId): ?array {return ['package_slug'=>'basic-user'];}
$entitlements=[
 'hosting.access'=>['enabled'=>true,'limit'=>0,'unlimited'=>false],
 'hosting.sites'=>['enabled'=>true,'limit'=>5,'unlimited'=>false],
 'hosting.subdomains'=>['enabled'=>true,'limit'=>5,'unlimited'=>false],
 'hosting.storage_mb_per_site'=>['enabled'=>true,'limit'=>250,'unlimited'=>false],
 'hosting.sqlite_mb_per_site'=>['enabled'=>true,'limit'=>50,'unlimited'=>false],
 'hosting.php_access'=>['enabled'=>true,'limit'=>0,'unlimited'=>false],
];
function subscription_effective_entitlement_v340(?array $user,string $key): array {global $entitlements;return ['key'=>$key]+($entitlements[$key]??['enabled'=>false,'limit'=>0,'unlimited'=>false]);}
function subscription_has_entitlement(?array $user,string $key): bool {return true;}

$testPdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach(['vp3_system_app_hosting_bindings','vp3_system_app_installations','vp3_system_app_events','vp3_system_app_ownership','vp3_system_app_catalog','cloud_hosting_route_credentials','cloud_hosting_edge_certificates','cloud_hosting_deployments','cloud_hosting_site_sync','cloud_hosting_entitlement_sync','cloud_hosting_provider_operations','cloud_hosting_routes','cloud_hosting_site_events','cloud_hosting_sites','homeserver_connections','users'] as $table)$testPdo->exec("DROP TABLE IF EXISTS `".$table."`");
$testPdo->exec('SET FOREIGN_KEY_CHECKS=1');
$testPdo->exec("CREATE TABLE users (id INT UNSIGNED NOT NULL PRIMARY KEY,email VARCHAR(190) NOT NULL,display_name VARCHAR(160) NULL,role VARCHAR(40) NOT NULL DEFAULT 'user') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("CREATE TABLE homeserver_connections (user_id INT UNSIGNED NOT NULL PRIMARY KEY,status VARCHAR(30) NOT NULL DEFAULT 'unpaired') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("INSERT INTO users(id,email,display_name,role) VALUES (1,'owner@example.com','Owner','user')");
$testPdo->exec("INSERT INTO homeserver_connections(user_id,status) VALUES (1,'connected')");

require dirname(__DIR__).'/includes/cloud-hosting-v100.php';
require dirname(__DIR__).'/includes/cloud-hosting-v110.php';
require dirname(__DIR__).'/includes/cloud-hosting-v120.php';
require dirname(__DIR__).'/includes/system-apps-v120.php';

vp3_cloud_hosting_v120_ensure_schema($testPdo);
vp3_system_apps_ensure_schema_v120($testPdo);
if(!vp3_system_apps_schema_ready_v120($testPdo))throw new RuntimeException('System Apps v1.20 schema not ready.');

$owner=['id'=>1,'email'=>'owner@example.com','display_name'=>'Owner','role'=>'user'];
$site=vp3_cloud_hosting_create_site_v100($owner,['display_name'=>'App Host','runtime_kind'=>'static'],1);
vp3_system_apps_acquire_v100(1,'vp3.notes','self_service',null,$testPdo);
vp3_system_apps_acquire_v100(1,'vp3.inventory','self_service',null,$testPdo);

$catalog=vp3_system_apps_catalog_v110($owner,$testPdo);$ids=[];foreach($catalog['apps'] as $a)$ids[$a['app_key']]=(int)$a['id'];
$stmt=$testPdo->prepare("INSERT INTO vp3_system_app_installations(user_id,app_id,observed_state,installed_version,is_installed,is_current,update_available,last_remote_sync_at) VALUES (?,?, 'running','1.0.0',1,1,0,NOW())");
$stmt->execute([1,$ids['vp3.notes']]);$stmt->execute([1,$ids['vp3.inventory']]);

$ops=[];
$remote=function(int $userId,string $operation,array $payload) use (&$ops): array {
 $ops[]=['operation'=>$operation,'payload'=>$payload];
 if($operation==='hosting.entitlements.reconcile')return ['revision'=>(int)$payload['revision'],'reconcile_result'=>'applied','configured'=>true];
 if($operation==='hosting.site.reconcile')return ['contract'=>'vp3.hosting.cloud-control.v1','cloud_site_id'=>$payload['cloud_site_id'],'site_id'=>'site_local_app','revision'=>(int)$payload['revision'],'desired_state'=>$payload['desired_state'],'observed_state'=>'configured','active_release_id'=>null,'public_routing'=>false,'target_app_key'=>$payload['target_app_key']??null];
 throw new RuntimeException('Unexpected remote operation '.$operation);
};

$bound=vp3_system_apps_hosting_bind_v120(1,'vp3.notes',(int)$site['id'],$remote,$testPdo);
if(empty($bound['hosting']['bound'])||(int)$bound['hosting']['site_id']!==(int)$site['id'])throw new RuntimeException('App hosting binding not persisted.');
$siteOps=array_values(array_filter($ops,fn($o)=>$o['operation']==='hosting.site.reconcile'));
if(($siteOps[count($siteOps)-1]['payload']['target_app_key']??null)!=='vp3.notes')throw new RuntimeException('Hosting reconcile did not project app target.');

$secondSite=vp3_cloud_hosting_create_site_v100($owner,['display_name'=>'Second App Host','runtime_kind'=>'static'],1);
try{vp3_system_apps_hosting_bind_v120(1,'vp3.notes',(int)$secondSite['id'],$remote,$testPdo);throw new RuntimeException('Cross-site reassignment was allowed without unbind.');}
catch(RuntimeException $e){if($e->getMessage()==='Cross-site reassignment was allowed without unbind.')throw $e;}

$fresh=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo);
vp3_cloud_hosting_v120_reconcile_site($fresh,$remote,$testPdo);
$siteOps=array_values(array_filter($ops,fn($o)=>$o['operation']==='hosting.site.reconcile'));
if(($siteOps[count($siteOps)-1]['payload']['target_app_key']??null)!=='vp3.notes')throw new RuntimeException('Ordinary hosting reconcile lost app target.');

try{vp3_system_apps_hosting_bind_v120(1,'vp3.inventory',(int)$site['id'],$remote,$testPdo);throw new RuntimeException('One-site-per-app invariant failed.');}
catch(RuntimeException $e){if($e->getMessage()==='One-site-per-app invariant failed.')throw $e;}

$unbound=vp3_system_apps_hosting_unbind_v120(1,'vp3.notes',$remote,$testPdo);
if(!empty($unbound['hosting']['bound']))throw new RuntimeException('App hosting binding was not removed.');
$siteOps=array_values(array_filter($ops,fn($o)=>$o['operation']==='hosting.site.reconcile'));
$lastPayload=$siteOps[count($siteOps)-1]['payload'];
if(!array_key_exists('target_app_key',$lastPayload)||$lastPayload['target_app_key']!==null)throw new RuntimeException('Unbind did not clear HomeServer app target.');
$after=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo);
if(($after['desired_state']??'')!=='configured')throw new RuntimeException('Unbound hosting site was not returned to configured state.');

$events=$testPdo->query("SELECT event_type FROM vp3_system_app_events WHERE user_id=1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
foreach(['app.hosting.bound','app.hosting.unbound'] as $event)if(!in_array($event,$events,true))throw new RuntimeException('Missing hosting lifecycle event '.$event);
$cap=vp3_system_apps_capability_v120();
if(empty($cap['hosting_binding'])||empty($cap['hosting_owns_dns_tls'])||empty($cap['existing_hosting_sites_only']))throw new RuntimeException('Section 12 capability boundary mismatch.');
echo "System Apps V1 Section 12 hosting binding MySQL: PASS\n";
