<?php
declare(strict_types=1);

/**
 * Tracky V2.80 Section 7 — Cloud Agent federation health mirror.
 * HomeServer owns health/recovery decisions. Cloud stores and displays the
 * canonical projection but cannot mark a site recovered or mutate authority.
 */
const VP3_TRACKY_AGENT_HEALTH_V280='vp3-tracky-agent-health-v280-20260928';
const VP3_TRACKY_AGENT_HEALTH_PROTOCOL_V280='physical_federation_agent_health.v1';

function tracky_v280_health_text(mixed $value,int $max=240): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/',' ',(string)($value??''))??''),0,max(1,$max),'');
}

function tracky_v280_health_states(): array
{
    return ['connected','degraded','stale','reconciling','recovering','partitioned','offline','failed'];
}

function tracky_v280_health_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo?table_exists('tracky_cloud_federation_agent_health'):false;
}

function tracky_v280_health_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_federation_agent_health (
      user_id INT UNSIGNED NOT NULL,
      reporting_site_id VARCHAR(100) NOT NULL,
      local_site_uuid CHAR(36) NOT NULL,
      generated_at_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
      semantic_hash CHAR(64) NOT NULL,
      snapshot_json LONGTEXT NOT NULL,
      received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,reporting_site_id),
      INDEX idx_tracky_health_user_updated (user_id,updated_at),
      INDEX idx_tracky_health_local_site (user_id,local_site_uuid,updated_at),
      CONSTRAINT fk_tracky_health_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tracky_v280_health_site(array $row): array
{
    $site=tracky_v278_world_uuid($row['site_id']??'','federation health site id');
    $state=strtolower(tracky_v280_health_text($row['state']??'stale',30));
    if(!in_array($state,tracky_v280_health_states(),true))$state='stale';
    $trust=is_array($row['trust']??null)?$row['trust']:[];
    $authority=is_array($row['authority']??null)?$row['authority']:[];
    $device=is_array($row['authority_device']??null)?$row['authority_device']:null;
    if($device!==null&&!empty($device['id'])){
        $device['id']=tracky_v278_world_uuid($device['id'],'federation health authority device id');
        $device=[
          'id'=>$device['id'],
          'label'=>tracky_v280_health_text($device['label']??'',160),
          'hardware_profile'=>tracky_v280_health_text($device['hardware_profile']??'',40),
          'runtime_status'=>strtolower(tracky_v280_health_text($device['runtime_status']??'unknown',40)),
          'last_seen_at'=>$device['last_seen_at']??0,
        ];
    }else{$device=null;}
    return [
      'site_id'=>$site,
      'label'=>tracky_v280_health_text($row['label']??$site,160),
      'is_local'=>!empty($row['is_local']),
      'state'=>$state,
      'state_since'=>max(0,(int)($row['state_since']??0)),
      'state_age_ms'=>max(0,(int)($row['state_age_ms']??0)),
      'escalation_level'=>max(0,min(3,(int)($row['escalation_level']??0))),
      'priority'=>strtolower(tracky_v280_health_text($row['priority']??'info',30)),
      'health'=>strtolower(tracky_v280_health_text($row['health']??'unknown',30)),
      'sync_status'=>strtolower(tracky_v280_health_text($row['sync_status']??'unknown',30)),
      'reconciliation_required'=>!empty($row['reconciliation_required']),
      'fresh'=>$state==='connected',
      'issue_code'=>tracky_v280_health_text($row['issue_code']??'',120),
      'message'=>tracky_v280_health_text($row['message']??'',800),
      'authority'=>[
        'status'=>tracky_v280_health_text($authority['status']??'unknown',40),
        'device_id'=>isset($authority['device_id'])&&$authority['device_id']!==''?tracky_v278_world_uuid($authority['device_id'],'health authority device id'):'',
        'epoch'=>max(0,(int)($authority['epoch']??0)),
        'reason'=>tracky_v280_health_text($authority['reason']??'',160),
      ],
      'authority_device'=>$device,
      'trust'=>[
        'local_physical_truth_current'=>!empty($trust['local_physical_truth_current']),
        'remote_federation_truth_current'=>$state==='connected',
        'cloud_transport_connected'=>!empty($trust['cloud_transport_connected']),
        'agent_may_treat_remote_state_as_current'=>$state==='connected',
        'agent_may_treat_local_state_as_current'=>!empty($trust['agent_may_treat_local_state_as_current']),
        'note'=>tracky_v280_health_text($trust['note']??'',500),
      ],
      'access'=>[
        'policy_peer_allowed'=>isset($row['access']['policy_peer_allowed'])?(bool)$row['access']['policy_peer_allowed']:null,
        'federation_enabled'=>isset($row['access']['federation_enabled'])?(bool)$row['access']['federation_enabled']:null,
        'revocation_wins'=>true,
      ],
      'recovery_pending'=>in_array($state,['recovering','reconciling'],true),
    ];
}

