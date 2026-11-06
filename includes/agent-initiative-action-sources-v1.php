<?php
declare(strict_types=1);

/** A local action may change its own exact sources, never waive source freshness.
 * The before witness is request-local and must be captured inside the same owner
 * transaction as the native mutation. Only hashes enter the durable receipt. */
function vp3_initiative_action_sources_hash_v1(mixed $value): string
{
    return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}

function vp3_initiative_action_sources_identity_v1(array $loaded,array $plan): array
{
    $goal=$loaded['goal']??null;$run=$loaded['run']??null;$state=$loaded['state']??null;
    if(!is_array($goal)||!is_array($run)||!is_array($state)||!is_array($goal['sources']??null)
        ||!$goal['sources']||count($goal['sources'])>5||!is_string($run['goal']??null)
        ||!hash_equals(hash('sha256',$run['goal']),(string)($state['goal_hash']??''))
        ||json_decode($run['goal'],true,32,JSON_THROW_ON_ERROR)!==$goal)
        throw new RuntimeException('Action original source binding changed.',409);
    foreach($goal['sources'] as $ref=>$revision)if(!is_string($ref)||!is_string($revision)
        ||!preg_match('/\A[a-f0-9]{64}\z/D',$revision))throw new RuntimeException('Action original sources changed.',409);
    return ['owner_user_id'=>(int)$loaded['user']['id'],'agent_id'=>$loaded['agent_id'],
        'conversation_id'=>$loaded['conversation_id'],'message_id'=>(int)$loaded['message']['id'],
        'run_id'=>(int)$run['id'],'goal_hash'=>$state['goal_hash'],'sources'=>$goal['sources'],
        'plan_hash'=>vp3_initiative_action_sources_hash_v1($plan),
        'original_result_hash'=>vp3_initiative_action_sources_hash_v1($loaded['result'])];
}

function vp3_initiative_action_sources_scope_v1(PDO $pdo,array $loaded): array
{
    require_once __DIR__.'/agent-initiative-capabilities-v1.php';
    return vp3_initiative_capability_scope_v1($pdo,['user'=>$loaded['user'],'principal'=>$loaded['principal'],
        'agent_id'=>$loaded['agent_id'],'conversation_id'=>$loaded['conversation_id'],'verification_only'=>true],$loaded['goal']['binding']);
}

/** Reuse the normal exact source verifier without re-entering the action loader.
 * The original readonly output is checked separately below, not discarded. */
function vp3_initiative_action_sources_evidence_v1(PDO $pdo,array $loaded,array $sources): array
{
    $goal=$loaded['goal'];$goal['sources']=$sources;$goal['tool']='work.prepare_private';
    $goal['capability_sources']=array_intersect_key(vp3_initiative_capability_saved_source_map_v1($loaded['goal']),$sources);
    return vp3_initiative_followthrough_sources_v1($pdo,$loaded['user'],$loaded['principal'],$goal,[]);
}

/** Actual native list rows preserve full unsampled members, not only previews. */
function vp3_initiative_action_sources_list_v1(PDO $pdo,array $scope,int $id): array
{
    require_once __DIR__.'/agent-initiative-task-lists-v1.php';
    $scope=vp3_initiative_task_list_scope_v1($pdo,$scope,$scope['binding']);
    $state=vp3_initiative_task_list_state_v1($pdo,$scope,$id);
    if(!$state)throw new RuntimeException('Action parent list is unavailable.',409);
    $q=$pdo->prepare('SELECT id,name FROM agent_task_lists_v1 WHERE id=? AND owner_user_id=? AND agent_scope_id=?');
    $q->execute([$id,(int)$scope['user']['id'],$scope['agent_id']]);$header=$q->fetch(PDO::FETCH_ASSOC);
    $q=$pdo->prepare('SELECT i.task_id,i.item_key,i.created_at,t.user_id,t.user_agent_id,t.subject,t.memory_text,t.memory_type,t.metadata_json,t.is_active
        FROM agent_task_list_items_v1 i JOIN agent_memory_items t ON t.id=i.task_id WHERE i.list_id=? ORDER BY i.task_id LIMIT 201');
    $q->execute([$id]);$members=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($members)>200)throw new RuntimeException('Action parent list exceeds its complete read bound.',503);
    return ['header'=>$header,'members'=>$members,'task_refs'=>array_column($state['members'],'task_ref')];
}

