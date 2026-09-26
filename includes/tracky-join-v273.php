<?php
declare(strict_types=1);

/**
 * Tracky V2.73 — OTRO/Cloud contract join + governed active perception.
 *
 * Cloud never talks around HomeServer v2.4. Every Cloud→OTRO physical request
 * routes through homeserver_execution_v220_* and the canonical HTTPS relay.
 * HomeServer-backed state is never treated as current while v2.4 reconciliation
 * says continuity is incomplete.
 */
const VP3_TRACKY_JOIN_V273='vp3-tracky-join-v273-20260926';
const VP3_TRACKY_ACTIVE_PROTOCOL_V273='active_perception.v1';

function tracky_v273_reconciliation(PDO $pdo,int $userId): array
{
    if($userId<1)return ['needs_reconciliation'=>true,'last_error'=>'signed_out'];
    if(!function_exists('homeserver_reconciliation_v246_state')){
        return ['needs_reconciliation'=>true,'last_error'=>'v2.4 reconciliation authority unavailable'];
    }
    return homeserver_reconciliation_v246_state($userId);
}

function tracky_v273_homeserver_status(int $userId): array
{
    $status=function_exists('homeserver_https_v1300_status')?homeserver_https_v1300_status($userId):null;
    return is_array($status)?$status:[
        'paired'=>false,'connected'=>false,'device_id'=>'','installed_version'=>'','capabilities'=>[],
        'error'=>'HomeServer HTTPS status unavailable.',
    ];
}

function tracky_v273_capabilities(PDO $pdo,array $user,bool $force=false): array
{
    $uid=(int)($user['id']??0);
    $reconciliation=tracky_v273_reconciliation($pdo,$uid);
    $status=tracky_v273_homeserver_status($uid);
    if($uid<1||empty($status['paired'])){
        return [
            'available'=>false,'compatible'=>false,'connected'=>false,'reason'=>'homeserver_not_paired',
            'reconciliation'=>$reconciliation,'tracky'=>[],
        ];
    }
    if(empty($status['connected'])){
        return [
            'available'=>false,'compatible'=>false,'connected'=>false,'reason'=>'homeserver_offline',
            'device_id'=>(string)($status['device_id']??''),'reconciliation'=>$reconciliation,'tracky'=>[],
        ];
    }
    if(!function_exists('homeserver_execution_v220_registry')){
        return [
            'available'=>false,'compatible'=>false,'connected'=>true,'reason'=>'execution_router_unavailable',
            'device_id'=>(string)($status['device_id']??''),'reconciliation'=>$reconciliation,'tracky'=>[],
        ];
    }
    $registry=homeserver_execution_v220_registry($uid,$force);
    $caps=is_array($registry['capabilities']??null)?$registry['capabilities']:[];
    $tracky=is_array($caps['tracky_physical_context']??null)?$caps['tracky_physical_context']:[];
    $protocol=(string)($tracky['protocol']??'');
    $activeProtocol=(string)($tracky['active_perception_protocol']??'');
    $compatible=$protocol===VP3_TRACKY_PROTOCOL_V270&&$activeProtocol===VP3_TRACKY_ACTIVE_PROTOCOL_V273;
    return [
        'available'=>!empty($registry['available'])&&!empty($tracky),
        'compatible'=>$compatible,
        'connected'=>!empty($registry['connected']),
        'reason'=>$compatible?'ready':(!empty($tracky)?'protocol_incompatible':'tracky_capability_unavailable'),
        'device_id'=>(string)($status['device_id']??''),
        'homeserver_version'=>(string)($registry['version']??''),
        'tracky'=>$tracky,
        'reconciliation'=>$reconciliation,
        'can_trust_current'=>$compatible&&empty($reconciliation['needs_reconciliation']),
    ];
}

function tracky_v273_settings(PDO $pdo,int $userId): array
{
    $settings=function_exists('tracky_v272_settings')?tracky_v272_settings($pdo,$userId):[];
    return [
        'active_perception_enabled'=>!empty($settings['active_perception_enabled']),
        'auto_refresh_stale'=>!empty($settings['auto_refresh_stale']),
    ];
}

function tracky_v273_query_requests_refresh(string $query): bool
{
    return (bool)preg_match(
        '/\b(?:check|verify|look again|check again|scan|rescan|re-scan|refresh|right now|now please|take another look|look for|see if)\b/i',
        $query
    );
}

