<?php
declare(strict_types=1);

/**
 * Tracky V2.80 Section 4 — Mobile Transition & Cross-Site Presence.
 * Read-only projection over governed mobile transition mirrors/history.
 */
const VP3_TRACKY_CROSS_SITE_PRESENCE_V280='vp3-tracky-cross-site-presence-v280-20260928';
const VP3_TRACKY_CROSS_SITE_PRESENCE_PROTOCOL_V280='physical_cross_site_presence.v1';

function tracky_v280_presence_text(mixed $value,int $max=200): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/',' ',(string)($value??''))??''),0,max(1,$max),'');
}

function tracky_v280_presence_confidence(mixed $value): float
{
    return is_numeric($value)?max(0.0,min(1.0,(float)$value)):0.0;
}

function tracky_v280_presence_site_map(array $operations): array
{
    $out=[];
    foreach((array)($operations['sites']??[]) as $row){
        if(!is_array($row))continue;
        $id=strtolower(tracky_v280_presence_text($row['id']??$row['site_id']??'',64));
        if($id==='')continue;
        $out[$id]=[
          'id'=>$id,'label'=>tracky_v280_presence_text($row['label']??$id,160),
          'authority_device_id'=>strtolower(tracky_v280_presence_text($row['authority']['device_id']??$row['authority_device_id']??'',64)),
          'authority_epoch'=>max(0,(int)($row['authority']['epoch']??$row['authority_epoch']??0)),
          'health'=>strtolower(tracky_v280_presence_text($row['health']??'unknown',24)),
          'federation_status'=>strtolower(tracky_v280_presence_text($row['federation']['status']??'unknown',24)),
        ];
    }
    return $out;
}

function tracky_v280_presence_subject_catalog(array $operations,array $agentContext=[]): array
{
    $out=[];
    foreach((array)($operations['devices']??[]) as $row){
        if(!is_array($row))continue;
        $id=strtolower(tracky_v280_presence_text($row['id']??$row['device_id']??'',160));
        if($id==='')continue;
        $out[$id]=[
          'label'=>tracky_v280_presence_text($row['label']??$row['hardware_profile_label']??$row['hardware_profile']??$id,160),
          'subject_type'=>'mobile_hardware',
          'hardware_profile'=>strtolower(tracky_v280_presence_text($row['hardware_profile']??'custom',40)),
        ];
    }
    $focus=is_array($agentContext['focus_identity']??null)?$agentContext['focus_identity']:[];
    $canonical=strtolower(tracky_v280_presence_text($focus['canonical_identity_id']??'',160));
    if($canonical!==''){
        $aliases=is_array($focus['aliases']??null)?$focus['aliases']:[];
        $out[$canonical]=[
          'label'=>tracky_v280_presence_text($aliases[0]??$canonical,160),
          'subject_type'=>strtolower(tracky_v280_presence_text($focus['entity_type']??'person',40)),
          'hardware_profile'=>'',
        ];
    }
    $active=is_array($agentContext['active_mobile_transition']??null)?$agentContext['active_mobile_transition']:[];
    $activeSubject=strtolower(tracky_v280_presence_text($active['subject_id']??'',160));
    if($activeSubject!==''&&!isset($out[$activeSubject])&&$canonical!==''&&isset($out[$canonical])){
        $out[$activeSubject]=$out[$canonical];
    }
    return $out;
}

function tracky_v280_presence_site_label(array $sites,string $id): string
{
    return tracky_v280_presence_text($sites[$id]['label']??$id,160);
}

function tracky_v280_presence_current(array $t,array $sites): ?array
{
    $state=strtolower(tracky_v280_presence_text($t['state']??'',40));
    $source=strtolower(tracky_v280_presence_text($t['source_site_id']??'',64));
    $dest=strtolower(tracky_v280_presence_text($t['destination_site_id']??'',64));
    $temp=is_array($t['temporary_context']??null)?$t['temporary_context']:null;
    if($state==='arrived'&&$dest!=='')return ['kind'=>'site','site_id'=>$dest,'label'=>tracky_v280_presence_site_label($sites,$dest),'status'=>'present','confirmed'=>true];
    if($state==='departing'&&$source!=='')return ['kind'=>'site','site_id'=>$source,'label'=>tracky_v280_presence_site_label($sites,$source),'status'=>'departing','confirmed'=>true];
    if($state==='temporary_context'&&$temp){
        return [
          'kind'=>'temporary_context','context_id'=>tracky_v280_presence_text($temp['id']??'',160),
          'label'=>tracky_v280_presence_text($temp['label']??'Temporary context',160),
          'status'=>'temporary_context','confirmed'=>false,'durable_site'=>false,'site_authority'=>false,
          'confidence'=>tracky_v280_presence_confidence($temp['confidence']??0),
        ];
    }
    if(in_array($state,['in_transit','arriving','uncertain','offline'],true)){
        return ['kind'=>'transition','site_id'=>'','label'=>'','status'=>$state,'confirmed'=>false];
    }
    return null;
}

