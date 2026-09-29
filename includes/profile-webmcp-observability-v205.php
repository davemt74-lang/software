<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_OBSERVABILITY_V205='profile-webmcp-observability-v205-20260929';
const VP3_PROFILE_WEBMCP_OBSERVABILITY_CONTRACT_V205='vp3.profile.webmcp.observability.v1';

function vp3_profile_webmcp_correlation_id_v205(string $value): string
{
    $value=trim($value);
    return preg_match('/^[A-Za-z0-9_-]{8,96}$/',$value)?$value:'';
}

function vp3_profile_webmcp_stage_v205(string $eventName,string $tool=''): string
{
    if(in_array($eventName,['webmcp_discovered','webmcp_manifest_loaded','webmcp_tool_registered'],true))return 'discovery';
    if(in_array($eventName,['webmcp_agent_planned','webmcp_resume_loaded'],true))return 'intent';
    if(str_ends_with($tool,'.prepare')||str_contains($eventName,'_prepared'))return 'prepare';
    if($eventName==='webmcp_confirmation_required'||str_ends_with($tool,'.confirm'))return 'confirmation';
    if(in_array($eventName,['webmcp_tool_completed','webmcp_booking_completed','webmcp_purchase_completed','webmcp_campaign_joined','webmcp_reward_claimed'],true))return 'completion';
    if($eventName==='webmcp_returned')return 'return';
    if(in_array($eventName,['webmcp_tool_failed','webmcp_tool_denied','webmcp_tool_cancelled'],true))return 'failure';
    return 'execution';
}

function vp3_profile_webmcp_observability_event_public_v205(array $row): array
{
    $details=json_decode((string)($row['details_json']??''),true);if(!is_array($details))$details=[];
    $event=(string)($row['event_type']??'');
    $tool=(string)($details['tool']??'');
    return [
        'id'=>(int)($row['id']??0),
        'owner_user_id'=>(int)($row['owner_user_id']??0),
        'property_id'=>(int)($row['property_id']??0),
        'event_name'=>$event,
        'stage'=>vp3_profile_webmcp_stage_v205($event,$tool),
        'surface'=>(string)($details['surface']??''),
        'correlation_id'=>vp3_profile_webmcp_correlation_id_v205((string)($details['correlation_id']??'')),
        'interaction_id'=>(string)($details['interaction_id']??''),
        'tool'=>$tool,
        'status'=>(string)($details['status']??''),
        'result_code'=>(string)($details['result_code']??''),
        'duration_ms'=>(int)($details['duration_ms']??0),
        'conversation_id'=>(int)($details['conversation_id']??0),
        'occurred_at'=>(string)($row['occurred_at']??''),
    ];
}

function vp3_profile_webmcp_observability_rows_v205(PDO $pdo,int $ownerUserId=0,int $propertyId=0,string $correlationId='',int $limit=400): array
{
    if(!function_exists('vp3_radar_schema_ready')||!vp3_radar_schema_ready($pdo))return [];
    $limit=max(1,min(800,$limit));
    $where=["e.event_type LIKE 'webmcp\\_%'","e.occurred_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)"];
    $params=[];
    if($ownerUserId>0){$where[]='e.owner_user_id=?';$params[]=$ownerUserId;}
    if($propertyId>0){$where[]='e.property_id=?';$params[]=$propertyId;}
    $correlationId=vp3_profile_webmcp_correlation_id_v205($correlationId);
    $sql="SELECT e.id,e.owner_user_id,e.property_id,e.event_type,e.details_json,e.occurred_at
      FROM vp3_radar_events e WHERE ".implode(' AND ',$where)." ORDER BY e.occurred_at DESC,e.id DESC LIMIT {$limit}";
    try{$stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll()?:[];}catch(Throwable $e){return [];}
    $out=[];
    foreach($rows as $row){
        $public=vp3_profile_webmcp_observability_event_public_v205($row);
        if($correlationId!==''&&!hash_equals($correlationId,(string)$public['correlation_id']))continue;
        $out[]=$public;
    }
    return $out;
}

function vp3_profile_webmcp_latency_summary_v205(array $events): array
{
    $groups=[];
    foreach($events as $row){
        $duration=max(0,(int)($row['duration_ms']??0));
        $tool=(string)($row['tool']??'');$surface=(string)($row['surface']??'');
        if($duration<=0||$tool===''||$surface==='')continue;
        $key=$surface.'|'.$tool;
        $groups[$key]??=['surface'=>$surface,'tool'=>$tool,'durations'=>[]];
        $groups[$key]['durations'][]=$duration;
    }
    $out=[];
    foreach($groups as $group){
        sort($group['durations']);$n=count($group['durations']);
        $p95=$group['durations'][max(0,min($n-1,(int)ceil($n*.95)-1))];
        $out[]=[
            'surface'=>$group['surface'],'tool'=>$group['tool'],'count'=>$n,
            'avg_ms'=>(int)round(array_sum($group['durations'])/$n),
            'p95_ms'=>$p95,'max_ms'=>max($group['durations']),
        ];
    }
    usort($out,static fn(array $a,array $b):int=>$b['avg_ms']<=>$a['avg_ms']);
    return $out;
}

function vp3_profile_webmcp_record_agent_event_v205(PDO $pdo,array $profile,array $user,string $eventName,string $correlationId,int $conversationId=0,string $status='planned'): ?int
{
    if(!in_array($eventName,['webmcp_agent_planned','webmcp_returned'],true))return null;
    $owner=(int)($profile['user_id']??0);$userId=(int)($user['id']??0);
    if($owner<1||$owner!==$userId)return null;
    $correlationId=vp3_profile_webmcp_correlation_id_v205($correlationId);
    if($correlationId==='')return null;
    try{
        $context=vp3_profile_webmcp_context_v130($pdo,$profile,'agent_brain',[
            'webmcp_session_id'=>'AgentBrain_'.substr($correlationId,0,32),
            'interaction_id'=>$correlationId,
            'correlation_id'=>$correlationId,
        ],null,$user);
        if(!$context)return null;
        return vp3_profile_webmcp_record_v130($pdo,$context,$eventName,'',$status,0,['conversation_id'=>$conversationId]);
    }catch(Throwable $e){
        error_log('VP3 WebMCP Agent observability failed: '.$e->getMessage());
        return null;
    }
}
