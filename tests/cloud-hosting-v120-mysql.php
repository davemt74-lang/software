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

$afterFirstCert=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo);
if((int)($afterFirstCert['desired_revision']??0)!==4)throw new RuntimeException('Initial certificate observation did not advance route revision.');
$renewed=(new DateTimeImmutable('+60 days'))->format(DateTimeInterface::ATOM);
vp3_cloud_hosting_v120_record_edge_certificate($afterFirstCert,'active',$renewed,str_repeat('b',64),1,$testPdo);
$afterRenewal=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo);
if((int)($afterRenewal['desired_revision']??0)!==5)throw new RuntimeException('Same-state certificate renewal did not advance route revision.');

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
    'ent_revision'=>5,
    'site_revision'=>7,
    'deployed'=>false,
    'release'=>'',
    'previous_release'=>'',
    'transfers'=>[],
    'ops'=>[],
    'interrupt_once'=>true,
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
        $incoming=(int)$payload['revision'];
        if($incoming<$remoteState['site_revision']){
            return [
                'contract'=>'vp3.hosting.cloud-control.v1',
                'cloud_site_id'=>$payload['cloud_site_id'],
                'site_id'=>'local_site_1',
                'revision'=>$remoteState['site_revision'],
                'desired_state'=>$payload['desired_state'],
                'observed_state'=>'configured',
                'active_release_id'=>$remoteState['release']?:null,
                'public_routing'=>false,
                'reconcile_result'=>'stale_ignored',
            ];
        }
        $remoteState['site_revision']=$incoming;
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
            if((int)$payload['chunk_index']===1&&!empty($remoteState['interrupt_once'])){
                $remoteState['interrupt_once']=false;
                throw new RuntimeException('Simulated transient HomeServer disconnect.');
            }
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
                'transfer_id'=>$transfer['transfer_id'],'state'=>'applied','next_chunk'=>$transfer['next'],
                'received_bytes'=>strlen($transfer['data']),'package_bytes'=>$transfer['expected_bytes'],
                'release_id'=>'release_1',
            ];
            return $transfer['status'];
        }
        unset($transfer);
        throw new RuntimeException('Unknown transfer.');
    }
    if($operation==='hosting.deployment.status'){
        $wanted=(string)($payload['transfer_id']??'');
        foreach($remoteState['transfers'] as $transfer){
            if($wanted===''||$transfer['transfer_id']===$wanted)return $transfer['status'];
        }
        return ['state'=>'receiving','next_chunk'=>0,'received_bytes'=>0,'package_bytes'=>0];
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
$testPdo->prepare("UPDATE cloud_hosting_sites SET desired_state='active',desired_revision=desired_revision+1,homeserver_user_id=NULL WHERE id=?")->execute([(int)$site['id']]);
$site=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo)??$site;

$pre=vp3_cloud_hosting_v120_reconcile_site($site,$remote,$testPdo);
$boundAfterSync=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo);
if((int)($boundAfterSync['homeserver_user_id']??0)!==1)throw new RuntimeException('Reconciliation did not repair the canonical HomeServer binding.');
if(($pre['site']['blocked_reason']??'')!=='deployment_required')throw new RuntimeException('Active site should be blocked until deployment.');
if(($pre['route']['desired_state']??'')!=='inactive')throw new RuntimeException('Public route must stay inactive until HomeServer runtime is active.');
if(vp3_cloud_hosting_v120_route_token((int)$site['id'],$testPdo)!=='ROUTE_TOKEN_SUPER_SECRET')throw new RuntimeException('Route token was not recoverable from encrypted storage.');
$rebasedSite=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo);
if((int)($rebasedSite['desired_revision']??0)!==8)throw new RuntimeException('Cloud site revision did not rebase above older HomeServer state.');
$entSync=$testPdo->query('SELECT revision,last_remote_revision FROM cloud_hosting_entitlement_sync WHERE user_id=1')->fetch();
if((int)$entSync['revision']!==6||(int)$entSync['last_remote_revision']!==6)throw new RuntimeException('Cloud entitlement revision did not rebase above older HomeServer state.');
$cred=$testPdo->query('SELECT route_token_enc,route_token_sha256 FROM cloud_hosting_route_credentials')->fetch();
if(str_contains((string)$cred['route_token_enc'],'ROUTE_TOKEN_SUPER_SECRET'))throw new RuntimeException('Route token was stored in plaintext.');

$leaseClaim=vp3_cloud_hosting_v120_claim_deployment(
    $testPdo,(int)$site['id'],'lease-test','deploy',(int)$rebasedSite['desired_revision'],str_repeat('c',64),123,1
);
try{
    vp3_cloud_hosting_v120_claim_deployment(
        $testPdo,(int)$site['id'],'lease-test','deploy',(int)$rebasedSite['desired_revision'],str_repeat('c',64),123,1
    );
    throw new RuntimeException('Concurrent same-key deployment lease was not enforced.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Concurrent same-key deployment lease was not enforced.')throw $e;
}
vp3_cloud_hosting_v120_update_deployment($testPdo,(int)$leaseClaim['row']['id'],'failed',[],'lease test complete');

$tmp=tempnam(sys_get_temp_dir(),'vp3-hosting-test-');
if($tmp===false)throw new RuntimeException('Could not create temporary ZIP path.');
$zipPath=$tmp.'.zip';@unlink($tmp);
$zip=new ZipArchive();
if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Could not create test deployment ZIP.');
$zip->addFromString('vp3-hosting.json',json_encode([
    'contract'=>'vp3.hosting.package.v1','version'=>'1.0.0','runtime'=>'static','entrypoint'=>'public/index.html'
],JSON_UNESCAPED_SLASHES));
$zip->addFromString('public/index.html',base64_encode(random_bytes(180000)));
if(method_exists($zip,'setCompressionName'))$zip->setCompressionName('public/index.html',ZipArchive::CM_STORE);
$zip->close();
$package=file_get_contents($zipPath);@unlink($zipPath);
if(!is_string($package))throw new RuntimeException('Could not read test deployment ZIP.');

