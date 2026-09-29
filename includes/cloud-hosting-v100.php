<?php
declare(strict_types=1);

/**
 * Cloud Hosting V1 Section 1 — canonical site registry and authority contract.
 *
 * Cloud owns desired hosting state, commercial entitlements, hostname intent,
 * and the binding to a user's canonical HomeServer connection. HomeServer
 * remains authoritative for local execution and observed runtime state.
 */

const VP3_CLOUD_HOSTING_V100 = 'cloud-hosting-v100';

function vp3_cloud_hosting_schema_ready_v100(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo
        && table_exists('cloud_hosting_sites')
        && table_exists('cloud_hosting_site_events');
}


function vp3_cloud_hosting_seed_package_entitlements_v100(PDO $pdo): void
{
    if(!table_exists('subscription_packages')||!table_exists('package_entitlements'))return;
    $stmt=$pdo->query("SELECT id,slug FROM subscription_packages WHERE slug IN ('basic','basic-user')");
    $rows=$stmt?$stmt->fetchAll():[];
    if(!$rows)return;
    $upsert=$pdo->prepare("INSERT IGNORE INTO package_entitlements (package_id,capability_key,is_enabled,limit_value)
      VALUES (?,?,?,?)");
    foreach($rows as $row){
        $id=(int)($row['id']??0);if($id<1)continue;
        $upsert->execute([$id,'hosting.access',1,null]);
        $upsert->execute([$id,'hosting.sites',1,1]);
        $upsert->execute([$id,'hosting.subdomains',1,1]);
    }
}


function vp3_cloud_hosting_ensure_schema_v100(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS cloud_hosting_sites (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      site_key VARCHAR(80) NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      creation_key VARCHAR(160) NULL,
      homeserver_user_id INT UNSIGNED NULL,
      display_name VARCHAR(160) NOT NULL,
      requested_hostname VARCHAR(253) NULL,
      canonical_hostname VARCHAR(253) NULL,
      runtime_kind VARCHAR(20) NOT NULL DEFAULT 'static',
      desired_state VARCHAR(30) NOT NULL DEFAULT 'configured',
      observed_state VARCHAR(30) NOT NULL DEFAULT 'pending',
      desired_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
      observed_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
      storage_limit_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
      sqlite_limit_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
      active_release_id VARCHAR(100) NULL,
      previous_release_id VARCHAR(100) NULL,
      route_state VARCHAR(30) NOT NULL DEFAULT 'inactive',
      tls_state VARCHAR(30) NOT NULL DEFAULT 'pending',
      last_error_code VARCHAR(80) NOT NULL DEFAULT '',
      last_error_message VARCHAR(500) NOT NULL DEFAULT '',
      last_reconciled_at DATETIME NULL,
      metadata_json LONGTEXT NULL,
      created_by INT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_cloud_hosting_site_key (site_key),
      UNIQUE KEY uq_cloud_hosting_user_creation (user_id,creation_key),
      UNIQUE KEY uq_cloud_hosting_user_hostname (user_id,requested_hostname),
      INDEX idx_cloud_hosting_user_state (user_id,desired_state,observed_state,id),
      INDEX idx_cloud_hosting_homeserver (homeserver_user_id,desired_state,id),
      INDEX idx_cloud_hosting_revision (desired_revision,observed_revision,id),
      CONSTRAINT fk_cloud_hosting_site_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      CONSTRAINT fk_cloud_hosting_site_homeserver FOREIGN KEY (homeserver_user_id) REFERENCES homeserver_connections(user_id) ON DELETE SET NULL,
      CONSTRAINT fk_cloud_hosting_site_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if(function_exists('column_exists')&&!column_exists('cloud_hosting_sites','creation_key')){
        $pdo->exec("ALTER TABLE cloud_hosting_sites ADD COLUMN creation_key VARCHAR(160) NULL AFTER user_id");
    }
    $idx=$pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='cloud_hosting_sites' AND index_name='uq_cloud_hosting_user_creation'");
    if($idx&&(int)$idx->fetchColumn()===0){
        $pdo->exec("ALTER TABLE cloud_hosting_sites ADD UNIQUE KEY uq_cloud_hosting_user_creation (user_id,creation_key)");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS cloud_hosting_site_events (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      site_id BIGINT UNSIGNED NOT NULL,
      event_type VARCHAR(80) NOT NULL,
      state VARCHAR(40) NOT NULL DEFAULT '',
      revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
      details_json LONGTEXT NULL,
      actor_user_id INT UNSIGNED NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_cloud_hosting_event_site (site_id,created_at,id),
      INDEX idx_cloud_hosting_event_type (event_type,created_at,id),
      CONSTRAINT fk_cloud_hosting_event_site FOREIGN KEY (site_id) REFERENCES cloud_hosting_sites(id) ON DELETE CASCADE,
      CONSTRAINT fk_cloud_hosting_event_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    vp3_cloud_hosting_seed_package_entitlements_v100($pdo);
}

function vp3_cloud_hosting_site_key_v100(): string
{
    return 'host_'.bin2hex(random_bytes(12));
}

function vp3_cloud_hosting_valid_runtime_v100(string $runtime): bool
{
    return in_array(strtolower(trim($runtime)),['static','php'],true);
}

function vp3_cloud_hosting_valid_state_v100(string $state): bool
{
    return in_array(strtolower(trim($state)),['configured','active','suspended'],true);
}

function vp3_cloud_hosting_normalize_hostname_v100(?string $hostname): ?string
{
    $hostname=strtolower(trim((string)$hostname));
    if($hostname==='')return null;
    if(strlen($hostname)>253||str_contains($hostname,':')||!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/',$hostname)){
        throw new RuntimeException('Enter a valid hosting hostname without a port.');
    }
    return $hostname;
}

function vp3_cloud_hosting_entitlement_v100(array $user,string $key): array
{
    if(function_exists('subscription_effective_entitlement_v340')){
        return subscription_effective_entitlement_v340($user,$key);
    }
    return ['key'=>$key,'enabled'=>false,'limit'=>0,'unlimited'=>false];
}

function vp3_cloud_hosting_entitlement_snapshot_v100(array $user): array
{
    $keys=[
        'hosting.access',
        'hosting.sites',
        'hosting.subdomains',
        'hosting.storage_mb_per_site',
        'hosting.sqlite_mb_per_site',
        'hosting.php_access',
    ];
    $result=['contract'=>'vp3.cloud-hosting-entitlements.v1','user_id'=>(int)($user['id']??0),'entitlements'=>[]];
    foreach($keys as $key)$result['entitlements'][$key]=vp3_cloud_hosting_entitlement_v100($user,$key);
    return $result;
}

function vp3_cloud_hosting_limit_v100(array $snapshot,string $key): ?int
{
    $state=(array)($snapshot['entitlements'][$key]??[]);
    if(empty($state['enabled']))return 0;
    if(!empty($state['unlimited']))return null;
    return max(0,(int)($state['limit']??0));
}

function vp3_cloud_hosting_count_sites_v100(int $userId,?PDO $pdo=null): int
{
    $pdo??=db();if(!$pdo||$userId<1)return 0;
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM cloud_hosting_sites WHERE user_id=?');
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function vp3_cloud_hosting_event_v100(PDO $pdo,int $siteId,string $type,string $state,int $revision,?int $actor,array $details=[]): void
{
    $safe=[];
    foreach($details as $key=>$value){
        $key=(string)$key;
        if(preg_match('/token|secret|password|credential|authorization/i',$key))continue;
        if(is_scalar($value)||$value===null)$safe[$key]=$value;
    }
    $stmt=$pdo->prepare('INSERT INTO cloud_hosting_site_events (site_id,event_type,state,revision,details_json,actor_user_id) VALUES (?,?,?,?,?,?)');
    $stmt->execute([
        $siteId,mb_substr($type,0,80),mb_substr($state,0,40),max(0,$revision),
        json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        $actor&&$actor>0?$actor:null,
    ]);
}

function vp3_cloud_hosting_create_site_v100(array $user,array $input,?int $actorUserId=null): array
{
    $userId=(int)($user['id']??0);
    if($userId<1)throw new RuntimeException('A hosting owner is required.');
    $pdo=db();if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_cloud_hosting_ensure_schema_v100($pdo);

    $snapshot=vp3_cloud_hosting_entitlement_snapshot_v100($user);
    $access=(array)($snapshot['entitlements']['hosting.access']??[]);
    if(empty($access['enabled']))throw new RuntimeException('This account package does not include Cloud Hosting.');

    $siteLimit=vp3_cloud_hosting_limit_v100($snapshot,'hosting.sites');
    if($siteLimit!==null&&vp3_cloud_hosting_count_sites_v100($userId,$pdo)>=$siteLimit){
        throw new RuntimeException('This account has reached its hosted-site limit.');
    }

    $creationKey=trim((string)($input['_creation_key']??''));
    if($creationKey!==''){
        if(strlen($creationKey)>160||!preg_match('/^[A-Za-z0-9._:-]+$/',$creationKey))throw new RuntimeException('Hosted site creation key is invalid.');
        if(function_exists('column_exists')&&column_exists('cloud_hosting_sites','creation_key')){
            $stmt=$pdo->prepare('SELECT * FROM cloud_hosting_sites WHERE user_id=? AND creation_key=? LIMIT 1');
            $stmt->execute([$userId,$creationKey]);
            $existing=$stmt->fetch();
            if(is_array($existing))return $existing;
        }
    }

    $displayName=trim(preg_replace('/\s+/u',' ',(string)($input['display_name']??''))??'');
    if($displayName===''||mb_strlen($displayName)>160)throw new RuntimeException('Enter a hosted site name using 160 characters or fewer.');
    $runtime=strtolower(trim((string)($input['runtime_kind']??'static')));
    if(!vp3_cloud_hosting_valid_runtime_v100($runtime))throw new RuntimeException('Hosting runtime must be static or php.');
    if($runtime==='php'&&empty($snapshot['entitlements']['hosting.php_access']['enabled'])){
        throw new RuntimeException('This account package does not include PHP hosting.');
    }

    $hostname=vp3_cloud_hosting_normalize_hostname_v100($input['requested_hostname']??null);
    if($hostname!==null){
        $subLimit=vp3_cloud_hosting_limit_v100($snapshot,'hosting.subdomains');
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM cloud_hosting_sites WHERE user_id=? AND requested_hostname IS NOT NULL');
        $stmt->execute([$userId]);
        if($subLimit!==null&&(int)$stmt->fetchColumn()>=$subLimit)throw new RuntimeException('This account has reached its hosting subdomain limit.');
    }

    $storageMb=vp3_cloud_hosting_limit_v100($snapshot,'hosting.storage_mb_per_site');
    $sqliteMb=vp3_cloud_hosting_limit_v100($snapshot,'hosting.sqlite_mb_per_site');
    $storageBytes=$storageMb===null?0:max(0,$storageMb)*1024*1024;
    $sqliteBytes=$sqliteMb===null?0:max(0,$sqliteMb)*1024*1024;

    $homeserverUserId=null;
    $stmt=$pdo->prepare("SELECT user_id FROM homeserver_connections WHERE user_id=? AND status IN ('connected','paired','online') LIMIT 1");
    $stmt->execute([$userId]);
    $candidate=(int)$stmt->fetchColumn();
    if($candidate>0)$homeserverUserId=$candidate;

    $siteKey=vp3_cloud_hosting_site_key_v100();
    $stmt=$pdo->prepare("INSERT INTO cloud_hosting_sites
      (site_key,user_id,creation_key,homeserver_user_id,display_name,requested_hostname,runtime_kind,desired_state,observed_state,desired_revision,storage_limit_bytes,sqlite_limit_bytes,created_by,metadata_json)
      VALUES (?,?,?,?,?,?,?,'configured','pending',1,?,?,?,?)");
    try{
        $stmt->execute([
            $siteKey,$userId,$creationKey!==''?$creationKey:null,$homeserverUserId,$displayName,$hostname,$runtime,$storageBytes,$sqliteBytes,
            $actorUserId&&$actorUserId>0?$actorUserId:null,
            json_encode(['contract'=>VP3_CLOUD_HOSTING_V100],JSON_UNESCAPED_SLASHES),
        ]);
    }catch(PDOException $e){
        if($creationKey!==''&&str_contains((string)$e->getCode(),'23000')){
            $retry=$pdo->prepare('SELECT * FROM cloud_hosting_sites WHERE user_id=? AND creation_key=? LIMIT 1');
            $retry->execute([$userId,$creationKey]);
            $existing=$retry->fetch();
            if(is_array($existing))return $existing;
        }
        throw $e;
    }
    $id=(int)$pdo->lastInsertId();
    vp3_cloud_hosting_event_v100($pdo,$id,'site.created','configured',1,$actorUserId,[
        'runtime_kind'=>$runtime,'requested_hostname'=>$hostname,'homeserver_bound'=>$homeserverUserId!==null,
    ]);
    return vp3_cloud_hosting_site_v100($id,$userId,$pdo)??[];
}

function vp3_cloud_hosting_site_v100(int $siteId,int $userId,?PDO $pdo=null): ?array
{
    $pdo??=db();if(!$pdo||$siteId<1||$userId<1)return null;
    $stmt=$pdo->prepare('SELECT * FROM cloud_hosting_sites WHERE id=? AND user_id=? LIMIT 1');
    $stmt->execute([$siteId,$userId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function vp3_cloud_hosting_sites_v100(int $userId,?PDO $pdo=null): array
{
    $pdo??=db();if(!$pdo||$userId<1)return [];
    $stmt=$pdo->prepare('SELECT * FROM cloud_hosting_sites WHERE user_id=? ORDER BY id DESC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll()?:[];
}

function vp3_cloud_hosting_desired_projection_v100(array $site): array
{
    return [
        'contract'=>'vp3.cloud-hosting-site.v1',
        'cloud_site_id'=>(string)($site['site_key']??''),
        'revision'=>(int)($site['desired_revision']??0),
        'display_name'=>(string)($site['display_name']??''),
        'requested_hostname'=>$site['requested_hostname']!==null?(string)$site['requested_hostname']:null,
        'runtime_kind'=>(string)($site['runtime_kind']??'static'),
        'desired_state'=>(string)($site['desired_state']??'configured'),
        'storage_limit_bytes'=>(int)($site['storage_limit_bytes']??0),
        'sqlite_limit_bytes'=>(int)($site['sqlite_limit_bytes']??0),
    ];
}

function vp3_cloud_hosting_normalize_cpanel_server_v100(string $value): string
{
    $value=trim($value);
    if($value==='')return '';
    if(!str_contains($value,'://'))$value='https://'.$value;
    $parts=parse_url($value);
    if(!is_array($parts)||strtolower((string)($parts['scheme']??''))!=='https')throw new RuntimeException('cPanel API server must use HTTPS.');
    $host=strtolower(trim((string)($parts['host']??'')));
    if($host===''||!preg_match('/^[a-z0-9.-]+$/',$host))throw new RuntimeException('Enter a valid cPanel server hostname.');
    if(isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||(!empty($parts['path'])&&$parts['path']!=='/')){
        throw new RuntimeException('Enter only the cPanel HTTPS server address, without credentials, paths, query strings, or fragments.');
    }
    $port=(int)($parts['port']??2083);
    if($port!==2083)throw new RuntimeException('cPanel UAPI must use the secure account port 2083.');
    return 'https://'.$host.':2083';
}


function vp3_cloud_hosting_cpanel_config_v100(): array
{
    $server=trim((string)setting('hosting_cpanel_server',''));
    $username=trim((string)setting('hosting_cpanel_username',''));
    $encrypted=trim((string)setting('hosting_cpanel_api_token',''));
    $token=$encrypted!==''&&function_exists('ai_decrypt_secret')?ai_decrypt_secret($encrypted):'';
    return [
        'server'=>$server,
        'username'=>$username,
        'token'=>$token,
        'configured'=>$server!==''&&$username!==''&&$token!=='',
    ];
}

function vp3_cloud_hosting_cpanel_public_state_v100(): array
{
    $config=vp3_cloud_hosting_cpanel_config_v100();
    return [
        'configured'=>(bool)$config['configured'],
        'server'=>(string)$config['server'],
        'username'=>(string)$config['username'],
        'token_suffix'=>$config['token']!==''?mb_substr((string)$config['token'],-6):'',
    ];
}