function tracky_v273_query_request_type(string $query,string $intent): string
{
    $q=mb_strtolower($query);
    if($intent==='where'&&preg_match('/\b(?:find|look for|where)\b/u',$q))return 'find_entity';
    if($intent==='present'&&preg_match('/\b(?:room|office|kitchen|garage|bedroom|living room)\b/u',$q))return 'check_room';
    if(in_array($intent,['confidence','why'],true))return 're_evaluate';
    return 'refresh_current_view';
}

function tracky_v273_target_from_query(PDO $pdo,array $user,string $query,string $intent): array
{
    $target=['query'=>mb_strimwidth(trim($query),0,240,'')];
    $site=tracky_agent_site_v271($pdo,(int)$user['id'],$query);
    if($site&&$intent==='present'){
        $presence=tracky_agent_present_v271($pdo,$user,$site,$query);
        $roomId=trim((string)($presence['room_id']??''));
        if($roomId!=='')$target['room_id']=$roomId;
    }
    $terms=tracky_agent_query_terms_v271($query);
    if(in_array($intent,['where','last_seen','confidence','why'],true)&&$terms){
        $target['entity_id']=mb_strimwidth((string)$terms[0],0,128,'');
    }
    return $target;
}

function tracky_v273_site_id(PDO $pdo,array $user,string $query=''): string
{
    $status=tracky_v273_homeserver_status((int)$user['id']);
    $deviceId=trim((string)($status['device_id']??''));
    if($deviceId==='')throw new RuntimeException('HomeServer device identity is unavailable.');
    $site=tracky_agent_site_v271($pdo,(int)$user['id'],$query);
    if($site&&trim((string)($site['device_id']??''))!==''&&!hash_equals($deviceId,(string)$site['device_id'])){
        throw new RuntimeException('The selected Tracky site is not the currently paired HomeServer.');
    }
    return $deviceId;
}

function tracky_v273_active_perception(
    PDO $pdo,array $user,string $query,string $intent,array $options=[]
): array {
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('Sign in to use active perception.');
    $settings=tracky_v273_settings($pdo,$uid);
    if(empty($settings['active_perception_enabled'])){
        return ['attempted'=>false,'status'=>'disabled','reason'=>'active_perception_not_enabled'];
    }

    $capability=tracky_v273_capabilities($pdo,$user,true);
    if(empty($capability['connected'])){
        return ['attempted'=>true,'status'=>'unable','reason'=>'homeserver_offline','capability'=>$capability];
    }
    if(empty($capability['compatible'])){
        return ['attempted'=>true,'status'=>'unable','reason'=>'protocol_incompatible','capability'=>$capability];
    }
    if(!empty($capability['reconciliation']['needs_reconciliation'])){
        return [
            'attempted'=>true,'status'=>'unable','reason'=>'homeserver_reconciliation_pending',
            'capability'=>$capability,
        ];
    }
    $tracky=is_array($capability['tracky']??null)?$capability['tracky']:[];
    $active=is_array($tracky['active_perception']??null)?$tracky['active_perception']:[];
    $provider=is_array($tracky['provider']??null)?$tracky['provider']:[];
    if(empty($active['supported'])||empty($provider['available'])){
        return ['attempted'=>true,'status'=>'unable','reason'=>'provider_unavailable','capability'=>$capability];
    }
    if(!function_exists('homeserver_execution_v220_can_route')
        ||!homeserver_execution_v220_can_route($uid,'physical_context.active_perception')){
        return ['attempted'=>true,'status'=>'unable','reason'=>'active_perception_route_unavailable','capability'=>$capability];
    }

    $requestId=function_exists('homeserver_https_v1300_uuid')
        ?homeserver_https_v1300_uuid()
        :'tracky-'.bin2hex(random_bytes(16));
    $correlationId=trim((string)($options['correlation_id']??''));
    if($correlationId==='')$correlationId='tracky-'.bin2hex(random_bytes(16));
    $payload=[
        'request_type'=>tracky_v273_query_request_type($query,$intent),
        'request_id'=>$requestId,
        'correlation_id'=>$correlationId,
        'site_id'=>tracky_v273_site_id($pdo,$user,$query),
        'target'=>tracky_v273_target_from_query($pdo,$user,$query,$intent),
        'reason'=>mb_strimwidth('Agent requested fresh physical verification for: '.trim($query),0,500,''),
    ];
    try{
        $response=homeserver_execution_v220_execute($uid,'physical_context.active_perception',$payload);
    }catch(Throwable $e){
        return [
            'attempted'=>true,'status'=>'failed','reason'=>'homeserver_request_failed',
            'request_id'=>$requestId,'correlation_id'=>$correlationId,
            'error'=>mb_strimwidth($e->getMessage(),0,300,''),
        ];
    }
    $request=is_array($response['request']??null)?$response['request']:[];
    $status=(string)($request['status']??'failed');
    $result=is_array($request['result']??null)?$request['result']:[];
    $cloudIngest=null;$cloudSyncError='';
    $projection=is_array($result['semantic_projection']??null)?$result['semantic_projection']:[];
    if($status==='completed'&&$projection){
        try{
            $deviceId=(string)($capability['device_id']??'');
            $cloudIngest=tracky_cloud_v270_ingest($pdo,$uid,$deviceId,$projection);
        }catch(Throwable $e){
            $cloudSyncError=mb_strimwidth($e->getMessage(),0,300,'');
        }
    }
    return [
        'attempted'=>true,
        'status'=>$status,
        'reason'=>(string)($result['reason']??($request['error']??'')),
        'request_id'=>(string)($request['request_id']??$requestId),
        'correlation_id'=>(string)($request['correlation_id']??$correlationId),
        'request_type'=>(string)($request['request_type']??$payload['request_type']),
        'cloud_projection_ingested'=>is_array($cloudIngest)&&!empty($cloudIngest['ok']),
        'cloud_ingest'=>$cloudIngest,
        'cloud_sync_error'=>$cloudSyncError,
        'fresh_context'=>is_array($result['current_context']??null)?$result['current_context']:[],
        'raw_perception_exposed'=>false,
    ];
}

