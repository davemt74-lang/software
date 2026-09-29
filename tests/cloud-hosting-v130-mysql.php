<?php
declare(strict_types=1);

$dsn=(string)getenv('VP3_TEST_MYSQL_DSN');
$user=(string)getenv('VP3_TEST_MYSQL_USER');
$pass=(string)getenv('VP3_TEST_MYSQL_PASS');
if($dsn==='')throw new RuntimeException('VP3_TEST_MYSQL_DSN is required.');
$testPdo=new PDO($dsn,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

function db(): ?PDO { global $testPdo; return $testPdo; }
function table_exists(string $table): bool {
    $pdo=db();$stmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $stmt->execute([$table]);return (int)$stmt->fetchColumn()>0;
}
$settings=[
    'hosting_cpanel_server'=>'https://panel.example.com:2083',
    'hosting_cpanel_username'=>'vp3user',
    'hosting_cpanel_api_token'=>'enc:CPANEL_SECRET_TOKEN',
    'hosting_cpanel_zone_domain'=>'sites.example.com',
    'hosting_public_ingress_hostname'=>'hosting-edge.example.net',
];
function setting(string $key,string $default=''): string { global $settings; return (string)($settings[$key]??$default); }
function ai_decrypt_secret(string $value): string { return str_starts_with($value,'enc:')?substr($value,4):''; }
function homeserver_vp3_encrypt(string $value): string { return 'hsenc:'.base64_encode($value); }
function homeserver_vp3_decrypt(?string $value): string {
    $raw=(string)$value;return str_starts_with($raw,'hsenc:')?(string)base64_decode(substr($raw,6),true):'';
}
function subscription_current_for_user_id(int $userId): ?array { return ['package_slug'=>'team-builder']; }

$entitlements=[
    'hosting.access'=>['enabled'=>true,'limit'=>0,'unlimited'=>false],
    'hosting.sites'=>['enabled'=>true,'limit'=>4,'unlimited'=>false],
    'hosting.subdomains'=>['enabled'=>true,'limit'=>4,'unlimited'=>false],
    'hosting.storage_mb_per_site'=>['enabled'=>true,'limit'=>500,'unlimited'=>false],
    'hosting.sqlite_mb_per_site'=>['enabled'=>true,'limit'=>100,'unlimited'=>false],
    'hosting.php_access'=>['enabled'=>true,'limit'=>0,'unlimited'=>false],
];
function subscription_effective_entitlement_v340(?array $user,string $key): array {
    global $entitlements;
    return ['key'=>$key,'base_enabled'=>false,'base_limit'=>null,'grant_count'=>0,'grant_limit'=>0]
        +($entitlements[$key]??['enabled'=>false,'limit'=>0,'unlimited'=>false]);
}

$testPdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach([
    'cloud_hosting_agent_actions','cloud_hosting_route_credentials','cloud_hosting_edge_certificates',
    'cloud_hosting_deployments','cloud_hosting_site_sync','cloud_hosting_entitlement_sync',
    'cloud_hosting_provider_operations','cloud_hosting_routes','cloud_hosting_site_events',
    'cloud_hosting_sites','package_entitlements','subscription_packages','homeserver_connections','users'
] as $table)$testPdo->exec("DROP TABLE IF EXISTS `".$table."`");
$testPdo->exec('SET FOREIGN_KEY_CHECKS=1');
$testPdo->exec("CREATE TABLE users (id INT UNSIGNED NOT NULL PRIMARY KEY,email VARCHAR(190) NOT NULL,display_name VARCHAR(160) NOT NULL DEFAULT '') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("CREATE TABLE homeserver_connections (user_id INT UNSIGNED NOT NULL PRIMARY KEY,status VARCHAR(30) NOT NULL DEFAULT 'unpaired') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("CREATE TABLE subscription_packages (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,slug VARCHAR(80) NOT NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("CREATE TABLE package_entitlements (package_id INT UNSIGNED NOT NULL,capability_key VARCHAR(120) NOT NULL,is_enabled TINYINT(1) NOT NULL DEFAULT 0,limit_value BIGINT NULL,PRIMARY KEY(package_id,capability_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("INSERT INTO users(id,email,display_name) VALUES (1,'owner@example.com','Owner'),(2,'other@example.com','Other')");
$testPdo->exec("INSERT INTO homeserver_connections(user_id,status) VALUES (1,'connected'),(2,'connected')");
$testPdo->exec("INSERT INTO subscription_packages(slug) VALUES ('team-builder')");

require dirname(__DIR__).'/includes/cloud-hosting-v100.php';
require dirname(__DIR__).'/includes/cloud-hosting-v110.php';
require dirname(__DIR__).'/includes/cloud-hosting-v120.php';
require dirname(__DIR__).'/includes/cloud-hosting-agent-v130.php';

vp3_cloud_hosting_agent_v130_ensure_schema($testPdo);
if(!vp3_cloud_hosting_agent_v130_schema_ready($testPdo))throw new RuntimeException('Section 4 schema is not ready.');

$owner=['id'=>1,'email'=>'owner@example.com','display_name'=>'Owner'];
$other=['id'=>2,'email'=>'other@example.com','display_name'=>'Other'];
$site=vp3_cloud_hosting_create_site_v100($owner,[
    'display_name'=>'Agent Site','requested_hostname'=>'agent.sites.example.com','runtime_kind'=>'static'
],1);

$remoteOps=[];
$remote=function(int $userId,string $operation,array $payload) use (&$remoteOps): array {
    $remoteOps[]=['user_id'=>$userId,'operation'=>$operation,'payload'=>$payload];
    if($operation==='hosting.dashboard'){
        return ['sites'=>[[
            'cloud_site_id'=>'host_unused',
            'observability'=>['requests_total'=>42,'client_error_total'=>2,'server_error_total'=>1,'average_duration_ms'=>17.5],
        ]]];
    }
    if($operation==='hosting.entitlements.reconcile')return ['revision'=>(int)$payload['revision'],'reconcile_result'=>'applied'];
    if($operation==='hosting.site.reconcile'){
        return [
            'site_id'=>'local_agent_site','revision'=>(int)$payload['revision'],
            'desired_state'=>(string)$payload['desired_state'],
            'observed_state'=>(string)$payload['desired_state'],
            'active_release_id'=>'release_current',
            'public_routing'=>false,'reconcile_result'=>'applied',
        ];
    }
    if($operation==='hosting.route.reconcile'){
        return [
            'revision'=>(int)$payload['revision'],'hostname'=>$payload['hostname'],
            'desired_state'=>$payload['desired_state'],'route_ready'=>false,
            'route_token'=>'NEVER_EXPOSE_ROUTE_TOKEN',
        ];
    }
    if($operation==='hosting.deployment.rollback'){
        return ['state'=>'applied','release_id'=>'release_previous','previous_release_id'=>'release_current'];
    }
    throw new RuntimeException('Unexpected remote operation '.$operation);
};
$provider=function(string $url,array $headers,int $timeout): array {
    return ['status'=>200,'body'=>json_encode(['cpanelresult'=>['data'=>[['result'=>1,'reason'=>'Added']]]])];
};

$list=vp3_cloud_hosting_agent_v130_query('show my hosted sites',$owner,77,$remote,$provider,$testPdo);
if(empty($list['handled'])||!str_contains((string)$list['answer'],'Agent Site'))throw new RuntimeException('Hosting list intent was not handled.');

$status=vp3_cloud_hosting_agent_v130_query('why is hosted site "Agent Site" offline?',$owner,77,$remote,$provider,$testPdo);
if(empty($status['handled'])||empty($status['hosting_plan']['read_only']))throw new RuntimeException('Hosting diagnostic intent was not read-only handled.');
$statusJson=json_encode($status);
foreach(['CPANEL_SECRET_TOKEN','NEVER_EXPOSE_ROUTE_TOKEN'] as $secret){
    if(str_contains((string)$statusJson,$secret))throw new RuntimeException('Hosting diagnostic leaked a secret.');
}

$prepared=vp3_cloud_hosting_agent_v130_query('activate hosted site "Agent Site"',$owner,77,$remote,$provider,$testPdo);
$plan=(array)($prepared['hosting_plan']??[]);
$code=(string)($plan['confirmation_code']??'');
if(!preg_match('/^[A-Z2-9]{8}$/',$code))throw new RuntimeException('Hosting action did not issue an explicit confirmation code.');
$row=$testPdo->query("SELECT * FROM cloud_hosting_agent_actions WHERE action_type='site.state' ORDER BY id DESC LIMIT 1")->fetch();
if(!$row||str_contains((string)$row['confirmation_token_hash'],$code))throw new RuntimeException('Confirmation code was stored in plaintext.');
if(!hash_equals((string)$row['confirmation_token_hash'],hash('sha256',$code)))throw new RuntimeException('Confirmation code hash mismatch.');

$testPdo->prepare("UPDATE cloud_hosting_agent_actions SET status='executing',execution_token='0123456789abcdef0123456789abcdef',execution_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) WHERE id=?")->execute([(int)$row['id']]);
try{
    vp3_cloud_hosting_agent_v130_confirm($owner,$code,77,$remote,$provider,$testPdo);
    throw new RuntimeException('Concurrent confirmation execution lease was bypassed.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Concurrent confirmation execution lease was bypassed.')throw $e;
    if(!str_contains($e->getMessage(),'already in progress'))throw $e;
}
$testPdo->prepare("UPDATE cloud_hosting_agent_actions SET execution_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?")->execute([(int)$row['id']]);
$reclaimed=vp3_cloud_hosting_agent_v130_confirm($owner,$code,77,$remote,$provider,$testPdo);
if(empty($reclaimed['completed']))throw new RuntimeException('Expired Hosting action execution lease was not reclaimable.');

try{
    vp3_cloud_hosting_agent_v130_confirm($other,$code,77,$remote,$provider,$testPdo);
    throw new RuntimeException('Another user confirmed the Hosting action.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Another user confirmed the Hosting action.')throw $e;
}
$before=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo);
if(($before['desired_state']??'')!=='configured')throw new RuntimeException('Prepared action changed state before confirmation.');

$after=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo);
if(($after['desired_state']??'')!=='active')throw new RuntimeException('Confirmed activation did not change desired state.');
$completed=$testPdo->query("SELECT status,result_json FROM cloud_hosting_agent_actions WHERE public_id=".$testPdo->quote((string)$plan['action_id']))->fetch();
if(($completed['status']??'')!=='completed')throw new RuntimeException('Confirmed action ledger did not close.');

$secondConfirm=vp3_cloud_hosting_agent_v130_query('confirm hosting '.$code,$owner,77,$remote,$provider,$testPdo);
if(!str_contains((string)$secondConfirm['answer'],'invalid or expired'))throw new RuntimeException('Confirmation token was reusable.');

$routePrepared=vp3_cloud_hosting_agent_v130_query('provision DNS for hosted site "Agent Site"',$owner,77,$remote,$provider,$testPdo);
$routeCode=(string)($routePrepared['hosting_plan']['confirmation_code']??'');
$routeDone=vp3_cloud_hosting_agent_v130_query('confirm hosting '.$routeCode,$owner,77,$remote,$provider,$testPdo);
if(empty($routeDone['hosting_plan']['completed']))throw new RuntimeException('Confirmed DNS action did not complete.');
$route=vp3_cloud_hosting_v110_route_for_site((int)$site['id'],$testPdo);
if(($route['dns_state']??'')!=='provisioned')throw new RuntimeException('Confirmed DNS action did not provision route.');

$testPdo->prepare("UPDATE cloud_hosting_sites SET active_release_id='release_current',previous_release_id='release_previous' WHERE id=?")->execute([(int)$site['id']]);
$rollbackPrepared=vp3_cloud_hosting_agent_v130_query('rollback deployment for hosted site "Agent Site"',$owner,77,$remote,$provider,$testPdo);
$rollbackCode=(string)($rollbackPrepared['hosting_plan']['confirmation_code']??'');
$rollbackDone=vp3_cloud_hosting_agent_v130_query('confirm hosting '.$rollbackCode,$owner,77,$remote,$provider,$testPdo);
if(empty($rollbackDone['hosting_plan']['completed']))throw new RuntimeException('Confirmed rollback did not complete.');
$afterRollback=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo);
if(($afterRollback['active_release_id']??'')!=='release_previous')throw new RuntimeException('Confirmed rollback did not update release lineage.');

$createPrepared=vp3_cloud_hosting_agent_v130_query('create a hosted site called "Second Agent Site"',$owner,88,$remote,$provider,$testPdo);
$createCode=(string)($createPrepared['hosting_plan']['confirmation_code']??'');
$createDone=vp3_cloud_hosting_agent_v130_query('confirm hosting '.$createCode,$owner,88,$remote,$provider,$testPdo);
if(empty($createDone['hosting_plan']['completed']))throw new RuntimeException('Confirmed site creation did not complete.');
if(vp3_cloud_hosting_count_sites_v100(1,$testPdo)!==2)throw new RuntimeException('Agent site creation count mismatch.');

$expired=vp3_cloud_hosting_agent_v130_prepare($owner,'site.reconcile',[],['site'=>'Agent Site'],(int)$site['id'],99,$testPdo);
$testPdo->prepare("UPDATE cloud_hosting_agent_actions SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE public_id=?")->execute([(string)$expired['action_id']]);
try{
    vp3_cloud_hosting_agent_v130_confirm($owner,(string)$expired['confirmation_code'],99,$remote,$provider,$testPdo);
    throw new RuntimeException('Expired Hosting confirmation was accepted.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Expired Hosting confirmation was accepted.')throw $e;
}

$ledger=$testPdo->query('SELECT payload_json,preview_json,result_json,error_message FROM cloud_hosting_agent_actions')->fetchAll();
$ledgerJson=json_encode($ledger);
foreach(['CPANEL_SECRET_TOKEN','NEVER_EXPOSE_ROUTE_TOKEN'] as $secret){
    if(str_contains((string)$ledgerJson,$secret))throw new RuntimeException('Hosting Agent action ledger leaked a secret.');
}

$prompt=vp3_cloud_hosting_agent_v130_prompt($owner);
if(!str_contains($prompt,'explicit 8-character confirmation code'))throw new RuntimeException('Agent Brain prompt does not enforce Hosting confirmation.');

echo "Cloud Hosting V1 Section 4 Agent tools MySQL integration: PASS\n";
