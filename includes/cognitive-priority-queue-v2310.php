<?php
declare(strict_types=1);

require_once __DIR__.'/agent-chat-intelligence-v171.php';

/**
 * VP3 Cognitive Operations v23.10 — Unified Agent Priority Queue.
 *
 * Projection only. Durable workflow state remains in Agent Work Queue v17.2 /
 * Work Control v17.3 / Dependencies v17.4 and execution remains Phase 19.
 */
const VP3_COGNITIVE_PRIORITY_QUEUE_V2310='vp3-cognitive-priority-queue-v2310-20260921';
const VP3_COGNITIVE_PRIORITY_QUEUE_CONTRACT_V2310='cognitive-priority-queue-v1';
const VP3_COGNITIVE_PRIORITY_QUEUE_MAX_ITEMS_V2310=12;

function vp3_cognitive_priority_queue_lanes_v2310(): array
{
    return ['needs_attention','next_up','priorities','opportunities','waiting','recent_changes'];
}

function vp3_cognitive_priority_queue_lane_label_v2310(string $lane): string
{
    return match($lane){
        'needs_attention'=>'Needs Attention',
        'next_up'=>'Next Up',
        'priorities'=>'Priorities',
        'opportunities'=>'Opportunities',
        'waiting'=>'Waiting',
        default=>'Recent Changes',
    };
}

function vp3_cognitive_priority_queue_workflow_lane_v2310(string $lane): string
{
    return match($lane){
        'approval','blocked','failed_retry'=>'needs_attention',
        'scheduled'=>'next_up',
        'paused'=>'waiting',
        'completed'=>'recent_changes',
        default=>'priorities',
    };
}

function vp3_cognitive_priority_queue_lane_v2310(array $candidate): string
{
    $workLane=vp3_cognitive_id_v500($candidate['work_queue_lane']??'',40);
    if($workLane!=='')return vp3_cognitive_priority_queue_workflow_lane_v2310($workLane);
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

function vp3_cognitive_priority_queue_authority_v2310(string $source): string
{
    if(function_exists('vp3_cognitive_operations_authority_v2300')){
        return vp3_cognitive_operations_authority_v2300($source);
    }
    return $source!==''?$source:'canonical_vp3_subsystem';
}

function vp3_cognitive_priority_queue_title_v2310(
    PDO $pdo,array $user,string $namespace,array $candidate
): array {
    $request=is_array($candidate['card_request']??null)?$candidate['card_request']:[];
    if(!$request)return ['title'=>'VP3 item','status'=>''];
    try{
        $card=vp3_cognitive_render_card_v500($pdo,$user,$namespace,$request);
        if(is_array($card)){
            return [
                'title'=>vp3_cognitive_text_v500($card['title']??'VP3 item',190),
                'status'=>vp3_cognitive_text_v500($card['status']??'',80),
            ];
        }
    }catch(Throwable $e){}
    $ref=is_array($request['object_ref']??null)?$request['object_ref']:[];
    $type=vp3_cognitive_id_v500($request['card_type']??$ref['type']??'',80);
    $id=vp3_cognitive_text_v500($ref['id']??'',80);
    return [
        'title'=>trim(ucwords(str_replace(['_','-'],' ',$type)).($id!==''?' #'.$id:''))?:'VP3 item',
        'status'=>'',
    ];
}

function vp3_cognitive_priority_queue_item_v2310(
    PDO $pdo,array $user,string $namespace,array $candidate
): ?array {
    $key=mb_strimwidth(trim((string)($candidate['key']??'')),0,190,'');
    $fingerprint=strtolower(trim((string)($candidate['fingerprint']??'')));
    if($key===''||!preg_match('/^[a-f0-9]{64}$/',$fingerprint))return null;

    $lane=vp3_cognitive_priority_queue_lane_v2310($candidate);
    if(!in_array($lane,vp3_cognitive_priority_queue_lanes_v2310(),true))$lane='recent_changes';

    $source=vp3_cognitive_id_v500($candidate['source']??'',80);
    $heading=vp3_cognitive_priority_queue_title_v2310($pdo,$user,$namespace,$candidate);
    $workPriority=max(0,min(100,(int)($candidate['work_priority']??0)));

    return [
        'key'=>$key,
        'fingerprint'=>$fingerprint,
        'lane'=>$lane,
        'lane_label'=>vp3_cognitive_priority_queue_lane_label_v2310($lane),
        'title'=>(string)$heading['title'],
        'status'=>(string)$heading['status'],
        'reason'=>vp3_cognitive_text_v500($candidate['reason']??'',360),
        'source'=>$source,
        'authority'=>vp3_cognitive_priority_queue_authority_v2310($source),
        'attention'=>!empty($candidate['attention'])||$lane==='needs_attention',
        'updated_at'=>vp3_cognitive_text_v500($candidate['updated_at']??'',64),
        '_feed_score'=>round(max(0,min(100,(float)($candidate['score']??0))),3),
        '_work_priority'=>$workPriority,
    ];
}

function vp3_cognitive_priority_queue_compose_v2310(
    PDO $pdo,array $user,string $namespace,array $selectedCandidates
): array {
    $namespace=vp3_cognitive_validate_namespace_v500($pdo,$user,$namespace);
    $items=[];$counts=array_fill_keys(vp3_cognitive_priority_queue_lanes_v2310(),0);

    foreach(array_slice($selectedCandidates,0,VP3_COGNITIVE_PRIORITY_QUEUE_MAX_ITEMS_V2310) as $candidate){
        if(!is_array($candidate)||!empty($candidate['hidden']))continue;
        $item=vp3_cognitive_priority_queue_item_v2310($pdo,$user,$namespace,$candidate);
        if(!$item)continue;
        $counts[$item['lane']]++;
        $items[]=$item;
    }

    $laneRank=array_flip(vp3_cognitive_priority_queue_lanes_v2310());
    usort($items,static function(array $a,array $b) use($laneRank): int {
        $lane=($laneRank[$a['lane']]??99)<=>($laneRank[$b['lane']]??99);
        if($lane!==0)return $lane;
        $attention=((int)!empty($b['attention']))<=>((int)!empty($a['attention']));
        if($attention!==0)return $attention;
        $feed=((float)$b['_feed_score'])<=>((float)$a['_feed_score']);
        if($feed!==0)return $feed;
        $work=((int)$b['_work_priority'])<=>((int)$a['_work_priority']);
        if($work!==0)return $work;
        return strcmp((string)$b['updated_at'],(string)$a['updated_at']);
    });

    foreach($items as &$item){unset($item['_feed_score'],$item['_work_priority'],$item['fingerprint']);}unset($item);

    return [
        'contract'=>VP3_COGNITIVE_PRIORITY_QUEUE_CONTRACT_V2310,
        'build'=>VP3_COGNITIVE_PRIORITY_QUEUE_V2310,
        'mode'=>'selected_feed_projection',
        'authority'=>[
            'item_set'=>'cognitive_feed_v530',
            'workflow_state'=>'agent_work_queue_v172',
            'workflow_controls'=>'agent_work_control_v173_and_dependencies_v174',
            'execution'=>'phase_19_existing_runtime',
            'automatic_external_writes'=>false,
            'approval_bypass'=>false,
        ],
        'lane_order'=>vp3_cognitive_priority_queue_lanes_v2310(),
        'counts'=>$counts,
        'item_count'=>count($items),
        'items'=>$items,
    ];
}