/** Capture the original tool's real readonly bytes, including remote/privacy checks. */
function vp3_initiative_action_sources_read_v1(PDO $pdo,array $loaded,array $evidence,array $removed=[]): ?array
{
    $goal=$loaded['goal'];$tool=$goal['tool']??'work.prepare_private';$scope=vp3_initiative_action_sources_scope_v1($pdo,$loaded);
    if(vp3_initiative_capability_definition_v1((string)$tool)){
        $refs=array_values(array_diff(array_keys($goal['sources']),$removed));$primary=$goal['primary'];
        if(in_array($primary,$removed,true)){
            $primary=null;foreach($refs as $ref)if(($evidence[$ref]['capability_tool']??null)===$tool){$primary=$ref;break;}
        }
        // A removed native primary is represented by the inspected tombstone,
        // never fed back to a reader that only admits active records.
        if($primary===null)return ['records'=>[],'sources'=>[],'coverage'=>[]];
        return vp3_initiative_capability_snapshot_v1($pdo,$scope,$goal['binding'],$tool,
            ['tool'=>$tool,'primary'=>$primary,'refs'=>$refs,'focus'=>$goal['focus']??'','report'=>$goal['report']??false],$evidence);
    }
    if(in_array($tool,['brain.read','knowledge.search'],true)){
        if($tool==='knowledge.search'){
            $policy=vp3_initiative_policy_v1($loaded['user'],$loaded['agent_id']);
            if(vp3_initiative_knowledge_scope_v1($goal['binding']['knowledge_scope']??[])!==vp3_initiative_knowledge_scope_v1($policy['knowledge_scope']??[]))
                throw new RuntimeException('Action Knowledge permission changed.',403);
        }
        return ['records'=>vp3_initiative_tools_records_v1($pdo,$scope,$goal['binding'],$tool,(string)($goal['focus']??'')),
            'sources'=>[],'coverage'=>[]];
    }
    return null;
}

function vp3_initiative_action_sources_prepare_in_scope_v1(PDO $pdo,array $loaded,array $plan): array
{
    if(!$pdo->inTransaction())throw new RuntimeException('Action sources require the owner transaction.',409);
    $identity=vp3_initiative_action_sources_identity_v1($loaded,$plan);
    $evidence=vp3_initiative_followthrough_sources_v1($pdo,$loaded['user'],$loaded['principal'],$loaded['goal'],$loaded['result']);
    $scope=vp3_initiative_action_sources_scope_v1($pdo,$loaded);$scope['binding']=$loaded['goal']['binding'];$lists=[];
    foreach(array_keys($loaded['goal']['sources']) as $ref)if(preg_match('/\Atask-list:([1-9][0-9]*)\z/D',$ref,$m))
        $lists[$ref]=vp3_initiative_action_sources_list_v1($pdo,$scope,(int)$m[1]);
    return ['version'=>1,'connection'=>spl_object_id($pdo),'identity'=>$identity,'evidence'=>$evidence,'lists'=>$lists,
        'read'=>vp3_initiative_action_sources_read_v1($pdo,$loaded,$evidence)];
}

/** Only native inspected targets may transition. Calendar series mutations cover
 * the original selected occurrences of that exact same record, not other events. */
