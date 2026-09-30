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
 'vp3_system_app_agent_actions','vp3_system_app_hosting_bindings','vp3_system_app_installations','vp3_system_app_events','vp3_system_app_ownership','vp3_system_app_catalog',
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
require dirname(__DIR__).'/includes/system-apps-v200.php';

vp3_cloud_hosting_v120_ensure_schema($testPdo);
vp3_system_apps_ensure_schema_v120($testPdo);
$owner=['id'=>1,'email'=>'owner@example.com','display_name'=>'Owner','role'=>'user'];
vp3_system_apps_acquire_v100(1,'vp3.notes','self_service',null,$testPdo);
$app=vp3_system_apps_owned_row_v110(1,'vp3.notes',$testPdo);
if((string)$app['current_version']!=='1.1.0')throw new RuntimeException('Cloud catalog did not advance to System App 1.1.0.');

$testPdo->prepare("INSERT INTO vp3_system_app_installations
  (user_id,app_id,observed_state,installed_version,homeserver_catalog_version,package_sha256,is_installed,is_current,update_available,last_remote_sync_at)
  VALUES (?,?, 'running','1.0.0','2026.09.29.1',?,1,0,1,NOW())")
  ->execute([1,(int)$app['id'],str_repeat('a',64)]);

$site=vp3_cloud_hosting_create_site_v100($owner,[
  'display_name'=>'Notes Public App',
  'runtime_kind'=>'static',
  'requested_hostname'=>'notes.release.example.com',
],1);

$ops=[];$activeRelease='apprel_old';$previousRelease=null;$installedVersion='1.0.0';
$remote=function(int $userId,string $operation,array $payload) use (&$ops,&$activeRelease,&$previousRelease,&$installedVersion): array {
    $ops[]=['operation'=>$operation,'payload'=>$payload];
    if($operation==='apps.system.release.status'){
        return [
          'contract'=>'vp3.system-app-release-status.v1',
          'catalog_version'=>'2026.09.30.2',
          'app_key'=>'vp3.notes',
          'release_channel'=>'stable',
          'available_version'=>'1.1.0',
          'release_notes'=>['Adds release lifecycle metadata.','Supports verified updates and rollback.'],
          'compatibility'=>['min_homeserver_version'=>'2.4','max_homeserver_version'=>null],
          'package_sha256'=>str_repeat('b',64),
          'integrity'=>['algorithm'=>'sha256','package_sha256'=>str_repeat('b',64),'trust'=>'embedded_vp3'],
          'runtime'=>[
            'active_release_id'=>$activeRelease,'previous_release_id'=>$previousRelease,'installed_version'=>$installedVersion,
          ],
          'rollback_available'=>$previousRelease!==null,
        ];
    }
    if($operation==='apps.system.install'){
        if(($payload['expected_version']??'')!=='1.1.0')throw new RuntimeException('Cloud did not bind expected release version.');
        if(($payload['expected_sha256']??'')!==str_repeat('b',64))throw new RuntimeException('Cloud did not bind expected package hash.');
        if(($payload['release_channel']??'')!=='stable')throw new RuntimeException('Cloud did not bind expected release channel.');
        $previousRelease=$activeRelease;$activeRelease='apprel_new';$installedVersion='1.1.0';
        return [
          'contract'=>'vp3.system-app-installation.v1','catalog_version'=>'2026.09.30.2',
          'package'=>[
            'key'=>'vp3.notes','version'=>'1.1.0','installed_version'=>'1.1.0','package_sha256'=>str_repeat('b',64),
            'installed'=>true,'current'=>true,'update_available'=>false,'state'=>'running',
          ],
          'installed'=>true,'current'=>true,'update_available'=>false,'state'=>'running','changed'=>true,'reason'=>'',
          'release'=>['release_id'=>'apprel_new','previous_release_id'=>'apprel_old','version'=>'1.1.0','package_sha256'=>str_repeat('b',64)],
          'verification'=>['contract'=>'vp3.app.release-health.v1','healthy'=>true,'release_id'=>'apprel_new','version'=>'1.1.0','package_sha256'=>str_repeat('b',64)],
          'rolled_back'=>false,
        ];
    }
    if($operation==='apps.system.rollback'){
        if(($payload['expected_active_release_id']??'')!==$activeRelease)throw new RuntimeException('Cloud rollback did not bind active release.');
        $from=$activeRelease;$target=$previousRelease;
        $activeRelease=$target??'apprel_old';$previousRelease=$from;$installedVersion='1.0.0';
        return [
          'changed'=>true,
          'rollback'=>['changed'=>true,'release'=>['release_id'=>$activeRelease,'version'=>'1.0.0','package_sha256'=>str_repeat('a',64)]],
          'status'=>['rollback_available'=>true],
        ];
    }
    if($operation==='apps.system.status'){
        return [
          'contract'=>'vp3.system-app-installation.v1','catalog_version'=>'2026.09.30.2',
          'package'=>[
            'key'=>'vp3.notes','version'=>'1.1.0','installed_version'=>$installedVersion,'package_sha256'=>str_repeat('a',64),
            'installed'=>true,'current'=>$installedVersion==='1.1.0','update_available'=>$installedVersion!=='1.1.0','state'=>'running',
          ],
          'installed'=>true,'current'=>$installedVersion==='1.1.0','update_available'=>$installedVersion!=='1.1.0','state'=>'running',
        ];
    }
    if($operation==='hosting.entitlements.reconcile')return ['revision'=>(int)$payload['revision'],'reconcile_result'=>'applied','configured'=>true];
    if($operation==='hosting.site.reconcile')return [
      'contract'=>'vp3.hosting.cloud-control.v1','cloud_site_id'=>$payload['cloud_site_id'],'site_id'=>'site_release_notes',
      'revision'=>(int)$payload['revision'],'desired_state'=>$payload['desired_state'],'observed_state'=>'configured',
      'active_release_id'=>$activeRelease,'public_routing'=>false,'target_app_key'=>$payload['target_app_key']??null,
    ];
    if($operation==='hosting.route.reconcile')return ['revision'=>(int)($payload['revision']??0),'route_ready'=>false];
    throw new RuntimeException('Unexpected remote operation '.$operation);
};

$bound=vp3_system_apps_hosting_bind_v120(1,'vp3.notes',(int)$site['id'],$remote,$testPdo);
if(empty($bound['hosting']['bound']))throw new RuntimeException('System App was not bound to Hosting before update.');
if(($bound['hosting']['hostname']??'')!=='notes.release.example.com')throw new RuntimeException('Subdomain hostname was not retained in app binding.');
$revisionBefore=(int)(vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo)['desired_revision']??0);

