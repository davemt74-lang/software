<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_HEALTH_V201='profile-webmcp-health-v201-20260929';
const VP3_PROFILE_WEBMCP_HEALTH_CONTRACT_V201='vp3.profile.webmcp.health.v1';

function vp3_profile_webmcp_health_component_v201(bool $ready,string $version='',string $detail=''): array
{
    return [
        'status'=>$ready?'ready':'unavailable',
        'ready'=>$ready,
        'version'=>mb_strimwidth(trim($version),0,160,''),
        'detail'=>mb_strimwidth(trim($detail),0,160,''),
    ];
}

function vp3_profile_webmcp_health_recent_v201(PDO $pdo,int $ownerUserId,int $propertyId=0): array
{
    $rows=function_exists('vp3_profile_webmcp_owner_activity_v130')
        ?vp3_profile_webmcp_owner_activity_v130($pdo,$ownerUserId,$propertyId,80)
        :[];
    $lastSuccess=null;$lastFailure=null;$lastCall=null;$failures=0;$completed=0;
    foreach($rows as $row){
        $event=(string)($row['event_name']??'');
        if($lastCall===null&&$event==='webmcp_tool_called')$lastCall=$row;
        if($lastSuccess===null&&$event==='webmcp_tool_completed')$lastSuccess=$row;
        if($lastFailure===null&&in_array($event,['webmcp_tool_failed','webmcp_tool_denied'],true))$lastFailure=$row;
        if($event==='webmcp_tool_completed')$completed++;
        if(in_array($event,['webmcp_tool_failed','webmcp_tool_denied'],true))$failures++;
    }
    $project=static function(?array $row): ?array {
        if(!$row)return null;
        return [
            'event_name'=>(string)($row['event_name']??''),
            'tool'=>(string)($row['tool']??''),
            'status'=>(string)($row['status']??''),
            'surface'=>(string)($row['surface']??''),
            'result_code'=>(string)($row['result_code']??''),
            'duration_ms'=>(int)($row['duration_ms']??0),
            'occurred_at'=>(string)($row['occurred_at']??''),
        ];
    };
    return [
        'sample_size'=>count($rows),
        'completed_count'=>$completed,
        'failure_count'=>$failures,
        'last_call'=>$project($lastCall),
        'last_success'=>$project($lastSuccess),
        'last_failure'=>$project($lastFailure),
    ];
}

function vp3_profile_webmcp_health_v201(
    PDO $pdo,array $profile,?array $viewer,string $surface,array $manifest,?array $property=null
): array {
    $owner=(int)($profile['user_id']??0);
    $propertyId=(int)($property['id']??0);
    $release=function_exists('vp3_profile_webmcp_release_audit_v196')?vp3_profile_webmcp_release_audit_v196():['ok'=>false];
    $negotiation=is_array($manifest['negotiation']??null)?$manifest['negotiation']:(
        function_exists('vp3_profile_webmcp_negotiate_v200')?vp3_profile_webmcp_negotiate_v200($surface,[]):['compatible'=>false]
    );
    $resolverReady=function_exists('vp3_profile_webmcp_resolve_capabilities_v190')&&isset($manifest['resolver']);
    $routerReady=function_exists('vp3_profile_webmcp_dispatch_v191');
    $confirmationReady=function_exists('vp3_profile_webmcp_actions_schema_ready_v150')&&vp3_profile_webmcp_actions_schema_ready_v150($pdo);
    $continuityReady=function_exists('vp3_profile_webmcp_resume_issue_v194')&&function_exists('vp3_profile_webmcp_return_consume_v195');
    $telemetryReady=function_exists('vp3_radar_schema_ready')&&vp3_radar_schema_ready($pdo)&&function_exists('vp3_profile_webmcp_record_v130');
    $propertyReady=$surface!=='external_site'||($propertyId>0&&!empty($property['is_active']));
    $catalogCount=count(function_exists('vp3_profile_webmcp_tool_catalog_v100')?vp3_profile_webmcp_tool_catalog_v100():[]);
    $allowedCount=count((array)($manifest['allowed_tools']??[]));
    $components=[
        'resolver'=>vp3_profile_webmcp_health_component_v201($resolverReady,(string)($manifest['resolver']['version']??'')),
        'router'=>vp3_profile_webmcp_health_component_v201($routerReady,defined('VP3_PROFILE_WEBMCP_TOOL_ROUTER_V191')?VP3_PROFILE_WEBMCP_TOOL_ROUTER_V191:''),
        'confirmation'=>vp3_profile_webmcp_health_component_v201($confirmationReady,defined('VP3_PROFILE_WEBMCP_CONFIRMATION_V192')?VP3_PROFILE_WEBMCP_CONFIRMATION_V192:''),
        'continuity'=>vp3_profile_webmcp_health_component_v201($continuityReady,defined('VP3_PROFILE_WEBMCP_CONTINUITY_V195')?VP3_PROFILE_WEBMCP_CONTINUITY_V195:''),
        'telemetry'=>vp3_profile_webmcp_health_component_v201($telemetryReady,defined('VP3_PROFILE_WEBMCP_ANALYTICS_V130')?VP3_PROFILE_WEBMCP_ANALYTICS_V130:''),
        'property'=>vp3_profile_webmcp_health_component_v201($propertyReady,'',$surface==='external_site'?'connected_site':'native_profile'),
    ];
    $overall=!empty($release['ok'])&&!empty($negotiation['compatible']);
    foreach($components as $component)$overall=$overall&&!empty($component['ready']);
    return [
        'contract'=>VP3_PROFILE_WEBMCP_HEALTH_CONTRACT_V201,
        'version'=>VP3_PROFILE_WEBMCP_HEALTH_V201,
        'status'=>$overall?'healthy':'degraded',
        'healthy'=>$overall,
        'surface'=>$surface,
        'profile_username'=>(string)($profile['username']??''),
        'catalog_tool_count'=>$catalogCount,
        'allowed_tool_count'=>$allowedCount,
        'release_ok'=>!empty($release['ok']),
        'negotiation_compatible'=>!empty($negotiation['compatible']),
        'components'=>$components,
        'recent'=>$owner>0?vp3_profile_webmcp_health_recent_v201($pdo,$owner,$propertyId):[],
        'contains_sensitive_payload'=>false,
    ];
}
