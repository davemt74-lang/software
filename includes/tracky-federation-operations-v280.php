<?php
declare(strict_types=1);

/**
 * Tracky V2.80 Section 1 — federation inventory, topology and health model.
 *
 * Cloud may render the operational picture but cannot decide destination
 * freshness or assign physical-world authority. HomeServer health observations
 * can be supplied as governed hints; absent those, peer freshness remains unknown.
 */
const VP3_TRACKY_FEDERATION_OPERATIONS_V280='vp3-tracky-federation-operations-v280-20260927';
const VP3_TRACKY_FEDERATION_OPERATIONS_PROTOCOL_V280='physical_federation_operations.v1';

function tracky_v280_operations_text(mixed $value,int $max=160): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/',' ',(string)($value??''))??''),0,max(1,$max),'');
}

function tracky_v280_operations_peer(array $peerHealth,string $siteId): array
{
    $allowed=['current','unknown','suspect','partitioned','reconciling','stale','failed'];
    foreach($peerHealth as $row){
        if(!is_array($row))continue;
        $id=strtolower(tracky_v280_operations_text($row['site_id']??$row['remote_site_id']??'',64));
        if($id!==$siteId)continue;
        $status=strtolower(tracky_v280_operations_text($row['status']??'unknown',24));
        if(!in_array($status,$allowed,true))$status='unknown';
        return [
          'site_id'=>$siteId,'status'=>$status,'fresh'=>$status==='current',
          'reconciliation_required'=>!empty($row['reconciliation_required'])||in_array($status,['unknown','partitioned','reconciling','stale','failed'],true),
          'last_error'=>tracky_v280_operations_text($row['last_error']??'',240),
        ];
    }
    return ['site_id'=>$siteId,'status'=>'unknown','fresh'=>false,'reconciliation_required'=>true,'last_error'=>''];
}

function tracky_v280_operations_authority(array $site,array $devices): array
{
    $deviceId=strtolower(tracky_v280_operations_text($site['authority_device_id']??'',64));
    $epoch=max(0,(int)($site['authority_epoch']??0));
    if($deviceId==='')return ['status'=>'missing','device_id'=>'','epoch'=>$epoch];
    $device=null;
    foreach($devices as $candidate){if(($candidate['id']??'')===$deviceId){$device=$candidate;break;}}
    if(!$device)return ['status'=>'invalid','device_id'=>$deviceId,'epoch'=>$epoch,'reason'=>'authority_device_not_in_inventory'];
    if(($device['trust_state']??'')!==''&&($device['trust_state']??'')!=='trusted'){
        return ['status'=>'invalid','device_id'=>$deviceId,'epoch'=>$epoch,'reason'=>'authority_device_not_trusted'];
    }
    $roles=is_array($device['roles']??null)?$device['roles']:[];
    $capabilities=$device['capabilities']??[];
    $eligible=in_array('site_authority',$roles,true);
    if(is_array($capabilities)){
        $eligible=$eligible||in_array('site_authority_eligible',$capabilities,true)||(($capabilities['site_authority_eligible']??false)===true);
    }
    if(!$eligible)return ['status'=>'invalid','device_id'=>$deviceId,'epoch'=>$epoch,'reason'=>'authority_device_not_eligible'];
    return ['status'=>'current','device_id'=>$deviceId,'epoch'=>$epoch];
}

