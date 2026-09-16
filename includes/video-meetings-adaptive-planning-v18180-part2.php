<?php
declare(strict_types=1);

function video_meeting_adaptive_planning_target_local_v18180(?string $targetAt,string $timezone): string
{
    $targetAt=trim((string)$targetAt);if($targetAt==='')return '';
    try{$dt=(new DateTimeImmutable($targetAt,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone));return $dt->format('Y-m-d\TH:i');}catch(Throwable $e){return '';}
}

function video_meeting_adaptive_planning_public_v18180(?array $plan,array $meeting): array
{
    $timezone=video_meeting_adaptive_planning_timezone_v18180($meeting);
    if(!$plan)return ['id'=>0,'owner_label'=>'','target_at'=>'','target_local'=>'','target_timezone'=>$timezone,'verification_criteria'=>'','readiness'=>'needs_definition','updated_at'=>''];
    return [
        'id'=>(int)$plan['id'],'owner_label'=>(string)$plan['owner_label'],'target_at'=>(string)($plan['target_at']??''),
        'target_local'=>video_meeting_adaptive_planning_target_local_v18180($plan['target_at']??null,$timezone),'target_timezone'=>$timezone,
        'verification_criteria'=>(string)$plan['verification_criteria'],'readiness'=>(string)$plan['readiness'],'updated_at'=>(string)$plan['updated_at'],
    ];
}

function video_meeting_adaptive_planning_learning_map_v18180(PDO $pdo,array $meeting,int $ownerUserId): array
{
    $learning=video_meeting_outcome_learning_for_meeting_v18170($pdo,$meeting,$ownerUserId);$map=[];
    foreach((array)($learning['agenda_emphasis']??[]) as $row){if(!is_array($row))continue;$id=(int)($row['agenda_item_id']??0);if($id>0)$map[$id]=(array)($row['learning']??[]);}
    return ['map'=>$map,'minimum_evidence'=>(int)($learning['minimum_evidence']??VP3_VIDEO_MEETINGS_OUTCOME_MIN_EVIDENCE_V18170)];
}

function video_meeting_adaptive_planning_advice_v18180(array $item,array $plan,array $learning,int $minimumEvidence): array
{
    $missing=[];if(trim((string)$plan['owner_label'])==='')$missing[]='owner';if(trim((string)$plan['target_at'])==='')$missing[]='target';if(trim((string)$plan['verification_criteria'])==='')$missing[]='verification criteria';
    $tone=(string)($learning['tone']??'');$observed=max(0,(int)($learning['observed_count']??0));$advice='Define an owner, target, and verification criterion before this follow-up leaves the meeting.';
    if(!$missing)$advice='This follow-through plan is defined. Review it with the room before approving any external action.';
    if($observed>=$minimumEvidence&&$tone==='friction')$advice='Historical outcomes for this kind of follow-through show repeated friction. Make the owner, target, and verification criterion explicit before approval.';
    elseif($observed>=$minimumEvidence&&$tone==='reliable')$advice='This follow-through structure has verified reliably in prior outcomes. Reuse the structure, but confirm the owner, target, and verification criterion for this meeting.';
    elseif($observed>=$minimumEvidence&&$tone==='mixed')$advice='Historical outcomes are mixed. Tighten any missing planning fields before approval.';
    return ['tone'=>$tone!==''?$tone:'no_evidence','observed_count'=>$observed,'missing'=>$missing,'ready'=>!$missing,'advice'=>$advice,'historical_guidance'=>(string)($learning['guidance']??'')];
}

function video_meeting_adaptive_planning_state_v18180(PDO $pdo,array $meeting,array $user): array
{
    $ownerUserId=(int)($user['id']??0);video_meeting_agenda_owner_guard_v18140($meeting,$ownerUserId);
    if(!video_meeting_adaptive_planning_schema_ready_v18180($pdo))throw new RuntimeException('Adaptive Meeting Planning is not ready. Run the current database upgrade first.');
    $learned=video_meeting_adaptive_planning_learning_map_v18180($pdo,$meeting,$ownerUserId);$map=$learned['map'];$minimum=(int)$learned['minimum_evidence'];
    $s=$pdo->prepare("SELECT id,item_text,item_type,source_kind,status,priority,action_kind,approval_state,sort_order FROM video_meeting_agenda_items WHERE meeting_id=? AND owner_user_id=? AND status<>'skipped' AND (status='follow_up' OR item_type='follow_up') ORDER BY sort_order,id LIMIT ".VP3_VIDEO_MEETINGS_PLANNING_LIMIT_V18180);$s->execute([(int)$meeting['id'],$ownerUserId]);
    $items=[];$counts=['total'=>0,'ready'=>0,'needs_definition'=>0];
    foreach($s->fetchAll()?:[] as $item){if(!is_array($item))continue;$plan=video_meeting_adaptive_planning_public_v18180(video_meeting_adaptive_planning_row_v18180($pdo,$ownerUserId,(int)$item['id']),$meeting);$advice=video_meeting_adaptive_planning_advice_v18180($item,$plan,(array)($map[(int)$item['id']]??[]),$minimum);$counts['total']++;$counts[$advice['ready']?'ready':'needs_definition']++;$items[]=[
        'agenda_item'=>['id'=>(int)$item['id'],'text'=>(string)$item['item_text'],'source_kind'=>(string)$item['source_kind'],'status'=>(string)$item['status'],'priority'=>(string)$item['priority'],'action_kind'=>(string)$item['action_kind'],'approval_state'=>(string)$item['approval_state']],
        'plan'=>$plan,'advice'=>$advice,
    ];}
    return [
        'version'=>'v18.18','schema'=>'vp3.meeting.adaptive_planning','meeting'=>['id'=>(int)$meeting['id'],'public_id'=>(string)$meeting['public_id'],'title'=>(string)$meeting['title'],'timezone'=>video_meeting_adaptive_planning_timezone_v18180($meeting)],
        'items'=>$items,'counts'=>$counts,'minimum_learning_evidence'=>$minimum,
        'policy'=>['advisory_learning_only'=>true,'auto_assign_owner'=>false,'auto_change_priority'=>false,'auto_change_action'=>false,'external_side_effects'=>false],
        'privacy'=>['participant_lookup'=>false,'participant_scoring'=>false,'participant_email_read'=>false,'raw_transcript_read'=>false,'private_notes_read'=>false,'homeserver_historical_probe'=>false],
        'generated_at'=>gmdate('c'),
    ];
}
