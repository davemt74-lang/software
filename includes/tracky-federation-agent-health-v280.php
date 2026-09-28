<?php
declare(strict_types=1);

/**
 * Tracky V2.80 Section 7 — Cloud federation Agent health mirror.
 * Health/recovery authority remains at the origin HomeServer. Cloud may display,
 * reason over, and retain the mirrored operational history, but cannot mark a
 * site recovered or current independently.
 */
const VP3_TRACKY_AGENT_HEALTH_V280='vp3-tracky-agent-health-v280-20260928';
const VP3_TRACKY_AGENT_HEALTH_PROTOCOL_V280='physical_federation_agent_health.v1';

function tracky_v280_fah_text(mixed $value,int $max=240): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/',' ',(string)($value??''))??''),0,max(1,$max),'');
}

function tracky_v280_fah_uuid(mixed $value,string $label,bool $allowEmpty=false): string
{
    $value=strtolower(mb_strimwidth(trim((string)($value??'')),0,64,''));
    if($value===''&&$allowEmpty)return '';
    if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$value)){
        throw new RuntimeException('Tracky federation Agent health '.$label.' must be a UUID.');
    }
    return $value;
}

function tracky_v280_fah_state(mixed $value): string
{
    $state=strtolower(tracky_v280_fah_text($value,30));
    return in_array($state,['current','degraded','stale','partitioned','reconciling','recovering','offline','failed','unknown'],true)?$state:'unknown';
}

function tracky_v280_fah_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo?table_exists('tracky_cloud_federation_agent_health'):false;
}

function tracky_v280_fah_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_federation_agent_health (
      user_id INT UNSIGNED NOT NULL,
      reporting_site_id VARCHAR(100) NOT NULL,
      local_site_uuid CHAR(36) NOT NULL,
      generated_at_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
      overall_state VARCHAR(30) NOT NULL DEFAULT 'unknown',
      semantic_hash CHAR(64) NOT NULL,
      snapshot_json LONGTEXT NOT NULL,
      received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,reporting_site_id),
      INDEX idx_tracky_fah_user_state (user_id,overall_state,updated_at),
      CONSTRAINT fk_tracky_fah_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tracky_v280_fah_trust(array $row): array
{
    return [
      'semantic_state'=>tracky_v280_fah_text($row['semantic_state']??'unknown',40),
      'agent_use'=>tracky_v280_fah_text($row['agent_use']??'health_only',40),
      'physical_claims'=>tracky_v280_fah_text($row['physical_claims']??'do_not_claim_current',50),
      'reason'=>tracky_v280_fah_text($row['reason']??'',120),
    ];
}

function tracky_v280_fah_site(array $row): array
{
    $site=tracky_v280_fah_uuid($row['site_id']??'','federation health site id');
    $state=tracky_v280_fah_state($row['state']??'unknown');
    $authority=is_array($row['authority']??null)?$row['authority']:[];
    return [
      'site_id'=>$site,
      'label'=>tracky_v280_fah_text($row['label']??$site,160),
      'state'=>$state,
      'previous_state'=>isset($row['previous_state'])?tracky_v280_fah_state($row['previous_state']):null,
      'state_since'=>max(0,(int)($row['state_since']??0)),
      'duration_ms'=>max(0,(int)($row['duration_ms']??0)),
      'severity'=>in_array(strtolower(tracky_v280_fah_text($row['severity']??'info',20)),['info','warning','critical'],true)
        ?strtolower(tracky_v280_fah_text($row['severity']??'info',20)):'info',
      'cause'=>tracky_v280_fah_text($row['cause']??'',160),
      'federation_status'=>tracky_v280_fah_text($row['federation_status']??'unknown',30),
      'fresh'=>!empty($row['fresh']),
      'reconciliation_required'=>!empty($row['reconciliation_required']),
      'revision_gap'=>max(0,(int)($row['revision_gap']??0)),
      'stale_age_ms'=>max(0,(int)($row['stale_age_ms']??0)),
      'authority'=>[
        'status'=>tracky_v280_fah_text($authority['status']??'unknown',30),
        'device_id'=>tracky_v280_fah_uuid($authority['device_id']??'','federation health authority device id',true),
        'epoch'=>max(0,(int)($authority['epoch']??0)),
      ],
      'authority_device_runtime'=>tracky_v280_fah_text($row['authority_device_runtime']??'unknown',40),
      'trust'=>tracky_v280_fah_trust(is_array($row['trust']??null)?$row['trust']:[]),
      'agent_visible'=>!empty($row['agent_visible']),
      'recovery_complete'=>!empty($row['recovery_complete'])&&$state==='current'&&!empty($row['fresh'])&&empty($row['reconciliation_required']),
      'recovery_gate'=>'authoritative_reconciliation_current',
      'flap_count_10m'=>max(0,(int)($row['flap_count_10m']??0)),
      'title'=>tracky_v280_fah_text($row['title']??'',300),
      'message'=>tracky_v280_fah_text($row['message']??'',1000),
    ];
}

