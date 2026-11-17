<?php
declare(strict_types=1);

require_once __DIR__.'/agent-initiative-office-records-v1.php';
require_once __DIR__.'/agent-initiative-task-lists-v1.php';
require_once __DIR__.'/agent-commerce-recovery-pa2.php';

/** The per-entry review reads one saved source, independent of proactive discovery. */
function vp3_initiative_office_entry_source_v1(PDO $pdo,array $scope,array $binding,string $ref): ?array
{
    $listId=vp3_initiative_task_list_id_v1($ref);
    $selector=$listId===null?vp3_initiative_office_ref_v1($ref):null;
    if($listId===null&&!in_array($selector['family']??null,['task','reminder'],true))return null;

    $read=static function()use($pdo,$scope,$binding,$ref,$listId,$selector):?array{
        if($listId!==null){
            $scope=vp3_initiative_task_list_scope_v1($pdo,$scope,$binding);
            return vp3_initiative_office_entry_list_source_v1($pdo,$scope,$ref,$listId);
        }

        $scope=vp3_initiative_office_scope_v1($pdo,$scope,$binding);
        $kind=$selector['family'];
        $state=$kind==='task'?vp3_initiative_office_task_state_v1($pdo,$scope,$selector['id'],true)
            :vp3_initiative_office_reminder_state_v1($pdo,$scope,$selector['id'],true);
        if($state===null)return null;
        $priorRevision=null;
        if($kind==='task'&&!in_array($state['status'],['completed','cancelled'],true)){
            $listContext=vp3_initiative_office_entry_list_context_v1($pdo,$scope,$selector['id']);
            if($listContext!==[]){
                $priorRevision=vp3_initiative_office_entry_v1($ref,$selector,$state)['revision'];
                $state['parent_lists']=$listContext;
            }
        }
        $entry=vp3_initiative_office_entry_v1($ref,$selector,$state);
        $record=array_diff_key($state,['owner_user_id'=>true,'agent_id'=>true,'source_hash'=>true]);
        if(isset($record['parent_lists']))$record['parent_lists']=array_column($record['parent_lists'],'name');
        $limited=!empty($state['content_truncated'])||($state['pending_coverage']??null)==='limited';
        if($kind==='task'){
            $closed=in_array($state['status'],['completed','cancelled'],true);
            if(strlen(json_encode($record,JSON_THROW_ON_ERROR))>7000){
                $record['text']=vp3_initiative_office_text_v1($record['text'],1000);
                $record['content_truncated']=$limited=true;
            }
        }else{
            $closed=$state['status']!=='active'||$state['revisit_delivered'];
            if(strlen(json_encode($record['condition'],JSON_THROW_ON_ERROR))>1000){
                unset($record['condition']);
                $record['condition_omitted']=$limited=true;
            }
        }
        return ['ref'=>$ref,'kind'=>$kind,'tool'=>$selector['tool'],'revision'=>$entry['revision'],
            'title'=>$entry['title'],'record'=>$record,'closed'=>$closed,'limited'=>$limited,'url'=>$entry['url']]
            +($priorRevision!==null?['pre_list_context_revision'=>$priorRevision]:[]);
    };

    // Use the caller's snapshot when verifying a commit or open a local read transaction.
    return $pdo->inTransaction()?$read():agent_commerce_atomic_pa2($pdo,$read);
}

/** Keep list identities in the source revision and send only authorized names to the model. */
function vp3_initiative_office_entry_list_context_v1(PDO $pdo,array $scope,int $taskId): array
{
    if(!table_exists('agent_task_lists_v1')||!table_exists('agent_task_list_items_v1'))return [];
    $query=$pdo->prepare('SELECT l.id,l.name FROM agent_task_lists_v1 l JOIN agent_task_list_items_v1 i
        ON i.list_id=l.id AND i.task_id=? WHERE l.owner_user_id=? AND l.agent_scope_id=? ORDER BY l.id LIMIT 4');
    $query->execute([$taskId,(int)$scope['user']['id'],$scope['agent_id']]);
    $lists=[];
    foreach($query->fetchAll(PDO::FETCH_ASSOC) as $row){
        $id=(int)$row['id'];
        if(function_exists('vp3_participant_resource_v1')
            &&!vp3_participant_resource_v1($pdo,$scope['user'],$scope['principal'],'task_list',$id))continue;
        $name=vp3_initiative_office_text_v1($row['name'],500);
        if($name!=='')$lists[]=['id'=>$id,'name'=>$name];
    }
    return $lists;
}

/** Hash each authorized member while retaining only the possible model sample. */
function vp3_initiative_office_entry_list_source_v1(PDO $pdo,array $scope,string $ref,int $id): ?array
{
    $list=vp3_initiative_task_list_row_v1($pdo,$scope,$id);
    if($list===null)return null;
    $owner=(int)$scope['user']['id'];
    $principal=$scope['principal'];
    $agent=$scope['agent_id'];
    if(function_exists('vp3_participant_require_resource_v1'))
        vp3_participant_require_resource_v1($pdo,$scope['user'],$principal,'task_list',$id);

    $digest=hash_init('sha256');
    hash_update($digest,json_encode(['initiative-office-entry-list-v1',$ref,$owner,$agent,$list['name']],JSON_THROW_ON_ERROR)."\n");
    $members=vp3_initiative_task_list_member_rows_v1($pdo,$scope,$id,false);
    $counts=array_fill_keys(['open','in_progress','waiting','completed','cancelled'],0);
    $total=0;$open=[];$closed=[];
    while($row=$members->fetch(PDO::FETCH_ASSOC)){
        if(!vp3_task_list_task_allowed_v1($pdo,$scope['user'],$principal,(int)$row['id']))continue;
        [$item,$semantic]=vp3_initiative_task_list_member_v1($row,++$total);
        hash_update($digest,json_encode($semantic,JSON_THROW_ON_ERROR)."\n");
        $counts[$item['status']]++;
        $item['assigned_to_someone_else']=$item['assigned_user_id']>0&&$item['assigned_user_id']!==$owner;
        $item['content_truncated']=strlen($semantic['text'])>600;
        unset($item['assigned_user_id']);
        if(in_array($item['status'],['completed','cancelled'],true)){
            if(count($closed)<20)$closed[]=$item;
        }elseif(count($open)<20){
            $open[]=$item;
        }
    }
    $progress=['total'=>$total,'unresolved'=>$total-$counts['completed']-$counts['cancelled'],
        'completed'=>$counts['completed'],'cancelled'=>$counts['cancelled'],'by_status'=>$counts];
    $title=vp3_initiative_office_text_v1($list['name'],500);
    $status=$total===0?'empty':($progress['unresolved']===0?'closed':'open');
    $record=vp3_initiative_task_list_record_v1(['id'=>$id,'title'=>$title,'status'=>$status,
        'members'=>[...$open,...$closed],'progress'=>$progress,'order'=>'canonical membership creation time, then task id'],true);
    $limited=$record['coverage']['item_sample']==='limited'||in_array(true,array_column($record['items'],'content_truncated'),true);

    return ['ref'=>$ref,'kind'=>'task_list','tool'=>'tasks.lists.read','revision'=>hash_final($digest),
        'title'=>mb_substr($title,0,170),'record'=>$record,'closed'=>$progress['unresolved']===0,
        'limited'=>$limited,'url'=>url('/chat.php')];
}
