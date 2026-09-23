<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Runtime v26.10 — Cross-Domain Entity Graph & Relationship Resolution.
 *
 * The graph is an ephemeral, read-only projection over existing v5.00 object
 * authorities and relationship providers. It creates no graph database, entity
 * table, CRM copy, Team copy, event ledger, memory store or execution path.
 */
const VP3_COGNITIVE_ENTITY_GRAPH_V2610='vp3-cognitive-entity-graph-v2610-20260923';
const VP3_COGNITIVE_ENTITY_GRAPH_CONTRACT_V2610='vp3-cognitive-entity-graph-v1';
const VP3_COGNITIVE_ENTITY_GRAPH_MAX_EVENT_SEEDS_V2610=24;
const VP3_COGNITIVE_ENTITY_GRAPH_MAX_SEEDS_V2610=10;
const VP3_COGNITIVE_ENTITY_GRAPH_MAX_NODES_V2610=36;
const VP3_COGNITIVE_ENTITY_GRAPH_MAX_EDGES_V2610=72;
const VP3_COGNITIVE_ENTITY_GRAPH_MAX_DEPTH_V2610=2;

function vp3_cognitive_entity_graph_relation_v2610(mixed $value): string
{
    $relation=function_exists('vp3_cognitive_id_v500')
        ?vp3_cognitive_id_v500($value,60)
        :preg_replace('/[^a-z0-9._:-]+/','_',strtolower(trim((string)$value)));
    $aliases=[
        'relates_to'=>'related_to','related'=>'related_to',
        'belongs'=>'belongs_to','owner'=>'owned_by',
        'generated_by'=>'generated_from','result_of'=>'generated_from',
        'fulfilled'=>'fulfilled_by','observed'=>'observed_by',
    ];
    $relation=$aliases[$relation]??$relation;
    $allowed=[
        'belongs_to','owned_by','owns','member_of','has_member','issued_to','issued_by',
        'claimed_by','claims','generated_from','converted_from','discussed_in','related_to',
        'resulted_in','assigned_to','fulfilled_by','observed_by','has_location','location_of',
        'offers','offered_by','enrolled_in','case_for','instance_of','authorizes_for',
        'processed_by','same_as','represents',
    ];
    return in_array($relation,$allowed,true)?$relation:'related_to';
}

function vp3_cognitive_entity_graph_domain_for_ref_v2610(array $ref): string
{
    try{
        [$module,$normalized]=vp3_cognitive_module_for_ref_v500($ref);
        $moduleId=(string)($module['module']??'');
        $registry=function_exists('vp3_cognitive_domain_registry_v2600')?vp3_cognitive_domain_registry_v2600():[];
        if($moduleId!==''&&isset($registry['domains'][$moduleId]))return $moduleId;
        $type=(string)($normalized['type']??'');
        $matches=[];
        foreach((array)($registry['domains']??[]) as $domain=>$definition){
            if(in_array($type,array_merge((array)($definition['objects']??[]),(array)($definition['related_objects']??[])),true))$matches[]=(string)$domain;
        }
        if(count($matches)===1)return $matches[0];
        return $moduleId;
    }catch(Throwable $e){return '';}
}

function vp3_cognitive_entity_graph_node_key_v2610(array $ref): string
{
    $type=(string)($ref['type']??'');$id=(string)($ref['id']??'');$scope=(string)($ref['scope']??'personal');
    $workspace=max(0,(int)($ref['workspace_id']??0));
    return hash('sha256',$type.'|'.$id.'|'.$scope.'|'.$workspace);
}

function vp3_cognitive_entity_graph_normalize_ref_v2610(PDO $pdo,array $user,string $namespace,array $ref): ?array
{
    try{
        $normalized=vp3_cognitive_validate_object_ref_v500($ref,true);
        if(!vp3_cognitive_authorize_ref_v500($pdo,$user,$namespace,$normalized,'read'))return null;
        [$module]=vp3_cognitive_module_for_ref_v500($normalized);
        return [
            'key'=>vp3_cognitive_entity_graph_node_key_v2610($normalized),
            'domain'=>vp3_cognitive_entity_graph_domain_for_ref_v2610($normalized),
            'module'=>(string)($module['module']??''),
            'type'=>(string)$normalized['type'],
            'id'=>(string)$normalized['id'],
            'scope'=>(string)$normalized['scope'],
            'workspace_id'=>max(0,(int)($normalized['workspace_id']??0)),
        ];
    }catch(Throwable $e){return null;}
}

