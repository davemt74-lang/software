<?php
declare(strict_types=1);

require_once __DIR__.'/extension-device-auth-v2000.php';

/**
 * VP3 Browser Companion durable device-token authentication v21.00.
 *
 * The extension authenticates once through the normal VP3 website, receives a
 * one-time authorization code, exchanges it for one durable random device
 * token, then sends that device token as its Bearer credential. VP3 remains the
 * source of truth for account state and permissions on every request.
 */
const VP3_EXTENSION_DEVICE_TOKEN_V2100='extension-device-token-v2100-20260919';
const VP3_EXTENSION_DEVICE_CODE_TTL_V2100=300;

function vp3_extension_device_token_schema_ready_v2100(?PDO $pdo=null): bool
{
    $pdo??=db();
    return (bool)$pdo
        && vp3_extension_schema_ready_v2000($pdo)
        && table_exists('extension_device_codes_v2100');
}

function vp3_extension_device_token_require_schema_v2100(?PDO $pdo=null): PDO
{
    $pdo??=db();
    if(!$pdo||!vp3_extension_device_token_schema_ready_v2100($pdo)){
        throw new RuntimeException('Run the VP3 database upgrade to enable Browser Companion device tokens.');
    }
    return $pdo;
}

function vp3_extension_device_token_ensure_schema_v2100(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    vp3_extension_ensure_schema_v2000($pdo);
    if(table_exists('extension_device_codes_v2100'))return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS extension_device_codes_v2100 (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      code_hash CHAR(64) NOT NULL,
      user_id INT UNSIGNED NOT NULL,
      installation_id CHAR(36) NOT NULL,
      device_name VARCHAR(120) NOT NULL,
      browser_family VARCHAR(40) NOT NULL DEFAULT 'Chrome',
      extension_version VARCHAR(40) NOT NULL DEFAULT '',
      redirect_uri VARCHAR(500) NOT NULL,
      state_token VARCHAR(128) NOT NULL,
      request_ip VARCHAR(45) NOT NULL DEFAULT '',
      expires_at DATETIME NOT NULL,
      consumed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_extension_device_code_hash_v2100 (code_hash),
      INDEX idx_extension_device_code_install_v2100 (installation_id,expires_at,consumed_at),
      INDEX idx_extension_device_code_user_v2100 (user_id,created_at),
      CONSTRAINT fk_extension_device_code_user_v2100 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function vp3_extension_allowed_chrome_ids_v2100(): array
{
    $configured=site_config('extension_allowed_origins',[]);
    if(is_string($configured))$configured=preg_split('/\s*,\s*/',trim($configured))?:[];
    $ids=[];
    foreach(is_array($configured)?$configured:[] as $origin){
        if(preg_match('#^chrome-extension://([a-p]{32})$#',trim((string)$origin),$m))$ids[$m[1]]=true;
    }
    return array_keys($ids);
}

function vp3_extension_chrome_origin_valid_v2100(string $origin): bool
{
    return (bool)preg_match('#^chrome-extension://[a-p]{32}$#',trim($origin));
}

function vp3_extension_chrome_id_allowed_v2100(string $extensionId): bool
{
    if(!preg_match('/^[a-p]{32}$/',$extensionId))return false;
    if(in_array($extensionId,vp3_extension_allowed_chrome_ids_v2100(),true))return true;
    return filter_var(site_config('extension_allow_unlisted_chrome_origins',false),FILTER_VALIDATE_BOOL);
}

function vp3_extension_redirect_uri_valid_v2100(string $value): bool
{
    $value=trim($value);
    if($value===''||strlen($value)>500)return false;
    $parts=parse_url($value);
    if(!is_array($parts))return false;
    $scheme=strtolower((string)($parts['scheme']??''));
    $host=strtolower((string)($parts['host']??''));
    $path=(string)($parts['path']??'');
    if($scheme!=='https'||!preg_match('/^([a-p]{32})\.chromiumapp\.org$/',$host,$match))return false;
    if(!vp3_extension_chrome_id_allowed_v2100($match[1]))return false;
    if($path!=='/vp3-connect')return false;
    if(isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])||isset($parts['query'])||isset($parts['fragment']))return false;
    return true;
}

function vp3_extension_state_valid_v2100(string $value): bool
{
    return (bool)preg_match('/^[A-Za-z0-9._~-]{16,128}$/',trim($value));
}