function tracky_v280_fah_history(array $items): array
{
    $out=[];
    foreach(array_slice($items,0,100) as $row){
        if(!is_array($row))continue;
        $payload=is_array($row['payload']??null)?$row['payload']:[];
        $out[]=[
          'event_id'=>tracky_v280_fah_text($row['event_id']??'',180),
          'event_type'=>tracky_v280_fah_text($row['event_type']??'',160),
          'summary'=>tracky_v280_fah_text($row['summary']??'',1000),
          'importance'=>max(0.0,min(1.0,(float)($row['importance']??0))),
          'occurred_at'=>tracky_v280_fah_text($row['occurred_at']??'',80),
          'payload'=>[
            'site_id'=>isset($payload['site_id'])?tracky_v280_fah_uuid($payload['site_id'],'health history site id',true):'',
            'label'=>tracky_v280_fah_text($payload['label']??'',160),
            'component'=>tracky_v280_fah_text($payload['component']??'',80),
            'state'=>tracky_v280_fah_state($payload['state']??'unknown'),
            'previous_state'=>isset($payload['previous_state'])?tracky_v280_fah_state($payload['previous_state']):null,
            'severity'=>tracky_v280_fah_text($payload['severity']??'',20),
            'cause'=>tracky_v280_fah_text($payload['cause']??'',160),
            'recovery_complete'=>!empty($payload['recovery_complete']),
          ],
        ];
    }
    return $out;
}

