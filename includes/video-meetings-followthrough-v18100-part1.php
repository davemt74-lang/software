<?php
declare(strict_types=1);

function video_meeting_followthrough_text_v18100(mixed $value,int $limit=1400): string
{
    return mb_strimwidth(trim(preg_replace('/\s+/u',' ',(string)$value)??''),0,max(0,$limit),'…');
}

function video_meeting_followthrough_body_v18100(mixed $value,int $limit=9000): string
{
    $text=str_replace(["\r\n","\r"],"\n",trim((string)$value));
    return mb_strimwidth($text,0,max(0,$limit),'…');
}

function video_meeting_followthrough_item_id_v18100(string $sourceHash,string $type,string $text,string $owner='',string $due=''): string
{
    return substr(hash('sha256',$sourceHash.'|'.$type.'|'.mb_strtolower(video_meeting_followthrough_text_v18100($text,1800)).'|'.mb_strtolower($owner).'|'.$due),0,24);
}

function video_meeting_followthrough_require_final_v18100(PDO $pdo,array $meeting): array
{
    if(!in_array((string)($meeting['status']??''),['ended','processed'],true))throw new RuntimeException('Post-meeting follow-through is available after the meeting ends.');
    $public=video_meeting_intelligence_public_state_v1890($pdo,$meeting);
    $hash=(string)($public['source_hash']??'');
    if($hash===''||empty($public['final_analysis_at'])||!empty($public['final_analysis_due']))throw new RuntimeException('Finalize and review the current meeting intelligence first.');
    $state=video_meeting_intelligence_state_row_v1820($pdo,$meeting);
    if((string)($state['final_source_hash']??'')===''||!hash_equals((string)$state['final_source_hash'],$hash))throw new RuntimeException('The final meeting intelligence is stale. Finalize it again before follow-through.');
    return $public;
}

function video_meeting_followthrough_artifact_v18100(PDO $pdo,array $meeting,string $sourceHash): ?array
{
    if($sourceHash==='')return null;
    $stmt=$pdo->prepare('SELECT result_json,generated_at FROM video_meeting_artifacts WHERE meeting_id=? AND app_id=? AND source_hash=? ORDER BY id DESC LIMIT 1');
    $stmt->execute([(int)$meeting['id'],VP3_VIDEO_MEETING_FOLLOWTHROUGH_APP_V18100,$sourceHash]);
    $row=$stmt->fetch();
    if(!is_array($row))return null;
    $queue=json_decode((string)($row['result_json']??''),true);
    return is_array($queue)?$queue:null;
}

function video_meeting_followthrough_store_v18100(PDO $pdo,array $meeting,array $queue): array
{
    $json=json_encode($queue,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    if(!is_string($json))throw new RuntimeException('Could not persist the meeting action queue.');
    $stmt=$pdo->prepare("INSERT INTO video_meeting_artifacts (meeting_id,app_id,artifact_type,result_json,source_hash,generated_at) VALUES (?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE result_json=VALUES(result_json),artifact_type=VALUES(artifact_type),generated_at=NOW()");
    $stmt->execute([(int)$meeting['id'],VP3_VIDEO_MEETING_FOLLOWTHROUGH_APP_V18100,VP3_VIDEO_MEETING_FOLLOWTHROUGH_ARTIFACT_V18100,$json,(string)$queue['source_hash']]);
    return $queue;
}

function video_meeting_followthrough_primary_v18100(array $row,array $keys): string
{
    foreach($keys as $key){$value=video_meeting_followthrough_text_v18100($row[$key]??'',1600);if($value!=='')return $value;}
    return '';
}

function video_meeting_followthrough_candidate_v18100(string $sourceHash,string $type,string $title,array $extra=[]): array
{
    $title=video_meeting_followthrough_text_v18100($title,1600);
    $owner=video_meeting_followthrough_text_v18100($extra['owner']??'',190);
    $due=video_meeting_followthrough_text_v18100($extra['due_date']??'',80);
    return array_merge([
        'id'=>video_meeting_followthrough_item_id_v18100($sourceHash,$type,$title,$owner,$due),
        'source_hash'=>$sourceHash,'type'=>$type,'status'=>'suggested','title'=>$title,'summary'=>$title,'owner'=>$owner,'due_date'=>$due,
        'lead_id'=>0,'followup_subject'=>'','followup_body'=>'','record_id'=>0,'record_kind'=>'','target_url'=>'',
        'approved_at'=>'','executed_at'=>'','verified_at'=>'','failed_at'=>'','error'=>'','updated_at'=>gmdate('c'),
    ],$extra);
}

function video_meeting_followthrough_booking_v18100(PDO $pdo,array $meeting): ?array
{
    $bookingId=max(0,(int)($meeting['booking_id']??0));
    if($bookingId<1||!function_exists('agent_appointment_lifecycle_booking_v700'))return null;
    $booking=agent_appointment_lifecycle_booking_v700($pdo,$bookingId);
    return is_array($booking)?$booking:null;
}
