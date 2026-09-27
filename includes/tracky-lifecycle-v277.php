<?php
declare(strict_types=1);

/**
 * Tracky V2.77 — Cloud read-only physical model lifecycle mirror.
 *
 * Tracky/HomeServer owns candidate registration, promotion, canary assignment,
 * drift evaluation, activation and rollback. Cloud stores only compact status.
 */
const VP3_TRACKY_LIFECYCLE_V277='vp3-tracky-lifecycle-v277-20260927';
const VP3_TRACKY_LIFECYCLE_PROTOCOL_V277='physical_model_lifecycle.v1';

function tracky_v277_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    if(!$pdo)return false;
    return table_exists('tracky_cloud_model_lifecycle');
}

function tracky_v277_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS tracky_cloud_model_lifecycle (
      user_id INT UNSIGNED NOT NULL,
      site_id VARCHAR(100) NOT NULL,
      protocol_version VARCHAR(60) NOT NULL DEFAULT 'physical_model_lifecycle.v1',
      schema_version INT UNSIGNED NOT NULL DEFAULT 1,
      health_state VARCHAR(24) NOT NULL DEFAULT 'empty',
      active_count INT UNSIGNED NOT NULL DEFAULT 0,
      shadow_count INT UNSIGNED NOT NULL DEFAULT 0,
      canary_count INT UNSIGNED NOT NULL DEFAULT 0,
      degraded_count INT UNSIGNED NOT NULL DEFAULT 0,
      environment_profile_count INT UNSIGNED NOT NULL DEFAULT 0,
      summary_json LONGTEXT NOT NULL,
      fingerprint CHAR(64) NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,site_id),
      INDEX idx_tracky_lifecycle_updated (user_id,updated_at),
      CONSTRAINT fk_tracky_lifecycle_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tracky_v277_text(mixed $value,int $max=128,bool $required=false,string $label='value'): string
{
    $text=mb_strimwidth(trim((string)($value??'')),0,max(1,$max),'');
    if($required&&$text==='')throw new RuntimeException('Tracky model lifecycle '.$label.' is required.');
    return $text;
}

function tracky_v277_count(mixed $value,int $max=1000000): int
{
    if(!is_numeric($value))return 0;
    return max(0,min($max,(int)$value));
}

function tracky_v277_metric(mixed $value): ?float
{
    if($value===null||$value==='')return null;
    if(!is_numeric($value))throw new RuntimeException('Tracky model lifecycle metric must be numeric.');
    return max(0.0,min(1.0,(float)$value));
}

function tracky_v277_model(array $input,string $channel): array
{
    $allowed=['id','model_key','model_version','status','known_good','canary_percent','activated_at','registered_at'];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true)){
            throw new RuntimeException('Tracky model lifecycle model contains unsupported field: '.(string)$key);
        }
    }
    $statuses=['evaluating','canary','active','known_good','rolled_back','degraded'];
    $status=strtolower(tracky_v277_text($input['status']??'evaluating',32));
    if(!in_array($status,$statuses,true))throw new RuntimeException('Tracky model lifecycle status is invalid.');
    $percent=$input['canary_percent']??0;
    if(!is_numeric($percent))throw new RuntimeException('Tracky model lifecycle canary percent must be numeric.');
    return [
      'id'=>tracky_v277_text($input['id']??'',180,true,'model id'),
      'model_key'=>tracky_v277_text($input['model_key']??'',128,true,'model_key'),
      'model_version'=>tracky_v277_text($input['model_version']??'',80,true,'model_version'),
      'channel'=>$channel,
      'status'=>$status,
      'known_good'=>!empty($input['known_good']),
      'canary_percent'=>max(0.0,min(100.0,(float)$percent)),
      'activated_at'=>tracky_v277_text($input['activated_at']??'',64),
      'registered_at'=>tracky_v277_text($input['registered_at']??'',64),
    ];
}

function tracky_v277_scenario(array $input): array
{
    $allowed=['runs','pass_rate','critical_failures','passed'];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true)){
            throw new RuntimeException('Tracky model lifecycle scenario contains unsupported field: '.(string)$key);
        }
    }
    return [
      'runs'=>tracky_v277_count($input['runs']??0),
      'pass_rate'=>tracky_v277_metric($input['pass_rate']??null),
      'critical_failures'=>tracky_v277_count($input['critical_failures']??0),
      'passed'=>!empty($input['passed']),
    ];
}