function tracky_v280_fah_normalize(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'federation_agent_health');
    if((string)($input['protocol']??'')!==VP3_TRACKY_AGENT_HEALTH_PROTOCOL_V280){
        throw new RuntimeException('Tracky federation Agent health protocol is unsupported.');
    }
    if(isset($input['cloud_read_only'])&&!$input['cloud_read_only']){
        throw new RuntimeException('Tracky federation Agent health Cloud projection must remain read-only.');
    }
    if(!empty($input['authority_mutation'])){
        throw new RuntimeException('Tracky federation Agent health cannot grant Cloud authority mutation.');
    }
    $local=tracky_v280_fah_uuid($input['local_site_id']??'','federation health local site id');
    $sites=[];$seen=[];
    foreach(array_slice(is_array($input['sites']??null)?$input['sites']:[],0,128) as $row){
        if(!is_array($row))continue;
        $normalized=tracky_v280_fah_site($row);
        if(isset($seen[$normalized['site_id']]))throw new RuntimeException('Tracky federation health site is duplicated.');
        $seen[$normalized['site_id']]=true;$sites[]=$normalized;
    }
    $relay=is_array($input['relay']??null)?$input['relay']:[];
    $relayHealth=is_array($input['relay_health']??null)?$input['relay_health']:[];
    $agent=is_array($input['agent_context']??null)?$input['agent_context']:[];
    $agentSites=[];
    foreach(array_slice(is_array($agent['sites']??null)?$agent['sites']:[],0,32) as $row){
        if(!is_array($row))continue;
        $agentSites[]=[
          'site_id'=>tracky_v280_fah_uuid($row['site_id']??'','Agent health site id'),
          'label'=>tracky_v280_fah_text($row['label']??'',160),
          'state'=>tracky_v280_fah_state($row['state']??'unknown'),
          'severity'=>tracky_v280_fah_text($row['severity']??'info',20),
          'cause'=>tracky_v280_fah_text($row['cause']??'',160),
          'fresh'=>!empty($row['fresh']),
          'recovery_complete'=>!empty($row['recovery_complete']),
          'trust'=>tracky_v280_fah_trust(is_array($row['trust']??null)?$row['trust']:[]),
        ];
    }
    $issues=[];
    foreach(array_slice(is_array($agent['active_issues']??null)?$agent['active_issues']:[],0,24) as $row){
        if(!is_array($row))continue;
        $siteId=tracky_v280_fah_uuid($row['site_id']??'','Agent health issue site id',true);
        $component=tracky_v280_fah_text($row['component']??'',80);
        if($siteId===''&&$component==='')continue;
        $issues[]=[
          'site_id'=>$siteId,
          'component'=>$component,
          'label'=>tracky_v280_fah_text($row['label']??'',160),
          'state'=>tracky_v280_fah_state($row['state']??'unknown'),
          'severity'=>tracky_v280_fah_text($row['severity']??'warning',20),
          'cause'=>tracky_v280_fah_text($row['cause']??'',160),
          'message'=>tracky_v280_fah_text($row['message']??'',1000),
          'trust'=>tracky_v280_fah_trust(is_array($row['trust']??null)?$row['trust']:[]),
        ];
    }
    return [
      'protocol'=>VP3_TRACKY_AGENT_HEALTH_PROTOCOL_V280,
      'version'=>'2.80','schema_version'=>1,
      'generated_at'=>max(0,(int)($input['generated_at']??0)),
      'local_site_id'=>$local,
      'overall_state'=>tracky_v280_fah_state($input['overall_state']??'unknown'),
      'relay'=>[
        'state'=>tracky_v280_fah_text($relay['state']??'unknown',30),
        'connected'=>!empty($relay['connected']),
        'paired'=>!empty($relay['paired']),
        'last_seen_at'=>tracky_v280_fah_text($relay['last_seen_at']??'',80),
        'last_error'=>tracky_v280_fah_text($relay['last_error']??'',240),
        'transport'=>tracky_v280_fah_text($relay['transport']??'',40),
      ],
      'relay_health'=>[
        'component'=>'vp3_cloud_relay',
        'state'=>tracky_v280_fah_state($relayHealth['state']??'unknown'),
        'previous_state'=>isset($relayHealth['previous_state'])?tracky_v280_fah_state($relayHealth['previous_state']):null,
        'severity'=>tracky_v280_fah_text($relayHealth['severity']??'info',20),
        'cause'=>tracky_v280_fah_text($relayHealth['cause']??'',240),
        'recovery_complete'=>!empty($relayHealth['recovery_complete']),
        'message'=>tracky_v280_fah_text($relayHealth['message']??'',1000),
      ],
      'sites'=>$sites,
      'history'=>tracky_v280_fah_history(is_array($input['history']??null)?$input['history']:[]),
      'counts'=>[
        'sites'=>count($sites),
        'current'=>count(array_filter($sites,static fn($s)=>$s['state']==='current')),
        'degraded'=>count(array_filter($sites,static fn($s)=>in_array($s['state'],['degraded','stale','reconciling','recovering'],true))),
        'critical'=>count(array_filter($sites,static fn($s)=>in_array($s['state'],['partitioned','offline','failed'],true))),
        'recovering'=>count(array_filter($sites,static fn($s)=>in_array($s['state'],['recovering','reconciling'],true))),
      ],
      'agent_context'=>[
        'overall_state'=>tracky_v280_fah_state($agent['overall_state']??$input['overall_state']??'unknown'),
        'sites'=>$agentSites,
        'active_issues'=>$issues,
        'summary'=>tracky_v280_fah_text($agent['summary']??'',1000),
        'recovery_requires_authoritative_reconciliation'=>true,
        'connectivity_returned_is_not_recovery'=>true,
      ],
      'summary_only'=>true,
      'cloud_read_only'=>true,
      'authority_mutation'=>false,
      'boundaries'=>[
        'connectivity-returned-is-not-recovery',
        'recovery-requires-authoritative-reconciliation-current',
        'stale-state-never-promoted-to-current',
        'health-never-changes-authority',
        'agent-health-context-respects-site-policy',
      ],
    ];
}

