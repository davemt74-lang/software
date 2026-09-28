<?php
declare(strict_types=1);

/**
 * Tracky V2.80 Section 3 — Physical World Dashboard & Site Switching.
 *
 * This is a read-only projection over the governed Cloud mirrors. Selecting a
 * site changes view/Agent dashboard context only; it does not change physical
 * location, physical authority, consent, identity links, or device state.
 */
const VP3_TRACKY_PHYSICAL_WORLD_DASHBOARD_V280='vp3-tracky-physical-world-dashboard-v280-20260928';
const VP3_TRACKY_PHYSICAL_WORLD_DASHBOARD_PROTOCOL_V280='physical_world_dashboard.v1';

function tracky_v280_dashboard_text(mixed $value,int $max=160): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/',' ',(string)($value??''))??''),0,max(1,$max),'');
}

function tracky_v280_dashboard_confidence(mixed $value): float
{
    return is_numeric($value)?max(0.0,min(1.0,(float)$value)):0.0;
}

function tracky_v280_dashboard_category(string $type): string
{
    $type=strtolower($type);
    if(in_array($type,['room','area','zone','space','environment'],true))return 'rooms';
    if($type==='person')return 'people';
    if(in_array($type,['device','sensor','camera','appliance','hardware'],true))return 'devices';
    return 'objects';
}

function tracky_v280_dashboard_ref(string $siteId,array $entity): string
{
    $ref=tracky_v280_dashboard_text($entity['ref']??'',360);
    if($ref!=='')return $ref;
    $local=tracky_v280_dashboard_text($entity['local_id']??$entity['id']??'',160);
    return 'site:'.$siteId.'::'.rawurlencode($local);
}

function tracky_v280_dashboard_ref_local(string $ref): string
{
    $parts=explode('::',$ref,2);
    return count($parts)===2?rawurldecode($parts[1]):'';
}

function tracky_v280_dashboard_location(string $siteId,array $entity,array $relations,array $entityByLocal): ?array
{
    $ref=tracky_v280_dashboard_ref($siteId,$entity);
    $local=tracky_v280_dashboard_text($entity['local_id']??$entity['id']??'',160);
    $allowed=['located_in','present_in','located_on','inside','in_room'];
    $candidates=[];
    foreach($relations as $relation){
        if(!is_array($relation))continue;
        $predicate=strtolower(tracky_v280_dashboard_text($relation['predicate']??'',60));
        if(!in_array($predicate,$allowed,true))continue;
        $subjectRef=tracky_v280_dashboard_text($relation['subject_ref']??'',360);
        $subjectLocal=tracky_v280_dashboard_text($relation['subject_local_id']??'',160);
        if($subjectRef===''&&$subjectLocal!=='')$subjectRef='site:'.$siteId.'::'.rawurlencode($subjectLocal);
        if($subjectRef!==$ref&&$subjectLocal!==$local)continue;
        $objectRef=tracky_v280_dashboard_text($relation['object_ref']??'',360);
        $objectLocal=tracky_v280_dashboard_text($relation['object_local_id']??'',160);
        if($objectRef===''&&$objectLocal!=='')$objectRef='site:'.$siteId.'::'.rawurlencode($objectLocal);
        if($objectLocal==='')$objectLocal=tracky_v280_dashboard_ref_local($objectRef);
        if($objectRef==='')continue;
        $target=$entityByLocal[$objectLocal]??[];
        $temporal=strtolower(tracky_v280_dashboard_text($relation['temporal_state']??'unknown',30));
        $candidates[]=[
          'location_ref'=>$objectRef,'location_local_id'=>$objectLocal,
          'location_label'=>tracky_v280_dashboard_text($target['label']??$target['name']??$objectLocal,160),
          'predicate'=>$predicate,'confidence'=>tracky_v280_dashboard_confidence($relation['confidence']??0),
          'temporal_state'=>$temporal,'as_of'=>max(0,(int)($relation['as_of']??0)),
        ];
    }
    usort($candidates,static function($a,$b){
        $ac=in_array($a['temporal_state'],['current','inferred'],true)?1:0;
        $bc=in_array($b['temporal_state'],['current','inferred'],true)?1:0;
        if($ac!==$bc)return $bc<=>$ac;
        if($a['confidence']!==$b['confidence'])return $a['confidence']<$b['confidence']?1:-1;
        return $b['as_of']<=>$a['as_of'];
    });
    return $candidates[0]??null;
}