$status=vp3_system_apps_release_status_v200(1,'vp3.notes',$remote,$testPdo);
if($status['cloud_version']!=='1.1.0'||$status['release_channel']!=='stable')throw new RuntimeException('Release status did not join Cloud and HomeServer metadata.');
if(($status['compatibility']['min_homeserver_version']??'')!=='2.4')throw new RuntimeException('Release compatibility metadata missing.');

$updated=vp3_system_apps_release_update_v200(1,'vp3.notes',$remote,$testPdo);
if(($updated['homeserver']['installed_version']??'')!=='1.1.0')throw new RuntimeException('Verified System App update did not persist.');
if(empty($updated['verification']['healthy']))throw new RuntimeException('Post-update verification was not retained.');
if(empty($updated['hosting']['bound'])||!empty($updated['hosting']['pending']))throw new RuntimeException('Hosting/subdomain did not reconcile after update.');
if(($updated['hosting']['hostname']??'')!=='notes.release.example.com')throw new RuntimeException('Update lost the app subdomain binding.');
$revisionAfterUpdate=(int)(vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo)['desired_revision']??0);
if($revisionAfterUpdate<=$revisionBefore)throw new RuntimeException('App update did not advance Hosting desired revision.');
$siteOps=array_values(array_filter($ops,static fn(array $op):bool=>$op['operation']==='hosting.site.reconcile'));
$lastSiteOp=$siteOps[count($siteOps)-1]??[];
if(($lastSiteOp['payload']['target_app_key']??null)!=='vp3.notes')throw new RuntimeException('Post-update Hosting reconcile lost System App target.');

$rollback=vp3_system_apps_release_rollback_v200(1,'vp3.notes','test_rollback',$remote,$testPdo);
if(($rollback['homeserver']['installed_version']??'')!=='1.0.0')throw new RuntimeException('Cloud rollback did not persist previous HomeServer version.');
if(empty($rollback['hosting']['bound'])||!empty($rollback['hosting']['pending']))throw new RuntimeException('Hosting/subdomain did not reconcile after rollback.');
if(($rollback['hosting']['hostname']??'')!=='notes.release.example.com')throw new RuntimeException('Rollback lost the app subdomain binding.');
$revisionAfterRollback=(int)(vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo)['desired_revision']??0);
if($revisionAfterRollback<=$revisionAfterUpdate)throw new RuntimeException('Rollback did not advance Hosting desired revision.');
$siteOps=array_values(array_filter($ops,static fn(array $op):bool=>$op['operation']==='hosting.site.reconcile'));
$lastSiteOp=$siteOps[count($siteOps)-1]??[];
if(($lastSiteOp['payload']['target_app_key']??null)!=='vp3.notes')throw new RuntimeException('Post-rollback Hosting reconcile lost System App target.');

$events=$testPdo->query("SELECT event_type FROM vp3_system_app_events WHERE user_id=1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
foreach(['app.release.update_requested','app.release.updated','app.release.rolled_back','app.release.hosting_reconciled'] as $event){
    if(!in_array($event,$events,true))throw new RuntimeException('Missing integrated release event '.$event);
}

$catalog=vp3_system_apps_catalog_v200($owner,$testPdo);
$notes=null;foreach($catalog['apps'] as $row)if($row['app_key']==='vp3.notes')$notes=$row;
if(!$notes||($notes['release']['release_channel']??'')!=='stable'||empty($notes['release']['release_notes']))throw new RuntimeException('Apps catalog did not expose release metadata.');
if(($notes['hosting']['hostname']??'')!=='notes.release.example.com')throw new RuntimeException('Release-enriched catalog lost Hosting/subdomain state.');

$cap=vp3_system_apps_capability_v200();
foreach(['cloud_release_authority','homeserver_activation_authority','compatibility_gates','sha256_release_integrity','post_update_verification','protected_release_rollback','hosting_subdomain_post_release_reconcile'] as $key){
    if(empty($cap[$key]))throw new RuntimeException('Missing integrated release capability '.$key);
}
if(!empty($cap['release_auto_update']))throw new RuntimeException('System App releases must not auto-update.');

echo "System Apps Section 6 integrated Cloud HomeServer Hosting release MySQL: PASS\n";
