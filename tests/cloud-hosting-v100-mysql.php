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
$settings=[];
function setting(string $key,string $default=''): string { global $settings; return (string)($settings[$key]??$default); }
function ai_decrypt_secret(string $value): string { return str_starts_with($value,'enc:')?substr($value,4):''; }

$entitlements=[
    'hosting.access'=>['enabled'=>true,'limit'=>0,'unlimited'=>false],
    'hosting.sites'=>['enabled'=>true,'limit'=>1,'unlimited'=>false],
    'hosting.subdomains'=>['enabled'=>true,'limit'=>1,'unlimited'=>false],
    'hosting.storage_mb_per_site'=>['enabled'=>true,'limit'=>250,'unlimited'=>false],
    'hosting.sqlite_mb_per_site'=>['enabled'=>true,'limit'=>50,'unlimited'=>false],
    'hosting.php_access'=>['enabled'=>false,'limit'=>0,'unlimited'=>false],
];
function subscription_effective_entitlement_v340(?array $user,string $key): array {
    global $entitlements;
    return ['key'=>$key,'base_enabled'=>false,'base_limit'=>null,'grant_count'=>0,'grant_limit'=>0]
        +($entitlements[$key]??['enabled'=>false,'limit'=>0,'unlimited'=>false]);
}

foreach(['cloud_hosting_site_events','cloud_hosting_sites','package_entitlements','subscription_packages','homeserver_connections','users'] as $table){
    $testPdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $testPdo->exec("DROP TABLE IF EXISTS `".$table."`");
    $testPdo->exec("SET FOREIGN_KEY_CHECKS=1");
}
$testPdo->exec("CREATE TABLE users (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  email VARCHAR(190) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("CREATE TABLE homeserver_connections (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  status VARCHAR(30) NOT NULL DEFAULT 'unpaired'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("CREATE TABLE subscription_packages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$testPdo->exec("CREATE TABLE package_entitlements (
  package_id INT UNSIGNED NOT NULL,
  capability_key VARCHAR(120) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  limit_value BIGINT NULL,
  PRIMARY KEY(package_id,capability_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$testPdo->exec("INSERT INTO users(id,email) VALUES (1,'owner@example.com')");
$testPdo->exec("INSERT INTO homeserver_connections(user_id,status) VALUES (1,'connected')");
$testPdo->exec("INSERT INTO subscription_packages(slug) VALUES ('basic-user')");

require dirname(__DIR__).'/includes/cloud-hosting-v100.php';

vp3_cloud_hosting_ensure_schema_v100($testPdo);
if(!vp3_cloud_hosting_schema_ready_v100($testPdo))throw new RuntimeException('Cloud Hosting schema did not become ready.');

$basicId=(int)$testPdo->query("SELECT id FROM subscription_packages WHERE slug='basic-user'")->fetchColumn();
$stmt=$testPdo->prepare("SELECT capability_key,is_enabled,limit_value FROM package_entitlements WHERE package_id=? AND capability_key LIKE 'hosting.%' ORDER BY capability_key");
$stmt->execute([$basicId]);$rows=$stmt->fetchAll();
$map=[];foreach($rows as $row)$map[(string)$row['capability_key']]=$row;
if((int)($map['hosting.access']['is_enabled']??0)!==1)throw new RuntimeException('Basic hosting access was not seeded.');
if((int)($map['hosting.sites']['limit_value']??0)!==1)throw new RuntimeException('Basic hosted-site quantity must be 1.');
if((int)($map['hosting.subdomains']['limit_value']??0)!==1)throw new RuntimeException('Basic subdomain quantity must be 1.');

$userRow=['id'=>1,'email'=>'owner@example.com'];
$site=vp3_cloud_hosting_create_site_v100($userRow,[
    'display_name'=>'My Hosted Site',
    'requested_hostname'=>'demo.example.com',
    'runtime_kind'=>'static',
],1);
if((int)($site['user_id']??0)!==1)throw new RuntimeException('Site owner mismatch.');
if((int)($site['homeserver_user_id']??0)!==1)throw new RuntimeException('Connected HomeServer was not bound.');
if((int)($site['storage_limit_bytes']??0)!==250*1024*1024)throw new RuntimeException('Storage entitlement did not project.');
if((int)($site['sqlite_limit_bytes']??0)!==50*1024*1024)throw new RuntimeException('SQLite entitlement did not project.');

$projection=vp3_cloud_hosting_desired_projection_v100($site);
foreach(['cloud_site_id','revision','display_name','requested_hostname','runtime_kind','desired_state','storage_limit_bytes','sqlite_limit_bytes'] as $key){
    if(!array_key_exists($key,$projection))throw new RuntimeException('Missing desired projection field '.$key);
}

$events=(int)$testPdo->query('SELECT COUNT(*) FROM cloud_hosting_site_events')->fetchColumn();
if($events!==1)throw new RuntimeException('Expected durable site creation event.');

try{
    vp3_cloud_hosting_create_site_v100($userRow,['display_name'=>'Second','runtime_kind'=>'static'],1);
    throw new RuntimeException('Site limit was bypassed.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Site limit was bypassed.')throw $e;
}

try{
    vp3_cloud_hosting_create_site_v100(['id'=>1],['display_name'=>'PHP','runtime_kind'=>'php'],1);
    throw new RuntimeException('PHP entitlement was bypassed.');
}catch(RuntimeException $e){
    if($e->getMessage()==='PHP entitlement was bypassed.')throw $e;
}

if(vp3_cloud_hosting_normalize_hostname_v100('Demo.Example.COM')!=='demo.example.com')throw new RuntimeException('Hostname normalization failed.');
if(vp3_cloud_hosting_normalize_cpanel_server_v100('panel.example.com')!=='https://panel.example.com:2083')throw new RuntimeException('cPanel endpoint normalization failed.');
try{
    vp3_cloud_hosting_normalize_cpanel_server_v100('http://panel.example.com:2082');
    throw new RuntimeException('Insecure cPanel endpoint was accepted.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Insecure cPanel endpoint was accepted.')throw $e;
}

$settings=[
    'hosting_cpanel_server'=>'https://panel.example.com:2083',
    'hosting_cpanel_username'=>'vp3user',
    'hosting_cpanel_api_token'=>'enc:SECRET_TOKEN_123456',
];
$public=vp3_cloud_hosting_cpanel_public_state_v100();
if(empty($public['configured']))throw new RuntimeException('cPanel public state should be configured.');
$serialized=json_encode($public);
if(str_contains((string)$serialized,'SECRET_TOKEN'))throw new RuntimeException('cPanel token leaked in public state.');
if(($public['token_suffix']??'')!=='123456')throw new RuntimeException('cPanel token suffix projection failed.');

echo "Cloud Hosting V1 Section 1 MySQL integration: PASS\n";