function vp3_initiative_action_sources_targets_v1(PDO $pdo,array $loaded,array $plan,array $result,array $parents=[]): array
{
    $adapter=$plan['adapter']??'';$binding=['owner_user_id'=>(int)$loaded['user']['id'],
        'agent_id'=>$loaded['agent_id'],'conversation_id'=>$loaded['conversation_id']];
    if(in_array($adapter,['task.create','task.set_status','task_list.create','task_list.add'],true)){
        require_once __DIR__.'/agent-initiative-office-actions-v1.php';
        vp3_initiative_office_action_inspect_v1($pdo,$loaded['user'],$loaded['principal'],$binding,$plan,$result);
        $native=$result['office_action'];$task=$native['task_id'];$list=$native['list_id'];$allowed=[];
        foreach(array_keys($loaded['goal']['sources']) as $ref){
            if($adapter==='task.set_status'&&$ref==='task:'.$task)$allowed[$ref]=true;
            if($list&&$ref==='task-list:'.$list)$allowed[$ref]=true;
            if($adapter==='task.set_status'&&in_array($ref,$parents,true)&&preg_match('/\Atask-list:([1-9][0-9]*)\z/D',$ref,$m)){
                $scope=vp3_initiative_action_sources_scope_v1($pdo,$loaded);$scope['binding']=$loaded['goal']['binding'];
                $state=vp3_initiative_action_sources_list_v1($pdo,$scope,(int)$m[1]);
                if(in_array('task:'.$task,$state['task_refs'],true))$allowed[$ref]=true;
            }
        }
        return ['refs'=>array_keys($allowed),'task_ids'=>$task?[$task]:[]];
    }
    if(!is_string($adapter)||!preg_match('/\A(reminder|calendar)\.(create|update|complete|snooze|dismiss|cancel|delete)\z/D',$adapter,$m))
        throw new RuntimeException('Action source adapter is unavailable.',409);
    require_once __DIR__.'/agent-initiative-schedule-actions-v1.php';
    vp3_initiative_schedule_action_inspect_v1($pdo,$loaded['user'],$loaded['principal'],$binding,$plan,$result);
    $allowed=[];$id=$result['record_id'];
    if($m[2]!=='create')foreach(array_keys($loaded['goal']['sources']) as $ref){
        $selector=vp3_initiative_office_ref_v1($ref);
        if($selector&&$selector['family']===$m[1]&&$selector['id']===$id)$allowed[]=$ref;
    }
    return ['refs'=>$allowed,'task_ids'=>[]];
}

function vp3_initiative_action_sources_strip_v1(?array $read,array $refs,array $taskIds): ?array
{
    if($read===null)return null;$ignored=array_fill_keys($refs,true);
    foreach($taskIds as $id)$ignored['brain:'.$id]=true;
    foreach(['records','sources'] as $key)$read[$key]=array_values(array_filter($read[$key],static fn(array $row):bool=>!isset($ignored[$row['source']??''])));
    $read['coverage']=array_diff_key($read['coverage'],$ignored);return $read;
}

/** Read allowed native sources through their originally admitted read paths. */
function vp3_initiative_action_sources_after_v1(PDO $pdo,array $loaded,array $refs,array $result): array
{
    $scope=vp3_initiative_action_sources_scope_v1($pdo,$loaded);$goal=$loaded['goal'];
    $map=vp3_initiative_capability_saved_source_map_v1($goal);$capRefs=array_values(array_intersect($refs,array_keys($map)));
    $coverage=[];$after=$capRefs?vp3_initiative_capabilities_evidence_v1($pdo,$scope,$goal['binding'],new DateTimeImmutable('now',new DateTimeZone('UTC')),$capRefs,$coverage):[];
    $basic=array_values(array_diff($refs,$capRefs));
    if($basic){
        $policy=vp3_initiative_policy_v1($loaded['user'],$loaded['agent_id']);
        $screen=!empty($goal['binding']['screen_enabled'])&&!empty($policy['screen_enabled'])&&$loaded['agent_id']===0;
        $after+=vp3_initiative_evidence_v1($pdo,$loaded['user'],new DateTimeImmutable('now',new DateTimeZone('UTC')),$screen,[],$coverage,$basic,$loaded['agent_id']);
    }
    $evidence=[];$revisions=[];$removed=[];
    foreach($refs as $ref){
        if(isset($after[$ref])){$evidence[$ref]=$after[$ref];$revisions[$ref]=$after[$ref]['revision'];continue;}
        // Inspectors above prove completed/cancelled/deleted records and enforce
        // live account, namespace and record authority. An arbitrary missing
        // source or missing list is never treated as an own-effect tombstone.
        if(str_starts_with($ref,'task-list:'))throw new RuntimeException('Action parent list disappeared.',409);
        $office=$result['office_action']??null;$isTask=is_array($office)&&($office['adapter']??'')==='task.set_status'
            &&$ref==='task:'.($office['task_id']??0)&&in_array($office['after']['task']['status']??'', ['completed','cancelled'],true);
        $selector=vp3_initiative_office_ref_v1($ref);
        if($isTask)$isTask=$selector&&vp3_initiative_office_state_v1($pdo,$scope,$selector)===null;
        $family=($result['tool_receipt']['capability']??'')==='reminders.manage'?'reminder':((($result['tool_receipt']['capability']??'')==='calendar.manage')?'calendar':null);
        // An entire recurring series can move a second selected occurrence out
        // of its old identity. It must be the inspected exact native record,
        // and its active canonical reader must itself confirm the absence.
        $schedule=$selector&&$selector['family']===$family&&$selector['id']===($result['record_id']??null)
            &&is_string($result['effect_hash']??null)
            &&vp3_initiative_office_state_v1($pdo,$scope,$selector)===null;
        if(!$isTask&&!$schedule)throw new RuntimeException('Action source verification is unavailable.',409);
        $removed[]=$ref;$revisions[$ref]=null;
        $evidence[$ref]=['ref'=>$ref,'kind'=>'action_tombstone','title'=>$loaded['evidence'][$ref]['title']??'Completed source',
            'detail'=>'The exact canonical action is verified; this source is no longer active.',
            'url'=>'/chat.php','revision'=>vp3_initiative_action_sources_hash_v1(['action-tombstone-v1',$ref,$result])];
    }
    return ['evidence'=>$evidence,'revisions'=>$revisions,'removed'=>$removed];
}

