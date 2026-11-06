<?php
declare(strict_types=1);

require_once __DIR__.'/agent-task-lists-v1.php';
require_once __DIR__.'/personal-capabilities-strict-v1.php';

/** Adapters over canonical Brain tasks and list references. The owner broker
 * freezes a reviewed plan and commits its receipt in the same transaction. */
function vp3_initiative_office_action_scope_v1(PDO $pdo,array $user,array $principal,array $binding): array
{
    try{
    if(!function_exists('db')||db()!==$pdo)throw new RuntimeException('Office canonical connection changed.',503);
    $uid=(int)($user['id']??0);$aid=$binding['agent_id']??null;$cid=$binding['conversation_id']??null;
    if($uid<1||!is_int($aid)||$aid<0||!is_int($cid)||$cid<1
        ||(isset($binding['owner_user_id'])&&$binding['owner_user_id']!==$uid)
        ||($principal['owner_user_id']??null)!==($aid>0?$uid:0)
        ||(int)($principal['agent_id']??0)!==$aid
        ||($principal['kind']??null)!==($aid>0?'user_agent':'system')
        ||(isset($principal['viewer_user_id'])&&$principal['viewer_user_id']!==$uid)
        ||($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']??null)!==null)
        throw new RuntimeException('Office action owner scope changed.',403);
    $q=$pdo->prepare('SELECT * FROM users WHERE id=?');$q->execute([$uid]);$live=$q->fetch(PDO::FETCH_ASSOC);
    if(!$live||empty($live['is_active']))throw new RuntimeException('Office action account is unavailable.',403);
    if(!function_exists('personal_capability_has_strict_v1'))throw new RuntimeException('Strict Office permission reader is unavailable.',503);
    if(!personal_capability_has_strict_v1('agent_brain.access',$live)
        ||!has_permission('account.access',$live)||!has_permission('chat.access',$live))
        throw new RuntimeException('Office action access is unavailable.',403);
    if($aid>0){
        $q=$pdo->prepare('SELECT id FROM user_agents WHERE id=? AND owner_user_id=? AND is_active=1');$q->execute([$aid,$uid]);
        if(!$q->fetchColumn())throw new RuntimeException('Office action Agent is unavailable.',403);
        if(!function_exists('user_data_policy_can_use_v236'))throw new RuntimeException('Office task data policy is unavailable.',503);
    }
    vp3_task_list_authorize_v1($pdo,$live,$principal,$cid);
    if(!vp3_task_lists_ready_v1($pdo))throw new RuntimeException('Office action storage is unavailable.',503);
    return $live;
    }catch(PDOException $error){throw new RuntimeException('Office action authority storage is unavailable.',503,$error);}
}

function vp3_initiative_office_action_catalog_v1(PDO $pdo,array $user,array $principal,array $binding): array
{
    vp3_initiative_office_action_scope_v1($pdo,$user,$principal,$binding);
    $text=static fn(int $max):array=>['type'=>'string','minLength'=>1,'maxLength'=>$max];
    $id=['type'=>'integer','minimum'=>1];$schema=static fn(array $props,array $required):array=>[
        'type'=>'object','additionalProperties'=>false,'properties'=>$props,'required'=>$required];
    return [
        ['adapter'=>'task.create','capability'=>'tasks.lists','operation'=>'create_task','review_required'=>true,'effect_class'=>'private_record',
            'description'=>'Save one new private task in this Agent namespace.','arguments_schema'=>$schema(['title'=>$text(180)],['title'])],
        ['adapter'=>'task.set_status','capability'=>'tasks.manage','operation'=>'set_status','review_required'=>true,'effect_class'=>'private_record',
            'description'=>'Change one exact task supplied by this proposal.','arguments_schema'=>$schema(['task_id'=>$id,'status'=>['type'=>'string','enum'=>['open','in_progress','waiting','completed','cancelled']]],['task_id','status'])],
        ['adapter'=>'task_list.create','capability'=>'tasks.lists','operation'=>'create_list','review_required'=>true,'effect_class'=>'private_record',
            'description'=>'Create one explicitly reviewed empty list.','arguments_schema'=>$schema(['name'=>$text(120)],['name'])],
        ['adapter'=>'task_list.add','capability'=>'tasks.lists','operation'=>'add','review_required'=>true,'effect_class'=>'private_record',
            'description'=>'Add one new task or one exact supplied existing task to an exact supplied list.',
            'arguments_schema'=>['oneOf'=>[$schema(['list_id'=>$id,'title'=>$text(180)],['list_id','title']),$schema(['list_id'=>$id,'task_id'=>$id],['list_id','task_id'])]]],
    ];
}

function vp3_initiative_office_action_keys_v1(array $value,array $keys): void
{
    $actual=array_keys($value);sort($actual);sort($keys);
    if($actual!==$keys)throw new InvalidArgumentException('Unexpected Office action fields.');
}

function vp3_initiative_office_action_id_v1(mixed $value): int
{
    if(!is_int($value)||$value<1)throw new InvalidArgumentException('An exact integer Office record ID is required.');
    return $value;
}

/** Only the broker's server-curated IDs/names confer resource scope. */
function vp3_initiative_office_action_allowed_v1(array $allowed,string $family,int $id,string $label): void
{
    $rows=$allowed[$family]??[];
    if(!is_array($rows))throw new InvalidArgumentException('Invalid Office action resource scope.');
    foreach($rows as $row){
        if(!is_array($row)||($row['id']??null)!==$id)continue;
        $key=$family==='tasks'?'title':'name';
        if(!is_string($row[$key]??null)||$row[$key]!==$label)throw new RuntimeException('Office action resource label changed.',409);
        return;
    }
    throw new RuntimeException('Office action target was not supplied by this proposal.',403);
}

/** Strict exact-record reads do not invoke a reconciler, scheduler or planner. */
function vp3_initiative_office_action_task_v1(PDO $pdo,array $user,array $principal,int $id): array
{
    try{
    $aid=vp3_task_list_agent_v1($principal);
    if($aid>0&&!user_data_policy_can_use_v236($pdo,$principal,(int)$user['id'],'knowledge','brain:'.$id,false))
        throw new RuntimeException('Office task access changed.',403);
    vp3_task_list_existing_task_v1($pdo,$user,$principal,$id);
    $q=$pdo->prepare('SELECT id,user_id,user_agent_id,memory_type,subject,memory_text,metadata_json,memory_hash,is_active FROM agent_memory_items WHERE id=? AND user_id=? AND '.($aid>0?'user_agent_id=?':'user_agent_id IS NULL')." AND is_active=1 AND memory_type IN ('task','commitment')");
    $q->execute($aid>0?[$id,(int)$user['id'],$aid]:[$id,(int)$user['id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Office task changed.',409);
    $meta=json_decode((string)$row['metadata_json'],true,32,JSON_THROW_ON_ERROR);
    if(!is_array($meta)||!in_array($meta['task_status']??'open',['open','in_progress','waiting','completed','cancelled'],true))
        throw new RuntimeException('Office task metadata is unavailable.',503);
    return ['id'=>$id,'title'=>(string)$row['subject'],'status'=>$meta['task_status']??'open',
        'memory_hash'=>(string)$row['memory_hash'],'revision'=>hash('sha256',json_encode($row,JSON_THROW_ON_ERROR))];
    }catch(PDOException|JsonException $error){throw new RuntimeException('Office task source storage is unavailable.',503,$error);}
}

function vp3_initiative_office_action_list_v1(PDO $pdo,array $user,array $principal,int $id): array
{
    try{
    $q=$pdo->prepare('SELECT id,name,name_key FROM agent_task_lists_v1 WHERE id=? AND owner_user_id=? AND agent_scope_id=?');
    $q->execute([$id,(int)$user['id'],vp3_task_list_agent_v1($principal)]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Office list is unavailable in this Agent.',403);
    $q=$pdo->prepare('SELECT task_id,item_key FROM agent_task_list_items_v1 WHERE list_id=? ORDER BY task_id');$q->execute([$id]);
    return ['id'=>$id,'name'=>(string)$row['name'],'revision'=>hash('sha256',json_encode([$row,$q->fetchAll(PDO::FETCH_ASSOC)],JSON_THROW_ON_ERROR))];
    }catch(PDOException|JsonException $error){throw new RuntimeException('Office list source storage is unavailable.',503,$error);}
}

function vp3_initiative_office_action_normalize_v1(PDO $pdo,array $user,array $principal,array $binding,array $raw,array $allowedResources): array
{
    $user=vp3_initiative_office_action_scope_v1($pdo,$user,$principal,$binding);
    vp3_initiative_office_action_keys_v1($raw,['adapter','arguments']);$adapter=$raw['adapter'];$args=$raw['arguments'];
    if(!is_string($adapter)||!is_array($args))throw new InvalidArgumentException('Invalid Office action.');
    $expected=[];$refs=[];
    if($adapter==='task.create'){
        vp3_initiative_office_action_keys_v1($args,['title']);$args=['title'=>vp3_task_list_text_v1($args['title'],180)];
        if($args['title']==='')throw new InvalidArgumentException('Task title is required.');
    }elseif($adapter==='task.set_status'){
        vp3_initiative_office_action_keys_v1($args,['task_id','status']);$id=vp3_initiative_office_action_id_v1($args['task_id']);
        if(!is_string($args['status'])||!in_array($args['status'],['open','in_progress','waiting','completed','cancelled'],true))throw new InvalidArgumentException('Invalid task status.');
        $task=vp3_initiative_office_action_task_v1($pdo,$user,$principal,$id);
        vp3_initiative_office_action_allowed_v1($allowedResources,'tasks',$id,$task['title']);$expected['task']=$task;$refs[]='task:'.$id;
        $args=['task_id'=>$id,'status'=>$args['status']];
    }elseif($adapter==='task_list.create'){
        vp3_initiative_office_action_keys_v1($args,['name']);$name=vp3_task_list_text_v1($args['name'],120);
        if($name==='')throw new InvalidArgumentException('List name is required.');
        $list=vp3_task_list_find_v1($pdo,$user,$principal,$name);$expected['list']=$list?vp3_initiative_office_action_list_v1($pdo,$user,$principal,(int)$list['id']):null;
        if($list)$refs[]='task-list:'.$list['id'];$args=['name'=>$name];
    }elseif($adapter==='task_list.add'){
        $existing=array_key_exists('task_id',$args);vp3_initiative_office_action_keys_v1($args,$existing?['list_id','task_id']:['list_id','title']);
        $lid=vp3_initiative_office_action_id_v1($args['list_id']);$list=vp3_initiative_office_action_list_v1($pdo,$user,$principal,$lid);
        vp3_initiative_office_action_allowed_v1($allowedResources,'lists',$lid,$list['name']);$expected['list']=$list;$refs[]='task-list:'.$lid;
        if($existing){$id=vp3_initiative_office_action_id_v1($args['task_id']);$task=vp3_initiative_office_action_task_v1($pdo,$user,$principal,$id);vp3_initiative_office_action_allowed_v1($allowedResources,'tasks',$id,$task['title']);$expected['task']=$task;$refs[]='task:'.$id;$args=['list_id'=>$lid,'task_id'=>$id];}
        else{$title=vp3_task_list_text_v1($args['title'],180);if($title==='')throw new InvalidArgumentException('Task title is required.');$args=['list_id'=>$lid,'title'=>$title];}
    }else throw new InvalidArgumentException('Unknown Office action adapter.');
    return ['adapter'=>$adapter,'arguments'=>$args,'capability'=>$adapter==='task.set_status'?'tasks.manage':'tasks.lists',
        'operation'=>match($adapter){'task.create'=>'create_task','task.set_status'=>'set_status','task_list.create'=>'create_list','task_list.add'=>'add'},
        'effect_class'=>'private_record','review_required'=>true,'target_refs'=>$refs,'expected'=>$expected];
}

/** Revalidate the frozen server plan, not a model-chosen new target. */
function vp3_initiative_office_action_current_v1(PDO $pdo,array $user,array $principal,array $binding,array $plan): array
{
    $allowed=['tasks'=>[],'lists'=>[]];
    if(isset($plan['expected']['task']))$allowed['tasks'][]=$plan['expected']['task'];
    if(isset($plan['expected']['list']))$allowed['lists'][]=$plan['expected']['list'];
    $current=vp3_initiative_office_action_normalize_v1($pdo,$user,$principal,$binding,
        ['adapter'=>$plan['adapter']??null,'arguments'=>$plan['arguments']??null],$allowed);
    if($current!==$plan)throw new RuntimeException('Reviewed Office action changed.',409);
    return $current;
}

function vp3_initiative_office_action_apply_v1(PDO $pdo,array $user,array $principal,array $binding,array $plan,string $actionId): array
{
    if(!$pdo->inTransaction())throw new RuntimeException('Office action requires the owner broker transaction.',409);
    if(!preg_match('/\A[a-zA-Z0-9:_-]{8,120}\z/D',$actionId))throw new InvalidArgumentException('Invalid Office action identity.');
    $user=vp3_initiative_office_action_scope_v1($pdo,$user,$principal,$binding);vp3_initiative_office_action_current_v1($pdo,$user,$principal,$binding,$plan);
    $adapter=$plan['adapter'];$args=$plan['arguments'];$aid=vp3_task_list_agent_v1($principal);$cid=$binding['conversation_id'];
    $identity='initiative-'.hash('sha256',$actionId);$old=$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null;
    vp3_agent_memory_scope_set_current_v410((int)$user['id'],$aid);
    $taskId=0;$listId=0;$changed=true;
    try{
        if($adapter==='task.set_status'){
            $taskId=$args['task_id'];$changed=$plan['expected']['task']['status']!==$args['status'];
            if($changed&&!agent_task_v123_update($user,$taskId,$args['status']))throw new RuntimeException('Canonical task status was not verified.',503);
            $answer='Task #'.$taskId.' “'.$plan['expected']['task']['title'].'” is '.$args['status'].'.';
        }elseif($adapter==='task_list.create'){
            $list=vp3_task_list_find_v1($pdo,$user,$principal,$args['name']);$changed=$list===null;
            if(!$list)$list=vp3_task_list_create_v1($pdo,$user,$principal,$args['name']);$listId=(int)$list['id'];
            $answer=($changed?'Created':'The list is ready:').' “'.$list['name'].'”.';
        }else{
            $list=null;$title=$args['title']??($plan['expected']['task']['title']??'');
            if($adapter==='task_list.add'){
                $listId=$args['list_id'];$list=$plan['expected']['list'];
                $q=$pdo->prepare('SELECT task_id FROM agent_task_list_items_v1 WHERE list_id=? AND item_key=?');$q->execute([$listId,vp3_task_list_key_v1($title)]);$taskId=(int)$q->fetchColumn();
                if($taskId){
                    vp3_initiative_office_action_task_v1($pdo,$user,$principal,$taskId);
                    if(isset($args['task_id'])&&$taskId!==$args['task_id'])throw new RuntimeException('A different task already uses that list item title.',409);
                    $changed=false;
                }
            }
            if(!$taskId){
                if(isset($args['task_id'])){$taskId=$args['task_id'];vp3_task_list_attach_task_v1($pdo,$user,$principal,$list,$taskId,$title);}
                else{
                    // A replay must inspect the committed broker receipt. Never
                    // upsert an existing stable hash and reset later task edits.
                    $hash=vp3_agent_memory_scope_hash_v410($aid,agent_brain_v122_memory_hash('task','task-list-action:'.$identity));
                    $q=$pdo->prepare('SELECT id FROM agent_memory_items WHERE user_id=? AND memory_hash=?');$q->execute([(int)$user['id'],$hash]);
                    if($q->fetchColumn())throw new RuntimeException('Office action already has a saved task; inspect its receipt.',409);
                    $taskId=vp3_task_list_save_task_v1($pdo,$user,$principal,$title,$list,$identity,$cid);
                }
            }
            $answer=$listId?($changed?'Added':'Already saved').' “'.$title.'” in “'.$list['name'].'”.':'Saved “'.$title.'” as task #'.$taskId.'.';
        }
        $refs=[];$after=[];
        if($taskId){$after['task']=vp3_initiative_office_action_task_v1($pdo,$user,$principal,$taskId);$refs[]='task:'.$taskId;}
        if($listId){$after['list']=vp3_initiative_office_action_list_v1($pdo,$user,$principal,$listId);$refs[]='task-list:'.$listId;}
        $result=['handled'=>true,'answer'=>$answer,'actions'=>[],'sources'=>[],'sticky_notes'=>$listId?[['kind'=>'task_list','id'=>$listId]]:[],
            'tool_receipt'=>vp3_tool_receipt_v1($plan['capability'],$plan['operation'],$changed?'completed':'read_complete','',$listId?:$taskId,$changed?1:0),
            'office_action'=>['adapter'=>$adapter,'action_id'=>$actionId,'task_id'=>$taskId,'list_id'=>$listId,'after'=>$after],
            'output_refs'=>$refs];
        vp3_initiative_office_action_inspect_v1($pdo,$user,$principal,$binding,$plan,$result);$result['verified']=true;return $result;
    }finally{if($old===null)unset($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']);else $GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']=$old;}
}

/** Read-only replay: later edits make the saved outcome stale, never reapply it. */
function vp3_initiative_office_action_inspect_v1(PDO $pdo,array $user,array $principal,array $binding,array $plan,array $result): array
{
    $user=vp3_initiative_office_action_scope_v1($pdo,$user,$principal,$binding);$saved=$result['office_action']??null;
    if(!is_array($saved)||($saved['adapter']??null)!==($plan['adapter']??null)||!is_array($saved['after']??null))throw new RuntimeException('Office action receipt is unavailable.',409);
    $receipt=vp3_tool_receipt_normalize_v1($result['tool_receipt']??null);
    if(!$receipt||$receipt['capability']!==($plan['capability']??null)||$receipt['operation']!==($plan['operation']??null)
        ||!in_array($receipt['status'],['completed','read_complete'],true)
        ||!is_string($saved['action_id']??null)||!preg_match('/\A[a-zA-Z0-9:_-]{8,120}\z/D',$saved['action_id']))
        throw new RuntimeException('Office action canonical receipt changed.',409);
    $refs=[];$resources=[];
    foreach(['task','list'] as $family){
        $id=$saved[$family.'_id']??null;if(!is_int($id)||$id<0)throw new RuntimeException('Office action resource receipt changed.',409);if(!$id)continue;
        $current=$family==='task'?vp3_initiative_office_action_task_v1($pdo,$user,$principal,$id):vp3_initiative_office_action_list_v1($pdo,$user,$principal,$id);
        if($current!==($saved['after'][$family]??null))throw new RuntimeException('Office action saved outcome changed.',409);
        $refs[]=($family==='task'?'task:':'task-list:').$id;$resources[]=$current;
    }
    if(!$refs||$refs!==($result['output_refs']??null))throw new RuntimeException('Office action output references changed.',409);
    if((int)$receipt['record_id']!==($saved['list_id']?:$saved['task_id']))throw new RuntimeException('Office action receipt record changed.',409);
    if($plan['adapter']==='task.create'){
        $hash=vp3_agent_memory_scope_hash_v410(vp3_task_list_agent_v1($principal),agent_brain_v122_memory_hash('task','task-list-action:initiative-'.hash('sha256',$saved['action_id'])));
        if(($saved['after']['task']['title']??null)!==$plan['arguments']['title']||($saved['after']['task']['status']??null)!=='open'||($saved['after']['task']['memory_hash']??null)!==$hash||$saved['list_id']!==0)
            throw new RuntimeException('Office task outcome differs from the approved action.',409);
    }
    if($plan['adapter']==='task_list.create'&&($saved['task_id']!==0||vp3_task_list_key_v1((string)($saved['after']['list']['name']??''))!==vp3_task_list_key_v1($plan['arguments']['name'])))
        throw new RuntimeException('Office list outcome differs from the approved action.',409);
    if($plan['adapter']==='task.set_status'&&(($saved['task_id']??null)!==$plan['arguments']['task_id']||($saved['after']['task']['status']??null)!==$plan['arguments']['status']||$saved['list_id']!==0))throw new RuntimeException('Office task outcome differs from the approved action.',409);
    if($plan['adapter']==='task_list.add'){
        if(($saved['list_id']??null)!==$plan['arguments']['list_id'])throw new RuntimeException('Office list outcome differs from the approved action.',409);
        if(isset($plan['arguments']['task_id'])&&$saved['task_id']!==$plan['arguments']['task_id'])throw new RuntimeException('Office list task differs from the approved action.',409);
        if(isset($plan['arguments']['title'])&&vp3_task_list_key_v1((string)($saved['after']['task']['title']??''))!==vp3_task_list_key_v1($plan['arguments']['title']))throw new RuntimeException('Office list task title differs from the approved action.',409);
        $q=$pdo->prepare('SELECT task_id FROM agent_task_list_items_v1 WHERE list_id=? AND task_id=?');$q->execute([$saved['list_id'],$saved['task_id']]);
        if(!$q->fetchColumn())throw new RuntimeException('Office list membership is unavailable.',409);
    }
    return ['verified'=>true,'resources'=>$resources,'affected_refs'=>$refs];
}