function tracky_v280_presence_last_site(array $t,array $sites): ?array
{
    $state=strtolower(tracky_v280_presence_text($t['state']??'',40));
    $source=strtolower(tracky_v280_presence_text($t['source_site_id']??'',64));
    $dest=strtolower(tracky_v280_presence_text($t['destination_site_id']??'',64));
    if($state==='arrived'&&$dest!==''){
        return ['site_id'=>$dest,'label'=>tracky_v280_presence_site_label($sites,$dest),'basis'=>'destination_arrival_confirmed','confirmed_at'=>(int)($t['arrived_at']??$t['updated_at']??0)];
    }
    if($source!=='')return ['site_id'=>$source,'label'=>tracky_v280_presence_site_label($sites,$source),'basis'=>'source_site_before_transition','confirmed_at'=>(int)($t['started_at']??0)];
    return null;
}

function tracky_v280_presence_transition(array $t,array $sites,array $catalog): array
{
    $state=strtolower(tracky_v280_presence_text($t['state']??'',40));
    $kind=strtolower(tracky_v280_presence_text($t['subject_kind']??'mobile_device',40));
    $subjectId=tracky_v280_presence_text($t['subject_id']??'',160);
    $meta=$catalog[strtolower($subjectId)]??[];
    $source=strtolower(tracky_v280_presence_text($t['source_site_id']??'',64));
    $dest=strtolower(tracky_v280_presence_text($t['destination_site_id']??'',64));
    $sourceAuthority=strtolower(tracky_v280_presence_text($sites[$source]['authority_device_id']??'',64));
    $evidence=[];
    foreach(array_slice(is_array($t['evidence']??null)?$t['evidence']:[],-8) as $item){
        if(!is_array($item))continue;
        $evidence[]=[
          'type'=>tracky_v280_presence_text($item['type']??'',80),
          'site_id'=>strtolower(tracky_v280_presence_text($item['site_id']??'',64)),
          'confidence'=>tracky_v280_presence_confidence($item['confidence']??0),
          'observed_at'=>max(0,(int)($item['observed_at']??0)),
          'context_id'=>tracky_v280_presence_text($item['context_id']??'',160),
          'context_label'=>tracky_v280_presence_text($item['context_label']??'',160),
        ];
    }
    return [
      'transition_id'=>tracky_v280_presence_text($t['transition_id']??'',160),
      'subject_kind'=>$kind,'subject_id'=>$subjectId,
      'subject_type'=>strtolower(tracky_v280_presence_text($meta['subject_type']??($kind==='mobile_device'?'mobile_hardware':'continuity_subject'),40)),
      'subject_label'=>tracky_v280_presence_text($meta['label']??$subjectId,160),
      'source_site'=>['site_id'=>$source,'label'=>tracky_v280_presence_site_label($sites,$source)],
      'destination_site'=>$dest!==''?['site_id'=>$dest,'label'=>tracky_v280_presence_site_label($sites,$dest)]:null,
      'state'=>$state,'active'=>in_array($state,['departing','in_transit','arriving','uncertain','offline','temporary_context'],true),
      'confidence'=>tracky_v280_presence_confidence($t['confidence']??0),
      'destination_confidence'=>tracky_v280_presence_confidence($t['destination_confidence']??0),
      'state_reason'=>tracky_v280_presence_text($t['state_reason']??'',200),
      'started_at'=>max(0,(int)($t['started_at']??0)),
      'state_changed_at'=>max(0,(int)($t['state_changed_at']??0)),
      'updated_at'=>max(0,(int)($t['updated_at']??0)),
      'arrived_at'=>isset($t['arrived_at'])?(int)$t['arrived_at']:null,
      'offline_since'=>isset($t['offline_since'])?(int)$t['offline_since']:null,
      'temporary_context'=>is_array($t['temporary_context']??null)?$t['temporary_context']:null,
      'evidence_count'=>count(is_array($t['evidence']??null)?$t['evidence']:[]),'evidence'=>$evidence,
      'last_confirmed_site'=>tracky_v280_presence_last_site($t,$sites),
      'current_presence'=>tracky_v280_presence_current($t,$sites),
      'destination_presence_confirmed'=>$state==='arrived'&&$dest!=='',
      'may_claim_present_at_destination'=>$state==='arrived'&&$dest!=='',
      'authority'=>[
        'subject_is_source_authority'=>$kind==='mobile_device'&&$sourceAuthority!==''&&$sourceAuthority===strtolower($subjectId),
        'source_authority_device_id'=>$sourceAuthority,'authority_transfer'=>false,'authority_unchanged'=>true,
      ],
      'identity'=>[
        'scope'=>$kind==='mobile_device'?'stable_mobile_device':'explicit_continuity_subject',
        'cross_site_merge'=>false,'identity_linking'=>false,
      ],
    ];
}

