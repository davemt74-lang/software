<?php
declare(strict_types=1);

function video_meeting_followthrough_verify_v18100(PDO $pdo,array $meeting,array $user,array $item): bool
{
    $id=max(0,(int)($item['record_id']??0));if($id<1)return false;$type=(string)$item['type'];
    if($type==='agent_task'){$s=$pdo->prepare('SELECT id FROM agent_memory_items WHERE id=? AND user_id=? LIMIT 1');$s->execute([$id,(int)$user['id']]);return (bool)$s->fetchColumn();}
    if($type==='agent_brain'){$s=$pdo->prepare('SELECT id FROM agent_memory_items WHERE id=? AND user_id=? LIMIT 1');$s->execute([$id,(int)$user['id']]);return (bool)$s->fetchColumn();}
    if($type==='calendar_event')return (bool)user_calendar_event_v1300($pdo,(int)$user['id'],$id);
    if($type==='crm_note'){$s=$pdo->prepare('SELECT id FROM crm_activities WHERE id=? AND lead_id=? LIMIT 1');$s->execute([$id,(int)$item['lead_id']]);return (bool)$s->fetchColumn();}
    if($type==='crm_task'){$s=$pdo->prepare('SELECT id FROM crm_tasks WHERE id=? AND lead_id=? LIMIT 1');$s->execute([$id,(int)$item['lead_id']]);return (bool)$s->fetchColumn();}
    if($type==='followup_workflow'){$s=$pdo->prepare('SELECT id FROM agent_workflow_runs WHERE id=? AND owner_user_id=? LIMIT 1');$s->execute([$id,(int)$user['id']]);return (bool)$s->fetchColumn();}
    return false;
}

function video_meeting_followthrough_execute_v18100(PDO $pdo,array $meeting,array $user,string $itemId): array
{
    $queue=video_meeting_followthrough_load_current_v18100($pdo,$meeting);$i=video_meeting_followthrough_find_index_v18100($queue,$itemId);$item=$queue['items'][$i];$status=(string)$item['status'];
    if($status==='verified')return video_meeting_followthrough_public_queue_v18100($pdo,$meeting,$queue);
    if($status!=='approved')throw new RuntimeException('Approve this meeting action before executing it.');
    $block=video_meeting_followthrough_item_block_v18100($pdo,$meeting,$user,$item);if($block!=='')throw new RuntimeException($block);
    $type=(string)$item['type'];
    try{
        if($type==='followup_workflow'){
            $receipt=video_meeting_followthrough_followup_v18100($pdo,$meeting,$user,$item);
            $item['record_id']=(int)$receipt['id'];$item['record_kind']=(string)$receipt['kind'];$item['target_url']=(string)$receipt['url'];
        }else{
            $pdo->beginTransaction();
            if($type==='agent_task'){$id=video_meeting_followthrough_task_v18100($pdo,$meeting,$user,$item);$kind='agent_memory_task';$url=url('/chat.php');}
            elseif($type==='agent_brain'){$id=video_meeting_followthrough_brain_v18100($pdo,$meeting,$user,$item);$kind='agent_memory';$url=url('/chat.php');}
            elseif($type==='calendar_event'){$id=video_meeting_followthrough_calendar_v18100($pdo,$meeting,$user,$item);$kind='calendar_event';$url=url('/calendar-event.php?id='.$id);}
            elseif($type==='crm_note'||$type==='crm_task'){$r=video_meeting_followthrough_crm_v18100($pdo,$meeting,$user,$item);$id=(int)$r['id'];$kind=(string)$r['kind'];$url=url('/admin/crm-lead.php?id='.(int)$item['lead_id']);}
            else throw new RuntimeException('Unsupported meeting follow-through action.');
            if($id<1)throw new RuntimeException('The canonical VP3 record was not created.');
            $item['record_id']=$id;$item['record_kind']=$kind;$item['target_url']=$url;
            $item['status']='executed';$item['executed_at']=gmdate('c');$item['error']='';$queue['items'][$i]=$item;video_meeting_followthrough_store_v18100($pdo,$meeting,$queue);$pdo->commit();
        }
        $item['status']='executed';$item['executed_at']=$item['executed_at']?:gmdate('c');$item['error']='';$queue['items'][$i]=$item;
        if(video_meeting_followthrough_verify_v18100($pdo,$meeting,$user,$item)){$item['status']='verified';$item['verified_at']=gmdate('c');}
        else{$item['status']='failed';$item['failed_at']=gmdate('c');$item['error']='The canonical VP3 record could not be verified after execution.';}
        $queue['items'][$i]=$item;video_meeting_followthrough_store_v18100($pdo,$meeting,$queue);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();$item['status']='failed';$item['failed_at']=gmdate('c');$item['error']=mb_strimwidth($e->getMessage(),0,500,'…');$queue['items'][$i]=$item;video_meeting_followthrough_store_v18100($pdo,$meeting,$queue);throw $e;
    }
    return video_meeting_followthrough_public_queue_v18100($pdo,$meeting,$queue);
}

function video_meeting_followthrough_queue_context_v18100(PDO $pdo,array $meeting): array
{
    $queue=video_meeting_followthrough_queue_v18100($pdo,$meeting,false);$pending=[];$verified=[];
    foreach((array)($queue['items']??[]) as $item){
        if(!is_array($item))continue;$row=['id'=>(string)$item['id'],'type'=>(string)$item['type'],'status'=>(string)$item['status'],'title'=>mb_strimwidth((string)$item['title'],0,260,'…')];
        if((string)$item['status']==='verified')$verified[]=$row;else $pending[]=$row;
    }
    return ['meeting_followthrough'=>true,'meeting_followthrough_version'=>'v18.10','followthrough_pending'=>array_slice($pending,0,20),'followthrough_verified'=>array_slice($verified,0,20),'followthrough_counts'=>$queue['counts']??[]];
}