function vp3_extension_device_context_v2100(array $input): array
{
    $installationId=strtolower(trim((string)($input['installation_id']??'')));
    if(!vp3_extension_valid_uuid_v2000($installationId))throw new InvalidArgumentException('A valid installation ID is required.');

    $deviceName=trim((string)($input['device_name']??'Chrome Browser'));
    if($deviceName==='')$deviceName='Chrome Browser';
    if(mb_strlen($deviceName)>120)throw new InvalidArgumentException('Device name is too long.');

    $extensionVersion=trim((string)($input['extension_version']??''));
    if(mb_strlen($extensionVersion)>40)throw new InvalidArgumentException('Extension version is too long.');

    $redirectUri=trim((string)($input['redirect_uri']??''));
    if(!vp3_extension_redirect_uri_valid_v2100($redirectUri))throw new InvalidArgumentException('The Chrome callback URL is invalid.');

    $state=trim((string)($input['state']??''));
    if(!vp3_extension_state_valid_v2100($state))throw new InvalidArgumentException('The connection state is invalid.');

    return [
        'installation_id'=>$installationId,
        'device_name'=>$deviceName,
        'browser_family'=>'Chrome',
        'extension_version'=>$extensionVersion,
        'redirect_uri'=>$redirectUri,
        'state'=>$state,
    ];
}

function vp3_extension_reported_version_v2100(): string
{
    $version=trim((string)($_SERVER['HTTP_X_VP3_EXTENSION_VERSION']??''));
    if($version===''||strlen($version)>40)return '';
    return preg_match('/^\d+(?:\.\d+){1,3}$/',$version)?$version:'';
}