function tracky_v280_presence_history(PDO $pdo,int $userId,int $limit=200): array
{
    tracky_v278_mobile_ensure_schema($pdo);
    $q=$pdo->prepare('SELECT transition_id,revision,state,fingerprint,snapshot_json FROM tracky_cloud_mobile_transition_history WHERE user_id=? ORDER BY id DESC LIMIT '.max(1,min(1000,$limit)));
    $q->execute([$userId]);$out=[];
    foreach($q->fetchAll()?:[] as $row){
        $snapshot=json_decode((string)($row['snapshot_json']??''),true);
        if(!is_array($snapshot))continue;
        $out[]=[
          'transition_id'=>(string)($row['transition_id']??''),'revision'=>(int)($row['revision']??0),
          'state'=>(string)($row['state']??''),'fingerprint'=>(string)($row['fingerprint']??''),
          'snapshot'=>$snapshot,
        ];
    }
    return $out;
}

function tracky_v280_presence_build(array $operations,array $mobile,array $history=[],array $agentContext=[]): array
{
    $sites=tracky_v280_presence_site_map($operations);
    $catalog=tracky_v280_presence_subject_catalog($operations,$agentContext);
    $transitions=[];
    foreach((array)($mobile['transitions']??[]) as $row)if(is_array($row))$transitions[]=tracky_v280_presence_transition($row,$sites,$catalog);
    usort($transitions,static function($a,$b){
        if((bool)$a['active']!==(bool)$b['active'])return $a['active']?-1:1;
        $at=(int)($a['state_changed_at']?:$a['updated_at']);$bt=(int)($b['state_changed_at']?:$b['updated_at']);
        return $bt<=>$at ?: strcmp((string)$a['transition_id'],(string)$b['transition_id']);
    });
    $active=array_values(array_filter($transitions,static fn($row)=>!empty($row['active'])));
    $timeline=[];
    foreach($history as $row){
        if(!is_array($row))continue;$snapshot=is_array($row['snapshot']??null)?$row['snapshot']:$row;
        $summary=tracky_v280_presence_transition($snapshot,$sites,$catalog);
        $timeline[]=[
          'event_id'=>tracky_v280_presence_text($row['event_id']??('transition:'.$summary['transition_id'].':revision:'.(int)($row['revision']??$snapshot['revision']??0)),220),
          'transition_id'=>$summary['transition_id'],'revision'=>max(0,(int)($row['revision']??$snapshot['revision']??0)),
          'state'=>$summary['state'],'subject_id'=>$summary['subject_id'],'subject_label'=>$summary['subject_label'],
          'source_site'=>$summary['source_site'],'destination_site'=>$summary['destination_site'],
          'confidence'=>$summary['confidence'],'state_reason'=>$summary['state_reason'],
          'occurred_at'=>max(0,(int)($snapshot['state_changed_at']??$snapshot['updated_at']??$snapshot['started_at']??0)),
          'immutable'=>true,'fingerprint'=>tracky_v280_presence_text($row['fingerprint']??$snapshot['fingerprint']??'',128),
        ];
    }
    if(!$timeline){
        foreach($transitions as $summary)$timeline[]=[
          'event_id'=>'transition:'.$summary['transition_id'].':current','transition_id'=>$summary['transition_id'],
          'revision'=>0,'state'=>$summary['state'],'subject_id'=>$summary['subject_id'],'subject_label'=>$summary['subject_label'],
          'source_site'=>$summary['source_site'],'destination_site'=>$summary['destination_site'],'confidence'=>$summary['confidence'],
          'state_reason'=>$summary['state_reason'],'occurred_at'=>(int)($summary['state_changed_at']?:$summary['updated_at']?:$summary['started_at']),
          'immutable'=>false,'fingerprint'=>'',
        ];
    }
    usort($timeline,static fn($a,$b)=>($b['occurred_at']<=>$a['occurred_at'])?:($b['revision']<=>$a['revision'])?:strcmp((string)$a['transition_id'],(string)$b['transition_id']));
    $stateCounts=array_fill_keys(['departing','in_transit','arriving','uncertain','offline','temporary_context','arrived','canceled'],0);
    foreach($transitions as $row)if(array_key_exists($row['state'],$stateCounts))$stateCounts[$row['state']]++;

    $sitePresence=[];
    foreach($sites as $site){
        $related=array_values(array_filter($transitions,static fn($row)=>$row['source_site']['site_id']===$site['id']||(($row['destination_site']['site_id']??'')===$site['id'])));
        $sitePresence[]=[
          'site_id'=>$site['id'],'label'=>$site['label'],'health'=>$site['health'],'federation_status'=>$site['federation_status'],
          'departing'=>count(array_filter($related,static fn($row)=>$row['active']&&$row['source_site']['site_id']===$site['id']&&$row['state']==='departing')),
          'arriving'=>count(array_filter($related,static fn($row)=>$row['active']&&($row['destination_site']['site_id']??'')===$site['id']&&$row['state']==='arriving')),
          'in_transit_from'=>count(array_filter($related,static fn($row)=>$row['active']&&$row['source_site']['site_id']===$site['id']&&$row['state']==='in_transit')),
          'in_transit_to'=>count(array_filter($related,static fn($row)=>$row['active']&&($row['destination_site']['site_id']??'')===$site['id']&&$row['state']==='in_transit')),
          'offline'=>count(array_filter($related,static fn($row)=>$row['active']&&$row['state']==='offline')),
          'recent_arrivals'=>count(array_filter($related,static fn($row)=>$row['state']==='arrived'&&($row['destination_site']['site_id']??'')===$site['id'])),
        ];
    }
    usort($sitePresence,static fn($a,$b)=>strcmp((string)$a['label'],(string)$b['label']));

    return [
      'protocol'=>VP3_TRACKY_CROSS_SITE_PRESENCE_PROTOCOL_V280,'version'=>'2.80','schema_version'=>1,
      'generated_at'=>gmdate(DATE_ATOM),'active_count'=>count($active),'transition_count'=>count($transitions),
      'state_counts'=>$stateCounts,'active_transitions'=>$active,'transitions'=>$transitions,
      'site_presence'=>$sitePresence,'timeline'=>array_slice($timeline,0,200),
      'history_source'=>$history?'immutable_transition_revision_history':'current_transition_snapshots',
      'agent_context'=>[
        'active_count'=>count($active),
        'transitions'=>array_map(static fn($row)=>[
          'transition_id'=>$row['transition_id'],'subject_label'=>$row['subject_label'],'subject_type'=>$row['subject_type'],
          'state'=>$row['state'],'source_site'=>$row['source_site'],'destination_site'=>$row['destination_site'],
          'confidence'=>$row['confidence'],'last_confirmed_site'=>$row['last_confirmed_site'],
          'current_presence'=>$row['current_presence'],'may_claim_present_at_destination'=>$row['may_claim_present_at_destination'],
          'authority_transfer'=>false,'cross_site_identity_merge'=>false,
        ],array_slice($active,0,12)),
        'destination_claim_rule'=>'present_at_destination_only_after_arrived','physical_location_invention'=>false,
      ],
      'boundaries'=>[
        'source-site-transition-authority-remains-authoritative','arrival-is-never-invented',
        'destination-presence-requires-arrived-state','offline-and-temporary-context-are-not-durable-sites',
        'moving-authority-device-does-not-transfer-authority','cross-site-identity-merge-remains-disabled',
        'semantic-only-no-raw-perception',
      ],
      'read_only'=>true,'authority_mutation'=>false,'physical_location_mutation'=>false,
      'cross_site_identity_merge'=>false,'cloud_role'=>'relay_and_mirror_only',
    ];
}

