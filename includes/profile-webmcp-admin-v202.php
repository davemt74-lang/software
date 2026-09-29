<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_ADMIN_V202='profile-webmcp-admin-v202-20260929';
const VP3_PROFILE_WEBMCP_ADMIN_CONTRACT_V202='vp3.profile.webmcp.admin.v1';

function vp3_profile_webmcp_admin_event_public_v202(array $row): array
{
    $details=json_decode((string)($row['details_json']??''),true);
    if(!is_array($details))$details=[];
    return [
        'id'=>(int)($row['id']??0),
        'owner_user_id'=>(int)($row['owner_user_id']??0),
        'property_id'=>(int)($row['property_id']??0),
        'event_name'=>(string)($row['event_type']??''),
        'surface'=>(string)($details['surface']??''),
        'tool'=>(string)($details['tool']??''),
        'status'=>(string)($details['status']??''),
        'result_code'=>(string)($details['result_code']??''),
        'duration_ms'=>(int)($details['duration_ms']??0),
        'client_manifest_version'=>(string)($details['client_manifest_version']??''),
        'client_release_version'=>(string)($details['client_release_version']??''),
        'client_runtime_build'=>(string)($details['client_runtime_build']??''),
        'negotiation_mode'=>(string)($details['negotiation_mode']??''),
        'occurred_at'=>(string)($row['occurred_at']??''),
    ];
}

function vp3_profile_webmcp_admin_summarize_v202(array $rows): array
{
    $calls=0;$completed=0;$failures=0;$denied=0;$confirmations=0;$manifests=0;
    $runtime=[];$surface=[];$modes=[];$lastFailure=null;
    foreach($rows as $raw){
        $row=isset($raw['event_name'])?$raw:vp3_profile_webmcp_admin_event_public_v202($raw);
        $event=(string)($row['event_name']??'');
        if($event==='webmcp_tool_called')$calls++;
        if($event==='webmcp_tool_completed')$completed++;
        if($event==='webmcp_tool_failed'){$failures++;if($lastFailure===null)$lastFailure=$row;}
        if($event==='webmcp_tool_denied'){$denied++;if($lastFailure===null)$lastFailure=$row;}
        if($event==='webmcp_confirmation_required')$confirmations++;
        if($event==='webmcp_manifest_loaded')$manifests++;
        $build=trim((string)($row['client_runtime_build']??''));
        if($build!=='')$runtime[$build]=($runtime[$build]??0)+1;
        $s=trim((string)($row['surface']??''));
        if($s!=='')$surface[$s]=($surface[$s]??0)+1;
        $mode=trim((string)($row['negotiation_mode']??''));
        if($mode!=='')$modes[$mode]=($modes[$mode]??0)+1;
    }
    arsort($runtime);arsort($surface);arsort($modes);
    $attempts=$completed+$failures+$denied;
    $failureRate=$attempts>0?round((($failures+$denied)/$attempts)*100,1):0.0;
    return [
        'contract'=>VP3_PROFILE_WEBMCP_ADMIN_CONTRACT_V202,
        'version'=>VP3_PROFILE_WEBMCP_ADMIN_V202,
        'event_count'=>count($rows),
        'calls'=>$calls,
        'completed'=>$completed,
        'failures'=>$failures,
        'denied'=>$denied,
        'confirmation_required'=>$confirmations,
        'manifest_loads'=>$manifests,
        'failure_rate_percent'=>$failureRate,
        'runtime_distribution'=>$runtime,
        'surface_distribution'=>$surface,
        'negotiation_distribution'=>$modes,
        'last_failure'=>$lastFailure,
        'contains_sensitive_payload'=>false,
    ];
}

function vp3_profile_webmcp_admin_rows_v202(PDO $pdo,int $ownerUserId=0,int $propertyId=0,int $limit=300): array
{
    if(!function_exists('vp3_radar_schema_ready')||!vp3_radar_schema_ready($pdo))return [];
    $limit=max(1,min(500,$limit));
    $where=["e.event_type LIKE 'webmcp\\_%'","e.occurred_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)"];
    $params=[];
    if($ownerUserId>0){$where[]='e.owner_user_id=?';$params[]=$ownerUserId;}
    if($propertyId>0){$where[]='e.property_id=?';$params[]=$propertyId;}
    $sql="SELECT e.id,e.owner_user_id,e.property_id,e.event_type,e.details_json,e.occurred_at
          FROM vp3_radar_events e WHERE ".implode(' AND ',$where)." ORDER BY e.occurred_at DESC,e.id DESC LIMIT {$limit}";
    try{$stmt=$pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchAll()?:[];}catch(Throwable $e){return [];}
}

function vp3_profile_webmcp_admin_overview_v202(PDO $pdo,int $ownerUserId=0,int $propertyId=0): array
{
    $rows=vp3_profile_webmcp_admin_rows_v202($pdo,$ownerUserId,$propertyId,300);
    $events=array_map('vp3_profile_webmcp_admin_event_public_v202',$rows);
    $summary=vp3_profile_webmcp_admin_summarize_v202($events);

    $activeProfiles=0;$connectedSites=0;$owners=[];$properties=[];
    try{
        if(function_exists('table_exists')&&table_exists('profiles')){
            $activeProfiles=(int)$pdo->query("SELECT COUNT(*) FROM profiles WHERE is_active=1 AND is_public=1")->fetchColumn();
        }
    }catch(Throwable $e){}
    try{
        if(function_exists('table_exists')&&table_exists('vp3_radar_properties')){
            $connectedSites=(int)$pdo->query("SELECT COUNT(*) FROM vp3_radar_properties WHERE property_type='external' AND is_active=1")->fetchColumn();
            $q=$pdo->query("SELECT id,owner_user_id,label,domain,is_active FROM vp3_radar_properties WHERE property_type='external' ORDER BY is_active DESC,label ASC,id ASC LIMIT 100");
            $properties=$q?$q->fetchAll():[];
        }
    }catch(Throwable $e){}
    try{
        if(function_exists('table_exists')&&table_exists('users')){
            $q=$pdo->query("SELECT id,display_name,email FROM users ORDER BY display_name ASC,id ASC LIMIT 500");
            $owners=$q?$q->fetchAll():[];
        }
    }catch(Throwable $e){}

    return [
        'contract'=>VP3_PROFILE_WEBMCP_ADMIN_CONTRACT_V202,
        'version'=>VP3_PROFILE_WEBMCP_ADMIN_V202,
        'active_profiles'=>$activeProfiles,
        'connected_sites'=>$connectedSites,
        'summary'=>$summary,
        'events'=>$events,
        'owners'=>$owners,
        'properties'=>$properties,
        'release'=>function_exists('vp3_profile_webmcp_release_descriptor_v196')?vp3_profile_webmcp_release_descriptor_v196():[],
        'release_audit'=>function_exists('vp3_profile_webmcp_release_audit_v196')?vp3_profile_webmcp_release_audit_v196():['ok'=>false],
        'contains_sensitive_payload'=>false,
    ];
}