function tracky_v280_health_normalize(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'federation_agent_health');
    if((string)($input['protocol']??'')!==VP3_TRACKY_AGENT_HEALTH_PROTOCOL_V280){
        throw new RuntimeException('Tracky federation Agent health protocol is unsupported.');
    }
    if(isset($input['cloud_read_only'])&&!$input['cloud_read_only']){
        throw new RuntimeException('Tracky federation health Cloud projection must remain read-only.');
    }
    if(!empty($input['cloud_can_mark_recovered'])||!empty($input['authority_mutation'])){
        throw new RuntimeException('Tracky Cloud cannot mark federation recovery or mutate authority.');
    }
    if(!empty($input['events'])){
        throw new RuntimeException('Tracky Cloud federation health accepts history, not an actionable event queue.');
    }
    $local=tracky_v278_world_uuid($input['local_site_id']??'','federation health local site id');
    $sites=[];$seen=[];
    foreach(array_slice(is_array($input['sites']??null)?$input['sites']:[],0,128) as $row){
        if(!is_array($row))continue;
        $site=tracky_v280_health_site($row);
        if(isset($seen[$site['site_id']]))throw new RuntimeException('Tracky federation health site is duplicated.');
        $seen[$site['site_id']]=true;$sites[]=$site;
    }
    usort($sites,static fn($a,$b)=>strcmp($a['site_id'],$b['site_id']));
    $overall=strtolower(tracky_v280_health_text($input['overall_state']??'stale',30));
    if(!in_array($overall,tracky_v280_health_states(),true))$overall='stale';

    $bridgeRaw=is_array($input['bridge']??null)?$input['bridge']:[];
    $bridgeState=strtolower(tracky_v280_health_text($bridgeRaw['state']??'not_connected',40));
    if(!in_array($bridgeState,['connected','reconnecting','offline','not_connected'],true))$bridgeState='offline';
    $bridge=[
      'state'=>$bridgeState,
      'connected'=>$bridgeState==='connected',
      'paired'=>!empty($bridgeRaw['paired']),
      'state_since'=>max(0,(int)($bridgeRaw['state_since']??0)),
      'state_age_ms'=>max(0,(int)($bridgeRaw['state_age_ms']??0)),
      'last_error'=>tracky_v280_health_text($bridgeRaw['last_error']??'',240),
      'last_connected_at'=>$bridgeRaw['last_connected_at']??null,
      'reconnect_count'=>max(0,(int)($bridgeRaw['reconnect_count']??0)),
      'transport'=>tracky_v280_health_text($bridgeRaw['transport']??'',80),
      'impacts_local_physical_truth'=>false,
      'impacts_cloud_federation_delivery'=>$bridgeState!=='connected',
      'escalation_level'=>max(0,min(3,(int)($bridgeRaw['escalation_level']??0))),
    ];

    $history=[];
    foreach(array_slice(is_array($input['history']??null)?$input['history']:[],0,100) as $row){
        if(!is_array($row))continue;
        $payload=is_array($row['payload']??null)?$row['payload']:[];
        tracky_cloud_v270_assert_governed_value($payload,'federation_agent_health.history.payload');
        $history[]=[
          'event_id'=>tracky_v280_health_text($row['event_id']??'',180),
          'event_type'=>tracky_v280_health_text($row['event_type']??'',120),
          'entity_key'=>tracky_v280_health_text($row['entity_key']??'',180),
          'summary'=>tracky_v280_health_text($row['summary']??'',1000),
          'importance'=>max(0.0,min(1.0,(float)($row['importance']??0))),
          'payload'=>$payload,
          'occurred_at'=>tracky_v280_health_text($row['occurred_at']??'',80),
          'created_at'=>tracky_v280_health_text($row['created_at']??'',80),
          'immutable'=>true,
        ];
    }

    $counts=['sites'=>count($sites)];
    foreach(tracky_v280_health_states() as $state){
        $counts[$state]=count(array_filter($sites,static fn($row)=>$row['state']===$state));
    }
    return [
      'protocol'=>VP3_TRACKY_AGENT_HEALTH_PROTOCOL_V280,
      'version'=>'2.80','schema_version'=>1,
      'generated_at'=>max(0,(int)($input['generated_at']??0)),
      'local_site_id'=>$local,'overall_state'=>$overall,
      'sites'=>$sites,'bridge'=>$bridge,'events'=>[],'history'=>$history,'counts'=>$counts,
      'agent_context'=>[
        'state'=>$overall,
        'site_health'=>array_map(static fn($row)=>[
          'site_id'=>$row['site_id'],'label'=>$row['label'],'state'=>$row['state'],
          'priority'=>$row['priority'],'message'=>$row['message'],'fresh'=>$row['fresh'],
          'reconciliation_required'=>$row['reconciliation_required'],'issue_code'=>$row['issue_code'],
          'trust'=>$row['trust'],
        ],$sites),
        'bridge'=>$bridge,
        'recovery_rule'=>'Connectivity returning does not equal recovery. Recovery completes only after authoritative reconciliation is current.',
        'local_truth_survives_cloud_relay_failure'=>true,
        'no_remote_authority_promotion'=>true,
      ],
      'delivery'=>[
        'chat_events'=>true,'priority_notifications'=>true,'voice_respects_existing_settings'=>true,
        'duplicate_state_events_suppressed'=>true,'escalation_requires_persistent_duration'=>true,
      ],
      'cloud_read_only'=>true,'cloud_can_mark_recovered'=>false,'authority_mutation'=>false,
      'boundaries'=>[
        'reconnect-is-not-recovery','authoritative-reconciliation-required-for-recovery',
        'local-physical-truth-separated-from-cloud-transport','stale-remote-data-never-current',
        'agent-health-does-not-promote-authority','permissions-and-consent-remain-enforced',
        'duplicate-transient-alerts-suppressed',
      ],
    ];
}

