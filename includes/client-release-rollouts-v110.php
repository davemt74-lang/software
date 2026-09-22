<?php
declare(strict_types=1);

const VP3_CLIENT_RELEASE_ROLLOUTS_V110 = 'client-release-controlled-rollouts-v110-20260922';

function client_release_product_valid_v110(string $product): bool
{
    return in_array($product, ['browser_companion','homeserver'], true);
}

function client_release_channel_v110(string $channel): string
{
    $channel=strtolower(trim($channel));
    return in_array($channel,['stable','beta','dev'],true)?$channel:'stable';
}

function client_release_lifecycle_states_v110(): array
{
    return ['draft','testing','canary','limited','general_availability','paused','superseded','withdrawn'];
}

function client_release_lifecycle_valid_v110(string $state): bool
{
    return in_array($state, client_release_lifecycle_states_v110(), true);
}

function client_release_table_ready_v110(PDO $pdo, string $table): bool
{
    try {
        $stmt=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function client_release_rollouts_schema_ready_v110(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach([
        'client_release_rollouts_v110',
        'client_release_assignments_v110',
        'client_release_update_state_v110',
        'client_release_audit_v110',
    ] as $table){
        if(!client_release_table_ready_v110($pdo,$table))return false;
    }
    return true;
}

function client_release_rollouts_ensure_schema_v110(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_rollouts_v110 (
        product VARCHAR(40) NOT NULL,
        release_id BIGINT UNSIGNED NOT NULL,
        lifecycle_state VARCHAR(32) NOT NULL DEFAULT 'draft',
        rollout_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
        summary VARCHAR(500) NOT NULL DEFAULT '',
        known_issues TEXT NOT NULL,
        compatibility_notes VARCHAR(1000) NOT NULL DEFAULT '',
        created_by_user_id INT UNSIGNED NULL,
        updated_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(product,release_id),
        INDEX idx_client_release_rollout_state (product,lifecycle_state,rollout_percent,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_assignments_v110 (
        user_id INT UNSIGNED NOT NULL,
        product VARCHAR(40) NOT NULL,
        scope_key VARCHAR(160) NOT NULL DEFAULT 'account',
        channel VARCHAR(20) NOT NULL DEFAULT 'stable',
        assigned_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(user_id,product,scope_key),
        INDEX idx_client_release_assignment_channel (product,channel,user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_update_state_v110 (
        user_id INT UNSIGNED NOT NULL,
        product VARCHAR(40) NOT NULL,
        scope_key VARCHAR(160) NOT NULL DEFAULT 'account',
        release_id BIGINT UNSIGNED NOT NULL,
        update_state VARCHAR(24) NOT NULL DEFAULT 'available',
        defer_until DATETIME NULL,
        details VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(user_id,product,scope_key,release_id),
        INDEX idx_client_release_update_state (user_id,product,update_state,defer_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_audit_v110 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        actor_user_id INT UNSIGNED NULL,
        product VARCHAR(40) NOT NULL,
        release_id BIGINT UNSIGNED NULL,
        action VARCHAR(60) NOT NULL,
        from_state VARCHAR(32) NOT NULL DEFAULT '',
        to_state VARCHAR(32) NOT NULL DEFAULT '',
        details_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client_release_audit_release (product,release_id,created_at),
        INDEX idx_client_release_audit_actor (actor_user_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    client_release_rollouts_seed_v110($pdo);
}

function client_release_release_table_v110(string $product): string
{
    return $product==='browser_companion'?'chrome_extension_releases':'homeserver_releases';
}

function client_release_release_row_v110(PDO $pdo,string $product,int $releaseId): ?array
{
    if(!client_release_product_valid_v110($product)||$releaseId<1)return null;
    $table=client_release_release_table_v110($product);
    if(!client_release_table_ready_v110($pdo,$table))return null;
    $stmt=$pdo->prepare("SELECT * FROM {$table} WHERE id=? LIMIT 1");
    $stmt->execute([$releaseId]);
    $row=$stmt->fetch();
    return $row?:null;
}

function client_release_synthetic_rollout_v110(array $release): array
{
    $published=!empty($release['is_published']);
    $latest=!empty($release['is_latest']);
    return [
        'lifecycle_state'=>$published?($latest?'general_availability':'superseded'):'draft',
        'rollout_percent'=>$published&&$latest?100:0,
        'summary'=>'',
        'known_issues'=>'',
        'compatibility_notes'=>'',
    ];
}

function client_release_rollout_for_v110(PDO $pdo,string $product,int $releaseId,?array $release=null): array
{
    if(client_release_rollouts_schema_ready_v110($pdo)){
        $stmt=$pdo->prepare('SELECT * FROM client_release_rollouts_v110 WHERE product=? AND release_id=? LIMIT 1');
        $stmt->execute([$product,$releaseId]);
        $row=$stmt->fetch();
        if($row)return $row;
    }
    $release??=client_release_release_row_v110($pdo,$product,$releaseId)??[];
    return client_release_synthetic_rollout_v110($release);
}

function client_release_audit_v110(PDO $pdo,?int $actor,string $product,?int $releaseId,string $action,string $from='',string $to='',array $details=[]): void
{
    if(!client_release_rollouts_schema_ready_v110($pdo))return;
    $stmt=$pdo->prepare('INSERT INTO client_release_audit_v110 (actor_user_id,product,release_id,action,from_state,to_state,details_json) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([
        $actor&&$actor>0?$actor:null,$product,$releaseId&&$releaseId>0?$releaseId:null,$action,$from,$to,
        $details?json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null
    ]);
}

function client_release_rollouts_seed_v110(PDO $pdo): void
{
    if(!client_release_rollouts_schema_ready_v110($pdo))return;
    foreach(['browser_companion'=>'chrome_extension_releases','homeserver'=>'homeserver_releases'] as $product=>$table){
        if(!client_release_table_ready_v110($pdo,$table))continue;
        $rows=$pdo->query("SELECT id,is_published,is_latest,created_by_user_id FROM {$table} ORDER BY id")->fetchAll();
        $stmt=$pdo->prepare("INSERT IGNORE INTO client_release_rollouts_v110
            (product,release_id,lifecycle_state,rollout_percent,summary,known_issues,compatibility_notes,created_by_user_id,updated_by_user_id)
            VALUES (?,?,?,?,?,?,?,?,?)");
        foreach($rows?:[] as $row){
            $synthetic=client_release_synthetic_rollout_v110($row);
            $creator=(int)($row['created_by_user_id']??0);
            $stmt->execute([
                $product,(int)$row['id'],$synthetic['lifecycle_state'],$synthetic['rollout_percent'],
                '','','',$creator>0?$creator:null,$creator>0?$creator:null
            ]);
        }
    }
}

function client_release_rollout_percent_v110(string $state,int $percent): int
{
    $percent=max(0,min(100,$percent));
    if(in_array($state,['draft','testing','paused','superseded','withdrawn'],true))return 0;
    if($state==='general_availability')return 100;
    return max(1,min(99,$percent));
}

function client_release_rollout_update_v110(PDO $pdo,string $product,int $releaseId,array $input,int $actorUserId): array
{
    if(!client_release_product_valid_v110($product))throw new RuntimeException('Unsupported release product.');
    $release=client_release_release_row_v110($pdo,$product,$releaseId);
    if(!$release)throw new RuntimeException('Client release was not found.');
    client_release_rollouts_ensure_schema_v110($pdo);

    $state=strtolower(trim((string)($input['lifecycle_state']??'draft')));
    if(!client_release_lifecycle_valid_v110($state))throw new RuntimeException('Choose a valid rollout lifecycle state.');
    if(function_exists('client_release_incident_active_for_release_v130')){
        $incident=client_release_incident_active_for_release_v130($pdo,$product,$releaseId);
        if($incident&&!in_array($state,['paused','withdrawn'],true)){
            throw new RuntimeException('An active release incident governs this build. Use the incident recovery controls until it is resolved.');
        }
    }
    $percent=client_release_rollout_percent_v110($state,(int)($input['rollout_percent']??0));
    $summary=mb_strimwidth(trim((string)($input['summary']??'')),0,500,'');
    $known=mb_strimwidth(trim((string)($input['known_issues']??'')),0,10000,'');
    $compat=mb_strimwidth(trim((string)($input['compatibility_notes']??'')),0,1000,'');
    $current=client_release_rollout_for_v110($pdo,$product,$releaseId,$release);
    $from=(string)($current['lifecycle_state']??'draft');
    $table=client_release_release_table_v110($product);
    $channel=(string)($release['channel']??'stable');

    $pdo->beginTransaction();
    try{
        if($state==='general_availability'){
            $other=$pdo->prepare("SELECT id FROM {$table} WHERE channel=? AND id<>?");
            $other->execute([$channel,$releaseId]);
            $ids=array_map('intval',$other->fetchAll(PDO::FETCH_COLUMN)?:[]);
            if($ids){
                $marks=implode(',',array_fill(0,count($ids),'?'));
                $pdo->prepare("UPDATE client_release_rollouts_v110 SET lifecycle_state='superseded',rollout_percent=0,updated_by_user_id=? WHERE product=? AND release_id IN ({$marks}) AND lifecycle_state='general_availability'")
                    ->execute(array_merge([$actorUserId,$product],$ids));
            }
            $pdo->prepare("UPDATE {$table} SET is_latest=0 WHERE channel=?")->execute([$channel]);
            $pdo->prepare("UPDATE {$table} SET is_published=1,is_latest=1,published_at=COALESCE(published_at,NOW()) WHERE id=?")->execute([$releaseId]);
        }elseif($state==='withdrawn'||$state==='draft'){
            $pdo->prepare("UPDATE {$table} SET is_published=0,is_latest=0 WHERE id=?")->execute([$releaseId]);
        }else{
            $pdo->prepare("UPDATE {$table} SET is_published=1,is_latest=0,published_at=COALESCE(published_at,NOW()) WHERE id=?")->execute([$releaseId]);
        }

        $stmt=$pdo->prepare("INSERT INTO client_release_rollouts_v110
          (product,release_id,lifecycle_state,rollout_percent,summary,known_issues,compatibility_notes,created_by_user_id,updated_by_user_id)
          VALUES (?,?,?,?,?,?,?,?,?)
          ON DUPLICATE KEY UPDATE lifecycle_state=VALUES(lifecycle_state),rollout_percent=VALUES(rollout_percent),
          summary=VALUES(summary),known_issues=VALUES(known_issues),compatibility_notes=VALUES(compatibility_notes),
          updated_by_user_id=VALUES(updated_by_user_id)");
        $stmt->execute([$product,$releaseId,$state,$percent,$summary,$known,$compat,$actorUserId,$actorUserId]);
        $pdo->commit();
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    client_release_audit_v110($pdo,$actorUserId,$product,$releaseId,'rollout_update',$from,$state,['rollout_percent'=>$percent,'channel'=>$channel]);
    return client_release_rollout_for_v110($pdo,$product,$releaseId,$release);
}

function client_release_rollout_sync_legacy_action_v110(PDO $pdo,string $product,int $releaseId,string $action,int $actorUserId): void
{
    client_release_rollouts_ensure_schema_v110($pdo);
    if($action==='latest'){
        client_release_rollout_update_v110($pdo,$product,$releaseId,['lifecycle_state'=>'general_availability','rollout_percent'=>100],$actorUserId);
        return;
    }
    if($action==='publish'){
        client_release_rollout_update_v110($pdo,$product,$releaseId,['lifecycle_state'=>'testing','rollout_percent'=>0],$actorUserId);
        return;
    }
    if($action==='unpublish'){
        client_release_rollout_update_v110($pdo,$product,$releaseId,['lifecycle_state'=>'withdrawn','rollout_percent'=>0],$actorUserId);
    }
}

function client_release_scope_key_v110(string $scopeKey): string
{
    $scopeKey=trim($scopeKey);
    if($scopeKey===''||$scopeKey==='account')return 'account';
    return mb_strimwidth($scopeKey,0,160,'');
}

function client_release_scope_authorized_v110(PDO $pdo,int $userId,string $product,string $scopeKey): bool
{
    if($userId<1||!client_release_product_valid_v110($product))return false;
    $scopeKey=client_release_scope_key_v110($scopeKey);
    if($product==='homeserver')return $scopeKey==='account';
    if($scopeKey==='account')return true;
    if(!client_release_table_ready_v110($pdo,'extension_devices_v2000'))return false;
    $stmt=$pdo->prepare("SELECT 1 FROM extension_devices_v2000 WHERE user_id=? AND public_id=? AND device_status='active' AND revoked_at IS NULL LIMIT 1");
    $stmt->execute([$userId,$scopeKey]);
    return (bool)$stmt->fetchColumn();
}

function client_release_channel_for_v110(PDO $pdo,int $userId,string $product,string $scopeKey='account'): string
{
    if(!client_release_rollouts_schema_ready_v110($pdo))return 'stable';
    $scopeKey=client_release_scope_key_v110($scopeKey);
    $stmt=$pdo->prepare("SELECT channel FROM client_release_assignments_v110
      WHERE user_id=? AND product=? AND scope_key IN (?,'account')
      ORDER BY (scope_key=?) DESC LIMIT 1");
    $stmt->execute([$userId,$product,$scopeKey,$scopeKey]);
    $channel=strtolower(trim((string)$stmt->fetchColumn()));
    return in_array($channel,['stable','beta','dev'],true)?$channel:'stable';
}

function client_release_set_channel_v110(PDO $pdo,int $userId,string $product,string $scopeKey,string $channel,int $actorUserId): void
{
    if(!client_release_rollouts_schema_ready_v110($pdo))throw new RuntimeException('Client release rollout schema is not ready.');
    $channel=strtolower(trim($channel));
    if(!in_array($channel,['stable','beta','dev'],true))throw new RuntimeException('Choose a valid release channel.');
    $scopeKey=client_release_scope_key_v110($scopeKey);
    if(!client_release_scope_authorized_v110($pdo,$userId,$product,$scopeKey))throw new RuntimeException('That client is not connected to this account.');
    $stmt=$pdo->prepare("INSERT INTO client_release_assignments_v110 (user_id,product,scope_key,channel,assigned_by_user_id)
      VALUES (?,?,?,?,?)
      ON DUPLICATE KEY UPDATE channel=VALUES(channel),assigned_by_user_id=VALUES(assigned_by_user_id)");
    $stmt->execute([$userId,$product,$scopeKey,$channel,$actorUserId>0?$actorUserId:null]);
    client_release_audit_v110($pdo,$actorUserId,$product,null,'channel_assignment','','',['user_id'=>$userId,'scope_key'=>$scopeKey,'channel'=>$channel]);
}

function client_release_cohort_bucket_v110(string $product,int $releaseId,int $userId,string $scopeKey): int
{
    $key=$product.'|'.$releaseId.'|'.$userId.'|'.client_release_scope_key_v110($scopeKey);
    return (int)(hexdec(substr(hash('sha256',$key),0,8))%100)+1;
}

function client_release_rollout_eligible_v110(array $rollout,int $bucket): bool
{
    $state=(string)($rollout['lifecycle_state']??'draft');
    $percent=(int)($rollout['rollout_percent']??0);
    if($state==='general_availability')return true;
    if(in_array($state,['canary','limited'],true))return $bucket>=1&&$bucket<=max(0,min(100,$percent));
    return false;
}

function client_release_release_candidates_v110(PDO $pdo,string $product,string $channel): array
{
    if(!client_release_product_valid_v110($product)||!in_array($channel,['stable','beta','dev'],true))return [];
    $table=client_release_release_table_v110($product);
    if(!client_release_table_ready_v110($pdo,$table))return [];
    $stmt=$pdo->prepare("SELECT * FROM {$table} WHERE channel=? AND is_published=1 ORDER BY published_at DESC,id DESC");
    $stmt->execute([$channel]);
    return $stmt->fetchAll()?:[];
}

function client_release_applicable_release_v110(PDO $pdo,string $product,string $channel,int $userId,string $scopeKey='account'): ?array
{
    if(function_exists('client_release_recovery_applicable_release_v130')){
        $recovery=client_release_recovery_applicable_release_v130($pdo,$product,$channel,$userId,$scopeKey);
        if($recovery)return $recovery;
    }
    if(function_exists('client_fleet_pin_applicable_release_v160')){
        $pin=client_fleet_pin_applicable_release_v160($pdo,$product,$channel,$userId,$scopeKey);
        if($pin)return $pin;
    }
    if(function_exists('client_fleet_maintenance_applicable_release_v160')){
        $maintenance=client_fleet_maintenance_applicable_release_v160($pdo,$product,$channel,$userId,$scopeKey);
        if($maintenance)return $maintenance;
    }
    foreach(client_release_release_candidates_v110($pdo,$product,$channel) as $release){
        $rollout=client_release_rollout_for_v110($pdo,$product,(int)$release['id'],$release);
        $bucket=client_release_cohort_bucket_v110($product,(int)$release['id'],$userId,$scopeKey);
        if(client_release_rollout_eligible_v110($rollout,$bucket)){
            $release['_rollout']=$rollout;
            $release['_cohort_bucket']=$bucket;
            return $release;
        }
    }
    return null;
}

function client_release_public_release_v110(PDO $pdo,string $product,string $channel='stable'): ?array
{
    foreach(client_release_release_candidates_v110($pdo,$product,$channel) as $release){
        $rollout=client_release_rollout_for_v110($pdo,$product,(int)$release['id'],$release);
        if((string)($rollout['lifecycle_state']??'')==='general_availability'){
            $release['_rollout']=$rollout;
            return $release;
        }
    }
    return null;
}

function client_release_update_state_v110(PDO $pdo,int $userId,string $product,string $scopeKey,int $releaseId): ?array
{
    if(!client_release_rollouts_schema_ready_v110($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM client_release_update_state_v110 WHERE user_id=? AND product=? AND scope_key=? AND release_id=? LIMIT 1');
    $stmt->execute([$userId,$product,client_release_scope_key_v110($scopeKey),$releaseId]);
    $row=$stmt->fetch();
    return $row?:null;
}

function client_release_set_update_state_v110(PDO $pdo,int $userId,string $product,string $scopeKey,int $releaseId,string $state,?string $deferUntil=null,string $details=''): void
{
    $allowed=['available','downloaded','installed','deferred','failed','superseded'];
    if(!in_array($state,$allowed,true))throw new RuntimeException('Unsupported client update state.');
    if(!client_release_rollouts_schema_ready_v110($pdo))throw new RuntimeException('Client release rollout schema is not ready.');
    $stmt=$pdo->prepare("INSERT INTO client_release_update_state_v110
      (user_id,product,scope_key,release_id,update_state,defer_until,details)
      VALUES (?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE update_state=VALUES(update_state),defer_until=VALUES(defer_until),details=VALUES(details)");
    $stmt->execute([$userId,$product,client_release_scope_key_v110($scopeKey),$releaseId,$state,$deferUntil,mb_strimwidth($details,0,500,'')]);
}

function client_release_defer_v110(PDO $pdo,int $userId,string $product,string $scopeKey,int $releaseId,int $days): void
{
    $days=max(1,min(30,$days));
    if(!client_release_scope_authorized_v110($pdo,$userId,$product,$scopeKey))throw new RuntimeException('That client is not connected to this account.');
    $until=(new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+'.$days.' days')->format('Y-m-d H:i:s');
    client_release_set_update_state_v110($pdo,$userId,$product,$scopeKey,$releaseId,'deferred',$until,'Deferred by user');
    client_release_audit_v110($pdo,$userId,$product,$releaseId,'defer_update','','deferred',['scope_key'=>client_release_scope_key_v110($scopeKey),'days'=>$days]);
}

function client_release_resume_v110(PDO $pdo,int $userId,string $product,string $scopeKey,int $releaseId): void
{
    if(!client_release_scope_authorized_v110($pdo,$userId,$product,$scopeKey))throw new RuntimeException('That client is not connected to this account.');
    client_release_set_update_state_v110($pdo,$userId,$product,$scopeKey,$releaseId,'available',null,'');
    client_release_audit_v110($pdo,$userId,$product,$releaseId,'resume_update','deferred','available',['scope_key'=>client_release_scope_key_v110($scopeKey)]);
}

function client_release_sync_update_state_v110(PDO $pdo,int $userId,string $product,string $scopeKey,array $release,string $installed): array
{
    $releaseId=(int)($release['id']??0);
    $version=(string)($release['version']??'');
    $versionState=function_exists('client_release_version_state_v100')?client_release_version_state_v100($installed,$version):'unknown';
    $isIncidentRecovery=!empty($release['_incident_id']);
    $isFleetOverride=!empty($release['_fleet_override']);
    if(($isIncidentRecovery||$isFleetOverride)&&$installed!==''&&$version!==''&&$installed!==$version)$versionState='update_available';
    $schemaReady=client_release_rollouts_schema_ready_v110($pdo);
    $current=$schemaReady?client_release_update_state_v110($pdo,$userId,$product,$scopeKey,$releaseId):null;
    if(in_array($versionState,['current','ahead'],true)){
        if($schemaReady&&(!$current||($current['update_state']??'')!=='installed'))client_release_set_update_state_v110($pdo,$userId,$product,$scopeKey,$releaseId,'installed',null,'Observed from client version telemetry');
        return ['version_state'=>$versionState,'update_state'=>'installed','deferred'=>false,'defer_until'=>null];
    }
    $state=(string)($current['update_state']??'available');
    $deferUntil=$current['defer_until']??null;
    $deferred=$state==='deferred'&&is_string($deferUntil)&&strtotime($deferUntil)>time();
    if($state==='deferred'&&!$deferred){
        $state='available';$deferUntil=null;
        client_release_set_update_state_v110($pdo,$userId,$product,$scopeKey,$releaseId,'available',null,'Deferral expired');
    }elseif($schemaReady&&!$current&&$versionState==='update_available'){
        client_release_set_update_state_v110($pdo,$userId,$product,$scopeKey,$releaseId,'available',null,'');
    }
    return ['version_state'=>$versionState,'update_state'=>$state,'deferred'=>$deferred,'defer_until'=>$deferUntil];
}

function client_release_download_url_v110(string $product,int $releaseId,string $scopeKey,string $type='package'): string
{
    return url('/client-release-download.php?product='.rawurlencode($product).'&release_id='.$releaseId.'&scope='.rawurlencode(client_release_scope_key_v110($scopeKey)).'&type='.rawurlencode($type));
}

function client_release_browser_snapshot_v110(PDO $pdo,int $userId): array
{
    $devices=[];
    if($userId>0&&function_exists('vp3_extension_schema_ready_v2000')&&vp3_extension_schema_ready_v2000($pdo)){
        try{$devices=vp3_extension_devices_for_user_v2000($pdo,$userId);}catch(Throwable $e){$devices=[];}
    }
    $out=[];$updates=0;$attention=0;$active=0;
    foreach($devices as $device){
        $scope=(string)($device['public_id']??'');
        $isActive=(string)($device['device_status']??'')==='active';
        if($isActive)$active++;
        $channel=client_release_channel_for_v110($pdo,$userId,'browser_companion',$scope);
        $release=$isActive?client_release_applicable_release_v110($pdo,'browser_companion',$channel,$userId,$scope):null;
        $installed=trim((string)($device['extension_version']??''));
        $sync=$release?client_release_sync_update_state_v110($pdo,$userId,'browser_companion',$scope,$release,$installed):['version_state'=>'unavailable','update_state'=>'unavailable','deferred'=>false,'defer_until'=>null];
        $available=$isActive&&$release&&$sync['version_state']==='update_available';
        if($available)$updates++;
        if($available&&!$sync['deferred'])$attention++;
        $rollout=is_array($release['_rollout']??null)?$release['_rollout']:[];
        $out[]=[
            'device_id'=>$scope,
            'device_name'=>(string)($device['device_name']??'Chrome Browser'),
            'browser_family'=>(string)($device['browser_family']??'Chrome'),
            'installed_version'=>$installed,
            'target_version'=>(string)($release['version']??''),
            'target_release_id'=>(int)($release['id']??0),
            'channel'=>$channel,
            'lifecycle_state'=>(string)($rollout['lifecycle_state']??'unavailable'),
            'rollout_percent'=>(int)($rollout['rollout_percent']??0),
            'cohort_bucket'=>(int)($release['_cohort_bucket']??0),
            'version_state'=>$isActive?$sync['version_state']:'inactive',
            'update_state'=>$sync['update_state'],
            'deferred'=>(bool)$sync['deferred'],
            'defer_until'=>$sync['defer_until'],
            'update_available'=>$available,
            'attention_required'=>$available&&!$sync['deferred'],
            'download_url'=>$release?client_release_download_url_v110('browser_companion',(int)$release['id'],$scope,'package'):'',
            'release_notes'=>(string)($release['release_notes']??''),
            'summary'=>(string)($rollout['summary']??''),
            'known_issues'=>(string)($rollout['known_issues']??''),
            'compatibility_notes'=>(string)($rollout['compatibility_notes']??''),
            'status'=>(string)($device['device_status']??''),
            'last_used_at'=>$device['last_used_at']??null,
        ];
    }
    $public=client_release_public_release_v110($pdo,'browser_companion','stable');
    return [
        'product'=>'browser_companion','label'=>'VP3 Browser Companion',
        'public_release'=>$public,'devices'=>$out,'active_device_count'=>$active,
        'update_count'=>$updates,'attention_count'=>$attention,'update_available'=>$updates>0,
        'manage_url'=>url('/connected-browsers.php'),'download_url'=>url('/chrome-extension-download.php')
    ];
}

function client_release_homeserver_snapshot_v110(PDO $pdo,int $userId): array
{
    try{$connection=homeserver_vp3_connection($userId);}catch(Throwable $e){$connection=null;}
    $scope='account';
    $channel=client_release_channel_for_v110($pdo,$userId,'homeserver',$scope);
    $paired=!empty($connection['homeserver_token_enc']);
    $release=$paired?client_release_applicable_release_v110($pdo,'homeserver',$channel,$userId,$scope):null;
    $installed=trim((string)($connection['installed_version']??''));
    $sync=$release?client_release_sync_update_state_v110($pdo,$userId,'homeserver',$scope,$release,$installed):['version_state'=>'unavailable','update_state'=>'unavailable','deferred'=>false,'defer_until'=>null];
    $available=$paired&&$release&&$sync['version_state']==='update_available';
    $rollout=is_array($release['_rollout']??null)?$release['_rollout']:[];
    $publicRelease=$release?homeserver_vp3_release_public($release):null;
    if($publicRelease&&$release){
        $rid=(int)$release['id'];
        if(is_array($publicRelease['installer']??null))$publicRelease['installer']['url']=client_release_download_url_v110('homeserver',$rid,$scope,'installer');
        if(is_array($publicRelease['portable']??null))$publicRelease['portable']['url']=client_release_download_url_v110('homeserver',$rid,$scope,'portable');
    }
    return [
        'product'=>'homeserver','label'=>'VP3 HomeServer','channel'=>$channel,
        'target_release'=>$publicRelease,'installed_version'=>$installed,
        'target_version'=>(string)($release['version']??''),'target_release_id'=>(int)($release['id']??0),
        'lifecycle_state'=>(string)($rollout['lifecycle_state']??'unavailable'),
        'rollout_percent'=>(int)($rollout['rollout_percent']??0),
        'cohort_bucket'=>(int)($release['_cohort_bucket']??0),
        'version_state'=>$connection?$sync['version_state']:'unpaired',
        'update_state'=>$sync['update_state'],'deferred'=>(bool)$sync['deferred'],'defer_until'=>$sync['defer_until'],
        'update_available'=>$available,'attention_required'=>$available&&!$sync['deferred'],
        'paired'=>$paired,'connection_state'=>strtolower(trim((string)($connection['status']??'unpaired'))),
        'last_seen_at'=>$connection['last_seen_at']??null,
        'summary'=>(string)($rollout['summary']??''),'known_issues'=>(string)($rollout['known_issues']??''),
        'compatibility_notes'=>(string)($rollout['compatibility_notes']??''),
        'manage_url'=>url('/settings-homeserver.php')
    ];
}

function client_release_intelligence_snapshot_v110(PDO $pdo,array $user,bool $reconcile=true): array
{
    $uid=(int)($user['id']??0);
    $browser=client_release_browser_snapshot_v110($pdo,$uid);
    $home=client_release_homeserver_snapshot_v110($pdo,$uid);
    $updates=(int)$browser['update_count']+(!empty($home['update_available'])?1:0);
    $attention=(int)$browser['attention_count']+(!empty($home['attention_required'])?1:0);
    $snapshot=[
        'build'=>VP3_CLIENT_RELEASE_ROLLOUTS_V110,'browser_companion'=>$browser,'homeserver'=>$home,
        'update_count'=>$updates,'attention_count'=>$attention,'updates_available'=>$updates>0,'generated_at'=>gmdate(DATE_ATOM)
    ];
    if($reconcile&&$uid>0)client_release_intelligence_reconcile_user_v110($pdo,$uid,$snapshot);
    return $snapshot;
}

function client_release_resolve_notification_sources_v110(PDO $pdo,int $userId,string $sourceType,array $keepIds): void
{
    if(!table_exists('notifications'))return;
    $keep=array_fill_keys(array_map('intval',$keepIds),true);
    $stmt=$pdo->prepare("SELECT id,source_id FROM notifications WHERE user_id=? AND type='client_release_update' AND source_type=? AND is_read=0");
    $stmt->execute([$userId,$sourceType]);
    $close=$pdo->prepare('UPDATE notifications SET is_read=1,read_at=COALESCE(read_at,NOW()) WHERE id=?');
    foreach($stmt->fetchAll()?:[] as $row){
        if(!isset($keep[(int)($row['source_id']??0)]))$close->execute([(int)$row['id']]);
    }
}

function client_release_intelligence_reconcile_user_v110(PDO $pdo,int $userId,?array $snapshot=null): void
{
    if($userId<1||!table_exists('notifications')||!function_exists('create_notification'))return;
    $snapshot??=client_release_intelligence_snapshot_v110($pdo,['id'=>$userId],false);
    $browser=(array)($snapshot['browser_companion']??[]);
    $groups=[];
    foreach((array)($browser['devices']??[]) as $device){
        if(empty($device['attention_required']))continue;
        $rid=(int)($device['target_release_id']??0);if($rid<1)continue;
        if(!isset($groups[$rid]))$groups[$rid]=['version'=>(string)($device['target_version']??''),'count'=>0,'channel'=>(string)($device['channel']??'stable')];
        $groups[$rid]['count']++;
    }
    client_release_resolve_notification_sources_v110($pdo,$userId,'client_release_browser',array_keys($groups));
    foreach($groups as $rid=>$g){
        create_notification($userId,'client_release_update','Browser Companion update available',
            'VP3 Browser Companion v'.$g['version'].' is available on the '.$g['channel'].' channel for '.$g['count'].' connected browser'.($g['count']===1?'':'s').'.',
            '/client-updates.php#browser-companion','client_release_browser',(int)$rid);
    }

    $home=(array)($snapshot['homeserver']??[]);
    $homeId=!empty($home['attention_required'])?(int)($home['target_release_id']??0):0;
    client_release_resolve_notification_sources_v110($pdo,$userId,'client_release_homeserver',$homeId>0?[$homeId]:[]);
    if($homeId>0){
        create_notification($userId,'client_release_update','HomeServer update available',
            'VP3 HomeServer v'.(string)($home['target_version']??'').' is available on the '.(string)($home['channel']??'stable').' channel. Review the release before installing.',
            '/client-updates.php#homeserver','client_release_homeserver',$homeId);
    }
}

function client_release_admin_rollouts_v110(PDO $pdo,string $product): array
{
    if(!client_release_product_valid_v110($product))return [];
    $table=client_release_release_table_v110($product);
    if(!client_release_table_ready_v110($pdo,$table))return [];
    $rows=$pdo->query("SELECT * FROM {$table} ORDER BY created_at DESC,id DESC")->fetchAll()?:[];
    foreach($rows as &$row)$row['_rollout']=client_release_rollout_for_v110($pdo,$product,(int)$row['id'],$row);
    unset($row);
    return $rows;
}

function client_release_adoption_summary_v110(PDO $pdo): array
{
    $summary=['browser_companion'=>[],'homeserver'=>[]];
    foreach(['browser_companion','homeserver'] as $product){
        foreach(client_release_admin_rollouts_v110($pdo,$product) as $release){
            $version=(string)($release['version']??'');
            if($product==='browser_companion'){
                $sql="SELECT COUNT(*) FROM extension_devices_v2000 WHERE device_status='active' AND revoked_at IS NULL AND extension_version=?";
            }else{
                $sql="SELECT COUNT(*) FROM homeserver_connections WHERE homeserver_token_enc IS NOT NULL AND installed_version=?";
            }
            try{$stmt=$pdo->prepare($sql);$stmt->execute([$version]);$count=(int)$stmt->fetchColumn();}catch(Throwable $e){$count=0;}
            $rollout=(array)($release['_rollout']??[]);
            $summary[$product][]=[
                'release_id'=>(int)$release['id'],'version'=>$version,'channel'=>(string)($release['channel']??'stable'),
                'lifecycle_state'=>(string)($rollout['lifecycle_state']??'draft'),'rollout_percent'=>(int)($rollout['rollout_percent']??0),
                'installed_clients'=>$count
            ];
        }
    }
    return $summary;
}


function client_release_rollout_delete_v110(PDO $pdo,string $product,int $releaseId,int $actorUserId): void
{
    if(!client_release_rollouts_schema_ready_v110($pdo)||!client_release_product_valid_v110($product)||$releaseId<1)return;
    $rollout=client_release_rollout_for_v110($pdo,$product,$releaseId);
    client_release_audit_v110($pdo,$actorUserId,$product,$releaseId,'release_deleted',(string)($rollout['lifecycle_state']??''),'deleted');
    $pdo->prepare('DELETE FROM client_release_update_state_v110 WHERE product=? AND release_id=?')->execute([$product,$releaseId]);
    $pdo->prepare('DELETE FROM client_release_rollouts_v110 WHERE product=? AND release_id=?')->execute([$product,$releaseId]);
}

function client_release_audit_recent_v110(PDO $pdo,int $limit=30): array
{
    if(!client_release_rollouts_schema_ready_v110($pdo))return [];
    $limit=max(1,min(100,$limit));
    return $pdo->query("SELECT * FROM client_release_audit_v110 ORDER BY id DESC LIMIT {$limit}")->fetchAll()?:[];
}


function client_release_public_fallback_allowed_v110(PDO $pdo,string $product): bool
{
    if(!client_release_rollouts_schema_ready_v110($pdo))return true;
    $stmt=$pdo->prepare("SELECT lifecycle_state FROM client_release_rollouts_v110 WHERE product=? ORDER BY updated_at DESC,release_id DESC LIMIT 1");
    $stmt->execute([$product]);
    $state=(string)$stmt->fetchColumn();
    if($state==='')return true;
    return !in_array($state,['paused','withdrawn'],true);
}
