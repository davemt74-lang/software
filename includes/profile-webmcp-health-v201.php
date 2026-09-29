<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_HEALTH_V201='profile-webmcp-health-v201-20260929';
const VP3_PROFILE_WEBMCP_HEALTH_CONTRACT_V201='vp3.profile.webmcp.health.v1';

function vp3_profile_webmcp_health_component_v201(string $name,bool $ready,string $detail=''): array
{
    return ['name'=>$name,'status'=>$ready?'healthy':'unavailable','ready'=>$ready,'detail'=>mb_strimwidth(trim($detail),0,160,'')];
}

function vp3_profile_webmcp_health_event_projection_v201(?array $row): ?array
{
    if(!$row)return null;
    $details=json_decode((string)($row['details_json']??''),true);if(!is_array($details))$details=[];
    return [
        'event_name'=>(string)($row['event_type']??''),
        'surface'=>(string)($details['surface']??''),
        'tool'=>(string)($details['tool']??''),
        'status'=>(string)($details['status']??''),
        'result_code'=>(string)($details['result_code']??''),
        'duration_ms'=>(int)($details['duration_ms']??0),
        'runtime_build'=>(string)($details['runtime_build']??''),
        'release_version'=>(string)($details['release_version']??''),
        'registered_tool_count'=>(int)($details['registered_tool_count']??0),
        'expected_tool_count'=>(int)($details['expected_tool_count']??0),
        'occurred_at'=>(string)($row['occurred_at']??''),
    ];
}

function vp3_profile_webmcp_health_latest_event_v201(PDO $pdo,int $owner,string $kind,int $propertyId=0): ?array
{
    if($owner<1||!function_exists('vp3_radar_schema_ready')||!vp3_radar_schema_ready($pdo))return null;
    $events=$kind==='failure'?['webmcp_tool_failed','webmcp_tool_denied']:['webmcp_tool_completed'];
    $marks=implode(',',array_fill(0,count($events),'?'));
    $params=[$owner,...$events];
    $propertySql='';
    if($propertyId>0){$propertySql=' AND property_id=?';$params[]=$propertyId;}
    $stmt=$pdo->prepare("SELECT event_type,details_json,occurred_at FROM vp3_radar_events WHERE owner_user_id=? AND event_type IN ({$marks}){$propertySql} ORDER BY occurred_at DESC,id DESC LIMIT 1");
    $stmt->execute($params);
    return vp3_profile_webmcp_health_event_projection_v201($stmt->fetch()?:null);
}

function vp3_profile_webmcp_health_properties_v201(PDO $pdo,int $owner): array
{
    if($owner<1||!function_exists('vp3_radar_schema_ready')||!vp3_radar_schema_ready($pdo))return [];
    try{
        $stmt=$pdo->prepare("SELECT id,label,domain,is_active,property_type FROM vp3_radar_properties WHERE owner_user_id=? ORDER BY id ASC");
        $stmt->execute([$owner]);$rows=$stmt->fetchAll()?:[];$out=[];
        foreach($rows as $row){
            $type=(string)($row['property_type']??'');
            if(!in_array($type,['native','external'],true))continue;
            $out[]=[
                'property_id'=>(int)$row['id'],
                'type'=>$type,
                'label'=>(string)($row['label']??''),
                'domain'=>(string)($row['domain']??''),
                'active'=>!empty($row['is_active']),
            ];
        }
        return $out;
    }catch(Throwable $e){return [];}
}

function vp3_profile_webmcp_health_rollup_v201(array $components,?array $lastSuccess,?array $lastFailure,int $expectedTools,int $registeredTools): array
{
    $unavailable=count(array_filter($components,static fn(array $row):bool=>empty($row['ready'])));
    $registrationMismatch=$registeredTools>0&&$expectedTools>0&&$registeredTools!==$expectedTools;
    $status=$unavailable>0?'unavailable':($registrationMismatch?'degraded':'healthy');
    return [
        'status'=>$status,
        'component_failures'=>$unavailable,
        'registration_mismatch'=>$registrationMismatch,
        'expected_tool_count'=>max(0,$expectedTools),
        'registered_tool_count'=>max(0,$registeredTools),
        'last_success'=>$lastSuccess,
        'last_failure'=>$lastFailure,
    ];
}

