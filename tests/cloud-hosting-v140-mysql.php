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
    'hosting.sites'=>['enabled'=>true,'limit'=>3,'unlimited'=>false],
    'hosting.subdomains'=>['enabled'=>true,'limit'=>3,'unlimited'=>false],
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
require dirname(__DIR__).'/includes/cloud-hosting-ui-v140.php';

vp3_cloud_hosting_v120_ensure_schema($testPdo);
$owner=['id'=>1,'email'=>'owner@example.com','display_name'=>'Owner'];
$other=['id'=>2,'email'=>'other@example.com','display_name'=>'Other'];

$created=vp3_cloud_hosting_ui_v140_execute($owner,'site.create',[
    'display_name'=>'UI Site','requested_hostname'=>'ui.sites.example.com','runtime_kind'=>'static','request_key'=>'ui-create-1'
],null,null,null,null,$testPdo);
$site=(array)$created['site'];
if(($site['display_name']??'')!=='UI Site')throw new RuntimeException('UI site creation failed.');

$replay=vp3_cloud_hosting_ui_v140_execute($owner,'site.create',[
    'display_name'=>'UI Site','requested_hostname'=>'ui.sites.example.com','runtime_kind'=>'static','request_key'=>'ui-create-1'
],null,null,null,null,$testPdo);
if((int)($replay['site']['id']??0)!==(int)$site['id'])throw new RuntimeException('UI site creation was not idempotent.');

try{
    vp3_cloud_hosting_ui_v140_execute($other,'site.reconcile',['site_id'=>(int)$site['id']],null,null,null,null,$testPdo);
    throw new RuntimeException('Cross-user Hosting UI access was allowed.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Cross-user Hosting UI access was allowed.')throw $e;
}

try{
    vp3_cloud_hosting_ui_v140_execute($owner,'dns.provision',['site_id'=>(int)$site['id'],'request_key'=>'dns-1'],null,null,null,null,$testPdo);
    throw new RuntimeException('Consequential Hosting UI action bypassed server confirmation.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Consequential Hosting UI action bypassed server confirmation.')throw $e;
    if(!str_contains($e->getMessage(),'Confirm this consequential'))throw $e;
}

$provider=fn(string $url,array $headers,int $timeout):array=>[
    'status'=>200,'body'=>json_encode(['cpanelresult'=>['data'=>[['result'=>1,'reason'=>'Added']]]])
];
$dns=vp3_cloud_hosting_ui_v140_execute($owner,'dns.provision',[
    'site_id'=>(int)$site['id'],'request_key'=>'dns-1','confirmed'=>'1'
],null,null,$provider,null,$testPdo);
if(($dns['site']['route']['dns_state']??'')!=='provisioned')throw new RuntimeException('UI DNS provisioning failed.');

$verified=vp3_cloud_hosting_ui_v140_execute($owner,'dns.verify',[
    'site_id'=>(int)$site['id']
],null,null,null,fn(string $host):array=>[['type'=>'CNAME','target'=>'hosting-edge.example.net.']],$testPdo);
if(($verified['site']['route']['dns_state']??'')!=='verified')throw new RuntimeException('UI DNS verification failed.');

$fresh=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo);
$future=(new DateTimeImmutable('+30 days'))->format(DateTimeInterface::ATOM);
vp3_cloud_hosting_v120_record_edge_certificate($fresh,'active',$future,str_repeat('a',64),1,$testPdo);

