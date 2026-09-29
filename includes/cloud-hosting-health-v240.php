<?php
declare(strict_types=1);

/**
 * Cloud Hosting V2 Section 4 — HomeServer automated health/recovery projection.
 *
 * HomeServer remains authoritative for runtime health checks and recovery
 * execution. Cloud exposes observed health, incident history and bounded
 * policy controls without duplicating the recovery engine.
 */

const VP3_CLOUD_HOSTING_HEALTH_V240='cloud-hosting-health-v240';

function vp3_cloud_hosting_health_v240_summary(
    array $site,
    ?callable $remote=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $siteId=(int)($site['id']??0);$userId=(int)($site['user_id']??0);
    if($siteId<1||$userId<1)throw new RuntimeException('A valid hosted site is required.');
    $fresh=vp3_cloud_hosting_site_v100($siteId,$userId,$pdo);
    if($fresh===null)throw new RuntimeException('Hosted site could not be loaded.');
    $fresh=vp3_cloud_hosting_v120_ensure_homeserver_binding($fresh,$pdo);
    $raw=vp3_cloud_hosting_v120_remote($userId,'hosting.health.status',[
        'cloud_site_id'=>(string)$fresh['site_key'],
    ],$remote);

    $policy=(array)($raw['policy']??[]);
    $incident=(array)($raw['incident']??[]);
    $history=[];
    foreach(array_slice((array)($raw['history']??[]),0,30) as $row){
        if(!is_array($row))continue;
        $details=(array)($row['details']??[]);
        $history[]=[
            'event_type'=>mb_substr((string)($row['event_type']??''),0,120),
            'state'=>mb_substr((string)($row['state']??''),0,40),
            'action'=>mb_substr((string)($details['action']??''),0,80),
            'status'=>mb_substr((string)($details['status']??''),0,40),
            'incident_id'=>mb_substr((string)($details['incident_id']??''),0,80),
            'created_at'=>mb_substr((string)($row['created_at']??''),0,80),
        ];
    }

    return [
        'contract'=>'vp3.cloud-hosting-health.v1',
        'site_id'=>$siteId,
        'site_key'=>(string)$fresh['site_key'],
        'health_state'=>mb_substr((string)($raw['health_state']??'unknown'),0,40),
        'policy'=>[
            'enabled'=>!empty($policy['enabled']),
            'interval_seconds'=>max(30,min(3600,(int)($policy['interval_seconds']??60))),
            'failure_threshold'=>max(1,min(10,(int)($policy['failure_threshold']??3))),
            'max_recovery_attempts'=>max(1,min(5,(int)($policy['max_recovery_attempts']??2))),
            'cooldown_seconds'=>max(30,min(3600,(int)($policy['cooldown_seconds']??300))),
            'auto_reactivate'=>!empty($policy['auto_reactivate']),
            'auto_rollback'=>!empty($policy['auto_rollback']),
            'auto_restore'=>!empty($policy['auto_restore']),
        ],
        'incident'=>$incident?[
            'event_type'=>mb_substr((string)($incident['event_type']??''),0,120),
            'state'=>mb_substr((string)($incident['state']??''),0,40),
            'created_at'=>mb_substr((string)($incident['created_at']??''),0,80),
            'details'=>is_array($incident['details']??null)?$incident['details']:[],
        ]:null,
        'history'=>$history,
        'homeserver_authoritative'=>true,
    ];
}

function vp3_cloud_hosting_health_v240_update_policy(
    array $site,
    array $policy,
    ?callable $remote=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $siteId=(int)($site['id']??0);$userId=(int)($site['user_id']??0);
    if($siteId<1||$userId<1)throw new RuntimeException('A valid hosted site is required.');
    $fresh=vp3_cloud_hosting_site_v100($siteId,$userId,$pdo);
    if($fresh===null)throw new RuntimeException('Hosted site could not be loaded.');
    $fresh=vp3_cloud_hosting_v120_ensure_homeserver_binding($fresh,$pdo);

    $bounded=[
        'enabled'=>!empty($policy['enabled']),
        'interval_seconds'=>max(30,min(3600,(int)($policy['interval_seconds']??60))),
        'failure_threshold'=>max(1,min(10,(int)($policy['failure_threshold']??3))),
        'max_recovery_attempts'=>max(1,min(5,(int)($policy['max_recovery_attempts']??2))),
        'cooldown_seconds'=>max(30,min(3600,(int)($policy['cooldown_seconds']??300))),
        'auto_reactivate'=>!empty($policy['auto_reactivate']),
        'auto_rollback'=>!empty($policy['auto_rollback']),
        'auto_restore'=>!empty($policy['auto_restore']),
    ];
    return vp3_cloud_hosting_v120_remote($userId,'hosting.health.policy.update',[
        'cloud_site_id'=>(string)$fresh['site_key'],
        'policy'=>$bounded,
    ],$remote);
}

function vp3_cloud_hosting_health_v240_check(
    array $site,
    bool $executeRecovery=false,
    ?callable $remote=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $siteId=(int)($site['id']??0);$userId=(int)($site['user_id']??0);
    if($siteId<1||$userId<1)throw new RuntimeException('A valid hosted site is required.');
    $fresh=vp3_cloud_hosting_site_v100($siteId,$userId,$pdo);
    if($fresh===null)throw new RuntimeException('Hosted site could not be loaded.');
    $fresh=vp3_cloud_hosting_v120_ensure_homeserver_binding($fresh,$pdo);
    return vp3_cloud_hosting_v120_remote($userId,'hosting.health.check',[
        'cloud_site_id'=>(string)$fresh['site_key'],
        'execute_recovery'=>$executeRecovery,
    ],$remote);
}

function vp3_cloud_hosting_health_v240_capability(): array
{
    return [
        'contract'=>'vp3.cloud-hosting-health.v1',
        'health_status'=>true,
        'incident_history'=>true,
        'policy_controls'=>true,
        'manual_check'=>true,
        'runtime_recovery_authority'=>'homeserver',
        'desired_state_authority'=>'cloud',
        'automatic_restore_default'=>false,
    ];
}
