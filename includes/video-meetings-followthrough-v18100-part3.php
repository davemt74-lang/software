<?php
declare(strict_types=1);

function video_meeting_followthrough_assignment_v18100(PDO $pdo,array $user,string $owner): array
{
    $owner=video_meeting_followthrough_text_v18100($owner,190);$needle=mb_strtolower($owner);$uid=(int)($user['id']??0);
    $display=mb_strtolower(trim((string)($user['display_name']??'')));$email=mb_strtolower(trim((string)($user['email']??'')));
    if($owner!==''&&in_array($needle,array_filter([$display,$email,'me','organizer','owner']),true))return ['user_id'=>$uid,'agent_id'=>0,'label'=>$owner];
    if($owner!==''&&function_exists('user_agents_list_v236')){
        foreach(user_agents_list_v236($pdo,$uid,true) as $agent){
            if($needle===mb_strtolower(trim((string)($agent['display_name']??'')))||$needle===mb_strtolower(trim((string)($agent['agent_key']??''))))return ['user_id'=>0,'agent_id'=>(int)$agent['id'],'label'=>$owner];
        }
    }
    if($owner!==''&&filter_var($owner,FILTER_VALIDATE_EMAIL)){
        $stmt=$pdo->prepare('SELECT id FROM users WHERE LOWER(email)=? AND is_active=1 LIMIT 2');$stmt->execute([mb_strtolower($owner)]);$ids=array_column($stmt->fetchAll()?:[],'id');
        if(count($ids)===1)return ['user_id'=>(int)$ids[0],'agent_id'=>0,'label'=>$owner];
    }
    return ['user_id'=>0,'agent_id'=>0,'label'=>$owner];
}

function video_meeting_followthrough_item_block_v18100(PDO $pdo,array $meeting,array $user,array $item): string
{
    $type=(string)($item['type']??'');
    if(in_array($type,['agent_task','agent_brain'],true)&&(!function_exists('agent_brain_schema_ready')||!agent_brain_schema_ready()))return 'Agent Brain storage is unavailable.';
    if($type==='calendar_event'){
        if(!user_calendar_schema_ready_v1300($pdo))return 'User Calendar is unavailable.';
        try{video_meeting_followthrough_due_input_v18100((string)($item['due_date']??''),(string)($meeting['timezone']??'UTC'));}catch(Throwable $e){return $e->getMessage();}
    }
    if(in_array($type,['crm_note','crm_task'],true)){
        if(!function_exists('crm_v180_can_manage')||!crm_v180_can_manage($user)||!crm_v180_schema_ready($pdo))return 'CRM write access is unavailable for this account.';
        $leadId=max(0,(int)($item['lead_id']??0));if($leadId<1)return 'Choose an explicit CRM lead before approval.';
        $stmt=$pdo->prepare('SELECT id FROM crm_leads WHERE id=? LIMIT 1');$stmt->execute([$leadId]);if(!(int)$stmt->fetchColumn())return 'The selected CRM lead is unavailable.';
    }
    if($type==='followup_workflow'){
        $booking=video_meeting_followthrough_booking_v18100($pdo,$meeting);
        if(!$booking)return 'This meeting is not linked to a canonical appointment.';
        $status=(string)($booking['lifecycle_status']??$booking['status']??'');if($status!=='completed'&&(string)($booking['status']??'')!=='completed')return 'Complete the appointment lifecycle before preparing external follow-up.';
        if(!agent_meeting_workflow_ready_v1410($pdo))return 'Phase 14 meeting workflows are unavailable.';
    }
    return '';
}

function video_meeting_followthrough_public_queue_v18100(PDO $pdo,array $meeting,array $queue): array
{
    $user=current_user()?:[];$counts=['suggested'=>0,'approved'=>0,'executed'=>0,'verified'=>0,'failed'=>0];$public=[];
    foreach((array)($queue['items']??[]) as $item){
        if(!is_array($item))continue;$status=(string)($item['status']??'suggested');if(isset($counts[$status]))$counts[$status]++;
        $block=video_meeting_followthrough_item_block_v18100($pdo,$meeting,$user,$item);
        $copy=$item;$copy['blocked_reason']=$block;$copy['executable']=$block===''&&$status==='approved';
        $copy['approvable']=$block===''&&in_array($status,['suggested','failed'],true);$public[]=$copy;
    }
    return ['version'=>'v18.10','ready'=>!empty($queue['ready']),'source_hash'=>(string)($queue['source_hash']??''),'generated_at'=>(string)($queue['generated_at']??''),'counts'=>$counts,'items'=>$public];
}

function video_meeting_followthrough_load_current_v18100(PDO $pdo,array $meeting): array
{
    $public=video_meeting_followthrough_require_final_v18100($pdo,$meeting);$hash=(string)$public['source_hash'];
    $queue=video_meeting_followthrough_artifact_v18100($pdo,$meeting,$hash);
    if(!$queue){video_meeting_followthrough_queue_v18100($pdo,$meeting,true);$queue=video_meeting_followthrough_artifact_v18100($pdo,$meeting,$hash);}
    if(!$queue)throw new RuntimeException('The post-meeting action queue could not be loaded.');
    return $queue;
}

function video_meeting_followthrough_find_index_v18100(array $queue,string $itemId): int
{
    foreach((array)($queue['items']??[]) as $i=>$item)if(is_array($item)&&hash_equals((string)($item['id']??''),$itemId))return (int)$i;
    throw new RuntimeException('Action queue item not found.');
}

function video_meeting_followthrough_update_v18100(PDO $pdo,array $meeting,array $user,string $itemId,array $changes): array
{
    $queue=video_meeting_followthrough_load_current_v18100($pdo,$meeting);$i=video_meeting_followthrough_find_index_v18100($queue,$itemId);$item=$queue['items'][$i];
    if(in_array((string)$item['status'],['executed','verified'],true))throw new RuntimeException('Executed meeting actions are immutable.');
    foreach(['title'=>1600,'summary'=>1600,'owner'=>190,'due_date'=>80,'followup_subject'=>190] as $key=>$limit)if(array_key_exists($key,$changes))$item[$key]=video_meeting_followthrough_text_v18100($changes[$key],$limit);
    if(array_key_exists('followup_body',$changes))$item['followup_body']=video_meeting_followthrough_body_v18100($changes['followup_body'],9000);
    if(array_key_exists('lead_id',$changes))$item['lead_id']=max(0,(int)$changes['lead_id']);
    $item['status']='suggested';$item['approved_at']='';$item['error']='';$item['failed_at']='';$item['updated_at']=gmdate('c');$queue['items'][$i]=$item;
    video_meeting_followthrough_store_v18100($pdo,$meeting,$queue);
    return video_meeting_followthrough_public_queue_v18100($pdo,$meeting,$queue);
}
