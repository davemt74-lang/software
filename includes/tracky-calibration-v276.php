<?php
declare(strict_types=1);

/**
 * Tracky V2.76 — Cloud model-accuracy mirror.
 *
 * Tracky/HomeServer remains the calibration authority. Cloud stores only the
 * latest compact semantic accuracy summary for Agent/UI reads. Cloud cannot
 * record forecasts, settle outcomes, train models, or rewrite calibration.
 */
const VP3_TRACKY_CALIBRATION_V276='vp3-tracky-calibration-v276-20260927';
const VP3_TRACKY_CALIBRATION_PROTOCOL_V276='forecast_calibration.v1';

function tracky_v276_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    return table_exists('tracky_cloud_calibration');
}

function tracky_v276_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_calibration (
      user_id INT UNSIGNED NOT NULL,
      site_id VARCHAR(100) NOT NULL,
      protocol_version VARCHAR(40) NOT NULL DEFAULT 'forecast_calibration.v1',
      schema_version INT UNSIGNED NOT NULL DEFAULT 1,
      prediction_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
      settlement_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
      summary_json LONGTEXT NOT NULL,
      fingerprint CHAR(64) NOT NULL,
      generated_at DATETIME NULL,
      observed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,site_id),
      INDEX idx_tracky_calibration_updated (user_id,updated_at),
      CONSTRAINT fk_tracky_calibration_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tracky_v276_metric(mixed $value): ?float
{
    if($value===null||$value==='')return null;
    if(!is_numeric($value))throw new RuntimeException('Tracky calibration metric must be numeric.');
    return max(0.0,min(1.0,(float)$value));
}

function tracky_v276_count(mixed $value): int
{
    if(!is_numeric($value))return 0;
    return max(0,min(100000000,(int)$value));
}

function tracky_v276_text(mixed $value,int $max=128,bool $required=false,string $label='value'): string
{
    $text=mb_strimwidth(trim((string)($value??'')),0,max(1,$max),'');
    if($required&&$text==='')throw new RuntimeException('Tracky calibration '.$label.' is required.');
    return $text;
}

function tracky_v276_timestamp_sql(mixed $value): ?string
{
    $text=trim((string)($value??''));
    if($text==='')return null;
    try{return tracky_cloud_v270_time($text)->format('Y-m-d H:i:s');}
    catch(Throwable $e){throw new RuntimeException('Tracky calibration timestamp is invalid.');}
}

function tracky_v276_profile(array $input): array
{
    $allowed=[
      'model_key','model_version','kind','channel','settled_count','weighted_count',
      'mean_original_confidence','empirical_accuracy','brier_score','expected_calibration_error'
    ];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true)){
            throw new RuntimeException('Tracky calibration profile contains unsupported field: '.(string)$key);
        }
    }
    $channel=strtolower(tracky_v276_text($input['channel']??'active',20));
    if(!in_array($channel,['active','shadow','canary'],true)){
        throw new RuntimeException('Tracky calibration channel is invalid.');
    }
    $weighted=$input['weighted_count']??0;
    if(!is_numeric($weighted))throw new RuntimeException('Tracky calibration weighted_count must be numeric.');
    return [
      'model_key'=>tracky_v276_text($input['model_key']??'',128,true,'model_key'),
      'model_version'=>tracky_v276_text($input['model_version']??'unversioned',80),
      'kind'=>tracky_v276_text($input['kind']??'generic',80),
      'channel'=>$channel,
      'settled_count'=>tracky_v276_count($input['settled_count']??0),
      'weighted_count'=>max(0.0,min(100000000.0,(float)$weighted)),
      'mean_original_confidence'=>tracky_v276_metric($input['mean_original_confidence']??null),
      'empirical_accuracy'=>tracky_v276_metric($input['empirical_accuracy']??null),
      'brier_score'=>tracky_v276_metric($input['brier_score']??null),
      'expected_calibration_error'=>tracky_v276_metric($input['expected_calibration_error']??null),
    ];
}

