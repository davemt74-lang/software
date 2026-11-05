<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-scope-v1.php';
require_once __DIR__.'/agent-memory-scope-v410.php';
require_once __DIR__.'/agent-task-lists-v1.php';
require_once __DIR__.'/agent-activity-situations-v1.php';

const VP3_INITIATIVE_CONTEXT_V1='initiative-context-v1';

function vp3_initiative_context_text_v1(mixed $value,int $limit): string
{
    return mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u',' ',strip_tags((string)$value))??''),0,$limit);
}

/** Select references, never substitute the newest item for a saved source. */
function vp3_initiative_context_selected_v1(?array $refs,string $prefix,string $pattern): ?array
{
    if($refs===null)return null;
    $selected=[];
    foreach($refs as $ref){
        if(!is_string($ref)||!str_starts_with($ref,$prefix.':'))continue;
        if(!preg_match($pattern,$ref,$match))throw new InvalidArgumentException('Invalid Initiative context reference.');
        $selected[$ref]=$match[1];
    }
    if(count($selected)>32)throw new InvalidArgumentException('Too many Initiative context references.');
    return $selected;
}

/** Undated tasks remain unresolved work; observation timestamps do not invent a new revision. */
function vp3_initiative_context_tasks_v1(PDO $pdo,array $user,array $principal,?array $selected): array
{
    if($selected===[])return ['ready',[]];
    if(!personal_capability_has_v242('agent_brain.access',$user))return ['forbidden',[]];
    if(!table_exists('agent_memory_items')||!vp3_agent_memory_scope_schema_ready_v410($pdo))return ['unavailable',[]];
    vp3_task_list_authorize_v1($pdo,$user,$principal,0);
    $agent=(int)$principal['agent_id'];
    [$scope,$args]=vp3_agent_memory_scope_sql_v410($agent,'m');
    $where='m.user_id=? AND '.$scope." AND m.is_active=1 AND m.memory_type IN ('task','commitment')";
    $json="CASE WHEN JSON_VALID(m.metadata_json) THEN m.metadata_json ELSE '{}' END";
    $value=static fn(string $field):string=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
        ?"JSON_EXTRACT({$json},'$.{$field}')":"NULLIF(JSON_UNQUOTE(JSON_EXTRACT({$json},'$.{$field}')),'null')";
    // Closed or dated rows must not crowd undated work out before the limit.
    $where.=" AND COALESCE(".$value('task_status').",'open') IN ('open','in_progress')"
        ." AND COALESCE(".$value('due_at').",'')='' AND COALESCE(".$value('due_date').",'')=''";
    $params=[(int)$user['id'],...$args];
    if($selected!==null){
        $where.=' AND m.id IN ('.implode(',',array_fill(0,count($selected),'?')).')';
        $params=[...$params,...array_map('intval',array_values($selected))];
    }
    $q=$pdo->prepare('SELECT m.id,m.memory_type,m.subject,m.memory_text,m.metadata_json,m.source_archive_id,m.last_seen_at FROM agent_memory_items m WHERE '
        .$where.' ORDER BY m.last_seen_at DESC,m.id DESC'.($selected===null?' LIMIT 101':''));
    $q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $limited=$selected===null&&count($rows)>100;$evidence=[];
    foreach($rows as $row){
        $id=(int)$row['id'];$meta=json_decode((string)$row['metadata_json'],true);
        if(!is_array($meta))continue;
        $status=$meta['task_status']??'open';
        if(!in_array($status,['open','in_progress'],true)||trim((string)($meta['due_at']??''))!==''
            ||trim((string)($meta['due_date']??''))!=='')continue;
        if((int)($meta['assigned_user_id']??0)>0&&(int)$meta['assigned_user_id']!==(int)$user['id'])continue;
        if(!vp3_task_list_task_allowed_v1($pdo,$user,$principal,$id))continue;
        $anchor=vp3_activity_situation_memory_anchor_v1($pdo,$user,$principal,$row,$meta);
        if($anchor===null)continue;
        $ref='open-task:'.$id;
        $task=['record_id'=>$id,'status'=>$status,'due_at'=>null,
            'text'=>vp3_initiative_context_text_v1($row['memory_text'],600),
            'source_kind'=>vp3_initiative_context_text_v1($meta['source_kind']??'',80)];
        $evidence[$ref]=['ref'=>$ref,'kind'=>'open_task','title'=>vp3_initiative_context_text_v1($row['subject'],170),
            'detail'=>'An existing '.$status.' task has no saved due date. Consider useful private progress or ask for missing owner choices; do not invent a deadline or claim completion.',
            'url'=>$anchor['url'],'task'=>$task,
            'revision'=>hash('sha256',json_encode([VP3_INITIATIVE_CONTEXT_V1,(int)$user['id'],$agent,$id,
                $row['memory_type'],$row['subject'],$row['memory_text'],$row['metadata_json'],$row['source_archive_id']],JSON_THROW_ON_ERROR))];
        if($selected===null&&count($evidence)>=8){$limited=true;break;}
    }
    return [$limited?'limited':'ready',$evidence];
}

