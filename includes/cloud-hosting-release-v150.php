<?php
declare(strict_types=1);

/**
 * Cloud Hosting V1 Section 6 — end-to-end certification and release readiness.
 *
 * Read-only release metadata only. No deployment, DNS, billing or runtime
 * authority is introduced here.
 */

const VP3_CLOUD_HOSTING_RELEASE_V150='cloud-hosting-v150';

function vp3_cloud_hosting_v150_versions(): array
{
    return [
        'control_plane'=>VP3_CLOUD_HOSTING_V100,
        'dns_routing'=>VP3_CLOUD_HOSTING_V110,
        'homeserver_sync'=>VP3_CLOUD_HOSTING_V120,
        'agent_tools'=>VP3_CLOUD_HOSTING_AGENT_V130,
        'member_ui'=>VP3_CLOUD_HOSTING_UI_V140,
        'release'=>VP3_CLOUD_HOSTING_RELEASE_V150,
    ];
}

function vp3_cloud_hosting_v150_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo
        && vp3_cloud_hosting_schema_ready_v100()
        && vp3_cloud_hosting_v110_schema_ready()
        && vp3_cloud_hosting_v120_schema_ready($pdo)
        && vp3_cloud_hosting_agent_v130_schema_ready($pdo);
}

function vp3_cloud_hosting_v150_readiness(array $user,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('A signed-in user is required.');

    $schemaReady=vp3_cloud_hosting_v150_schema_ready($pdo);
    $cpanel=vp3_cloud_hosting_cpanel_config_v100();
    $zone='';
    $ingress='';
    try{$zone=vp3_cloud_hosting_v110_zone_domain();}catch(Throwable $e){}
    try{$ingress=vp3_cloud_hosting_v110_ingress_hostname();}catch(Throwable $e){}
    $providerReady=!empty($cpanel['configured'])&&$zone!==''&&$ingress!=='';

    $entitlements=vp3_cloud_hosting_ui_v140_entitlements($user);
    $connection=function_exists('homeserver_vp3_connection')?homeserver_vp3_connection($uid):null;
    $homeStatus=(string)($connection['status']??'unpaired');
    $homeReady=in_array($homeStatus,['connected','paired','online'],true);

    $sites=vp3_cloud_hosting_sites_v100($uid,$pdo);
    $drift=0;$failedDeployments=0;$routeIssues=0;
    foreach($sites as $site){
        if((string)$site['desired_state']!==(string)$site['observed_state'])$drift++;
        $deployment=vp3_cloud_hosting_ui_v140_latest_deployment((int)$site['id'],$pdo);
        if($deployment&&in_array((string)$deployment['state'],['failed','interrupted'],true))$failedDeployments++;
        $route=vp3_cloud_hosting_v110_route_for_site((int)$site['id'],$pdo);
        if($route&&((string)$route['dns_state']==='failed'||(string)$route['tls_state']==='failed'))$routeIssues++;
    }

    return [
        'contract'=>'vp3.cloud-hosting-release.v1',
        'versions'=>vp3_cloud_hosting_v150_versions(),
        'platform'=>[
            'schema_ready'=>$schemaReady,
            'cpanel_provider_ready'=>$providerReady,
            'dns_zone_configured'=>$zone!=='',
            'public_ingress_configured'=>$ingress!=='',
            'release_ready'=>$schemaReady&&$providerReady,
        ],
        'account'=>[
            'hosting_entitled'=>!empty($entitlements['access']),
            'homeserver_status'=>$homeStatus,
            'homeserver_ready'=>$homeReady,
            'site_count'=>count($sites),
            'drifted_sites'=>$drift,
            'failed_deployments'=>$failedDeployments,
            'route_issues'=>$routeIssues,
            'ready'=>!empty($entitlements['access'])&&$homeReady,
        ],
        'capabilities'=>vp3_cloud_hosting_v150_public_capability(),
    ];
}

function vp3_cloud_hosting_v150_public_capability(): array
{
    return [
        'contract'=>'vp3.cloud-hosting-release.v1',
        'control_plane'=>true,
        'package_entitlements'=>true,
        'cpanel_dns'=>true,
        'cloud_edge_tls'=>true,
        'homeserver_reconciliation'=>true,
        'chunked_deployment'=>true,
        'deployment_resume'=>true,
        'rollback'=>true,
        'agent_chat_controls'=>true,
        'member_hosting_ui'=>true,
        'sqlite_runtime_governed_by_homeserver'=>true,
        'recovery_governed_by_homeserver'=>true,
        'server_confirmed_consequential_ui_actions'=>true,
        'agent_confirmed_consequential_actions'=>true,
        'secret_redaction'=>true,
        'non_destructive_package_downgrade'=>true,
        'cloud_is_desired_state_authority'=>true,
        'homeserver_is_execution_authority'=>true,
        'parallel_hosting_engine'=>false,
        'raw_cpanel_secret_exposed'=>false,
        'route_token_exposed'=>false,
        'cloud_edge_private_key_exposed'=>false,
    ];
}
