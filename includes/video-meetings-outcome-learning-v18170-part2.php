<?php
declare(strict_types=1);

function video_meeting_outcome_learning_for_meeting_v18170(PDO $pdo,array $meeting,int $ownerUserId): array
{
    if($ownerUserId<1||(int)($meeting['owner_user_id']??0)!==$ownerUserId)throw new RuntimeException('Meeting Outcome Learning is organizer-only.');
    if(!video_meeting_outcome_learning_schema_ready_v18170($pdo))return ['version'=>'v18.17','available'=>false,'guidance'=>[],'agenda_emphasis'=>[],'suggested_questions'=>[]];
    $patterns=[];foreach(video_meeting_outcome_learning_rows_v18170($pdo,$ownerUserId) as $row){if(!is_array($row))continue;$guidance=video_meeting_outcome_learning_guidance_v18170($row);if(!$guidance)continue;$patterns[]=$guidance;}
    usort($patterns,static function(array $a,array $b): int{$af=$a['tone']==='friction'?2:($a['tone']==='reliable'?1:0);$bf=$b['tone']==='friction'?2:($b['tone']==='reliable'?1:0);return ($bf<=>$af)?:((int)$b['observed_count']<=>(int)$a['observed_count'])?:strcmp((string)$a['action_kind'],(string)$b['action_kind']);});
    $patterns=array_slice($patterns,0,8);
    $lookup=[];foreach($patterns as $pattern)$lookup[(string)$pattern['action_kind'].'|'.(string)$pattern['source_kind'].'|'.(string)$pattern['priority']]=$pattern;
    $agenda=[];
    if(video_meeting_agenda_schema_ready_v18140($pdo)){
        $s=$pdo->prepare("SELECT id,item_text,source_kind,priority,action_kind,approval_state,status FROM video_meeting_agenda_items WHERE meeting_id=? AND owner_user_id=? ORDER BY sort_order,id LIMIT 80");$s->execute([(int)$meeting['id'],$ownerUserId]);
        foreach($s->fetchAll()?:[] as $item){if(!is_array($item))continue;$kind=video_meeting_agenda_normalize_action_v18140((string)($item['action_kind']??''));if($kind==='')continue;$source=video_meeting_outcome_learning_source_v18170((string)($item['source_kind']??'manual'));$key=$kind.'|'.$source.'|'.video_meeting_agenda_normalize_priority_v18140((string)($item['priority']??'normal'));$pattern=$lookup[$key]??null;if(!$pattern)continue;$agenda[]=['agenda_item_id'=>(int)$item['id'],'action_kind'=>$kind,'item_text'=>video_meeting_action_text_v18150($item['item_text']??'',800),'status'=>(string)$item['status'],'approval_state'=>(string)$item['approval_state'],'learning'=>$pattern];}
    }
    $questions=[];foreach($patterns as $pattern){if((string)$pattern['tone']!=='friction')continue;$kind=ucfirst((string)$pattern['action_kind']);$questions[]=$kind.' follow-through has had repeated friction. Who owns the outcome, what is the target, and what evidence will verify completion?';if(count($questions)>=3)break;}
    return [
        'version'=>'v18.17','available'=>true,'minimum_evidence'=>VP3_VIDEO_MEETINGS_OUTCOME_MIN_EVIDENCE_V18170,
        'guidance'=>$patterns,'agenda_emphasis'=>$agenda,'suggested_questions'=>$questions,
        'policy'=>['advisory_only'=>true,'automatic_priority_changes'=>false,'automatic_action_changes'=>false,'participant_scoring'=>false],
        'privacy'=>['participant_names_persisted'=>false,'participant_email_read'=>false,'participant_email_persisted'=>false,'meeting_text_persisted'=>false,'raw_transcript_read'=>false,'private_notes_read'=>false,'homeserver_historical_probe'=>false],
        'generated_at'=>gmdate('c'),
    ];
}