function tracky_v280_presence_report(PDO $pdo,int $userId): array
{
    $operationsWrapper=tracky_v280_operations_report($pdo,$userId,null);
    $operations=is_array($operationsWrapper['operations']??null)?$operationsWrapper['operations']:[];
    $mobile=tracky_v278_mobile_report($pdo,$userId);
    $agent=tracky_v278_agent_context_report($pdo,$userId);
    $context=is_array($agent['preferred_context']??null)?$agent['preferred_context']:[];
    $history=tracky_v280_presence_history($pdo,$userId,200);
    return ['available'=>!empty($mobile['transitions']),'presence'=>tracky_v280_presence_build($operations,$mobile,$history,$context),'capability'=>tracky_v280_presence_public_capability()];
}

function tracky_v280_presence_public_capability(): array
{
    return [
      'version'=>'2.80','protocol'=>VP3_TRACKY_CROSS_SITE_PRESENCE_PROTOCOL_V280,
      'states'=>['departing','in_transit','arriving','arrived','uncertain','offline','temporary_context','canceled'],
      'transition_history'=>true,'immutable_history_when_available'=>true,'destination_claim_requires_arrived'=>true,
      'agent_context'=>true,'authority_mutation'=>false,'physical_location_mutation'=>false,
      'cross_site_identity_merge'=>false,'temporary_context_site_authority'=>false,'read_only'=>true,
      'cloud_role'=>'relay_and_mirror_only',
    ];
}