function tracky_v280_fah_ingest(PDO $pdo,int $userId,string $reportingSiteId,array $input): array
{
    if(!tracky_v280_fah_schema_ready($pdo))tracky_v280_fah_ensure_schema($pdo);
    $snapshot=tracky_v280_fah_normalize($input);
    $json=tracky_cloud_v270_json($snapshot);
    $hash=hash('sha256',$json);
    $q=$pdo->prepare('SELECT generated_at_ms,semantic_hash FROM tracky_cloud_federation_agent_health WHERE user_id=? AND reporting_site_id=? LIMIT 1');
    $q->execute([$userId,$reportingSiteId]);$prior=$q->fetch();
    if($prior&&(int)$snapshot['generated_at']<(int)$prior['generated_at_ms']){
        return ['accepted'=>true,'changed'=>0,'stale'=>1,'idempotent'=>0];
    }
    if($prior&&(int)$snapshot['generated_at']===(int)$prior['generated_at_ms']){
        if(hash_equals((string)$prior['semantic_hash'],$hash))return ['accepted'=>true,'changed'=>0,'stale'=>0,'idempotent'=>1];
        throw new RuntimeException('Tracky federation Agent health timestamp conflicts with the existing Cloud mirror.');
    }
    $stmt=$pdo->prepare("INSERT INTO tracky_cloud_federation_agent_health(
      user_id,reporting_site_id,local_site_uuid,generated_at_ms,overall_state,semantic_hash,snapshot_json
    ) VALUES (?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE local_site_uuid=VALUES(local_site_uuid),generated_at_ms=VALUES(generated_at_ms),
      overall_state=VALUES(overall_state),semantic_hash=VALUES(semantic_hash),snapshot_json=VALUES(snapshot_json),
      received_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP");
    $stmt->execute([$userId,$reportingSiteId,$snapshot['local_site_id'],$snapshot['generated_at'],$snapshot['overall_state'],$hash,$json]);
    return ['accepted'=>true,'changed'=>1,'stale'=>0,'idempotent'=>0];
}

function tracky_v280_fah_report(PDO $pdo,int $userId): array
{
    if($userId<1)return ['available'=>false,'protocol'=>VP3_TRACKY_AGENT_HEALTH_PROTOCOL_V280];
    tracky_v280_fah_ensure_schema($pdo);
    $q=$pdo->prepare('SELECT reporting_site_id,snapshot_json,updated_at FROM tracky_cloud_federation_agent_health WHERE user_id=? ORDER BY updated_at DESC,reporting_site_id');
    $q->execute([$userId]);$snapshots=[];
    foreach($q->fetchAll()?:[] as $row){
        $decoded=json_decode((string)($row['snapshot_json']??''),true);
        if(!is_array($decoded))continue;
        $snapshots[]=['reporting_site_id'=>(string)$row['reporting_site_id'],'updated_at'=>(string)$row['updated_at'],'health'=>$decoded];
    }
    return [
      'available'=>!empty($snapshots),
      'protocol'=>VP3_TRACKY_AGENT_HEALTH_PROTOCOL_V280,
      'snapshots'=>$snapshots,
      'preferred_health'=>$snapshots[0]['health']??null,
      'preferred_reporting_site_id'=>$snapshots[0]['reporting_site_id']??'',
      'cloud_role'=>'mirror_only',
      'cloud_read_only'=>true,
      'cloud_can_mark_recovered'=>false,
      'authority_mutation'=>false,
    ];
}

function tracky_v280_fah_public_capability(): array
{
    return [
      'version'=>'2.80','protocol'=>VP3_TRACKY_AGENT_HEALTH_PROTOCOL_V280,
      'states'=>['current','degraded','stale','partitioned','reconciling','recovering','offline','failed','unknown'],
      'recovery_requires_authoritative_reconciliation'=>true,
      'connectivity_returned_is_not_recovery'=>true,
      'priority_agent_events'=>true,
      'persistent_operational_history'=>true,
      'stale_data_qualification'=>true,
      'permission_filtered_agent_context'=>true,
      'cloud_read_only'=>true,
      'cloud_can_mark_recovered'=>false,
      'authority_mutation'=>false,
    ];
}
