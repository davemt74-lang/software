<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_CONNECTED_SITES_V204='profile-webmcp-connected-sites-v204-20260929';
const VP3_PROFILE_WEBMCP_CONNECTED_SITES_CONTRACT_V204='vp3.profile.webmcp.connected-sites.v1';
const VP3_PROFILE_WEBMCP_CONNECTED_SITE_STALE_SECONDS_V204=86400;

function vp3_profile_webmcp_connected_status_v204(
    bool $active,bool $verified,string $observedBuild,string $expectedBuild,int $lastContactUnix,int $now
): string {
    if(!$active)return 'paused';
    if(!$verified)return 'awaiting_verification';
    if($observedBuild!==''&&$expectedBuild!==''&&!hash_equals($expectedBuild,$observedBuild))return 'outdated';
    if($lastContactUnix<1)return 'awaiting_webmcp';
    if(max(0,$now-$lastContactUnix)>VP3_PROFILE_WEBMCP_CONNECTED_SITE_STALE_SECONDS_V204)return 'stale';
    return 'healthy';
}

function vp3_profile_webmcp_connected_runtime_observation_v204(PDO $pdo,int $ownerUserId,int $propertyId): array
{
    $empty=[
        'runtime_build'=>'','release_version'=>'','manifest_version'=>'','negotiation_mode'=>'',
        'last_contact_at'=>'','last_success_at'=>'','last_failure_at'=>'',
    ];
    if($ownerUserId<1||$propertyId<1||!function_exists('vp3_radar_schema_ready')||!vp3_radar_schema_ready($pdo))return $empty;
    try{
        $stmt=$pdo->prepare("SELECT event_type,details_json,occurred_at
          FROM vp3_radar_events
          WHERE owner_user_id=? AND property_id=? AND event_type LIKE 'webmcp\\_%'
          ORDER BY occurred_at DESC,id DESC LIMIT 80");
        $stmt->execute([$ownerUserId,$propertyId]);
        $rows=$stmt->fetchAll()?:[];
    }catch(Throwable $e){return $empty;}
    $out=$empty;
    foreach($rows as $row){
        $details=json_decode((string)($row['details_json']??''),true);if(!is_array($details))$details=[];
        if($out['last_contact_at']==='')$out['last_contact_at']=(string)($row['occurred_at']??'');
        if($out['runtime_build']===''&&!empty($details['client_runtime_build']))$out['runtime_build']=(string)$details['client_runtime_build'];
        if($out['release_version']===''&&!empty($details['client_release_version']))$out['release_version']=(string)$details['client_release_version'];
        if($out['manifest_version']===''&&!empty($details['client_manifest_version']))$out['manifest_version']=(string)$details['client_manifest_version'];
        if($out['negotiation_mode']===''&&!empty($details['negotiation_mode']))$out['negotiation_mode']=(string)$details['negotiation_mode'];
        $event=(string)($row['event_type']??'');
        if($out['last_success_at']===''&&$event==='webmcp_tool_completed')$out['last_success_at']=(string)($row['occurred_at']??'');
        if($out['last_failure_at']===''&&in_array($event,['webmcp_tool_failed','webmcp_tool_denied'],true))$out['last_failure_at']=(string)($row['occurred_at']??'');
    }
    return $out;
}

function vp3_profile_webmcp_connected_sites_enrich_v204(PDO $pdo,array $user,array $state): array
{
    $owner=(int)($user['id']??0);
    if($owner<1||empty($state['sites'])||!is_array($state['sites']))return $state;
    $expectedBuild=defined('VP3_PROFILE_WEBMCP_EXTERNAL_RUNTIME_V200')?VP3_PROFILE_WEBMCP_EXTERNAL_RUNTIME_V200:'profile-webmcp-external-v120-20260928';
    $expectedRelease=defined('VP3_PROFILE_WEBMCP_RELEASE_V196')?VP3_PROFILE_WEBMCP_RELEASE_V196:'profile-webmcp-release-v196-20260929';
    $contract=defined('VP3_PROFILE_WEBMCP_NEGOTIATION_CONTRACT_V200')?VP3_PROFILE_WEBMCP_NEGOTIATION_CONTRACT_V200:'vp3.profile.webmcp.negotiation.v1';
    $now=time();
    foreach($state['sites'] as &$site){
        $propertyId=(int)($site['id']??0);
        $observation=vp3_profile_webmcp_connected_runtime_observation_v204($pdo,$owner,$propertyId);
        $lastUnix=$observation['last_contact_at']!==''?(strtotime($observation['last_contact_at'])?:0):0;
        $active=!empty($site['is_active']);$verified=!empty($site['verified_at']);
        $status=vp3_profile_webmcp_connected_status_v204(
            $active,$verified,(string)$observation['runtime_build'],$expectedBuild,$lastUnix,$now
        );
        $site['webmcp_management_contract']=VP3_PROFILE_WEBMCP_CONNECTED_SITES_CONTRACT_V204;
        $site['webmcp_runtime_expected']=$expectedBuild;
        $site['webmcp_release_expected']=$expectedRelease;
        $site['webmcp_negotiation_contract']=$contract;
        $site['webmcp_runtime_observed']=(string)$observation['runtime_build'];
        $site['webmcp_release_observed']=(string)$observation['release_version'];
        $site['webmcp_manifest_observed']=(string)$observation['manifest_version'];
        $site['webmcp_negotiation_mode']=(string)$observation['negotiation_mode'];
        $site['webmcp_last_contact_at']=(string)$observation['last_contact_at'];
        $site['webmcp_last_success_at']=(string)$observation['last_success_at'];
        $site['webmcp_last_failure_at']=(string)$observation['last_failure_at'];
        $site['webmcp_runtime_status']=$status;
        $site['webmcp_stale']=$status==='stale';
        $site['webmcp_upgrade_required']=$status==='outdated';
        $site['webmcp_origin_policy']='registered_domain_enforced';
        $site['webmcp_verification_state']=$verified?'verified':'awaiting_signal';
    }
    unset($site);
    return $state;
}

function vp3_profile_webmcp_connected_site_owned_v204(PDO $pdo,int $ownerUserId,int $propertyId): array
{
    if($ownerUserId<1||$propertyId<1)throw new RuntimeException('Connected site not found.');
    $stmt=$pdo->prepare("SELECT * FROM vp3_radar_properties WHERE id=? AND owner_user_id=? AND property_type='external' LIMIT 1");
    $stmt->execute([$propertyId,$ownerUserId]);
    $site=$stmt->fetch();
    if(!$site)throw new RuntimeException('Connected site not found.');
    return $site;
}

function vp3_profile_webmcp_connected_site_reverify_v204(PDO $pdo,array $user,int $propertyId): array
{
    $owner=(int)($user['id']??0);
    vp3_profile_webmcp_connected_site_owned_v204($pdo,$owner,$propertyId);
    $stmt=$pdo->prepare("UPDATE vp3_radar_properties SET verified_at=NULL,updated_at=NOW() WHERE id=? AND owner_user_id=? AND property_type='external'");
    $stmt->execute([$propertyId,$owner]);
    return vp3_radar_external_site_state($pdo,$user);
}

function vp3_profile_webmcp_connected_site_reconnect_v204(PDO $pdo,array $user,int $propertyId): array
{
    $owner=(int)($user['id']??0);
    vp3_profile_webmcp_connected_site_owned_v204($pdo,$owner,$propertyId);
    $publicKey=bin2hex(random_bytes(20));
    $verificationToken=bin2hex(random_bytes(32));
    $stmt=$pdo->prepare("UPDATE vp3_radar_properties
      SET public_key=?,verification_token=?,verified_at=NULL,is_active=1,updated_at=NOW()
      WHERE id=? AND owner_user_id=? AND property_type='external'");
    $stmt->execute([$publicKey,$verificationToken,$propertyId,$owner]);
    return vp3_radar_external_site_state($pdo,$user);
}
