<?php
declare(strict_types=1);

const VP3_TRACKY_FLEET_HEALTH_PROTOCOL_V280='physical_federation_fleet_health.v1';

function tracky_v280_ffh_text(mixed $value,int $max=240): string
{
    return mb_strimwidth(trim((string)($value??'')),0,max(1,$max),'');
}
function tracky_v280_ffh_state(mixed $value): string
{
    $state=strtolower(tracky_v280_ffh_text($value,32));
    return in_array($state,['healthy','degraded','stale','recovering','offline','failed','unknown'],true)?$state:'unknown';
}
function tracky_v280_ffh_uuid(mixed $value,string $label,bool $allowEmpty=false): string
{
    $value=strtolower(tracky_v280_ffh_text($value,64));
    if($value===''&&$allowEmpty)return '';
    if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$value)){
        throw new RuntimeException('Tracky fleet health '.$label.' must be a UUID.');
    }
    return $value;
}
function tracky_v280_ffh_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo?table_exists('tracky_cloud_federation_fleet_health'):false;
}
function tracky_v280_ffh_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_federation_fleet_health (
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
      INDEX idx_tracky_ffh_user_state (user_id,overall_state,updated_at),
      CONSTRAINT fk_tracky_ffh_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function tracky_v280_ffh_device(array $row,string $siteId): array
{
    $issues=[];
    foreach(array_slice(is_array($row['issues']??null)?$row['issues']:[],0,32) as $code){
        $code=strtolower(preg_replace('/[^a-z0-9_.:-]+/','_',tracky_v280_ffh_text($code,80))??'');
        if($code!==''&&!in_array($code,$issues,true))$issues[]=$code;
    }
    return [
      'device_id'=>tracky_v280_ffh_text($row['device_id']??'',80),
      'label'=>tracky_v280_ffh_text($row['label']??'',120),
      'site_id'=>$siteId,
      'hardware_profile'=>tracky_v280_ffh_text($row['hardware_profile']??'custom',60),
      'os_version'=>tracky_v280_ffh_text($row['os_version']??'',80),
      'hardware_experience_version'=>tracky_v280_ffh_text($row['hardware_experience_version']??'',80),
      'release_channel'=>tracky_v280_ffh_text($row['release_channel']??'',24),
      'rollout_ring'=>tracky_v280_ffh_text($row['rollout_ring']??'',24),
      'commissioning_state'=>tracky_v280_ffh_text($row['commissioning_state']??'',24),
      'certification_result'=>tracky_v280_ffh_text($row['certification_result']??'',24),
      'update_status'=>tracky_v280_ffh_text($row['update_status']??'',32),
      'backup_state'=>tracky_v280_ffh_text($row['backup_state']??'',24),
      'storage_state'=>tracky_v280_ffh_text($row['storage_state']??'',24),
      'watchdog_failures'=>max(0,min(1000,(int)($row['watchdog_failures']??0))),
      'privacy_fault'=>!empty($row['privacy_fault']),
      'runtime_status'=>tracky_v280_ffh_text($row['runtime_status']??'',32),
      'runtime_version'=>tracky_v280_ffh_text($row['runtime_version']??'',80),
      'camera_count'=>max(0,min(128,(int)($row['camera_count']??0))),
      'sensor_count'=>max(0,min(512,(int)($row['sensor_count']??0))),
      'model_health'=>tracky_v280_ffh_text($row['model_health']??'unknown',32),
      'active_models'=>max(0,min(256,(int)($row['active_models']??0))),
      'calibration_profiles'=>max(0,min(256,(int)($row['calibration_profiles']??0))),
      'calibration_state'=>tracky_v280_ffh_text($row['calibration_state']??'unknown',32),
      'last_sync_at'=>tracky_v280_ffh_text($row['last_sync_at']??'',80),
      'error_count'=>max(0,min(1000,(int)($row['error_count']??count($issues)))),
      'upgrade_state'=>tracky_v280_ffh_text($row['upgrade_state']??$row['update_status']??'',32),
      'last_seen_at'=>tracky_v280_ffh_text($row['last_seen_at']??'',80),
      'stale_age_ms'=>max(0,(int)($row['stale_age_ms']??0)),
      'state'=>tracky_v280_ffh_state($row['state']??'unknown'),
      'severity'=>in_array(strtolower(tracky_v280_ffh_text($row['severity']??'info',20)),['info','warning','critical'],true)?strtolower(tracky_v280_ffh_text($row['severity']??'info',20)):'info',
      'issues'=>$issues,
    ];
}
function tracky_v280_ffh_normalize(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'federation_fleet_health');
    if((string)($input['protocol']??'')!==VP3_TRACKY_FLEET_HEALTH_PROTOCOL_V280){
        throw new RuntimeException('Tracky federation fleet health protocol is unsupported.');
    }
    if(isset($input['cloud_read_only'])&&!$input['cloud_read_only'])throw new RuntimeException('Fleet health Cloud projection must remain read-only.');
    if(!empty($input['authority_mutation'])||!empty($input['remote_command_execution'])){
        throw new RuntimeException('Fleet diagnostics cannot mutate authority or execute remote commands.');
    }
    $local=tracky_v280_ffh_uuid($input['local_site_id']??'','local site id');
    $sites=[];$seen=[];
    foreach(array_slice(is_array($input['sites']??null)?$input['sites']:[],0,128) as $row){
        if(!is_array($row))continue;
        $siteId=tracky_v280_ffh_uuid($row['site_id']??'','site id');
        if(isset($seen[$siteId]))throw new RuntimeException('Tracky fleet health site is duplicated.');
        $seen[$siteId]=true;
        $devices=[];
        foreach(array_slice(is_array($row['devices']??null)?$row['devices']:[],0,256) as $device){
            if(is_array($device))$devices[]=tracky_v280_ffh_device($device,$siteId);
        }
        $federationState=strtolower(tracky_v280_ffh_text($row['federation_state']??'unknown',32));
        $recoveryComplete=!empty($row['federation_recovery_complete']);
        $federationCurrent=$federationState==='current'&&$recoveryComplete;
        $state=tracky_v280_ffh_state($row['state']??'unknown');
        if(!$federationCurrent&&$state==='healthy')$state=in_array($federationState,['recovering','reconciling'],true)?'recovering':'stale';
        $sites[]=[
          'site_id'=>$siteId,'label'=>tracky_v280_ffh_text($row['label']??$siteId,160),
          'state'=>$state,
          'severity'=>tracky_v280_ffh_text($row['severity']??'info',20),
          'cause'=>tracky_v280_ffh_text($row['cause']??'',160),
          'diagnostics_allowed'=>!empty($row['diagnostics_allowed']),
          'diagnostics_current'=>$federationCurrent&&!empty($row['diagnostics_current']),
          'federation_state'=>$federationState,
          'federation_recovery_complete'=>$recoveryComplete,
          'device_count'=>count($devices),
          'healthy_device_count'=>max(0,(int)($row['healthy_device_count']??0)),
          'degraded_device_count'=>max(0,(int)($row['degraded_device_count']??0)),
          'critical_device_count'=>max(0,(int)($row['critical_device_count']??0)),
          'devices'=>$devices,
          'agent_visible'=>!empty($row['agent_visible']),
          'current_claims_allowed'=>$federationCurrent&&!empty($row['current_claims_allowed']),
          'message'=>tracky_v280_ffh_text($row['message']??'',1000),
        ];
    }
    return [
      'protocol'=>VP3_TRACKY_FLEET_HEALTH_PROTOCOL_V280,'version'=>'2.80','schema_version'=>1,
      'generated_at'=>max(0,(int)($input['generated_at']??0)),'local_site_id'=>$local,
      'overall_state'=>tracky_v280_ffh_state($input['overall_state']??'unknown'),
      'sites'=>$sites,
      'counts'=>[
        'sites'=>count($sites),'devices'=>array_sum(array_column($sites,'device_count')),
        'healthy'=>count(array_filter($sites,static fn($s)=>$s['state']==='healthy')),
        'degraded'=>count(array_filter($sites,static fn($s)=>in_array($s['state'],['degraded','stale','recovering'],true))),
        'critical'=>count(array_filter($sites,static fn($s)=>in_array($s['state'],['failed','offline'],true))),
      ],
      'privacy'=>[
        'diagnostic_content_included'=>false,'local_path_details_included'=>false,'network_endpoint_details_included'=>false,
        'secret_material_included'=>false,'conversations_included'=>false,'captured_media_content_included'=>false,'knowledge_content_included'=>false,
      ],
      'summary_only'=>true,'cloud_read_only'=>true,'authority_mutation'=>false,'remote_command_execution'=>false,
      'boundaries'=>[
        'section7-federation-health-remains-authoritative','diagnostics-never-promote-stale-state-to-current',
        'diagnostics-are-observational-not-control-authority','cloud-mirror-is-read-only','privacy-safe-summary-only',
      ],
    ];
}
function tracky_v280_ffh_ingest(PDO $pdo,int $userId,string $reportingSiteId,array $input): array
{
    tracky_v280_ffh_ensure_schema($pdo);
    $snapshot=tracky_v280_ffh_normalize($input);
    $json=tracky_cloud_v270_json($snapshot);$hash=hash('sha256',$json);
    $q=$pdo->prepare('SELECT generated_at_ms,semantic_hash FROM tracky_cloud_federation_fleet_health WHERE user_id=? AND reporting_site_id=? LIMIT 1');
    $q->execute([$userId,$reportingSiteId]);$prior=$q->fetch();
    if($prior&&(int)$snapshot['generated_at']<(int)$prior['generated_at_ms'])return ['accepted'=>true,'changed'=>0,'stale'=>1,'idempotent'=>0];
    if($prior&&(int)$snapshot['generated_at']===(int)$prior['generated_at_ms']){
        if(hash_equals((string)$prior['semantic_hash'],$hash))return ['accepted'=>true,'changed'=>0,'stale'=>0,'idempotent'=>1];
        throw new RuntimeException('Tracky fleet health timestamp conflicts with the existing Cloud mirror.');
    }
    $stmt=$pdo->prepare("INSERT INTO tracky_cloud_federation_fleet_health(user_id,reporting_site_id,local_site_uuid,generated_at_ms,overall_state,semantic_hash,snapshot_json)
      VALUES (?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE local_site_uuid=VALUES(local_site_uuid),generated_at_ms=VALUES(generated_at_ms),overall_state=VALUES(overall_state),
      semantic_hash=VALUES(semantic_hash),snapshot_json=VALUES(snapshot_json),received_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP");
    $stmt->execute([$userId,$reportingSiteId,$snapshot['local_site_id'],$snapshot['generated_at'],$snapshot['overall_state'],$hash,$json]);
    return ['accepted'=>true,'changed'=>1,'stale'=>0,'idempotent'=>0];
}
function tracky_v280_ffh_report(PDO $pdo,int $userId): array
{
    if($userId<1)return ['available'=>false,'protocol'=>VP3_TRACKY_FLEET_HEALTH_PROTOCOL_V280];
    tracky_v280_ffh_ensure_schema($pdo);
    $q=$pdo->prepare('SELECT reporting_site_id,snapshot_json,updated_at FROM tracky_cloud_federation_fleet_health WHERE user_id=? ORDER BY updated_at DESC,reporting_site_id');
    $q->execute([$userId]);$snapshots=[];
    foreach($q->fetchAll()?:[] as $row){
        $decoded=json_decode((string)($row['snapshot_json']??''),true);
        if(is_array($decoded))$snapshots[]=['reporting_site_id'=>(string)$row['reporting_site_id'],'updated_at'=>(string)$row['updated_at'],'fleet_health'=>$decoded];
    }
    return ['available'=>!empty($snapshots),'protocol'=>VP3_TRACKY_FLEET_HEALTH_PROTOCOL_V280,'snapshots'=>$snapshots,
      'preferred_fleet_health'=>$snapshots[0]['fleet_health']??null,'cloud_role'=>'mirror_only','cloud_read_only'=>true,
      'remote_command_execution'=>false,'authority_mutation'=>false];
}
function tracky_v280_ffh_public_capability(): array
{
    return ['version'=>'2.80','protocol'=>VP3_TRACKY_FLEET_HEALTH_PROTOCOL_V280,
      'states'=>['healthy','degraded','stale','recovering','offline','failed','unknown'],
      'section7_health_is_authoritative'=>true,'diagnostics_never_promote_federation_freshness'=>true,
      'privacy_safe_summary_only'=>true,'cloud_read_only'=>true,'remote_command_execution'=>false,'authority_mutation'=>false,
      'hardware_profiles'=>['homeserver','node','desk','studio','team_node','pocket','custom','future']];
}
