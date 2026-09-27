<?php
declare(strict_types=1);

/**
 * Tracky V2.77 — Cloud model lifecycle mirror.
 *
 * Tracky/HomeServer remains activation, promotion and rollback authority.
 * Cloud stores only the latest compact semantic rollout status for Agent/UI.
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
      summary_json LONGTEXT NOT NULL,
      fingerprint CHAR(64) NOT NULL,
      generated_at DATETIME NULL,
      observed_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id,site_id),
      INDEX idx_tracky_model_lifecycle_updated (user_id,updated_at),
      CONSTRAINT fk_tracky_model_lifecycle_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function tracky_v277_text(mixed $value,int $max=128,bool $required=false,string $label='value'): string
{
    $text=mb_strimwidth(trim((string)($value??'')),0,max(1,$max),'');
    if($required&&$text==='')throw new RuntimeException('Tracky model lifecycle '.$label.' is required.');
    return $text;
}

function tracky_v277_count(mixed $value,int $max=100000000): int
{
    if(!is_numeric($value))return 0;
    return max(0,min($max,(int)$value));
}

function tracky_v277_percent(mixed $value): float
{
    if(!is_numeric($value))return 0.0;
    return max(0.0,min(100.0,(float)$value));
}

function tracky_v277_timestamp_sql(mixed $value): ?string
{
    $text=trim((string)($value??''));
    if($text==='')return null;
    try{return tracky_cloud_v270_time($text)->format('Y-m-d H:i:s');}
    catch(Throwable $e){throw new RuntimeException('Tracky model lifecycle timestamp is invalid.');}
}

function tracky_v277_model(array $input,string $channel): array
{
    $allowed=['model_key','model_version','status','known_good','canary_percent'];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true)){
            throw new RuntimeException('Tracky model lifecycle model contains unsupported field: '.(string)$key);
        }
    }
    $percent=tracky_v277_percent($input['canary_percent']??0);
    if($channel==='active')$percent=100.0;
    if($channel!=='active'&&$channel!=='canary')$percent=0.0;
    return [
      'model_key'=>tracky_v277_text($input['model_key']??'',128,true,'model_key'),
      'model_version'=>tracky_v277_text($input['model_version']??'',80,true,'model_version'),
      'status'=>tracky_v277_text($input['status']??$channel,40),
      'known_good'=>!empty($input['known_good']),
      'canary_percent'=>$percent,
    ];
}

function tracky_v277_model_list(mixed $value,string $channel): array
{
    $items=is_array($value)?array_values($value):[];
    if(count($items)>16)throw new RuntimeException('Tracky model lifecycle list exceeds the Cloud limit.');
    $out=[];
    foreach($items as $item){
        if(!is_array($item))throw new RuntimeException('Tracky model lifecycle model is invalid.');
        $out[]=tracky_v277_model($item,$channel);
    }
    return $out;
}

function tracky_v277_last_decision(mixed $value): ?array
{
    if($value===null)return null;
    if(!is_array($value))throw new RuntimeException('Tracky model lifecycle last decision is invalid.');
    $allowed=['type','automatic','at'];
    foreach(array_keys($value) as $key){
        if(!in_array((string)$key,$allowed,true)){
            throw new RuntimeException('Tracky model lifecycle last decision contains unsupported field: '.(string)$key);
        }
    }
    return [
      'type'=>tracky_v277_text($value['type']??'',60),
      'automatic'=>!empty($value['automatic']),
      'at'=>tracky_v277_count($value['at']??0,PHP_INT_MAX),
    ];
}

function tracky_v277_normalize(array $input): array
{
    tracky_cloud_v270_assert_governed_value($input,'model_lifecycle');
    $allowed=[
      'protocol','schema_version','generated_at','observed_at',
      'active_models','shadow_models','canary_models','degraded_models',
      'environment_profile_count','last_decision','summary_only','activation_authority',
      'environment_profiles_exposed','decision_history_exposed','scenario_details_exposed'
    ];
    foreach(array_keys($input) as $key){
        if(!in_array((string)$key,$allowed,true)){
            throw new RuntimeException('Tracky model lifecycle summary contains unsupported field: '.(string)$key);
        }
    }
    if(trim((string)($input['protocol']??''))!==VP3_TRACKY_LIFECYCLE_PROTOCOL_V277){
        throw new RuntimeException('Tracky model lifecycle protocol is unsupported.');
    }
    if(empty($input['summary_only']))throw new RuntimeException('Tracky Cloud accepts model lifecycle summaries only.');
    if((string)($input['activation_authority']??'')!=='local_tracky'){
        throw new RuntimeException('Tracky model activation authority must remain local.');
    }
    if(!empty($input['environment_profiles_exposed'])
      ||!empty($input['decision_history_exposed'])
      ||!empty($input['scenario_details_exposed'])){
        throw new RuntimeException('Tracky Cloud lifecycle cannot contain local rollout details.');
    }
    return [
      'protocol'=>VP3_TRACKY_LIFECYCLE_PROTOCOL_V277,
      'schema_version'=>max(1,min(100,(int)($input['schema_version']??1))),
      'generated_at'=>tracky_v277_text($input['generated_at']??'',64),
      'observed_at'=>tracky_v277_text($input['observed_at']??'',64),
      'active_models'=>tracky_v277_model_list($input['active_models']??[],'active'),
      'shadow_models'=>tracky_v277_model_list($input['shadow_models']??[],'shadow'),
      'canary_models'=>tracky_v277_model_list($input['canary_models']??[],'canary'),
      'degraded_models'=>tracky_v277_model_list($input['degraded_models']??[],'active'),
      'environment_profile_count'=>tracky_v277_count($input['environment_profile_count']??0,100000),
      'last_decision'=>tracky_v277_last_decision($input['last_decision']??null),
      'summary_only'=>true,
      'activation_authority'=>'local_tracky',
      'environment_profiles_exposed'=>false,
      'decision_history_exposed'=>false,
      'scenario_details_exposed'=>false,
    ];
}

function tracky_v277_ingest(PDO $pdo,int $userId,string $siteId,array $input): array
{
    if($userId<1)throw new RuntimeException('Authentication required.');
    $siteId=tracky_cloud_v270_site_id($siteId);
    $summary=tracky_v277_normalize($input);
    $json=tracky_cloud_v270_json($summary);
    $fingerprint=hash('sha256',$json);
    $generated=tracky_v277_timestamp_sql($summary['generated_at']);
    $observed=tracky_v277_timestamp_sql($summary['observed_at']);

    $q=$pdo->prepare('SELECT fingerprint FROM tracky_cloud_model_lifecycle WHERE user_id=? AND site_id=? LIMIT 1');
    $q->execute([$userId,$siteId]);
    $prior=(string)($q->fetchColumn()?:'');
    $changed=$prior===''||!hash_equals($prior,$fingerprint);

    $stmt=$pdo->prepare("INSERT INTO tracky_cloud_model_lifecycle
      (user_id,site_id,protocol_version,schema_version,summary_json,fingerprint,generated_at,observed_at)
      VALUES (?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE
        protocol_version=VALUES(protocol_version),
        schema_version=VALUES(schema_version),
        summary_json=VALUES(summary_json),
        generated_at=VALUES(generated_at),
        observed_at=VALUES(observed_at),
        updated_at=IF(NOT (fingerprint <=> VALUES(fingerprint)),CURRENT_TIMESTAMP,updated_at),
        fingerprint=VALUES(fingerprint)");
    $stmt->execute([$userId,$siteId,VP3_TRACKY_LIFECYCLE_PROTOCOL_V277,(int)$summary['schema_version'],$json,$fingerprint,$generated,$observed]);
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
      'authority'=>'homeserver_tracky_summary','activation_authority'=>'local_tracky',
      'cloud_read_only'=>true
    ];
    $decoded=json_decode((string)($row['summary_json']??''),true);
    $summary=is_array($decoded)?$decoded:[];
    return [
      'available'=>true,
      'protocol'=>(string)$row['protocol_version'],
      'authority'=>'homeserver_tracky_summary',
      'activation_authority'=>'local_tracky',
      'site_id'=>(string)$row['site_id'],
      'schema_version'=>(int)$row['schema_version'],
      'active_models'=>(array)($summary['active_models']??[]),
      'shadow_models'=>(array)($summary['shadow_models']??[]),
      'canary_models'=>(array)($summary['canary_models']??[]),
      'degraded_models'=>(array)($summary['degraded_models']??[]),
      'environment_profile_count'=>(int)($summary['environment_profile_count']??0),
      'last_decision'=>is_array($summary['last_decision']??null)?$summary['last_decision']:null,
      'generated_at'=>$row['generated_at']??null,
      'observed_at'=>$row['observed_at']??null,
      'updated_at'=>$row['updated_at']??null,
      'cloud_read_only'=>true,
      'promotion_authority'=>false,
      'rollback_authority'=>false,
      'environment_profiles_exposed'=>false,
      'decision_history_exposed'=>false,
      'scenario_details_exposed'=>false,
    ];
}

function tracky_v277_account_summary(PDO $pdo,int $userId,?string $siteId=null): array
{
    $report=tracky_v277_report($pdo,$userId,$siteId);
    $active=(array)($report['active_models']??[]);
    $shadow=(array)($report['shadow_models']??[]);
    $canary=(array)($report['canary_models']??[]);
    $degraded=(array)($report['degraded_models']??[]);
    $state=!empty($degraded)?'degraded':(!empty($canary)?'canary':(!empty($shadow)?'evaluating':(!empty($active)?'healthy':'empty')));
    return [
      'available'=>!empty($report['available']),
      'site_id'=>(string)($report['site_id']??''),
      'state'=>$state,
      'active_models'=>count($active),
      'shadow_models'=>count($shadow),
      'canary_models'=>count($canary),
      'degraded_models'=>count($degraded),
      'environment_profile_count'=>(int)($report['environment_profile_count']??0),
      'last_decision'=>is_array($report['last_decision']??null)?$report['last_decision']:null,
      'authority'=>'homeserver_tracky_summary',
      'activation_authority'=>'local_tracky',
      'cloud_read_only'=>true,
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
          'label'=>'Read physical model rollout status',
          'kind'=>'read','risk'=>'low','requires_approval'=>false
        ],
      ],
      'freshness_policy'=>['stale_behavior'=>'label_and_continue'],
      'sensitivity_policy'=>[
        'summary_only'=>true,
        'cloud_read_only'=>true,
        'activation_authority'=>'local_tracky',
        'environment_profiles_exposed'=>false,
        'decision_history_exposed'=>false,
        'scenario_details_exposed'=>false,
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
      'activation_authority'=>'local_tracky',
      'cloud_read_only'=>true,
      'promotion_authority'=>false,
      'rollback_authority'=>false,
      'environment_profiles_exposed'=>false,
      'decision_history_exposed'=>false,
      'scenario_details_exposed'=>false,
    ];
}