function tracky_v276_normalize(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'forecast_calibration');
    $allowed=[
      'protocol','schema_version','generated_at','observed_at','predictions','settlements',
      'profiles','summary_only','prediction_records_exposed','settlement_records_exposed'
    ];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true)){
            throw new RuntimeException('Tracky calibration summary contains unsupported field: '.(string)$key);
        }
    }
    if(trim((string)($input['protocol']??''))!==VP3_TRACKY_CALIBRATION_PROTOCOL_V276){
        throw new RuntimeException('Tracky calibration protocol is unsupported.');
    }
    if(empty($input['summary_only'])){
        throw new RuntimeException('Tracky Cloud accepts calibration summaries only.');
    }
    if(!empty($input['prediction_records_exposed'])||!empty($input['settlement_records_exposed'])){
        throw new RuntimeException('Tracky Cloud calibration cannot contain prediction or settlement records.');
    }
    $profiles=is_array($input['profiles']??null)?array_values($input['profiles']):[];
    if(count($profiles)>50)throw new RuntimeException('Tracky calibration profile batch exceeds the Cloud limit.');
    $normalized=[];
    foreach($profiles as $profile){
        if(!is_array($profile))throw new RuntimeException('Tracky calibration profile is invalid.');
        if(array_key_exists('buckets',$profile))throw new RuntimeException('Tracky Cloud calibration does not accept confidence buckets.');
        $normalized[]=tracky_v276_profile($profile);
    }
    return [
      'protocol'=>VP3_TRACKY_CALIBRATION_PROTOCOL_V276,
      'schema_version'=>max(1,min(100,(int)($input['schema_version']??1))),
      'generated_at'=>tracky_v276_text($input['generated_at']??'',64),
      'observed_at'=>tracky_v276_text($input['observed_at']??'',64),
      'predictions'=>tracky_v276_count($input['predictions']??0),
      'settlements'=>tracky_v276_count($input['settlements']??0),
      'profiles'=>$normalized,
      'summary_only'=>true,
      'prediction_records_exposed'=>false,
      'settlement_records_exposed'=>false,
    ];
}