/** A grouped conversation must not launder a named Agent's revoked Brain source. */
function vp3_initiative_context_denied_situations_v1(PDO $pdo,array $user,array $principal,string $namespace): array
{
    if((int)$principal['agent_id']===0||!table_exists('agent_memory_items')
        ||!personal_capability_has_v242('agent_brain.access',$user))return [];
    if(!function_exists('user_data_policy_can_use_v236'))throw new RuntimeException('Agent source policy is unavailable.',503);
    [$scope,$args]=vp3_agent_memory_scope_sql_v410((int)$principal['agent_id'],'m');
    $q=$pdo->prepare("SELECT m.id FROM agent_memory_items m WHERE m.user_id=? AND {$scope} AND m.is_active=1
        AND m.memory_type IN ('task','commitment','conversation_summary','agent_eyes','screen_observation','preference','goal')
        ORDER BY m.last_seen_at DESC,m.id DESC LIMIT 32");
    $q->execute([(int)$user['id'],...$args]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
    // The canonical notification reader can also reference an older memory.
    if(table_exists('notifications')){
        $q=$pdo->prepare('SELECT source_type,source_id FROM notifications WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 32');
        $q->execute([(int)$user['id']]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            if(in_array($row['source_type'],['brain_memory','agent_memory_item','agent_memory','agent_task'],true))$ids[]=(int)$row['source_id'];
        }
    }
    $denied=[];
    foreach(array_unique($ids) as $id){
        $row=vp3_activity_situation_memory_v1($pdo,$user,$principal,(int)$id);
        if(!$row||user_data_policy_can_use_v236($pdo,$principal,(int)$user['id'],'knowledge','brain:'.$id,false))continue;
        $meta=json_decode((string)$row['metadata_json'],true);
        $anchor=vp3_activity_situation_memory_anchor_v1($pdo,$user,$principal,$row,is_array($meta)?$meta:[]);
        if($anchor)$denied[hash('sha256',json_encode(['activity-situation-v1',(int)$user['id'],$namespace,$anchor['key']],JSON_THROW_ON_ERROR))]=true;
    }
    return $denied;
}

/** Reuse the one existing situation stream. Advisory context is never an action/capture grant. */
function vp3_initiative_context_situations_v1(PDO $pdo,array $user,array $principal,DateTimeImmutable $now,?array $selected): array
{
    if($selected===[])return ['ready',[]];
    $agent=(int)$principal['agent_id'];$namespace=$agent>0?'agent:'.$agent:'system';
    $stream=vp3_activity_situations_v1($pdo,$user,$namespace,$now);
    $denied=$stream['items']?vp3_initiative_context_denied_situations_v1($pdo,$user,$principal,$namespace):[];
    $coverage=$stream['coverage'];
    $state=in_array('unavailable',$coverage,true)?'unavailable':
        (in_array('limited',$coverage,true)||count($stream['items'])>=12?'limited':'ready');
    $evidence=[];
    foreach($stream['items'] as $item){
        $ref='activity-situation:'.$item['id'];
        if($selected!==null&&!isset($selected[$ref]))continue;
        if(isset($denied[$item['id']]))continue;
        // Task situations already have a canonical due/undated task source.
        // They must not become a competing second task or six event notifications.
        if(($item['object_ref']['type']??'')==='brain_task'||($item['state']??'')==='resolved')continue;
        if(!vp3_activity_situation_recent_v1((string)$item['updated_at'],$now))continue;
        $object=$item['object_ref']??[];
        if($agent>0&&($object['type']??'')==='brain_memory'&&function_exists('user_data_policy_can_use_v236')
            &&!user_data_policy_can_use_v236($pdo,$principal,(int)$user['id'],'knowledge','brain:'.$object['id'],false))continue;
        $sources=[];
        foreach(array_slice($item['sources'],0,8) as $source){
            $sources[]=['kind'=>$source['kind'],'label'=>$source['label'],'occurred_at'=>$source['occurred_at'],
                'confirmation'=>$source['confirmation']];
        }
        $context=['state'=>$item['state'],'summary'=>vp3_initiative_context_text_v1($item['summary'],320),
            'source_label'=>vp3_initiative_context_text_v1($item['source_label'],170),
            'object_ref'=>$object,'sources'=>$sources,'advisory_only'=>true,
            'private_preparation_allowed'=>$item['private_preparation_allowed'],
            'action_authority'=>false,'capture_authority'=>false];
        $evidence[$ref]=['ref'=>$ref,'kind'=>'activity_situation','title'=>vp3_initiative_context_text_v1($item['title'],170),
            'detail'=>$context['summary'].' Sources: '.$context['source_label'].'. Existing scoped context only; reported presence is provisional, not identity or permission.',
            'url'=>$item['source_url'],'activity_context'=>$context,
            'revision'=>hash('sha256',json_encode([VP3_INITIATIVE_CONTEXT_V1,(int)$user['id'],$agent,$item['id'],$item['revision']],JSON_THROW_ON_ERROR))];
        if($selected===null&&count($evidence)>=8){$state=$state==='unavailable'?'unavailable':'limited';break;}
    }
    return [$state,$evidence];
}

/** Read-only source admission; policy/budget/execution authority stays with canonical Initiative. */
function vp3_initiative_context_evidence_v1(PDO $pdo,array $user,array $principal,DateTimeImmutable $now,array &$coverage,?array $selectedRefs=null): array
{
    $owner=(int)($user['id']??0);$agent=$principal['agent_id']??null;$kind=$principal['kind']??null;
    if($owner<1||!is_int($agent)||$agent<0||!in_array($kind,['system','user_agent'],true)
        ||($kind==='system'&&$agent!==0)||($kind==='user_agent'&&($agent<1||(int)($principal['owner_user_id']??0)!==$owner))
        ||(int)($principal['viewer_user_id']??$owner)!==$owner
        ||(function_exists('vp3_participant_scope_v1')&&vp3_participant_scope_v1()!==null))throw new RuntimeException('Initiative context scope is unavailable.',403);
    return vp3_initiative_in_scope_v1($user,$agent,static function()use($pdo,$user,$principal,$now,&$coverage,$selectedRefs):array{
        $live=vp3_capability_live_user_v1($pdo,$user,$principal,[],null);
        if(!has_permission('account.access',$live))throw new RuntimeException('Initiative context access changed.',403);
        $taskRefs=vp3_initiative_context_selected_v1($selectedRefs,'open-task','/\Aopen-task:([1-9][0-9]*)\z/D');
        $situationRefs=vp3_initiative_context_selected_v1($selectedRefs,'activity-situation','/\Aactivity-situation:([a-f0-9]{64})\z/D');
        $evidence=[];
        foreach(['open_tasks'=>static fn()=>vp3_initiative_context_tasks_v1($pdo,$live,$principal,$taskRefs),
            'activity_situations'=>static fn()=>vp3_initiative_context_situations_v1($pdo,$live,$principal,$now,$situationRefs)] as $family=>$reader){
            try{[$coverage[$family],$rows]=$reader();$evidence+=$rows;}
            catch(Throwable $e){$coverage[$family]=in_array($e->getCode(),[403,404],true)?'forbidden':'unavailable';}
        }
        ksort($evidence,SORT_STRING);
        return $evidence;
    });
}
