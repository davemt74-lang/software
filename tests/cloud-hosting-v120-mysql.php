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
    $raw=(string)$value;
    return str_starts_with($raw,'hsenc:')?(string)base64_decode(substr($raw,6),true):'';
}
function subscription_current_for_user_id(int $userId): ?array { return ['package_slug'=>'basic-user']; }

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
foreach([
    'cloud_hosting_route_credentials','cloud_hosting_edge_certificates','cloud_hosting_deployments',
    'cloud_hosting_site_sync','cloud_hosting_entitlement_sync','cloud_hosting_provider_operations',
    'cloud_hosting_routes','cloud_hosting_site_events','cloud_hosting_sites','package_entitlements',
    'subscription_packages','homeserver_connections','users'
] as $table)$testPdo->exec("DROP TABLE IF EXISTS `".$table."`");
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
require dirname(__DIR__).'/includes/cloud-hosting-v120.php';

vp3_cloud_hosting_v120_ensure_schema($testPdo);
if(!vp3_cloud_hosting_v120_schema_ready($testPdo))throw new RuntimeException('Section 3 schema is not ready.');

$owner=['id'=>1,'email'=>'owner@example.com'];
$site=vp3_cloud_hosting_create_site_v100($owner,[
    'display_name'=>'Synced Site',
    'requested_hostname'=>'sync.sites.example.com',
    'runtime_kind'=>'static',
],1);

$cpanelTransport=fn(string $url,array $headers,int $timeout)=>[
    'status'=>200,
    'body'=>json_encode(['cpanelresult'=>['data'=>[['result'=>1,'reason'=>'Added']]]],JSON_UNESCAPED_SLASHES),
];
vp3_cloud_hosting_v110_provision_dns($site,'section3-dns',1,$cpanelTransport);
vp3_cloud_hosting_v110_verify_dns($site,1,fn(string $host)=>[['type'=>'CNAME','target'=>'hosting-edge.example.net.']]);
$site=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo)??$site;
$future=(new DateTimeImmutable('+30 days'))->format(DateTimeInterface::ATOM);
vp3_cloud_hosting_v120_record_edge_certificate($site,'active',$future,str_repeat('a',64),1,$testPdo);

$payload1=vp3_cloud_hosting_v120_entitlement_payload($owner,$testPdo);
if((int)$payload1['revision']!==1)throw new RuntimeException('Initial entitlement revision must be 1.');
if(($payload1['package_key']??'')!=='basic-user')throw new RuntimeException('Package slug was not projected.');
if((int)$payload1['max_sites']!==2||(int)$payload1['max_public_routes']!==2)throw new RuntimeException('Count entitlements were not projected.');
if((int)$payload1['max_storage_bytes_per_site']!==250*1048576)throw new RuntimeException('Storage entitlement bytes mismatch.');
if($payload1['allowed_runtimes']!==['static','php'])throw new RuntimeException('Runtime entitlement projection mismatch.');
$payloadAgain=vp3_cloud_hosting_v120_entitlement_payload($owner,$testPdo);
if((int)$payloadAgain['revision']!==1)throw new RuntimeException('Unchanged entitlement fingerprint churned revision.');
$entitlements['hosting.storage_mb_per_site']['limit']=300;
$payload2=vp3_cloud_hosting_v120_entitlement_payload($owner,$testPdo);
if((int)$payload2['revision']!==2)throw new RuntimeException('Changed entitlement did not advance revision.');
$entitlements['hosting.storage_mb_per_site']['limit']=250;
$payload3=vp3_cloud_hosting_v120_entitlement_payload($owner,$testPdo);
if((int)$payload3['revision']!==3)throw new RuntimeException('Restored entitlement fingerprint did not advance revision.');

