<?php
declare(strict_types=1);

/**
 * VP3 Phase 18.13 — Meeting Intelligence Automation.
 *
 * Read-only deterministic preparation built exclusively from Phase 18.12
 * finalized Meeting Memory. It never probes HomeServer, reads transcript text,
 * reads organizer private notes, or performs task/CRM/calendar/mail/tool writes.
 */
const VP3_VIDEO_MEETINGS_AUTOMATION_V18130='video-meetings-automation-v18130-20260916';
const VP3_VIDEO_MEETINGS_AUTOMATION_SOURCE_LIMIT_V18130=8;
const VP3_VIDEO_MEETINGS_AUTOMATION_ITEM_LIMIT_V18130=8;

require_once __DIR__.'/video-meetings-memory-v18120.php';

function video_meeting_automation_text_v18130(mixed $value,int $limit=600): string
{
    return video_meeting_memory_text_v18120($value,$limit);
}

function video_meeting_automation_participants_v18130(PDO $pdo,array $meeting): array
{
    $stmt=$pdo->prepare("SELECT display_name FROM video_meeting_participants WHERE meeting_id=? AND TRIM(display_name)<>'' ORDER BY role='organizer' DESC,id ASC LIMIT 24");
    $stmt->execute([(int)$meeting['id']]);
    $out=[];$seen=[];
    foreach($stmt->fetchAll()?:[] as $row){
        $name=video_meeting_automation_text_v18130($row['display_name']??'',190);
        if($name==='')continue;$key=mb_strtolower($name);if(isset($seen[$key]))continue;
        $seen[$key]=true;$out[]=$name;
    }
    return $out;
}

function video_meeting_automation_subject_v18130(array $meeting,array $participants): string
{
    $parts=[];$title=video_meeting_automation_text_v18130($meeting['title']??'',190);
    if($title!=='')$parts[]=$title;
    foreach(array_slice($participants,0,5) as $name)$parts[]=$name;
    $subject=trim(implode(' ',array_unique($parts)));
    return $subject!==''?$subject:'recent work';
}

function video_meeting_automation_unresolved_v18130(array $row): bool
{
    $category=strtolower((string)($row['category']??''));
    $status=strtolower(trim((string)($row['status']??'')));
    if($category==='followthrough_pending')return true;
    if($category==='followthrough_verified'||$category==='followthrough_failed')return false;
    if(!in_array($category,['action','decision'],true))return false;
    if($status==='')return $category==='action';
    return !in_array($status,['verified','completed','complete','done','closed','cancelled','canceled','failed'],true);
}

function video_meeting_automation_pick_v18130(array $rows,array $categories,int $limit,bool $unresolvedOnly=false,int $excludeMeetingId=0): array
{
    $out=[];$seen=[];
    foreach($rows as $row){
        if(!is_array($row))continue;$category=(string)($row['category']??'');
        if(!in_array($category,$categories,true))continue;
        if($unresolvedOnly&&!video_meeting_automation_unresolved_v18130($row))continue;
        $meetingId=(int)($row['meeting_id']??0);$text=video_meeting_automation_text_v18130($row['text']??'',1200);
        if($meetingId<1||$meetingId===$excludeMeetingId||$text==='')continue;
        $fingerprint=$meetingId.'|'.$category.'|'.hash('sha256',mb_strtolower($text));
        if(isset($seen[$fingerprint]))continue;$seen[$fingerprint]=true;
        $out[]=$row;if(count($out)>=$limit)break;
    }
    return $out;
}

