<?php
declare(strict_types=1);

$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');
$user=(string)getenv('VP3_TEST_MYSQL_USER');
$pass=(string)getenv('VP3_TEST_MYSQL_PASS');
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
foreach([
 'vp3_system_app_hosting_bindings','vp3_system_app_installations','vp3_system_app_events','vp3_system_app_ownership','vp3_system_app_catalog',
 'cloud_hosting_route_credentials','cloud_hosting_edge_certificates','cloud_hosting_deployments','cloud_hosting_site_sync','cloud_hosting_entitlement_sync',
 'cloud_hosting_provider_operations','cloud_hosting_routes','cloud_hosting_site_events','cloud_hosting_sites','homeserver_connections','users'
] as $table)$testPdo->exec("DROP TABLE IF EXISTS `".$table."`");
$testPdo->exec('SET FOREIGN_KEY_CHECKS=1');
$testPdo->exec("CREATE TABLE users (id INT UNSIGNED NOT NULL PRIMARY KEY,email VARCHAR(190) NOT NULL,display_name VARCHAR(160) NULL,role VARCHAR(40) NOT NULL DEFAULT 'user') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("CREATE TABLE homeserver_connections (user_id INT UNSIGNED NOT NULL PRIMARY KEY,status VARCHAR(30) NOT NULL DEFAULT 'unpaired') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("INSERT INTO users(id,email,display_name,role) VALUES (1,'owner@example.com','Owner','user')");
$testPdo->exec("INSERT INTO homeserver_connections(user_id,status) VALUES (1,'connected')");

require dirname(__DIR__).'/includes/cloud-hosting-v100.php';
require dirname(__DIR__).'/includes/cloud-hosting-v110.php';
require dirname(__DIR__).'/includes/cloud-hosting-v120.php';
require dirname(__DIR__).'/includes/system-apps-v130.php';

vp3_cloud_hosting_v120_ensure_schema($testPdo);
vp3_system_apps_ensure_schema_v120($testPdo);

$owner=['id'=>1,'email'=>'owner@example.com','display_name'=>'Owner','role'=>'user'];
$site1=vp3_cloud_hosting_create_site_v100($owner,['display_name'=>'Notes Host','runtime_kind'=>'static'],1);
$site2=vp3_cloud_hosting_create_site_v100($owner,['display_name'=>'Inventory Host','runtime_kind'=>'static'],1);
vp3_system_apps_acquire_v100(1,'vp3.notes','self_service',null,$testPdo);
vp3_system_apps_acquire_v100(1,'vp3.inventory','self_service',null,$testPdo);

$catalog=vp3_system_apps_catalog_v110($owner,$testPdo);$ids=[];
foreach($catalog['apps'] as $a)$ids[$a['app_key']]=(int)$a['id'];
$stmt=$testPdo->prepare("INSERT INTO vp3_system_app_installations(user_id,app_id,observed_state,installed_version,is_installed,is_current,update_available,last_remote_sync_at) VALUES (?,?, 'running','1.0.0',1,1,0,NOW())");
$stmt->execute([1,$ids['vp3.notes']]);
$stmt->execute([1,$ids['vp3.inventory']]);

$remoteOnline=function(int $userId,string $operation,array $payload): array {
    if($operation==='hosting.entitlements.reconcile')return ['revision'=>(int)$payload['revision'],'reconcile_result'=>'applied','configured'=>true];
    if($operation==='hosting.site.reconcile')return [
      'contract'=>'vp3.hosting.cloud-control.v1','cloud_site_id'=>$payload['cloud_site_id'],'site_id'=>'site_local',
      'revision'=>(int)$payload['revision'],'desired_state'=>$payload['desired_state'],'observed_state'=>$payload['desired_state'],
      'active_release_id'=>null,'public_routing'=>false,'target_app_key'=>$payload['target_app_key']??null,
    ];
    if($operation==='apps.system.deactivate'){
        $key=(string)$payload['app_key'];
        return [
          'contract'=>'vp3.system-app-installation.v1',
          'catalog_version'=>'2026.09.30.1',
          'package'=>['key'=>$key,'version'=>'1.0.0','installed_version'=>'1.0.0','installed'=>true,'current'=>false,'update_available'=>false,'state'=>'stopped'],
          'installed'=>true,'current'=>false,'update_available'=>false,'state'=>'stopped','changed'=>true,'reason'=>'ownership_revoked',
        ];
    }
    if($operation==='apps.system.reconcile')return ['contract'=>'vp3.system-app-reconciliation.v1','runtime_authority'=>'homeserver','items'=>[]];
    throw new RuntimeException('Unexpected remote operation '.$operation);
};

vp3_system_apps_hosting_bind_v120(1,'vp3.notes',(int)$site1['id'],$remoteOnline,$testPdo);
if(!vp3_system_apps_revoke_v100(1,'vp3.notes','audit',$testPdo,$remoteOnline))throw new RuntimeException('Online ownership revoke failed.');
$ownership=$testPdo->query("SELECT status FROM vp3_system_app_ownership WHERE user_id=1 AND app_id=".$ids['vp3.notes'])->fetchColumn();
if($ownership!=='revoked')throw new RuntimeException('Ownership was not revoked.');
$binding=vp3_system_apps_hosting_projection_v120(1,$ids['vp3.notes'],$testPdo);
if(!empty($binding['bound']))throw new RuntimeException('Revoked app retained hosting binding.');
$install=vp3_system_apps_install_projection_v110(1,$ids['vp3.notes'],$testPdo);
if($install['state']!=='stopped'||empty($install['installed']))throw new RuntimeException('Revoked app runtime was not deactivated while preserving install.');
$site=vp3_cloud_hosting_site_v100((int)$site1['id'],1,$testPdo);
if(($site['desired_state']??'')!=='configured')throw new RuntimeException('Revoked app hosting site was not deactivated.');

vp3_system_apps_hosting_bind_v120(1,'vp3.inventory',(int)$site2['id'],$remoteOnline,$testPdo);
$remoteOffline=function(int $userId,string $operation,array $payload): array {
    throw new RuntimeException('HomeServer offline');
};
if(!vp3_system_apps_revoke_v100(1,'vp3.inventory','audit-offline',$testPdo,$remoteOffline))throw new RuntimeException('Offline ownership revoke was blocked.');
$binding=vp3_system_apps_hosting_projection_v120(1,$ids['vp3.inventory'],$testPdo);
if(!empty($binding['bound']))throw new RuntimeException('Offline revoke retained Cloud hosting binding.');
$pending=vp3_system_apps_install_projection_v110(1,$ids['vp3.inventory'],$testPdo);
if($pending['state']!=='revocation_pending'||$pending['error']==='')throw new RuntimeException('Offline runtime cleanup was not persisted.');

$retry=vp3_system_apps_reconcile_all_v130(1,$remoteOnline,$testPdo);
if((int)$retry['revoked_cleanup']['count']!==1)throw new RuntimeException('Pending revocation cleanup was not retried.');
$after=vp3_system_apps_install_projection_v110(1,$ids['vp3.inventory'],$testPdo);
if($after['state']!=='stopped'||$after['error']!=='')throw new RuntimeException('Retried revocation cleanup did not converge.');

$event=$testPdo->query("SELECT metadata_json FROM vp3_system_app_events WHERE user_id=1 AND event_type='app.ownership.revoked' ORDER BY id DESC LIMIT 1")->fetchColumn();
$meta=json_decode((string)$event,true);
if(!is_array($meta)||!isset($meta['cleanup']))throw new RuntimeException('Ownership revoke event omitted cleanup audit state.');

$cap=vp3_system_apps_capability_v130();
foreach(['ownership_revocation_unbinds_hosting','ownership_revocation_deactivates_runtime','offline_revocation_cleanup_persists','revocation_cleanup_retries_on_reconcile','revocation_preserves_app_data'] as $key){
    if(empty($cap[$key]))throw new RuntimeException('Missing revocation capability '.$key);
}

echo "System Apps audit revocation lifecycle MySQL: PASS\n";
