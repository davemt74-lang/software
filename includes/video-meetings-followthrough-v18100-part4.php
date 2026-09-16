<?php
declare(strict_types=1);

function video_meeting_followthrough_approve_v18100(PDO $pdo,array $meeting,array $user,string $itemId): array
{
    $queue=video_meeting_followthrough_load_current_v18100($pdo,$meeting);$i=video_meeting_followthrough_find_index_v18100($queue,$itemId);$item=$queue['items'][$i];
    if(!in_array((string)$item['status'],['suggested','failed'],true))return video_meeting_followthrough_public_queue_v18100($pdo,$meeting,$queue);
    $block=video_meeting_followthrough_item_block_v18100($pdo,$meeting,$user,$item);if($block!=='')throw new RuntimeException($block);
    $item['status']='approved';$item['approved_at']=gmdate('c');$item['failed_at']='';$item['error']='';$item['updated_at']=gmdate('c');$queue['items'][$i]=$item;
    video_meeting_followthrough_store_v18100($pdo,$meeting,$queue);
    return video_meeting_followthrough_public_queue_v18100($pdo,$meeting,$queue);
}

function video_meeting_followthrough_task_v18100(PDO $pdo,array $meeting,array $user,array $item): int
{
    if(!agent_brain_schema_ready()||!table_exists('agent_memory_items'))throw new RuntimeException('Agent task storage is unavailable.');
    $uid=(int)$user['id'];$kind=(string)($item['task_kind']??'task')==='commitment'?'commitment':'task';$hash=sha1('meeting-followthrough|'.$uid.'|'.$kind.'|'.(string)$meeting['id'].'|'.(string)$item['id']);
    $find=$pdo->prepare('SELECT id,metadata_json FROM agent_memory_items WHERE user_id=? AND memory_hash=? LIMIT 1');$find->execute([$uid,$hash]);$row=$find->fetch()?:null;
    $assignment=video_meeting_followthrough_assignment_v18100($pdo,$user,(string)($item['owner']??''));$sourceUrl=url('/meeting.php?meeting='.(string)$meeting['public_id'].'&review=1');
    $prior=is_array($row)?json_decode((string)($row['metadata_json']??''),true):[];if(!is_array($prior))$prior=[];$status=(string)($prior['task_status']??'open');if(!in_array($status,['open','in_progress','waiting','completed','cancelled'],true))$status='open';
    $meta=array_replace($prior,['source_kind'=>'video_meeting_intelligence','video_meeting_id'=>(int)$meeting['id'],'video_meeting_public_id'=>(string)$meeting['public_id'],'followthrough_item_id'=>(string)$item['id'],'source_hash'=>(string)($item['source_hash']??''),'task_status'=>$status,'task_kind'=>$kind,'task_key'=>sha1('meeting-followthrough|'.(string)$item['id']),'assigned_user_id'=>$assignment['user_id'],'assigned_agent_id'=>$assignment['agent_id'],'owner_label'=>$assignment['label'],'due_date'=>(string)($item['due_date']??''),'source_label'=>'Meeting Intelligence','source_url'=>$sourceUrl,'review_state'=>'approved','updated_at'=>gmdate('c')]);
    $body=(string)$item['summary']."\n\nSource: Meeting Intelligence · ".(string)$meeting['title']."\nReview: ".$sourceUrl;$json=json_encode($meta,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?:'{}';
    if(is_array($row)&&(int)$row['id']>0){$id=(int)$row['id'];$pdo->prepare('UPDATE agent_memory_items SET subject=?,memory_text=?,confidence=GREATEST(confidence,0.99),last_seen_at=NOW(),is_active=1,metadata_json=? WHERE id=? AND user_id=?')->execute([mb_strimwidth((string)$item['title'],0,190,'…'),mb_strimwidth($body,0,6000,'…'),$json,$id,$uid]);return $id;}
    $pdo->prepare('INSERT INTO agent_memory_items (user_id,memory_type,subject,memory_text,memory_hash,source_archive_id,confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json) VALUES (?,?,?,?,?,NULL,0.99,1,NOW(),NOW(),1,?)')->execute([$uid,$kind,mb_strimwidth((string)$item['title'],0,190,'…'),mb_strimwidth($body,0,6000,'…'),$hash,$json]);
    return (int)$pdo->lastInsertId();
}

function video_meeting_followthrough_brain_v18100(PDO $pdo,array $meeting,array $user,array $item): int
{
    if(!function_exists('agent_brain_v122_upsert_system_memory'))throw new RuntimeException('Agent Brain is unavailable.');
    $url=url('/meeting.php?meeting='.(string)$meeting['public_id'].'&review=1');$kind=(string)($item['memory_kind']??'decision');
    return agent_brain_v122_upsert_system_memory($user,'meeting_'.$kind,'meeting:'.(int)$meeting['id'].':'.(string)$item['id'],(string)$item['summary']."\n\nSource: Meeting Intelligence · ".(string)$meeting['title'],[
        'source'=>'video_meeting_intelligence','source_label'=>'Meeting Intelligence','source_url'=>$url,'video_meeting_id'=>(int)$meeting['id'],'video_meeting_public_id'=>(string)$meeting['public_id'],'followthrough_item_id'=>(string)$item['id'],'review_state'=>'approved','owner'=>(string)($item['owner']??''),'due_date'=>(string)($item['due_date']??''),'saved_at'=>gmdate('c')
    ],0.99);
}

function video_meeting_followthrough_calendar_v18100(PDO $pdo,array $meeting,array $user,array $item): int
{
    $input=video_meeting_followthrough_due_input_v18100((string)($item['due_date']??''),(string)($meeting['timezone']??'UTC'));
    $input['title']=mb_strimwidth((string)$item['title'],0,190,'…');$input['description']='From meeting: '.(string)$meeting['title'].((string)($item['owner']??'')!==''?'\nOwner: '.(string)$item['owner']:'');$input['location']='';
    $event=user_calendar_automation_create_event_v1300($pdo,$user,$input,'meeting-followthrough:'.(int)$meeting['id'].':'.(string)$item['id']);
    return (int)($event['id']??0);
}