function tracky_v280_health_ingest(PDO $pdo,int $userId,string $reportingSiteId,array $input): array
{
    if(!tracky_v280_health_schema_ready($pdo))tracky_v280_health_ensure_schema($pdo);
    $snapshot=tracky_v280_health_normalize($input);
    $json=tracky_cloud_v270_json($snapshot);$hash=hash('sha256',$json);
    $q=$pdo->prepare('SELECT generated_at_ms,semantic_hash FROM tracky_cloud_federation_agent_health WHERE user_id=? AND reporting_site_id=? LIMIT 1');
    $q->execute([$userId,$reportingSiteId]);$prior=$q->fetch();
    if($prior&&(int)$snapshot['generated_at']<(int)$prior['generated_at_ms']){
        return ['accepted'=>true,'changed'=>0,'stale'=>1,'idempotent'=>0];
    }
    if($prior&&(int)$snapshot['generated_at']===(int)$prior['generated_at_ms']){
        if(hash_equals((string)$prior['semantic_hash'],$hash))return ['accepted'=>true,'changed'=>0,'stale'=>0,'idempotent'=>1];
        throw new RuntimeException('Tracky federation health timestamp conflicts with the existing Cloud mirror.');
    }
    $stmt=$pdo->prepare("INSERT INTO tracky_cloud_federation_agent_health(
      user_id,reporting_site_id,local_site_uuid,generated_at_ms,semantic_hash,snapshot_json
    ) VALUES (?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE local_site_uuid=VALUES(local_site_uuid),generated_at_ms=VALUES(generated_at_ms),
      semantic_hash=VALUES(semantic_hash),snapshot_json=VALUES(snapshot_json),received_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP");
    $stmt->execute([$userId,$reportingSiteId,$snapshot['local_site_id'],$snapshot['generated_at'],$hash,$json]);
    return ['accepted'=>true,'changed'=>1,'stale'=>0,'idempotent'=>0];
}

function tracky_v280_health_report(PDO $pdo,int $userId): array
{
    if($userId<1)return ['available'=>false,'protocol'=>VP3_TRACKY_AGENT_HEALTH_PROTOCOL_V280];
    tracky_v280_health_ensure_schema($pdo);
    $q=$pdo->prepare('SELECT reporting_site_id,local_site_uuid,snapshot_json,received_at,updated_at FROM tracky_cloud_federation_agent_health WHERE user_id=? ORDER BY updated_at DESC,reporting_site_id');
    $q->execute([$userId]);$snapshots=[];
    foreach($q->fetchAll()?:[] as $row){
        $decoded=json_decode((string)($row['snapshot_json']??''),true);
        if(!is_array($decoded))continue;
        $snapshots[]=[
          'reporting_site_id'=>(string)$row['reporting_site_id'],
          'local_site_id'=>(string)$row['local_site_uuid'],
          'received_at'=>(string)$row['received_at'],
          'updated_at'=>(string)$row['updated_at'],
          'health'=>$decoded,
        ];
    }
    return [
      'available'=>!empty($snapshots),'protocol'=>VP3_TRACKY_AGENT_HEALTH_PROTOCOL_V280,
      'snapshots'=>$snapshots,'preferred_health'=>$snapshots[0]['health']??null,
      'preferred_reporting_site_id'=>$snapshots[0]['reporting_site_id']??'',
      'cloud_role'=>'mirror_only','cloud_read_only'=>true,'cloud_can_mark_recovered'=>false,
      'authority_mutation'=>false,
    ];
}

function tracky_v280_health_public_capability(): array
{
    return [
      'version'=>'2.80','protocol'=>VP3_TRACKY_AGENT_HEALTH_PROTOCOL_V280,
      'states'=>tracky_v280_health_states(),
      'relay_states'=>['connected','reconnecting','offline','not_connected'],
      'recovery_requires_current_reconciliation'=>true,'persistent_history'=>true,
      'duplicate_state_suppression'=>true,'duration_escalation'=>true,
      'cloud_read_only'=>true,'cloud_can_mark_recovered'=>false,'authority_mutation'=>false,
    ];
}