try{
    vp3_cloud_hosting_v120_deploy_package($site,$package,'deploy-1',1,$remote,$testPdo);
    throw new RuntimeException('Transient deployment interruption was not surfaced.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Transient deployment interruption was not surfaced.')throw $e;
}
$interrupted=vp3_cloud_hosting_v120_deployment_row((int)$site['id'],'deploy-1',$testPdo);
if(($interrupted['state']??'')!=='interrupted')throw new RuntimeException('Transient deployment was not left resumable.');

$result=vp3_cloud_hosting_v120_deploy_package($site,$package,'deploy-1',1,$remote,$testPdo);
$deployment=(array)$result['deployment'];
if(($deployment['state']??'')!=='deployed'||($deployment['release_id']??'')!=='release_1')throw new RuntimeException('Resumed deployment did not complete.');
$chunks=array_values(array_filter($remoteState['ops'],fn(array $op)=>$op['operation']==='hosting.deployment.chunk'));
if(count($chunks)<2)throw new RuntimeException('Deployment did not use bounded chunk transfer.');
foreach($chunks as $op){
    $raw=base64_decode((string)$op['payload']['data_b64'],true);
    if(!is_string($raw)||strlen($raw)>VP3_CLOUD_HOSTING_DEPLOY_CHUNK_BYTES)throw new RuntimeException('Deployment chunk exceeded the Cloud bound.');
}
$afterDeploy=vp3_cloud_hosting_site_v100((int)$site['id'],1,$testPdo);
if(($afterDeploy['active_release_id']??'')!=='release_1')throw new RuntimeException('Cloud active release was not updated.');
if(($afterDeploy['observed_state']??'')!=='active')throw new RuntimeException('Post-deploy reconcile did not activate observed state.');

if(empty($result['reconcile']['route']['route_ready']))throw new RuntimeException('Public route did not become ready after runtime activation.');
if(($result['reconcile']['route']['desired_state']??'')!=='active')throw new RuntimeException('Public route did not activate after deployment.');

$syncBeforeError=$testPdo->query("SELECT observed_state,remote_json FROM cloud_hosting_site_sync WHERE site_id=".(int)$site['id'])->fetch();
vp3_cloud_hosting_v120_store_site_sync($testPdo,(int)$site['id'],(int)$afterDeploy['desired_revision'],[],'simulated reconnect failure');
$syncAfterError=$testPdo->query("SELECT observed_state,remote_json,last_error_code FROM cloud_hosting_site_sync WHERE site_id=".(int)$site['id'])->fetch();
if(($syncAfterError['observed_state']??'')!==($syncBeforeError['observed_state']??''))throw new RuntimeException('Transient sync error erased last observed state.');
if(($syncAfterError['remote_json']??'')!==($syncBeforeError['remote_json']??''))throw new RuntimeException('Transient sync error erased last remote projection.');
if(($syncAfterError['last_error_code']??'')!=='remote_error')throw new RuntimeException('Transient sync error was not recorded.');

if(($result['reconcile']['route']['desired_state']??'')!=='active'||empty($result['reconcile']['route']['route_ready']))throw new RuntimeException('Public route did not activate after runtime became ready.');
$syncRow=$testPdo->query('SELECT public_route_ready FROM cloud_hosting_site_sync WHERE site_id='.(int)$site['id'])->fetch();
if((int)($syncRow['public_route_ready']??0)!==1)throw new RuntimeException('Site sync did not retain public route readiness.');

$refreshed=vp3_cloud_hosting_v120_refresh_deployment($afterDeploy,'deploy-1',$remote,$testPdo);
if(($refreshed['deployment']['state']??'')!=='deployed')throw new RuntimeException('Deployment status refresh lost deployed state.');

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

$entitlements['hosting.access']['enabled']=false;
$opsBeforeDowngrade=count($remoteState['ops']);
try{
    vp3_cloud_hosting_v120_deploy_package($afterRollback,$package,'deploy-blocked',1,$remote,$testPdo);
    throw new RuntimeException('Deployment bypassed current Hosting entitlement.');
}catch(RuntimeException $e){
    if($e->getMessage()==='Deployment bypassed current Hosting entitlement.')throw $e;
}
if(count($remoteState['ops'])!==$opsBeforeDowngrade)throw new RuntimeException('Entitlement-rejected deployment reached HomeServer.');
$entitlements['hosting.access']['enabled']=true;

$cap=vp3_cloud_hosting_v120_public_capability();
if(empty($cap['chunked_deployment'])||empty($cap['deployment_resume'])||empty($cap['transient_failure_resume_with_same_key'])||empty($cap['single_inflight_operation_per_site'])||empty($cap['deployment_execution_lease'])||empty($cap['deployment_status_refresh'])||empty($cap['deployment_entitlement_revalidation'])||empty($cap['encrypted_route_token_storage']))throw new RuntimeException('Section 3 capability projection incomplete.');
if(!empty($cap['raw_package_persisted'])||!empty($cap['cloud_edge_private_key_persisted']))throw new RuntimeException('Section 3 capability violates secret/package boundaries.');

echo "Cloud Hosting V1 Section 3 MySQL integration: PASS\n";
