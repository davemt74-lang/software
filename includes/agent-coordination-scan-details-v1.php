<?php
declare(strict_types=1);

require_once __DIR__.'/agent-coordination-rules-v1.php';

const VP3_COORDINATION_SCAN_DISPOSITIONS_V1=[
    'queued','existing_handoff','handoff_pending','already_handled','dedupe_conflicts','handoff_failed','handoff_cancelled','handoff_paused',
    'quiet_chat_required','owner_turn_pending','recent_proactive_post','playing','cooldown','new_activity_required',
    'already_offered','route_unavailable','transcript_history','transcript_pacing','next_scan','observe_only',
];

/** References are used only while selecting work. Neither the checkpoint nor Debug keeps them. */
function vp3_coordination_scan_matches_v1(array $routes,string $reason='next_scan'): array
{
    $matches=[];
    foreach($routes as $ref=>$route){
        if(!in_array($route['action']??null,['ask','report'],true))continue;
        $matches[$ref]=['specialist'=>$route['specialist'],'trigger'=>$route['trigger'],'action'=>$route['action'],'reason'=>$reason];
    }
    return $matches;
}

function vp3_coordination_scan_mark_v1(array &$matches,string $ref,string $reason): void
{
    if(!isset($matches[$ref])||$matches[$ref]['reason']==='queued'
        ||!in_array($reason,VP3_COORDINATION_SCAN_DISPOSITIONS_V1,true))return;
    $matches[$ref]['reason']=$reason;
}

/** A stored checkpoint can only expose public rule names and closed diagnostic codes. */
function vp3_coordination_scan_disposition_rows_v1(string $family,mixed $rows): array
{
    if(!is_array($rows)||!array_is_list($rows))return [];
    $rules=vp3_coordination_rule_definitions_v1()[$family]['triggers']??[];
    if($family==='media')$rules['idle_invitation']=['actions'=>['ask']];
    $safe=[];
    foreach(array_slice($rows,0,120) as $row){
        if(!is_array($row))continue;
        $trigger=$row['trigger']??null;$action=$row['action']??null;$reason=$row['reason']??null;
        if(!is_string($trigger)||!isset($rules[$trigger])||!in_array($action,['ask','report'],true)
            ||(isset($rules[$trigger]['actions'])&&!in_array($action,$rules[$trigger]['actions'],true))
            ||!in_array($reason,VP3_COORDINATION_SCAN_DISPOSITIONS_V1,true))continue;
        $safe[]=['trigger'=>$trigger,'action'=>$action,'reason'=>$reason];
    }
    return $safe;
}

function vp3_coordination_scan_dispositions_v1(array $matches): array
{
    $groups=[];
    foreach($matches as $match)$groups[$match['specialist']][]=$match;
    foreach($groups as $family=>$rows)$groups[$family]=vp3_coordination_scan_disposition_rows_v1($family,$rows);
    return $groups;
}
