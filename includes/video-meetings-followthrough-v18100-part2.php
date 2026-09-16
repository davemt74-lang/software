<?php
declare(strict_types=1);

function video_meeting_followthrough_candidates_v18100(PDO $pdo,array $meeting,array $public): array
{
    $hash=(string)$public['source_hash'];$snapshot=is_array($public['snapshot']??null)?$public['snapshot']:[];
    $items=[];$push=static function(array $item) use (&$items): void {if(($item['title']??'')!=='')$items[(string)$item['id']]=$item;};

    foreach(array_slice((array)($snapshot['actions']??[]),0,24) as $row){
        if(!is_array($row))continue;
        $text=video_meeting_followthrough_primary_v18100($row,['action','follow_up','next_step','text','title']);
        if($text==='')continue;
        $owner=video_meeting_followthrough_text_v18100($row['owner']??'',190);
        $due=video_meeting_followthrough_text_v18100($row['due_date']??$row['timing']??'',80);
        $push(video_meeting_followthrough_candidate_v18100($hash,'agent_task',$text,['owner'=>$owner,'due_date'=>$due,'task_kind'=>'task']));
        if($due!=='')$push(video_meeting_followthrough_candidate_v18100($hash,'calendar_event',$text,['owner'=>$owner,'due_date'=>$due]));
    }
    foreach(array_slice((array)($snapshot['decisions']??[]),0,24) as $row){
        if(!is_array($row))continue;
        $text=video_meeting_followthrough_primary_v18100($row,['commitment','decision','text']);
        if($text==='')continue;
        $owner=video_meeting_followthrough_text_v18100($row['owner']??'',190);
        $due=video_meeting_followthrough_text_v18100($row['due_date']??$row['timing']??'',80);
        $kind=trim((string)($row['commitment']??''))!==''?'commitment':'decision';
        $push(video_meeting_followthrough_candidate_v18100($hash,'agent_brain',$text,['owner'=>$owner,'due_date'=>$due,'memory_kind'=>$kind]));
        if($kind==='commitment')$push(video_meeting_followthrough_candidate_v18100($hash,'agent_task',$text,['owner'=>$owner,'due_date'=>$due,'task_kind'=>'commitment']));
        if($due!=='')$push(video_meeting_followthrough_candidate_v18100($hash,'calendar_event',$text,['owner'=>$owner,'due_date'=>$due]));
    }

    $crm=is_array($snapshot['crm']??null)?$snapshot['crm']:[];
    foreach(array_slice((array)($crm['recommended_updates']??[]),0,16) as $row){
        if(!is_array($row))continue;$text=video_meeting_followthrough_primary_v18100($row,['update','suggested_update','action','text','signal']);if($text==='')continue;
        $push(video_meeting_followthrough_candidate_v18100($hash,'crm_note',$text,['lead_id'=>max(0,(int)($row['lead_id']??0)),'owner'=>video_meeting_followthrough_text_v18100($row['contact']??'',190)]));
    }
    foreach(array_slice((array)($crm['next_best_actions']??[]),0,16) as $row){
        if(!is_array($row))continue;$text=video_meeting_followthrough_primary_v18100($row,['action','suggested_update','update','text','signal']);if($text==='')continue;
        $push(video_meeting_followthrough_candidate_v18100($hash,'crm_task',$text,['lead_id'=>max(0,(int)($row['lead_id']??0)),'owner'=>video_meeting_followthrough_text_v18100($row['contact']??'',190),'due_date'=>video_meeting_followthrough_text_v18100($row['due_date']??'',80)]));
    }

    $booking=video_meeting_followthrough_booking_v18100($pdo,$meeting);
    $draft=trim((string)($snapshot['follow_up_draft']??''));
    if($booking){
        $subject='Follow-up: '.video_meeting_followthrough_text_v18100($meeting['title']??'Meeting',150);
        $label=$draft!==''?'Review and send the generated meeting follow-up':'Prepare a post-meeting follow-up draft for review';
        $push(video_meeting_followthrough_candidate_v18100($hash,'followup_workflow',$label,[
            'booking_id'=>(int)$booking['id'],'followup_subject'=>$subject,'followup_body'=>video_meeting_followthrough_body_v18100($draft,9000),
            'owner'=>video_meeting_followthrough_text_v18100($booking['guest_name']??'',190),
        ]));
    }
    return array_values($items);
}

function video_meeting_followthrough_queue_v18100(PDO $pdo,array $meeting,bool $refresh=false): array
{
    try{$public=video_meeting_followthrough_require_final_v18100($pdo,$meeting);}catch(Throwable $e){
        return ['version'=>'v18.10','ready'=>false,'source_hash'=>'','items'=>[],'counts'=>[],'reason'=>$e->getMessage()];
    }
    $hash=(string)$public['source_hash'];$existing=video_meeting_followthrough_artifact_v18100($pdo,$meeting,$hash);
    if($existing&&!$refresh)return video_meeting_followthrough_public_queue_v18100($pdo,$meeting,$existing);
    $old=[];
    foreach((array)($existing['items']??[]) as $item)if(is_array($item)&&!empty($item['id']))$old[(string)$item['id']]=$item;
    $items=video_meeting_followthrough_candidates_v18100($pdo,$meeting,$public);
    foreach($items as &$item){
        $id=(string)$item['id'];$previous=$old[$id]??null;
        if(is_array($previous)){
            foreach(['status','title','summary','owner','due_date','lead_id','followup_subject','followup_body','record_id','record_kind','target_url','approved_at','executed_at','verified_at','failed_at','error'] as $key){
                if(array_key_exists($key,$previous))$item[$key]=$previous[$key];
            }
        }
    }unset($item);
    $queue=['version'=>'v18.10','ready'=>true,'meeting_id'=>(int)$meeting['id'],'meeting_public_id'=>(string)$meeting['public_id'],'source_hash'=>$hash,'generated_at'=>gmdate('c'),'items'=>$items];
    video_meeting_followthrough_store_v18100($pdo,$meeting,$queue);
    return video_meeting_followthrough_public_queue_v18100($pdo,$meeting,$queue);
}

function video_meeting_followthrough_due_input_v18100(string $raw,string $fallbackTimezone='UTC'): array
{
    $raw=trim($raw);if($raw==='')throw new RuntimeException('Add a due date before creating this calendar item.');
    $timezone=user_calendar_timezone_v1300($fallbackTimezone,'UTC');
    if(preg_match('/^(\d{4}-\d{2}-\d{2})$/',$raw,$m))return ['date'=>$m[1],'all_day'=>1,'timezone'=>$timezone];
    if(preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(?::\d{2})?$/',$raw,$m))return ['date'=>$m[1],'start_time'=>$m[2],'duration_minutes'=>60,'all_day'=>0,'timezone'=>$timezone];
    throw new RuntimeException('Use an exact due date such as 2026-09-18 or 2026-09-18 14:00.');
}
