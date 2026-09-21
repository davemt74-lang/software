<?php
declare(strict_types=1);

/**
 * VP3 Cognitive Operations Core v23.00.
 *
 * Read-only projection across the existing Cognitive Runtime / Brain / Feed.
 * This file deliberately owns no persistence, execution, approval, worker,
 * scheduler or model authority.
 */
const VP3_COGNITIVE_OPERATIONS_V2300='vp3-cognitive-operations-v2300-20260921';
const VP3_COGNITIVE_OPERATIONS_CONTRACT_V2300='cognitive-operations-v1';
const VP3_COGNITIVE_OPERATIONS_MAX_ITEMS_V2300=20;

function vp3_cognitive_operations_lanes_v2300(): array
{
    return ['needs_attention','next_up','priorities','opportunities','waiting','recent_changes'];
}

function vp3_cognitive_operations_lane_v2300(array $candidate): string
{
    if(!empty($candidate['attention']))return 'needs_attention';
    return match((string)($candidate['section']??'')){
        'attention'=>'needs_attention',
        'next_up'=>'next_up',
        'priorities'=>'priorities',
        'opportunities'=>'opportunities',
        'waiting'=>'waiting',
        default=>'recent_changes',
    };
}

function vp3_cognitive_operations_authority_v2300(string $source): string
{
    return match($source){
        'meeting'=>'meetings',
        'calendar'=>'user_calendar',
        'workflow'=>'agent_workflows',
        'goal'=>'agent_goals',
        'agent_brain'=>'agent_brain',
        'notification'=>'notifications',
        'cognitive_observation'=>'cognitive_runtime',
        'cognitive_plan'=>'cognitive_planning',
        'cognitive_orchestration'=>'cognitive_orchestration',
        'cognitive_memory'=>'cognitive_memory',
        'browser_research'=>'research',
        'browser_transaction','transaction_intelligence'=>'browser_transaction_runtime',
        default=>$source!==''?$source:'canonical_vp3_subsystem',
    };
}

function vp3_cognitive_operations_work_item_v2300(array $candidate): ?array
{
    $key=mb_strimwidth(trim((string)($candidate['key']??'')),0,190,'');
    $fingerprint=strtolower(trim((string)($candidate['fingerprint']??'')));
    if($key===''||!preg_match('/^[a-f0-9]{64}$/',$fingerprint))return null;

    $source=vp3_cognitive_id_v500($candidate['source']??'',80);
    $request=is_array($candidate['card_request']??null)?$candidate['card_request']:[];
    $ref=is_array($request['object_ref']??null)?$request['object_ref']:null;
    if(is_array($ref)){
        try{$ref=vp3_cognitive_validate_object_ref_v500($ref,true);}
        catch(Throwable $e){$ref=null;}
    }

    $actions=[];
    foreach(array_slice((array)($candidate['planning_action_ids']??[]),0,12) as $action){
        $action=vp3_cognitive_id_v500($action,120);
        if($action!==''&&!in_array($action,$actions,true))$actions[]=$action;
    }

    $requiresApproval=false;
    if($actions){
        $registry=vp3_cognitive_registry_storage_v500();
        foreach($actions as $action){
            if(!empty($registry['tools'][$action]['requires_approval'])){$requiresApproval=true;break;}
        }
    }

    return [
        'key'=>$key,
        'fingerprint'=>$fingerprint,
        'lane'=>vp3_cognitive_operations_lane_v2300($candidate),
        'source'=>$source,
        'authority'=>vp3_cognitive_operations_authority_v2300($source),
        '_rank_score'=>round(max(0,min(100,(float)($candidate['score']??0))),3),
        'reason'=>vp3_cognitive_text_v500($candidate['reason']??'',420),
        'object_ref'=>$ref,
        'proposed_action_ids'=>$actions,
        'requires_approval'=>$requiresApproval,
        'attention'=>!empty($candidate['attention']),
        'updated_at'=>vp3_cognitive_text_v500($candidate['updated_at']??'',64),
        'execution_boundary'=>[
            'mode'=>'existing_runtime_only',
            'model_may_execute'=>false,
            'automatic_external_writes'=>false,
            'existing_authority_required'=>true,
            'approval_when_required_by_existing_runtime'=>true,
        ],
    ];
}

