<?php
declare(strict_types=1);

/**
 * Cloud Hosting V1 Section 5 — user-facing Hosting control surface helpers.
 *
 * This file is deliberately thin: every mutation delegates to the canonical
 * Hosting services from Sections 1–4.
 */

const VP3_CLOUD_HOSTING_UI_V140='cloud-hosting-ui-v140';

function vp3_cloud_hosting_ui_v140_request_key(string $prefix='hosting'): string
{
    $prefix=preg_replace('/[^a-z0-9._-]+/i','-',trim($prefix))??'hosting';
    $prefix=trim($prefix,'-');
    if($prefix==='')$prefix='hosting';
    return mb_substr($prefix,0,40).'-'.bin2hex(random_bytes(16));
}

function vp3_cloud_hosting_ui_v140_owner_site(array $user,int $siteId,?PDO $pdo=null): array
{
    $pdo??=db();
    $uid=(int)($user['id']??0);
    if(!$pdo||$uid<1||$siteId<1)throw new RuntimeException('A valid hosted site is required.');
    $site=vp3_cloud_hosting_site_v100($siteId,$uid,$pdo);
    if($site===null)throw new RuntimeException('Hosted site was not found for this account.');
    return $site;
}

function vp3_cloud_hosting_ui_v140_entitlements(array $user): array
{
    $snapshot=vp3_cloud_hosting_entitlement_snapshot_v100($user);
    $count=static function(array $state): ?int {
        if(empty($state['enabled']))return 0;
        if(!empty($state['unlimited']))return null;
        return max(0,(int)($state['limit']??0));
    };
    return [
        'access'=>!empty($snapshot['entitlements']['hosting.access']['enabled']),
        'sites'=>$count((array)($snapshot['entitlements']['hosting.sites']??[])),
        'subdomains'=>$count((array)($snapshot['entitlements']['hosting.subdomains']??[])),
        'storage_mb_per_site'=>$count((array)($snapshot['entitlements']['hosting.storage_mb_per_site']??[])),
        'sqlite_mb_per_site'=>$count((array)($snapshot['entitlements']['hosting.sqlite_mb_per_site']??[])),
        'php'=>!empty($snapshot['entitlements']['hosting.php_access']['enabled']),
    ];
}

