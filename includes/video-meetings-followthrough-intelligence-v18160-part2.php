<?php
declare(strict_types=1);

function video_meeting_followthrough_names_v18160(PDO $pdo,int $meetingId): array
{
    $s=$pdo->prepare("SELECT display_name FROM video_meeting_participants WHERE meeting_id=? AND TRIM(display_name)<>'' ORDER BY id LIMIT 24");$s->execute([$meetingId]);$out=[];$seen=[];
    foreach($s->fetchAll()?:[] as $row){$name=video_meeting_action_text_v18150($row['display_name']??'',190);if($name==='')continue;$key=mb_strtolower($name);if(isset($seen[$key]))continue;$seen[$key]=true;$out[]=$name;}
    return $out;
}

function video_meeting_followthrough_name_overlap_v18160(array $a,array $b): bool
{
    if(!$a||!$b)return false;$set=[];foreach($a as $name)$set[mb_strtolower(trim((string)$name))]=true;foreach($b as $name){if(isset($set[mb_strtolower(trim((string)$name))]))return true;}return false;
}

function video_meeting_followthrough_title_tokens_v18160(string $title): array
{
    $text=mb_strtolower(preg_replace('/[^\pL\pN]+/u',' ',trim($title))??'');$stop=array_fill_keys(['meeting','with','and','the','for','from','about','review','call','sync','weekly','monthly'],true);$tokens=[];
    foreach(preg_split('/\s+/u',$text)?:[] as $token){if(mb_strlen($token)<4||isset($stop[$token]))continue;$tokens[$token]=true;}return array_keys($tokens);
}

function video_meeting_followthrough_title_overlap_v18160(string $a,string $b): bool
{
    $left=video_meeting_followthrough_title_tokens_v18160($a);if(!$left)return false;$set=array_fill_keys($left,true);foreach(video_meeting_followthrough_title_tokens_v18160($b) as $token){if(isset($set[$token]))return true;}return false;
}

function video_meeting_followthrough_prep_row_v18160(array $row): array
{
    return [
        'monitor_id'=>(int)$row['id'],'execution_id'=>(int)$row['execution_id'],'meeting_id'=>(int)$row['meeting_id'],'meeting_public_id'=>(string)$row['public_id'],
        'meeting_title'=>video_meeting_action_text_v18150($row['meeting_title']??'Meeting',190),'meeting_when_utc'=>(string)($row['start_at_utc']??''),
        'action_kind'=>(string)$row['action_kind'],'status'=>(string)$row['status'],'text'=>video_meeting_action_text_v18150($row['item_text']??'',1000),
        'expected_by_at'=>(string)($row['expected_by_at']??''),'evidence_summary'=>video_meeting_action_text_v18150($row['evidence_summary']??'',900),
        'canonical_status'=>(string)$row['canonical_status'],'review_path'=>'/meeting.php?meeting='.rawurlencode((string)$row['public_id']),
    ];
}

function video_meeting_followthrough_prep_v18160(PDO $pdo,array $meeting,int $ownerUserId): array
{
    if((int)($meeting['owner_user_id']??0)!==$ownerUserId)throw new RuntimeException('Follow-through preparation is organizer-only.');
    if(!video_meeting_followthrough_intelligence_schema_ready_v18160($pdo))return ['version'=>'v18.16','available'=>false,'needs_attention'=>[],'recent_outcomes'=>[]];
    video_meeting_followthrough_reconcile_owner_v18160($pdo,$ownerUserId);
    $currentNames=video_meeting_followthrough_names_v18160($pdo,(int)$meeting['id']);$currentTitle=(string)($meeting['title']??'');$cache=[];
    $s=$pdo->prepare("SELECT m.*,e.action_kind,e.result_summary,a.item_text,v.public_id,v.title AS meeting_title,v.start_at_utc FROM video_meeting_followthrough_monitors m JOIN video_meeting_action_executions e ON e.id=m.execution_id JOIN video_meeting_agenda_items a ON a.id=e.agenda_item_id JOIN video_meetings v ON v.id=m.meeting_id WHERE m.owner_user_id=? AND m.meeting_id<>? AND m.status IN ('blocked','overdue','due_soon','verified') ORDER BY FIELD(m.status,'blocked','overdue','due_soon','verified'),m.updated_at DESC,m.id DESC LIMIT 60");
    $s->execute([$ownerUserId,(int)$meeting['id']]);$attention=[];$outcomes=[];
    foreach($s->fetchAll()?:[] as $row){if(!is_array($row))continue;$mid=(int)$row['meeting_id'];if(!isset($cache[$mid]))$cache[$mid]=video_meeting_followthrough_names_v18160($pdo,$mid);$relevant=!$currentNames||video_meeting_followthrough_name_overlap_v18160($currentNames,$cache[$mid])||video_meeting_followthrough_title_overlap_v18160($currentTitle,(string)$row['meeting_title']);if(!$relevant)continue;
        $public=video_meeting_followthrough_prep_row_v18160($row);if((string)$row['status']==='verified'){if(count($outcomes)<4)$outcomes[]=$public;}elseif(count($attention)<6)$attention[]=$public;if(count($attention)>=6&&count($outcomes)>=4)break;
    }
    return ['version'=>'v18.16','available'=>true,'needs_attention'=>$attention,'recent_outcomes'=>$outcomes,'generated_at'=>gmdate('c'),'privacy'=>['participant_email_read'=>false,'raw_transcript_read'=>false,'private_notes_read'=>false,'homeserver_historical_probe'=>false,'external_side_effects'=>false]];
}

function video_meeting_followthrough_owner_summary_v18160(PDO $pdo,int $ownerUserId,int $limit=12): array
{
    if($ownerUserId<1||!video_meeting_followthrough_intelligence_schema_ready_v18160($pdo))return [];$limit=max(1,min(30,$limit));video_meeting_followthrough_reconcile_owner_v18160($pdo,$ownerUserId);
    $s=$pdo->prepare("SELECT m.*,e.action_kind,e.result_summary,a.item_text,v.public_id,v.title AS meeting_title,v.start_at_utc FROM video_meeting_followthrough_monitors m JOIN video_meeting_action_executions e ON e.id=m.execution_id JOIN video_meeting_agenda_items a ON a.id=e.agenda_item_id JOIN video_meetings v ON v.id=m.meeting_id WHERE m.owner_user_id=? AND m.status<>'dismissed' ORDER BY FIELD(m.status,'blocked','overdue','due_soon','watching','verified'),COALESCE(m.expected_by_at,'9999-12-31'),m.updated_at DESC LIMIT ".$limit);$s->execute([$ownerUserId]);$out=[];foreach($s->fetchAll()?:[] as $row){if(!is_array($row))continue;$out[]=video_meeting_followthrough_prep_row_v18160($row);}return $out;
}