function vp3_extension_device_code_issue_v2100(PDO $pdo,int $userId,array $context): string
{
    vp3_extension_device_token_require_schema_v2100($pdo);
    if($userId<1)throw new RuntimeException('Sign in to connect this browser.');
    $context=vp3_extension_device_context_v2100($context);

    $active=$pdo->prepare('SELECT 1 FROM users WHERE id=? AND is_active=1 LIMIT 1');
    $active->execute([$userId]);
    if(!$active->fetchColumn())throw new RuntimeException('This VP3 account is not active.');

    $code=vp3_extension_secret_v2000();

    // Keep the one-time code table bounded for browsers that reconnect or
    // repeatedly restart authorization. The installation index makes this a
    // narrow cleanup; keep the currently usable code (if any) until it is
    // explicitly consumed below.
    $pdo->prepare("DELETE FROM extension_device_codes_v2100
      WHERE installation_id=?
        AND (consumed_at IS NOT NULL OR expires_at<=NOW())")
      ->execute([$context['installation_id']]);

    $pdo->prepare("UPDATE extension_device_codes_v2100 SET consumed_at=COALESCE(consumed_at,NOW())
      WHERE installation_id=? AND consumed_at IS NULL")
      ->execute([$context['installation_id']]);

    $pdo->prepare("INSERT INTO extension_device_codes_v2100
      (code_hash,user_id,installation_id,device_name,browser_family,extension_version,redirect_uri,state_token,request_ip,expires_at)
      VALUES (?,?,?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 5 MINUTE))")
      ->execute([
          vp3_extension_hash_v2000($code),$userId,$context['installation_id'],$context['device_name'],
          $context['browser_family'],$context['extension_version'],$context['redirect_uri'],$context['state'],
          vp3_extension_request_ip_v2000()
      ]);
    return $code;
}

function vp3_extension_device_code_exchange_v2100(PDO $pdo,string $code,string $installationId,string $origin): array
{
    vp3_extension_device_token_require_schema_v2100($pdo);
    $code=strtolower(trim($code));
    $installationId=strtolower(trim($installationId));
    $origin=trim($origin);
    if(!vp3_extension_chrome_origin_valid_v2100($origin))throw new InvalidArgumentException('The extension origin is invalid.');
    if(!preg_match('/^[a-f0-9]{64}$/',$code)||!vp3_extension_valid_uuid_v2000($installationId)){
        throw new InvalidArgumentException('The browser connection code is invalid.');
    }

    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT * FROM extension_device_codes_v2100
          WHERE code_hash=? AND installation_id=? AND consumed_at IS NULL AND expires_at>NOW()
          LIMIT 1 FOR UPDATE");
        $stmt->execute([vp3_extension_hash_v2000($code),$installationId]);
        $row=$stmt->fetch();
        if(!$row)throw new RuntimeException('This browser connection code is invalid or expired.');

        $redirect=parse_url((string)$row['redirect_uri']);
        $redirectHost=strtolower((string)($redirect['host']??''));
        if(!preg_match('/^([a-p]{32})\.chromiumapp\.org$/',$redirectHost,$originMatch)
            || !hash_equals('chrome-extension://'.$originMatch[1],$origin)){
            throw new RuntimeException('This browser connection code belongs to another extension.');
        }

        $userStmt=$pdo->prepare('SELECT id,display_name,role,is_active FROM users WHERE id=? LIMIT 1 FOR UPDATE');
        $userStmt->execute([(int)$row['user_id']]);
        $user=$userStmt->fetch();
        if(!$user||(int)$user['is_active']!==1)throw new RuntimeException('This VP3 account is not active.');

        $deviceToken=vp3_extension_secret_v2000();
        $tokenHash=vp3_extension_hash_v2000($deviceToken);
        $capabilities=vp3_extension_capabilities_v2000();

        $existing=$pdo->prepare('SELECT id,public_id FROM extension_devices_v2000 WHERE installation_id=? LIMIT 1 FOR UPDATE');
        $existing->execute([$installationId]);
        $device=$existing->fetch();
        if($device){
            $deviceDbId=(int)$device['id'];
            $devicePublicId=(string)$device['public_id'];
            $pdo->prepare("UPDATE extension_sessions_v2000 SET revoked_at=COALESCE(revoked_at,NOW())
              WHERE device_id=? AND revoked_at IS NULL")->execute([$deviceDbId]);
            $pdo->prepare("UPDATE extension_devices_v2000 SET
              user_id=?,device_name=?,browser_family=?,extension_version=?,credential_hash=?,capabilities_json=?,
              device_status='active',approved_at=NOW(),last_used_at=NOW(),revoked_at=NULL,updated_at=NOW()
              WHERE id=?")
              ->execute([
                  (int)$user['id'],(string)$row['device_name'],(string)$row['browser_family'],(string)$row['extension_version'],
                  $tokenHash,json_encode($capabilities,JSON_UNESCAPED_SLASHES),$deviceDbId
              ]);
        }else{
            $devicePublicId=vp3_extension_uuid_v2000();
            $pdo->prepare("INSERT INTO extension_devices_v2000
              (public_id,user_id,installation_id,device_name,browser_family,extension_version,credential_hash,capabilities_json,device_status,approved_at,last_used_at)
              VALUES (?,?,?,?,?,?,?,?,'active',NOW(),NOW())")
              ->execute([
                  $devicePublicId,(int)$user['id'],$installationId,(string)$row['device_name'],(string)$row['browser_family'],
                  (string)$row['extension_version'],$tokenHash,json_encode($capabilities,JSON_UNESCAPED_SLASHES)
              ]);
            $deviceDbId=(int)$pdo->lastInsertId();
        }

        $pdo->prepare('UPDATE extension_device_codes_v2100 SET consumed_at=NOW() WHERE id=? AND consumed_at IS NULL')
            ->execute([(int)$row['id']]);
        $pdo->commit();

        return [
            'device_token'=>$deviceToken,
            'device_id'=>$devicePublicId,
            'user'=>[
                'id'=>(int)$user['id'],
                'display_name'=>(string)$user['display_name'],
                'role'=>(string)($user['role']??''),
            ],
            'capabilities'=>$capabilities,
        ];
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
}

function vp3_extension_device_token_authenticate_v2100(PDO $pdo,string $token=''): ?array
{
    vp3_extension_device_token_require_schema_v2100($pdo);
    $token=$token!==''?$token:vp3_extension_bearer_token_v2000();
    if(!preg_match('/^[a-f0-9]{64}$/',$token))return null;

    $stmt=$pdo->prepare("SELECT d.id device_db_id,d.public_id device_id,d.user_id,d.capabilities_json,d.device_status,d.revoked_at,d.extension_version,
      u.display_name,u.role,u.is_active
      FROM extension_devices_v2000 d
      INNER JOIN users u ON u.id=d.user_id
      WHERE d.credential_hash=? LIMIT 1");
    $stmt->execute([vp3_extension_hash_v2000($token)]);
    $row=$stmt->fetch();
    if(!$row||(string)$row['device_status']!=='active'||!empty($row['revoked_at'])||(int)$row['is_active']!==1)return null;

    $reportedVersion=vp3_extension_reported_version_v2100();
    if($reportedVersion!==''&&!hash_equals((string)($row['extension_version']??''),$reportedVersion)){
        $pdo->prepare("UPDATE extension_devices_v2000
          SET extension_version=?,last_used_at=NOW(),updated_at=NOW()
          WHERE id=?")
          ->execute([$reportedVersion,(int)$row['device_db_id']]);
        $row['extension_version']=$reportedVersion;
    }else{
        // Agent Now and source feeds can make several authenticated requests per
        // minute. last_used_at is operational telemetry, not request-by-request
        // state, so avoid turning every read into a row write/lock.
        $pdo->prepare("UPDATE extension_devices_v2000
          SET last_used_at=NOW(),updated_at=updated_at
          WHERE id=? AND (last_used_at IS NULL OR last_used_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE))")
          ->execute([(int)$row['device_db_id']]);
    }

    return [
        'session_id'=>'device:'.(string)$row['device_id'],
        'device_id'=>(string)$row['device_id'],
        'user_id'=>(int)$row['user_id'],
        'display_name'=>(string)$row['display_name'],
        'role'=>(string)($row['role']??''),
        'extension_version'=>(string)($row['extension_version']??''),
        'capabilities'=>vp3_extension_capability_filter_v2000(vp3_extension_json_array_v2000($row['capabilities_json'])),
        'expires_at'=>null,
        'auth_type'=>'device_token',
    ];
}