$remoteState=[
    'ent_revision'=>0,
    'site_revision'=>0,
    'deployed'=>false,
    'release'=>'',
    'previous_release'=>'',
    'transfers'=>[],
    'ops'=>[],
];
$remote=function(int $userId,string $operation,array $payload) use (&$remoteState): array {
    if($userId!==1)throw new RuntimeException('Wrong HomeServer owner.');
    $remoteState['ops'][]=['operation'=>$operation,'payload'=>$payload];
    if($operation==='hosting.entitlements.reconcile'){
        $rev=(int)$payload['revision'];
        if($rev<$remoteState['ent_revision'])return ['revision'=>$remoteState['ent_revision'],'reconcile_result'=>'stale_ignored'];
        $remoteState['ent_revision']=$rev;
        return ['revision'=>$rev,'reconcile_result'=>'applied','configured'=>true];
    }
    if($operation==='hosting.site.reconcile'){
        $remoteState['site_revision']=(int)$payload['revision'];
        $desired=(string)$payload['desired_state'];
        $observed=$desired==='active'&&$remoteState['deployed']?'active':'configured';
        return [
            'contract'=>'vp3.hosting.cloud-control.v1',
            'cloud_site_id'=>$payload['cloud_site_id'],
            'site_id'=>'local_site_1',
            'revision'=>(int)$payload['revision'],
            'desired_state'=>$desired,
            'observed_state'=>$observed,
            'active_release_id'=>$remoteState['release']?:null,
            'public_routing'=>false,
            'blocked_reason'=>$desired==='active'&&!$remoteState['deployed']?'deployment_required':null,
        ];
    }
    if($operation==='hosting.route.reconcile'){
        if((int)$payload['revision']!==$remoteState['site_revision'])throw new RuntimeException('Route revision did not match site revision.');
        return [
            'contract'=>'vp3.hosting.public-route.v1',
            'revision'=>(int)$payload['revision'],
            'hostname'=>$payload['hostname'],
            'desired_state'=>$payload['desired_state'],
            'hostname_verified'=>$payload['hostname_verified'],
            'tls_state'=>$payload['tls_state'],
            'certificate_not_after'=>$payload['certificate_not_after'],
            'route_ready'=>$payload['desired_state']==='active',
            'route_token'=>'ROUTE_TOKEN_SUPER_SECRET',
            'route_token_sha256'=>hash('sha256','ROUTE_TOKEN_SUPER_SECRET'),
        ];
    }
    if($operation==='hosting.deployment.begin'){
        $key=(string)$payload['request_key'];
        if(isset($remoteState['transfers'][$key]))return $remoteState['transfers'][$key]['status'];
        $transfer='transfer_test_'.count($remoteState['transfers']);
        $remoteState['transfers'][$key]=[
            'expected_sha'=>$payload['package_sha256'],'expected_bytes'=>(int)$payload['package_bytes'],
            'data'=>'','next'=>0,'transfer_id'=>$transfer,
            'status'=>[
                'transfer_id'=>$transfer,'state'=>'receiving','next_chunk'=>0,
                'received_bytes'=>0,'package_bytes'=>(int)$payload['package_bytes'],
            ],
        ];
        return $remoteState['transfers'][$key]['status'];
    }
    if($operation==='hosting.deployment.chunk'){
        foreach($remoteState['transfers'] as $key=>&$transfer){
            if($transfer['transfer_id']!==$payload['transfer_id'])continue;
            if((int)$payload['chunk_index']!==$transfer['next'])throw new RuntimeException('Chunk index mismatch.');
            $decoded=base64_decode((string)$payload['data_b64'],true);
            if(!is_string($decoded))throw new RuntimeException('Invalid base64 chunk.');
            $transfer['data'].=$decoded;$transfer['next']++;
            $transfer['status']['next_chunk']=$transfer['next'];
            $transfer['status']['received_bytes']=strlen($transfer['data']);
            return $transfer['status'];
        }
        unset($transfer);
        throw new RuntimeException('Unknown transfer.');
    }
    if($operation==='hosting.deployment.commit'){
        foreach($remoteState['transfers'] as $key=>&$transfer){
            if($transfer['transfer_id']!==$payload['transfer_id'])continue;
            if(strlen($transfer['data'])!==$transfer['expected_bytes']||hash('sha256',$transfer['data'])!==$transfer['expected_sha'])throw new RuntimeException('Package integrity mismatch.');
            $remoteState['previous_release']=$remoteState['release'];
            $remoteState['release']='release_1';
            $remoteState['deployed']=true;
            $transfer['status']=[
                'transfer_id'=>$transfer['transfer_id'],'state'=>'deployed','next_chunk'=>$transfer['next'],
                'received_bytes'=>strlen($transfer['data']),'package_bytes'=>$transfer['expected_bytes'],
                'release_id'=>'release_1',
            ];
            return $transfer['status'];
        }
        unset($transfer);
        throw new RuntimeException('Unknown transfer.');
    }
    if($operation==='hosting.deployment.rollback'){
        $old=$remoteState['release'];
        $remoteState['release']='release_0';
        $remoteState['previous_release']=$old;
        $remoteState['deployed']=true;
        return ['state'=>'applied','release_id'=>'release_0','previous_release_id'=>$old,'request_key'=>$payload['request_key']];
    }
    throw new RuntimeException('Unexpected remote operation '.$operation);
};

