<?php
declare(strict_types=1);

const VP3_CLIENT_RELEASE_INCIDENTS_V130 = 'client-release-incidents-v130-20260922';

function client_release_incident_schema_ready_v130(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach([
        'client_release_incidents_v130',
        'client_release_incident_clients_v130',
        'client_release_incident_events_v130',
    ] as $table){
        if(!client_release_table_ready_v110($pdo,$table))return false;
    }
    return true;
}

function client_release_incident_ensure_schema_v130(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    client_release_health_ensure_schema_v120($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_incidents_v130 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product VARCHAR(40) NOT NULL,
        release_id BIGINT UNSIGNED NOT NULL,
        recovery_release_id BIGINT UNSIGNED NULL,
        health_snapshot_id BIGINT UNSIGNED NULL,
        severity VARCHAR(16) NOT NULL DEFAULT 'high',
        status VARCHAR(24) NOT NULL DEFAULT 'open',
        title VARCHAR(180) NOT NULL,
        symptoms VARCHAR(2000) NOT NULL DEFAULT '',
        recovery_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
        min_recovery_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 9500,
        max_recovery_failure_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 500,
        verification_hours SMALLINT UNSIGNED NOT NULL DEFAULT 12,
        root_cause VARCHAR(2000) NOT NULL DEFAULT '',
        resolution_summary VARCHAR(2000) NOT NULL DEFAULT '',
        lessons_learned VARCHAR(4000) NOT NULL DEFAULT '',
        opened_by_user_id INT UNSIGNED NULL,
        contained_by_user_id INT UNSIGNED NULL,
        resolved_by_user_id INT UNSIGNED NULL,
        opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        contained_at DATETIME NULL,
        recovery_started_at DATETIME NULL,
        monitoring_started_at DATETIME NULL,
        resolved_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_client_release_incident_release (product,release_id,status,opened_at),
        INDEX idx_client_release_incident_status (status,severity,opened_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_incident_clients_v130 (
        incident_id BIGINT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        scope_key VARCHAR(160) NOT NULL DEFAULT 'account',
        original_update_state VARCHAR(24) NOT NULL DEFAULT 'installed',
        recovery_state VARCHAR(24) NOT NULL DEFAULT 'queued',
        recovery_bucket TINYINT UNSIGNED NOT NULL DEFAULT 100,
        last_seen_at DATETIME NULL,
        first_observed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        recovered_at DATETIME NULL,
        acknowledged_at DATETIME NULL,
        acknowledged_by_user_id INT UNSIGNED NULL,
        acknowledged_reason VARCHAR(500) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(incident_id,user_id,scope_key),
        INDEX idx_client_release_incident_client_state (incident_id,recovery_state,recovery_bucket),
        INDEX idx_client_release_incident_client_user (user_id,incident_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_incident_events_v130 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        incident_id BIGINT UNSIGNED NOT NULL,
        actor_user_id INT UNSIGNED NULL,
        event_type VARCHAR(60) NOT NULL,
        from_state VARCHAR(32) NOT NULL DEFAULT '',
        to_state VARCHAR(32) NOT NULL DEFAULT '',
        details_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client_release_incident_event (incident_id,created_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function client_release_incident_statuses_v130(): array
{
    return ['open','contained','recovering','monitoring','resolved'];
}

function client_release_incident_severities_v130(): array
{
    return ['low','medium','high','critical'];
}

function client_release_incident_event_v130(PDO $pdo,int $incidentId,int $actorUserId,string $eventType,string $from='',string $to='',array $details=[]): void
{
    if(!client_release_incident_schema_ready_v130($pdo)||$incidentId<1)return;
    $stmt=$pdo->prepare('INSERT INTO client_release_incident_events_v130 (incident_id,actor_user_id,event_type,from_state,to_state,details_json) VALUES (?,?,?,?,?,?)');
    $stmt->execute([
        $incidentId,$actorUserId>0?$actorUserId:null,$eventType,$from,$to,
        $details?json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null
    ]);
}

function client_release_incident_v130(PDO $pdo,int $incidentId): ?array
{
    if($incidentId<1||!client_release_incident_schema_ready_v130($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM client_release_incidents_v130 WHERE id=? LIMIT 1');
    $stmt->execute([$incidentId]);
    $row=$stmt->fetch();
    return $row?:null;
}

function client_release_incident_active_for_release_v130(PDO $pdo,string $product,int $releaseId): ?array
{
    if(!client_release_incident_schema_ready_v130($pdo))return null;
    $stmt=$pdo->prepare("SELECT * FROM client_release_incidents_v130
        WHERE product=? AND release_id=? AND status IN ('open','contained','recovering','monitoring')
        ORDER BY id DESC LIMIT 1");
    $stmt->execute([$product,$releaseId]);
    $row=$stmt->fetch();
    return $row?:null;
}

function client_release_incident_recovery_candidate_v130(PDO $pdo,string $product,int $releaseId): ?array
{
    $affected=client_release_release_row_v110($pdo,$product,$releaseId);
    if(!$affected)return null;
    $channel=(string)($affected['channel']??'stable');
    $preferred=[];$fallback=[];
    foreach(client_release_admin_rollouts_v110($pdo,$product) as $release){
        if((int)$release['id']===$releaseId||(string)($release['channel']??'stable')!==$channel)continue;
        $roll=(array)($release['_rollout']??[]);
        $state=(string)($roll['lifecycle_state']??'draft');
        if($state==='superseded')$preferred[]=$release;
        elseif($state==='general_availability')$fallback[]=$release;
    }
    $candidates=array_merge($preferred,$fallback);
    if(!$candidates)return null;
    usort($candidates,static function(array $a,array $b) use($releaseId): int {
        $aId=(int)$a['id'];$bId=(int)$b['id'];
        $aBefore=$aId<$releaseId?1:0;$bBefore=$bId<$releaseId?1:0;
        if($aBefore!==$bBefore)return $bBefore<=>$aBefore;
        return $bId<=>$aId;
    });
    return $candidates[0]??null;
}

function client_release_incident_scope_bucket_v130(int $incidentId,int $userId,string $scopeKey): int
{
    $key='incident|'.$incidentId.'|'.$userId.'|'.client_release_scope_key_v110($scopeKey);
    return (int)(hexdec(substr(hash('sha256',$key),0,8))%100)+1;
}

function client_release_incident_client_upsert_v130(PDO $pdo,int $incidentId,int $userId,string $scopeKey,string $originalState,?string $lastSeen=null): void
{
    if($incidentId<1||$userId<1)return;
    $scopeKey=client_release_scope_key_v110($scopeKey);
    $bucket=client_release_incident_scope_bucket_v130($incidentId,$userId,$scopeKey);
    $allowed=['available','downloaded','installed','deferred','failed','superseded'];
    if(!in_array($originalState,$allowed,true))$originalState='installed';
    $stmt=$pdo->prepare("INSERT INTO client_release_incident_clients_v130
      (incident_id,user_id,scope_key,original_update_state,recovery_state,recovery_bucket,last_seen_at)
      VALUES (?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE
        original_update_state=VALUES(original_update_state),
        last_seen_at=COALESCE(VALUES(last_seen_at),last_seen_at)");
    $stmt->execute([$incidentId,$userId,$scopeKey,$originalState,'queued',$bucket,$lastSeen]);
}

function client_release_incident_collect_affected_v130(PDO $pdo,int $incidentId): int
{
    $incident=client_release_incident_v130($pdo,$incidentId);
    if(!$incident)return 0;
    $product=(string)$incident['product'];$releaseId=(int)$incident['release_id'];
    $release=client_release_release_row_v110($pdo,$product,$releaseId);
    if(!$release)return 0;
    $version=(string)($release['version']??'');

    $stmt=$pdo->prepare("SELECT user_id,scope_key,update_state,updated_at FROM client_release_update_state_v110
        WHERE product=? AND release_id=? AND update_state IN ('downloaded','installed','failed')");
    $stmt->execute([$product,$releaseId]);
    foreach($stmt->fetchAll()?:[] as $row){
        client_release_incident_client_upsert_v130(
            $pdo,$incidentId,(int)$row['user_id'],(string)$row['scope_key'],(string)$row['update_state'],(string)($row['updated_at']??'')
        );
    }

    if($version!==''&&$product==='browser_companion'&&client_release_table_ready_v110($pdo,'extension_devices_v2000')){
        try{
            $q=$pdo->prepare("SELECT user_id,public_id,last_used_at FROM extension_devices_v2000
                WHERE extension_version=? AND device_status='active' AND revoked_at IS NULL");
            $q->execute([$version]);
            foreach($q->fetchAll()?:[] as $row){
                client_release_incident_client_upsert_v130(
                    $pdo,$incidentId,(int)$row['user_id'],(string)$row['public_id'],'installed',(string)($row['last_used_at']??'')
                );
            }
        }catch(Throwable $e){}
    }

    if($version!==''&&$product==='homeserver'&&client_release_table_ready_v110($pdo,'homeserver_connections')){
        try{
            $q=$pdo->prepare("SELECT user_id,last_seen_at FROM homeserver_connections
                WHERE installed_version=? AND homeserver_token_enc IS NOT NULL");
            $q->execute([$version]);
            foreach($q->fetchAll()?:[] as $row){
                client_release_incident_client_upsert_v130(
                    $pdo,$incidentId,(int)$row['user_id'],'account','installed',(string)($row['last_seen_at']??'')
                );
            }
        }catch(Throwable $e){}
    }

    $count=$pdo->prepare('SELECT COUNT(*) FROM client_release_incident_clients_v130 WHERE incident_id=?');
    $count->execute([$incidentId]);
    return (int)$count->fetchColumn();
}

function client_release_incident_open_v130(PDO $pdo,string $product,int $releaseId,array $input,int $actorUserId): array
{
    if(!client_release_product_valid_v110($product))throw new RuntimeException('Unsupported release product.');
    $release=client_release_release_row_v110($pdo,$product,$releaseId);
    if(!$release)throw new RuntimeException('Client release was not found.');
    client_release_incident_ensure_schema_v130($pdo);
    if(client_release_incident_active_for_release_v130($pdo,$product,$releaseId)){
        throw new RuntimeException('An active incident already exists for this client release.');
    }

    $severity=strtolower(trim((string)($input['severity']??'high')));
    if(!in_array($severity,client_release_incident_severities_v130(),true))$severity='high';
    $title=mb_strimwidth(trim((string)($input['title']??'')),0,180,'');
    if($title==='')$title='Release incident: '.$product.' v'.(string)($release['version']??'');
    $symptoms=mb_strimwidth(trim((string)($input['symptoms']??'')),0,2000,'');
    $candidate=client_release_incident_recovery_candidate_v130($pdo,$product,$releaseId);
    $health=client_release_health_previous_snapshot_v120($pdo,$product,$releaseId);
    $healthId=$health?(int)$health['id']:null;

    $stmt=$pdo->prepare("INSERT INTO client_release_incidents_v130
      (product,release_id,recovery_release_id,health_snapshot_id,severity,status,title,symptoms,opened_by_user_id)
      VALUES (?,?,?,?,?,'open',?,?,?)");
    $stmt->execute([
        $product,$releaseId,$candidate?(int)$candidate['id']:null,$healthId,$severity,$title,$symptoms,$actorUserId>0?$actorUserId:null
    ]);
    $id=(int)$pdo->lastInsertId();
    $affected=client_release_incident_collect_affected_v130($pdo,$id);
    client_release_incident_event_v130($pdo,$id,$actorUserId,'incident_opened','','open',[
        'severity'=>$severity,'affected_clients'=>$affected,'recovery_release_id'=>$candidate?(int)$candidate['id']:null
    ]);
    client_release_audit_v110($pdo,$actorUserId,$product,$releaseId,'incident_opened','','open',[
        'incident_id'=>$id,'severity'=>$severity,'affected_clients'=>$affected
    ]);
    return client_release_incident_v130($pdo,$id)??[];
}

function client_release_incident_contain_v130(PDO $pdo,int $incidentId,int $actorUserId): array
{
    $incident=client_release_incident_v130($pdo,$incidentId);
    if(!$incident)throw new RuntimeException('Release incident was not found.');
    if((string)$incident['status']==='resolved')throw new RuntimeException('Resolved incidents cannot be changed.');
    $product=(string)$incident['product'];$releaseId=(int)$incident['release_id'];
    $release=client_release_release_row_v110($pdo,$product,$releaseId);
    if(!$release)throw new RuntimeException('Affected client release was not found.');
    $roll=client_release_rollout_for_v110($pdo,$product,$releaseId,$release);
    $from=(string)$incident['status'];
    if(!in_array((string)($roll['lifecycle_state']??''),['paused','withdrawn'],true)){
        client_release_rollout_update_v110($pdo,$product,$releaseId,[
            'lifecycle_state'=>'paused','rollout_percent'=>0,
            'summary'=>(string)($roll['summary']??''),
            'known_issues'=>(string)($roll['known_issues']??''),
            'compatibility_notes'=>(string)($roll['compatibility_notes']??''),
        ],$actorUserId);
    }
    $stmt=$pdo->prepare("UPDATE client_release_incidents_v130 SET status='contained',contained_by_user_id=?,contained_at=COALESCE(contained_at,NOW()) WHERE id=?");
    $stmt->execute([$actorUserId>0?$actorUserId:null,$incidentId]);
    client_release_incident_collect_affected_v130($pdo,$incidentId);
    client_release_incident_event_v130($pdo,$incidentId,$actorUserId,'rollout_contained',$from,'contained',['affected_release_state'=>'paused']);
    client_release_audit_v110($pdo,$actorUserId,$product,$releaseId,'incident_contained',$from,'contained',['incident_id'=>$incidentId]);
    return client_release_incident_v130($pdo,$incidentId)??[];
}

function client_release_incident_recovery_release_valid_v130(PDO $pdo,array $incident,int $recoveryReleaseId): bool
{
    if($recoveryReleaseId<1||(int)$incident['release_id']===$recoveryReleaseId)return false;
    $affected=client_release_release_row_v110($pdo,(string)$incident['product'],(int)$incident['release_id']);
    $recovery=client_release_release_row_v110($pdo,(string)$incident['product'],$recoveryReleaseId);
    if(!$affected||!$recovery)return false;
    if((string)($affected['channel']??'stable')!==(string)($recovery['channel']??'stable'))return false;
    $roll=client_release_rollout_for_v110($pdo,(string)$incident['product'],$recoveryReleaseId,$recovery);
    return in_array((string)($roll['lifecycle_state']??''),['superseded','general_availability'],true);
}

function client_release_incident_start_recovery_v130(PDO $pdo,int $incidentId,int $recoveryReleaseId,int $percent,int $actorUserId): array
{
    $incident=client_release_incident_v130($pdo,$incidentId);
    if(!$incident)throw new RuntimeException('Release incident was not found.');
    if((string)$incident['status']!=='contained'){
        throw new RuntimeException('Contain the affected rollout before starting or changing the recovery target.');
    }
    if(!client_release_incident_recovery_release_valid_v130($pdo,$incident,$recoveryReleaseId)){
        throw new RuntimeException('Choose a prior known-good release from the same channel.');
    }
    $allowed=[10,25,50,100];
    if(!in_array($percent,$allowed,true))$percent=10;
    $from=(string)$incident['status'];
    $recoveryStatus=$percent===100?'monitoring':'recovering';
    $monitoringSql=$percent===100?'NOW()':'NULL';
    $stmt=$pdo->prepare("UPDATE client_release_incidents_v130
        SET recovery_release_id=?,recovery_percent=?,status=?,recovery_started_at=COALESCE(recovery_started_at,NOW()),monitoring_started_at={$monitoringSql}
        WHERE id=?");
    $stmt->execute([$recoveryReleaseId,$percent,$recoveryStatus,$incidentId]);
    client_release_incident_collect_affected_v130($pdo,$incidentId);
    client_release_incident_refresh_v130($pdo,$incidentId,0,false);
    client_release_incident_event_v130($pdo,$incidentId,$actorUserId,'recovery_started',$from,$recoveryStatus,[
        'recovery_release_id'=>$recoveryReleaseId,'recovery_percent'=>$percent
    ]);
    client_release_audit_v110($pdo,$actorUserId,(string)$incident['product'],(int)$incident['release_id'],'incident_recovery_started',$from,$recoveryStatus,[
        'incident_id'=>$incidentId,'recovery_release_id'=>$recoveryReleaseId,'recovery_percent'=>$percent
    ]);
    return client_release_incident_v130($pdo,$incidentId)??[];
}

function client_release_incident_set_cohort_v130(PDO $pdo,int $incidentId,int $percent,int $actorUserId): array
{
    $incident=client_release_incident_v130($pdo,$incidentId);
    if(!$incident)throw new RuntimeException('Release incident was not found.');
    if(!in_array((string)$incident['status'],['recovering','monitoring','contained'],true))throw new RuntimeException('This incident is not in recovery.');
    $allowed=[0,10,25,50,100];
    if(!in_array($percent,$allowed,true))throw new RuntimeException('Choose a supported recovery cohort.');
    if($percent>0&&!(int)($incident['recovery_release_id']??0))throw new RuntimeException('Choose a recovery release before opening a recovery cohort.');
    $fromPercent=(int)$incident['recovery_percent'];
    $status=$percent===0?'contained':($percent===100?'monitoring':'recovering');
    $monitoringSql=$percent===100?'NOW()':'NULL';
    $pdo->prepare("UPDATE client_release_incidents_v130 SET recovery_percent=?,status=?,monitoring_started_at={$monitoringSql} WHERE id=?")->execute([$percent,$status,$incidentId]);
    client_release_incident_refresh_v130($pdo,$incidentId,0,false);
    client_release_incident_event_v130($pdo,$incidentId,$actorUserId,$percent===0?'recovery_paused':'recovery_cohort_changed',
        (string)$incident['status'],$status,['from_percent'=>$fromPercent,'to_percent'=>$percent]);
    client_release_audit_v110($pdo,$actorUserId,(string)$incident['product'],(int)$incident['release_id'],'incident_recovery_cohort',
        (string)$incident['status'],$status,['incident_id'=>$incidentId,'from_percent'=>$fromPercent,'to_percent'=>$percent]);
    return client_release_incident_v130($pdo,$incidentId)??[];
}

function client_release_incident_client_presence_v130(PDO $pdo,string $product,int $userId,string $scopeKey): array
{
    $lastSeen=null;$online=true;$version='';
    if($product==='browser_companion'){
        try{
            $stmt=$pdo->prepare("SELECT extension_version,last_used_at,device_status FROM extension_devices_v2000 WHERE user_id=? AND public_id=? AND revoked_at IS NULL LIMIT 1");
            $stmt->execute([$userId,$scopeKey]);
            $row=$stmt->fetch();
            if(!$row)return ['online'=>false,'last_seen_at'=>null,'version'=>''];
            $version=(string)($row['extension_version']??'');
            $lastSeen=(string)($row['last_used_at']??'');
            $online=(string)($row['device_status']??'active')==='active';
        }catch(Throwable $e){return ['online'=>true,'last_seen_at'=>null,'version'=>''];}
    }else{
        try{
            $stmt=$pdo->prepare("SELECT installed_version,last_seen_at,status FROM homeserver_connections WHERE user_id=? AND homeserver_token_enc IS NOT NULL LIMIT 1");
            $stmt->execute([$userId]);
            $row=$stmt->fetch();
            if(!$row)return ['online'=>false,'last_seen_at'=>null,'version'=>''];
            $version=(string)($row['installed_version']??'');
            $lastSeen=(string)($row['last_seen_at']??'');
            $online=!in_array(strtolower((string)($row['status']??'')),['revoked','disconnected','disabled'],true);
        }catch(Throwable $e){return ['online'=>true,'last_seen_at'=>null,'version'=>''];}
    }
    if($lastSeen!==''){
        $ts=strtotime($lastSeen);
        if($ts!==false&&$ts<time()-72*3600)$online=false;
    }
    return ['online'=>$online,'last_seen_at'=>$lastSeen!==''?$lastSeen:null,'version'=>$version];
}

function client_release_incident_refresh_v130(PDO $pdo,int $incidentId,int $actorUserId=0,bool $recordEvent=true): array
{
    $incident=client_release_incident_v130($pdo,$incidentId);
    if(!$incident)throw new RuntimeException('Release incident was not found.');
    client_release_incident_collect_affected_v130($pdo,$incidentId);
    $product=(string)$incident['product'];
    $recoveryId=(int)($incident['recovery_release_id']??0);
    $recovery=$recoveryId>0?client_release_release_row_v110($pdo,$product,$recoveryId):null;
    $targetVersion=(string)($recovery['version']??'');
    $percent=(int)$incident['recovery_percent'];

    $stmt=$pdo->prepare('SELECT * FROM client_release_incident_clients_v130 WHERE incident_id=? ORDER BY user_id,scope_key');
    $stmt->execute([$incidentId]);
    $rows=$stmt->fetchAll()?:[];
    $update=$pdo->prepare("UPDATE client_release_incident_clients_v130
        SET recovery_state=?,last_seen_at=COALESCE(?,last_seen_at),recovered_at=?,updated_at=NOW()
        WHERE incident_id=? AND user_id=? AND scope_key=?");

    foreach($rows as $row){
        $userId=(int)$row['user_id'];$scope=(string)$row['scope_key'];
        if(!empty($row['acknowledged_at']))continue;
        $presence=client_release_incident_client_presence_v130($pdo,$product,$userId,$scope);
        $state='queued';$recoveredAt=null;
        $targetState=$recoveryId>0?client_release_update_state_v110($pdo,$userId,$product,$scope,$recoveryId):null;
        $reported=(string)($targetState['update_state']??'');
        if($targetVersion!==''&&(string)$presence['version']===$targetVersion){
            $state='recovered';$recoveredAt=(string)($row['recovered_at']??'')!==''?(string)$row['recovered_at']:gmdate('Y-m-d H:i:s');
        }elseif($reported==='installed'){
            $state='recovered';$recoveredAt=(string)($row['recovered_at']??'')!==''?(string)$row['recovered_at']:gmdate('Y-m-d H:i:s');
        }elseif($reported==='failed'){
            $state='recovery_failed';
        }elseif($reported==='downloaded'){
            $state='downloaded';
        }elseif(empty($presence['online'])){
            $state='offline';
        }elseif($percent>0&&(int)$row['recovery_bucket']<=$percent){
            $state='pending';
        }
        $update->execute([$state,$presence['last_seen_at'],$recoveredAt,$incidentId,$userId,$scope]);
    }

    $stats=client_release_incident_stats_v130($pdo,$incidentId,false);
    if($recordEvent){
        client_release_incident_event_v130($pdo,$incidentId,$actorUserId,'fleet_refreshed',(string)$incident['status'],(string)$incident['status'],$stats);
    }
    return $stats;
}

function client_release_incident_clients_v130(PDO $pdo,int $incidentId): array
{
    if(!client_release_incident_schema_ready_v130($pdo))return [];
    $stmt=$pdo->prepare('SELECT * FROM client_release_incident_clients_v130 WHERE incident_id=? ORDER BY recovery_state,user_id,scope_key');
    $stmt->execute([$incidentId]);
    return $stmt->fetchAll()?:[];
}

function client_release_incident_stats_v130(PDO $pdo,int $incidentId,bool $refresh=false): array
{
    if($refresh)return client_release_incident_refresh_v130($pdo,$incidentId,0,false);
    $stats=['affected'=>0,'recovered'=>0,'pending'=>0,'downloaded'=>0,'recovery_failed'=>0,'offline'=>0,'queued'=>0,'acknowledged'=>0];
    foreach(client_release_incident_clients_v130($pdo,$incidentId) as $row){
        $stats['affected']++;
        $state=(string)$row['recovery_state'];
        if(isset($stats[$state]))$stats[$state]++;
    }
    $attempted=$stats['recovered']+$stats['recovery_failed'];
    $stats['recovery_failure_rate_bps']=$attempted>0?(int)round(($stats['recovery_failed']/$attempted)*10000):0;
    $required=max(0,$stats['affected']-$stats['acknowledged']);
    $stats['recovery_rate_bps']=$required>0?(int)round(($stats['recovered']/$required)*10000):10000;
    $stats['unresolved']=$stats['pending']+$stats['downloaded']+$stats['recovery_failed']+$stats['offline']+$stats['queued'];
    return $stats;
}

function client_release_incident_acknowledge_client_v130(PDO $pdo,int $incidentId,int $userId,string $scopeKey,string $reason,int $actorUserId): void
{
    $incident=client_release_incident_v130($pdo,$incidentId);
    if(!$incident)throw new RuntimeException('Release incident was not found.');
    if((string)$incident['status']==='resolved')throw new RuntimeException('Resolved incidents cannot be changed.');
    $scopeKey=client_release_scope_key_v110($scopeKey);
    $reason=mb_strimwidth(trim($reason),0,500,'');
    if($reason==='')throw new RuntimeException('Add a reason before acknowledging a recovery exception.');
    $stmt=$pdo->prepare("UPDATE client_release_incident_clients_v130
        SET recovery_state='acknowledged',acknowledged_at=NOW(),acknowledged_by_user_id=?,acknowledged_reason=?
        WHERE incident_id=? AND user_id=? AND scope_key=?");
    $stmt->execute([$actorUserId>0?$actorUserId:null,$reason,$incidentId,$userId,$scopeKey]);
    if($stmt->rowCount()<1)throw new RuntimeException('Affected client was not found.');
    client_release_incident_event_v130($pdo,$incidentId,$actorUserId,'client_exception_acknowledged','','acknowledged',[
        'user_id'=>$userId,'scope_key'=>$scopeKey,'reason'=>$reason
    ]);
}

function client_release_recovery_applicable_release_v130(PDO $pdo,string $product,string $channel,int $userId,string $scopeKey='account'): ?array
{
    if(!client_release_incident_schema_ready_v130($pdo)||$userId<1)return null;
    $scopeKey=client_release_scope_key_v110($scopeKey);
    $stmt=$pdo->prepare("SELECT i.*,c.recovery_bucket,c.recovery_state,c.acknowledged_at
        FROM client_release_incidents_v130 i
        JOIN client_release_incident_clients_v130 c ON c.incident_id=i.id
        WHERE i.product=? AND i.status IN ('recovering','monitoring') AND i.recovery_release_id IS NOT NULL
          AND i.recovery_percent>0 AND c.user_id=? AND c.scope_key=?
        ORDER BY i.id DESC LIMIT 1");
    $stmt->execute([$product,$userId,$scopeKey]);
    $incident=$stmt->fetch();
    if(!$incident||!empty($incident['acknowledged_at']))return null;
    if((int)$incident['recovery_bucket']>(int)$incident['recovery_percent'])return null;
    $release=client_release_release_row_v110($pdo,$product,(int)$incident['recovery_release_id']);
    if(!$release||(string)($release['channel']??'stable')!==$channel)return null;
    $release['_rollout']=[
        'lifecycle_state'=>'limited','rollout_percent'=>(int)$incident['recovery_percent'],
        'summary'=>'Recovery release for incident #'.(int)$incident['id'],
        'known_issues'=>'','compatibility_notes'=>''
    ];
    $release['_cohort_bucket']=(int)$incident['recovery_bucket'];
    $release['_incident_id']=(int)$incident['id'];
    return $release;
}

function client_release_incident_closure_readiness_v130(PDO $pdo,int $incidentId): array
{
    $incident=client_release_incident_v130($pdo,$incidentId);
    if(!$incident)throw new RuntimeException('Release incident was not found.');
    $stats=client_release_incident_refresh_v130($pdo,$incidentId,0,false);
    $reasons=[];
    if((int)$incident['recovery_percent']<100)$reasons[]='Recovery cohort has not reached 100%.';
    if((int)$stats['recovery_rate_bps']<(int)$incident['min_recovery_rate_bps'])$reasons[]='Recovered-client rate is below the closure threshold.';
    if((int)$stats['recovery_failure_rate_bps']>(int)$incident['max_recovery_failure_rate_bps'])$reasons[]='Recovery failure rate exceeds the closure threshold.';
    if((int)$stats['unresolved']>0)$reasons[]='Every remaining recovery exception must be recovered or explicitly acknowledged.';
    $started=(string)($incident['monitoring_started_at']??'');
    $hours=$started!==''&&strtotime($started)!==false?max(0.0,(time()-strtotime($started))/3600):0.0;
    if($hours<(int)$incident['verification_hours'])$reasons[]='The 100% recovery verification window is still open.';
    return ['ready'=>!$reasons,'reasons'=>$reasons,'stats'=>$stats,'verification_elapsed_hours'=>round($hours,2)];
}

function client_release_incident_resolve_v130(PDO $pdo,int $incidentId,array $input,int $actorUserId): array
{
    $incident=client_release_incident_v130($pdo,$incidentId);
    if(!$incident)throw new RuntimeException('Release incident was not found.');
    if((string)$incident['status']==='resolved')return $incident;
    if((string)$incident['status']!=='monitoring')throw new RuntimeException('Move recovery to 100% monitoring before resolving the incident.');
    $root=mb_strimwidth(trim((string)($input['root_cause']??'')),0,2000,'');
    $summary=mb_strimwidth(trim((string)($input['resolution_summary']??'')),0,2000,'');
    $lessons=mb_strimwidth(trim((string)($input['lessons_learned']??'')),0,4000,'');
    if($root===''||$summary==='')throw new RuntimeException('Root cause and resolution summary are required before closing an incident.');
    $ready=client_release_incident_closure_readiness_v130($pdo,$incidentId);
    if(empty($ready['ready']))throw new RuntimeException(implode(' ',$ready['reasons']));
    $from=(string)$incident['status'];
    $stmt=$pdo->prepare("UPDATE client_release_incidents_v130
        SET status='resolved',root_cause=?,resolution_summary=?,lessons_learned=?,resolved_by_user_id=?,resolved_at=NOW()
        WHERE id=?");
    $stmt->execute([$root,$summary,$lessons,$actorUserId>0?$actorUserId:null,$incidentId]);
    client_release_incident_event_v130($pdo,$incidentId,$actorUserId,'incident_resolved',$from,'resolved',[
        'root_cause'=>$root,'resolution_summary'=>$summary,'stats'=>$ready['stats']
    ]);
    client_release_audit_v110($pdo,$actorUserId,(string)$incident['product'],(int)$incident['release_id'],'incident_resolved',$from,'resolved',[
        'incident_id'=>$incidentId,'stats'=>$ready['stats']
    ]);
    return client_release_incident_v130($pdo,$incidentId)??[];
}

function client_release_incident_list_v130(PDO $pdo,int $limit=30): array
{
    if(!client_release_incident_schema_ready_v130($pdo))return [];
    $limit=max(1,min(100,$limit));
    $rows=$pdo->query("SELECT * FROM client_release_incidents_v130 ORDER BY (status='resolved') ASC,id DESC LIMIT {$limit}")->fetchAll()?:[];
    foreach($rows as &$row){
        $row['_affected_release']=client_release_release_row_v110($pdo,(string)$row['product'],(int)$row['release_id']);
        $row['_recovery_release']=(int)($row['recovery_release_id']??0)>0
            ?client_release_release_row_v110($pdo,(string)$row['product'],(int)$row['recovery_release_id']):null;
        $row['_stats']=client_release_incident_stats_v130($pdo,(int)$row['id'],false);
        if((string)$row['status']!=='resolved')$row['_closure']=client_release_incident_closure_readiness_v130($pdo,(int)$row['id']);
    }
    unset($row);
    return $rows;
}

function client_release_incident_events_v130(PDO $pdo,int $incidentId,int $limit=50): array
{
    if(!client_release_incident_schema_ready_v130($pdo))return [];
    $limit=max(1,min(200,$limit));
    $stmt=$pdo->prepare("SELECT * FROM client_release_incident_events_v130 WHERE incident_id=? ORDER BY id DESC LIMIT {$limit}");
    $stmt->execute([$incidentId]);
    return $stmt->fetchAll()?:[];
}
