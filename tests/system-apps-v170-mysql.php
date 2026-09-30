<?php
declare(strict_types=1);
$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');$dbUser=(string)getenv('VP3_TEST_MYSQL_USER');$pass=(string)getenv('VP3_TEST_MYSQL_PASS');
if($dsn==='')throw new RuntimeException('VP3_TEST_MYSQL_DSN is required.');
$testPdo=new PDO($dsn,$dbUser,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): ?PDO {global $testPdo;return $testPdo;}
function table_exists(string $table): bool {$s=db()->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$s->execute([$table]);return (int)$s->fetchColumn()>0;}
function setting(string $key,string $default=''): string {return $default;}
function url(string $path=''): string {return $path;}
function subscription_current_for_user_id(int $userId): ?array {return ['package_slug'=>'basic-user'];}
function subscription_has_entitlement(?array $user,string $key): bool {return true;}
$entitlements=[
 'hosting.access'=>['enabled'=>true,'limit'=>0,'unlimited'=>false],
 'hosting.sites'=>['enabled'=>true,'limit'=>5,'unlimited'=>false],
 'hosting.subdomains'=>['enabled'=>true,'limit'=>5,'unlimited'=>false],
 'hosting.storage_mb_per_site'=>['enabled'=>true,'limit'=>250,'unlimited'=>false],
 'hosting.sqlite_mb_per_site'=>['enabled'=>true,'limit'=>50,'unlimited'=>false],
 'hosting.php_access'=>['enabled'=>true,'limit'=>0,'unlimited'=>false],
];
function subscription_effective_entitlement_v340(?array $user,string $key): array {global $entitlements;return ['key'=>$key]+($entitlements[$key]??['enabled'=>false,'limit'=>0,'unlimited'=>false]);}
function homeserver_vp3_connection(int $userId): ?array {$s=db()->prepare('SELECT * FROM homeserver_connections WHERE user_id=? LIMIT 1');$s->execute([$userId]);$r=$s->fetch();return $r?:null;}
function agent_tool_log(array $user,string $tool,string $request,string $status,array $result=[],int $conversationId=0): void {}

$testPdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach([
 'vp3_system_app_hosting_bindings','vp3_system_app_installations','vp3_system_app_events','vp3_system_app_ownership','vp3_system_app_catalog',
 'cloud_hosting_route_credentials','cloud_hosting_edge_certificates','cloud_hosting_deployments','cloud_hosting_site_sync','cloud_hosting_entitlement_sync',
 'cloud_hosting_provider_operations','cloud_hosting_routes','cloud_hosting_site_events','cloud_hosting_sites','homeserver_connections','users'
] as $table)$testPdo->exec("DROP TABLE IF EXISTS `".$table."`");
$testPdo->exec('SET FOREIGN_KEY_CHECKS=1');
$testPdo->exec("CREATE TABLE users (id INT UNSIGNED NOT NULL PRIMARY KEY,email VARCHAR(190) NOT NULL,display_name VARCHAR(160) NULL,role VARCHAR(40) NOT NULL DEFAULT 'user') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("CREATE TABLE homeserver_connections (user_id INT UNSIGNED NOT NULL PRIMARY KEY,status VARCHAR(30) NOT NULL DEFAULT 'unpaired',last_seen_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("INSERT INTO users(id,email,display_name,role) VALUES (1,'owner@example.com','Owner','user')");
$testPdo->exec("INSERT INTO homeserver_connections(user_id,status,last_seen_at) VALUES (1,'connected',NOW())");

require dirname(__DIR__).'/includes/cloud-hosting-v100.php';
require dirname(__DIR__).'/includes/cloud-hosting-v110.php';
require dirname(__DIR__).'/includes/cloud-hosting-v120.php';
require dirname(__DIR__).'/includes/system-apps-v180.php';

vp3_cloud_hosting_v120_ensure_schema($testPdo);
vp3_system_apps_ensure_schema_v120($testPdo);
$owner=['id'=>1,'email'=>'owner@example.com','display_name'=>'Owner','role'=>'user'];
vp3_system_apps_acquire_v100(1,'vp3.notes','self_service',null,$testPdo);
$app=vp3_system_apps_owned_row_v110(1,'vp3.notes',$testPdo);

function remote_item(string $state,bool $update): array {
 return [
   'contract'=>'vp3.system-app-installation.v1','catalog_version'=>'2026.09.30.2',
   'package'=>[
     'key'=>'vp3.notes','version'=>'1.1.0','installed_version'=>'1.0.0','package_sha256'=>str_repeat('d',64),
     'installed'=>true,'current'=>!$update,'update_available'=>$update,'state'=>$state,
   ],
   'installed'=>true,'current'=>!$update,'update_available'=>$update,'state'=>$state,
 ];
}

vp3_system_apps_store_remote_v110(1,$app,remote_item('running',false),false,$testPdo);
vp3_system_apps_store_remote_v110(1,$app,remote_item('running',true),false,$testPdo);
vp3_system_apps_store_remote_v110(1,$app,remote_item('running',true),false,$testPdo);
$available=(int)$testPdo->query("SELECT COUNT(*) FROM vp3_system_app_events WHERE event_type='app.update.available'")->fetchColumn();
if($available!==1)throw new RuntimeException('Update-available transition was not deduplicated.');

vp3_system_apps_store_remote_v110(1,$app,remote_item('stopped',false),false,$testPdo);
$cleared=(int)$testPdo->query("SELECT COUNT(*) FROM vp3_system_app_events WHERE event_type='app.update.cleared'")->fetchColumn();
$stateChanged=(int)$testPdo->query("SELECT COUNT(*) FROM vp3_system_app_events WHERE event_type='app.runtime.state_changed'")->fetchColumn();
if($cleared!==1||$stateChanged!==1)throw new RuntimeException('Update clear/runtime transition events mismatch.');

vp3_system_apps_store_error_v110(1,$app,'HomeServer offline','reconcile_failed',$testPdo);
vp3_system_apps_store_error_v110(1,$app,'HomeServer offline','reconcile_failed',$testPdo);
$problems=(int)$testPdo->query("SELECT COUNT(*) FROM vp3_system_app_events WHERE event_type='app.health.problem'")->fetchColumn();
if($problems!==1)throw new RuntimeException('Identical health errors were not deduplicated.');

$health=vp3_system_apps_health_snapshot_v170($owner,$testPdo);$notes=null;
foreach($health['apps'] as $candidate)if($candidate['app_key']==='vp3.notes')$notes=$candidate;
if(!$notes||$notes['health_state']!=='issue'||empty($notes['health_issues']))throw new RuntimeException('Health projection did not surface the persisted error.');

vp3_system_apps_store_remote_v110(1,$app,remote_item('running',false),false,$testPdo);
$recovered=(int)$testPdo->query("SELECT COUNT(*) FROM vp3_system_app_events WHERE event_type='app.health.recovered'")->fetchColumn();
if($recovered!==1)throw new RuntimeException('Health recovery transition was not recorded.');

$health=vp3_system_apps_health_snapshot_v170($owner,$testPdo);$notes=null;
foreach($health['apps'] as $candidate)if($candidate['app_key']==='vp3.notes')$notes=$candidate;
if(!$notes||$notes['health_state']!=='healthy'||$notes['health_issues'])throw new RuntimeException('Recovered app did not project healthy state.');

$diagnosis=vp3_system_apps_health_query_v170('why is Notes app not working?',$owner,17,null,$testPdo);
if(empty($diagnosis['handled'])||!isset($diagnosis['system_app_health']['app']))throw new RuntimeException('Agent diagnostic query was not handled from canonical health state.');

$cap=vp3_system_apps_capability_v180();
foreach(['transition_health_events','proactive_update_events','proactive_error_recovery_events','agent_health_context','agent_diagnostics','hosting_diagnostics_integration'] as $key){
 if(empty($cap[$key]))throw new RuntimeException('Missing System App health capability '.$key);
}

vp3_system_apps_store_remote_v110(1,$app,remote_item('running',true),false,$testPdo);
$proactive=vp3_system_apps_proactive_candidates_v180($testPdo,$owner);
$updateSuggestion=array_values(array_filter($proactive,static fn(array $item):bool=>(string)($item['source']??'')==='system_apps_update'));
if(count($updateSuggestion)!==1)throw new RuntimeException('Update state did not create one proactive recommendation.');
vp3_system_apps_store_error_v110(1,$app,'HomeServer reconcile failed','reconcile_failed',$testPdo);
$proactive=vp3_system_apps_proactive_candidates_v180($testPdo,$owner);
$healthSuggestion=array_values(array_filter($proactive,static fn(array $item):bool=>(string)($item['source']??'')==='system_apps_health'));
if(count($healthSuggestion)!==1)throw new RuntimeException('Health state did not create one proactive recommendation.');
echo "System Apps Agent Integration Sections 3-4 health/proactive MySQL: PASS\n";