/** Called immediately after the native effect, before its canonical receipt. */
function vp3_initiative_action_sources_capture_in_scope_v1(PDO $pdo,array $loaded,array $plan,array $domainResult,?array $before=null): array
{
    if(!$pdo->inTransaction())throw new RuntimeException('Action sources require the owner transaction.',409);
    $before??=$loaded['action_sources_before']??null;$identity=vp3_initiative_action_sources_identity_v1($loaded,$plan);
    if(!is_array($before)||($before['version']??null)!==1||($before['connection']??null)!==spl_object_id($pdo)
        ||($before['identity']??null)!==$identity)throw new RuntimeException('Action before-source witness is unavailable.',409);
    $parents=[];$task=$domainResult['office_action']['task_id']??0;
    if(($plan['adapter']??'')==='task.set_status')foreach($before['lists'] as $ref=>$list)
        if(in_array('task:'.$task,$list['task_refs'],true))$parents[]=$ref;
    $targets=vp3_initiative_action_sources_targets_v1($pdo,$loaded,$plan,$domainResult,$parents);$refs=$targets['refs'];
    $unaffected=array_diff_key($loaded['goal']['sources'],array_fill_keys($refs,true));
    $evidence=vp3_initiative_action_sources_evidence_v1($pdo,$loaded,$unaffected);
    $after=vp3_initiative_action_sources_after_v1($pdo,$loaded,$refs,$domainResult);$evidence+=$after['evidence'];
    $scope=vp3_initiative_action_sources_scope_v1($pdo,$loaded);$scope['binding']=$loaded['goal']['binding'];
    foreach($before['lists'] as $ref=>$list){
        if(!in_array($ref,$refs,true))continue;
        $current=vp3_initiative_action_sources_list_v1($pdo,$scope,(int)substr($ref,10));
        foreach(['members'] as $key){
            $strip=static fn(array $rows):array=>array_values(array_filter($rows,static fn(array $row):bool=>!in_array((int)$row['task_id'],$targets['task_ids'],true)));
            $list[$key]=$strip($list[$key]);$current[$key]=$strip($current[$key]);
        }
        $ignore=array_map(static fn(int $id):string=>'task:'.$id,$targets['task_ids']);
        $list['task_refs']=array_values(array_diff($list['task_refs'],$ignore));
        $current['task_refs']=array_values(array_diff($current['task_refs'],$ignore));
        if($list!==$current)throw new RuntimeException('An unrelated list member changed during the action.',409);
    }
    $read=vp3_initiative_action_sources_read_v1($pdo,$loaded,$evidence,$after['removed']);
    if(vp3_initiative_action_sources_strip_v1($before['read'],$refs,$targets['task_ids'])!==vp3_initiative_action_sources_strip_v1($read,$refs,$targets['task_ids']))
        throw new RuntimeException('Unrelated readonly evidence changed during the action.',409);
    // Non-read tool proofs still use their original verifier. Only revisions of
    // exact native targets are replaced; no global freshness bypass exists.
    if($read===null){$goal=$loaded['goal'];$goal['sources']=array_replace($unaffected,array_filter($after['revisions'],static fn($r):bool=>$r!==null));
        vp3_initiative_followthrough_sources_v1($pdo,$loaded['user'],$loaded['principal'],$goal,$loaded['result']);}
    return ['version'=>1,'identity'=>$identity,'domain_hash'=>vp3_initiative_action_sources_hash_v1($domainResult),
        'affected_refs'=>$refs,'parent_refs'=>$parents,'after_revisions'=>$after['revisions'],'removed_refs'=>$after['removed'],
        'read_hash'=>$read===null?null:vp3_initiative_action_sources_hash_v1($read)];
}

