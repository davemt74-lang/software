<?php
declare(strict_types=1);

/**
 * Cloud Hosting V1 Section 3 — Cloud ↔ HomeServer reconciliation and deployment.
 *
 * Cloud remains authoritative for package entitlements, desired site state,
 * public hostname/TLS intent, and deployment requests. HomeServer remains
 * authoritative for local runtime execution, release activation, recovery,
 * SQLite migration, and observed state.
 */

const VP3_CLOUD_HOSTING_V120='cloud-hosting-v120';
const VP3_CLOUD_HOSTING_DEPLOY_CHUNK_BYTES=98304; // 96 KiB; base64 stays under relay payload ceiling.
const VP3_CLOUD_HOSTING_MAX_PACKAGE_BYTES=67108864; // Must match HomeServer's 64 MiB package gate.
const VP3_CLOUD_HOSTING_UNLIMITED_COUNT=2147483647;

function vp3_cloud_hosting_v120_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo
        && table_exists('cloud_hosting_entitlement_sync')
        && table_exists('cloud_hosting_site_sync')
        && table_exists('cloud_hosting_deployments')
        && table_exists('cloud_hosting_edge_certificates')
        && table_exists('cloud_hosting_route_credentials');
}

function vp3_cloud_hosting_v120_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_cloud_hosting_v110_ensure_schema($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS cloud_hosting_entitlement_sync (
      user_id INT UNSIGNED NOT NULL PRIMARY KEY,
      revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
      fingerprint CHAR(64) NOT NULL,
      payload_json LONGTEXT NOT NULL,
      last_remote_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
      last_synced_at DATETIME NULL,
      last_error_code VARCHAR(80) NOT NULL DEFAULT '',
      last_error_message VARCHAR(500) NOT NULL DEFAULT '',
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_cloud_hosting_entitlement_sync_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cloud_hosting_site_sync (
      site_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      last_sent_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
      remote_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
      remote_site_id VARCHAR(100) NOT NULL DEFAULT '',
      observed_state VARCHAR(30) NOT NULL DEFAULT 'pending',
      active_release_id VARCHAR(100) NULL,
      public_route_ready TINYINT(1) NOT NULL DEFAULT 0,
      last_synced_at DATETIME NULL,
      last_error_code VARCHAR(80) NOT NULL DEFAULT '',
      last_error_message VARCHAR(500) NOT NULL DEFAULT '',
      remote_json LONGTEXT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_cloud_hosting_site_sync_site FOREIGN KEY (site_id) REFERENCES cloud_hosting_sites(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cloud_hosting_deployments (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      site_id BIGINT UNSIGNED NOT NULL,
      request_key VARCHAR(160) NOT NULL,
      operation VARCHAR(20) NOT NULL DEFAULT 'deploy',
      desired_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
      package_sha256 CHAR(64) NOT NULL DEFAULT '',
      package_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
      transfer_id VARCHAR(100) NOT NULL DEFAULT '',
      state VARCHAR(30) NOT NULL DEFAULT 'pending',
      release_id VARCHAR(100) NULL,
      response_json LONGTEXT NULL,
      error_code VARCHAR(80) NOT NULL DEFAULT '',
      error_message VARCHAR(500) NOT NULL DEFAULT '',
      created_by INT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      completed_at DATETIME NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_cloud_hosting_deployment_request (site_id,request_key),
      INDEX idx_cloud_hosting_deployment_state (state,created_at,id),
      CONSTRAINT fk_cloud_hosting_deployment_site FOREIGN KEY (site_id) REFERENCES cloud_hosting_sites(id) ON DELETE CASCADE,
      CONSTRAINT fk_cloud_hosting_deployment_actor FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cloud_hosting_edge_certificates (
      site_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      tls_state VARCHAR(30) NOT NULL DEFAULT 'pending',
      certificate_not_after VARCHAR(64) NULL,
      certificate_fingerprint CHAR(64) NOT NULL DEFAULT '',
      observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_cloud_hosting_edge_certificate_site FOREIGN KEY (site_id) REFERENCES cloud_hosting_sites(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS cloud_hosting_route_credentials (
      site_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      revision BIGINT UNSIGNED NOT NULL,
      route_token_enc LONGTEXT NOT NULL,
      route_token_sha256 CHAR(64) NOT NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_cloud_hosting_route_credential_site FOREIGN KEY (site_id) REFERENCES cloud_hosting_sites(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_cloud_hosting_v120_remote(
    int $userId,
    string $operation,
    array $payload=[],
    ?callable $remote=null
): array {
    if($userId<1)throw new RuntimeException('A valid HomeServer owner is required.');
    if($remote!==null){
        $result=$remote($userId,$operation,$payload);
        if(!is_array($result))throw new RuntimeException('HomeServer transport returned an invalid response.');
        return $result;
    }
    return homeserver_vp3_remote_operation_for_user($userId,$operation,$payload);
}

function vp3_cloud_hosting_v120_user(int $userId,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo||$userId<1)throw new RuntimeException('A valid hosting owner is required.');
    $stmt=$pdo->prepare('SELECT * FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$userId]);
    $row=$stmt->fetch();
    if(!is_array($row))throw new RuntimeException('Hosting owner could not be loaded.');
    return $row;
}

function vp3_cloud_hosting_v120_count_limit(array $snapshot,string $key): int
{
    $state=(array)($snapshot['entitlements'][$key]??[]);
    if(empty($state['enabled']))return 0;
    if(!empty($state['unlimited']))return VP3_CLOUD_HOSTING_UNLIMITED_COUNT;
    return max(0,(int)($state['limit']??0));
}

function vp3_cloud_hosting_v120_byte_limit(array $snapshot,string $key): int
{
    $state=(array)($snapshot['entitlements'][$key]??[]);
    if(empty($state['enabled']))return 0;
    if(!empty($state['unlimited']))return PHP_INT_MAX;
    $mb=max(0,(int)($state['limit']??0));
    if($mb>intdiv(PHP_INT_MAX,1048576))return PHP_INT_MAX;
    return $mb*1048576;
}

function vp3_cloud_hosting_v120_entitlement_base(array $user): array
{
    $snapshot=vp3_cloud_hosting_entitlement_snapshot_v100($user);
    $subscription=function_exists('subscription_current_for_user_id')
        ? subscription_current_for_user_id((int)($user['id']??0))
        : null;
    $packageKey=trim((string)($subscription['package_slug']??''));
    if($packageKey==='')$packageKey='unassigned';
    $runtimes=['static'];
    if(!empty($snapshot['entitlements']['hosting.php_access']['enabled']))$runtimes[]='php';
    $sites=vp3_cloud_hosting_v120_count_limit($snapshot,'hosting.sites');
    return [
        'contract'=>'vp3.hosting.entitlements.v1',
        'package_key'=>$packageKey,
        'max_sites'=>$sites,
        'max_active_sites'=>$sites,
        'max_public_routes'=>vp3_cloud_hosting_v120_count_limit($snapshot,'hosting.subdomains'),
        'max_storage_bytes_per_site'=>vp3_cloud_hosting_v120_byte_limit($snapshot,'hosting.storage_mb_per_site'),
        'max_sqlite_bytes_per_site'=>vp3_cloud_hosting_v120_byte_limit($snapshot,'hosting.sqlite_mb_per_site'),
        'allowed_runtimes'=>$runtimes,
    ];
}

function vp3_cloud_hosting_v120_entitlement_payload(array $user,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_cloud_hosting_v120_ensure_schema($pdo);
    $userId=(int)($user['id']??0);
    if($userId<1)throw new RuntimeException('A valid hosting owner is required.');
    $base=vp3_cloud_hosting_v120_entitlement_base($user);
    $fingerprint=hash('sha256',json_encode($base,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare('SELECT revision,fingerprint FROM cloud_hosting_entitlement_sync WHERE user_id=? FOR UPDATE');
        $stmt->execute([$userId]);
        $row=$stmt->fetch();
        if(!is_array($row)){
            $revision=1;
            $stmt=$pdo->prepare('INSERT INTO cloud_hosting_entitlement_sync (user_id,revision,fingerprint,payload_json) VALUES (?,?,?,?)');
            $stmt->execute([$userId,$revision,$fingerprint,json_encode($base,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);
        }elseif(hash_equals((string)$row['fingerprint'],$fingerprint)){
            $revision=max(1,(int)$row['revision']);
        }else{
            $revision=max(1,(int)$row['revision'])+1;
            $stmt=$pdo->prepare('UPDATE cloud_hosting_entitlement_sync SET revision=?,fingerprint=?,payload_json=?,last_error_code=?,last_error_message=? WHERE user_id=?');
            $stmt->execute([$revision,$fingerprint,json_encode($base,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'','',$userId]);
        }
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    return ['revision'=>$revision]+$base;
}

function vp3_cloud_hosting_v120_public_remote(array $payload): array
{
    $blocked=['route_token','bearer_token','authorization','token','secret','credential','password'];
    $clean=[];
    foreach($payload as $key=>$value){
        $lower=strtolower((string)$key);
        if(in_array($lower,$blocked,true)||preg_match('/(?:^|_)(?:token|secret|password|credential|authorization)(?:_|$)/',$lower))continue;
        if(is_scalar($value)||$value===null)$clean[(string)$key]=$value;
        elseif(is_array($value))$clean[(string)$key]=vp3_cloud_hosting_v120_public_remote($value);
    }
    return $clean;
}

function vp3_cloud_hosting_v120_reconcile_entitlements(
    array $user,
    ?callable $remote=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $userId=(int)($user['id']??0);
    $payload=vp3_cloud_hosting_v120_entitlement_payload($user,$pdo);
    try{
        $result=vp3_cloud_hosting_v120_remote($userId,'hosting.entitlements.reconcile',$payload,$remote);
        $remoteRevision=(int)($result['revision']??$payload['revision']);
        $stmt=$pdo->prepare("UPDATE cloud_hosting_entitlement_sync SET last_remote_revision=?,last_synced_at=NOW(),last_error_code='',last_error_message='' WHERE user_id=?");
        $stmt->execute([$remoteRevision,$userId]);
        return $result;
    }catch(Throwable $e){
        $stmt=$pdo->prepare("UPDATE cloud_hosting_entitlement_sync SET last_error_code='remote_error',last_error_message=? WHERE user_id=?");
        $stmt->execute([mb_substr($e->getMessage(),0,500),$userId]);
        throw $e;
    }
}

function vp3_cloud_hosting_v120_edge_certificate(int $siteId,?PDO $pdo=null): ?array
{
    $pdo??=db();if(!$pdo)return null;
    $stmt=$pdo->prepare('SELECT * FROM cloud_hosting_edge_certificates WHERE site_id=? LIMIT 1');
    $stmt->execute([$siteId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function vp3_cloud_hosting_v120_record_edge_certificate(
    array $site,
    string $tlsState,
    ?string $notAfter,
    string $fingerprint='',
    ?int $actorUserId=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_cloud_hosting_v120_ensure_schema($pdo);
    $siteId=(int)($site['id']??0);
    if($siteId<1)throw new RuntimeException('A valid hosted site is required.');
    $tlsState=strtolower(trim($tlsState));
    if(!in_array($tlsState,['pending','active','renewing','failed'],true))throw new RuntimeException('Unsupported Cloud-edge TLS state.');
    $normalizedNotAfter=null;
    if(in_array($tlsState,['active','renewing'],true)){
        $raw=trim((string)$notAfter);
        if($raw==='')throw new RuntimeException('Active Cloud-edge TLS requires a certificate expiration.');
        try{$date=new DateTimeImmutable($raw);}catch(Throwable $e){throw new RuntimeException('Enter a valid Cloud-edge certificate expiration.');}
        if($date->getTimestamp()<=time())throw new RuntimeException('Cloud-edge certificate is expired.');
        $normalizedNotAfter=$date->format(DateTimeInterface::ATOM);
    }
    $fingerprint=strtolower(trim($fingerprint));
    if($fingerprint!==''&&!preg_match('/^[0-9a-f]{64}$/',$fingerprint))throw new RuntimeException('Certificate fingerprint must be SHA-256 hex.');

    $existing=vp3_cloud_hosting_v120_edge_certificate($siteId,$pdo);
    $before=vp3_cloud_hosting_site_v100($siteId,(int)($site['user_id']??0),$pdo);
    if($before===null)throw new RuntimeException('Hosted site could not be loaded.');
    $beforeRevision=(int)$before['desired_revision'];

    vp3_cloud_hosting_v110_mark_tls_state($before,$tlsState,$actorUserId);
    $stmt=$pdo->prepare("INSERT INTO cloud_hosting_edge_certificates (site_id,tls_state,certificate_not_after,certificate_fingerprint,observed_at)
      VALUES (?,?,?,?,NOW())
      ON DUPLICATE KEY UPDATE tls_state=VALUES(tls_state),certificate_not_after=VALUES(certificate_not_after),
        certificate_fingerprint=VALUES(certificate_fingerprint),observed_at=NOW()");
    $stmt->execute([$siteId,$tlsState,$normalizedNotAfter,$fingerprint]);

    $changedCertificate=!is_array($existing)
        || (string)($existing['certificate_not_after']??'')!==(string)$normalizedNotAfter
        || (string)($existing['certificate_fingerprint']??'')!==$fingerprint;
    $after=vp3_cloud_hosting_site_v100($siteId,(int)$site['user_id'],$pdo);
    if($after===null)throw new RuntimeException('Hosted site could not be reloaded.');
    if($changedCertificate&&(int)$after['desired_revision']===$beforeRevision){
        vp3_cloud_hosting_v110_bump_site_route_state($pdo,$siteId,null,null,null);
    }
    $fresh=vp3_cloud_hosting_v120_edge_certificate($siteId,$pdo);
    vp3_cloud_hosting_event_v100($pdo,$siteId,'tls.certificate_observed',$tlsState,vp3_cloud_hosting_v110_site_revision($pdo,$siteId),$actorUserId,[
        'certificate_not_after'=>$normalizedNotAfter,
        'certificate_fingerprint'=>$fingerprint,
        'authority'=>'cloud_edge',
    ]);
    return $fresh??[];
}

function vp3_cloud_hosting_v120_certificate_valid(?array $certificate): bool
{
    if(!$certificate)return false;
    if(!in_array((string)($certificate['tls_state']??''),['active','renewing'],true))return false;
    $raw=trim((string)($certificate['certificate_not_after']??''));
    if($raw==='')return false;
    try{$date=new DateTimeImmutable($raw);}catch(Throwable $e){return false;}
    return $date->getTimestamp()>time();
}

function vp3_cloud_hosting_v120_route_payload(array $site,?PDO $pdo=null): ?array
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $siteId=(int)($site['id']??0);
    $route=vp3_cloud_hosting_v110_route_for_site($siteId,$pdo);
    if($route===null)return null;
    $fresh=vp3_cloud_hosting_site_v100($siteId,(int)($site['user_id']??0),$pdo);
    if($fresh===null)throw new RuntimeException('Hosted site could not be loaded.');
    $certificate=vp3_cloud_hosting_v120_edge_certificate($siteId,$pdo);
    $verified=(string)$route['dns_state']==='verified';
    $tlsValid=vp3_cloud_hosting_v120_certificate_valid($certificate);
    $active=(string)$fresh['desired_state']==='active'&&$verified&&$tlsValid;
    return [
        'cloud_site_id'=>(string)$fresh['site_key'],
        'revision'=>(int)$fresh['desired_revision'],
        'hostname'=>(string)$route['hostname'],
        'desired_state'=>$active?'active':'inactive',
        'hostname_verified'=>$verified,
        'tls_state'=>$certificate?(string)$certificate['tls_state']:(string)$route['tls_state'],
        'certificate_not_after'=>$certificate['certificate_not_after']??null,
        'rotate_token'=>false,
    ];
}

function vp3_cloud_hosting_v120_store_route_token(PDO $pdo,int $siteId,int $revision,string $token): void
{
    $token=trim($token);
    if($token==='')return;
    $encrypted=homeserver_vp3_encrypt($token);
    $sha=hash('sha256',$token);
    $stmt=$pdo->prepare("INSERT INTO cloud_hosting_route_credentials (site_id,revision,route_token_enc,route_token_sha256)
      VALUES (?,?,?,?)
      ON DUPLICATE KEY UPDATE revision=VALUES(revision),route_token_enc=VALUES(route_token_enc),route_token_sha256=VALUES(route_token_sha256)");
    $stmt->execute([$siteId,$revision,$encrypted,$sha]);
}

function vp3_cloud_hosting_v120_route_token(int $siteId,?PDO $pdo=null): string
{
    $pdo??=db();if(!$pdo||$siteId<1)return '';
    $stmt=$pdo->prepare('SELECT route_token_enc FROM cloud_hosting_route_credentials WHERE site_id=? LIMIT 1');
    $stmt->execute([$siteId]);
    $encoded=(string)$stmt->fetchColumn();
    return $encoded!==''?homeserver_vp3_decrypt($encoded):'';
}

function vp3_cloud_hosting_v120_reconcile_route(
    array $site,
    ?callable $remote=null,
    ?PDO $pdo=null
): ?array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $payload=vp3_cloud_hosting_v120_route_payload($site,$pdo);
    if($payload===null)return null;
    $userId=(int)($site['user_id']??0);
    $result=vp3_cloud_hosting_v120_remote($userId,'hosting.route.reconcile',$payload,$remote);
    $token=trim((string)($result['route_token']??''));
    if($token!=='')vp3_cloud_hosting_v120_store_route_token($pdo,(int)$site['id'],(int)$payload['revision'],$token);
    return vp3_cloud_hosting_v120_public_remote($result);
}

function vp3_cloud_hosting_v120_store_site_sync(PDO $pdo,int $siteId,int $revision,array $result,?string $error=null): void
{
    $public=vp3_cloud_hosting_v120_public_remote($result);
    $remoteRevision=(int)($result['revision']??0);
    $remoteSiteId=mb_substr((string)($result['site_id']??''),0,100);
    $observed=mb_substr((string)($result['observed_state']??'pending'),0,30);
    $activeRelease=trim((string)($result['active_release_id']??''));
    $routeReady=!empty($result['public_routing'])||!empty($result['public_route']['route_ready']);
    $stmt=$pdo->prepare("INSERT INTO cloud_hosting_site_sync
      (site_id,last_sent_revision,remote_revision,remote_site_id,observed_state,active_release_id,public_route_ready,last_synced_at,last_error_code,last_error_message,remote_json)
      VALUES (?,?,?,?,?,?,?,NOW(),?,?,?)
      ON DUPLICATE KEY UPDATE last_sent_revision=VALUES(last_sent_revision),remote_revision=VALUES(remote_revision),
        remote_site_id=VALUES(remote_site_id),observed_state=VALUES(observed_state),active_release_id=VALUES(active_release_id),
        public_route_ready=VALUES(public_route_ready),last_synced_at=VALUES(last_synced_at),
        last_error_code=VALUES(last_error_code),last_error_message=VALUES(last_error_message),remote_json=VALUES(remote_json)");
    $stmt->execute([
        $siteId,$revision,$remoteRevision,$remoteSiteId,$observed,$activeRelease!==''?$activeRelease:null,$routeReady?1:0,
        $error!==null?'remote_error':'',$error!==null?mb_substr($error,0,500):'',
        json_encode($public,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
    ]);
}

function vp3_cloud_hosting_v120_reconcile_site(
    array $site,
    ?callable $remote=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_cloud_hosting_v120_ensure_schema($pdo);
    $siteId=(int)($site['id']??0);
    $userId=(int)($site['user_id']??0);
    if($siteId<1||$userId<1)throw new RuntimeException('A valid hosted site is required.');
    $user=vp3_cloud_hosting_v120_user($userId,$pdo);
    vp3_cloud_hosting_v120_reconcile_entitlements($user,$remote,$pdo);
    $fresh=vp3_cloud_hosting_site_v100($siteId,$userId,$pdo);
    if($fresh===null)throw new RuntimeException('Hosted site could not be loaded.');
    $desired=vp3_cloud_hosting_desired_projection_v100($fresh);
    try{
        $result=vp3_cloud_hosting_v120_remote($userId,'hosting.site.reconcile',$desired,$remote);
        $observed=trim((string)($result['observed_state']??'pending'));
        $remoteRevision=(int)($result['revision']??$fresh['desired_revision']);
        $activeRelease=trim((string)($result['active_release_id']??''));
        $stmt=$pdo->prepare("UPDATE cloud_hosting_sites SET observed_state=?,observed_revision=?,active_release_id=?,last_reconciled_at=NOW(),last_error_code='',last_error_message='' WHERE id=?");
        $stmt->execute([$observed,$remoteRevision,$activeRelease!==''?$activeRelease:null,$siteId]);
        vp3_cloud_hosting_v120_store_site_sync($pdo,$siteId,(int)$fresh['desired_revision'],$result);
        $routeResult=vp3_cloud_hosting_v120_reconcile_route($fresh,$remote,$pdo);
        return ['site'=>$result,'route'=>$routeResult];
    }catch(Throwable $e){
        $pdo->prepare("UPDATE cloud_hosting_sites SET last_error_code='homeserver_reconcile_failed',last_error_message=? WHERE id=?")
            ->execute([mb_substr($e->getMessage(),0,500),$siteId]);
        vp3_cloud_hosting_v120_store_site_sync($pdo,$siteId,(int)$fresh['desired_revision'],[],$e->getMessage());
        throw $e;
    }
}

function vp3_cloud_hosting_v120_deployment_row(int $siteId,string $requestKey,?PDO $pdo=null): ?array
{
    $pdo??=db();if(!$pdo)return null;
    $stmt=$pdo->prepare('SELECT * FROM cloud_hosting_deployments WHERE site_id=? AND request_key=? LIMIT 1');
    $stmt->execute([$siteId,$requestKey]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function vp3_cloud_hosting_v120_update_deployment(
    PDO $pdo,
    int $id,
    string $state,
    array $response=[],
    string $error=''
): void {
    $public=vp3_cloud_hosting_v120_public_remote($response);
    $transferId=mb_substr((string)($response['transfer_id']??''),0,100);
    $releaseId=trim((string)($response['release_id']??''));
    $terminal=in_array($state,['deployed','rolled_back','failed'],true);
    $stmt=$pdo->prepare('UPDATE cloud_hosting_deployments SET state=?,transfer_id=IF(?<>"",?,transfer_id),release_id=?,response_json=?,error_code=?,error_message=?,completed_at=IF(?,NOW(),completed_at) WHERE id=?');
    $stmt->execute([
        $state,$transferId,$transferId,$releaseId!==''?$releaseId:null,
        json_encode($public,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        $error!==''?'remote_error':'',mb_substr($error,0,500),$terminal?1:0,$id,
    ]);
}

function vp3_cloud_hosting_v120_deploy_package(
    array $site,
    string $package,
    string $requestKey,
    ?int $actorUserId=null,
    ?callable $remote=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_cloud_hosting_v120_ensure_schema($pdo);
    $siteId=(int)($site['id']??0);
    $userId=(int)($site['user_id']??0);
    if($siteId<1||$userId<1)throw new RuntimeException('A valid hosted site is required.');
    $requestKey=trim($requestKey);
    if($requestKey===''||strlen($requestKey)>160)throw new RuntimeException('A valid deployment idempotency key is required.');
    $size=strlen($package);
    if($size<1||$size>VP3_CLOUD_HOSTING_MAX_PACKAGE_BYTES)throw new RuntimeException('Hosting deployment package must be between 1 byte and 64 MiB.');
    $sha=hash('sha256',$package);
    $fresh=vp3_cloud_hosting_site_v100($siteId,$userId,$pdo);
    if($fresh===null)throw new RuntimeException('Hosted site could not be loaded.');
    $revision=(int)$fresh['desired_revision'];

    $existing=vp3_cloud_hosting_v120_deployment_row($siteId,$requestKey,$pdo);
    if($existing!==null){
        if((string)$existing['operation']!=='deploy'||(string)$existing['package_sha256']!==$sha||(int)$existing['package_bytes']!==$size){
            throw new RuntimeException('Deployment idempotency key was already used for a different operation or package.');
        }
        if((int)$existing['desired_revision']!==$revision){
            throw new RuntimeException('Deployment idempotency key belongs to an older Cloud desired-state revision.');
        }
        if((string)$existing['state']==='deployed')return ['replayed'=>true,'deployment'=>$existing];
        if((string)$existing['state']==='failed')throw new RuntimeException('Failed deployment requires a new idempotency key.');
        $deploymentId=(int)$existing['id'];
    }else{
        $stmt=$pdo->prepare("INSERT INTO cloud_hosting_deployments
          (site_id,request_key,operation,desired_revision,package_sha256,package_bytes,state,created_by)
          VALUES (?,?,'deploy',?,?,?,'pending',?)");
        $stmt->execute([$siteId,$requestKey,$revision,$sha,$size,$actorUserId&&$actorUserId>0?$actorUserId:null]);
        $deploymentId=(int)$pdo->lastInsertId();
    }

    try{
        vp3_cloud_hosting_v120_reconcile_site($fresh,$remote,$pdo);
        $begin=vp3_cloud_hosting_v120_remote($userId,'hosting.deployment.begin',[
            'cloud_site_id'=>(string)$fresh['site_key'],
            'revision'=>$revision,
            'package_sha256'=>$sha,
            'package_bytes'=>$size,
            'request_key'=>$requestKey,
        ],$remote);
        $transferId=trim((string)($begin['transfer_id']??''));
        if($transferId==='')throw new RuntimeException('HomeServer did not return a deployment transfer ID.');
        $state=(string)($begin['state']??'receiving');
        if($state==='deployed'){
            vp3_cloud_hosting_v120_update_deployment($pdo,$deploymentId,'deployed',$begin);
            $pdo->prepare('UPDATE cloud_hosting_sites SET previous_release_id=active_release_id,active_release_id=? WHERE id=?')
                ->execute([trim((string)($begin['release_id']??''))?:null,$siteId]);
            vp3_cloud_hosting_v120_reconcile_site($fresh,$remote,$pdo);
            return ['replayed'=>true,'deployment'=>vp3_cloud_hosting_v120_deployment_row($siteId,$requestKey,$pdo)];
        }
        if($state!=='receiving')throw new RuntimeException('HomeServer deployment transfer is not receiving data.');
        vp3_cloud_hosting_v120_update_deployment($pdo,$deploymentId,'transferring',$begin);

        $totalChunks=(int)ceil($size/VP3_CLOUD_HOSTING_DEPLOY_CHUNK_BYTES);
        $next=max(0,(int)($begin['next_chunk']??0));
        if($next>$totalChunks)throw new RuntimeException('HomeServer returned an invalid deployment chunk cursor.');
        for($index=$next;$index<$totalChunks;$index++){
            $chunk=substr($package,$index*VP3_CLOUD_HOSTING_DEPLOY_CHUNK_BYTES,VP3_CLOUD_HOSTING_DEPLOY_CHUNK_BYTES);
            if($chunk==='')throw new RuntimeException('Deployment chunk generation failed.');
            $chunkResult=vp3_cloud_hosting_v120_remote($userId,'hosting.deployment.chunk',[
                'cloud_site_id'=>(string)$fresh['site_key'],
                'transfer_id'=>$transferId,
                'chunk_index'=>$index,
                'data_b64'=>base64_encode($chunk),
            ],$remote);
            if((int)($chunkResult['next_chunk']??($index+1))!==$index+1){
                throw new RuntimeException('HomeServer returned an invalid deployment chunk acknowledgement.');
            }
        }

        $commit=vp3_cloud_hosting_v120_remote($userId,'hosting.deployment.commit',[
            'cloud_site_id'=>(string)$fresh['site_key'],
            'transfer_id'=>$transferId,
        ],$remote);
        if((string)($commit['state']??'')!=='deployed')throw new RuntimeException('HomeServer did not activate the uploaded deployment.');
        vp3_cloud_hosting_v120_update_deployment($pdo,$deploymentId,'deployed',$commit);
        $releaseId=trim((string)($commit['release_id']??''));
        $pdo->prepare("UPDATE cloud_hosting_sites SET previous_release_id=active_release_id,active_release_id=?,last_error_code='',last_error_message='' WHERE id=?")
            ->execute([$releaseId!==''?$releaseId:null,$siteId]);
        vp3_cloud_hosting_event_v100($pdo,$siteId,'deployment.completed','deployed',$revision,$actorUserId,[
            'release_id'=>$releaseId,'package_sha256'=>$sha,'package_bytes'=>$size,
        ]);
        $post=vp3_cloud_hosting_v120_reconcile_site(vp3_cloud_hosting_site_v100($siteId,$userId,$pdo)??$fresh,$remote,$pdo);
        return ['replayed'=>false,'deployment'=>vp3_cloud_hosting_v120_deployment_row($siteId,$requestKey,$pdo),'reconcile'=>$post];
    }catch(Throwable $e){
        vp3_cloud_hosting_v120_update_deployment($pdo,$deploymentId,'failed',[],$e->getMessage());
        $pdo->prepare("UPDATE cloud_hosting_sites SET last_error_code='deployment_failed',last_error_message=? WHERE id=?")
            ->execute([mb_substr($e->getMessage(),0,500),$siteId]);
        throw $e;
    }
}

function vp3_cloud_hosting_v120_rollback(
    array $site,
    string $requestKey,
    ?int $actorUserId=null,
    ?callable $remote=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_cloud_hosting_v120_ensure_schema($pdo);
    $siteId=(int)($site['id']??0);$userId=(int)($site['user_id']??0);
    if($siteId<1||$userId<1)throw new RuntimeException('A valid hosted site is required.');
    $requestKey=trim($requestKey);
    if($requestKey===''||strlen($requestKey)>160)throw new RuntimeException('A valid rollback idempotency key is required.');
    $fresh=vp3_cloud_hosting_site_v100($siteId,$userId,$pdo);
    if($fresh===null)throw new RuntimeException('Hosted site could not be loaded.');
    $existing=vp3_cloud_hosting_v120_deployment_row($siteId,$requestKey,$pdo);
    if($existing!==null){
        if((string)$existing['operation']!=='rollback')throw new RuntimeException('Rollback idempotency key was already used for a different operation.');
        if((string)$existing['state']==='rolled_back')return ['replayed'=>true,'deployment'=>$existing];
        if((string)$existing['state']==='failed')throw new RuntimeException('Failed rollback requires a new idempotency key.');
        $id=(int)$existing['id'];
    }else{
        $stmt=$pdo->prepare("INSERT INTO cloud_hosting_deployments
          (site_id,request_key,operation,desired_revision,state,created_by)
          VALUES (?,?,'rollback',?,'pending',?)");
        $stmt->execute([$siteId,$requestKey,(int)$fresh['desired_revision'],$actorUserId&&$actorUserId>0?$actorUserId:null]);
        $id=(int)$pdo->lastInsertId();
    }
    try{
        $result=vp3_cloud_hosting_v120_remote($userId,'hosting.deployment.rollback',[
            'cloud_site_id'=>(string)$fresh['site_key'],
            'request_key'=>$requestKey,
        ],$remote);
        $releaseId=trim((string)($result['release_id']??$result['active_release_id']??''));
        vp3_cloud_hosting_v120_update_deployment($pdo,$id,'rolled_back',$result);
        $pdo->prepare('UPDATE cloud_hosting_sites SET previous_release_id=active_release_id,active_release_id=?,last_error_code=?,last_error_message=? WHERE id=?')
            ->execute([$releaseId!==''?$releaseId:null,'','',$siteId]);
        vp3_cloud_hosting_event_v100($pdo,$siteId,'deployment.rolled_back','rolled_back',(int)$fresh['desired_revision'],$actorUserId,[
            'release_id'=>$releaseId,
        ]);
        $post=vp3_cloud_hosting_v120_reconcile_site(vp3_cloud_hosting_site_v100($siteId,$userId,$pdo)??$fresh,$remote,$pdo);
        return ['replayed'=>false,'deployment'=>vp3_cloud_hosting_v120_deployment_row($siteId,$requestKey,$pdo),'reconcile'=>$post];
    }catch(Throwable $e){
        vp3_cloud_hosting_v120_update_deployment($pdo,$id,'failed',[],$e->getMessage());
        throw $e;
    }
}

function vp3_cloud_hosting_v120_public_capability(): array
{
    return [
        'contract'=>'vp3.cloud-hosting-sync.v1',
        'cloud_authoritative_desired_state'=>true,
        'homeserver_authoritative_execution'=>true,
        'entitlement_reconciliation'=>true,
        'monotonic_entitlement_revisions'=>true,
        'site_reconciliation'=>true,
        'route_reconciliation'=>true,
        'encrypted_route_token_storage'=>true,
        'chunked_deployment'=>true,
        'deployment_chunk_bytes'=>VP3_CLOUD_HOSTING_DEPLOY_CHUNK_BYTES,
        'max_package_bytes'=>VP3_CLOUD_HOSTING_MAX_PACKAGE_BYTES,
        'deployment_resume'=>true,
        'deployment_idempotency'=>true,
        'rollback_idempotency'=>true,
        'raw_package_persisted'=>false,
        'cloud_edge_private_key_persisted'=>false,
    ];
}
