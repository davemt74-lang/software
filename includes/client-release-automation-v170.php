<?php
declare(strict_types=1);

const VP3_CLIENT_RELEASE_AUTOMATION_V170 = 'client-release-automation-v170-20260922';

function client_release_automation_schema_ready_v170(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    foreach([
        'client_release_automation_control_v170',
        'client_release_automation_policy_v170',
        'client_release_automation_holds_v170',
        'client_release_automation_proposals_v170',
        'client_release_automation_runs_v170',
        'client_release_automation_events_v170',
    ] as $table){
        if(!client_release_table_ready_v110($pdo,$table))return false;
    }
    return true;
}

function client_release_automation_ensure_schema_v170(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    client_fleet_ensure_schema_v160($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_automation_control_v170 (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        automation_enabled TINYINT(1) NOT NULL DEFAULT 0,
        kill_switch TINYINT(1) NOT NULL DEFAULT 0,
        dry_run TINYINT(1) NOT NULL DEFAULT 1,
        updated_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO client_release_automation_control_v170 (id,automation_enabled,kill_switch,dry_run) VALUES (1,0,0,1)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_automation_policy_v170 (
        product VARCHAR(40) NOT NULL,
        channel VARCHAR(16) NOT NULL,
        policy_enabled TINYINT(1) NOT NULL DEFAULT 0,
        execution_mode VARCHAR(24) NOT NULL DEFAULT 'recommend_only',
        auto_hold_enabled TINYINT(1) NOT NULL DEFAULT 1,
        rollout_progression_enabled TINYINT(1) NOT NULL DEFAULT 1,
        fleet_progression_enabled TINYINT(1) NOT NULL DEFAULT 1,
        proposal_expiry_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,
        cooldown_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
        fleet_completion_gate_bps SMALLINT UNSIGNED NOT NULL DEFAULT 9000,
        fleet_failure_hold_bps SMALLINT UNSIGNED NOT NULL DEFAULT 500,
        updated_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(product,channel)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_automation_holds_v170 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        scope_type VARCHAR(24) NOT NULL,
        product VARCHAR(40) NOT NULL,
        release_id BIGINT UNSIGNED NULL,
        campaign_id BIGINT UNSIGNED NULL,
        hold_reason VARCHAR(1000) NOT NULL,
        evidence_json LONGTEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by VARCHAR(24) NOT NULL DEFAULT 'automation',
        created_by_user_id INT UNSIGNED NULL,
        released_by_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        released_at DATETIME NULL,
        INDEX idx_client_release_automation_hold_release (product,release_id,is_active,created_at),
        INDEX idx_client_release_automation_hold_campaign (campaign_id,is_active,created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_automation_proposals_v170 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        run_id BIGINT UNSIGNED NULL,
        product VARCHAR(40) NOT NULL,
        channel VARCHAR(16) NOT NULL,
        release_id BIGINT UNSIGNED NULL,
        campaign_id BIGINT UNSIGNED NULL,
        proposal_type VARCHAR(40) NOT NULL,
        proposal_status VARCHAR(24) NOT NULL DEFAULT 'pending',
        requires_approval TINYINT(1) NOT NULL DEFAULT 1,
        auto_executable TINYINT(1) NOT NULL DEFAULT 0,
        from_state VARCHAR(40) NOT NULL DEFAULT '',
        from_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
        to_state VARCHAR(40) NOT NULL DEFAULT '',
        to_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
        rationale VARCHAR(1500) NOT NULL DEFAULT '',
        evidence_json LONGTEXT NULL,
        fingerprint_sha256 CHAR(64) NOT NULL,
        expires_at DATETIME NULL,
        decided_by_user_id INT UNSIGNED NULL,
        decision_note VARCHAR(1000) NOT NULL DEFAULT '',
        decided_at DATETIME NULL,
        executed_by_user_id INT UNSIGNED NULL,
        executed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_client_release_automation_proposal_status (proposal_status,created_at),
        INDEX idx_client_release_automation_proposal_release (product,release_id,proposal_status,created_at),
        INDEX idx_client_release_automation_proposal_campaign (campaign_id,proposal_status,created_at),
        INDEX idx_client_release_automation_proposal_fingerprint (fingerprint_sha256,proposal_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_automation_runs_v170 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        run_key CHAR(64) NOT NULL,
        trigger_type VARCHAR(24) NOT NULL DEFAULT 'manual',
        dry_run TINYINT(1) NOT NULL DEFAULT 1,
        automation_enabled TINYINT(1) NOT NULL DEFAULT 0,
        kill_switch TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(24) NOT NULL DEFAULT 'running',
        proposals_created INT UNSIGNED NOT NULL DEFAULT 0,
        actions_executed INT UNSIGNED NOT NULL DEFAULT 0,
        holds_created INT UNSIGNED NOT NULL DEFAULT 0,
        summary_json LONGTEXT NULL,
        triggered_by_user_id INT UNSIGNED NULL,
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        UNIQUE KEY uq_client_release_automation_run_key (run_key),
        INDEX idx_client_release_automation_run_status (status,started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS client_release_automation_events_v170 (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        run_id BIGINT UNSIGNED NULL,
        proposal_id BIGINT UNSIGNED NULL,
        actor_user_id INT UNSIGNED NULL,
        event_type VARCHAR(60) NOT NULL,
        details_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client_release_automation_event_run (run_id,created_at,id),
        INDEX idx_client_release_automation_event_proposal (proposal_id,created_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function client_release_automation_control_v170(PDO $pdo): array
{
    client_release_automation_ensure_schema_v170($pdo);
    $row=$pdo->query('SELECT * FROM client_release_automation_control_v170 WHERE id=1 LIMIT 1')->fetch();
    return $row?:['automation_enabled'=>0,'kill_switch'=>0,'dry_run'=>1];
}

function client_release_automation_live_actions_allowed_v170(PDO $pdo): bool
{
    $control=client_release_automation_control_v170($pdo);
    return !empty($control['automation_enabled'])&&empty($control['kill_switch'])&&empty($control['dry_run']);
}

function client_release_automation_control_update_v170(PDO $pdo,array $input,int $actorUserId): array
{
    client_release_automation_ensure_schema_v170($pdo);
    $enabled=!empty($input['automation_enabled'])?1:0;
    $kill=!empty($input['kill_switch'])?1:0;
    $dry=!empty($input['dry_run'])?1:0;
    $pdo->prepare('UPDATE client_release_automation_control_v170 SET automation_enabled=?,kill_switch=?,dry_run=?,updated_by_user_id=? WHERE id=1')
        ->execute([$enabled,$kill,$dry,$actorUserId>0?$actorUserId:null]);
    client_release_audit_v110($pdo,$actorUserId,'browser_companion',null,'automation_control_update','','',[
        'automation_enabled'=>(bool)$enabled,'kill_switch'=>(bool)$kill,'dry_run'=>(bool)$dry
    ]);
    return client_release_automation_control_v170($pdo);
}

function client_release_automation_default_policy_v170(): array
{
    return [
        'policy_enabled'=>0,
        'execution_mode'=>'recommend_only',
        'auto_hold_enabled'=>1,
        'rollout_progression_enabled'=>1,
        'fleet_progression_enabled'=>1,
        'proposal_expiry_hours'=>24,
        'cooldown_minutes'=>30,
        'fleet_completion_gate_bps'=>9000,
        'fleet_failure_hold_bps'=>500,
    ];
}

function client_release_automation_policy_v170(PDO $pdo,string $product,string $channel): array
{
    $defaults=client_release_automation_default_policy_v170();
    $channel=client_release_channel_v110($channel);
    if(!client_release_automation_schema_ready_v170($pdo))return $defaults;
    $stmt=$pdo->prepare('SELECT * FROM client_release_automation_policy_v170 WHERE product=? AND channel=? LIMIT 1');
    $stmt->execute([$product,$channel]);
    $row=$stmt->fetch();
    return $row?array_merge($defaults,$row):$defaults;
}

function client_release_automation_policy_update_v170(PDO $pdo,string $product,string $channel,array $input,int $actorUserId): array
{
    if(!client_release_product_valid_v110($product))throw new RuntimeException('Unsupported release product.');
    $channel=client_release_channel_v110($channel);
    $mode=strtolower(trim((string)($input['execution_mode']??'recommend_only')));
    if(!in_array($mode,['recommend_only','auto_low_risk'],true))throw new RuntimeException('Choose a valid automation execution mode.');
    $policy=[
        'policy_enabled'=>!empty($input['policy_enabled'])?1:0,
        'execution_mode'=>$mode,
        'auto_hold_enabled'=>!empty($input['auto_hold_enabled'])?1:0,
        'rollout_progression_enabled'=>!empty($input['rollout_progression_enabled'])?1:0,
        'fleet_progression_enabled'=>!empty($input['fleet_progression_enabled'])?1:0,
        'proposal_expiry_hours'=>max(1,min(168,(int)($input['proposal_expiry_hours']??24))),
        'cooldown_minutes'=>max(0,min(1440,(int)($input['cooldown_minutes']??30))),
        'fleet_completion_gate_bps'=>max(5000,min(10000,(int)($input['fleet_completion_gate_bps']??9000))),
        'fleet_failure_hold_bps'=>max(0,min(5000,(int)($input['fleet_failure_hold_bps']??500))),
    ];
    client_release_automation_ensure_schema_v170($pdo);
    $stmt=$pdo->prepare("INSERT INTO client_release_automation_policy_v170
      (product,channel,policy_enabled,execution_mode,auto_hold_enabled,rollout_progression_enabled,fleet_progression_enabled,
       proposal_expiry_hours,cooldown_minutes,fleet_completion_gate_bps,fleet_failure_hold_bps,updated_by_user_id)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE policy_enabled=VALUES(policy_enabled),execution_mode=VALUES(execution_mode),
       auto_hold_enabled=VALUES(auto_hold_enabled),rollout_progression_enabled=VALUES(rollout_progression_enabled),
       fleet_progression_enabled=VALUES(fleet_progression_enabled),proposal_expiry_hours=VALUES(proposal_expiry_hours),
       cooldown_minutes=VALUES(cooldown_minutes),fleet_completion_gate_bps=VALUES(fleet_completion_gate_bps),
       fleet_failure_hold_bps=VALUES(fleet_failure_hold_bps),updated_by_user_id=VALUES(updated_by_user_id)");
    $stmt->execute([
        $product,$channel,$policy['policy_enabled'],$policy['execution_mode'],$policy['auto_hold_enabled'],
        $policy['rollout_progression_enabled'],$policy['fleet_progression_enabled'],$policy['proposal_expiry_hours'],
        $policy['cooldown_minutes'],$policy['fleet_completion_gate_bps'],$policy['fleet_failure_hold_bps'],
        $actorUserId>0?$actorUserId:null
    ]);
    client_release_audit_v110($pdo,$actorUserId,$product,null,'automation_policy_update','','',$policy+['channel'=>$channel]);
    return client_release_automation_policy_v170($pdo,$product,$channel);
}

function client_release_automation_event_v170(PDO $pdo,?int $runId,?int $proposalId,int $actorUserId,string $eventType,array $details=[]): void
{
    if(!client_release_automation_schema_ready_v170($pdo))return;
    $stmt=$pdo->prepare('INSERT INTO client_release_automation_events_v170 (run_id,proposal_id,actor_user_id,event_type,details_json) VALUES (?,?,?,?,?)');
    $stmt->execute([
        $runId&&$runId>0?$runId:null,$proposalId&&$proposalId>0?$proposalId:null,$actorUserId>0?$actorUserId:null,
        $eventType,$details?json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null
    ]);
}

function client_release_automation_active_hold_v170(PDO $pdo,string $scopeType,string $product,?int $releaseId=null,?int $campaignId=null): ?array
{
    if(!client_release_automation_schema_ready_v170($pdo))return null;
    $sql="SELECT * FROM client_release_automation_holds_v170 WHERE scope_type=? AND product=? AND is_active=1";
    $args=[$scopeType,$product];
    if($releaseId!==null){$sql.=" AND release_id=?";$args[]=$releaseId;}
    if($campaignId!==null){$sql.=" AND campaign_id=?";$args[]=$campaignId;}
    $sql.=" ORDER BY id DESC LIMIT 1";
    $stmt=$pdo->prepare($sql);$stmt->execute($args);
    $row=$stmt->fetch();
    return $row?:null;
}

function client_release_automation_hold_v170(PDO $pdo,string $scopeType,string $product,?int $releaseId,?int $campaignId,string $reason,array $evidence=[],int $actorUserId=0,string $createdBy='automation'): array
{
    if(!in_array($scopeType,['release','fleet_campaign'],true))throw new RuntimeException('Unsupported automation hold scope.');
    $existing=client_release_automation_active_hold_v170($pdo,$scopeType,$product,$releaseId,$campaignId);
    if($existing)return $existing;
    $reason=mb_strimwidth(trim($reason),0,1000,'');
    if($reason==='')$reason='Automation health gate requested a hold.';
    $stmt=$pdo->prepare("INSERT INTO client_release_automation_holds_v170
      (scope_type,product,release_id,campaign_id,hold_reason,evidence_json,is_active,created_by,created_by_user_id)
      VALUES (?,?,?,?,?,?,1,?,?)");
    $stmt->execute([
        $scopeType,$product,$releaseId&&$releaseId>0?$releaseId:null,$campaignId&&$campaignId>0?$campaignId:null,
        $reason,$evidence?json_encode($evidence,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE):null,
        $createdBy,$actorUserId>0?$actorUserId:null
    ]);
    $id=(int)$pdo->lastInsertId();
    client_release_audit_v110($pdo,$actorUserId,$product,$releaseId,'automation_hold_created','','hold',[
        'hold_id'=>$id,'scope_type'=>$scopeType,'campaign_id'=>$campaignId,'reason'=>$reason,'created_by'=>$createdBy
    ]);
    $stmt=$pdo->prepare('SELECT * FROM client_release_automation_holds_v170 WHERE id=?');$stmt->execute([$id]);
    return $stmt->fetch()?:[];
}

function client_release_automation_release_hold_v170(PDO $pdo,int $holdId,int $actorUserId,string $note=''): void
{
    $stmt=$pdo->prepare('SELECT * FROM client_release_automation_holds_v170 WHERE id=? AND is_active=1 LIMIT 1');
    $stmt->execute([$holdId]);$hold=$stmt->fetch();
    if(!$hold)throw new RuntimeException('Active automation hold was not found.');
    $pdo->prepare('UPDATE client_release_automation_holds_v170 SET is_active=0,released_by_user_id=?,released_at=NOW() WHERE id=?')
        ->execute([$actorUserId>0?$actorUserId:null,$holdId]);
    client_release_audit_v110($pdo,$actorUserId,(string)$hold['product'],(int)($hold['release_id']??0),'automation_hold_released','hold','',[
        'hold_id'=>$holdId,'campaign_id'=>(int)($hold['campaign_id']??0),'note'=>mb_strimwidth(trim($note),0,1000,'')
    ]);
}

function client_release_automation_hold_blocks_release_v170(PDO $pdo,string $product,int $releaseId): bool
{
    return client_release_automation_active_hold_v170($pdo,'release',$product,$releaseId,null)!==null;
}

function client_release_automation_hold_blocks_campaign_v170(PDO $pdo,int $campaignId): bool
{
    $campaign=client_fleet_campaign_v160($pdo,$campaignId);
    if(!$campaign)return false;
    return client_release_automation_active_hold_v170($pdo,'fleet_campaign',(string)$campaign['product'],null,$campaignId)!==null;
}

function client_release_automation_proposal_fingerprint_v170(array $payload): string
{
    ksort($payload);
    return hash('sha256',json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

function client_release_automation_expire_proposals_v170(PDO $pdo): void
{
    if(!client_release_automation_schema_ready_v170($pdo))return;
    $pdo->exec("UPDATE client_release_automation_proposals_v170 SET proposal_status='expired'
      WHERE proposal_status IN ('pending','deferred') AND expires_at IS NOT NULL AND expires_at<=NOW()");
}

function client_release_automation_existing_proposal_v170(PDO $pdo,string $fingerprint,int $cooldownMinutes=0): ?array
{
    $stmt=$pdo->prepare("SELECT * FROM client_release_automation_proposals_v170
      WHERE fingerprint_sha256=? AND proposal_status IN ('pending','approved','deferred','executed')
      ORDER BY id DESC LIMIT 1");
    $stmt->execute([$fingerprint]);$row=$stmt->fetch();
    if($row)return $row;
    if($cooldownMinutes>0){
        $cutoff=gmdate('Y-m-d H:i:s',time()-max(0,$cooldownMinutes)*60);
        $stmt=$pdo->prepare("SELECT * FROM client_release_automation_proposals_v170
          WHERE fingerprint_sha256=? AND created_at>=? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$fingerprint,$cutoff]);$row=$stmt->fetch();
        if($row)return $row;
    }
    return null;
}

function client_release_automation_create_proposal_v170(PDO $pdo,array $proposal,int $runId=0): array
{
    $fingerprint=client_release_automation_proposal_fingerprint_v170([
        'product'=>(string)$proposal['product'],'channel'=>(string)$proposal['channel'],
        'release_id'=>(int)($proposal['release_id']??0),'campaign_id'=>(int)($proposal['campaign_id']??0),
        'proposal_type'=>(string)$proposal['proposal_type'],'from_state'=>(string)($proposal['from_state']??''),
        'from_percent'=>(int)($proposal['from_percent']??0),'to_state'=>(string)($proposal['to_state']??''),
        'to_percent'=>(int)($proposal['to_percent']??0),
        'evidence_version'=>(string)($proposal['evidence_version']??''),
    ]);
    $policy=client_release_automation_policy_v170($pdo,(string)$proposal['product'],(string)$proposal['channel']);
    $cooldown=max(0,min(1440,(int)($proposal['cooldown_minutes']??$policy['cooldown_minutes']??30)));
    $existing=client_release_automation_existing_proposal_v170($pdo,$fingerprint,$cooldown);
    if($existing){$existing['_automation_created']=false;return $existing;}
    $expiresHours=max(1,min(168,(int)($proposal['proposal_expiry_hours']??$policy['proposal_expiry_hours']??24)));
    $expires=gmdate('Y-m-d H:i:s',time()+$expiresHours*3600);
    $stmt=$pdo->prepare("INSERT INTO client_release_automation_proposals_v170
      (run_id,product,channel,release_id,campaign_id,proposal_type,proposal_status,requires_approval,auto_executable,
       from_state,from_percent,to_state,to_percent,rationale,evidence_json,fingerprint_sha256,expires_at)
      VALUES (?,?,?,?,?,?,'pending',?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $runId>0?$runId:null,(string)$proposal['product'],client_release_channel_v110((string)$proposal['channel']),
        (int)($proposal['release_id']??0)>0?(int)$proposal['release_id']:null,
        (int)($proposal['campaign_id']??0)>0?(int)$proposal['campaign_id']:null,
        (string)$proposal['proposal_type'],!empty($proposal['requires_approval'])?1:0,!empty($proposal['auto_executable'])?1:0,
        (string)($proposal['from_state']??''),(int)($proposal['from_percent']??0),
        (string)($proposal['to_state']??''),(int)($proposal['to_percent']??0),
        mb_strimwidth(trim((string)($proposal['rationale']??'')),0,1500,''),
        json_encode((array)($proposal['evidence']??[]),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
        $fingerprint,$expires
    ]);
    $id=(int)$pdo->lastInsertId();
    client_release_automation_event_v170($pdo,$runId,$id,0,'proposal_created',[
        'proposal_type'=>(string)$proposal['proposal_type'],'fingerprint'=>$fingerprint
    ]);
    $stmt=$pdo->prepare('SELECT * FROM client_release_automation_proposals_v170 WHERE id=?');$stmt->execute([$id]);
    $row=$stmt->fetch()?:[];
    $row['_automation_created']=true;
    return $row;
}

function client_release_automation_rollout_evidence_v170(PDO $pdo,string $product,int $releaseId): array
{
    $health=client_release_health_calculate_v120($pdo,$product,$releaseId);
    $risk=client_release_risk_assess_v140($pdo,$product,$releaseId);
    $readiness=client_release_readiness_current_v150($pdo,$product,$releaseId);
    $incident=client_release_incident_active_for_release_v130($pdo,$product,$releaseId);
    return ['health'=>$health,'risk'=>$risk,'readiness'=>$readiness,'incident'=>$incident];
}

function client_release_automation_risk_allows_auto_v170(array $risk): bool
{
    return in_array((string)($risk['risk_level']??'critical'),['low','moderate'],true);
}

function client_release_automation_rollout_proposal_v170(PDO $pdo,string $product,array $release,array $policy,int $runId): ?array
{
    $releaseId=(int)$release['id'];$channel=(string)($release['channel']??'stable');
    $roll=client_release_rollout_for_v110($pdo,$product,$releaseId,$release);
    $state=(string)($roll['lifecycle_state']??'draft');$percent=(int)($roll['rollout_percent']??0);
    if(!in_array($state,['canary','limited','general_availability'],true))return null;
    $evidence=client_release_automation_rollout_evidence_v170($pdo,$product,$releaseId);
    $health=(array)$evidence['health'];$risk=(array)$evidence['risk'];$readiness=(array)$evidence['readiness'];

    if(!empty($evidence['incident'])){
        return client_release_automation_create_proposal_v170($pdo,[
            'product'=>$product,'channel'=>$channel,'release_id'=>$releaseId,'proposal_type'=>'incident_controlled',
            'requires_approval'=>1,'auto_executable'=>0,'from_state'=>$state,'from_percent'=>$percent,
            'to_state'=>$state,'to_percent'=>$percent,'rationale'=>'Active v1.30 incident owns this release; normal automation is suspended.',
            'evidence'=>$evidence,'evidence_version'=>'incident:'.(int)$evidence['incident']['id'],
            'proposal_expiry_hours'=>(int)$policy['proposal_expiry_hours']
        ],$runId);
    }

    $recommendation=(string)($health['recommendation']??'manual_validation');
    if(in_array($recommendation,['hold','rollback_review'],true)){
        if($recommendation==='rollback_review'){
            $candidate=client_release_incident_recovery_candidate_v130($pdo,$product,$releaseId);
            if($candidate)$evidence['rollback_candidate']=[
                'release_id'=>(int)$candidate['id'],'version'=>(string)($candidate['version']??''),
                'channel'=>(string)($candidate['channel']??$channel)
            ];
        }
        if(!empty($policy['auto_hold_enabled'])&&client_release_automation_live_actions_allowed_v170($pdo)){
            client_release_automation_hold_v170($pdo,'release',$product,$releaseId,null,
                implode(' ',(array)($health['reasons']??[])),[
                    'health_status'=>$health['health_status'],'recommendation'=>$recommendation,
                    'failure_rate_bps'=>$health['failure_rate_bps'],'compatibility_failure_rate_bps'=>$health['compatibility_failure_rate_bps']
                ],0,'automation');
        }
        return client_release_automation_create_proposal_v170($pdo,[
            'product'=>$product,'channel'=>$channel,'release_id'=>$releaseId,
            'proposal_type'=>$recommendation==='rollback_review'?'rollback_review':'rollout_hold',
            'requires_approval'=>1,'auto_executable'=>0,'from_state'=>$state,'from_percent'=>$percent,
            'to_state'=>$state,'to_percent'=>$percent,
            'rationale'=>implode(' ',(array)($health['reasons']??[]))?:'Health gate requests a hold.',
            'evidence'=>$evidence,
            'evidence_version'=>'health:'.(string)$health['health_status'].':'.(int)$health['observed_clients'].':'.(int)$health['failed_clients'],
            'proposal_expiry_hours'=>(int)$policy['proposal_expiry_hours']
        ],$runId);
    }

    if($state==='general_availability'||empty($policy['rollout_progression_enabled']))return null;
    if(client_release_automation_hold_blocks_release_v170($pdo,$product,$releaseId))return null;
    if($recommendation!=='promote'||empty($health['next_transition']))return null;
    if(empty($readiness['ready']))return client_release_automation_create_proposal_v170($pdo,[
        'product'=>$product,'channel'=>$channel,'release_id'=>$releaseId,'proposal_type'=>'readiness_block',
        'requires_approval'=>1,'auto_executable'=>0,'from_state'=>$state,'from_percent'=>$percent,'to_state'=>$state,'to_percent'=>$percent,
        'rationale'=>'v1.50 readiness is not currently approved: '.(string)($readiness['reason']??'readiness gate failed'),
        'evidence'=>$evidence,'evidence_version'=>'readiness:'.(string)($readiness['status']??'unknown'),
        'proposal_expiry_hours'=>(int)$policy['proposal_expiry_hours']
    ],$runId);

    $healthSnapshot=client_release_health_snapshot_v120($pdo,$product,$releaseId,0);
    $evidence['health_snapshot_id']=(int)$healthSnapshot['snapshot_id'];
    $next=(array)$healthSnapshot['next_transition'];$toState=(string)$next['state'];$toPercent=(int)$next['percent'];
    $isGa=$toState==='general_availability';
    $autoAllowed=!$isGa&&(string)$policy['execution_mode']==='auto_low_risk'&&client_release_automation_risk_allows_auto_v170($risk);
    return client_release_automation_create_proposal_v170($pdo,[
        'product'=>$product,'channel'=>$channel,'release_id'=>$releaseId,'proposal_type'=>'rollout_promote',
        'requires_approval'=>$isGa||!$autoAllowed,'auto_executable'=>$autoAllowed,
        'from_state'=>$state,'from_percent'=>$percent,'to_state'=>$toState,'to_percent'=>$toPercent,
        'rationale'=>'v1.20 health gates are passing, v1.50 readiness is current, and v1.40 risk is '.(string)$risk['risk_level'].'.',
        'evidence'=>$evidence,
        'evidence_version'=>'health:'.(string)$health['health_status'].':'.(int)$health['observed_clients'].':risk:'.(string)$risk['risk_level'],
        'proposal_expiry_hours'=>(int)$policy['proposal_expiry_hours']
    ],$runId);
}

function client_release_automation_campaign_cohort_stats_v170(PDO $pdo,int $campaignId,int $percent): array
{
    $stats=['total'=>0,'installed'=>0,'downloaded'=>0,'failed'=>0,'offered'=>0,'queued'=>0,'offline'=>0,'excluded'=>0];
    $stmt=$pdo->prepare("SELECT maintenance_state,COUNT(*) total FROM client_fleet_maintenance_members_v160
      WHERE campaign_id=? AND cohort_bucket<=? GROUP BY maintenance_state");
    $stmt->execute([$campaignId,max(0,min(100,$percent))]);
    foreach($stmt->fetchAll()?:[] as $row){
        $state=(string)$row['maintenance_state'];$count=(int)$row['total'];
        $stats['total']+=$count;if(isset($stats[$state]))$stats[$state]=$count;
    }
    $attempted=$stats['installed']+$stats['downloaded']+$stats['failed'];
    $stats['failure_rate_bps']=$attempted>0?(int)round(($stats['failed']/$attempted)*10000):0;
    $eligible=max(0,$stats['total']-$stats['excluded']);
    $stats['completion_rate_bps']=$eligible>0?(int)round(($stats['installed']/$eligible)*10000):10000;
    return $stats;
}

function client_release_automation_next_fleet_percent_v170(int $current,int $riskMax): int
{
    foreach([10,25,50,100] as $step){
        if($step>$current&&$step<=$riskMax)return $step;
    }
    return $current;
}

function client_release_automation_fleet_proposal_v170(PDO $pdo,array $campaign,array $policy,int $runId): ?array
{
    if(empty($policy['fleet_progression_enabled']))return null;
    $campaignId=(int)$campaign['id'];$product=(string)$campaign['product'];$channel=(string)$campaign['channel'];
    $state=(string)$campaign['campaign_state'];$percent=(int)$campaign['cohort_percent'];
    if(!in_array($state,['active','paused'],true))return null;
    client_fleet_campaign_refresh_v160($pdo,$campaignId,0);

    $targetId=(int)$campaign['target_release_id'];
    $readiness=client_release_readiness_current_v150($pdo,$product,$targetId);
    $risk=client_fleet_target_risk_v160($pdo,$product,$targetId);
    $riskMax=client_fleet_max_cohort_for_target_v160($pdo,$product,$targetId);
    $stats=client_release_automation_campaign_cohort_stats_v170($pdo,$campaignId,max(1,$percent));
    $evidence=['campaign'=>$campaign,'readiness'=>$readiness,'risk'=>$risk,'cohort_stats'=>$stats,'risk_max_cohort'=>$riskMax];

    if(client_release_incident_active_for_release_v130($pdo,$product,$targetId)){
        if(!empty($policy['auto_hold_enabled'])&&client_release_automation_live_actions_allowed_v170($pdo))client_release_automation_hold_v170($pdo,'fleet_campaign',$product,null,$campaignId,'Maintenance target is under an active incident.',$evidence,0,'automation');
        return client_release_automation_create_proposal_v170($pdo,[
            'product'=>$product,'channel'=>$channel,'campaign_id'=>$campaignId,'release_id'=>$targetId,
            'proposal_type'=>'fleet_hold','requires_approval'=>1,'auto_executable'=>0,
            'from_state'=>$state,'from_percent'=>$percent,'to_state'=>$state,'to_percent'=>$percent,
            'rationale'=>'Maintenance target is under an active v1.30 incident.','evidence'=>$evidence,
            'evidence_version'=>'incident_target','proposal_expiry_hours'=>(int)$policy['proposal_expiry_hours']
        ],$runId);
    }

    if(empty($readiness['ready'])){
        if(!empty($policy['auto_hold_enabled'])&&client_release_automation_live_actions_allowed_v170($pdo))client_release_automation_hold_v170($pdo,'fleet_campaign',$product,null,$campaignId,'v1.50 readiness is no longer approved.',$evidence,0,'automation');
        return client_release_automation_create_proposal_v170($pdo,[
            'product'=>$product,'channel'=>$channel,'campaign_id'=>$campaignId,'release_id'=>$targetId,
            'proposal_type'=>'fleet_hold','requires_approval'=>1,'auto_executable'=>0,
            'from_state'=>$state,'from_percent'=>$percent,'to_state'=>$state,'to_percent'=>$percent,
            'rationale'=>'v1.50 readiness is no longer approved: '.(string)($readiness['reason']??'readiness gate failed'),
            'evidence'=>$evidence,'evidence_version'=>'readiness:'.(string)($readiness['status']??'unknown'),
            'proposal_expiry_hours'=>(int)$policy['proposal_expiry_hours']
        ],$runId);
    }

    if((int)$stats['failure_rate_bps']>(int)$policy['fleet_failure_hold_bps']){
        if(!empty($policy['auto_hold_enabled'])&&client_release_automation_live_actions_allowed_v170($pdo))client_release_automation_hold_v170($pdo,'fleet_campaign',$product,null,$campaignId,'Fleet failure rate crossed the automation hold threshold.',$evidence,0,'automation');
        return client_release_automation_create_proposal_v170($pdo,[
            'product'=>$product,'channel'=>$channel,'campaign_id'=>$campaignId,'release_id'=>$targetId,
            'proposal_type'=>'fleet_hold','requires_approval'=>1,'auto_executable'=>0,
            'from_state'=>$state,'from_percent'=>$percent,'to_state'=>$state,'to_percent'=>$percent,
            'rationale'=>'Fleet failure rate '.client_release_health_percent_v120((int)$stats['failure_rate_bps']).' exceeds the automation hold threshold.',
            'evidence'=>$evidence,'evidence_version'=>'fleetfail:'.(int)$stats['failed'].':'.(int)$stats['installed'],
            'proposal_expiry_hours'=>(int)$policy['proposal_expiry_hours']
        ],$runId);
    }

    if($state!=='active'||$percent<1)return null;
    if(client_release_automation_hold_blocks_campaign_v170($pdo,$campaignId))return null;
    if((int)$stats['completion_rate_bps']<(int)$policy['fleet_completion_gate_bps'])return null;
    $next=client_release_automation_next_fleet_percent_v170($percent,$riskMax);
    if($next<=$percent)return null;
    $autoAllowed=(string)$policy['execution_mode']==='auto_low_risk'&&client_release_automation_risk_allows_auto_v170($risk);
    return client_release_automation_create_proposal_v170($pdo,[
        'product'=>$product,'channel'=>$channel,'campaign_id'=>$campaignId,'release_id'=>$targetId,
        'proposal_type'=>'fleet_advance','requires_approval'=>!$autoAllowed,'auto_executable'=>$autoAllowed,
        'from_state'=>$state,'from_percent'=>$percent,'to_state'=>'active','to_percent'=>$next,
        'rationale'=>'Current maintenance cohort reached '.client_release_health_percent_v120((int)$stats['completion_rate_bps']).' completion with acceptable failure rate.',
        'evidence'=>$evidence,'evidence_version'=>'fleet:'.$percent.':'.(int)$stats['installed'].':'.(int)$stats['failed'].':risk:'.(string)($risk['risk_level']??''),
        'proposal_expiry_hours'=>(int)$policy['proposal_expiry_hours']
    ],$runId);
}

function client_release_automation_proposal_v170(PDO $pdo,int $proposalId): ?array
{
    if($proposalId<1||!client_release_automation_schema_ready_v170($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM client_release_automation_proposals_v170 WHERE id=? LIMIT 1');
    $stmt->execute([$proposalId]);$row=$stmt->fetch();
    return $row?:null;
}

function client_release_automation_validate_proposal_v170(PDO $pdo,array $proposal): array
{
    $type=(string)$proposal['proposal_type'];$product=(string)$proposal['product'];
    if($type==='rollout_promote'){
        $releaseId=(int)$proposal['release_id'];$release=client_release_release_row_v110($pdo,$product,$releaseId);
        if(!$release)throw new RuntimeException('Release no longer exists.');
        $roll=client_release_rollout_for_v110($pdo,$product,$releaseId,$release);
        if((string)$roll['lifecycle_state']!==(string)$proposal['from_state']||(int)$roll['rollout_percent']!==(int)$proposal['from_percent']){
            throw new RuntimeException('Rollout state changed after the proposal was created.');
        }
        $health=client_release_health_calculate_v120($pdo,$product,$releaseId);
        if((string)$health['recommendation']!=='promote'||empty($health['next_transition']))throw new RuntimeException('Release health no longer recommends promotion.');
        $next=(array)$health['next_transition'];
        if((string)$next['state']!==(string)$proposal['to_state']||(int)$next['percent']!==(int)$proposal['to_percent'])throw new RuntimeException('The recommended rollout transition changed.');
        $ready=client_release_readiness_current_v150($pdo,$product,$releaseId);
        if(empty($ready['ready']))throw new RuntimeException('Release readiness is no longer approved.');
        if(client_release_automation_hold_blocks_release_v170($pdo,$product,$releaseId))throw new RuntimeException('An active automation hold blocks rollout progression.');
        if(client_release_incident_active_for_release_v130($pdo,$product,$releaseId))throw new RuntimeException('An active incident now governs this release.');
        return ['release'=>$release,'rollout'=>$roll,'health'=>$health,'readiness'=>$ready,'risk'=>client_release_risk_assess_v140($pdo,$product,$releaseId)];
    }
    if($type==='fleet_advance'){
        $campaignId=(int)$proposal['campaign_id'];$campaign=client_fleet_campaign_v160($pdo,$campaignId);
        if(!$campaign)throw new RuntimeException('Maintenance campaign no longer exists.');
        if((string)$campaign['campaign_state']!==(string)$proposal['from_state']||(int)$campaign['cohort_percent']!==(int)$proposal['from_percent'])throw new RuntimeException('Maintenance campaign changed after the proposal was created.');
        if(client_release_automation_hold_blocks_campaign_v170($pdo,$campaignId))throw new RuntimeException('An active automation hold blocks fleet progression.');
        $targetId=(int)$campaign['target_release_id'];$ready=client_release_readiness_current_v150($pdo,$product,$targetId);
        if(empty($ready['ready']))throw new RuntimeException('Maintenance target readiness is no longer approved.');
        if(client_release_incident_active_for_release_v130($pdo,$product,$targetId))throw new RuntimeException('Maintenance target is now under active incident.');
        $risk=client_fleet_target_risk_v160($pdo,$product,$targetId);
        $riskMax=client_fleet_max_cohort_for_target_v160($pdo,$product,$targetId);
        if((int)$proposal['to_percent']>$riskMax)throw new RuntimeException('Current risk policy no longer permits the proposed fleet cohort.');
        return ['campaign'=>$campaign,'readiness'=>$ready,'risk'=>$risk];
    }
    if(in_array($type,['rollout_hold','fleet_hold','rollback_review','incident_controlled','readiness_block'],true))return [];
    throw new RuntimeException('Unsupported automation proposal type.');
}

function client_release_automation_execute_proposal_v170(PDO $pdo,int $proposalId,int $actorUserId,string $note='',bool $systemExecution=false): array
{
    $proposal=client_release_automation_proposal_v170($pdo,$proposalId);
    if(!$proposal)throw new RuntimeException('Automation proposal was not found.');
    if(!in_array((string)$proposal['proposal_status'],['pending','approved','deferred'],true))throw new RuntimeException('Automation proposal is no longer executable.');
    if(!empty($proposal['expires_at'])&&strtotime((string)$proposal['expires_at'])<=time())throw new RuntimeException('Automation proposal expired.');
    if($systemExecution&&!empty($proposal['requires_approval']))throw new RuntimeException('This automation proposal requires operator approval.');
    if($systemExecution&&empty($proposal['auto_executable']))throw new RuntimeException('This proposal is not eligible for automatic execution.');

    $control=client_release_automation_control_v170($pdo);
    if($systemExecution&&(empty($control['automation_enabled'])||!empty($control['kill_switch'])||!empty($control['dry_run']))){
        throw new RuntimeException('Global automation controls currently prevent automatic execution.');
    }
    $validated=client_release_automation_validate_proposal_v170($pdo,$proposal);
    $type=(string)$proposal['proposal_type'];$product=(string)$proposal['product'];
    $note=mb_strimwidth(trim($note),0,1000,'');

    if($type==='rollout_promote'){
        if((string)$proposal['to_state']==='general_availability'&&$systemExecution)throw new RuntimeException('General Availability promotion always requires an operator.');
        $releaseId=(int)$proposal['release_id'];
        $evidence=json_decode((string)($proposal['evidence_json']??''),true);
        $snapshotId=is_array($evidence)?(int)($evidence['health_snapshot_id']??0):0;
        if($snapshotId<1)throw new RuntimeException('Automation proposal is missing its v1.20 health snapshot.');
        client_release_health_approve_promotion_v120(
            $pdo,$product,$releaseId,$snapshotId,$actorUserId,
            $note!==''?$note:'v1.70 governed automation approval.'
        );
    }elseif($type==='fleet_advance'){
        client_fleet_campaign_set_v160($pdo,(int)$proposal['campaign_id'],'active',(int)$proposal['to_percent'],$actorUserId);
    }else{
        throw new RuntimeException('This proposal is advisory and cannot directly execute an action.');
    }

    $pdo->prepare("UPDATE client_release_automation_proposals_v170 SET proposal_status='executed',
      executed_by_user_id=?,executed_at=NOW(),decision_note=CASE WHEN decision_note='' THEN ? ELSE decision_note END
      WHERE id=?")->execute([$actorUserId>0?$actorUserId:null,$note,$proposalId]);
    client_release_automation_event_v170($pdo,(int)($proposal['run_id']??0),$proposalId,$actorUserId,'proposal_executed',[
        'system_execution'=>$systemExecution,'note'=>$note,'proposal_type'=>$type
    ]);
    client_release_audit_v110($pdo,$actorUserId,$product,(int)($proposal['release_id']??0),'automation_proposal_executed',
        (string)$proposal['from_state'],(string)$proposal['to_state'],[
            'proposal_id'=>$proposalId,'campaign_id'=>(int)($proposal['campaign_id']??0),
            'from_percent'=>(int)$proposal['from_percent'],'to_percent'=>(int)$proposal['to_percent'],
            'system_execution'=>$systemExecution,'note'=>$note
        ]);
    return client_release_automation_proposal_v170($pdo,$proposalId)??[];
}

function client_release_automation_execute_modified_proposal_v170(PDO $pdo,array $proposal,int $modifiedPercent,int $actorUserId,string $note): array
{
    $type=(string)$proposal['proposal_type'];
    $fromPercent=(int)$proposal['from_percent'];$originalTo=(int)$proposal['to_percent'];
    if($modifiedPercent<1||$modifiedPercent>=$originalTo||$modifiedPercent<=$fromPercent){
        throw new RuntimeException('Modified cohort must be larger than the current cohort and smaller than the proposed cohort.');
    }
    if($type==='fleet_advance'){
        client_release_automation_validate_proposal_v170($pdo,$proposal);
        client_fleet_campaign_set_v160($pdo,(int)$proposal['campaign_id'],'active',$modifiedPercent,$actorUserId);
    }elseif($type==='rollout_promote'){
        if((string)$proposal['to_state']==='general_availability')throw new RuntimeException('General Availability promotion cannot be modified; approve or reject it explicitly.');
        $validated=client_release_automation_validate_proposal_v170($pdo,$proposal);
        $release=(array)$validated['release'];$roll=(array)$validated['rollout'];
        $health=client_release_health_snapshot_v120($pdo,(string)$proposal['product'],(int)$proposal['release_id'],$actorUserId);
        if((string)$health['recommendation']!=='promote')throw new RuntimeException('Release health no longer recommends promotion.');
        client_release_rollout_update_v110($pdo,(string)$proposal['product'],(int)$proposal['release_id'],[
            'lifecycle_state'=>'limited','rollout_percent'=>$modifiedPercent,
            'summary'=>(string)($roll['summary']??''),'known_issues'=>(string)($roll['known_issues']??''),
            'compatibility_notes'=>(string)($roll['compatibility_notes']??''),
        ],$actorUserId);
        $stmt=$pdo->prepare("INSERT INTO client_release_promotion_decisions_v120
          (actor_user_id,product,release_id,health_snapshot_id,decision,from_state,from_percent,to_state,to_percent,rationale)
          VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $actorUserId>0?$actorUserId:null,(string)$proposal['product'],(int)$proposal['release_id'],
            (int)$health['snapshot_id'],'approved_modified',(string)$proposal['from_state'],$fromPercent,'limited',$modifiedPercent,$note
        ]);
        client_release_audit_v110($pdo,$actorUserId,(string)$proposal['product'],(int)$proposal['release_id'],'health_promotion_approved_modified',
            (string)$proposal['from_state'],'limited',[
                'from_percent'=>$fromPercent,'to_percent'=>$modifiedPercent,'health_snapshot_id'=>(int)$health['snapshot_id'],
                'automation_proposal_id'=>(int)$proposal['id'],'rationale'=>$note
            ]);
    }else{
        throw new RuntimeException('Only rollout and fleet advancement proposals can be modified.');
    }
    $pdo->prepare("UPDATE client_release_automation_proposals_v170 SET proposal_status='executed',
      to_percent=?,decided_by_user_id=?,decision_note=?,decided_at=NOW(),executed_by_user_id=?,executed_at=NOW() WHERE id=?")
      ->execute([$modifiedPercent,$actorUserId>0?$actorUserId:null,$note,$actorUserId>0?$actorUserId:null,(int)$proposal['id']]);
    client_release_automation_event_v170($pdo,(int)($proposal['run_id']??0),(int)$proposal['id'],$actorUserId,'proposal_modified_and_executed',[
        'original_to_percent'=>$originalTo,'modified_to_percent'=>$modifiedPercent,'note'=>$note
    ]);
    return client_release_automation_proposal_v170($pdo,(int)$proposal['id'])??[];
}

function client_release_automation_decide_proposal_v170(PDO $pdo,int $proposalId,string $decision,int $actorUserId,string $note='',int $modifiedPercent=0): array
{
    $proposal=client_release_automation_proposal_v170($pdo,$proposalId);
    if(!$proposal)throw new RuntimeException('Automation proposal was not found.');
    if(!in_array((string)$proposal['proposal_status'],['pending','deferred'],true))throw new RuntimeException('Automation proposal is no longer awaiting a decision.');
    $decision=strtolower(trim($decision));
    if(!in_array($decision,['approve','reject','defer','modify'],true))throw new RuntimeException('Choose a valid automation decision.');
    $note=mb_strimwidth(trim($note),0,1000,'');
    if(in_array($decision,['reject','defer','modify'],true)&&$note==='')throw new RuntimeException('Add an operator note when rejecting, deferring, or modifying automation.');
    if($decision==='modify')return client_release_automation_execute_modified_proposal_v170($pdo,$proposal,$modifiedPercent,$actorUserId,$note);

    if($decision==='approve'){
        if(in_array((string)$proposal['proposal_type'],['rollout_promote','fleet_advance'],true)){
            $pdo->prepare("UPDATE client_release_automation_proposals_v170 SET proposal_status='approved',decided_by_user_id=?,decision_note=?,decided_at=NOW() WHERE id=?")
                ->execute([$actorUserId>0?$actorUserId:null,$note,$proposalId]);
            client_release_automation_event_v170($pdo,(int)($proposal['run_id']??0),$proposalId,$actorUserId,'proposal_approved',['note'=>$note]);
            return client_release_automation_execute_proposal_v170($pdo,$proposalId,$actorUserId,$note,false);
        }
        $pdo->prepare("UPDATE client_release_automation_proposals_v170 SET proposal_status='acknowledged',decided_by_user_id=?,decision_note=?,decided_at=NOW() WHERE id=?")
            ->execute([$actorUserId>0?$actorUserId:null,$note,$proposalId]);
    }else{
        $status=$decision==='reject'?'rejected':'deferred';
        $pdo->prepare('UPDATE client_release_automation_proposals_v170 SET proposal_status=?,decided_by_user_id=?,decision_note=?,decided_at=NOW() WHERE id=?')
            ->execute([$status,$actorUserId>0?$actorUserId:null,$note,$proposalId]);
    }
    client_release_automation_event_v170($pdo,(int)($proposal['run_id']??0),$proposalId,$actorUserId,'proposal_'.$decision,['note'=>$note]);
    client_release_audit_v110($pdo,$actorUserId,(string)$proposal['product'],(int)($proposal['release_id']??0),'automation_proposal_'.$decision,
        (string)$proposal['from_state'],(string)$proposal['to_state'],['proposal_id'=>$proposalId,'note'=>$note]);
    return client_release_automation_proposal_v170($pdo,$proposalId)??[];
}

function client_release_automation_auto_execute_pending_v170(PDO $pdo,int $runId,int $actorUserId=0): int
{
    $control=client_release_automation_control_v170($pdo);
    if(empty($control['automation_enabled'])||!empty($control['kill_switch'])||!empty($control['dry_run']))return 0;
    $stmt=$pdo->prepare("SELECT * FROM client_release_automation_proposals_v170
      WHERE run_id=? AND proposal_status='pending' AND auto_executable=1 AND requires_approval=0 ORDER BY id");
    $stmt->execute([$runId]);$count=0;
    foreach($stmt->fetchAll()?:[] as $proposal){
        try{client_release_automation_execute_proposal_v170($pdo,(int)$proposal['id'],$actorUserId,'Policy-authorized low-risk automation.',true);$count++;}
        catch(Throwable $e){
            client_release_automation_event_v170($pdo,$runId,(int)$proposal['id'],$actorUserId,'auto_execution_blocked',['error'=>$e->getMessage()]);
        }
    }
    return $count;
}

function client_release_automation_run_v170(PDO $pdo,string $triggerType='manual',int $actorUserId=0,bool $forceEvaluation=false,string $idempotencyKey=''): array
{
    client_release_automation_ensure_schema_v170($pdo);
    client_release_automation_expire_proposals_v170($pdo);
    $control=client_release_automation_control_v170($pdo);
    $triggerType=in_array($triggerType,['manual','scheduled','cli'],true)?$triggerType:'manual';
    $idempotencyKey=trim($idempotencyKey);
    $runKey=$idempotencyKey!==''
        ?hash('sha256',$triggerType.'|'.$idempotencyKey)
        :hash('sha256',$triggerType.'|'.gmdate('Y-m-d H:i:s').'|'.$actorUserId.'|'.microtime(true));
    if($idempotencyKey!==''){
        $existingStmt=$pdo->prepare('SELECT * FROM client_release_automation_runs_v170 WHERE run_key=? LIMIT 1');
        $existingStmt->execute([$runKey]);
        $existing=$existingStmt->fetch();
        if($existing)return $existing;
    }
    $stmt=$pdo->prepare("INSERT INTO client_release_automation_runs_v170
      (run_key,trigger_type,dry_run,automation_enabled,kill_switch,status,triggered_by_user_id)
      VALUES (?,?,?,?,?,'running',?)");
    $stmt->execute([$runKey,$triggerType,!empty($control['dry_run'])?1:0,!empty($control['automation_enabled'])?1:0,!empty($control['kill_switch'])?1:0,$actorUserId>0?$actorUserId:null]);
    $runId=(int)$pdo->lastInsertId();

    $created=0;$holdsBefore=(int)$pdo->query("SELECT COUNT(*) FROM client_release_automation_holds_v170 WHERE is_active=1")->fetchColumn();
    $summary=['release_proposals'=>0,'fleet_proposals'=>0,'skipped_policies'=>0,'errors'=>[]];

    if(!empty($control['kill_switch'])&&!$forceEvaluation){
        $summary['kill_switch']='Automation kill switch is active.';
    }elseif(empty($control['automation_enabled'])&&!$forceEvaluation){
        $summary['disabled']='Automation is globally disabled.';
    }else{
        foreach(['browser_companion','homeserver'] as $product){
            foreach(client_release_admin_rollouts_v110($pdo,$product) as $release){
                $channel=(string)($release['channel']??'stable');$policy=client_release_automation_policy_v170($pdo,$product,$channel);
                if(empty($policy['policy_enabled'])){$summary['skipped_policies']++;continue;}
                try{$proposal=client_release_automation_rollout_proposal_v170($pdo,$product,$release,$policy,$runId);if($proposal&&!empty($proposal['_automation_created'])){$created++;$summary['release_proposals']++;}}
                catch(Throwable $e){$summary['errors'][]=$product.' release #'.(int)$release['id'].': '.$e->getMessage();}
            }
        }
        foreach(client_fleet_campaigns_v160($pdo,100) as $campaign){
            $policy=client_release_automation_policy_v170($pdo,(string)$campaign['product'],(string)$campaign['channel']);
            if(empty($policy['policy_enabled']))continue;
            try{$proposal=client_release_automation_fleet_proposal_v170($pdo,$campaign,$policy,$runId);if($proposal&&!empty($proposal['_automation_created'])){$created++;$summary['fleet_proposals']++;}}
            catch(Throwable $e){$summary['errors'][]='fleet campaign #'.(int)$campaign['id'].': '.$e->getMessage();}
        }
    }

    $executed=client_release_automation_auto_execute_pending_v170($pdo,$runId,$actorUserId);
    $holdsAfter=(int)$pdo->query("SELECT COUNT(*) FROM client_release_automation_holds_v170 WHERE is_active=1")->fetchColumn();
    $status=$summary['errors']?'completed_with_errors':'completed';
    $pdo->prepare('UPDATE client_release_automation_runs_v170 SET status=?,proposals_created=?,actions_executed=?,holds_created=?,summary_json=?,completed_at=NOW() WHERE id=?')
        ->execute([$status,$created,$executed,max(0,$holdsAfter-$holdsBefore),json_encode($summary,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$runId]);
    client_release_automation_event_v170($pdo,$runId,null,$actorUserId,'automation_run_completed',[
        'status'=>$status,'proposals_created'=>$created,'actions_executed'=>$executed,'holds_created'=>max(0,$holdsAfter-$holdsBefore),'summary'=>$summary
    ]);
    return client_release_automation_run_by_id_v170($pdo,$runId)??[];
}

function client_release_automation_run_by_id_v170(PDO $pdo,int $runId): ?array
{
    if($runId<1||!client_release_automation_schema_ready_v170($pdo))return null;
    $stmt=$pdo->prepare('SELECT * FROM client_release_automation_runs_v170 WHERE id=? LIMIT 1');
    $stmt->execute([$runId]);$row=$stmt->fetch();
    return $row?:null;
}

function client_release_automation_recent_runs_v170(PDO $pdo,int $limit=20): array
{
    if(!client_release_automation_schema_ready_v170($pdo))return [];
    $limit=max(1,min(100,$limit));
    return $pdo->query("SELECT * FROM client_release_automation_runs_v170 ORDER BY id DESC LIMIT {$limit}")->fetchAll()?:[];
}

function client_release_automation_pending_proposals_v170(PDO $pdo,int $limit=100): array
{
    if(!client_release_automation_schema_ready_v170($pdo))return [];
    client_release_automation_expire_proposals_v170($pdo);
    $limit=max(1,min(200,$limit));
    return $pdo->query("SELECT * FROM client_release_automation_proposals_v170
      WHERE proposal_status IN ('pending','deferred','approved') ORDER BY id DESC LIMIT {$limit}")->fetchAll()?:[];
}

function client_release_automation_recent_proposals_v170(PDO $pdo,int $limit=40): array
{
    if(!client_release_automation_schema_ready_v170($pdo))return [];
    $limit=max(1,min(200,$limit));
    return $pdo->query("SELECT * FROM client_release_automation_proposals_v170 ORDER BY id DESC LIMIT {$limit}")->fetchAll()?:[];
}

function client_release_automation_active_holds_v170(PDO $pdo,int $limit=100): array
{
    if(!client_release_automation_schema_ready_v170($pdo))return [];
    $limit=max(1,min(200,$limit));
    return $pdo->query("SELECT * FROM client_release_automation_holds_v170 WHERE is_active=1 ORDER BY id DESC LIMIT {$limit}")->fetchAll()?:[];
}

function client_release_automation_admin_summary_v170(PDO $pdo): array
{
    $control=client_release_automation_control_v170($pdo);
    $policies=[];
    foreach(['browser_companion','homeserver'] as $product){
        foreach(['stable','beta','dev'] as $channel)$policies[$product][$channel]=client_release_automation_policy_v170($pdo,$product,$channel);
    }
    return [
        'control'=>$control,'policies'=>$policies,
        'pending'=>client_release_automation_pending_proposals_v170($pdo,100),
        'holds'=>client_release_automation_active_holds_v170($pdo,100),
        'runs'=>client_release_automation_recent_runs_v170($pdo,20),
        'recent'=>client_release_automation_recent_proposals_v170($pdo,40),
    ];
}