/** Receipt identity is verified by the broker before this readonly recheck. */
function vp3_initiative_action_sources_current_in_scope_v1(PDO $pdo,array $loaded,array $plan,array $domainResult,array $proof): array
{
    $identity=vp3_initiative_action_sources_identity_v1($loaded,$plan);
    if(($proof['version']??null)!==1||($proof['identity']??null)!==$identity
        ||($proof['domain_hash']??null)!==vp3_initiative_action_sources_hash_v1($domainResult))throw new RuntimeException('Action source receipt changed.',409);
    $parents=$proof['parent_refs']??null;
    if(!is_array($parents)||!array_is_list($parents)||count($parents)>5||count($parents)!==count(array_unique($parents)))throw new RuntimeException('Action parent sources changed.',409);
    foreach($parents as $ref)if(!is_string($ref)||!isset($loaded['goal']['sources'][$ref])||!preg_match('/\Atask-list:[1-9][0-9]*\z/D',$ref))throw new RuntimeException('Action parent sources changed.',409);
    $targets=vp3_initiative_action_sources_targets_v1($pdo,$loaded,$plan,$domainResult,$parents);
    if(($proof['affected_refs']??null)!==$targets['refs'])throw new RuntimeException('Action source targets changed.',409);
    $unaffected=array_diff_key($loaded['goal']['sources'],array_fill_keys($targets['refs'],true));
    $evidence=vp3_initiative_action_sources_evidence_v1($pdo,$loaded,$unaffected);
    $after=vp3_initiative_action_sources_after_v1($pdo,$loaded,$targets['refs'],$domainResult);$evidence+=$after['evidence'];
    if(($proof['after_revisions']??null)!==$after['revisions']||($proof['removed_refs']??null)!==$after['removed'])throw new RuntimeException('Action saved source outcome changed.',409);
    $read=vp3_initiative_action_sources_read_v1($pdo,$loaded,$evidence,$after['removed']);
    if(($proof['read_hash']??null)!==($read===null?null:vp3_initiative_action_sources_hash_v1($read)))throw new RuntimeException('Action saved readonly evidence changed.',409);
    if($read===null){$goal=$loaded['goal'];$goal['sources']=array_replace($unaffected,array_filter($after['revisions'],static fn($r):bool=>$r!==null));
        vp3_initiative_followthrough_sources_v1($pdo,$loaded['user'],$loaded['principal'],$goal,$loaded['result']);}
    return $evidence;
}

/** These entry points may run after the loader has restored its caller's Brain
 * namespace. Always select the exact original owner/Agent for every source and
 * readonly record read; never rely on whichever global scope the caller had. */
function vp3_initiative_action_sources_prepare_v1(PDO $pdo,array $loaded,array $plan): array
{
    return vp3_initiative_in_scope_v1($loaded['user'],$loaded['agent_id'],
        static fn():array=>vp3_initiative_action_sources_prepare_in_scope_v1($pdo,$loaded,$plan));
}

function vp3_initiative_action_sources_capture_v1(PDO $pdo,array $loaded,array $plan,array $domainResult,?array $before=null): array
{
    return vp3_initiative_in_scope_v1($loaded['user'],$loaded['agent_id'],
        static fn():array=>vp3_initiative_action_sources_capture_in_scope_v1($pdo,$loaded,$plan,$domainResult,$before));
}

function vp3_initiative_action_sources_current_v1(PDO $pdo,array $loaded,array $plan,array $domainResult,array $proof): array
{
    return vp3_initiative_in_scope_v1($loaded['user'],$loaded['agent_id'],
        static fn():array=>vp3_initiative_action_sources_current_in_scope_v1($pdo,$loaded,$plan,$domainResult,$proof));
}