$site=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo)??$site;
$testPdo->prepare("UPDATE cloud_hosting_sites SET desired_state='active',desired_revision=desired_revision+1 WHERE id=?")->execute([(int)$site['id']]);
$site=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo)??$site;

$pre=vp3_cloud_hosting_v120_reconcile_site($site,$remote,$testPdo);
if(($pre['site']['blocked_reason']??'')!=='deployment_required')throw new RuntimeException('Active site should be blocked until deployment.');
if(($pre['route']['desired_state']??'')!=='active')throw new RuntimeException('Verified DNS + valid Cloud-edge TLS should reconcile as an active public route.');
if(vp3_cloud_hosting_v120_route_token((int)$site['id'],$testPdo)!=='ROUTE_TOKEN_SUPER_SECRET')throw new RuntimeException('Route token was not recoverable from encrypted storage.');
$cred=$testPdo->query('SELECT route_token_enc,route_token_sha256 FROM cloud_hosting_route_credentials')->fetch();
if(str_contains((string)$cred['route_token_enc'],'ROUTE_TOKEN_SUPER_SECRET'))throw new RuntimeException('Route token was stored in plaintext.');

$package=str_repeat('VP3-PACKAGE-',20000);
$result=vp3_cloud_hosting_v120_deploy_package($site,$package,'deploy-1',1,$remote,$testPdo);
$deployment=(array)$result['deployment'];
if(($deployment['state']??'')!=='deployed'||($deployment['release_id']??'')!=='release_1')throw new RuntimeException('Deployment did not complete.');
$chunks=array_values(array_filter($remoteState['ops'],fn(array $op)=>$op['operation']==='hosting.deployment.chunk'));
if(count($chunks)<2)throw new RuntimeException('Deployment did not use bounded chunk transfer.');
foreach($chunks as $op){
    $raw=base64_decode((string)$op['payload']['data_b64'],true);
    if(!is_string($raw)||strlen($raw)>VP3_CLOUD_HOSTING_DEPLOY_CHUNK_BYTES)throw new RuntimeException('Deployment chunk exceeded the Cloud bound.');
}
$afterDeploy=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo);
if(($afterDeploy['active_release_id']??'')!=='release_1')throw new RuntimeException('Cloud active release was not updated.');
if(($afterDeploy['observed_state']??'')!=='active')throw new RuntimeException('Post-deploy reconcile did not activate observed state.');

$opCount=count($remoteState['ops']);
$replay=vp3_cloud_hosting_v120_deploy_package($afterDeploy,$package,'deploy-1',1,$remote,$testPdo);
if(empty($replay['replayed']))throw new RuntimeException('Deployment replay was not served locally.');
if(count($remoteState['ops'])!==$opCount)throw new RuntimeException('Deployment replay made duplicate remote calls.');

$rollback=vp3_cloud_hosting_v120_rollback($afterDeploy,'rollback-1',1,$remote,$testPdo);
if(($rollback['deployment']['state']??'')!=='rolled_back')throw new RuntimeException('Rollback did not complete.');
$afterRollback=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo);
if(($afterRollback['active_release_id']??'')!=='release_0'||($afterRollback['previous_release_id']??'')!=='release_1')throw new RuntimeException('Cloud rollback release lineage mismatch.');
$rollbackOps=count($remoteState['ops']);
$rollbackReplay=vp3_cloud_hosting_v120_rollback($afterRollback,'rollback-1',1,$remote,$testPdo);
if(empty($rollbackReplay['replayed'])||count($remoteState['ops'])!==$rollbackOps)throw new RuntimeException('Rollback replay was not idempotent.');

$stored=$testPdo->query("SELECT remote_json FROM cloud_hosting_site_sync WHERE site_id=".(int)$site['id'])->fetchColumn();
if(str_contains((string)$stored,'ROUTE_TOKEN_SUPER_SECRET'))throw new RuntimeException('Route token leaked into site sync projection.');
$deployJson=$testPdo->query("SELECT response_json FROM cloud_hosting_deployments WHERE request_key='deploy-1'")->fetchColumn();
if(str_contains((string)$deployJson,$package))throw new RuntimeException('Raw deployment package leaked into deployment ledger.');

$cap=vp3_cloud_hosting_v120_public_capability();
if(empty($cap['chunked_deployment'])||empty($cap['deployment_resume'])||empty($cap['encrypted_route_token_storage']))throw new RuntimeException('Section 3 capability projection incomplete.');
if(!empty($cap['raw_package_persisted'])||!empty($cap['cloud_edge_private_key_persisted']))throw new RuntimeException('Section 3 capability violates secret/package boundaries.');

echo "Cloud Hosting V1 Section 3 MySQL integration: PASS\n";