function tracky_v280_dashboard_entity(string $siteId,array $entity,array $relations,array $entityByLocal): array
{
    $type=strtolower(tracky_v280_dashboard_text($entity['type']??'entity',40))?:'entity';
    $location=tracky_v280_dashboard_location($siteId,$entity,$relations,$entityByLocal);
    $state=strtolower(tracky_v280_dashboard_text($entity['state']??'unknown',30));
    $entityConfidence=tracky_v280_dashboard_confidence($entity['confidence']??0);
    $confidence=$location
      ?tracky_v280_dashboard_confidence(($entityConfidence*.4)+((float)$location['confidence']*.6))
      :$entityConfidence;
    $observed=max(0,(int)($entity['observed_at']??0));
    return [
      'ref'=>tracky_v280_dashboard_ref($siteId,$entity),
      'local_id'=>tracky_v280_dashboard_text($entity['local_id']??$entity['id']??'',160),
      'site_id'=>$siteId,'type'=>$type,'category'=>tracky_v280_dashboard_category($type),
      'label'=>tracky_v280_dashboard_text($entity['label']??$entity['name']??$entity['local_id']??$entity['id']??$type,160),
      'state'=>$state,'confidence'=>$confidence,'observed_at'=>$observed,
      'current'=>in_array($state,['observed','user-confirmed'],true)
        &&(!$location||in_array((string)$location['temporal_state'],['current','inferred'],true)),
      'last_known'=>$state==='last-known'||$observed>0,
      'location'=>$location,'identity_scope'=>'site_local',
    ];
}

