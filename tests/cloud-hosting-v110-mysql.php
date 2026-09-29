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

$entitlements=[
    'hosting.access'=>['enabled'=>true,'limit'=>0,'unlimited'=>false],
    'hosting.sites'=>['enabled'=>true,'limit'=>2,'unlimited'=>false],
    'hosting.subdomains'=>['enabled'=>true,'limit'=>2,'unlimited'=>false],
    'hosting.storage_mb_per_site'=>['enabled'=>true,'limit'=>250,'unlimited'=>false],
    'hosting.sqlite_mb_per_site'=>['enabled'=>true,'limit'=>50,'unlimited'=>false],
    'hosting.php_access'=>['enabled'=>true,'limit'=>0,'unlimited'=>false],
];
function subscription_effective_entitlement_v340(?array $user,string $key): array {
    global $entitlements;
    return ['key'=>$key,'base_enabled'=>false,'base_limit'=>null,'grant_count'=>0,'grant_limit'=>0]
        +($entitlements[$key]??['enabled'=>false,'limit'=>0,'unlimited'=>false]);
}

$testPdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach(['cloud_hosting_provider_operations','cloud_hosting_routes','cloud_hosting_site_events','cloud_hosting_sites','package_entitlements','subscription_packages','homeserver_connections','users'] as $table){
    $testPdo->exec("DROP TABLE IF EXISTS `".$table."`");
}
$testPdo->exec('SET FOREIGN_KEY_CHECKS=1');
$testPdo->exec("CREATE TABLE users (id INT UNSIGNED NOT NULL PRIMARY KEY,email VARCHAR(190) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("CREATE TABLE homeserver_connections (user_id INT UNSIGNED NOT NULL PRIMARY KEY,status VARCHAR(30) NOT NULL DEFAULT 'unpaired') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("CREATE TABLE subscription_packages (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,slug VARCHAR(80) NOT NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("CREATE TABLE package_entitlements (package_id INT UNSIGNED NOT NULL,capability_key VARCHAR(120) NOT NULL,is_enabled TINYINT(1) NOT NULL DEFAULT 0,limit_value BIGINT NULL,PRIMARY KEY(package_id,capability_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("INSERT INTO users(id,email) VALUES (1,'owner@example.com')");
$testPdo->exec("INSERT INTO homeserver_connections(user_id,status) VALUES (1,'connected')");
$testPdo->exec("INSERT INTO subscription_packages(slug) VALUES ('basic-user')");

require dirname(__DIR__).'/includes/cloud-hosting-v100.php';
require dirname(__DIR__).'/includes/cloud-hosting-v110.php';

vp3_cloud_hosting_v110_ensure_schema($testPdo);
if(!vp3_cloud_hosting_v110_schema_ready($testPdo))throw new RuntimeException('Section 2 schema is not ready.');

$uapiRequests=[];
$uapiTransport=function(string $url,array $headers,int $timeout) use (&$uapiRequests): array {
    $uapiRequests[]=['url'=>$url,'headers'=>$headers];
    return ['status'=>200,'body'=>json_encode(['result'=>['status'=>1,'data'=>['main_domain'=>'sites.example.com']]])];
};
$uapi=vp3_cloud_hosting_v110_cpanel_call('uapi','DomainInfo','list_domains',[],$uapiTransport);
if(($uapi['status']??0)!==200||count($uapiRequests)!==1)throw new RuntimeException('cPanel UAPI health call failed.');
if(!str_contains($uapiRequests[0]['url'],'/execute/DomainInfo/list_domains'))throw new RuntimeException('cPanel UAPI path mismatch.');
if(!in_array('Authorization: cpanel vp3user:CPANEL_SECRET_TOKEN',$uapiRequests[0]['headers'],true))throw new RuntimeException('cPanel UAPI auth header missing.');

try{
    vp3_cloud_hosting_v110_cpanel_call('api2','ZoneEdit','add_zone_record',[],fn()=>[
        'status'=>200,'body'=>json_encode(['cpanelresult'=>['data'=>[[]]]])
    ]);
    throw new RuntimeException('API2 response without explicit success was accepted.');
}catch(RuntimeException $e){
    if($e->getMessage()==='API2 response without explicit success was accepted.')throw $e;
}

$owner=['id'=>1,'email'=>'owner@example.com'];
$site=vp3_cloud_hosting_create_site_v100($owner,[
    'display_name'=>'DNS Site',
    'requested_hostname'=>'demo.sites.example.com',
    'runtime_kind'=>'static',
],1);

$requests=[];
$transport=function(string $url,array $headers,int $timeout) use (&$requests): array {
    $requests[]=['url'=>$url,'headers'=>$headers,'timeout'=>$timeout];
    return [
        'status'=>200,
        'body'=>json_encode([
            'cpanelresult'=>[
                'data'=>[['result'=>1,'reason'=>'Added record']],
                'event'=>['result'=>1],
            ],
        ],JSON_UNESCAPED_SLASHES),
    ];
};

$first=vp3_cloud_hosting_v110_provision_dns($site,'dns-request-1',1,$transport);
if(!empty($first['replayed']))throw new RuntimeException('First provisioning unexpectedly replayed.');
$route=(array)$first['route'];
if(($route['dns_state']??'')!=='provisioned')throw new RuntimeException('DNS route was not provisioned.');
if(($route['hostname']??'')!=='demo.sites.example.com')throw new RuntimeException('Route hostname mismatch.');
if(($route['record_value']??'')!=='hosting-edge.example.net')throw new RuntimeException('Ingress CNAME mismatch.');
if(count($requests)!==1)throw new RuntimeException('Expected exactly one provider call.');
if(!str_contains($requests[0]['url'],'ZoneEdit')||!str_contains($requests[0]['url'],'add_zone_record'))throw new RuntimeException('Expected cPanel ZoneEdit add_zone_record call.');
if(!str_contains($requests[0]['url'],'type=CNAME'))throw new RuntimeException('Expected CNAME provider request.');
if(!str_contains($requests[0]['url'],'name=demo'))throw new RuntimeException('Expected zone-relative cPanel record name.');
if(!in_array('Authorization: cpanel vp3user:CPANEL_SECRET_TOKEN',$requests[0]['headers'],true))throw new RuntimeException('cPanel token auth header missing.');

$replay=vp3_cloud_hosting_v110_provision_dns($site,'dns-request-1',1,$transport);
if(empty($replay['replayed']))throw new RuntimeException('Idempotent replay was not detected.');
if(count($requests)!==1)throw new RuntimeException('Replay made a duplicate provider mutation.');

$reuse=vp3_cloud_hosting_v110_provision_dns($site,'dns-request-2',1,$transport);
if(empty($reuse['reused']))throw new RuntimeException('Existing DNS route was not safely reused for a new request key.');
if(count($requests)!==1)throw new RuntimeException('Existing route reuse made a duplicate provider mutation.');

try{
    vp3_cloud_hosting_v110_begin_operation($testPdo,(int)$site['id'],'dns-request-1','dns.other');
    throw new RuntimeException('Cross-operation idempotency reuse was accepted.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Cross-operation idempotency reuse was accepted.')throw $e;
}

$pending=vp3_cloud_hosting_v110_verify_dns($site,1,fn(string $host)=>[]);
if(($pending['dns_state']??'')!=='pending')throw new RuntimeException('Unresolved DNS should remain pending.');
$verified=vp3_cloud_hosting_v110_verify_dns($site,1,fn(string $host)=>[['type'=>'CNAME','target'=>'HOSTING-EDGE.EXAMPLE.NET.']]);
if(($verified['dns_state']??'')!=='verified')throw new RuntimeException('Matching DNS CNAME was not verified.');

$tls=vp3_cloud_hosting_v110_mark_tls_state($site,'active',1);
if(($tls['tls_state']??'')!=='active')throw new RuntimeException('Cloud-edge TLS state did not activate after DNS verification.');

$site2=vp3_cloud_hosting_create_site_v100($owner,[
    'display_name'=>'Outside Zone',
    'requested_hostname'=>'demo.other-example.com',
    'runtime_kind'=>'static',
],1);
try{
    vp3_cloud_hosting_v110_provision_dns($site2,'outside-zone',1,$transport);
    throw new RuntimeException('Out-of-zone hostname was provisioned.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Out-of-zone hostname was provisioned.')throw $e;
}

$op=$testPdo->query("SELECT response_json,error_message FROM cloud_hosting_provider_operations WHERE request_key='dns-request-1'")->fetch();
$serialized=json_encode($op);
if(str_contains((string)$serialized,'CPANEL_SECRET_TOKEN'))throw new RuntimeException('Provider operation ledger leaked cPanel token.');

$events=$testPdo->query("SELECT event_type,details_json FROM cloud_hosting_site_events ORDER BY id")->fetchAll();
$eventJson=json_encode($events);
if(!str_contains((string)$eventJson,'dns.provisioned')||!str_contains((string)$eventJson,'dns.verified')||!str_contains((string)$eventJson,'tls.active')){
    throw new RuntimeException('Routing lifecycle events are incomplete.');
}
if(str_contains((string)$eventJson,'CPANEL_SECRET_TOKEN'))throw new RuntimeException('Site event ledger leaked cPanel token.');

$public=vp3_cloud_hosting_v110_public_state();
if(($public['dns_provider']??'')!=='cpanel')throw new RuntimeException('Public routing state missing cPanel provider.');
if(($public['tls_authority']??'')!=='cloud_edge'||!empty($public['cpanel_tls_authority']))throw new RuntimeException('TLS authority boundary is incorrect.');
if(!empty($public['homeserver_public_listener']))throw new RuntimeException('HomeServer must not become a public listener.');

echo "Cloud Hosting V1 Section 2 MySQL integration: PASS\n";