function tracky_v276_ingest(PDO $pdo,int $userId,string $siteId,array $input): array
{
    if($userId<1)throw new RuntimeException('Authentication required.');
    $siteId=tracky_cloud_v270_site_id($siteId);
    $summary=tracky_v276_normalize($input);
    $json=tracky_cloud_v270_json($summary);
    $fingerprint=hash('sha256',$json);
    $generated=tracky_v276_timestamp_sql($summary['generated_at']);
    $observed=tracky_v276_timestamp_sql($summary['observed_at']);

    $q=$pdo->prepare('SELECT fingerprint FROM tracky_cloud_calibration WHERE user_id=? AND site_id=? LIMIT 1');
    $q->execute([$userId,$siteId]);
    $prior=(string)($q->fetchColumn()?:'');
    $changed=$prior===''||!hash_equals($prior,$fingerprint);

    $stmt=$pdo->prepare("INSERT INTO tracky_cloud_calibration
      (user_id,site_id,protocol_version,schema_version,prediction_count,settlement_count,summary_json,fingerprint,generated_at,observed_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE
        protocol_version=VALUES(protocol_version),
        schema_version=VALUES(schema_version),
        prediction_count=VALUES(prediction_count),
        settlement_count=VALUES(settlement_count),
        summary_json=VALUES(summary_json),
        generated_at=VALUES(generated_at),
        observed_at=VALUES(observed_at),
        updated_at=IF(NOT (fingerprint <=> VALUES(fingerprint)),CURRENT_TIMESTAMP,updated_at),
        fingerprint=VALUES(fingerprint)");
    $stmt->execute([
      $userId,$siteId,VP3_TRACKY_CALIBRATION_PROTOCOL_V276,(int)$summary['schema_version'],
      (int)$summary['predictions'],(int)$summary['settlements'],$json,$fingerprint,$generated,$observed
    ]);
    return ['accepted'=>true,'changed'=>$changed,'fingerprint'=>$fingerprint,'summary'=>$summary];
}

function tracky_v276_report(PDO $pdo,int $userId,?string $siteId=null): array
{
    if($userId<1)return ['available'=>false,'protocol'=>VP3_TRACKY_CALIBRATION_PROTOCOL_V276,'profiles'=>[]];
    if($siteId!==null&&trim($siteId)!==''){
        $siteId=tracky_cloud_v270_site_id($siteId);
        $stmt=$pdo->prepare("SELECT * FROM tracky_cloud_calibration WHERE user_id=? AND site_id=? LIMIT 1");
        $stmt->execute([$userId,$siteId]);
    }else{
        $stmt=$pdo->prepare("SELECT * FROM tracky_cloud_calibration WHERE user_id=? ORDER BY updated_at DESC LIMIT 1");
        $stmt->execute([$userId]);
    }
    $row=$stmt->fetch();
    if(!$row)return [
      'available'=>false,'protocol'=>VP3_TRACKY_CALIBRATION_PROTOCOL_V276,
      'authority'=>'homeserver_tracky_summary','profiles'=>[],
      'cloud_read_only'=>true,'training_authority'=>false
    ];
    $decoded=json_decode((string)($row['summary_json']??''),true);
    $summary=is_array($decoded)?$decoded:[];
    return [
      'available'=>true,
      'protocol'=>(string)$row['protocol_version'],
      'authority'=>'homeserver_tracky_summary',
      'site_id'=>(string)$row['site_id'],
      'schema_version'=>(int)$row['schema_version'],
      'prediction_count'=>(int)$row['prediction_count'],
      'settlement_count'=>(int)$row['settlement_count'],
      'profiles'=>is_array($summary['profiles']??null)?$summary['profiles']:[],
      'generated_at'=>$row['generated_at']??null,
      'observed_at'=>$row['observed_at']??null,
      'updated_at'=>$row['updated_at']??null,
      'cloud_read_only'=>true,
      'training_authority'=>false,
      'settlement_authority'=>false,
      'prediction_records_exposed'=>false,
      'settlement_records_exposed'=>false,
      'confidence_buckets_exposed'=>false,
    ];
}

function tracky_v276_account_summary(PDO $pdo,int $userId,?string $siteId=null): array
{
    $report=tracky_v276_report($pdo,$userId,$siteId);
    $active=array_values(array_filter(
      (array)($report['profiles']??[]),
      static fn($item)=>is_array($item)&&($item['channel']??'')==='active'
    ));
    $weightedBrier=0.0;$weightedEce=0.0;$brierWeight=0;$eceWeight=0;$settled=0;
    foreach($active as $profile){
        $count=max(0,(int)($profile['settled_count']??0));$settled+=$count;
        if($profile['brier_score']!==null){$weightedBrier+=(float)$profile['brier_score']*$count;$brierWeight+=$count;}
        if($profile['expected_calibration_error']!==null){$weightedEce+=(float)$profile['expected_calibration_error']*$count;$eceWeight+=$count;}
    }
    return [
      'available'=>!empty($report['available']),
      'site_id'=>(string)($report['site_id']??''),
      'active_profiles'=>count($active),
      'active_settlements'=>$settled,
      'mean_brier_score'=>$brierWeight>0?$weightedBrier/$brierWeight:null,
      'mean_expected_calibration_error'=>$eceWeight>0?$weightedEce/$eceWeight:null,
      'generated_at'=>$report['generated_at']??null,
      'updated_at'=>$report['updated_at']??null,
      'authority'=>'homeserver_tracky_summary',
      'cloud_read_only'=>true,
    ];
}

function tracky_v276_cognitive_permission(
    PDO $pdo,array $user,string $agentNamespace,array $ref,string $operation='read'
): bool {
    if($operation!=='read'||(int)($user['id']??0)<1)return false;
    if(!function_exists('tracky_cloud_v270_plugin_enabled')||!tracky_cloud_v270_plugin_enabled($pdo,$user))return false;
    return (string)($ref['type']??'')==='physical_model_profile';
}

function tracky_v276_register_cognitive(): void
{
    if(!function_exists('vp3_cognitive_register_module_v500'))return;
    $registry=vp3_cognitive_registry_storage_v500();
    if(isset($registry['modules']['physical_model_accuracy']))return;
    vp3_cognitive_register_module_v500([
      'module'=>'physical_model_accuracy',
      'version'=>'tracky-v2.76',
      'objects'=>['physical_model_profile'],
      'events'=>[],
      'permission_resolver'=>'tracky_v276_cognitive_permission',
      'context_provider'=>null,
      'relationship_provider'=>null,
      'cards'=>[],
      'tools'=>[
        'tracky.model_accuracy'=>[
          'label'=>'Read physical-world model accuracy',
          'kind'=>'read','risk'=>'low','requires_approval'=>false
        ],
      ],
      'freshness_policy'=>['stale_behavior'=>'label_and_continue'],
      'sensitivity_policy'=>[
        'summary_only'=>true,
        'cloud_read_only'=>true,
        'prediction_records_exposed'=>false,
        'settlement_records_exposed'=>false,
        'confidence_buckets_exposed'=>false,
      ],
      'surfaces'=>['brief','chat_response'],
      'voice_safe'=>false,
    ]);
}

function tracky_v276_public_capability(): array
{
    return [
      'version'=>'2.76',
      'protocol'=>VP3_TRACKY_CALIBRATION_PROTOCOL_V276,
      'authority'=>'homeserver_tracky_summary',
      'cloud_read_only'=>true,
      'training_authority'=>false,
      'settlement_authority'=>false,
      'prediction_authority'=>false,
      'prediction_records_exposed'=>false,
      'settlement_records_exposed'=>false,
      'confidence_buckets_exposed'=>false,
    ];
}
