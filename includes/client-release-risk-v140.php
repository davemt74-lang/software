<?php
declare(strict_types=1);

const VP3_CLIENT_RELEASE_RISK_V140 = 'client-release-risk-v140-20260922';

function client_release_risk_schema_ready_v140(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach([
        'client_release_risk_profiles_v140',
        'client_release_risk_policy_v140',
        'client_release_risk_snapshots_v140',
        'client_release_risk_reviews_v140',
    ] as $table){
        if(!client_release_table_ready_v110($pdo,$table))return false;
    }
    return true;
}

function client_release_risk_ensure_schema_v140(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    client_release_incident_ensure_schema_v130($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_risk_profiles_v140 (
        product VARCHAR(40) NOT NULL,
        release_id BIGINT UNSIGNED NOT NULL,
        change_size VARCHAR(16) NOT NULL DEFAULT 'unknown',
        touches_auth TINYINT(1) NOT NULL DEFAULT 0,
        touches_storage TINYINT(1) NOT NULL DEFAULT 0,
        touches_update_path TINYINT(1) NOT NULL DEFAULT 0,
        touches_permissions TINYINT(1) NOT NULL DEFAULT 0,
        touches_compatibility TINYINT(1) NOT NULL DEFAULT 0,
        touches_schema TINYINT(1) NOT NULL DEFAULT 0,
        touches_network TINYINT(1) NOT NULL DEFAULT 0,
        rollback_complexity VARCHAR(16) NOT NULL DEFAULT 'normal',
        notes VARCHAR(2000) NOT NULL DEFAULT '',
        updated_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(product,release_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_risk_policy_v140 (
        product VARCHAR(40) NOT NULL,
        channel VARCHAR(16) NOT NULL,
        lookback_releases TINYINT UNSIGNED NOT NULL DEFAULT 10,
        min_comparable_releases TINYINT UNSIGNED NOT NULL DEFAULT 3,
        low_max_score TINYINT UNSIGNED NOT NULL DEFAULT 24,
        moderate_max_score TINYINT UNSIGNED NOT NULL DEFAULT 49,
        high_max_score TINYINT UNSIGNED NOT NULL DEFAULT 74,
        incident_rate_warning_bps SMALLINT UNSIGNED NOT NULL DEFAULT 1500,
        failure_rate_warning_bps SMALLINT UNSIGNED NOT NULL DEFAULT 700,
        compatibility_rate_warning_bps SMALLINT UNSIGNED NOT NULL DEFAULT 300,
        updated_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(product,channel)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_risk_snapshots_v140 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product VARCHAR(40) NOT NULL,
        release_id BIGINT UNSIGNED NOT NULL,
        channel VARCHAR(16) NOT NULL,
        risk_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
        risk_level VARCHAR(16) NOT NULL,
        confidence VARCHAR(16) NOT NULL,
        recommendation VARCHAR(40) NOT NULL,
        comparable_releases INT UNSIGNED NOT NULL DEFAULT 0,
        incident_releases INT UNSIGNED NOT NULL DEFAULT 0,
        historical_incident_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        historical_avg_failure_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        current_failure_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        current_compat_failure_rate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        active_incident TINYINT(1) NOT NULL DEFAULT 0,
        matching_incident_domains INT UNSIGNED NOT NULL DEFAULT 0,
        factors_json LONGTEXT NULL,
        evidence_json LONGTEXT NULL,
        assessed_by_user_id INT UNSIGNED NULL,
        assessed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client_release_risk_release (product,release_id,assessed_at),
        INDEX idx_client_release_risk_level (risk_level,recommendation,assessed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_risk_reviews_v140 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        actor_user_id INT UNSIGNED NULL,
        product VARCHAR(40) NOT NULL,
        release_id BIGINT UNSIGNED NOT NULL,
        risk_snapshot_id BIGINT UNSIGNED NOT NULL,
        decision VARCHAR(24) NOT NULL DEFAULT 'reviewed',
        note VARCHAR(1000) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client_release_risk_review_release (product,release_id,created_at),
        INDEX idx_client_release_risk_review_snapshot (risk_snapshot_id,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function client_release_risk_domains_v140(): array
{
    return [
        'auth'=>'Authentication / sessions',
        'storage'=>'Storage / persistence',
        'update_path'=>'Updater / install path',
        'permissions'=>'Permissions / authorization',
        'compatibility'=>'Compatibility / prerequisites',
        'schema'=>'Database / schema',
        'network'=>'Network / connectivity',
    ];
}

function client_release_risk_default_profile_v140(): array
{
    return [
        'change_size'=>'unknown',
        'touches_auth'=>0,
        'touches_storage'=>0,
        'touches_update_path'=>0,
        'touches_permissions'=>0,
        'touches_compatibility'=>0,
        'touches_schema'=>0,
        'touches_network'=>0,
        'rollback_complexity'=>'normal',
        'notes'=>'',
    ];
}

function client_release_risk_profile_v140(PDO $pdo,string $product,int $releaseId): array
{
    $defaults=client_release_risk_default_profile_v140();
    if(!client_release_risk_schema_ready_v140($pdo))return $defaults;
    $stmt=$pdo->prepare('SELECT * FROM client_release_risk_profiles_v140 WHERE product=? AND release_id=? LIMIT 1');
    $stmt->execute([$product,$releaseId]);
    $row=$stmt->fetch();
    return $row?array_merge($defaults,$row):$defaults;
}

function client_release_risk_profile_update_v140(PDO $pdo,string $product,int $releaseId,array $input,int $actorUserId): array
{
    if(!client_release_product_valid_v110($product))throw new RuntimeException('Unsupported release product.');
    if(!client_release_release_row_v110($pdo,$product,$releaseId))throw new RuntimeException('Client release was not found.');
    client_release_risk_ensure_schema_v140($pdo);

    $size=strtolower(trim((string)($input['change_size']??'unknown')));
    if(!in_array($size,['unknown','small','medium','large'],true))$size='unknown';
    $rollback=strtolower(trim((string)($input['rollback_complexity']??'normal')));
    if(!in_array($rollback,['easy','normal','hard'],true))$rollback='normal';
    $values=[];
    foreach(array_keys(client_release_risk_domains_v140()) as $domain){
        $values['touches_'.$domain]=!empty($input['touches_'.$domain])?1:0;
    }
    $notes=mb_strimwidth(trim((string)($input['notes']??'')),0,2000,'');

    $stmt=$pdo->prepare("INSERT INTO client_release_risk_profiles_v140
      (product,release_id,change_size,touches_auth,touches_storage,touches_update_path,touches_permissions,
       touches_compatibility,touches_schema,touches_network,rollback_complexity,notes,updated_by_user_id)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE change_size=VALUES(change_size),touches_auth=VALUES(touches_auth),
       touches_storage=VALUES(touches_storage),touches_update_path=VALUES(touches_update_path),
       touches_permissions=VALUES(touches_permissions),touches_compatibility=VALUES(touches_compatibility),
       touches_schema=VALUES(touches_schema),touches_network=VALUES(touches_network),
       rollback_complexity=VALUES(rollback_complexity),notes=VALUES(notes),updated_by_user_id=VALUES(updated_by_user_id)");
    $stmt->execute([
        $product,$releaseId,$size,$values['touches_auth'],$values['touches_storage'],$values['touches_update_path'],
        $values['touches_permissions'],$values['touches_compatibility'],$values['touches_schema'],$values['touches_network'],
        $rollback,$notes,$actorUserId>0?$actorUserId:null
    ]);
    client_release_audit_v110($pdo,$actorUserId,$product,$releaseId,'risk_profile_update','','',[
        'change_size'=>$size,'rollback_complexity'=>$rollback,'domains'=>$values
    ]);
    return client_release_risk_profile_v140($pdo,$product,$releaseId);
}

function client_release_risk_default_policy_v140(): array
{
    return [
        'lookback_releases'=>10,
        'min_comparable_releases'=>3,
        'low_max_score'=>24,
        'moderate_max_score'=>49,
        'high_max_score'=>74,
        'incident_rate_warning_bps'=>1500,
        'failure_rate_warning_bps'=>700,
        'compatibility_rate_warning_bps'=>300,
    ];
}

function client_release_risk_policy_v140(PDO $pdo,string $product,string $channel): array
{
    $defaults=client_release_risk_default_policy_v140();
    if(!client_release_risk_schema_ready_v140($pdo))return $defaults;
    $stmt=$pdo->prepare('SELECT * FROM client_release_risk_policy_v140 WHERE product=? AND channel=? LIMIT 1');
    $stmt->execute([$product,$channel]);
    $row=$stmt->fetch();
    return $row?array_merge($defaults,$row):$defaults;
}

function client_release_risk_policy_update_v140(PDO $pdo,string $product,string $channel,array $input,int $actorUserId): array
{
    if(!client_release_product_valid_v110($product))throw new RuntimeException('Unsupported release product.');
    $channel=client_release_channel_v110($channel);
    client_release_risk_ensure_schema_v140($pdo);
    $policy=[
        'lookback_releases'=>max(3,min(50,(int)($input['lookback_releases']??10))),
        'min_comparable_releases'=>max(1,min(20,(int)($input['min_comparable_releases']??3))),
        'low_max_score'=>max(5,min(40,(int)($input['low_max_score']??24))),
        'moderate_max_score'=>max(20,min(70,(int)($input['moderate_max_score']??49))),
        'high_max_score'=>max(40,min(95,(int)($input['high_max_score']??74))),
        'incident_rate_warning_bps'=>max(0,min(10000,(int)($input['incident_rate_warning_bps']??1500))),
        'failure_rate_warning_bps'=>max(0,min(10000,(int)($input['failure_rate_warning_bps']??700))),
        'compatibility_rate_warning_bps'=>max(0,min(10000,(int)($input['compatibility_rate_warning_bps']??300))),
    ];
    if(!($policy['low_max_score']<$policy['moderate_max_score']&&$policy['moderate_max_score']<$policy['high_max_score'])){
        throw new RuntimeException('Risk score thresholds must increase from low to moderate to high.');
    }
    $stmt=$pdo->prepare("INSERT INTO client_release_risk_policy_v140
      (product,channel,lookback_releases,min_comparable_releases,low_max_score,moderate_max_score,high_max_score,
       incident_rate_warning_bps,failure_rate_warning_bps,compatibility_rate_warning_bps,updated_by_user_id)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE lookback_releases=VALUES(lookback_releases),min_comparable_releases=VALUES(min_comparable_releases),
       low_max_score=VALUES(low_max_score),moderate_max_score=VALUES(moderate_max_score),high_max_score=VALUES(high_max_score),
       incident_rate_warning_bps=VALUES(incident_rate_warning_bps),failure_rate_warning_bps=VALUES(failure_rate_warning_bps),
       compatibility_rate_warning_bps=VALUES(compatibility_rate_warning_bps),updated_by_user_id=VALUES(updated_by_user_id)");
    $stmt->execute([
        $product,$channel,$policy['lookback_releases'],$policy['min_comparable_releases'],$policy['low_max_score'],
        $policy['moderate_max_score'],$policy['high_max_score'],$policy['incident_rate_warning_bps'],
        $policy['failure_rate_warning_bps'],$policy['compatibility_rate_warning_bps'],$actorUserId>0?$actorUserId:null
    ]);
    client_release_audit_v110($pdo,$actorUserId,$product,null,'risk_policy_update','','',$policy+['channel'=>$channel]);
    return client_release_risk_policy_v140($pdo,$product,$channel);
}

function client_release_risk_profile_domains_v140(array $profile): array
{
    $out=[];
    foreach(client_release_risk_domains_v140() as $domain=>$label){
        if(!empty($profile['touches_'.$domain]))$out[]=$domain;
    }
    return $out;
}

function client_release_risk_text_domains_v140(string $text): array
{
    $text=strtolower($text);
    $map=[
        'auth'=>['auth','login','session','token','oauth','credential'],
        'storage'=>['storage','disk','file','filesystem','persist','cache'],
        'update_path'=>['update','upgrade','installer','install','rollback','package'],
        'permissions'=>['permission','authorize','authorization','access control','role'],
        'compatibility'=>['compatib','unsupported','prerequisite','version mismatch','platform'],
        'schema'=>['schema','migration','database','mysql','mariadb','column','table'],
        'network'=>['network','connection','connectivity','timeout','dns','relay','websocket'],
    ];
    $domains=[];
    foreach($map as $domain=>$needles){
        foreach($needles as $needle){
            if(str_contains($text,$needle)){$domains[]=$domain;break;}
        }
    }
    return array_values(array_unique($domains));
}

function client_release_risk_comparables_v140(PDO $pdo,string $product,int $releaseId,string $channel,int $limit): array
{
    $rows=[];
    foreach(client_release_admin_rollouts_v110($pdo,$product) as $release){
        if((int)$release['id']===$releaseId)continue;
        if((string)($release['channel']??'stable')!==$channel)continue;
        if((int)$release['id']>$releaseId)continue;
        $rows[]=$release;
    }
    usort($rows,static fn(array $a,array $b): int=>(int)$b['id']<=>(int)$a['id']);
    return array_slice($rows,0,max(1,$limit));
}

function client_release_risk_latest_health_for_v140(PDO $pdo,string $product,int $releaseId): ?array
{
    return client_release_health_previous_snapshot_v120($pdo,$product,$releaseId);
}

function client_release_risk_incidents_for_release_v140(PDO $pdo,string $product,int $releaseId): array
{
    if(!client_release_incident_schema_ready_v130($pdo))return [];
    $stmt=$pdo->prepare('SELECT * FROM client_release_incidents_v130 WHERE product=? AND release_id=? ORDER BY id DESC');
    $stmt->execute([$product,$releaseId]);
    return $stmt->fetchAll()?:[];
}

function client_release_risk_learning_v140(PDO $pdo,string $product,int $releaseId,string $channel,array $profile,array $policy): array
{
    $comparables=client_release_risk_comparables_v140($pdo,$product,$releaseId,$channel,(int)$policy['lookback_releases']);
    $incidentReleases=0;$incidentCount=0;$failureRates=[];$domainCounts=[];$matching=0;
    $profileDomains=client_release_risk_profile_domains_v140($profile);
    $history=[];

    foreach($comparables as $release){
        $rid=(int)$release['id'];
        $incidents=client_release_risk_incidents_for_release_v140($pdo,$product,$rid);
        if($incidents)$incidentReleases++;
        $incidentCount+=count($incidents);
        $domains=[];
        foreach($incidents as $incident){
            $text=(string)($incident['symptoms']??'').' '.(string)($incident['root_cause']??'').' '.(string)($incident['resolution_summary']??'').' '.(string)($incident['lessons_learned']??'');
            foreach(client_release_risk_text_domains_v140($text) as $domain){
                $domains[]=$domain;
                $domainCounts[$domain]=($domainCounts[$domain]??0)+1;
            }
        }
        $domains=array_values(array_unique($domains));
        foreach($profileDomains as $domain){if(in_array($domain,$domains,true))$matching++;}
        $health=client_release_risk_latest_health_for_v140($pdo,$product,$rid);
        if($health)$failureRates[]=(int)($health['failure_rate_bps']??0);
        $history[]=[
            'release_id'=>$rid,'version'=>(string)($release['version']??''),'incidents'=>count($incidents),
            'incident_domains'=>$domains,'failure_rate_bps'=>$health?(int)($health['failure_rate_bps']??0):null,
            'health_status'=>$health?(string)($health['health_status']??''):null
        ];
    }

    arsort($domainCounts);
    $count=count($comparables);
    return [
        'comparable_releases'=>$count,
        'incident_releases'=>$incidentReleases,
        'incident_count'=>$incidentCount,
        'historical_incident_rate_bps'=>$count>0?(int)round(($incidentReleases/$count)*10000):0,
        'historical_avg_failure_rate_bps'=>$failureRates?(int)round(array_sum($failureRates)/count($failureRates)):0,
        'domain_counts'=>$domainCounts,
        'matching_incident_domains'=>$matching,
        'history'=>$history,
    ];
}

function client_release_risk_level_v140(int $score,array $policy): string
{
    if($score<=(int)$policy['low_max_score'])return 'low';
    if($score<=(int)$policy['moderate_max_score'])return 'moderate';
    if($score<=(int)$policy['high_max_score'])return 'high';
    return 'critical';
}

function client_release_risk_confidence_v140(int $comparables,int $observed,int $minimum): string
{
    if($comparables>=$minimum&&$observed>=10)return 'high';
    if($comparables>=max(1,$minimum-1)||$observed>=3)return 'medium';
    return 'low';
}

function client_release_risk_assess_v140(PDO $pdo,string $product,int $releaseId): array
{
    if(!client_release_product_valid_v110($product))throw new RuntimeException('Unsupported release product.');
    $release=client_release_release_row_v110($pdo,$product,$releaseId);
    if(!$release)throw new RuntimeException('Client release was not found.');
    client_release_risk_ensure_schema_v140($pdo);

    $channel=(string)($release['channel']??'stable');
    $profile=client_release_risk_profile_v140($pdo,$product,$releaseId);
    $policy=client_release_risk_policy_v140($pdo,$product,$channel);
    $learning=client_release_risk_learning_v140($pdo,$product,$releaseId,$channel,$profile,$policy);
    $health=client_release_health_calculate_v120($pdo,$product,$releaseId);
    $activeIncident=client_release_incident_active_for_release_v130($pdo,$product,$releaseId);
    $priorIncidents=client_release_risk_incidents_for_release_v140($pdo,$product,$releaseId);

    $score=0;$factors=[];$evidence=[];
    $size=(string)$profile['change_size'];
    $sizePoints=['unknown'=>8,'small'=>4,'medium'=>10,'large'=>18][$size]??8;
    $score+=$sizePoints;
    $factors[]=['factor'=>'change_size','points'=>$sizePoints,'detail'=>'Change size: '.$size];

    $domains=client_release_risk_profile_domains_v140($profile);
    $domainPoints=min(21,count($domains)*3);
    if($domainPoints>0){$score+=$domainPoints;$factors[]=['factor'=>'change_domains','points'=>$domainPoints,'detail'=>implode(', ',$domains)];}

    $rollback=(string)$profile['rollback_complexity'];
    $rollbackPoints=['easy'=>0,'normal'=>4,'hard'=>10][$rollback]??4;
    $score+=$rollbackPoints;
    if($rollbackPoints>0)$factors[]=['factor'=>'rollback_complexity','points'=>$rollbackPoints,'detail'=>$rollback];

    $histRate=(int)$learning['historical_incident_rate_bps'];
    if($histRate>0){
        $histPoints=min(18,(int)round($histRate/500));
        if($histRate>=(int)$policy['incident_rate_warning_bps'])$histPoints=max($histPoints,8);
        $score+=$histPoints;
        $factors[]=['factor'=>'historical_incident_rate','points'=>$histPoints,'detail'=>client_release_health_percent_v120($histRate)];
    }

    $match=(int)$learning['matching_incident_domains'];
    if($match>0){
        $points=min(15,$match*4);$score+=$points;
        $factors[]=['factor'=>'recurring_incident_domains','points'=>$points,'detail'=>(string)$match.' historical domain matches'];
    }

    $currentFailure=(int)($health['failure_rate_bps']??0);
    if($currentFailure>0){
        $points=min(20,(int)round($currentFailure/400));
        if($currentFailure>=(int)$policy['failure_rate_warning_bps'])$points=max($points,8);
        $score+=$points;
        $factors[]=['factor'=>'current_failure_rate','points'=>$points,'detail'=>client_release_health_percent_v120($currentFailure)];
    }

    $currentCompat=(int)($health['compatibility_failure_rate_bps']??0);
    if($currentCompat>0){
        $points=min(12,(int)round($currentCompat/300));
        if($currentCompat>=(int)$policy['compatibility_rate_warning_bps'])$points=max($points,6);
        $score+=$points;
        $factors[]=['factor'=>'current_compatibility_failures','points'=>$points,'detail'=>client_release_health_percent_v120($currentCompat)];
    }

    $healthStatus=(string)($health['health_status']??'inactive');
    if($healthStatus==='critical'){$score+=25;$factors[]=['factor'=>'current_health','points'=>25,'detail'=>'critical'];}
    elseif($healthStatus==='hold'){$score+=15;$factors[]=['factor'=>'current_health','points'=>15,'detail'=>'hold'];}
    elseif($healthStatus==='insufficient_data'){$score+=5;$factors[]=['factor'=>'uncertainty','points'=>5,'detail'=>'health sample is still insufficient'];}

    if($priorIncidents){
        $points=min(25,count($priorIncidents)*10);$score+=$points;
        $factors[]=['factor'=>'release_incident_history','points'=>$points,'detail'=>count($priorIncidents).' incident(s) already recorded for this release'];
    }
    if($activeIncident){
        $score=max(95,$score);
        $factors[]=['factor'=>'active_incident','points'=>null,'detail'=>'Active incident #'.(int)$activeIncident['id']];
    }

    $score=max(0,min(100,$score));
    $riskLevel=client_release_risk_level_v140($score,$policy);
    $confidence=client_release_risk_confidence_v140(
        (int)$learning['comparable_releases'],(int)($health['observed_clients']??0),(int)$policy['min_comparable_releases']
    );
    $state=(string)(($health['rollout']['lifecycle_state']??'draft'));
    if($activeIncident)$recommendation='incident_controlled';
    elseif($riskLevel==='critical')$recommendation='stop_and_review';
    elseif($riskLevel==='high')$recommendation='conservative_canary';
    elseif($riskLevel==='moderate')$recommendation='extended_observation';
    elseif(in_array($state,['draft','testing'],true))$recommendation='standard_canary';
    else $recommendation='standard_rollout';

    $evidence=[
        'release'=>['version'=>(string)($release['version']??''),'channel'=>$channel,'lifecycle_state'=>$state],
        'profile'=>$profile,
        'health'=>[
            'status'=>$healthStatus,'recommendation'=>(string)($health['recommendation']??''),
            'observed_clients'=>(int)($health['observed_clients']??0),
            'failure_rate_bps'=>$currentFailure,'compatibility_failure_rate_bps'=>$currentCompat,
        ],
        'learning'=>$learning,
        'active_incident'=>$activeIncident?(int)$activeIncident['id']:null,
    ];

    return [
        'product'=>$product,'release_id'=>$releaseId,'version'=>(string)($release['version']??''),'channel'=>$channel,
        'risk_score'=>$score,'risk_level'=>$riskLevel,'confidence'=>$confidence,'recommendation'=>$recommendation,
        'factors'=>$factors,'evidence'=>$evidence,'profile'=>$profile,'policy'=>$policy,'health'=>$health,'learning'=>$learning,
        'active_incident'=>$activeIncident,
    ];
}

function client_release_risk_snapshot_v140(PDO $pdo,string $product,int $releaseId,int $actorUserId=0): array
{
    $risk=client_release_risk_assess_v140($pdo,$product,$releaseId);
    $learning=(array)$risk['learning'];$health=(array)$risk['health'];
    $stmt=$pdo->prepare("INSERT INTO client_release_risk_snapshots_v140
      (product,release_id,channel,risk_score,risk_level,confidence,recommendation,comparable_releases,incident_releases,
       historical_incident_rate_bps,historical_avg_failure_rate_bps,current_failure_rate_bps,current_compat_failure_rate_bps,
       active_incident,matching_incident_domains,factors_json,evidence_json,assessed_by_user_id)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $product,$releaseId,(string)$risk['channel'],(int)$risk['risk_score'],(string)$risk['risk_level'],
        (string)$risk['confidence'],(string)$risk['recommendation'],(int)$learning['comparable_releases'],
        (int)$learning['incident_releases'],(int)$learning['historical_incident_rate_bps'],
        (int)$learning['historical_avg_failure_rate_bps'],(int)($health['failure_rate_bps']??0),
        (int)($health['compatibility_failure_rate_bps']??0),$risk['active_incident']?1:0,
        (int)$learning['matching_incident_domains'],
        json_encode($risk['factors'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        json_encode($risk['evidence'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        $actorUserId>0?$actorUserId:null
    ]);
    $risk['snapshot_id']=(int)$pdo->lastInsertId();
    client_release_audit_v110($pdo,$actorUserId,$product,$releaseId,'risk_assessed','','',[
        'risk_snapshot_id'=>$risk['snapshot_id'],'risk_score'=>$risk['risk_score'],
        'risk_level'=>$risk['risk_level'],'confidence'=>$risk['confidence'],'recommendation'=>$risk['recommendation']
    ]);
    return $risk;
}

function client_release_risk_latest_snapshot_v140(PDO $pdo,string $product,int $releaseId): ?array
{
    if(!client_release_risk_schema_ready_v140($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM client_release_risk_snapshots_v140 WHERE product=? AND release_id=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$product,$releaseId]);
    $row=$stmt->fetch();
    return $row?:null;
}

function client_release_risk_review_v140(PDO $pdo,string $product,int $releaseId,int $snapshotId,string $decision,string $note,int $actorUserId): void
{
    client_release_risk_ensure_schema_v140($pdo);
    $decision=strtolower(trim($decision));
    if(!in_array($decision,['reviewed','accepted_risk','defer'],true))throw new RuntimeException('Choose a valid risk review decision.');
    $stmt=$pdo->prepare('SELECT * FROM client_release_risk_snapshots_v140 WHERE id=? AND product=? AND release_id=? LIMIT 1');
    $stmt->execute([$snapshotId,$product,$releaseId]);
    $snapshot=$stmt->fetch();
    if(!$snapshot)throw new RuntimeException('Risk snapshot was not found.');
    $note=mb_strimwidth(trim($note),0,1000,'');
    if($decision!=='reviewed'&&$note==='')throw new RuntimeException('Add an operator note for accepted-risk or defer decisions.');
    $insert=$pdo->prepare('INSERT INTO client_release_risk_reviews_v140 (actor_user_id,product,release_id,risk_snapshot_id,decision,note) VALUES (?,?,?,?,?,?)');
    $insert->execute([$actorUserId>0?$actorUserId:null,$product,$releaseId,$snapshotId,$decision,$note]);
    client_release_audit_v110($pdo,$actorUserId,$product,$releaseId,'risk_reviewed','','',[
        'risk_snapshot_id'=>$snapshotId,'decision'=>$decision,'note'=>$note,
        'risk_level'=>(string)$snapshot['risk_level'],'risk_score'=>(int)$snapshot['risk_score']
    ]);
}

function client_release_risk_recent_reviews_v140(PDO $pdo,int $limit=20): array
{
    if(!client_release_risk_schema_ready_v140($pdo))return [];
    $limit=max(1,min(100,$limit));
    return $pdo->query("SELECT * FROM client_release_risk_reviews_v140 ORDER BY id DESC LIMIT {$limit}")->fetchAll()?:[];
}

function client_release_risk_learning_summary_v140(PDO $pdo,string $product,string $channel,int $limit=50): array
{
    if(!client_release_incident_schema_ready_v130($pdo))return ['incidents'=>0,'resolved'=>0,'domains'=>[],'severity'=>[]];
    $stmt=$pdo->prepare("SELECT i.* FROM client_release_incidents_v130 i WHERE i.product=? ORDER BY i.id DESC LIMIT {$limit}");
    $stmt->execute([$product]);
    $incidents=[];$domains=[];$severity=[];$resolved=0;
    foreach($stmt->fetchAll()?:[] as $incident){
        $release=client_release_release_row_v110($pdo,$product,(int)$incident['release_id']);
        if(!$release||(string)($release['channel']??'stable')!==$channel)continue;
        $incidents[]=$incident;
        if((string)$incident['status']==='resolved')$resolved++;
        $sev=(string)$incident['severity'];$severity[$sev]=($severity[$sev]??0)+1;
        $text=(string)($incident['symptoms']??'').' '.(string)($incident['root_cause']??'').' '.(string)($incident['lessons_learned']??'');
        foreach(client_release_risk_text_domains_v140($text) as $domain)$domains[$domain]=($domains[$domain]??0)+1;
    }
    arsort($domains);arsort($severity);
    return ['incidents'=>count($incidents),'resolved'=>$resolved,'domains'=>$domains,'severity'=>$severity];
}

function client_release_risk_admin_summary_v140(PDO $pdo): array
{
    $out=['browser_companion'=>[],'homeserver'=>[]];
    foreach(array_keys($out) as $product){
        foreach(client_release_admin_rollouts_v110($pdo,$product) as $release){
            try{$out[$product][]=client_release_risk_assess_v140($pdo,$product,(int)$release['id']);}
            catch(Throwable $e){$out[$product][]=['product'=>$product,'release_id'=>(int)$release['id'],'version'=>(string)($release['version']??''),'error'=>$e->getMessage()];}
        }
    }
    return $out;
}

function client_release_risk_recommendation_label_v140(string $recommendation): string
{
    return match($recommendation){
        'incident_controlled'=>'Managed by active incident',
        'stop_and_review'=>'Stop and review before expansion',
        'conservative_canary'=>'Use a conservative canary',
        'extended_observation'=>'Extend observation before expansion',
        'standard_canary'=>'Standard canary is reasonable',
        'standard_rollout'=>'Standard governed rollout',
        default=>'Operator review recommended',
    };
}
