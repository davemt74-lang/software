<?php
declare(strict_types=1);

$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');$dbUser=(string)getenv('VP3_TEST_MYSQL_USER');$pass=(string)getenv('VP3_TEST_MYSQL_PASS');
if($dsn==='')throw new RuntimeException('VP3_TEST_MYSQL_DSN is required.');
$testPdo=new PDO($dsn,$dbUser,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function db(): ?PDO {global $testPdo;return $testPdo;}
function table_exists(string $table): bool {$s=db()->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");$s->execute([$table]);return (int)$s->fetchColumn()>0;}
function setting(string $key,string $default=''): string {return $default;}
function url(string $path=''): string {return $path;}
function has_permission(string $key,?array $user=null): bool {return true;}
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
$testPdo->exec("INSERT INTO users(id,email,display_name,role) VALUES (1,'owner@example.com','Owner','user'),(2,'other@example.com','Other','user')");
$testPdo->exec("INSERT INTO homeserver_connections(user_id,status,last_seen_at) VALUES (1,'connected',NOW()),(2,'connected',NOW())");

require dirname(__DIR__).'/includes/cloud-hosting-v100.php';
require dirname(__DIR__).'/includes/cloud-hosting-v110.php';
require dirname(__DIR__).'/includes/cloud-hosting-v120.php';
require dirname(__DIR__).'/includes/system-apps-v160.php';

vp3_cloud_hosting_v120_ensure_schema($testPdo);
vp3_system_apps_ensure_schema_v120($testPdo);
vp3_system_apps_agent_actions_ensure_schema_v160($testPdo);
if(!vp3_system_apps_agent_actions_schema_ready_v160($testPdo))throw new RuntimeException('System Apps Agent action schema not ready.');

$owner=['id'=>1,'email'=>'owner@example.com','display_name'=>'Owner','role'=>'user'];
$other=['id'=>2,'email'=>'other@example.com','display_name'=>'Other','role'=>'user'];

if(vp3_system_apps_agent_action_intent_v160('what apps need updates?'))throw new RuntimeException('Read-only update question was classified as an action.');
if(!vp3_system_apps_agent_action_intent_v160('install Inventory app'))throw new RuntimeException('Explicit install was not classified as an action.');

$acquire=vp3_system_apps_agent_prepare_v160($owner,'ownership.acquire','vp3.inventory',[],['app'=>'VP3 Inventory'],42,null,$testPdo);
if(empty($acquire['requires_confirmation'])||strlen((string)$acquire['confirmation_code'])!==8)throw new RuntimeException('Acquire action was not confirmation-gated.');
$done=vp3_system_apps_agent_confirm_v160($owner,(string)$acquire['confirmation_code'],42,null,$testPdo);
if(empty($done['completed'])||!empty($done['idempotent_replay']))throw new RuntimeException('Acquire confirmation did not execute normally.');
$count=(int)$testPdo->query("SELECT COUNT(*) FROM vp3_system_app_ownership o JOIN vp3_system_app_catalog c ON c.id=o.app_id WHERE o.user_id=1 AND c.app_key='vp3.inventory' AND o.status='active'")->fetchColumn();
if($count!==1)throw new RuntimeException('Acquire action did not persist one ownership record.');
$replay=vp3_system_apps_agent_confirm_v160($owner,(string)$acquire['confirmation_code'],42,null,$testPdo);
if(empty($replay['idempotent_replay']))throw new RuntimeException('Completed confirmation did not replay idempotently.');
$count2=(int)$testPdo->query("SELECT COUNT(*) FROM vp3_system_app_ownership o JOIN vp3_system_app_catalog c ON c.id=o.app_id WHERE o.user_id=1 AND c.app_key='vp3.inventory'")->fetchColumn();
if($count2!==1)throw new RuntimeException('Idempotent replay duplicated ownership.');

$remote=function(int $userId,string $operation,array $payload): array {
    if($operation==='apps.system.install'){
        $key=(string)$payload['app_key'];
        return [
          'contract'=>'vp3.system-app-installation.v1','catalog_version'=>'2026.09.30.1',
          'package'=>['key'=>$key,'version'=>'1.0.0','installed_version'=>'1.0.0','package_sha256'=>str_repeat('a',64),'installed'=>true,'current'=>true,'update_available'=>false,'state'=>'running'],
          'installed'=>true,'current'=>true,'update_available'=>false,'state'=>'running','changed'=>true,'reason'=>'',
        ];
    }
    if($operation==='apps.system.reconcile')return ['contract'=>'vp3.system-app-reconciliation.v1','runtime_authority'=>'homeserver','items'=>[]];
    if($operation==='hosting.entitlements.reconcile')return ['revision'=>(int)$payload['revision'],'reconcile_result'=>'applied','configured'=>true];
    if($operation==='hosting.site.reconcile')throw new RuntimeException('HomeServer offline during hosting reconciliation.');
    throw new RuntimeException('Unexpected remote op '.$operation);
};

$install=vp3_system_apps_agent_prepare_v160($owner,'install','vp3.inventory',[],['app'=>'VP3 Inventory'],42,null,$testPdo);
$installed=vp3_system_apps_agent_confirm_v160($owner,(string)$install['confirmation_code'],42,$remote,$testPdo);
if(empty($installed['completed']))throw new RuntimeException('Install confirmation did not complete.');
$catalog=vp3_system_apps_agent_snapshot_v150($owner,$testPdo);$inventory=null;
foreach($catalog['apps'] as $a)if($a['app_key']==='vp3.inventory')$inventory=$a;
if(!$inventory||empty($inventory['installed'])||empty($inventory['running']))throw new RuntimeException('Install action did not update canonical Agent state.');

$wrongConversation=vp3_system_apps_agent_prepare_v160($owner,'update.verify','vp3.inventory',[],['app'=>'VP3 Inventory'],99,null,$testPdo);
try{vp3_system_apps_agent_confirm_v160($owner,(string)$wrongConversation['confirmation_code'],98,$remote,$testPdo);throw new RuntimeException('Conversation-bound confirmation was accepted in another conversation.');}
catch(RuntimeException $e){if($e->getMessage()==='Conversation-bound confirmation was accepted in another conversation.')throw $e;}

$expired=vp3_system_apps_agent_prepare_v160($owner,'update.verify','vp3.inventory',[],['app'=>'VP3 Inventory'],42,null,$testPdo);
$testPdo->prepare("UPDATE vp3_system_app_agent_actions SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE public_id=?")->execute([$expired['action_id']]);
try{vp3_system_apps_agent_confirm_v160($owner,(string)$expired['confirmation_code'],42,$remote,$testPdo);throw new RuntimeException('Expired confirmation was accepted.');}
catch(RuntimeException $e){if($e->getMessage()==='Expired confirmation was accepted.')throw $e;}

$leased=vp3_system_apps_agent_prepare_v160($owner,'update.verify','vp3.inventory',[],['app'=>'VP3 Inventory'],42,null,$testPdo);
$testPdo->prepare("UPDATE vp3_system_app_agent_actions SET status='executing',execution_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 4 MINUTE) WHERE public_id=?")->execute([$leased['action_id']]);
try{vp3_system_apps_agent_confirm_v160($owner,(string)$leased['confirmation_code'],42,$remote,$testPdo);throw new RuntimeException('Active execution lease was bypassed.');}
catch(RuntimeException $e){if($e->getMessage()==='Active execution lease was bypassed.')throw $e;}

$site=vp3_cloud_hosting_create_site_v100($owner,['display_name'=>'Inventory Host','runtime_kind'=>'static'],1);
$bind=vp3_system_apps_agent_prepare_v160($owner,'hosting.bind','vp3.inventory',['site_id'=>(int)$site['id']],['app'=>'VP3 Inventory'],42,(int)$site['id'],$testPdo);
$bound=vp3_system_apps_agent_confirm_v160($owner,(string)$bind['confirmation_code'],42,$remote,$testPdo);
if(empty($bound['completed'])||empty($bound['result']['reconcile_pending']))throw new RuntimeException('Partial Hosting reconcile was not completed safely with pending reconciliation.');
$binding=$testPdo->query("SELECT status FROM vp3_system_app_hosting_bindings WHERE user_id=1 LIMIT 1")->fetchColumn();
if($binding!=='active')throw new RuntimeException('Hosting binding was not preserved after remote reconcile failure.');

try{vp3_system_apps_agent_prepare_v160($owner,'ownership.revoke','vp3.inventory',[],[],42,null,$testPdo);throw new RuntimeException('Ownership revoke was exposed through Agent actions.');}
catch(RuntimeException $e){if($e->getMessage()==='Ownership revoke was exposed through Agent actions.')throw $e;}

$otherAcquire=vp3_system_apps_agent_prepare_v160($other,'ownership.acquire','vp3.notes',[],['app'=>'VP3 Notes'],7,null,$testPdo);
try{vp3_system_apps_agent_confirm_v160($owner,(string)$otherAcquire['confirmation_code'],7,null,$testPdo);throw new RuntimeException('Another user confirmation code was accepted.');}
catch(RuntimeException $e){if($e->getMessage()==='Another user confirmation code was accepted.')throw $e;}

$cap=vp3_system_apps_capability_v160();
foreach(['consequential_agent_actions','explicit_confirmation','action_execution_lease','idempotent_confirmation_replay','agent_install_update','agent_hosting_bind_unbind','agent_reconcile'] as $key){
  if(empty($cap[$key]))throw new RuntimeException('Missing Agent action capability '.$key);
}
if(!empty($cap['ownership_revoke_via_agent']))throw new RuntimeException('Ownership revoke must remain disabled for Agent actions.');

echo "System Apps Agent Integration Section 2 governed actions MySQL: PASS\n";
