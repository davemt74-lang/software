<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_SITES_V204='profile-webmcp-sites-v204-20260929';
const VP3_PROFILE_WEBMCP_SITES_CONTRACT_V204='vp3.profile.webmcp.connected-sites.v1';
const VP3_PROFILE_WEBMCP_SITE_STALE_SECONDS_V204=604800;

function vp3_profile_webmcp_site_latest_event_v204(PDO $pdo,int $propertyId): ?array
{
    if($propertyId<1)return null;
    try{
        $stmt=$pdo->prepare("SELECT id,event_type,details_json,occurred_at FROM vp3_radar_events
            WHERE property_id=? AND event_type LIKE 'webmcp\\_%'
            ORDER BY occurred_at DESC,id DESC LIMIT 1");
        $stmt->execute([$propertyId]);$row=$stmt->fetch();
        if(!$row)return null;
        $details=json_decode((string)($row['details_json']??''),true);if(!is_array($details))$details=[];
        return [
            'id'=>(int)$row['id'],
            'event_name'=>(string)$row['event_type'],
            'runtime_build'=>(string)($details['client_runtime_build']??''),
            'manifest_version'=>(string)($details['client_manifest_version']??''),
            'release_version'=>(string)($details['client_release_version']??''),
            'negotiation_mode'=>(string)($details['negotiation_mode']??''),
            'result_code'=>(string)($details['result_code']??''),
            'occurred_at'=>(string)$row['occurred_at'],
        ];
    }catch(Throwable $e){return null;}
}

function vp3_profile_webmcp_site_origin_denied_count_v204(PDO $pdo,int $propertyId): int
{
    if($propertyId<1)return 0;
    try{
        $stmt=$pdo->prepare("SELECT details_json FROM vp3_radar_events
          WHERE property_id=? AND event_type='webmcp_tool_denied' AND occurred_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)
          ORDER BY occurred_at DESC,id DESC LIMIT 200");
        $stmt->execute([$propertyId]);$count=0;
        foreach($stmt->fetchAll()?:[] as $row){
            $details=json_decode((string)($row['details_json']??''),true);
            if(is_array($details)&&($details['result_code']??'')==='ORIGIN_DENIED')$count++;
        }
        return $count;
    }catch(Throwable $e){return 0;}
}

function vp3_profile_webmcp_site_record_origin_denied_v204(PDO $pdo,array $property): void
{
    try{
        $propertyId=(int)($property['id']??0);$owner=(int)($property['owner_user_id']??0);
        if($propertyId<1||$owner<1||!function_exists('vp3_profile_webmcp_session_v130'))return;
        $session=vp3_profile_webmcp_session_v130($pdo,$property,null,'OriginDenied_'.bin2hex(random_bytes(8)),'external_site');
        if(!$session)return;
        $context=[
            'owner_user_id'=>$owner,
            'property_id'=>$propertyId,
            'session_id'=>(int)$session['id'],
            'agent_contact_id'=>0,
            'surface'=>'external_site',
            'profile_username'=>'',
            'webmcp_session_id'=>'',
            'interaction_id'=>'',
            'attribution_origin'=>'webmcp_unclassified',
            'risk_score'=>0,
        ];
        vp3_profile_webmcp_record_v130($pdo,$context,'webmcp_tool_denied','','denied',0,['result_code'=>'ORIGIN_DENIED']);
    }catch(Throwable $e){
        error_log('VP3 WebMCP origin-denied telemetry failed: '.$e->getMessage());
    }
}

function vp3_profile_webmcp_site_state_v204(PDO $pdo,array $property): array
{
    $id=(int)($property['id']??0);$owner=(int)($property['owner_user_id']??0);
    $latest=vp3_profile_webmcp_site_latest_event_v204($pdo,$id);
    $lastAt=(string)($latest['occurred_at']??'');$lastTs=$lastAt!==''?strtotime($lastAt):false;
    $stale=$lastTs===false||$lastTs<time()-VP3_PROFILE_WEBMCP_SITE_STALE_SECONDS_V204;
    $active=!empty($property['is_active']);$verified=trim((string)($property['verified_at']??''))!=='';
    $expected=defined('VP3_PROFILE_WEBMCP_EXTERNAL_RUNTIME_V200')?VP3_PROFILE_WEBMCP_EXTERNAL_RUNTIME_V200:'profile-webmcp-external-v120-20260928';
    $observed=(string)($latest['runtime_build']??'');
    $runtimeState=$observed===''?'unseen':(hash_equals($expected,$observed)?'current':'upgrade_required');

    $features=['chat'=>false,'scheduling'=>false,'commerce'=>false,'campaigns'=>false,'read_only'=>true];
    $toolCount=0;$manifestError='';
    if($active&&$owner>0&&function_exists('vp3_profile_webmcp_external_profile_v120')&&function_exists('vp3_profile_webmcp_external_manifest_v120')){
        try{
            $profile=vp3_profile_webmcp_external_profile_v120($pdo,$property);
            $manifest=vp3_profile_webmcp_external_manifest_v120($pdo,$property,$profile,true,true,true,true);
            $toolCount=count((array)($manifest['allowed_tools']??[]));
            $external=(array)($manifest['external']??[]);
            $features=[
                'chat'=>!empty($external['stateful_profile_agent']),
                'scheduling'=>!empty($external['scheduling_enabled']),
                'commerce'=>!empty($external['commerce_enabled']),
                'campaigns'=>!empty($external['campaigns_enabled']),
                'read_only'=>!empty($external['read_only']),
            ];
        }catch(Throwable $e){$manifestError='manifest_unavailable';}
    }

    $status='healthy';
    if(!$active)$status='paused';
    elseif(!$verified)$status='unverified';
    elseif($runtimeState==='upgrade_required')$status='upgrade_required';
    elseif($stale)$status='stale';
    elseif($manifestError!=='')$status='degraded';

    $originDenied=vp3_profile_webmcp_site_origin_denied_count_v204($pdo,$id);
    if($originDenied>0&&$status==='healthy')$status='origin_attention';

    return [
        'contract'=>VP3_PROFILE_WEBMCP_SITES_CONTRACT_V204,
        'version'=>VP3_PROFILE_WEBMCP_SITES_V204,
        'property_id'=>$id,
        'owner_user_id'=>$owner,
        'label'=>(string)($property['label']??''),
        'domain'=>(string)($property['domain']??''),
        'is_active'=>$active,
        'verified'=>$verified,
        'verified_at'=>(string)($property['verified_at']??''),
        'status'=>$status,
        'stale'=>$stale,
        'last_contact_at'=>$lastAt,
        'last_event'=>(string)($latest['event_name']??''),
        'last_result_code'=>(string)($latest['result_code']??''),
        'runtime_build'=>$observed,
        'expected_runtime_build'=>$expected,
        'runtime_state'=>$runtimeState,
        'manifest_version'=>(string)($latest['manifest_version']??''),
        'release_version'=>(string)($latest['release_version']??''),
        'negotiation_mode'=>(string)($latest['negotiation_mode']??''),
        'origin_denied_30d'=>$originDenied,
        'tool_count'=>$toolCount,
        'features'=>$features,
        'upgrade_required'=>$runtimeState==='upgrade_required',
        'reverify_required'=>$active&&!$verified,
        'reconnect_required'=>$active&&$stale,
        'management_path'=>'/profile-agent.php',
        'upgrade_guidance'=>'Open Profile Agent → Connected Sites and copy the current VP3 Tracking + WebMCP install snippet to this site.',
        'reverify_guidance'=>'Confirm the registered domain is correct, reinstall the current snippet, and load the site from its registered Origin.',
        'contains_sensitive_payload'=>false,
    ];
}

function vp3_profile_webmcp_sites_admin_v204(PDO $pdo): array
{
    if(!function_exists('vp3_radar_schema_ready')||!vp3_radar_schema_ready($pdo))return [];
    try{
        $stmt=$pdo->query("SELECT id,owner_user_id,label,domain,is_active,verified_at,updated_at
          FROM vp3_radar_properties WHERE property_type='external'
          ORDER BY is_active DESC,label ASC,id ASC");
        $rows=$stmt?$stmt->fetchAll():[];
    }catch(Throwable $e){return [];}
    return array_map(static fn(array $row): array=>vp3_profile_webmcp_site_state_v204($pdo,$row),$rows);
}