function vp3_cloud_hosting_ui_v140_latest_deployment(int $siteId,?PDO $pdo=null): ?array
{
    $pdo??=db();
    if(!$pdo||$siteId<1||!table_exists('cloud_hosting_deployments'))return null;
    $stmt=$pdo->prepare("SELECT id,request_key,operation,desired_revision,package_bytes,state,release_id,error_code,error_message,created_at,completed_at,updated_at
      FROM cloud_hosting_deployments WHERE site_id=? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$siteId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function vp3_cloud_hosting_ui_v140_sync(int $siteId,?PDO $pdo=null): ?array
{
    $pdo??=db();
    if(!$pdo||$siteId<1||!table_exists('cloud_hosting_site_sync'))return null;
    $stmt=$pdo->prepare("SELECT last_sent_revision,remote_revision,remote_site_id,observed_state,active_release_id,public_route_ready,last_synced_at,last_error_code,last_error_message,updated_at
      FROM cloud_hosting_site_sync WHERE site_id=? LIMIT 1");
    $stmt->execute([$siteId]);
    $row=$stmt->fetch();
    return is_array($row)?$row:null;
}

function vp3_cloud_hosting_ui_v140_site_card(array $site,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $siteId=(int)$site['id'];
    $route=vp3_cloud_hosting_v110_route_for_site($siteId,$pdo);
    $cert=vp3_cloud_hosting_v120_edge_certificate($siteId,$pdo);
    $sync=vp3_cloud_hosting_ui_v140_sync($siteId,$pdo);
    $deployment=vp3_cloud_hosting_ui_v140_latest_deployment($siteId,$pdo);
    $issues=[];
    if((string)$site['last_error_message']!=='')$issues[]=mb_substr((string)$site['last_error_message'],0,240);
    if($sync&&($sync['last_error_message']??'')!=='')$issues[]=mb_substr((string)$sync['last_error_message'],0,240);
    if($route&&in_array((string)$route['dns_state'],['failed'],true))$issues[]='DNS provisioning failed.';
    if($deployment&&in_array((string)$deployment['state'],['failed','interrupted'],true))$issues[]='Latest deployment is '.(string)$deployment['state'].'.';

    $hostname=(string)($site['canonical_hostname']??'');
    if($hostname==='')$hostname=(string)($site['requested_hostname']??'');
    return [
        'id'=>$siteId,
        'site_key'=>(string)$site['site_key'],
        'display_name'=>(string)$site['display_name'],
        'hostname'=>$hostname,
        'runtime_kind'=>(string)$site['runtime_kind'],
        'desired_state'=>(string)$site['desired_state'],
        'observed_state'=>(string)$site['observed_state'],
        'desired_revision'=>(int)$site['desired_revision'],
        'observed_revision'=>(int)$site['observed_revision'],
        'storage_limit_bytes'=>(int)$site['storage_limit_bytes'],
        'sqlite_limit_bytes'=>(int)$site['sqlite_limit_bytes'],
        'active_release_id'=>$site['active_release_id']??null,
        'previous_release_id'=>$site['previous_release_id']??null,
        'route'=>is_array($route)?[
            'dns_state'=>(string)$route['dns_state'],
            'tls_state'=>(string)$route['tls_state'],
            'hostname'=>(string)$route['hostname'],
            'record_value'=>(string)$route['record_value'],
            'verified_at'=>$route['verified_at']??null,
        ]:null,
        'certificate'=>is_array($cert)?[
            'tls_state'=>(string)$cert['tls_state'],
            'certificate_not_after'=>$cert['certificate_not_after']??null,
        ]:null,
        'sync'=>$sync,
        'deployment'=>$deployment,
        'issues'=>array_values(array_unique($issues)),
        'public_url'=>$hostname!==''?'https://'.$hostname:null,
    ];
}

function vp3_cloud_hosting_ui_v140_dashboard(array $user,?PDO $pdo=null): array
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('A signed-in user is required.');
    $entitlements=vp3_cloud_hosting_ui_v140_entitlements($user);
    $sites=[];
    foreach(vp3_cloud_hosting_sites_v100($uid,$pdo) as $site)$sites[]=vp3_cloud_hosting_ui_v140_site_card($site,$pdo);
    return [
        'contract'=>'vp3.cloud-hosting-ui.v1',
        'entitlements'=>$entitlements,
        'site_count'=>count($sites),
        'sites'=>$sites,
        'capabilities'=>vp3_cloud_hosting_ui_v140_capability(),
    ];
}

function vp3_cloud_hosting_ui_v140_execute(
    array $user,
    string $action,
    array $input=[],
    ?string $packageBytes=null,
    ?callable $remote=null,
    ?callable $providerTransport=null,
    ?callable $resolver=null,
    ?PDO $pdo=null
): array {
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    $uid=(int)($user['id']??0);
    if($uid<1)throw new RuntimeException('A signed-in user is required.');
    $action=trim($action);
    $consequential=['site.activate','site.suspend','dns.provision','deployment.deploy','deployment.rollback'];
    if(in_array($action,$consequential,true)&&((string)($input['confirmed']??''))!=='1'){
        throw new RuntimeException('Confirm this consequential Hosting action before execution.');
    }

    if($action==='site.create'){
        $payload=[
            'display_name'=>(string)($input['display_name']??''),
            'requested_hostname'=>$input['requested_hostname']??null,
            'runtime_kind'=>(string)($input['runtime_kind']??'static'),
            '_creation_key'=>(string)($input['request_key']??vp3_cloud_hosting_ui_v140_request_key('create')),
        ];
        $site=vp3_cloud_hosting_create_site_v100($user,$payload,$uid);
        return ['action'=>$action,'site'=>vp3_cloud_hosting_ui_v140_site_card($site,$pdo)];
    }

    $siteId=(int)($input['site_id']??0);
    $site=vp3_cloud_hosting_ui_v140_owner_site($user,$siteId,$pdo);
    $requestKey=trim((string)($input['request_key']??''));
    if($requestKey==='')$requestKey=vp3_cloud_hosting_ui_v140_request_key($action);

    if($action==='site.activate'||$action==='site.suspend'){
        $state=$action==='site.activate'?'active':'suspended';
        $fresh=vp3_cloud_hosting_set_desired_state_v100($site,$state,$uid,'hosting_ui',$pdo);
        $sync=vp3_cloud_hosting_v120_reconcile_site($fresh,$remote,$pdo);
        return ['action'=>$action,'sync'=>$sync,'site'=>vp3_cloud_hosting_ui_v140_site_card(vp3_cloud_hosting_site_v100($siteId,$uid,$pdo)??$fresh,$pdo)];
    }

    if($action==='site.reconcile'){
        $sync=vp3_cloud_hosting_v120_reconcile_site($site,$remote,$pdo);
        return ['action'=>$action,'sync'=>$sync,'site'=>vp3_cloud_hosting_ui_v140_site_card(vp3_cloud_hosting_site_v100($siteId,$uid,$pdo)??$site,$pdo)];
    }

    if($action==='dns.provision'){
        $result=vp3_cloud_hosting_v110_provision_dns($site,$requestKey,$uid,$providerTransport);
        return ['action'=>$action,'result'=>$result,'site'=>vp3_cloud_hosting_ui_v140_site_card(vp3_cloud_hosting_site_v100($siteId,$uid,$pdo)??$site,$pdo)];
    }

    if($action==='dns.verify'){
        $result=vp3_cloud_hosting_v110_verify_dns($site,$uid,$resolver);
        return ['action'=>$action,'result'=>$result,'site'=>vp3_cloud_hosting_ui_v140_site_card(vp3_cloud_hosting_site_v100($siteId,$uid,$pdo)??$site,$pdo)];
    }

    if($action==='deployment.deploy'){
        if($packageBytes===null||$packageBytes==='')throw new RuntimeException('Choose a deployment ZIP package.');
        $result=vp3_cloud_hosting_v120_deploy_package($site,$packageBytes,$requestKey,$uid,$remote,$pdo);
        return ['action'=>$action,'result'=>$result,'site'=>vp3_cloud_hosting_ui_v140_site_card(vp3_cloud_hosting_site_v100($siteId,$uid,$pdo)??$site,$pdo)];
    }

    if($action==='deployment.rollback'){
        $result=vp3_cloud_hosting_v120_rollback($site,$requestKey,$uid,$remote,$pdo);
        return ['action'=>$action,'result'=>$result,'site'=>vp3_cloud_hosting_ui_v140_site_card(vp3_cloud_hosting_site_v100($siteId,$uid,$pdo)??$site,$pdo)];
    }

    throw new RuntimeException('Unsupported Cloud Hosting UI action.');
}

function vp3_cloud_hosting_ui_v140_capability(): array
{
    return [
        'contract'=>'vp3.cloud-hosting-ui.v1',
        'canonical_services_only'=>true,
        'user_scoped'=>true,
        'site_creation'=>true,
        'dns_provisioning'=>true,
        'dns_verification'=>true,
        'deployment_zip_upload'=>true,
        'deployment_max_bytes'=>VP3_CLOUD_HOSTING_MAX_PACKAGE_BYTES,
        'desired_state_control'=>true,
        'server_enforced_consequential_confirmation'=>true,
        'reconciliation'=>true,
        'rollback'=>true,
        'agent_chat_handoff'=>true,
        'raw_cpanel_secret_exposed'=>false,
        'route_token_exposed'=>false,
        'cloud_edge_private_key_exposed'=>false,
        'raw_sql_exposed'=>false,
    ];
}
