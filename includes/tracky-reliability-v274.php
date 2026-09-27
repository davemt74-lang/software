<?php
declare(strict_types=1);

/**
 * Tracky V2.74 — end-to-end reliability hardening for the V2.73 join.
 *
 * This layer adds response identity verification and reliability projection.
 * It creates no new queue, reconciliation store, or physical-action authority.
 */
const VP3_TRACKY_RELIABILITY_V274='vp3-tracky-reliability-v274-20260926';

function tracky_v274_projection_max_sequence(array $projection): int
{
    $max=max(0,(int)($projection['context_sequence']??0));
    foreach((array)($projection['events']??[]) as $event){
        if(is_array($event))$max=max($max,(int)($event['sequence']??0));
    }
    foreach((array)($projection['world_state']??[]) as $relation){
        if(is_array($relation))$max=max($max,(int)($relation['sequence']??0));
    }
    return $max;
}

function tracky_v274_validate_active_response(
    array $capability,array $response,string $expectedRequestId,string $expectedCorrelationId,string $expectedSiteId
): array {
    $request=is_array($response['request']??null)?$response['request']:[];
    if(!$request)throw new RuntimeException('HomeServer active-perception response is missing its request envelope.');

    $requestId=trim((string)($request['request_id']??''));
    $correlationId=trim((string)($request['correlation_id']??''));
    $siteId=trim((string)($request['site_id']??''));
    if($requestId===''||!hash_equals($expectedRequestId,$requestId)){
        throw new RuntimeException('HomeServer active-perception request identity does not match the Cloud request.');
    }
    if($correlationId===''||!hash_equals($expectedCorrelationId,$correlationId)){
        throw new RuntimeException('HomeServer active-perception correlation identity does not match the Cloud request.');
    }
    if($siteId===''||!hash_equals($expectedSiteId,$siteId)){
        throw new RuntimeException('HomeServer active-perception site identity does not match the routed HomeServer.');
    }

    $status=strtolower(trim((string)($request['status']??'')));
    $allowed=['requested','accepted','observing','completed','unable','denied','failed','superseded'];
    if(!in_array($status,$allowed,true))throw new RuntimeException('HomeServer active-perception status is invalid.');

    $result=is_array($request['result']??null)?$request['result']:[];
    $projection=is_array($result['semantic_projection']??null)?$result['semantic_projection']:[];
    if($status==='completed'&&$projection){
        $protocol=trim((string)($projection['protocol']??''));
        if($protocol!==VP3_TRACKY_PROTOCOL_V270){
            throw new RuntimeException('HomeServer returned an incompatible physical-context projection.');
        }
        $site=is_array($projection['site']??null)?$projection['site']:[];
        $projectionSite=trim((string)($site['id']??''));
        if($projectionSite===''||!hash_equals($expectedSiteId,$projectionSite)){
            throw new RuntimeException('HomeServer returned a physical projection for the wrong site.');
        }
    }

    $capDevice=trim((string)($capability['device_id']??''));
    if($capDevice===''||!hash_equals($expectedSiteId,$capDevice)){
        throw new RuntimeException('Cloud capability identity changed during active perception.');
    }

    return [
        'request'=>$request,
        'status'=>$status,
        'result'=>$result,
        'projection'=>$projection,
        'request_id'=>$requestId,
        'correlation_id'=>$correlationId,
        'site_id'=>$siteId,
    ];
}

function tracky_v274_ingest_active_projection(
    PDO $pdo,int $userId,string $deviceId,array $validated
): array {
    $projection=is_array($validated['projection']??null)?$validated['projection']:[];
    if(!$projection)return ['ok'=>false,'reason'=>'no_projection'];
    $expectedSequence=tracky_v274_projection_max_sequence($projection);
    $ingest=tracky_cloud_v270_ingest($pdo,$userId,$deviceId,$projection);
    if(empty($ingest['ok']))throw new RuntimeException('Cloud did not accept the fresh physical projection.');
    if((int)($ingest['last_sequence']??0)<$expectedSequence){
        throw new RuntimeException('Cloud physical projection verification did not reach the HomeServer sequence.');
    }
    $siteId=trim((string)($ingest['site_id']??''));
    if($siteId===''||!hash_equals($deviceId,$siteId)){
        throw new RuntimeException('Cloud physical projection resolved to an unexpected site.');
    }
    return $ingest+[
        'verified_sequence'=>$expectedSequence,
        'verified_site_id'=>$siteId,
    ];
}

