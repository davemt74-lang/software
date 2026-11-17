<?php
declare(strict_types=1);

require_once __DIR__.'/agent-coordination-planning-material-v1.php';

function vp3_coordination_material_hash_v1(mixed $value): bool
{
    return is_string($value)&&preg_match('/\A[a-f0-9]{64}\z/D',$value)===1;
}

/** Use native finding facts when the specialist can prove them. Exact source revisions still guard actions. */
function vp3_coordination_material_v1(array $item,array $items,bool $includePlanning): ?array
{
    $native=($item['specialist']??null)==='planning'?vp3_coordination_planning_material_v1($item,$items):($item['material']??null);
    if(!vp3_coordination_material_hash_v1($native['key']??null)||!vp3_coordination_material_hash_v1($native['revision']??null))return null;
    $planning=[];
    if($includePlanning&&$item['specialist']==='gmail'){
        $planning=vp3_coordination_planning_conflict_for_gmail_v1($item,$items)??[];
    }
    $observed=$native['observed_at']??0;
    return ['key'=>$native['key'],'revision'=>vp3_coordination_source_hash_v1([$native['revision'],$planning['revision']??null]),
        'observed_at'=>is_int($observed)&&$observed>0?$observed:0,
        'planning_refs'=>$planning['related_refs']??($item['specialist']==='planning'
            ?array_values(array_diff($native['related_refs']??[],[$item['ref']])):[])];
}

/** A proven successor supplies the current evidence; older copies remain available to their specialist. */
function vp3_coordination_material_discovery_v1(array $items,array $routes): array
{
    $material=[];$current=[];$covered=[];
    foreach($items as $ref=>$item){
        $route=$routes[$ref];
        if(!vp3_coordination_chat_route_v1($route))continue;
        if(!empty($item['legacy_delivered']))continue;
        $proof=vp3_coordination_material_v1($item,$items,vp3_coordination_gmail_ask_v1($route));
        if($proof===null)continue;
        $material[$ref]=$proof;
        if($item['specialist']!=='gmail'||$proof['observed_at']===0)continue;
        $key=$proof['key'];
        $prior=$current[$key]??null;
        if($prior===null||[$proof['observed_at'],$ref]>[$material[$prior]['observed_at'],$prior])$current[$key]=$ref;
    }
    foreach($material as $ref=>$proof){
        if(isset($current[$proof['key']])&&$current[$proof['key']]!==$ref){
            $covered[$ref]=true;
            continue;
        }
        foreach($items[$ref]['specialist']==='gmail'?$proof['planning_refs']:[] as $planningRef){
            $route=$routes[$planningRef]??[];
            if(($route['trigger']??null)==='conflict'&&vp3_coordination_chat_route_v1($route))$covered[$planningRef]=true;
        }
    }
    return [$material,$covered];
}

function vp3_coordination_material_same_matter_v1(array $goal,array $priorGoal): bool
{
    $current=$goal['coordination_material']??null;
    $prior=$priorGoal['coordination_material']??null;
    return vp3_coordination_material_hash_v1($current['key']??null)&&$current['key']===($prior['key']??null)
        &&($goal['coordination_route']['action']??null)===($priorGoal['coordination_route']['action']??null)
        &&!isset($priorGoal['followthrough'])&&!isset($priorGoal['coordination_work']);
}

function vp3_coordination_material_same_v1(array $goal,array $priorGoal): bool
{
    $revision=$goal['coordination_material']['revision']??null;
    return vp3_coordination_material_same_matter_v1($goal,$priorGoal)
        &&vp3_coordination_material_hash_v1($revision)&&$revision===($priorGoal['coordination_material']['revision']??null);
}

/** A completed receipt and its exact message are the only proof that Chat already handled a version. */
function vp3_coordination_material_previous_chat_v1(PDO $pdo,array $user,array $goal,array $run,array $result): ?array
{
    $prior=json_decode((string)$run['goal'],true);
    if(!is_array($prior)||!vp3_coordination_material_same_matter_v1($goal,$prior))return null;
    $work=$result['initiative_tool_result']['output']??[];
    if(array_diff($prior['coordination_material']['planning_refs']??[],array_keys($work['evidence_revisions']??[])))return null;
    $post=null;
    return vp3_coordination_chat_handoff_proof_v1($pdo,$user,$prior['binding'],$run,$result,null,$post)==='missing'?null:$post;
}

function vp3_coordination_material_current_v1(array $goal,array $evidence): void
{
    if(!isset($goal['coordination_material'])||isset($goal['followthrough'])||isset($goal['coordination_work']))return;
    $primary=$evidence[$goal['primary']]??[];
    $proof=vp3_coordination_material_v1($primary,$evidence,vp3_coordination_gmail_ask_v1($goal['coordination_route']??[]));
    $saved=$goal['coordination_material'];
    // Receiving an exact copy can advance the mailbox timestamp without changing the bound evidence.
    unset($proof['observed_at'],$saved['observed_at']);
    if($proof!==$saved)throw new Vp3InitiativeSourcesChangedV1();
}

/** A newer verified Gmail thread can make a prepared, undelivered version obsolete. */
function vp3_coordination_material_gmail_changed_v1(PDO $pdo,array $user,array $goal,array $item): bool
{
    $native=$item['material']??null;
    if(($item['specialist']??null)!=='gmail'||!vp3_coordination_material_hash_v1($native['group_key']??null))return false;
    $live=vp3_coordination_sources_v1($pdo,$user,$goal['binding'],specialistOnly:'gmail');
    foreach($live['items'] as $ref=>$candidate){
        $next=$candidate['material']??null;
        if($ref!==$item['ref']&&($next['group_key']??null)===$native['group_key']
            &&($next['observed_at']??0)>($native['observed_at']??0)&&$next['revision']!==$native['revision'])return true;
    }
    return false;
}

/** A newer verified decision for this same situation replaces its older open question. */
function vp3_coordination_material_superseded_v1(PDO $pdo,array $user,array $goal,array $result): bool
{
    $material=$goal['coordination_material']??null;
    $message=filter_var($result['initiative_message_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!vp3_coordination_material_hash_v1($material['key']??null)||$message===false
        ||($result['initiative_tool_result']['output']['state']??null)!=='needs_owner')return false;
    foreach(vp3_coordination_runs_v1($pdo,$user,$goal['binding'],$goal['coordination_id'],18) as $row){
        $later=json_decode((string)$row['goal'],true);
        if(!is_array($later)||($later['coordination_material']['key']??null)!==$material['key']
            ||vp3_coordination_material_same_v1($goal,$later)||isset($later['followthrough'])||isset($later['coordination_work']))continue;
        $proof=vp3_coordination_verified_run_v1($pdo,$user,$row);
        $laterMessage=filter_var($proof['initiative_message_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($laterMessage!==false&&$laterMessage>$message
            &&vp3_coordination_chat_projection_v1($pdo,$user,$later['binding'],$row,$proof)!==null)return true;
    }
    return false;
}