function vp3_cognitive_operations_public_v2300(array $state): array
{
    return [
        'contract'=>(string)($state['contract']??VP3_COGNITIVE_OPERATIONS_CONTRACT_V2300),
        'build'=>(string)($state['build']??VP3_COGNITIVE_OPERATIONS_V2300),
        'mode'=>'projection_summary',
        'all_systems_listening'=>is_array($state['all_systems_listening']??null)?$state['all_systems_listening']:[],
        'ranking'=>is_array($state['ranking']??null)?$state['ranking']:[],
        'execution'=>is_array($state['execution']??null)?$state['execution']:[],
        'work_item_count'=>max(0,(int)($state['work_item_count']??0)),
        'attention_count'=>max(0,(int)($state['attention_count']??0)),
        'plan_count'=>max(0,(int)($state['plan_count']??0)),
        'lane_counts'=>is_array($state['lane_counts']??null)?$state['lane_counts']:[],
    ];
}

function vp3_cognitive_operations_compose_v2300(array $candidates): array
{
    $items=[];$sources=[];$authorities=[];$laneCounts=array_fill_keys(vp3_cognitive_operations_lanes_v2300(),0);
    $attention=0;$plans=0;

    foreach($candidates as $candidate){
        if(!is_array($candidate)||!empty($candidate['hidden']))continue;
        $item=vp3_cognitive_operations_work_item_v2300($candidate);
        if(!$item)continue;
        $sources[$item['source']?:'vp3']=true;
        $authorities[$item['authority']]=true;
        $laneCounts[$item['lane']]=($laneCounts[$item['lane']]??0)+1;
        if($item['attention'])$attention++;
        if(in_array((string)($candidate['source']??''),['cognitive_plan','cognitive_orchestration'],true))$plans++;
        if(count($items)<VP3_COGNITIVE_OPERATIONS_MAX_ITEMS_V2300)$items[]=$item;
    }

    usort($items,static function(array $a,array $b): int {
        $laneOrder=['needs_attention'=>0,'next_up'=>1,'priorities'=>2,'opportunities'=>3,'waiting'=>4,'recent_changes'=>5];
        $lane=($laneOrder[$a['lane']]??9)<=>($laneOrder[$b['lane']]??9);
        if($lane!==0)return $lane;
        $score=((float)$b['_rank_score'])<=>((float)$a['_rank_score']);
        return $score!==0?$score:strcmp((string)$b['updated_at'],(string)$a['updated_at']);
    });

    foreach($items as &$item)unset($item['_rank_score']);unset($item);

    $sourceList=array_keys($sources);sort($sourceList,SORT_STRING);
    $authorityList=array_keys($authorities);sort($authorityList,SORT_STRING);

    return [
        'contract'=>VP3_COGNITIVE_OPERATIONS_CONTRACT_V2300,
        'build'=>VP3_COGNITIVE_OPERATIONS_V2300,
        'mode'=>'projection_only',
        'all_systems_listening'=>[
            'state'=>'active',
            'ingress'=>'existing_agent_event_infrastructure',
            'cognitive_contract'=>defined('VP3_COGNITIVE_CONTRACT_V500')?VP3_COGNITIVE_CONTRACT_V500:'cognitive-runtime-v1',
            'source_count'=>count($sourceList),
            'sources'=>$sourceList,
            'authorities'=>$authorityList,
        ],
        'ranking'=>[
            'authority'=>'existing_cognitive_feed_and_agent_brain',
            'independent_v2300_ranking'=>false,
        ],
        'execution'=>[
            'authority'=>'existing_execution_runtimes',
            'model_may_execute'=>false,
            'automatic_external_writes'=>false,
            'approval_bypass'=>false,
            'authority_bypass'=>false,
        ],
        'work_item_count'=>count($items),
        'attention_count'=>$attention,
        'plan_count'=>$plans,
        'lane_counts'=>$laneCounts,
        'items'=>$items,
    ];
}