function vp3_profile_webmcp_health_snapshot_v201(PDO $pdo,array $profile,array $viewer,int $propertyId=0): array
{
    $owner=(int)($profile['user_id']??0);$viewerId=(int)($viewer['id']??0);
    if($owner<1||$viewerId!==$owner)throw new RuntimeException('Profile WebMCP health requires the profile owner.');

    $releaseAudit=function_exists('vp3_profile_webmcp_release_audit_v196')?vp3_profile_webmcp_release_audit_v196():['ok'=>false];
    $components=[
        vp3_profile_webmcp_health_component_v201('capability_resolver',function_exists('vp3_profile_webmcp_resolve_capabilities_v190')),
        vp3_profile_webmcp_health_component_v201('tool_router',function_exists('vp3_profile_webmcp_dispatch_v191')),
        vp3_profile_webmcp_health_component_v201('confirmation_ledger',function_exists('vp3_profile_webmcp_actions_schema_ready_v150')&&vp3_profile_webmcp_actions_schema_ready_v150($pdo)),
        vp3_profile_webmcp_health_component_v201('continuity',function_exists('vp3_profile_webmcp_resume_consume_v194')&&function_exists('vp3_profile_webmcp_return_consume_v195')),
        vp3_profile_webmcp_health_component_v201('version_negotiation',function_exists('vp3_profile_webmcp_negotiate_v200')),
        vp3_profile_webmcp_health_component_v201('release_contract',!empty($releaseAudit['ok'])),
        vp3_profile_webmcp_health_component_v201('telemetry',function_exists('vp3_radar_schema_ready')&&vp3_radar_schema_ready($pdo)),
    ];

    $manifest=vp3_profile_webmcp_manifest_v100($pdo,$profile,$viewer,['surface'=>'native_profile']);
    $expected=count((array)($manifest['allowed_tools']??[]));
    $lastSuccess=vp3_profile_webmcp_health_latest_event_v201($pdo,$owner,'success',$propertyId);
    $lastFailure=vp3_profile_webmcp_health_latest_event_v201($pdo,$owner,'failure',$propertyId);
    $registered=(int)($lastSuccess['registered_tool_count']??0);
    if($registered<1)$registered=(int)($lastFailure['registered_tool_count']??0);
    $rollup=vp3_profile_webmcp_health_rollup_v201($components,$lastSuccess,$lastFailure,$expected,$registered);

    return [
        'contract'=>VP3_PROFILE_WEBMCP_HEALTH_CONTRACT_V201,
        'version'=>VP3_PROFILE_WEBMCP_HEALTH_V201,
        'profile_username'=>(string)($profile['username']??''),
        'checked_at_utc'=>gmdate('c'),
        'status'=>$rollup['status'],
        'components'=>$components,
        'runtime'=>[
            'expected_tool_count'=>$rollup['expected_tool_count'],
            'last_observed_registered_tool_count'=>$rollup['registered_tool_count'],
            'registration_mismatch'=>$rollup['registration_mismatch'],
            'last_success'=>$lastSuccess,
            'last_failure'=>$lastFailure,
        ],
        'properties'=>vp3_profile_webmcp_health_properties_v201($pdo,$owner),
        'release'=>[
            'version'=>defined('VP3_PROFILE_WEBMCP_RELEASE_V196')?VP3_PROFILE_WEBMCP_RELEASE_V196:'',
            'audit_ok'=>!empty($releaseAudit['ok']),
        ],
        'sensitive_payloads_included'=>false,
    ];
}