function tracky_v274_classify(array $capability,array $cloudSite=[],array $freshness=[]): array
{
    $reason=(string)($capability['reason']??'unknown');
    if(empty($capability['connected'])){
        return ['state'=>'offline','reason'=>$reason?:'homeserver_offline','fresh_verification'=>false];
    }
    if(empty($capability['compatible'])){
        return ['state'=>'incompatible','reason'=>'protocol_incompatible','fresh_verification'=>false];
    }
    if(!empty($capability['reconciliation']['needs_reconciliation'])){
        return ['state'=>'reconciling','reason'=>'homeserver_reconciliation_pending','fresh_verification'=>false];
    }
    $tracky=is_array($capability['tracky']??null)?$capability['tracky']:[];
    $provider=is_array($tracky['provider']??null)?$tracky['provider']:[];
    if(empty($provider['available'])){
        return ['state'=>'degraded','reason'=>'provider_unavailable','fresh_verification'=>false];
    }
    $reliability=is_array($tracky['reliability']??null)?$tracky['reliability']:[];
    $localState=strtolower(trim((string)($reliability['state']??'healthy')));
    if($localState==='critical'){
        return ['state'=>'critical','reason'=>'homeserver_tracky_backlog_critical','fresh_verification'=>true];
    }
    if($localState==='degraded'){
        return ['state'=>'degraded','reason'=>'homeserver_tracky_degraded','fresh_verification'=>true];
    }
    if($localState==='recovering'){
        return ['state'=>'recovering','reason'=>'homeserver_tracky_recovering','fresh_verification'=>true];
    }
    $cloudStatus=strtolower(trim((string)($cloudSite['status']??'healthy')));
    if(in_array($cloudStatus,['offline','failed','disabled'],true)){
        return ['state'=>'degraded','reason'=>'cloud_projection_'.$cloudStatus,'fresh_verification'=>true];
    }
    if((string)($freshness['state']??'current')==='stale'){
        return ['state'=>'stale','reason'=>'cloud_projection_stale','fresh_verification'=>true];
    }
    return ['state'=>'healthy','reason'=>'ready','fresh_verification'=>true];
}

function tracky_v274_reliability_state(PDO $pdo,array $user,string $query=''): array
{
    $uid=(int)($user['id']??0);
    $capability=tracky_v273_capabilities($pdo,$user,false);
    $site=tracky_agent_site_v271($pdo,$uid,$query);
    $freshness=['state'=>'stale','live'=>false];
    if($site){
        $freshness=tracky_agent_freshness_v271(
            (string)($site['last_seen_at']??$site['last_event_at']??''),
            $site
        );
    }
    $classification=tracky_v274_classify($capability,is_array($site)?$site:[],$freshness);
    return [
        'contract'=>VP3_TRACKY_RELIABILITY_V274,
        'state'=>$classification['state'],
        'reason'=>$classification['reason'],
        'fresh_verification'=>$classification['fresh_verification'],
        'capability'=>$capability,
        'cloud_site'=>$site?:null,
        'freshness'=>$freshness,
        'v24_reconciliation_authoritative'=>true,
        'raw_perception_exposed'=>false,
    ];
}

function tracky_v274_failure_note(string $reason): string
{
    return match($reason){
        'provider_timeout'=>' The HomeServer perception provider exceeded its deadline, so I kept the prior physical state rather than calling it fresh.',
        'invalid_homeserver_response'=>' The HomeServer response failed the physical-context identity checks, so I rejected it and kept the prior state.',
        'cloud_projection_verification_failed'=>' The fresh HomeServer result could not be verified in Cloud, so the prior state remains authoritative.',
        default=>'',
    };
}