function tracky_v280_dashboard_build(array $operations,array $world,array $agentContext,string $selectedSiteId=''): array
{
    $opSites=array_values(array_filter((array)($operations['sites']??[]),'is_array'));
    $worldSites=array_values(array_filter((array)($world['sites']??[]),'is_array'));
    $opById=[];$worldById=[];
    foreach($opSites as $row){$id=strtolower(tracky_v280_dashboard_text($row['id']??$row['site_id']??'',64));if($id!=='')$opById[$id]=$row;}
    foreach($worldSites as $row){$id=strtolower(tracky_v280_dashboard_text($row['site_id']??$row['id']??'',64));if($id!=='')$worldById[$id]=$row;}
    $siteIds=array_values(array_unique(array_merge(array_keys($opById),array_keys($worldById))));sort($siteIds,SORT_STRING);

    $requested=strtolower(tracky_v280_dashboard_text($selectedSiteId,64));
    $current=strtolower(tracky_v280_dashboard_text($agentContext['current_site']['site_id']??'',64));
    $local=strtolower(tracky_v280_dashboard_text($operations['local_site_id']??'',64));
    $basis='none';$selected='';$requestAvailable=$requested==='';
    if($requested!==''&&in_array($requested,$siteIds,true)){$selected=$requested;$basis='explicit_user_selection';$requestAvailable=true;}
    elseif($current!==''&&in_array($current,$siteIds,true)){$selected=$current;$basis='current_agent_site';}
    elseif($local!==''&&in_array($local,$siteIds,true)){$selected=$local;$basis='local_site_fallback';}
    elseif($siteIds){$selected=$siteIds[0];$basis='first_authorized_site';}

    $fragment=$worldById[$selected]??[];
    $relations=array_values(array_filter((array)($fragment['relations']??[]),'is_array'));
    $rawEntities=array_values(array_filter((array)($fragment['entities']??[]),'is_array'));
    $entityByLocal=[];
    foreach($rawEntities as $entity){$id=tracky_v280_dashboard_text($entity['local_id']??$entity['id']??'',160);if($id!=='')$entityByLocal[$id]=$entity;}
    $groups=['rooms'=>[],'people'=>[],'objects'=>[],'devices'=>[]];
    foreach($rawEntities as $entity){$normalized=tracky_v280_dashboard_entity($selected,$entity,$relations,$entityByLocal);$groups[$normalized['category']][]=$normalized;}
    foreach($groups as &$items){
        usort($items,static function($a,$b){
            if((bool)$a['current']!==(bool)$b['current'])return $a['current']?-1:1;
            if($a['confidence']!==$b['confidence'])return $a['confidence']<$b['confidence']?1:-1;
            return strcmp((string)$a['label'],(string)$b['label']);
        });
    }unset($items);

    $selectedOp=$opById[$selected]??[];
    $authorityId=strtolower(tracky_v280_dashboard_text($selectedOp['authority']['device_id']??$selectedOp['authority_device_id']??'',64));
    $hardware=[];
    foreach((array)($operations['devices']??[]) as $device){
        if(!is_array($device)||strtolower(tracky_v280_dashboard_text($device['site_id']??'',64))!==$selected)continue;
        $id=strtolower(tracky_v280_dashboard_text($device['id']??$device['device_id']??'',64));
        $hardware[]=[
          'id'=>$id,'label'=>tracky_v280_dashboard_text($device['label']??$device['hardware_profile_label']??$device['hardware_profile']??'Device',160),
          'hardware_profile'=>strtolower(tracky_v280_dashboard_text($device['hardware_profile']??'custom',40)),
          'hardware_profile_label'=>tracky_v280_dashboard_text($device['hardware_profile_label']??$device['hardware_profile']??'Custom',80),
          'trust_state'=>strtolower(tracky_v280_dashboard_text($device['trust_state']??'unknown',24)),
          'runtime_status'=>strtolower(tracky_v280_dashboard_text($device['runtime_status']??'unknown',32)),
          'version'=>tracky_v280_dashboard_text($device['version']??'',64),'is_authority'=>$id!==''&&$id===$authorityId,
        ];
    }
    usort($hardware,static fn($a,$b)=>strcmp((string)$a['label'],(string)$b['label']));

    $siteOptions=[];
    foreach($siteIds as $siteId){
        $op=$opById[$siteId]??[];$ws=$worldById[$siteId]??[];
        $counts=['rooms'=>0,'people'=>0,'objects'=>0,'devices'=>0];
        foreach((array)($ws['entities']??[]) as $entity){
            if(!is_array($entity))continue;
            $counts[tracky_v280_dashboard_category(strtolower(tracky_v280_dashboard_text($entity['type']??'entity',40)))]++;
        }
        $siteOptions[]=[
          'site_id'=>$siteId,'label'=>tracky_v280_dashboard_text($op['label']??$ws['context']['site']??$siteId,160),
          'selected'=>$siteId===$selected,'health'=>strtolower(tracky_v280_dashboard_text($op['health']??'unknown',24)),
          'federation_status'=>strtolower(tracky_v280_dashboard_text($op['federation']['status']??'unknown',24)),
          'authority_device_id'=>strtolower(tracky_v280_dashboard_text($op['authority']['device_id']??$op['authority_device_id']??'',64)),
          'authority_epoch'=>max(0,(int)($op['authority']['epoch']??$op['authority_epoch']??0)),
          'world_revision'=>max(0,(int)($ws['revision']??0)),'counts'=>$counts,
        ];
    }

    $issues=[];
    if($requested!==''&&!$requestAvailable)$issues[]=['severity'=>'degraded','code'=>'requested_site_not_available'];

    return [
      'protocol'=>VP3_TRACKY_PHYSICAL_WORLD_DASHBOARD_PROTOCOL_V280,'version'=>'2.80','schema_version'=>1,
      'generated_at'=>gmdate(DATE_ATOM),
      'selected_site'=>[
        'site_id'=>$selected,'label'=>tracky_v280_dashboard_text($selectedOp['label']??$fragment['context']['site']??'',160),
        'basis'=>$basis,'health'=>strtolower(tracky_v280_dashboard_text($selectedOp['health']??'unknown',24)),
        'federation_status'=>strtolower(tracky_v280_dashboard_text($selectedOp['federation']['status']??'unknown',24)),
        'world_revision'=>max(0,(int)($fragment['revision']??0)),'observed_at'=>$fragment['observed_at']??'',
      ],
      'site_options'=>$siteOptions,
      'counts'=>[
        'rooms'=>count($groups['rooms']),'people'=>count($groups['people']),'objects'=>count($groups['objects']),
        'world_devices'=>count($groups['devices']),'hardware_units'=>count($hardware),
      ],
      'rooms'=>$groups['rooms'],'people'=>$groups['people'],'objects'=>$groups['objects'],
      'world_devices'=>$groups['devices'],'hardware_units'=>$hardware,
      'agent_context'=>[
        'view_site_id'=>$selected,'view_basis'=>$basis,
        'physical_current_site_id'=>strtolower(tracky_v280_dashboard_text($agentContext['current_site']['site_id']??'',64)),
        'physical_state'=>strtolower(tracky_v280_dashboard_text($agentContext['physical_state']??'unknown',40)),
        'agent_state'=>strtolower(tracky_v280_dashboard_text($agentContext['agent_state']??'unknown',40)),
        'follows_selected_site'=>true,'view_only'=>true,
        'changes_physical_authority'=>false,'changes_physical_location'=>false,
      ],
      'issues'=>$issues,'semantic_only'=>true,'permission_filtered_input'=>true,
      'identity_scope'=>'site_local','cross_site_identity_merge'=>false,
      'authority_assignment'=>'origin_only','cloud_role'=>'relay_and_mirror_only',
    ];
}