$remoteState=['revision'=>0,'release'=>'','previous'=>'','transfers'=>[]];
$remote=function(int $userId,string $operation,array $payload) use (&$remoteState):array {
    if($userId!==1)throw new RuntimeException('Wrong HomeServer owner.');
    if($operation==='hosting.entitlements.reconcile')return ['revision'=>(int)$payload['revision'],'reconcile_result'=>'applied'];
    if($operation==='hosting.site.reconcile'){
        $remoteState['revision']=(int)$payload['revision'];
        return [
            'site_id'=>'local-ui-site','revision'=>(int)$payload['revision'],
            'desired_state'=>(string)$payload['desired_state'],
            'observed_state'=>(string)$payload['desired_state'],
            'active_release_id'=>$remoteState['release']?:null,
            'public_routing'=>false,'reconcile_result'=>'applied',
        ];
    }
    if($operation==='hosting.route.reconcile'){
        return [
            'revision'=>(int)$payload['revision'],'hostname'=>$payload['hostname'],
            'desired_state'=>$payload['desired_state'],'route_ready'=>$payload['desired_state']==='active',
            'route_token'=>'SECRET_ROUTE_TOKEN',
        ];
    }
    if($operation==='hosting.deployment.begin'){
        $key=(string)$payload['request_key'];
        if(isset($remoteState['transfers'][$key]))return $remoteState['transfers'][$key]['status'];
        $id='transfer_ui_'.count($remoteState['transfers']);
        $remoteState['transfers'][$key]=[
            'id'=>$id,'data'=>'','sha'=>$payload['package_sha256'],'bytes'=>(int)$payload['package_bytes'],'next'=>0,
            'status'=>['transfer_id'=>$id,'state'=>'receiving','next_chunk'=>0,'received_bytes'=>0,'package_bytes'=>(int)$payload['package_bytes']],
        ];
        return $remoteState['transfers'][$key]['status'];
    }
    if($operation==='hosting.deployment.chunk'){
        foreach($remoteState['transfers'] as &$transfer){
            if($transfer['id']!==$payload['transfer_id'])continue;
            $data=base64_decode((string)$payload['data_b64'],true);
            if(!is_string($data))throw new RuntimeException('Invalid chunk');
            $transfer['data'].=$data;$transfer['next']++;
            $transfer['status']['next_chunk']=$transfer['next'];
            $transfer['status']['received_bytes']=strlen($transfer['data']);
            return $transfer['status'];
        }
        unset($transfer);throw new RuntimeException('Unknown transfer');
    }
    if($operation==='hosting.deployment.commit'){
        foreach($remoteState['transfers'] as &$transfer){
            if($transfer['id']!==$payload['transfer_id'])continue;
            if(hash('sha256',$transfer['data'])!==$transfer['sha'])throw new RuntimeException('Checksum mismatch');
            $remoteState['previous']=$remoteState['release'];
            $remoteState['release']='release_ui_1';
            $transfer['status']=['transfer_id'=>$transfer['id'],'state'=>'applied','next_chunk'=>$transfer['next'],'received_bytes'=>strlen($transfer['data']),'package_bytes'=>$transfer['bytes'],'release_id'=>$remoteState['release']];
            return $transfer['status'];
        }
        unset($transfer);throw new RuntimeException('Unknown transfer');
    }
    if($operation==='hosting.deployment.status'){
        foreach($remoteState['transfers'] as $transfer)if($transfer['id']===($payload['transfer_id']??''))return $transfer['status'];
        return ['state'=>'receiving'];
    }
    if($operation==='hosting.deployment.rollback'){
        $old=$remoteState['release'];
        $remoteState['release']=$remoteState['previous']?:'release_ui_0';
        $remoteState['previous']=$old;
        return ['state'=>'applied','release_id'=>$remoteState['release'],'previous_release_id'=>$old];
    }
    throw new RuntimeException('Unexpected HomeServer operation '.$operation);
};

$active=vp3_cloud_hosting_ui_v140_execute($owner,'site.activate',[
    'site_id'=>(int)$site['id'],'confirmed'=>'1'
],null,$remote,null,null,$testPdo);
if(($active['site']['desired_state']??'')!=='active'||($active['site']['observed_state']??'')!=='active')throw new RuntimeException('UI site activation/reconciliation failed.');

$tmp=tempnam(sys_get_temp_dir(),'vp3-ui-');if($tmp===false)throw new RuntimeException('Could not allocate ZIP temp file.');
$zipPath=$tmp.'.zip';@unlink($tmp);
$zip=new ZipArchive();
if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Could not create UI deployment ZIP.');
$zip->addFromString('vp3-hosting.json',json_encode(['contract'=>'vp3.hosting.package.v1','version'=>'1.0.0','runtime'=>'static','entrypoint'=>'public/index.html']));
$zip->addFromString('public/index.html','<h1>UI deployment</h1>');
$zip->close();
$bytes=file_get_contents($zipPath);@unlink($zipPath);
if(!is_string($bytes))throw new RuntimeException('Could not read UI deployment ZIP.');

$deployed=vp3_cloud_hosting_ui_v140_execute($owner,'deployment.deploy',[
    'site_id'=>(int)$site['id'],'request_key'=>'ui-deploy-1','confirmed'=>'1'
],$bytes,$remote,null,null,$testPdo);
if(($deployed['site']['active_release_id']??'')!=='release_ui_1')throw new RuntimeException('UI deployment did not activate HomeServer release.');

$testPdo->prepare("UPDATE cloud_hosting_sites SET previous_release_id='release_ui_0' WHERE id=?")->execute([(int)$site['id']]);
$remoteState['previous']='release_ui_0';
$rolled=vp3_cloud_hosting_ui_v140_execute($owner,'deployment.rollback',[
    'site_id'=>(int)$site['id'],'request_key'=>'ui-rollback-1','confirmed'=>'1'
],null,$remote,null,null,$testPdo);
if(($rolled['site']['active_release_id']??'')!=='release_ui_0')throw new RuntimeException('UI rollback failed.');

$dashboard=vp3_cloud_hosting_ui_v140_dashboard($owner,$testPdo);
if((int)$dashboard['site_count']!==1)throw new RuntimeException('UI dashboard site count mismatch.');
if(($dashboard['entitlements']['sites']??0)!==3||empty($dashboard['entitlements']['php']))throw new RuntimeException('UI entitlement projection mismatch.');
$json=json_encode($dashboard);
foreach(['CPANEL_SECRET_TOKEN','SECRET_ROUTE_TOKEN'] as $secret)if(str_contains((string)$json,$secret))throw new RuntimeException('UI dashboard leaked a secret.');

$cap=vp3_cloud_hosting_ui_v140_capability();
if(empty($cap['canonical_services_only'])||empty($cap['server_enforced_consequential_confirmation'])||empty($cap['deployment_zip_upload']))throw new RuntimeException('UI capability contract is incomplete.');
if(!empty($cap['raw_cpanel_secret_exposed'])||!empty($cap['route_token_exposed'])||!empty($cap['cloud_edge_private_key_exposed']))throw new RuntimeException('UI capability exposes secrets.');

echo "Cloud Hosting V1 Section 5 UI MySQL integration: PASS\n";
