<?php
declare(strict_types=1);

const VP3_CLIENT_RELEASE_HEALTH_V120 = 'client-release-health-v120-20260922';

function client_release_health_schema_ready_v120(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach([
        'client_release_health_policy_v120',
        'client_release_health_snapshots_v120',
        'client_release_promotion_decisions_v120',
    ] as $table){
        if(!client_release_table_ready_v110($pdo,$table))return false;
    }
    return true;
}

function client_release_health_ensure_schema_v120(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    client_release_rollouts_ensure_schema_v110($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_health_policy_v120 (
        product VARCHAR(40) NOT NULL,
        release_id BIGINT UNSIGNED NOT NULL,
        min_observed_clients INT UNSIGNED NOT NULL DEFAULT 3,
        min_observation_hours INT UNSIGNED NOT NULL DEFAULT 6,
        max_failure_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 500,
        max_compat_failure_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 200,
        min_install_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 5000,
        rollback_failure_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 1500,
        limited_step_percent TINYINT UNSIGNED NOT NULL DEFAULT 25,
        updated_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(product,release_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_health_snapshots_v120 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product VARCHAR(40) NOT NULL,
        release_id BIGINT UNSIGNED NOT NULL,
        lifecycle_state VARCHAR(32) NOT NULL,
        rollout_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
        observed_clients INT UNSIGNED NOT NULL DEFAULT 0,
        available_clients INT UNSIGNED NOT NULL DEFAULT 0,
        downloaded_clients INT UNSIGNED NOT NULL DEFAULT 0,
        installed_clients INT UNSIGNED NOT NULL DEFAULT 0,
        failed_clients INT UNSIGNED NOT NULL DEFAULT 0,
        compatibility_failed_clients INT UNSIGNED NOT NULL DEFAULT 0,
        deferred_clients INT UNSIGNED NOT NULL DEFAULT 0,
        failure_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        compatibility_failure_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        install_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        adoption_velocity_per_day DECIMAL(10,2) NOT NULL DEFAULT 0,
        observation_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
        health_status VARCHAR(24) NOT NULL,
        recommendation VARCHAR(32) NOT NULL,
        reasons_json LONGTEXT NULL,
        sampled_by_user_id INT UNSIGNED NULL,
        sampled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client_release_health_release (product,release_id,sampled_at),
        INDEX idx_client_release_health_status (health_status,recommendation,sampled_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_promotion_decisions_v120 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        actor_user_id INT UNSIGNED NULL,
        product VARCHAR(40) NOT NULL,
        release_id BIGINT UNSIGNED NOT NULL,
        health_snapshot_id BIGINT UNSIGNED NULL,
        decision VARCHAR(20) NOT NULL,
        from_state VARCHAR(32) NOT NULL,
        from_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
        to_state VARCHAR(32) NOT NULL,
        to_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
        rationale VARCHAR(500) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client_release_promotion_release (product,release_id,created_at),
        INDEX idx_client_release_promotion_actor (actor_user_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function client_release_health_default_policy_v120(): array
{
    return [
        'min_observed_clients'=>3,
        'min_observation_hours'=>6,
        'max_failure_rate_bps'=>500,
        'max_compat_failure_rate_bps'=>200,
        'min_install_rate_bps'=>5000,
        'rollback_failure_rate_bps'=>1500,
        'limited_step_percent'=>25,
    ];
}

function client_release_health_policy_v120(PDO $pdo,string $product,int $releaseId): array
{
    $defaults=client_release_health_default_policy_v120();
    if(!client_release_health_schema_ready_v120($pdo))return $defaults;
    $stmt=$pdo->prepare('SELECT * FROM client_release_health_policy_v120 WHERE product=? AND release_id=? LIMIT 1');
    $stmt->execute([$product,$releaseId]);
    $row=$stmt->fetch();
    return $row?array_merge($defaults,$row):$defaults;
}

function client_release_health_policy_update_v120(PDO $pdo,string $product,int $releaseId,array $input,int $actorUserId): array
{
    if(!client_release_product_valid_v110($product))throw new RuntimeException('Unsupported release product.');
    if(!client_release_release_row_v110($pdo,$product,$releaseId))throw new RuntimeException('Client release was not found.');
    client_release_health_ensure_schema_v120($pdo);

    $policy=[
        'min_observed_clients'=>max(1,min(100000,(int)($input['min_observed_clients']??3))),
        'min_observation_hours'=>max(0,min(720,(int)($input['min_observation_hours']??6))),
        'max_failure_rate_bps'=>max(0,min(10000,(int)($input['max_failure_rate_bps']??500))),
        'max_compat_failure_rate_bps'=>max(0,min(10000,(int)($input['max_compat_failure_rate_bps']??200))),
        'min_install_rate_bps'=>max(0,min(10000,(int)($input['min_install_rate_bps']??5000))),
        'rollback_failure_rate_bps'=>max(0,min(10000,(int)($input['rollback_failure_rate_bps']??1500))),
        'limited_step_percent'=>max(1,min(50,(int)($input['limited_step_percent']??25))),
    ];
    if($policy['rollback_failure_rate_bps']<$policy['max_failure_rate_bps']){
        throw new RuntimeException('Rollback review threshold must be at least the normal failure hold threshold.');
    }

    $stmt=$pdo->prepare("INSERT INTO client_release_health_policy_v120
      (product,release_id,min_observed_clients,min_observation_hours,max_failure_rate_bps,max_compat_failure_rate_bps,min_install_rate_bps,rollback_failure_rate_bps,limited_step_percent,updated_by_user_id)
      VALUES (?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE min_observed_clients=VALUES(min_observed_clients),
      min_observation_hours=VALUES(min_observation_hours),max_failure_rate_bps=VALUES(max_failure_rate_bps),
      max_compat_failure_rate_bps=VALUES(max_compat_failure_rate_bps),min_install_rate_bps=VALUES(min_install_rate_bps),
      rollback_failure_rate_bps=VALUES(rollback_failure_rate_bps),limited_step_percent=VALUES(limited_step_percent),
      updated_by_user_id=VALUES(updated_by_user_id)");
    $stmt->execute([
        $product,$releaseId,$policy['min_observed_clients'],$policy['min_observation_hours'],
        $policy['max_failure_rate_bps'],$policy['max_compat_failure_rate_bps'],$policy['min_install_rate_bps'],
        $policy['rollback_failure_rate_bps'],$policy['limited_step_percent'],$actorUserId>0?$actorUserId:null
    ]);
    client_release_audit_v110($pdo,$actorUserId,$product,$releaseId,'health_policy_update','','',$policy);
    return client_release_health_policy_v120($pdo,$product,$releaseId);
}

function client_release_health_row_is_eligible_v120(array $rollout,array $row): bool
{
    $state=(string)($rollout['lifecycle_state']??'draft');
    if($state==='general_availability')return true;
    if(!in_array($state,['canary','limited'],true))return false;
    $bucket=client_release_cohort_bucket_v110(
        (string)$row['product'],
        (int)$row['release_id'],
        (int)$row['user_id'],
        (string)$row['scope_key']
    );
    return $bucket<=(int)($rollout['rollout_percent']??0);
}

function client_release_health_counts_v120(PDO $pdo,string $product,int $releaseId,array $rollout): array
{
    $counts=[
        'observed'=>0,'available'=>0,'downloaded'=>0,'installed'=>0,'failed'=>0,
        'compatibility_failed'=>0,'deferred'=>0,'superseded'=>0,
    ];
    if(!client_release_rollouts_schema_ready_v110($pdo))return $counts;
    $stmt=$pdo->prepare('SELECT user_id,product,scope_key,release_id,update_state,details FROM client_release_update_state_v110 WHERE product=? AND release_id=?');
    $stmt->execute([$product,$releaseId]);
    foreach($stmt->fetchAll()?:[] as $row){
        if(!client_release_health_row_is_eligible_v120($rollout,$row))continue;
        $counts['observed']++;
        $state=(string)($row['update_state']??'available');
        if(isset($counts[$state]))$counts[$state]++;
        if($state==='failed'){
            $details=strtolower((string)($row['details']??''));
            if(str_contains($details,'compatib')||str_contains($details,'unsupported')||str_contains($details,'prerequisite')){
                $counts['compatibility_failed']++;
            }
        }
    }
    return $counts;
}

function client_release_health_observation_hours_v120(array $rollout,array $release): float
{
    $stamp=(string)($rollout['updated_at']??$release['published_at']??$release['created_at']??'');
    $ts=$stamp!==''?strtotime($stamp):false;
    if($ts===false)return 0.0;
    return max(0.0,(time()-$ts)/3600);
}

function client_release_health_previous_snapshot_v120(PDO $pdo,string $product,int $releaseId): ?array
{
    if(!client_release_health_schema_ready_v120($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM client_release_health_snapshots_v120 WHERE product=? AND release_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$product,$releaseId]);
    $row=$stmt->fetch();
    return $row?:null;
}

function client_release_health_next_transition_v120(array $rollout,array $policy): ?array
{
    $state=(string)($rollout['lifecycle_state']??'draft');
    $percent=(int)($rollout['rollout_percent']??0);
    $step=(int)($policy['limited_step_percent']??25);
    if($state==='canary'){
        return ['state'=>'limited','percent'=>max($percent,min(99,$step))];
    }
    if($state==='limited'){
        if($percent>=75)return ['state'=>'general_availability','percent'=>100];
        return ['state'=>'limited','percent'=>min(99,max($percent+1,$percent+$step))];
    }
    return null;
}

function client_release_health_calculate_v120(PDO $pdo,string $product,int $releaseId): array
{
    if(!client_release_product_valid_v110($product))throw new RuntimeException('Unsupported release product.');
    $release=client_release_release_row_v110($pdo,$product,$releaseId);
    if(!$release)throw new RuntimeException('Client release was not found.');
    $rollout=client_release_rollout_for_v110($pdo,$product,$releaseId,$release);
    $policy=client_release_health_policy_v120($pdo,$product,$releaseId);
    $counts=client_release_health_counts_v120($pdo,$product,$releaseId,$rollout);
    $attempts=$counts['downloaded']+$counts['installed']+$counts['failed'];
    $failureRate=$attempts>0?(int)round(($counts['failed']/$attempts)*10000):0;
    $compatRate=$attempts>0?(int)round(($counts['compatibility_failed']/$attempts)*10000):0;
    $installRate=$counts['observed']>0?(int)round(($counts['installed']/$counts['observed'])*10000):0;
    $hours=client_release_health_observation_hours_v120($rollout,$release);
    $previous=client_release_health_previous_snapshot_v120($pdo,$product,$releaseId);
    $velocity=0.0;
    if($previous){
        $prevAt=strtotime((string)($previous['sampled_at']??''));
        if($prevAt!==false){
            $elapsed=max(0.0,(time()-$prevAt)/3600);
            if($elapsed>=0.1){
                $velocity=max(0.0,($counts['installed']-(int)$previous['installed_clients'])/($elapsed/24));
            }
        }
    }

    $state=(string)($rollout['lifecycle_state']??'draft');
    $status='inactive';
    $recommendation='manual_validation';
    $reasons=[];

    if(in_array($state,['canary','limited','general_availability'],true)){
        if($counts['observed']<(int)$policy['min_observed_clients']){
            $status='insufficient_data';$recommendation='observe';
            $reasons[]='Observed client count is below the configured health gate.';
        }elseif($hours<(float)$policy['min_observation_hours']){
            $status='insufficient_data';$recommendation='observe';
            $reasons[]='The rollout has not met the configured observation window.';
        }elseif($failureRate>=(int)$policy['rollback_failure_rate_bps']&&$counts['failed']>0){
            $status='critical';$recommendation='rollback_review';
            $reasons[]='Failure rate crossed the rollback-review threshold.';
        }elseif($failureRate>(int)$policy['max_failure_rate_bps']){
            $status='hold';$recommendation='hold';
            $reasons[]='Failure rate exceeds the promotion threshold.';
        }elseif($compatRate>(int)$policy['max_compat_failure_rate_bps']){
            $status='hold';$recommendation='hold';
            $reasons[]='Compatibility-related failures exceed the promotion threshold.';
        }elseif($installRate<(int)$policy['min_install_rate_bps']){
            $status='hold';$recommendation='hold';
            $reasons[]='Install/adoption rate is below the configured promotion threshold.';
        }else{
            $status='healthy';
            $recommendation=$state==='general_availability'?'continue':'promote';
            $reasons[]=$state==='general_availability'
                ?'General Availability health gates are currently passing.'
                :'Configured health gates are passing for the current cohort.';
        }
    }else{
        $reasons[]='Automated health promotion guidance begins once a release enters Canary.';
    }

    $next=client_release_health_next_transition_v120($rollout,$policy);
    return [
        'product'=>$product,'release_id'=>$releaseId,'version'=>(string)($release['version']??''),
        'channel'=>(string)($release['channel']??'stable'),'rollout'=>$rollout,'policy'=>$policy,
        'observed_clients'=>$counts['observed'],'available_clients'=>$counts['available'],
        'downloaded_clients'=>$counts['downloaded'],'installed_clients'=>$counts['installed'],
        'failed_clients'=>$counts['failed'],'compatibility_failed_clients'=>$counts['compatibility_failed'],
        'deferred_clients'=>$counts['deferred'],'failure_rate_bps'=>$failureRate,
        'compatibility_failure_rate_bps'=>$compatRate,'install_rate_bps'=>$installRate,
        'adoption_velocity_per_day'=>round($velocity,2),'observation_hours'=>round($hours,2),
        'health_status'=>$status,'recommendation'=>$recommendation,'reasons'=>$reasons,
        'next_transition'=>$next,'latest_snapshot'=>$previous,
    ];
}

function client_release_health_snapshot_v120(PDO $pdo,string $product,int $releaseId,int $actorUserId=0): array
{
    client_release_health_ensure_schema_v120($pdo);
    $health=client_release_health_calculate_v120($pdo,$product,$releaseId);
    $rollout=(array)$health['rollout'];
    $stmt=$pdo->prepare("INSERT INTO client_release_health_snapshots_v120
      (product,release_id,lifecycle_state,rollout_percent,observed_clients,available_clients,downloaded_clients,installed_clients,
       failed_clients,compatibility_failed_clients,deferred_clients,failure_rate_bps,compatibility_failure_rate_bps,
       install_rate_bps,adoption_velocity_per_day,observation_hours,health_status,recommendation,reasons_json,sampled_by_user_id)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $product,$releaseId,(string)$rollout['lifecycle_state'],(int)$rollout['rollout_percent'],
        (int)$health['observed_clients'],(int)$health['available_clients'],(int)$health['downloaded_clients'],
        (int)$health['installed_clients'],(int)$health['failed_clients'],(int)$health['compatibility_failed_clients'],
        (int)$health['deferred_clients'],(int)$health['failure_rate_bps'],(int)$health['compatibility_failure_rate_bps'],
        (int)$health['install_rate_bps'],(float)$health['adoption_velocity_per_day'],(float)$health['observation_hours'],
        (string)$health['health_status'],(string)$health['recommendation'],
        json_encode($health['reasons'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        $actorUserId>0?$actorUserId:null
    ]);
    $health['snapshot_id']=(int)$pdo->lastInsertId();
    client_release_audit_v110($pdo,$actorUserId,$product,$releaseId,'health_evaluated',
        (string)$rollout['lifecycle_state'],(string)$rollout['lifecycle_state'],[
            'health_status'=>$health['health_status'],'recommendation'=>$health['recommendation'],
            'observed_clients'=>$health['observed_clients'],'failure_rate_bps'=>$health['failure_rate_bps'],
            'install_rate_bps'=>$health['install_rate_bps']
        ]);
    return $health;
}

function client_release_health_snapshot_by_id_v120(PDO $pdo,int $snapshotId): ?array
{
    if($snapshotId<1||!client_release_health_schema_ready_v120($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM client_release_health_snapshots_v120 WHERE id=? LIMIT 1');
    $stmt->execute([$snapshotId]);
    $row=$stmt->fetch();
    return $row?:null;
}

function client_release_health_approve_promotion_v120(PDO $pdo,string $product,int $releaseId,int $snapshotId,int $actorUserId,string $rationale=''): array
{
    client_release_health_ensure_schema_v120($pdo);
    $requested=client_release_health_snapshot_by_id_v120($pdo,$snapshotId);
    if(!$requested||(string)$requested['product']!==$product||(int)$requested['release_id']!==$releaseId){
        throw new RuntimeException('The selected release health snapshot is no longer available.');
    }
    if((string)$requested['recommendation']!=='promote'){
        throw new RuntimeException('That health snapshot does not recommend promotion.');
    }

    $fresh=client_release_health_snapshot_v120($pdo,$product,$releaseId,$actorUserId);
    if((string)$fresh['recommendation']!=='promote'||empty($fresh['next_transition'])){
        throw new RuntimeException('Release health changed. Review the new health snapshot before promoting.');
    }
    $current=(array)$fresh['rollout'];
    if((string)$current['lifecycle_state']!==(string)$requested['lifecycle_state']||(int)$current['rollout_percent']!==(int)$requested['rollout_percent']){
        throw new RuntimeException('Rollout state changed after the selected health snapshot. Review health again.');
    }

    $next=(array)$fresh['next_transition'];
    $release=client_release_release_row_v110($pdo,$product,$releaseId);
    if(!$release)throw new RuntimeException('Client release was not found.');
    $meta=client_release_rollout_for_v110($pdo,$product,$releaseId,$release);
    client_release_rollout_update_v110($pdo,$product,$releaseId,[
        'lifecycle_state'=>(string)$next['state'],
        'rollout_percent'=>(int)$next['percent'],
        'summary'=>(string)($meta['summary']??''),
        'known_issues'=>(string)($meta['known_issues']??''),
        'compatibility_notes'=>(string)($meta['compatibility_notes']??''),
    ],$actorUserId);

    $rationale=mb_strimwidth(trim($rationale),0,500,'');
    $stmt=$pdo->prepare("INSERT INTO client_release_promotion_decisions_v120
      (actor_user_id,product,release_id,health_snapshot_id,decision,from_state,from_percent,to_state,to_percent,rationale)
      VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $actorUserId>0?$actorUserId:null,$product,$releaseId,(int)$fresh['snapshot_id'],'approved',
        (string)$current['lifecycle_state'],(int)$current['rollout_percent'],
        (string)$next['state'],(int)$next['percent'],$rationale
    ]);
    client_release_audit_v110($pdo,$actorUserId,$product,$releaseId,'health_promotion_approved',
        (string)$current['lifecycle_state'],(string)$next['state'],[
            'from_percent'=>(int)$current['rollout_percent'],'to_percent'=>(int)$next['percent'],
            'health_snapshot_id'=>(int)$fresh['snapshot_id'],'rationale'=>$rationale
        ]);
    return client_release_health_calculate_v120($pdo,$product,$releaseId);
}

function client_release_health_record_rejection_v120(PDO $pdo,string $product,int $releaseId,int $snapshotId,int $actorUserId,string $rationale=''): void
{
    client_release_health_ensure_schema_v120($pdo);
    $snapshot=client_release_health_snapshot_by_id_v120($pdo,$snapshotId);
    if(!$snapshot||(string)$snapshot['product']!==$product||(int)$snapshot['release_id']!==$releaseId){
        throw new RuntimeException('The selected release health snapshot is no longer available.');
    }
    $rationale=mb_strimwidth(trim($rationale),0,500,'');
    $stmt=$pdo->prepare("INSERT INTO client_release_promotion_decisions_v120
      (actor_user_id,product,release_id,health_snapshot_id,decision,from_state,from_percent,to_state,to_percent,rationale)
      VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $actorUserId>0?$actorUserId:null,$product,$releaseId,$snapshotId,'rejected',
        (string)$snapshot['lifecycle_state'],(int)$snapshot['rollout_percent'],
        (string)$snapshot['lifecycle_state'],(int)$snapshot['rollout_percent'],$rationale
    ]);
    client_release_audit_v110($pdo,$actorUserId,$product,$releaseId,'health_promotion_rejected',
        (string)$snapshot['lifecycle_state'],(string)$snapshot['lifecycle_state'],['health_snapshot_id'=>$snapshotId,'rationale'=>$rationale]);
}

function client_release_health_admin_summary_v120(PDO $pdo): array
{
    $out=['browser_companion'=>[],'homeserver'=>[]];
    foreach(['browser_companion','homeserver'] as $product){
        foreach(client_release_admin_rollouts_v110($pdo,$product) as $release){
            $rid=(int)$release['id'];
            try{$out[$product][]=client_release_health_calculate_v120($pdo,$product,$rid);}
            catch(Throwable $e){$out[$product][]=['product'=>$product,'release_id'=>$rid,'version'=>(string)($release['version']??''),'error'=>$e->getMessage()];}
        }
    }
    return $out;
}

function client_release_health_percent_v120(int $bps): string
{
    return number_format(max(0,min(10000,$bps))/100,2).'%';
}

function client_release_health_recommendation_label_v120(string $recommendation): string
{
    return match($recommendation){
        'promote'=>'Ready for operator-approved promotion',
        'hold'=>'Hold current rollout',
        'rollback_review'=>'Rollback review recommended',
        'observe'=>'Continue observation',
        'continue'=>'GA healthy',
        default=>'Manual validation required',
    };
}

function client_release_health_recent_decisions_v120(PDO $pdo,int $limit=20): array
{
    if(!client_release_health_schema_ready_v120($pdo))return [];
    $limit=max(1,min(100,$limit));
    return $pdo->query("SELECT * FROM client_release_promotion_decisions_v120 ORDER BY id DESC LIMIT {$limit}")->fetchAll()?:[];
}