function tracky_v277_decision(array $input): array
{
    $allowed=['id','type','model_id','from_channel','to_channel','reason','automatic','at'];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true)){
            throw new RuntimeException('Tracky model lifecycle decision contains unsupported field: '.(string)$key);
        }
    }
    return [
      'id'=>tracky_v277_text($input['id']??'',128,true,'decision id'),
      'type'=>tracky_v277_text($input['type']??'',60,true,'decision type'),
      'model_id'=>tracky_v277_text($input['model_id']??'',180,true,'decision model'),
      'from_channel'=>tracky_v277_text($input['from_channel']??'',30),
      'to_channel'=>tracky_v277_text($input['to_channel']??'',30),
      'reason'=>tracky_v277_text($input['reason']??'',500),
      'automatic'=>!empty($input['automatic']),
      'at'=>tracky_v277_text($input['at']??'',64),
    ];
}

function tracky_v277_normalize(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'model_lifecycle');
    $allowed=[
      'protocol','schema_version','active','shadow','canary','degraded',
      'scenario_status','recent_decisions','environment_profile_count','health_state',
      'summary_only','cloud_read_only','activation_authority','rollback_authority'
    ];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true)){
            throw new RuntimeException('Tracky model lifecycle summary contains unsupported field: '.(string)$key);
        }
    }
    if(trim((string)($input['protocol']??''))!==VP3_TRACKY_LIFECYCLE_PROTOCOL_V277){
        throw new RuntimeException('Tracky model lifecycle protocol is unsupported.');
    }
    if(empty($input['summary_only'])||empty($input['cloud_read_only'])){
        throw new RuntimeException('Tracky Cloud accepts read-only model lifecycle summaries only.');
    }
    if((string)($input['activation_authority']??'')!=='local_only'
        ||(string)($input['rollback_authority']??'')!=='local_only'){
        throw new RuntimeException('Tracky model lifecycle activation and rollback authority must remain local.');
    }
    $health=strtolower(tracky_v277_text($input['health_state']??'empty',24));
    if(!in_array($health,['empty','evaluating','healthy','recovering','degraded'],true)){
        throw new RuntimeException('Tracky model lifecycle health state is invalid.');
    }

    $out=[
      'protocol'=>VP3_TRACKY_LIFECYCLE_PROTOCOL_V277,
      'schema_version'=>max(1,min(100,(int)($input['schema_version']??1))),
      'active'=>[],
      'shadow'=>[],
      'canary'=>[],
      'degraded'=>[],
      'scenario_status'=>[],
      'recent_decisions'=>[],
      'environment_profile_count'=>tracky_v277_count($input['environment_profile_count']??0),
      'health_state'=>$health,
      'summary_only'=>true,
      'cloud_read_only'=>true,
      'activation_authority'=>'local_only',
      'rollback_authority'=>'local_only',
    ];

    foreach(['active','shadow','canary','degraded'] as $channel){
        $rows=is_array($input[$channel]??null)?array_values($input[$channel]):[];
        if(count($rows)>64)throw new RuntimeException('Tracky model lifecycle model batch exceeds the Cloud limit.');
        foreach($rows as $row){
            if(!is_array($row))throw new RuntimeException('Tracky model lifecycle model is invalid.');
            $out[$channel][]=tracky_v277_model($row,$channel==='degraded'?'active':$channel);
        }
    }

    $scenarios=is_array($input['scenario_status']??null)?$input['scenario_status']:[];
    if(count($scenarios)>128)throw new RuntimeException('Tracky model lifecycle scenario batch exceeds the Cloud limit.');
    foreach($scenarios as $modelId=>$scenario){
        if(!is_array($scenario))throw new RuntimeException('Tracky model lifecycle scenario is invalid.');
        $safeId=tracky_v277_text($modelId,180,true,'scenario model id');
        $out['scenario_status'][$safeId]=tracky_v277_scenario($scenario);
    }

    $decisions=is_array($input['recent_decisions']??null)?array_values($input['recent_decisions']):[];
    if(count($decisions)>20)throw new RuntimeException('Tracky model lifecycle decision batch exceeds the Cloud limit.');
    foreach($decisions as $decision){
        if(!is_array($decision))throw new RuntimeException('Tracky model lifecycle decision is invalid.');
        $out['recent_decisions'][]=tracky_v277_decision($decision);
    }
    return $out;
}