function tracky_v280_dashboard_report(PDO $pdo,int $userId,string $selectedSiteId=''): array
{
    if($userId<1)return ['available'=>false,'protocol'=>VP3_TRACKY_PHYSICAL_WORLD_DASHBOARD_PROTOCOL_V280];
    $operationsWrapper=tracky_v280_operations_report($pdo,$userId,null);
    $operations=is_array($operationsWrapper['operations']??null)?$operationsWrapper['operations']:[];
    $world=tracky_v278_world_report($pdo,$userId,null);
    $agent=tracky_v278_agent_context_report($pdo,$userId);
    $context=is_array($agent['preferred_context']??null)?$agent['preferred_context']:[];
    $dashboard=tracky_v280_dashboard_build($operations,$world,$context,$selectedSiteId);
    if(function_exists('tracky_v280_syncv_report')&&function_exists('tracky_v280_syncv_annotate_dashboard')){
        $visibilityReport=tracky_v280_syncv_report($pdo,$userId);
        $visibility=is_array($visibilityReport['preferred_visibility']??null)?$visibilityReport['preferred_visibility']:[];
        if($visibility)$dashboard=tracky_v280_syncv_annotate_dashboard($dashboard,$visibility);
    }
    return ['available'=>!empty($dashboard['site_options']),'dashboard'=>$dashboard,'capability'=>tracky_v280_dashboard_public_capability()];
}

function tracky_v280_dashboard_public_capability(): array
{
    return [
      'version'=>'2.80','protocol'=>VP3_TRACKY_PHYSICAL_WORLD_DASHBOARD_PROTOCOL_V280,
      'categories'=>['rooms','people','objects','world_devices','hardware_units'],
      'site_switching'=>true,'agent_context_follows_selected_site'=>true,'view_only'=>true,
      'authority_mutation'=>false,'physical_location_mutation'=>false,'cross_site_identity_merge'=>false,
      'semantic_only'=>true,'cloud_role'=>'relay_and_mirror_only',
    ];
}