function tracky_v273_maybe_refresh(PDO $pdo,array $user,string $query,string $intent,array $currentData=[]): array
{
    $settings=tracky_v273_settings($pdo,(int)$user['id']);
    if(empty($settings['active_perception_enabled']))return ['attempted'=>false,'status'=>'disabled'];
    $explicit=tracky_v273_query_requests_refresh($query);
    $stale=false;
    if(($currentData['kind']??'')==='current'){
        $stale=(string)($currentData['freshness']['state']??'stale')==='stale';
    }elseif(($currentData['kind']??'')==='current_room'){
        $stale=(string)($currentData['freshness']['state']??'stale')==='stale';
    }elseif(isset($currentData['matches'][0]['freshness'])){
        $stale=(string)($currentData['matches'][0]['freshness']['state']??'stale')==='stale';
    }
    if(!$explicit&&!( !empty($settings['auto_refresh_stale'])&&$stale)){
        return ['attempted'=>false,'status'=>'not_needed','stale'=>$stale];
    }
    return tracky_v273_active_perception($pdo,$user,$query,$intent);
}

function tracky_v273_refresh_note(array $refresh): string
{
    if(empty($refresh['attempted']))return '';
    $status=(string)($refresh['status']??'');
    $reason=(string)($refresh['reason']??'');
    if($status==='completed'){
        if(!empty($refresh['cloud_sync_error'])){
            return ' I asked the HomeServer to check again. It completed locally, but Cloud could not accept the fresh semantic projection, so I am not presenting the older Cloud state as freshly verified.';
        }
        if(!empty($refresh['cloud_projection_ingested'])){
            return ' I asked the HomeServer to check again and the fresh governed physical context was synchronized.';
        }
        return ' I asked the HomeServer to check again. It completed, but it did not return a new semantic projection, so I am keeping the existing state labeled by its original freshness.';
    }
    if($reason==='homeserver_reconciliation_pending'){
        return ' I did not request a new camera check because HomeServer v2.4 continuity reconciliation is still running; HomeServer-backed context remains stale until that authority gate clears.';
    }
    if($reason==='provider_unavailable'){
        return ' The HomeServer supports the physical-context contract, but no local Tracky perception provider is attached yet, so I cannot honestly perform a fresh visual check.';
    }
    if($reason==='homeserver_offline'){
        return ' The HomeServer is offline, so I can only use the last synchronized physical state.';
    }
    if($status==='denied'||$reason==='privacy_engaged'){
        return ' The fresh physical check was denied by the HomeServer privacy boundary.';
    }
    if($status==='unable'){
        return ' The HomeServer could not perform a fresh physical check right now.';
    }
    if($status==='failed'){
        return ' The HomeServer physical check failed, so I am keeping the result labeled as last-known rather than freshly verified.';
    }
    return '';
}