function vp3_cognitive_entity_graph_event_seeds_v2610(PDO $pdo,array $user,string $namespace,int $limit=VP3_COGNITIVE_ENTITY_GRAPH_MAX_EVENT_SEEDS_V2610): array
{
    $uid=(int)($user['id']??0);
    if($uid<1||!function_exists('agent_event_schema_ready_v1920')||!agent_event_schema_ready_v1920($pdo))return [];
    $limit=max(1,min(VP3_COGNITIVE_ENTITY_GRAPH_MAX_EVENT_SEEDS_V2610,$limit));
    $stmt=$pdo->prepare("SELECT id,source,event_type,payload_json FROM agent_event_inbox
      WHERE owner_user_id=? AND verification_status IN ('trusted','verified')
      ORDER BY COALESCE(occurred_at,received_at) DESC,id DESC LIMIT {$limit}");
    $stmt->execute([$uid]);$out=[];$seen=[];
    foreach($stmt->fetchAll()?:[] as $row){
        $payload=json_decode((string)($row['payload_json']??''),true);if(!is_array($payload))continue;
        $refs=is_array($payload['object_refs']??null)?$payload['object_refs']:[];
        foreach(array_slice($refs,0,16) as $candidate){
            if(!is_array($candidate))continue;
            $node=vp3_cognitive_entity_graph_normalize_ref_v2610($pdo,$user,$namespace,$candidate);if(!$node)continue;
            if(isset($seen[$node['key']]))continue;$seen[$node['key']]=true;
            $node['seed_source']='canonical_event';
            $node['seed_event_type']=mb_strimwidth((string)($row['event_type']??''),0,120,'');
            $out[]=$node;
            if(count($out)>=VP3_COGNITIVE_ENTITY_GRAPH_MAX_SEEDS_V2610)return $out;
        }
    }
    return $out;
}

function vp3_cognitive_entity_graph_seed_nodes_v2610(PDO $pdo,array $user,string $namespace,array $explicitRefs=[]): array
{
    $out=[];$seen=[];
    foreach(array_slice($explicitRefs,0,VP3_COGNITIVE_ENTITY_GRAPH_MAX_SEEDS_V2610) as $candidate){
        if(!is_array($candidate))continue;
        $node=vp3_cognitive_entity_graph_normalize_ref_v2610($pdo,$user,$namespace,$candidate);if(!$node)continue;
        if(isset($seen[$node['key']]))continue;$seen[$node['key']]=true;$node['seed_source']='working_context';$out[]=$node;
    }
    foreach(vp3_cognitive_entity_graph_event_seeds_v2610($pdo,$user,$namespace) as $node){
        if(isset($seen[$node['key']]))continue;$seen[$node['key']]=true;$out[]=$node;
        if(count($out)>=VP3_COGNITIVE_ENTITY_GRAPH_MAX_SEEDS_V2610)break;
    }
    return array_slice($out,0,VP3_COGNITIVE_ENTITY_GRAPH_MAX_SEEDS_V2610);
}

function vp3_cognitive_entity_graph_projection_v2610(
    PDO $pdo,array $user,string $namespace,array $explicitRefs=[],array $options=[]
): array {
    $uid=(int)($user['id']??0);if($uid<1)throw new RuntimeException('A signed-in VP3 user is required.');
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $maxDepth=max(0,min(VP3_COGNITIVE_ENTITY_GRAPH_MAX_DEPTH_V2610,(int)($options['max_depth']??VP3_COGNITIVE_ENTITY_GRAPH_MAX_DEPTH_V2610)));
    $seeds=vp3_cognitive_entity_graph_seed_nodes_v2610($pdo,$user,$namespace,$explicitRefs);
    $nodes=[];$edges=[];$queue=[];$degree=[];$diagnostics=['unresolved'=>[],'conflicts'=>[],'truncated'=>false];
    foreach($seeds as $node){
        $node['depth']=0;$node['seed']=true;$nodes[$node['key']]=$node;$queue[]=$node['key'];$degree[$node['key']]=0;
    }
    $expanded=[];
    while($queue&&count($nodes)<=VP3_COGNITIVE_ENTITY_GRAPH_MAX_NODES_V2610&&count($edges)<VP3_COGNITIVE_ENTITY_GRAPH_MAX_EDGES_V2610){
        $sourceKey=array_shift($queue);if(isset($expanded[$sourceKey]))continue;$expanded[$sourceKey]=true;
        $source=$nodes[$sourceKey]??null;if(!$source||((int)$source['depth'])>=$maxDepth)continue;
        $sourceRef=['type'=>$source['type'],'id'=>$source['id'],'scope'=>$source['scope']];
        if(!empty($source['workspace_id']))$sourceRef['workspace_id']=$source['workspace_id'];
        try{$rawEdges=vp3_cognitive_relationships_for_ref_v500($pdo,$user,$namespace,$sourceRef,$options);}catch(Throwable $e){$rawEdges=[];}
        foreach(array_slice($rawEdges,0,40) as $edge){
            if(!is_array($edge)||!is_array($edge['object_ref']??null))continue;
            $confirmation=(string)($edge['confirmation_state']??'deterministic');
            $relation=vp3_cognitive_entity_graph_relation_v2610($edge['relation']??'related_to');
            if($confirmation==='model_inferred'){
                $diagnostics['unresolved'][]=['reason'=>'model_inferred_not_merged','relation'=>$relation,'source_type'=>$source['type']];
                continue;
            }
            if(!in_array($confirmation,['deterministic','user_confirmed'],true)){
                $diagnostics['unresolved'][]=['reason'=>'unverified_relationship','relation'=>$relation,'source_type'=>$source['type']];
                continue;
            }
            $target=vp3_cognitive_entity_graph_normalize_ref_v2610($pdo,$user,$namespace,(array)$edge['object_ref']);
            if(!$target){
                $diagnostics['unresolved'][]=['reason'=>'unauthorized_or_stale_target','relation'=>$relation,'source_type'=>$source['type']];
                continue;
            }
            $targetKey=$target['key'];
            if(!isset($nodes[$targetKey])){
                if(count($nodes)>=VP3_COGNITIVE_ENTITY_GRAPH_MAX_NODES_V2610){$diagnostics['truncated']=true;break;}
                $target['depth']=(int)$source['depth']+1;$target['seed']=false;$nodes[$targetKey]=$target;$degree[$targetKey]=0;
                if($target['depth']<$maxDepth)$queue[]=$targetKey;
            }
            $edgeKey=hash('sha256',$sourceKey.'|'.$relation.'|'.$targetKey);
            if(isset($edges[$edgeKey]))continue;
            $edges[$edgeKey]=[
                'source_key'=>$sourceKey,'target_key'=>$targetKey,'relation'=>$relation,
                'cross_domain'=>(string)$source['domain']!==(string)$nodes[$targetKey]['domain'],
                'provenance'=>mb_strimwidth((string)($edge['provenance']??$source['module']),0,120,''),
                'confirmation_state'=>$confirmation,
            ];
            $degree[$sourceKey]=($degree[$sourceKey]??0)+1;$degree[$targetKey]=($degree[$targetKey]??0)+1;
            if(count($edges)>=VP3_COGNITIVE_ENTITY_GRAPH_MAX_EDGES_V2610){$diagnostics['truncated']=true;break 2;}
        }
    }
    $identityTargets=[];
    foreach($edges as $edge){
        if(!in_array($edge['relation'],['same_as','represents'],true))continue;
        $key=$edge['source_key'].'|'.$edge['relation'];$identityTargets[$key][$edge['target_key']]=true;
    }
    foreach($identityTargets as $key=>$targets)if(count($targets)>1)$diagnostics['conflicts'][]=['reason'=>'conflicting_verified_identity_links','link'=>$key,'target_count'=>count($targets)];
    $domainCounts=[];$crossDomain=0;
    foreach($nodes as $node){$domain=(string)($node['domain']?:$node['module']?:'unknown');$domainCounts[$domain]=($domainCounts[$domain]??0)+1;}
    foreach($edges as $edge)if(!empty($edge['cross_domain']))$crossDomain++;
    ksort($domainCounts);
    $orphans=0;foreach($seeds as $seed)if(($degree[$seed['key']]??0)===0)$orphans++;
    return [
        'contract'=>VP3_COGNITIVE_ENTITY_GRAPH_CONTRACT_V2610,
        'build'=>VP3_COGNITIVE_ENTITY_GRAPH_V2610,
        'ready'=>true,
        'seeds'=>array_values(array_map(static fn(array $n): array=>[
            'key'=>$n['key'],'domain'=>$n['domain'],'module'=>$n['module'],'type'=>$n['type'],'scope'=>$n['scope'],'seed_source'=>$n['seed_source']??''
        ],$seeds)),
        'nodes'=>array_values($nodes),
        'edges'=>array_values($edges),
        'counts'=>[
            'seeds'=>count($seeds),'nodes'=>count($nodes),'edges'=>count($edges),
            'domains'=>count($domainCounts),'cross_domain_edges'=>$crossDomain,
            'unresolved'=>count($diagnostics['unresolved']),'conflicts'=>count($diagnostics['conflicts']),'orphaned_seeds'=>$orphans,
        ],
        'domain_counts'=>$domainCounts,
        'diagnostics'=>$diagnostics,
        'authority'=>[
            'object_registry'=>'cognitive_runtime_v500',
            'domain_registry'=>'cognitive_domain_registry_v2600',
            'relationships'=>'registered_domain_relationship_providers',
            'event_seed_source'=>'agent_event_inbox_v1920_object_refs_only',
            'materialization'=>'ephemeral_read_only',
            'identity_merge_authority'=>false,
            'execution_authority'=>false,
        ],
        'privacy'=>[
            'raw_event_payloads_exposed'=>false,
            'contact_pii_copied'=>false,
            'model_inferred_identity_links_merged'=>false,
            'authorization_checked_each_hop'=>true,
        ],
        'generated_at'=>gmdate('c'),
    ];
}

function vp3_cognitive_entity_graph_context_item_v2610(
    PDO $pdo,array $user,string $namespace,array $explicitRefs=[]
): ?array {
    $graph=vp3_cognitive_entity_graph_projection_v2610($pdo,$user,$namespace,$explicitRefs);
    $counts=(array)($graph['counts']??[]);
    if((int)($counts['nodes']??0)<1)return null;
    $text='Verified entity context connects '.(int)$counts['nodes'].' authorized object(s) across '.(int)$counts['domains'].' domain(s) with '.(int)$counts['edges'].' relationship(s).';
    if((int)($counts['cross_domain_edges']??0)>0)$text.=' '.(int)$counts['cross_domain_edges'].' relationship(s) cross domain boundaries.';
    if((int)($counts['unresolved']??0)>0||(int)($counts['conflicts']??0)>0)$text.=' Ambiguous or unavailable links remain unresolved and are not merged.';
    return [
        'source'=>'cognitive-entity-graph:v2610','title'=>'Cross-domain entity graph','text'=>mb_strimwidth($text,0,700,'…'),
        'section'=>'entity_graph','authority'=>'cognitive_entity_graph_v2610_ephemeral_projection','trust'=>'data_only',
        'instruction_authority'=>false,'score'=>94.5,
        'meta'=>[
            'build'=>VP3_COGNITIVE_ENTITY_GRAPH_V2610,'node_count'=>(int)$counts['nodes'],'edge_count'=>(int)$counts['edges'],
            'domain_count'=>(int)$counts['domains'],'cross_domain_edges'=>(int)$counts['cross_domain_edges'],
            'unresolved_count'=>(int)$counts['unresolved'],'conflict_count'=>(int)$counts['conflicts'],
        ],
    ];
}

function vp3_cognitive_entity_graph_presentation_v2610(array $graph): array
{
    $counts=(array)($graph['counts']??[]);
    $issues=(int)($counts['unresolved']??0)+(int)($counts['conflicts']??0);
    $candidate=[
        'type'=>'entity_graph','title'=>'Connected context',
        'summary'=>(int)($counts['nodes']??0).' authorized object(s) across '.(int)($counts['domains']??0).' domain(s) with '.(int)($counts['cross_domain_edges']??0).' verified cross-domain relationship(s).',
        'status'=>$issues>0?'review':'current',
        'next_action'=>$issues>0?'Unresolved relationships remain separate until a deterministic or user-confirmed link is available.':'Use verified relationships as context without duplicating domain records.',
        'action_label'=>'Review connected context','href'=>'',
        'meta'=>['domain'=>'cross_domain','freshness'=>'current'],
    ];
    return function_exists('vp3_cognitive_presentation_firewall_validate_v2590')
        ?vp3_cognitive_presentation_firewall_validate_v2590($candidate)['presentation']
        :$candidate;
}

function vp3_cognitive_entity_graph_health_v2610(array $graph): array
{
    $counts=(array)($graph['counts']??[]);$diagnostics=(array)($graph['diagnostics']??[]);
    return [
        'ok'=>empty($diagnostics['conflicts']),
        'counts'=>$counts,
        'truncated'=>!empty($diagnostics['truncated']),
        'unresolved'=>array_values((array)($diagnostics['unresolved']??[])),
        'conflicts'=>array_values((array)($diagnostics['conflicts']??[])),
        'authority'=>'diagnostic_only',
    ];
}