function video_meeting_automation_sources_v18130(array $groups): array
{
    $sources=[];$seen=[];
    foreach($groups as $rows){
        foreach((array)$rows as $row){
            if(!is_array($row))continue;$meetingId=(int)($row['meeting_id']??0);$sourceHash=(string)($row['provenance']['source_hash']??'');
            if($meetingId<1||$sourceHash==='')continue;$key=$meetingId.'|'.$sourceHash;if(isset($seen[$key]))continue;$seen[$key]=true;
            $sources[]=[
                'meeting_id'=>$meetingId,
                'meeting_public_id'=>(string)($row['meeting_public_id']??''),
                'title'=>video_meeting_automation_text_v18130($row['meeting_title']??'Meeting',190),
                'when_utc'=>video_meeting_automation_text_v18130($row['meeting_when_utc']??'',40),
                'review_path'=>(string)($row['review_path']??''),
                'source_hash'=>$sourceHash,
                'final_source_hash'=>(string)($row['provenance']['final_source_hash']??''),
                'index_hash'=>(string)($row['provenance']['index_hash']??''),
                'artifact_id'=>(int)($row['provenance']['artifact_id']??0),
            ];
            if(count($sources)>=VP3_VIDEO_MEETINGS_AUTOMATION_SOURCE_LIMIT_V18130)break 2;
        }
    }
    return $sources;
}

function video_meeting_automation_prep_v18130(PDO $pdo,array $meeting,int $ownerUserId): array
{
    if((int)($meeting['owner_user_id']??0)!==$ownerUserId)throw new RuntimeException('Meeting preparation is available only to the organizer.');
    if(strtolower((string)($meeting['status']??''))==='cancelled')throw new RuntimeException('Cancelled meetings do not have automated preparation.');

    $meetingId=(int)$meeting['id'];
    $participants=video_meeting_automation_participants_v18130($pdo,$meeting);
    $subject=video_meeting_automation_subject_v18130($meeting,$participants);
    $decisionSearch=video_meeting_memory_search_v18120($pdo,$ownerUserId,'decided '.$subject,24,0);
    $followSearch=video_meeting_memory_search_v18120($pdo,$ownerUserId,'pending action item '.$subject,24,0);
    $contextSearch=video_meeting_memory_search_v18120($pdo,$ownerUserId,'discussed '.$subject,24,0);

    $decisions=video_meeting_automation_pick_v18130((array)($decisionSearch['results']??[]),['decision','followthrough_verified'],VP3_VIDEO_MEETINGS_AUTOMATION_ITEM_LIMIT_V18130,false,$meetingId);
    $commitments=video_meeting_automation_pick_v18130((array)($followSearch['results']??[]),['followthrough_pending','action','decision'],VP3_VIDEO_MEETINGS_AUTOMATION_ITEM_LIMIT_V18130,true,$meetingId);
    $context=video_meeting_automation_pick_v18130((array)($contextSearch['results']??[]),['summary','key_point','topic','objective','question','risk'],VP3_VIDEO_MEETINGS_AUTOMATION_ITEM_LIMIT_V18130,false,$meetingId);
    $sources=video_meeting_automation_sources_v18130([$decisions,$commitments,$context]);

    return [
        'version'=>'v18.13','schema'=>'vp3.meeting.intelligence.automation.prep','automated'=>true,'read_only'=>true,
        'meeting'=>[
            'id'=>$meetingId,'public_id'=>(string)$meeting['public_id'],'title'=>video_meeting_automation_text_v18130($meeting['title']??'',190),
            'start_at_utc'=>(string)($meeting['start_at_utc']??''),'timezone'=>(string)($meeting['timezone']??'UTC'),'status'=>(string)($meeting['status']??''),
            'participants'=>$participants,
        ],
        'subject'=>$subject,
        'historical_decisions'=>$decisions,
        'unresolved_commitments'=>$commitments,
        'relevant_context'=>$context,
        'sources'=>$sources,
        'generated_at'=>gmdate('c'),
        'privacy'=>[
            'source'=>'finalized_phase_18_12_meeting_memory','raw_transcript_read'=>false,'private_notes_read'=>false,
            'participant_email_read'=>false,'homeserver_historical_probe'=>false,'side_effects_executed'=>false,
        ],
        'instructions'=>'Preparation is source-backed historical context only. The current meeting is excluded from historical retrieval, and pending commitments remain pending until separately verified. Review sources before acting; no task, CRM, calendar, mail, Agent Brain, or tool action is executed by this automation.',
    ];
}
