<?php
declare(strict_types=1);

const VP3_CLIENT_FLEET_MAINTENANCE_V160 = 'client-fleet-maintenance-v160-20260922';

function client_fleet_schema_ready_v160(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach([
        'client_fleet_support_policy_v160',
        'client_fleet_version_policy_v160',
        'client_fleet_compatibility_rules_v160',
        'client_fleet_pins_v160',
        'client_fleet_upgrade_paths_v160',
        'client_fleet_maintenance_campaigns_v160',
        'client_fleet_maintenance_members_v160',
    ] as $table){
        if(!client_release_table_ready_v110($pdo,$table))return false;
    }
    return true;
}

function client_fleet_ensure_schema_v160(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    client_release_risk_ensure_schema_v140($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_fleet_support_policy_v160 (
        product VARCHAR(40) NOT NULL,
        channel VARCHAR(16) NOT NULL,
        minimum_supported_version VARCHAR(64) NOT NULL DEFAULT '',
        stale_after_hours INT UNSIGNED NOT NULL DEFAULT 336,
        maintenance_days VARCHAR(32) NOT NULL DEFAULT '1,2,3,4,5,6,7',
        maintenance_window_start CHAR(5) NOT NULL DEFAULT '00:00',
        maintenance_window_end CHAR(5) NOT NULL DEFAULT '23:59',
        default_cohort_percent TINYINT UNSIGNED NOT NULL DEFAULT 25,
        deprecation_warning_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
        updated_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(product,channel)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_fleet_version_policy_v160 (
        product VARCHAR(40) NOT NULL,
        channel VARCHAR(16) NOT NULL,
        version VARCHAR(64) NOT NULL,
        support_status VARCHAR(20) NOT NULL DEFAULT 'supported',
        deprecated_at DATETIME NULL,
        unsupported_at DATETIME NULL,
        notes VARCHAR(1000) NOT NULL DEFAULT '',
        updated_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(product,channel,version),
        INDEX idx_client_fleet_version_status (product,channel,support_status,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_fleet_compatibility_rules_v160 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        browser_min_version VARCHAR(64) NOT NULL DEFAULT '',
        browser_max_version VARCHAR(64) NOT NULL DEFAULT '',
        homeserver_min_version VARCHAR(64) NOT NULL DEFAULT '',
        homeserver_max_version VARCHAR(64) NOT NULL DEFAULT '',
        compatibility_status VARCHAR(20) NOT NULL DEFAULT 'compatible',
        notes VARCHAR(1000) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        updated_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_client_fleet_compatibility_active (is_active,compatibility_status,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_fleet_pins_v160 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product VARCHAR(40) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        scope_key VARCHAR(160) NOT NULL DEFAULT 'account',
        pinned_version VARCHAR(64) NOT NULL,
        reason VARCHAR(500) NOT NULL,
        expires_at DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_client_fleet_pin_scope (user_id,product,scope_key,is_active,expires_at),
        INDEX idx_client_fleet_pin_version (product,pinned_version,is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_fleet_upgrade_paths_v160 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product VARCHAR(40) NOT NULL,
        channel VARCHAR(16) NOT NULL,
        from_min_version VARCHAR(64) NOT NULL DEFAULT '',
        from_max_version VARCHAR(64) NOT NULL DEFAULT '',
        target_release_id BIGINT UNSIGNED NOT NULL,
        intermediate_release_id BIGINT UNSIGNED NULL,
        notes VARCHAR(1000) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        updated_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_client_fleet_upgrade_target (product,channel,target_release_id,is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_fleet_maintenance_campaigns_v160 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product VARCHAR(40) NOT NULL,
        channel VARCHAR(16) NOT NULL,
        target_release_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(180) NOT NULL,
        eligibility_mode VARCHAR(32) NOT NULL DEFAULT 'outdated',
        campaign_state VARCHAR(20) NOT NULL DEFAULT 'draft',
        cohort_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
        window_enforced TINYINT(1) NOT NULL DEFAULT 1,
        created_by_user_id INT UNSIGNED NULL,
        started_at DATETIME NULL,
        completed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_client_fleet_campaign_state (product,channel,campaign_state,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_fleet_maintenance_members_v160 (
        campaign_id BIGINT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        scope_key VARCHAR(160) NOT NULL DEFAULT 'account',
        installed_version VARCHAR(64) NOT NULL DEFAULT '',
        target_release_id BIGINT UNSIGNED NOT NULL,
        final_release_id BIGINT UNSIGNED NOT NULL,
        cohort_bucket TINYINT UNSIGNED NOT NULL,
        maintenance_state VARCHAR(24) NOT NULL DEFAULT 'queued',
        last_seen_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(campaign_id,user_id,scope_key),
        INDEX idx_client_fleet_member_state (campaign_id,maintenance_state,cohort_bucket),
        INDEX idx_client_fleet_member_scope (user_id,scope_key,campaign_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function client_fleet_support_statuses_v160(): array
{
    return ['current','supported','maintenance','deprecated','unsupported'];
}

function client_fleet_default_policy_v160(): array
{
    return [
        'minimum_supported_version'=>'',
        'stale_after_hours'=>336,
        'maintenance_days'=>'1,2,3,4,5,6,7',
        'maintenance_window_start'=>'00:00',
        'maintenance_window_end'=>'23:59',
        'default_cohort_percent'=>25,
        'deprecation_warning_days'=>30,
    ];
}

function client_fleet_policy_v160(PDO $pdo,string $product,string $channel): array
{
    $channel=client_release_channel_v110($channel);
    $defaults=client_fleet_default_policy_v160();
    if(!client_fleet_schema_ready_v160($pdo))return $defaults;
    $stmt=$pdo->prepare('SELECT * FROM client_fleet_support_policy_v160 WHERE product=? AND channel=? LIMIT 1');
    $stmt->execute([$product,$channel]);
    $row=$stmt->fetch();
    return $row?array_merge($defaults,$row):$defaults;
}

function client_fleet_valid_time_v160(string $value): bool
{
    return (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$value);
}

function client_fleet_policy_update_v160(PDO $pdo,string $product,string $channel,array $input,int $actorUserId): array
{
    if(!client_release_product_valid_v110($product))throw new RuntimeException('Unsupported fleet product.');
    $channel=client_release_channel_v110($channel);
    client_fleet_ensure_schema_v160($pdo);
    $minimum=trim((string)($input['minimum_supported_version']??''));
    if($minimum!==''&&!client_release_version_valid_v100($minimum))throw new RuntimeException('Minimum supported version is invalid.');
    $days=trim((string)($input['maintenance_days']??'1,2,3,4,5,6,7'));
    $validDays=[];
    foreach(preg_split('/\s*,\s*/',$days)?:[] as $day){
        $n=(int)$day;if($n>=1&&$n<=7)$validDays[$n]=true;
    }
    if(!$validDays)$validDays=array_fill_keys(range(1,7),true);
    ksort($validDays);$days=implode(',',array_keys($validDays));
    $start=trim((string)($input['maintenance_window_start']??'00:00'));
    $end=trim((string)($input['maintenance_window_end']??'23:59'));
    if(!client_fleet_valid_time_v160($start)||!client_fleet_valid_time_v160($end))throw new RuntimeException('Maintenance window time is invalid.');
    $stale=max(24,min(8760,(int)($input['stale_after_hours']??336)));
    $cohort=max(1,min(100,(int)($input['default_cohort_percent']??25)));
    $warning=max(0,min(365,(int)($input['deprecation_warning_days']??30)));

    $stmt=$pdo->prepare("INSERT INTO client_fleet_support_policy_v160
      (product,channel,minimum_supported_version,stale_after_hours,maintenance_days,maintenance_window_start,
       maintenance_window_end,default_cohort_percent,deprecation_warning_days,updated_by_user_id)
      VALUES (?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE minimum_supported_version=VALUES(minimum_supported_version),
       stale_after_hours=VALUES(stale_after_hours),maintenance_days=VALUES(maintenance_days),
       maintenance_window_start=VALUES(maintenance_window_start),maintenance_window_end=VALUES(maintenance_window_end),
       default_cohort_percent=VALUES(default_cohort_percent),deprecation_warning_days=VALUES(deprecation_warning_days),
       updated_by_user_id=VALUES(updated_by_user_id)");
    $stmt->execute([$product,$channel,$minimum,$stale,$days,$start,$end,$cohort,$warning,$actorUserId>0?$actorUserId:null]);
    client_release_audit_v110($pdo,$actorUserId,$product,null,'fleet_support_policy_update','','',[
        'channel'=>$channel,'minimum_supported_version'=>$minimum,'stale_after_hours'=>$stale,
        'maintenance_days'=>$days,'maintenance_window_start'=>$start,'maintenance_window_end'=>$end,
        'default_cohort_percent'=>$cohort,'deprecation_warning_days'=>$warning
    ]);
    return client_fleet_policy_v160($pdo,$product,$channel);
}

function client_fleet_version_policy_v160(PDO $pdo,string $product,string $channel,string $version): ?array
{
    if(!client_fleet_schema_ready_v160($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM client_fleet_version_policy_v160 WHERE product=? AND channel=? AND version=? LIMIT 1');
    $stmt->execute([$product,client_release_channel_v110($channel),trim($version)]);
    $row=$stmt->fetch();
    return $row?:null;
}

function client_fleet_version_policy_update_v160(PDO $pdo,string $product,string $channel,string $version,array $input,int $actorUserId): array
{
    if(!client_release_product_valid_v110($product))throw new RuntimeException('Unsupported fleet product.');
    $channel=client_release_channel_v110($channel);$version=trim($version);
    if(!client_release_version_valid_v100($version))throw new RuntimeException('Choose a valid client version.');
    $status=strtolower(trim((string)($input['support_status']??'supported')));
    if(!in_array($status,client_fleet_support_statuses_v160(),true))throw new RuntimeException('Choose a valid support status.');
    $knownRelease=client_fleet_release_by_version_v160($pdo,$product,$channel,$version,false);
    if(!$knownRelease)throw new RuntimeException('Version support policy must reference a known release on this channel.');
    if($status==='current'){
        $latest=client_fleet_latest_release_v160($pdo,$product,$channel);
        if(!$latest||(string)$latest['version']!==$version)throw new RuntimeException('Only the current General Availability release can be marked current.');
    }
    $deprecated=trim((string)($input['deprecated_at']??''));
    if($deprecated==='')$deprecated=null;
    else{
        $ts=strtotime($deprecated);if($ts===false)throw new RuntimeException('Choose a valid deprecation date.');
        $deprecated=gmdate('Y-m-d H:i:s',$ts);
    }
    $unsupported=trim((string)($input['unsupported_at']??''));
    if($unsupported==='')$unsupported=null;
    else{
        $ts=strtotime($unsupported);if($ts===false)throw new RuntimeException('Choose a valid unsupported date.');
        $unsupported=gmdate('Y-m-d H:i:s',$ts);
    }
    if($deprecated&&$unsupported&&strtotime($unsupported)<strtotime($deprecated))throw new RuntimeException('Unsupported date cannot precede deprecation date.');
    $notes=mb_strimwidth(trim((string)($input['notes']??'')),0,1000,'');
    client_fleet_ensure_schema_v160($pdo);
    $stmt=$pdo->prepare("INSERT INTO client_fleet_version_policy_v160
      (product,channel,version,support_status,deprecated_at,unsupported_at,notes,updated_by_user_id)
      VALUES (?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE support_status=VALUES(support_status),deprecated_at=VALUES(deprecated_at),
       unsupported_at=VALUES(unsupported_at),notes=VALUES(notes),updated_by_user_id=VALUES(updated_by_user_id)");
    $stmt->execute([$product,$channel,$version,$status,$deprecated,$unsupported,$notes,$actorUserId>0?$actorUserId:null]);
    client_release_audit_v110($pdo,$actorUserId,$product,null,'fleet_version_policy_update','','',$input+[
        'channel'=>$channel,'version'=>$version,'support_status'=>$status
    ]);
    return client_fleet_version_policy_v160($pdo,$product,$channel,$version)??[];
}

function client_fleet_release_by_version_v160(PDO $pdo,string $product,string $channel,string $version,bool $publishedOnly=true): ?array
{
    if(!client_release_product_valid_v110($product)||!client_release_version_valid_v100($version))return null;
    $table=client_release_release_table_v110($product);
    $sql="SELECT * FROM {$table} WHERE channel=? AND version=?";
    if($publishedOnly)$sql.=" AND is_published=1";
    $sql.=" ORDER BY id DESC LIMIT 1";
    $stmt=$pdo->prepare($sql);$stmt->execute([client_release_channel_v110($channel),$version]);
    $row=$stmt->fetch();
    return $row?:null;
}

function client_fleet_latest_release_v160(PDO $pdo,string $product,string $channel): ?array
{
    return client_release_public_release_v110($pdo,$product,client_release_channel_v110($channel));
}

function client_fleet_support_status_v160(PDO $pdo,string $product,string $channel,string $version): array
{
    $channel=client_release_channel_v110($channel);$version=trim($version);
    $policy=client_fleet_policy_v160($pdo,$product,$channel);
    $versionPolicy=$version!==''?client_fleet_version_policy_v160($pdo,$product,$channel,$version):null;
    $latest=client_fleet_latest_release_v160($pdo,$product,$channel);
    $latestVersion=trim((string)($latest['version']??''));
    $status='supported';$reason='Within support policy.';

    if(!client_release_version_valid_v100($version)){
        $status='unsupported';$reason='Installed version is missing or invalid.';
    }elseif($versionPolicy){
        $status=(string)$versionPolicy['support_status'];
        if($status==='supported'&&$latestVersion!==''&&version_compare($version,$latestVersion,'=='))$status='current';
        $deprecatedAt=(string)($versionPolicy['deprecated_at']??'');
        $unsupportedAt=(string)($versionPolicy['unsupported_at']??'');
        if($unsupportedAt!==''&&strtotime($unsupportedAt)!==false&&strtotime($unsupportedAt)<=time()){
            $status='unsupported';$reason='Version reached its unsupported date.';
        }elseif($deprecatedAt!==''&&strtotime($deprecatedAt)!==false&&strtotime($deprecatedAt)<=time()&&$status!=='unsupported'){
            $status='deprecated';$reason='Version reached its deprecation date.';
        }else{
            $reason='Explicit version support policy.';
        }
    }elseif((string)$policy['minimum_supported_version']!==''&&client_release_version_valid_v100((string)$policy['minimum_supported_version'])
        && version_compare($version,(string)$policy['minimum_supported_version'],'<')){
        $status='unsupported';$reason='Installed version is below the minimum supported version.';
    }elseif($latestVersion!==''&&version_compare($version,$latestVersion,'==')){
        $status='current';$reason='Installed version is the current General Availability release.';
    }elseif($latestVersion!==''&&version_compare($version,$latestVersion,'>')){
        $status='maintenance';$reason='Installed version is ahead of the current General Availability release.';
    }
    return ['status'=>$status,'reason'=>$reason,'latest_version'=>$latestVersion,'minimum_supported_version'=>(string)$policy['minimum_supported_version'],'policy'=>$versionPolicy];
}

function client_fleet_is_stale_v160(?string $lastSeen,int $hours): bool
{
    $lastSeen=trim((string)$lastSeen);
    if($lastSeen==='')return true;
    $ts=strtotime($lastSeen);
    return $ts===false||$ts<time()-max(1,$hours)*3600;
}

function client_fleet_active_pin_v160(PDO $pdo,int $userId,string $product,string $scopeKey): ?array
{
    if(!client_fleet_schema_ready_v160($pdo))return null;
    $scopeKey=client_release_scope_key_v110($scopeKey);
    $stmt=$pdo->prepare("SELECT * FROM client_fleet_pins_v160
      WHERE user_id=? AND product=? AND scope_key=? AND is_active=1 AND (expires_at IS NULL OR expires_at>NOW())
      ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId,$product,$scopeKey]);
    $row=$stmt->fetch();
    return $row?:null;
}

function client_fleet_pin_set_v160(PDO $pdo,int $userId,string $product,string $scopeKey,string $version,string $reason,?string $expiresAt,int $actorUserId): array
{
    if($userId<1||!client_release_product_valid_v110($product))throw new RuntimeException('Choose a valid connected client.');
    $scopeKey=client_release_scope_key_v110($scopeKey);
    if(!client_release_scope_authorized_v110($pdo,$userId,$product,$scopeKey))throw new RuntimeException('That client is not connected to this account.');
    $channel=client_release_channel_for_v110($pdo,$userId,$product,$scopeKey);
    $release=client_fleet_release_by_version_v160($pdo,$product,$channel,$version,true);
    if(!$release)throw new RuntimeException('Pinned version must be a published release on the client channel.');
    $compat=client_fleet_target_compatibility_v160($pdo,$product,$userId,$scopeKey,$release);
    if((string)($compat['status']??'unknown')==='incompatible')throw new RuntimeException('That version pin violates an explicit Browser Companion ↔ HomeServer compatibility rule.');
    $reason=mb_strimwidth(trim($reason),0,500,'');
    if($reason==='')throw new RuntimeException('Add a reason for the maintenance exception.');
    $expires=null;
    if($expiresAt!==null&&trim($expiresAt)!==''){
        $ts=strtotime($expiresAt);if($ts===false||$ts<=time())throw new RuntimeException('Pin expiration must be in the future.');
        $expires=gmdate('Y-m-d H:i:s',$ts);
    }
    client_fleet_ensure_schema_v160($pdo);
    $pdo->prepare("UPDATE client_fleet_pins_v160 SET is_active=0 WHERE user_id=? AND product=? AND scope_key=? AND is_active=1")
        ->execute([$userId,$product,$scopeKey]);
    $stmt=$pdo->prepare("INSERT INTO client_fleet_pins_v160
      (product,user_id,scope_key,pinned_version,reason,expires_at,is_active,created_by_user_id)
      VALUES (?,?,?,?,?,?,1,?)");
    $stmt->execute([$product,$userId,$scopeKey,(string)$release['version'],$reason,$expires,$actorUserId>0?$actorUserId:null]);
    $id=(int)$pdo->lastInsertId();
    client_release_audit_v110($pdo,$actorUserId,$product,(int)$release['id'],'fleet_pin_set','','',[
        'pin_id'=>$id,'user_id'=>$userId,'scope_key'=>$scopeKey,'version'=>(string)$release['version'],'expires_at'=>$expires,'reason'=>$reason
    ]);
    return client_fleet_active_pin_v160($pdo,$userId,$product,$scopeKey)??[];
}

function client_fleet_pin_clear_v160(PDO $pdo,int $pinId,int $actorUserId): void
{
    if($pinId<1||!client_fleet_schema_ready_v160($pdo))return;
    $stmt=$pdo->prepare('SELECT * FROM client_fleet_pins_v160 WHERE id=? LIMIT 1');$stmt->execute([$pinId]);$pin=$stmt->fetch();
    if(!$pin)throw new RuntimeException('Fleet pin was not found.');
    $pdo->prepare('UPDATE client_fleet_pins_v160 SET is_active=0 WHERE id=?')->execute([$pinId]);
    client_release_audit_v110($pdo,$actorUserId,(string)$pin['product'],null,'fleet_pin_cleared','','',[
        'pin_id'=>$pinId,'user_id'=>(int)$pin['user_id'],'scope_key'=>(string)$pin['scope_key'],'version'=>(string)$pin['pinned_version']
    ]);
}

function client_fleet_pin_applicable_release_v160(PDO $pdo,string $product,string $channel,int $userId,string $scopeKey): ?array
{
    $pin=client_fleet_active_pin_v160($pdo,$userId,$product,$scopeKey);
    if(!$pin)return null;
    $release=client_fleet_release_by_version_v160($pdo,$product,$channel,(string)$pin['pinned_version'],true);
    if(!$release)return null;
    if(client_release_incident_active_for_release_v130($pdo,$product,(int)$release['id']))return null;
    $release['_rollout']=client_release_rollout_for_v110($pdo,$product,(int)$release['id'],$release);
    $release['_cohort_bucket']=client_release_cohort_bucket_v110($product,(int)$release['id'],$userId,$scopeKey);
    $release['_fleet_pin_id']=(int)$pin['id'];
    $release['_fleet_override']=true;
    return $release;
}

function client_fleet_version_in_range_v160(string $version,string $min='',string $max=''): bool
{
    if(!client_release_version_valid_v100($version))return false;
    if($min!==''&&client_release_version_valid_v100($min)&&version_compare($version,$min,'<'))return false;
    if($max!==''&&client_release_version_valid_v100($max)&&version_compare($version,$max,'>'))return false;
    return true;
}

function client_fleet_compatibility_rules_v160(PDO $pdo): array
{
    if(!client_fleet_schema_ready_v160($pdo))return [];
    return $pdo->query('SELECT * FROM client_fleet_compatibility_rules_v160 ORDER BY is_active DESC,id DESC')->fetchAll()?:[];
}

function client_fleet_compatibility_rule_save_v160(PDO $pdo,array $input,int $actorUserId): array
{
    client_fleet_ensure_schema_v160($pdo);
    $id=max(0,(int)($input['rule_id']??0));
    $fields=[];
    foreach(['browser_min_version','browser_max_version','homeserver_min_version','homeserver_max_version'] as $field){
        $value=trim((string)($input[$field]??''));
        if($value!==''&&!client_release_version_valid_v100($value))throw new RuntimeException('Compatibility version range is invalid.');
        $fields[$field]=$value;
    }
    $status=strtolower(trim((string)($input['compatibility_status']??'compatible')));
    if(!in_array($status,['compatible','warning','incompatible'],true))throw new RuntimeException('Choose a valid compatibility status.');
    $notes=mb_strimwidth(trim((string)($input['notes']??'')),0,1000,'');
    $active=!empty($input['is_active'])?1:0;
    if($id>0){
        $stmt=$pdo->prepare("UPDATE client_fleet_compatibility_rules_v160 SET
          browser_min_version=?,browser_max_version=?,homeserver_min_version=?,homeserver_max_version=?,
          compatibility_status=?,notes=?,is_active=?,updated_by_user_id=? WHERE id=?");
        $stmt->execute([$fields['browser_min_version'],$fields['browser_max_version'],$fields['homeserver_min_version'],$fields['homeserver_max_version'],$status,$notes,$active,$actorUserId>0?$actorUserId:null,$id]);
    }else{
        $stmt=$pdo->prepare("INSERT INTO client_fleet_compatibility_rules_v160
          (browser_min_version,browser_max_version,homeserver_min_version,homeserver_max_version,compatibility_status,notes,is_active,updated_by_user_id)
          VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$fields['browser_min_version'],$fields['browser_max_version'],$fields['homeserver_min_version'],$fields['homeserver_max_version'],$status,$notes,$active,$actorUserId>0?$actorUserId:null]);
        $id=(int)$pdo->lastInsertId();
    }
    client_release_audit_v110($pdo,$actorUserId,'browser_companion',null,'fleet_compatibility_rule_save','','',[
        'rule_id'=>$id,'status'=>$status,'browser_min'=>$fields['browser_min_version'],'browser_max'=>$fields['browser_max_version'],
        'homeserver_min'=>$fields['homeserver_min_version'],'homeserver_max'=>$fields['homeserver_max_version'],'active'=>$active
    ]);
    foreach(client_fleet_compatibility_rules_v160($pdo) as $row)if((int)$row['id']===$id)return $row;
    return [];
}

function client_fleet_compatibility_rule_delete_v160(PDO $pdo,int $ruleId,int $actorUserId): void
{
    if($ruleId<1||!client_fleet_schema_ready_v160($pdo))return;
    $pdo->prepare('DELETE FROM client_fleet_compatibility_rules_v160 WHERE id=?')->execute([$ruleId]);
    client_release_audit_v110($pdo,$actorUserId,'browser_companion',null,'fleet_compatibility_rule_delete','','',['rule_id'=>$ruleId]);
}

function client_fleet_compatibility_v160(PDO $pdo,string $browserVersion,string $homeserverVersion): array
{
    $matches=[];
    foreach(client_fleet_compatibility_rules_v160($pdo) as $rule){
        if(empty($rule['is_active']))continue;
        if(!client_fleet_version_in_range_v160($browserVersion,(string)$rule['browser_min_version'],(string)$rule['browser_max_version']))continue;
        if(!client_fleet_version_in_range_v160($homeserverVersion,(string)$rule['homeserver_min_version'],(string)$rule['homeserver_max_version']))continue;
        $matches[]=$rule;
    }
    if(!$matches)return ['status'=>'unknown','notes'=>'No explicit Browser Companion ↔ HomeServer compatibility rule matches this pair.','rule_id'=>null];
    $rank=['compatible'=>1,'warning'=>2,'incompatible'=>3];
    usort($matches,static fn(array $a,array $b): int=>($rank[(string)$b['compatibility_status']]??0)<=>($rank[(string)$a['compatibility_status']]??0));
    $rule=$matches[0];
    return ['status'=>(string)$rule['compatibility_status'],'notes'=>(string)$rule['notes'],'rule_id'=>(int)$rule['id']];
}

function client_fleet_upgrade_paths_v160(PDO $pdo,string $product,string $channel): array
{
    if(!client_fleet_schema_ready_v160($pdo))return [];
    $stmt=$pdo->prepare('SELECT * FROM client_fleet_upgrade_paths_v160 WHERE product=? AND channel=? ORDER BY is_active DESC,id DESC');
    $stmt->execute([$product,client_release_channel_v110($channel)]);
    return $stmt->fetchAll()?:[];
}

function client_fleet_upgrade_path_save_v160(PDO $pdo,array $input,int $actorUserId): array
{
    $product=(string)($input['product']??'');$channel=client_release_channel_v110((string)($input['channel']??'stable'));
    if(!client_release_product_valid_v110($product))throw new RuntimeException('Unsupported fleet product.');
    $target=max(0,(int)($input['target_release_id']??0));$intermediate=max(0,(int)($input['intermediate_release_id']??0));
    $targetRelease=client_release_release_row_v110($pdo,$product,$target);
    if(!$targetRelease||(string)$targetRelease['channel']!==$channel)throw new RuntimeException('Upgrade target must exist on the selected channel.');
    if($intermediate>0){
        $mid=client_release_release_row_v110($pdo,$product,$intermediate);
        if(!$mid||(string)$mid['channel']!==$channel||empty($mid['is_published']))throw new RuntimeException('Intermediate release must be a published release on the same channel.');
        if(client_release_incident_active_for_release_v130($pdo,$product,$intermediate))throw new RuntimeException('A release under active incident cannot be used as an intermediate maintenance target.');
        if($intermediate===$target)throw new RuntimeException('Intermediate release must differ from the final target.');
    }
    $min=trim((string)($input['from_min_version']??''));$max=trim((string)($input['from_max_version']??''));
    foreach([$min,$max] as $v)if($v!==''&&!client_release_version_valid_v100($v))throw new RuntimeException('Upgrade path version range is invalid.');
    if($min!==''&&$max!==''&&version_compare($min,$max,'>'))throw new RuntimeException('Upgrade path minimum version cannot exceed maximum version.');
    $notes=mb_strimwidth(trim((string)($input['notes']??'')),0,1000,'');$active=!empty($input['is_active'])?1:0;
    client_fleet_ensure_schema_v160($pdo);
    $id=max(0,(int)($input['path_id']??0));
    if($id>0){
        $stmt=$pdo->prepare("UPDATE client_fleet_upgrade_paths_v160 SET product=?,channel=?,from_min_version=?,from_max_version=?,
          target_release_id=?,intermediate_release_id=?,notes=?,is_active=?,updated_by_user_id=? WHERE id=?");
        $stmt->execute([$product,$channel,$min,$max,$target,$intermediate?:null,$notes,$active,$actorUserId>0?$actorUserId:null,$id]);
    }else{
        $stmt=$pdo->prepare("INSERT INTO client_fleet_upgrade_paths_v160
          (product,channel,from_min_version,from_max_version,target_release_id,intermediate_release_id,notes,is_active,updated_by_user_id)
          VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$product,$channel,$min,$max,$target,$intermediate?:null,$notes,$active,$actorUserId>0?$actorUserId:null]);
        $id=(int)$pdo->lastInsertId();
    }
    client_release_audit_v110($pdo,$actorUserId,$product,$target,'fleet_upgrade_path_save','','',[
        'path_id'=>$id,'channel'=>$channel,'from_min'=>$min,'from_max'=>$max,'intermediate_release_id'=>$intermediate?:null,'active'=>$active
    ]);
    foreach(client_fleet_upgrade_paths_v160($pdo,$product,$channel) as $row)if((int)$row['id']===$id)return $row;
    return [];
}

function client_fleet_upgrade_path_delete_v160(PDO $pdo,int $pathId,int $actorUserId): void
{
    if($pathId<1||!client_fleet_schema_ready_v160($pdo))return;
    $stmt=$pdo->prepare('SELECT * FROM client_fleet_upgrade_paths_v160 WHERE id=? LIMIT 1');$stmt->execute([$pathId]);$row=$stmt->fetch();
    if(!$row)throw new RuntimeException('Upgrade path was not found.');
    $pdo->prepare('DELETE FROM client_fleet_upgrade_paths_v160 WHERE id=?')->execute([$pathId]);
    client_release_audit_v110($pdo,$actorUserId,(string)$row['product'],(int)$row['target_release_id'],'fleet_upgrade_path_delete','','',['path_id'=>$pathId]);
}

function client_fleet_upgrade_plan_v160(PDO $pdo,string $product,string $channel,string $installedVersion,int $targetReleaseId): array
{
    $target=client_release_release_row_v110($pdo,$product,$targetReleaseId);
    if(!$target)return ['mode'=>'unavailable','target_release_id'=>0,'intermediate_release_id'=>0];
    foreach(client_fleet_upgrade_paths_v160($pdo,$product,$channel) as $path){
        if(empty($path['is_active'])||(int)$path['target_release_id']!==$targetReleaseId)continue;
        if(!client_fleet_version_in_range_v160($installedVersion,(string)$path['from_min_version'],(string)$path['from_max_version']))continue;
        $mid=(int)($path['intermediate_release_id']??0);
        return ['mode'=>$mid>0?'intermediate':'direct','target_release_id'=>$targetReleaseId,'intermediate_release_id'=>$mid,'path_id'=>(int)$path['id'],'notes'=>(string)$path['notes']];
    }
    return ['mode'=>'direct','target_release_id'=>$targetReleaseId,'intermediate_release_id'=>0,'path_id'=>null,'notes'=>''];
}

function client_fleet_scope_has_active_incident_v160(PDO $pdo,int $userId,string $product,string $scopeKey): bool
{
    if(!client_release_incident_schema_ready_v130($pdo))return false;
    $stmt=$pdo->prepare("SELECT 1 FROM client_release_incident_clients_v130 c
      JOIN client_release_incidents_v130 i ON i.id=c.incident_id
      WHERE c.user_id=? AND c.scope_key=? AND i.product=? AND i.status IN ('open','contained','recovering','monitoring')
      LIMIT 1");
    $stmt->execute([$userId,client_release_scope_key_v110($scopeKey),$product]);
    return (bool)$stmt->fetchColumn();
}

function client_fleet_inventory_v160(PDO $pdo): array
{
    client_fleet_ensure_schema_v160($pdo);
    $out=['browser_companion'=>[],'homeserver'=>[]];

    if(client_release_table_ready_v110($pdo,'extension_devices_v2000')){
        $rows=$pdo->query("SELECT user_id,public_id,device_name,browser_family,extension_version,device_status,last_used_at
          FROM extension_devices_v2000 WHERE device_status='active' AND revoked_at IS NULL ORDER BY user_id,public_id")->fetchAll()?:[];
        foreach($rows as $row){
            $uid=(int)$row['user_id'];$scope=(string)$row['public_id'];
            $channel=client_release_channel_for_v110($pdo,$uid,'browser_companion',$scope);
            $support=client_fleet_support_status_v160($pdo,'browser_companion',$channel,(string)$row['extension_version']);
            $policy=client_fleet_policy_v160($pdo,'browser_companion',$channel);
            $pin=client_fleet_active_pin_v160($pdo,$uid,'browser_companion',$scope);
            $known=client_fleet_release_by_version_v160($pdo,'browser_companion',$channel,(string)$row['extension_version'],false);
            $out['browser_companion'][]=[
                'user_id'=>$uid,'scope_key'=>$scope,'name'=>(string)$row['device_name'],'platform'=>(string)$row['browser_family'],
                'installed_version'=>(string)$row['extension_version'],'channel'=>$channel,'support_status'=>$support['status'],
                'support_reason'=>$support['reason'],'latest_version'=>$support['latest_version'],
                'stale'=>client_fleet_is_stale_v160($row['last_used_at']??null,(int)$policy['stale_after_hours']),
                'last_seen_at'=>$row['last_used_at']??null,'pinned'=>$pin,'incident'=>client_fleet_scope_has_active_incident_v160($pdo,$uid,'browser_companion',$scope),
                'drift'=>$known===null&&client_release_version_valid_v100((string)$row['extension_version'])
            ];
        }
    }

    if(client_release_table_ready_v110($pdo,'homeserver_connections')){
        $rows=$pdo->query("SELECT user_id,device_id,installed_version,status,last_seen_at FROM homeserver_connections
          WHERE homeserver_token_enc IS NOT NULL ORDER BY user_id")->fetchAll()?:[];
        foreach($rows as $row){
            $uid=(int)$row['user_id'];$scope='account';
            $channel=client_release_channel_for_v110($pdo,$uid,'homeserver',$scope);
            $support=client_fleet_support_status_v160($pdo,'homeserver',$channel,(string)$row['installed_version']);
            $policy=client_fleet_policy_v160($pdo,'homeserver',$channel);
            $pin=client_fleet_active_pin_v160($pdo,$uid,'homeserver',$scope);
            $known=client_fleet_release_by_version_v160($pdo,'homeserver',$channel,(string)$row['installed_version'],false);
            $out['homeserver'][]=[
                'user_id'=>$uid,'scope_key'=>$scope,'name'=>(string)($row['device_id']?:'HomeServer'),'platform'=>'Windows HomeServer',
                'installed_version'=>(string)$row['installed_version'],'channel'=>$channel,'support_status'=>$support['status'],
                'support_reason'=>$support['reason'],'latest_version'=>$support['latest_version'],
                'stale'=>client_fleet_is_stale_v160($row['last_seen_at']??null,(int)$policy['stale_after_hours']),
                'last_seen_at'=>$row['last_seen_at']??null,'pinned'=>$pin,'incident'=>client_fleet_scope_has_active_incident_v160($pdo,$uid,'homeserver',$scope),
                'drift'=>$known===null&&client_release_version_valid_v100((string)$row['installed_version'])
            ];
        }
    }
    return $out;
}

function client_fleet_compatibility_summary_v160(PDO $pdo,array $inventory): array
{
    $homeByUser=[];
    foreach((array)($inventory['homeserver']??[]) as $row)$homeByUser[(int)$row['user_id']]=$row;
    $rows=[];$counts=['compatible'=>0,'warning'=>0,'incompatible'=>0,'unknown'=>0];
    foreach((array)($inventory['browser_companion']??[]) as $browser){
        $uid=(int)$browser['user_id'];
        if(!isset($homeByUser[$uid]))continue;
        $home=$homeByUser[$uid];
        $compat=client_fleet_compatibility_v160($pdo,(string)$browser['installed_version'],(string)$home['installed_version']);
        $status=(string)$compat['status'];$counts[$status]=($counts[$status]??0)+1;
        $rows[]=['user_id'=>$uid,'browser'=>$browser,'homeserver'=>$home,'compatibility'=>$compat];
    }
    return ['counts'=>$counts,'rows'=>$rows];
}

function client_fleet_summary_v160(PDO $pdo): array
{
    $inventory=client_fleet_inventory_v160($pdo);
    $summary=[];
    foreach(['browser_companion','homeserver'] as $product){
        $counts=['total'=>0,'current'=>0,'supported'=>0,'maintenance'=>0,'deprecated'=>0,'unsupported'=>0,'stale'=>0,'pinned'=>0,'incident'=>0,'drift'=>0];
        foreach($inventory[$product] as $row){
            $counts['total']++;
            $status=(string)$row['support_status'];if(isset($counts[$status]))$counts[$status]++;
            if(!empty($row['stale']))$counts['stale']++;
            if(!empty($row['pinned']))$counts['pinned']++;
            if(!empty($row['incident']))$counts['incident']++;
            if(!empty($row['drift']))$counts['drift']++;
        }
        $summary[$product]=$counts;
    }
    return ['inventory'=>$inventory,'summary'=>$summary,'compatibility'=>client_fleet_compatibility_summary_v160($pdo,$inventory)];
}

function client_fleet_window_open_v160(array $policy,?DateTimeImmutable $now=null): bool
{
    $now??=new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $days=array_map('intval',preg_split('/\s*,\s*/',(string)($policy['maintenance_days']??''))?:[]);
    if(!in_array((int)$now->format('N'),$days,true))return false;
    $current=$now->format('H:i');$start=(string)($policy['maintenance_window_start']??'00:00');$end=(string)($policy['maintenance_window_end']??'23:59');
    if($start===$end)return true;
    if($start<$end)return $current>=$start&&$current<=$end;
    return $current>=$start||$current<=$end;
}

function client_fleet_campaign_bucket_v160(int $campaignId,int $userId,string $scopeKey): int
{
    $key='maintenance|'.$campaignId.'|'.$userId.'|'.client_release_scope_key_v110($scopeKey);
    return (int)(hexdec(substr(hash('sha256',$key),0,8))%100)+1;
}

function client_fleet_campaigns_v160(PDO $pdo,int $limit=40): array
{
    if(!client_fleet_schema_ready_v160($pdo))return [];
    $limit=max(1,min(100,$limit));
    $rows=$pdo->query("SELECT * FROM client_fleet_maintenance_campaigns_v160 ORDER BY id DESC LIMIT {$limit}")->fetchAll()?:[];
    foreach($rows as &$row){
        $row['_target_release']=client_release_release_row_v110($pdo,(string)$row['product'],(int)$row['target_release_id']);
        $row['_stats']=client_fleet_campaign_stats_v160($pdo,(int)$row['id']);
    }
    unset($row);
    return $rows;
}

function client_fleet_campaign_v160(PDO $pdo,int $campaignId): ?array
{
    if($campaignId<1||!client_fleet_schema_ready_v160($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM client_fleet_maintenance_campaigns_v160 WHERE id=? LIMIT 1');$stmt->execute([$campaignId]);$row=$stmt->fetch();
    return $row?:null;
}

function client_fleet_target_risk_v160(PDO $pdo,string $product,int $releaseId): array
{
    try{return client_release_risk_assess_v140($pdo,$product,$releaseId);}
    catch(Throwable $e){return ['risk_level'=>'critical','risk_score'=>100,'recommendation'=>'stop_and_review','error'=>$e->getMessage()];}
}

function client_fleet_risk_override_v160(PDO $pdo,string $product,int $releaseId): bool
{
    $snapshot=client_release_risk_latest_snapshot_v140($pdo,$product,$releaseId);
    if(!$snapshot||!in_array((string)($snapshot['risk_level']??''),['high','critical'],true))return false;
    $assessedAt=strtotime((string)($snapshot['assessed_at']??''));
    if($assessedAt===false||$assessedAt<time()-86400)return false;
    $stmt=$pdo->prepare("SELECT 1 FROM client_release_risk_reviews_v140
      WHERE product=? AND release_id=? AND risk_snapshot_id=? AND decision='accepted_risk'
      ORDER BY id DESC LIMIT 1");
    $stmt->execute([$product,$releaseId,(int)$snapshot['id']]);
    return (bool)$stmt->fetchColumn();
}

function client_fleet_max_cohort_for_target_v160(PDO $pdo,string $product,int $releaseId): int
{
    $risk=client_fleet_target_risk_v160($pdo,$product,$releaseId);
    return match((string)($risk['risk_level']??'critical')){
        'low'=>100,
        'moderate'=>50,
        'high'=>10,
        'critical'=>client_fleet_risk_override_v160($pdo,$product,$releaseId)?10:0,
        default=>0,
    };
}

function client_fleet_optional_readiness_v160(PDO $pdo,string $product,int $releaseId): array
{
    if(function_exists('client_release_readiness_current_v150')){
        try{
            $result=client_release_readiness_current_v150($pdo,$product,$releaseId);
            return is_array($result)?$result:['ready'=>false,'reason'=>'v1.50 readiness returned an invalid result.'];
        }catch(Throwable $e){return ['ready'=>false,'reason'=>$e->getMessage()];}
    }
    return ['ready'=>true,'skipped'=>true,'reason'=>'v1.50 readiness is not installed; v1.60 uses GA lifecycle and v1.40 risk controls.'];
}

function client_fleet_eligible_for_mode_v160(array $row,string $mode,string $targetVersion): bool
{
    return match($mode){
        'unsupported'=>(string)$row['support_status']==='unsupported',
        'deprecated'=>in_array((string)$row['support_status'],['deprecated','unsupported'],true),
        'maintenance'=>in_array((string)$row['support_status'],['maintenance','deprecated','unsupported'],true),
        'stale'=>!empty($row['stale']),
        'all_supported_old'=>client_release_version_valid_v100((string)$row['installed_version'])&&client_release_version_valid_v100($targetVersion)
            &&version_compare((string)$row['installed_version'],$targetVersion,'<'),
        default=>client_release_version_valid_v100((string)$row['installed_version'])&&client_release_version_valid_v100($targetVersion)
            &&version_compare((string)$row['installed_version'],$targetVersion,'<'),
    };
}

function client_fleet_target_compatibility_v160(PDO $pdo,string $product,int $userId,string $scopeKey,array $targetRelease): array
{
    $targetVersion=(string)($targetRelease['version']??'');
    if($product==='browser_companion'){
        if(!client_release_table_ready_v110($pdo,'homeserver_connections'))return ['status'=>'unknown','notes'=>'No HomeServer telemetry table.'];
        $stmt=$pdo->prepare("SELECT installed_version FROM homeserver_connections WHERE user_id=? AND homeserver_token_enc IS NOT NULL LIMIT 1");
        $stmt->execute([$userId]);
        $home=trim((string)$stmt->fetchColumn());
        if($home==='')return ['status'=>'unknown','notes'=>'No paired HomeServer version for this account.'];
        return client_fleet_compatibility_v160($pdo,$targetVersion,$home);
    }
    if(!client_release_table_ready_v110($pdo,'extension_devices_v2000'))return ['status'=>'unknown','notes'=>'No Browser Companion telemetry table.'];
    $stmt=$pdo->prepare("SELECT extension_version FROM extension_devices_v2000 WHERE user_id=? AND device_status='active' AND revoked_at IS NULL");
    $stmt->execute([$userId]);
    $worst=['status'=>'unknown','notes'=>'No active Browser Companion version for this account.'];
    $rank=['unknown'=>0,'compatible'=>1,'warning'=>2,'incompatible'=>3];
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $browserVersion){
        $compat=client_fleet_compatibility_v160($pdo,(string)$browserVersion,$targetVersion);
        if(($rank[(string)$compat['status']]??0)>($rank[(string)$worst['status']]??0))$worst=$compat;
    }
    return $worst;
}

function client_fleet_release_compatible_for_scope_v160(PDO $pdo,string $product,int $userId,string $scopeKey,array $release): bool
{
    if(!client_fleet_schema_ready_v160($pdo))return true;
    $compat=client_fleet_target_compatibility_v160($pdo,$product,$userId,$scopeKey,$release);
    return (string)($compat['status']??'unknown')!=='incompatible';
}

function client_fleet_maintenance_governs_scope_v160(PDO $pdo,string $product,string $channel,int $userId,string $scopeKey): bool
{
    if(!client_fleet_schema_ready_v160($pdo))return false;
    $stmt=$pdo->prepare("SELECT 1 FROM client_fleet_maintenance_campaigns_v160 c
      JOIN client_fleet_maintenance_members_v160 m ON m.campaign_id=c.id
      WHERE c.product=? AND c.channel=? AND c.campaign_state IN ('active','paused')
        AND m.user_id=? AND m.scope_key=? AND m.maintenance_state<>'installed'
      LIMIT 1");
    $stmt->execute([$product,client_release_channel_v110($channel),$userId,client_release_scope_key_v110($scopeKey)]);
    return (bool)$stmt->fetchColumn();
}

function client_fleet_recommendations_v160(PDO $pdo,array $fleetState): array
{
    $out=[];
    foreach(['browser_companion'=>'Browser Companion','homeserver'=>'HomeServer'] as $product=>$label){
        $counts=(array)($fleetState['summary'][$product]??[]);
        if((int)($counts['unsupported']??0)>0)$out[]=['severity'=>'high','product'=>$product,'message'=>$label.': '.(int)$counts['unsupported'].' unsupported client(s) should be moved to a supported GA target.'];
        if((int)($counts['deprecated']??0)>0)$out[]=['severity'=>'medium','product'=>$product,'message'=>$label.': '.(int)$counts['deprecated'].' deprecated client(s) should enter a maintenance cohort.'];
        if((int)($counts['stale']??0)>0)$out[]=['severity'=>'medium','product'=>$product,'message'=>$label.': '.(int)$counts['stale'].' stale client(s) need reconnect/retirement review before maintenance.'];
        if((int)($counts['drift']??0)>0)$out[]=['severity'=>'high','product'=>$product,'message'=>$label.': '.(int)$counts['drift'].' client(s) report versions not registered on their assigned channel.'];
    }
    $compat=(array)($fleetState['compatibility']['counts']??[]);
    if((int)($compat['incompatible']??0)>0)$out[]=['severity'=>'high','product'=>'cross_client','message'=>(int)$compat['incompatible'].' Browser Companion ↔ HomeServer pair(s) violate an explicit compatibility rule.'];
    if((int)($compat['warning']??0)>0)$out[]=['severity'=>'medium','product'=>'cross_client','message'=>(int)$compat['warning'].' cross-client pair(s) match compatibility warning rules.'];
    return $out;
}

function client_fleet_campaign_create_v160(PDO $pdo,array $input,int $actorUserId): array
{
    client_fleet_ensure_schema_v160($pdo);
    $product=(string)($input['product']??'');$channel=client_release_channel_v110((string)($input['channel']??'stable'));
    if(!client_release_product_valid_v110($product))throw new RuntimeException('Unsupported fleet product.');
    $targetId=max(0,(int)($input['target_release_id']??0));$target=client_release_release_row_v110($pdo,$product,$targetId);
    if(!$target||(string)$target['channel']!==$channel)throw new RuntimeException('Maintenance target does not exist on this channel.');
    $roll=client_release_rollout_for_v110($pdo,$product,$targetId,$target);
    if((string)($roll['lifecycle_state']??'')!=='general_availability')throw new RuntimeException('Maintenance campaigns require a General Availability final target.');
    if(client_release_incident_active_for_release_v130($pdo,$product,$targetId))throw new RuntimeException('A release under active incident cannot be a maintenance target.');
    $readiness=client_fleet_optional_readiness_v160($pdo,$product,$targetId);
    if(empty($readiness['ready']))throw new RuntimeException('Maintenance target is not release-ready: '.(string)($readiness['reason']??'preflight blocked'));
    $maxCohort=client_fleet_max_cohort_for_target_v160($pdo,$product,$targetId);
    if($maxCohort<1)throw new RuntimeException('Current v1.40 risk is critical. Record an accepted-risk review before creating maintenance cohorts.');
    $mode=strtolower(trim((string)($input['eligibility_mode']??'outdated')));
    if(!in_array($mode,['outdated','unsupported','deprecated','maintenance','stale','all_supported_old'],true))$mode='outdated';
    $title=mb_strimwidth(trim((string)($input['title']??'')),0,180,'');
    if($title==='')$title=ucwords(str_replace('_',' ',$product)).' maintenance to v'.(string)$target['version'];
    $window=!empty($input['window_enforced'])?1:0;
    $stmt=$pdo->prepare("INSERT INTO client_fleet_maintenance_campaigns_v160
      (product,channel,target_release_id,title,eligibility_mode,campaign_state,cohort_percent,window_enforced,created_by_user_id)
      VALUES (?,?,?,?,?,'draft',0,?,?)");
    $stmt->execute([$product,$channel,$targetId,$title,$mode,$window,$actorUserId>0?$actorUserId:null]);
    $campaignId=(int)$pdo->lastInsertId();

    $inventory=client_fleet_inventory_v160($pdo);
    $insert=$pdo->prepare("INSERT IGNORE INTO client_fleet_maintenance_members_v160
      (campaign_id,user_id,scope_key,installed_version,target_release_id,final_release_id,cohort_bucket,maintenance_state,last_seen_at)
      VALUES (?,?,?,?,?,?,?,?,?)");
    $added=0;
    foreach((array)$inventory[$product] as $row){
        if((string)$row['channel']!==$channel)continue;
        if(!client_fleet_eligible_for_mode_v160($row,$mode,(string)$target['version']))continue;
        $plan=client_fleet_upgrade_plan_v160($pdo,$product,$channel,(string)$row['installed_version'],$targetId);
        $memberTarget=(int)($plan['intermediate_release_id']??0)>0?(int)$plan['intermediate_release_id']:$targetId;
        $memberRelease=client_release_release_row_v110($pdo,$product,$memberTarget);
        $compat=$memberRelease?client_fleet_target_compatibility_v160($pdo,$product,(int)$row['user_id'],(string)$row['scope_key'],$memberRelease):['status'=>'incompatible'];
        $excluded=!empty($row['incident'])||!empty($row['pinned'])||(string)($compat['status']??'unknown')==='incompatible';
        $memberState=$excluded?'excluded':'queued';
        $bucket=client_fleet_campaign_bucket_v160($campaignId,(int)$row['user_id'],(string)$row['scope_key']);
        $insert->execute([$campaignId,(int)$row['user_id'],(string)$row['scope_key'],(string)$row['installed_version'],$memberTarget,$targetId,$bucket,$memberState,$row['last_seen_at']??null]);
        $added+=$insert->rowCount()>0?1:0;
    }
    client_release_audit_v110($pdo,$actorUserId,$product,$targetId,'fleet_campaign_created','','draft',[
        'campaign_id'=>$campaignId,'channel'=>$channel,'eligibility_mode'=>$mode,'members'=>$added,'risk_max_cohort'=>$maxCohort,'window_enforced'=>(bool)$window
    ]);
    return client_fleet_campaign_v160($pdo,$campaignId)??[];
}

function client_fleet_campaign_stats_v160(PDO $pdo,int $campaignId): array
{
    $stats=['total'=>0,'queued'=>0,'offered'=>0,'downloaded'=>0,'installed'=>0,'failed'=>0,'offline'=>0,'excluded'=>0];
    if(!client_fleet_schema_ready_v160($pdo))return $stats;
    $stmt=$pdo->prepare('SELECT maintenance_state,COUNT(*) total FROM client_fleet_maintenance_members_v160 WHERE campaign_id=? GROUP BY maintenance_state');
    $stmt->execute([$campaignId]);
    foreach($stmt->fetchAll()?:[] as $row){
        $state=(string)$row['maintenance_state'];$count=(int)$row['total'];
        $stats['total']+=$count;if(isset($stats[$state]))$stats[$state]=$count;
    }
    return $stats;
}

function client_fleet_member_installed_v160(PDO $pdo,string $product,int $userId,string $scopeKey): array
{
    if($product==='browser_companion'){
        $stmt=$pdo->prepare("SELECT extension_version installed_version,last_used_at last_seen_at FROM extension_devices_v2000
          WHERE user_id=? AND public_id=? AND device_status='active' AND revoked_at IS NULL LIMIT 1");
        $stmt->execute([$userId,$scopeKey]);
    }else{
        $stmt=$pdo->prepare("SELECT installed_version,last_seen_at FROM homeserver_connections
          WHERE user_id=? AND homeserver_token_enc IS NOT NULL LIMIT 1");
        $stmt->execute([$userId]);
    }
    $row=$stmt->fetch();
    return $row?:['installed_version'=>'','last_seen_at'=>null];
}

function client_fleet_campaign_refresh_v160(PDO $pdo,int $campaignId,int $actorUserId=0): array
{
    $campaign=client_fleet_campaign_v160($pdo,$campaignId);
    if(!$campaign)throw new RuntimeException('Maintenance campaign was not found.');
    $product=(string)$campaign['product'];$policy=client_fleet_policy_v160($pdo,$product,(string)$campaign['channel']);
    $windowOpen=empty($campaign['window_enforced'])||client_fleet_window_open_v160($policy);
    $stmt=$pdo->prepare('SELECT * FROM client_fleet_maintenance_members_v160 WHERE campaign_id=? ORDER BY user_id,scope_key');$stmt->execute([$campaignId]);
    $update=$pdo->prepare("UPDATE client_fleet_maintenance_members_v160 SET installed_version=?,target_release_id=?,maintenance_state=?,last_seen_at=? WHERE campaign_id=? AND user_id=? AND scope_key=?");
    foreach($stmt->fetchAll()?:[] as $member){
        $uid=(int)$member['user_id'];$scope=(string)$member['scope_key'];
        if(client_fleet_scope_has_active_incident_v160($pdo,$uid,$product,$scope)||client_fleet_active_pin_v160($pdo,$uid,$product,$scope)){
            $state='excluded';$target=(int)$member['target_release_id'];
        }else{
            $seen=client_fleet_member_installed_v160($pdo,$product,$uid,$scope);
            $installed=(string)$seen['installed_version'];$target=(int)$member['target_release_id'];$final=(int)$member['final_release_id'];
            $targetRelease=client_release_release_row_v110($pdo,$product,$target);$targetVersion=(string)($targetRelease['version']??'');
            if($targetVersion!==''&&$installed===$targetVersion){
                if($target!==$final){
                    $target=$final;
                    $finalRelease=client_release_release_row_v110($pdo,$product,$final);
                    $compat=$finalRelease?client_fleet_target_compatibility_v160($pdo,$product,$uid,$scope,$finalRelease):['status'=>'incompatible'];
                    $state=(string)($compat['status']??'unknown')==='incompatible'?'excluded':'queued';
                }else{$state='installed';}
            }else{
                $compat=$targetRelease?client_fleet_target_compatibility_v160($pdo,$product,$uid,$scope,$targetRelease):['status'=>'incompatible'];
                if((string)($compat['status']??'unknown')==='incompatible')$state='excluded';
                else{
                    $updateState=client_release_update_state_v110($pdo,$uid,$product,$scope,$target);
                    $reported=(string)($updateState['update_state']??'');
                    if($reported==='failed')$state='failed';
                    elseif($reported==='downloaded')$state='downloaded';
                    elseif(client_fleet_is_stale_v160($seen['last_seen_at']??null,(int)$policy['stale_after_hours']))$state='offline';
                    elseif($windowOpen&&(int)$member['cohort_bucket']<=(int)$campaign['cohort_percent']&&(string)$campaign['campaign_state']==='active')$state='offered';
                    else $state='queued';
                }
            }
            $member['installed_version']=$installed;$member['last_seen_at']=$seen['last_seen_at']??null;
        }
        $update->execute([(string)($member['installed_version']??''),$target,$state,$member['last_seen_at']??null,$campaignId,$uid,$scope]);
    }
    $stats=client_fleet_campaign_stats_v160($pdo,$campaignId);
    if($actorUserId>0)client_release_audit_v110($pdo,$actorUserId,$product,(int)$campaign['target_release_id'],'fleet_campaign_refreshed',(string)$campaign['campaign_state'],(string)$campaign['campaign_state'],['campaign_id'=>$campaignId,'stats'=>$stats]);
    return $stats;
}

function client_fleet_campaign_set_v160(PDO $pdo,int $campaignId,string $state,int $percent,int $actorUserId): array
{
    $campaign=client_fleet_campaign_v160($pdo,$campaignId);
    if(!$campaign)throw new RuntimeException('Maintenance campaign was not found.');
    if(!in_array($state,['draft','active','paused','completed','cancelled'],true))throw new RuntimeException('Choose a valid maintenance campaign state.');
    $max=client_fleet_max_cohort_for_target_v160($pdo,(string)$campaign['product'],(int)$campaign['target_release_id']);
    if($state==='active'&&$max<1)throw new RuntimeException('Target release risk currently blocks maintenance activation.');
    $percent=max(0,min(100,$percent));
    if($state==='completed'){
        $beforeComplete=client_fleet_campaign_refresh_v160($pdo,$campaignId,0);
        $resolved=(int)$beforeComplete['installed']+(int)$beforeComplete['excluded'];
        if((int)$beforeComplete['total']!==$resolved)throw new RuntimeException('Maintenance campaign cannot be completed until every member is installed or explicitly excluded.');
    }
    if($state==='active'){
        if($percent<1)$percent=1;
        if($percent>$max)throw new RuntimeException('Risk policy limits this maintenance target to '.$max.'% at a time.');
    }elseif(in_array($state,['draft','paused','completed','cancelled'],true))$percent=$state==='completed'?100:0;
    if($state==='active'&&function_exists('client_release_readiness_current_v150')){
        $ready=client_fleet_optional_readiness_v160($pdo,(string)$campaign['product'],(int)$campaign['target_release_id']);
        if(empty($ready['ready']))throw new RuntimeException('v1.50 readiness no longer allows this maintenance target.');
    }
    $from=(string)$campaign['campaign_state'];
    $stmt=$pdo->prepare("UPDATE client_fleet_maintenance_campaigns_v160 SET campaign_state=?,cohort_percent=?,
      started_at=CASE WHEN ?='active' THEN COALESCE(started_at,NOW()) ELSE started_at END,
      completed_at=CASE WHEN ?='completed' THEN NOW() ELSE completed_at END WHERE id=?");
    $stmt->execute([$state,$percent,$state,$state,$campaignId]);
    client_fleet_campaign_refresh_v160($pdo,$campaignId,0);
    client_release_audit_v110($pdo,$actorUserId,(string)$campaign['product'],(int)$campaign['target_release_id'],'fleet_campaign_state',$from,$state,[
        'campaign_id'=>$campaignId,'cohort_percent'=>$percent,'risk_max_cohort'=>$max
    ]);
    if(function_exists('client_release_intelligence_reconcile_all_v100'))client_release_intelligence_reconcile_all_v100($pdo);
    return client_fleet_campaign_v160($pdo,$campaignId)??[];
}

function client_fleet_maintenance_applicable_release_v160(PDO $pdo,string $product,string $channel,int $userId,string $scopeKey): ?array
{
    if(!client_fleet_schema_ready_v160($pdo))return null;
    $scopeKey=client_release_scope_key_v110($scopeKey);$channel=client_release_channel_v110($channel);
    $stmt=$pdo->prepare("SELECT c.*,m.target_release_id member_target,m.cohort_bucket,m.maintenance_state
      FROM client_fleet_maintenance_campaigns_v160 c
      JOIN client_fleet_maintenance_members_v160 m ON m.campaign_id=c.id
      WHERE c.product=? AND c.channel=? AND c.campaign_state='active' AND c.cohort_percent>0
        AND m.user_id=? AND m.scope_key=? AND m.maintenance_state NOT IN ('installed','failed','excluded')
        AND m.cohort_bucket<=c.cohort_percent
      ORDER BY c.id DESC LIMIT 1");
    $stmt->execute([$product,$channel,$userId,$scopeKey]);
    $row=$stmt->fetch();
    if(!$row)return null;
    if(!empty($row['window_enforced'])&&!client_fleet_window_open_v160(client_fleet_policy_v160($pdo,$product,$channel)))return null;
    if(client_fleet_scope_has_active_incident_v160($pdo,$userId,$product,$scopeKey)||client_fleet_active_pin_v160($pdo,$userId,$product,$scopeKey))return null;
    $release=client_release_release_row_v110($pdo,$product,(int)$row['member_target']);
    if(!$release||empty($release['is_published'])||(string)$release['channel']!==$channel)return null;
    if(client_release_incident_active_for_release_v130($pdo,$product,(int)$release['id']))return null;
    $compat=client_fleet_target_compatibility_v160($pdo,$product,$userId,$scopeKey,$release);
    if((string)($compat['status']??'unknown')==='incompatible')return null;
    $release['_rollout']=client_release_rollout_for_v110($pdo,$product,(int)$release['id'],$release);
    $release['_cohort_bucket']=(int)$row['cohort_bucket'];
    $release['_fleet_campaign_id']=(int)$row['id'];
    $release['_fleet_override']=true;
    return $release;
}

function client_fleet_client_state_v160(PDO $pdo,int $userId,string $product,string $scopeKey,string $installedVersion,?string $lastSeen): array
{
    $channel=client_release_channel_for_v110($pdo,$userId,$product,$scopeKey);
    $support=client_fleet_support_status_v160($pdo,$product,$channel,$installedVersion);
    $policy=client_fleet_policy_v160($pdo,$product,$channel);
    return [
        'support_status'=>$support['status'],'support_reason'=>$support['reason'],
        'minimum_supported_version'=>$support['minimum_supported_version'],'latest_version'=>$support['latest_version'],
        'stale'=>client_fleet_is_stale_v160($lastSeen,(int)$policy['stale_after_hours']),
        'pin'=>client_fleet_active_pin_v160($pdo,$userId,$product,$scopeKey),
        'incident'=>client_fleet_scope_has_active_incident_v160($pdo,$userId,$product,$scopeKey),
    ];
}