function tracky_v277_ingest(PDO $pdo,int $userId,string $siteId,array $input): array
{
    if($userId<1)throw new RuntimeException('Authentication required.');
    $siteId=tracky_cloud_v270_site_id($siteId);
    $summary=tracky_v277_normalize($input);
    $json=tracky_cloud_v270_json($summary);
    $fingerprint=hash('sha256',$json);

    $q=$pdo->prepare('SELECT fingerprint FROM tracky_cloud_model_lifecycle WHERE user_id=? AND site_id=? LIMIT 1');
    $q->execute([$userId,$siteId]);
    $prior=(string)($q->fetchColumn()?:'');
    $changed=$prior===''||!hash_equals($prior,$fingerprint);

    $stmt=$pdo->prepare("INSERT INTO tracky_cloud_model_lifecycle
      (user_id,site_id,protocol_version,schema_version,health_state,active_count,shadow_count,canary_count,degraded_count,environment_profile_count,summary_json,fingerprint)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE
        protocol_version=VALUES(protocol_version),
        schema_version=VALUES(schema_version),
        health_state=VALUES(health_state),
        active_count=VALUES(active_count),
        shadow_count=VALUES(shadow_count),
        canary_count=VALUES(canary_count),
        degraded_count=VALUES(degraded_count),
        environment_profile_count=VALUES(environment_profile_count),
        summary_json=VALUES(summary_json),
        updated_at=IF(NOT (fingerprint <=> VALUES(fingerprint)),CURRENT_TIMESTAMP,updated_at),
        fingerprint=VALUES(fingerprint)");
    $stmt->execute([
      $userId,$siteId,VP3_TRACKY_LIFECYCLE_PROTOCOL_V277,(int)$summary['schema_version'],
      $summary['health_state'],count($summary['active']),count($summary['shadow']),
      count($summary['canary']),count($summary['degraded']),
      (int)$summary['environment_profile_count'],$json,$fingerprint
    ]);
    return ['accepted'=>true,'changed'=>$changed,'fingerprint'=>$fingerprint,'summary'=>$summary];
}

function tracky_v277_report(PDO $pdo,int $userId,?string $siteId=null): array
{
    if($userId<1)return ['available'=>false,'protocol'=>VP3_TRACKY_LIFECYCLE_PROTOCOL_V277];
    if($siteId!==null&&trim($siteId)!==''){
        $siteId=tracky_cloud_v270_site_id($siteId);
        $stmt=$pdo->prepare("SELECT * FROM tracky_cloud_model_lifecycle WHERE user_id=? AND site_id=? LIMIT 1");
        $stmt->execute([$userId,$siteId]);
    }else{
        $stmt=$pdo->prepare("SELECT * FROM tracky_cloud_model_lifecycle WHERE user_id=? ORDER BY updated_at DESC LIMIT 1");
        $stmt->execute([$userId]);
    }
    $row=$stmt->fetch();
    if(!$row)return [
      'available'=>false,'protocol'=>VP3_TRACKY_LIFECYCLE_PROTOCOL_V277,
      'authority'=>'homeserver_tracky_summary','cloud_read_only'=>true,
      'activation_authority'=>'local_only','rollback_authority'=>'local_only'
    ];
    $decoded=json_decode((string)($row['summary_json']??''),true);
    $summary=is_array($decoded)?$decoded:[];
    return [
      'available'=>true,
      'protocol'=>(string)$row['protocol_version'],
      'authority'=>'homeserver_tracky_summary',
      'site_id'=>(string)$row['site_id'],
      'schema_version'=>(int)$row['schema_version'],
      'health_state'=>(string)$row['health_state'],
      'active_count'=>(int)$row['active_count'],
      'shadow_count'=>(int)$row['shadow_count'],
      'canary_count'=>(int)$row['canary_count'],
      'degraded_count'=>(int)$row['degraded_count'],
      'environment_profile_count'=>(int)$row['environment_profile_count'],
      'active'=>is_array($summary['active']??null)?$summary['active']:[],
      'shadow'=>is_array($summary['shadow']??null)?$summary['shadow']:[],
      'canary'=>is_array($summary['canary']??null)?$summary['canary']:[],
      'degraded'=>is_array($summary['degraded']??null)?$summary['degraded']:[],
      'scenario_status'=>is_array($summary['scenario_status']??null)?$summary['scenario_status']:[],
      'recent_decisions'=>is_array($summary['recent_decisions']??null)?$summary['recent_decisions']:[],
      'updated_at'=>$row['updated_at']??null,
      'cloud_read_only'=>true,
      'activation_authority'=>'local_only',
      'rollback_authority'=>'local_only',
    ];
}

function tracky_v277_account_summary(PDO $pdo,int $userId,?string $siteId=null): array
{
    $report=tracky_v277_report($pdo,$userId,$siteId);
    $rollbacks=array_values(array_filter(
      (array)($report['recent_decisions']??[]),
      static fn($item)=>is_array($item)&&($item['type']??'')==='rollback'
    ));
    return [
      'available'=>!empty($report['available']),
      'site_id'=>(string)($report['site_id']??''),
      'health_state'=>(string)($report['health_state']??'empty'),
      'active_models'=>(int)($report['active_count']??0),
      'shadow_models'=>(int)($report['shadow_count']??0),
      'canary_models'=>(int)($report['canary_count']??0),
      'degraded_models'=>(int)($report['degraded_count']??0),
      'environment_profiles'=>(int)($report['environment_profile_count']??0),
      'recent_rollbacks'=>count($rollbacks),
      'updated_at'=>$report['updated_at']??null,
      'authority'=>'homeserver_tracky_summary',
      'cloud_read_only'=>true,
      'activation_authority'=>'local_only',
      'rollback_authority'=>'local_only',
    ];
}

function tracky_v277_cognitive_permission(
    PDO $pdo,array $user,string $agentNamespace,array $ref,string $operation='read'
): bool {
    if($operation!=='read'||(int)($user['id']??0)<1)return false;
    if(!function_exists('tracky_cloud_v270_plugin_enabled')||!tracky_cloud_v270_plugin_enabled($pdo,$user))return false;
    return (string)($ref['type']??'')==='physical_model_lifecycle';
}

function tracky_v277_register_cognitive(): void
{
    if(!function_exists('vp3_cognitive_register_module_v500'))return;
    $registry=vp3_cognitive_registry_storage_v500();
    if(isset($registry['modules']['physical_model_lifecycle']))return;
    vp3_cognitive_register_module_v500([
      'module'=>'physical_model_lifecycle',
      'version'=>'tracky-v2.77',
      'objects'=>['physical_model_lifecycle'],
      'events'=>[],
      'permission_resolver'=>'tracky_v277_cognitive_permission',
      'context_provider'=>null,
      'relationship_provider'=>null,
      'cards'=>[],
      'tools'=>[
        'tracky.model_lifecycle'=>[
          'label'=>'Read physical model lifecycle and rollout health',
          'kind'=>'read','risk'=>'low','requires_approval'=>false
        ],
      ],
      'freshness_policy'=>['stale_behavior'=>'label_and_continue'],
      'sensitivity_policy'=>[
        'summary_only'=>true,
        'cloud_read_only'=>true,
        'activation_authority'=>'local_only',
        'rollback_authority'=>'local_only',
        'environment_profile_details_exposed'=>false,
        'package_checksums_exposed'=>false,
      ],
      'surfaces'=>['brief','chat_response'],
      'voice_safe'=>false,
    ]);
}

function tracky_v277_public_capability(): array
{
    return [
      'version'=>'2.77',
      'protocol'=>VP3_TRACKY_LIFECYCLE_PROTOCOL_V277,
      'authority'=>'homeserver_tracky_summary',
      'cloud_read_only'=>true,
      'activation_authority'=>'local_only',
      'rollback_authority'=>'local_only',
      'promotion_authority'=>false,
      'candidate_registration_authority'=>false,
      'environment_profile_details_exposed'=>false,
      'package_checksums_exposed'=>false,
    ];
}