function tracky_v280_operations_snapshot(array $topologyInput,array $peerHealth=[]): array
{
    $topology=is_array($topologyInput['topology']??null)?$topologyInput['topology']:$topologyInput;
    $rawDevices=is_array($topology['devices']??null)?array_values($topology['devices']):[];
    $devices=[];
    foreach($rawDevices as $raw){
        if(!is_array($raw))continue;
        $capabilities=$raw['capabilities']??[];
        if(!is_array($capabilities))$capabilities=[];
        $devices[]=[
          'id'=>strtolower(tracky_v280_operations_text($raw['id']??$raw['device_id']??'',64)),
          'label'=>tracky_v280_operations_text($raw['label']??'',160),
          'site_id'=>strtolower(tracky_v280_operations_text($raw['site_id']??'',64)),
          'hardware_profile'=>strtolower(tracky_v280_operations_text($raw['hardware_profile']??'custom',40))?:'custom',
          'hardware_profile_label'=>tracky_v280_operations_text($raw['hardware_profile_label']??$raw['hardware_profile']??'Custom',80),
          'mobility'=>strtolower(tracky_v280_operations_text($raw['mobility']??'unknown',24)),
          'trust_state'=>strtolower(tracky_v280_operations_text($raw['trust_state']??'unknown',24)),
          'roles'=>is_array($raw['roles']??null)?array_values($raw['roles']):[],
          'capabilities'=>$capabilities,
        ];
    }
    $sites=[];$issues=[];
    foreach((array)($topology['sites']??[]) as $raw){
        if(!is_array($raw))continue;
        $siteId=strtolower(tracky_v280_operations_text($raw['id']??$raw['site_id']??'',64));
        $members=array_values(array_filter($devices,static fn($d)=>($d['site_id']??'')===$siteId));
        $authority=tracky_v280_operations_authority($raw,$devices);
        $peer=tracky_v280_operations_peer($peerHealth,$siteId);
        $active=strtolower(tracky_v280_operations_text($raw['status']??'active',24))==='active';
        if(!$active)$health='unknown';
        elseif($authority['status']!=='current'||in_array($peer['status'],['partitioned','failed'],true))$health='critical';
        elseif(in_array($peer['status'],['unknown','suspect','reconciling','stale'],true))$health='degraded';
        else $health='healthy';

        if($active&&$authority['status']==='missing')$issues[]=['site_id'=>$siteId,'severity'=>'critical','code'=>'authority_missing','message'=>'Active site has no authority device.'];
        if($active&&$authority['status']==='invalid')$issues[]=['site_id'=>$siteId,'severity'=>'critical','code'=>$authority['reason']??'authority_invalid','message'=>'Site authority is not valid for the mirrored inventory.'];
        if(in_array($peer['status'],['partitioned','failed'],true))$issues[]=['site_id'=>$siteId,'severity'=>'critical','code'=>'federation_'.$peer['status'],'message'=>'Federation peer is '.$peer['status'].'.'];
        elseif(in_array($peer['status'],['unknown','suspect','reconciling','stale'],true))$issues[]=['site_id'=>$siteId,'severity'=>'degraded','code'=>'federation_'.$peer['status'],'message'=>'Federation peer is '.$peer['status'].'.'];

        $profiles=[];$mobile=0;
        foreach($members as $member){$profiles[]=$member['hardware_profile'];if($member['mobility']==='mobile')$mobile++;}
        $profiles=array_values(array_unique($profiles));sort($profiles,SORT_STRING);
        $sites[]=[
          'id'=>$siteId,'label'=>tracky_v280_operations_text($raw['label']??'Site',160),
          'kind'=>tracky_v280_operations_text($raw['kind']??'physical_site',64),
          'status'=>strtolower(tracky_v280_operations_text($raw['status']??'active',24)),
          'device_count'=>count($members),'profiles'=>$profiles,'mobile_device_count'=>$mobile,
          'authority'=>$authority,'federation'=>$peer,'health'=>$health,
        ];
    }
    usort($sites,static fn($a,$b)=>strcmp($a['id'],$b['id']));
    usort($devices,static fn($a,$b)=>strcmp($a['id'],$b['id']));
    usort($issues,static fn($a,$b)=>strcmp($a['site_id'].'|'.$a['code'],$b['site_id'].'|'.$b['code']));
    $rank=['healthy'=>0,'unknown'=>1,'degraded'=>2,'critical'=>3];$health='unknown';$seen=false;
    foreach($sites as $site){if($site['status']!=='active')continue;if(!$seen||$rank[$site['health']]>$rank[$health])$health=$site['health'];$seen=true;}
    $profileCounts=[];
    foreach($devices as $device)$profileCounts[$device['hardware_profile']]=($profileCounts[$device['hardware_profile']]??0)+1;
    ksort($profileCounts,SORT_STRING);
    return [
      'protocol'=>VP3_TRACKY_FEDERATION_OPERATIONS_PROTOCOL_V280,'version'=>'2.80','schema_version'=>1,
      'generated_at'=>gmdate(DATE_ATOM),'read_only'=>true,'authority_assignment'=>'origin_only',
      'cloud_role'=>'relay_and_mirror_only','observation_scope'=>'cloud_mirror','health'=>$health,
      'topology_revision'=>max(0,(int)($topology['revision']??$topologyInput['revision']??0)),
      'summary'=>[
        'site_count'=>count($sites),'active_site_count'=>count(array_filter($sites,static fn($s)=>$s['status']==='active')),
        'device_count'=>count($devices),'authority_count'=>count(array_filter($sites,static fn($s)=>$s['authority']['status']==='current')),
        'current_site_count'=>count(array_filter($sites,static fn($s)=>$s['federation']['status']==='current')),
        'degraded_site_count'=>count(array_filter($sites,static fn($s)=>$s['health']==='degraded')),
        'critical_site_count'=>count(array_filter($sites,static fn($s)=>$s['health']==='critical')),
        'profile_counts'=>$profileCounts,
      ],
      'sites'=>$sites,'devices'=>$devices,'relationships'=>is_array($topology['relationships']??null)?$topology['relationships']:[],
      'issues'=>$issues,
      'boundaries'=>[
        'operations-snapshot-is-read-only','origin-site-authority-only','cloud-remains-relay-and-mirror-only',
        'cloud-does-not-decide-destination-freshness','stale-and-partitioned-sites-are-explicit',
        'inventory-does-not-merge-site-local-identities','health-never-promotes-authority',
      ],
    ];
}

function tracky_v280_operations_report(PDO $pdo,int $userId,?string $reportingSiteId=null): array
{
    $topology=tracky_v278_report($pdo,$userId,$reportingSiteId);
    if(empty($topology['available'])){
        return ['available'=>false,'protocol'=>VP3_TRACKY_FEDERATION_OPERATIONS_PROTOCOL_V280,'read_only'=>true,'cloud_role'=>'relay_and_mirror_only'];
    }
    return ['available'=>true,'operations'=>tracky_v280_operations_snapshot($topology,[]),'capability'=>tracky_v280_operations_public_capability()];
}

function tracky_v280_operations_public_capability(): array
{
    return [
      'version'=>'2.80','protocol'=>VP3_TRACKY_FEDERATION_OPERATIONS_PROTOCOL_V280,
      'health_states'=>['healthy','degraded','critical','unknown'],
      'hardware_profiles'=>['node','desk','studio','team_node','pocket','custom'],
      'read_only'=>true,'authority_assignment'=>'origin_only','cloud_role'=>'relay_and_mirror_only',
      'cloud_decides_destination_freshness'=>false,'authority_mutation'=>false,'identity_mutation'=>false,
    ];
}
