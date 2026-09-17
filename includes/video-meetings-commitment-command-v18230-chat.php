<?php
declare(strict_types=1);

require_once __DIR__.'/video-meetings-commitment-command-v18230.php';

function video_meeting_commitment_command_enrich_v18230(PDO $pdo,int $ownerUserId,string $query,array $meetingMemory): array
{
    if(!video_meeting_commitment_command_query_relevant_v18230($query))return $meetingMemory;
    $state=video_meeting_commitment_command_state_v18230($pdo,$ownerUserId,60);
    $meetingMemory['relevant']=true;
    $meetingMemory['source']=$meetingMemory['source']??'vp3_meeting_memory';
    $meetingMemory['commitment_command_version']='v18.23';
    $meetingMemory['meeting_commitment_command']=$state;
    $base=trim((string)($meetingMemory['instructions']??''));
    $extra='Meeting Commitment Command is organizer-scoped Phase 18.23 state assembled from canonical agenda, plan, handoff/drift, Meeting Action, follow-through, verification, and continuity records. Treat command buckets as a read-only status summary: attention_required identifies recorded exceptions or organizer review points; verified means the intended outcome has an explicit verified closure; carried_forward means continuity exists and does not prove completion. A stale handoff means the current plan or plan-bound action draft differs from the recorded handoff snapshot. Do not imply that viewing this command changed, reconciled, executed, verified, carried, or closed anything. Use the Meeting workspace for any mutation or execution.';
    $meetingMemory['instructions']=$base!==''?$base.' '.$extra:$extra;
    return $meetingMemory;
}

function video_meeting_commitment_command_chat_sources_v18230(array $meetingMemory): array
{
    $command=$meetingMemory['meeting_commitment_command']??null;
    if(!is_array($command)||empty($command['available']))return [];
    $sources=[];$seen=[];
    foreach((array)($command['commitments']??[]) as $item){
        if(!is_array($item))continue;$meeting=is_array($item['meeting']??null)?$item['meeting']:[];
        $id=(int)($meeting['id']??0);$publicId=trim((string)($meeting['public_id']??''));
        if($id<1||$publicId===''||isset($seen[$id]))continue;$seen[$id]=true;
        $title=video_meeting_memory_text_v18120($meeting['title']??'Meeting commitments',190);
        $when=video_meeting_memory_text_v18120($meeting['start_at_utc']??'',40);
        $sources[]=['source'=>'video_meeting_commitment_command:'.$id,'title'=>$title.($when!==''?' · '.$when:'').' · commitments','url'=>url('/meeting.php?meeting='.rawurlencode($publicId))];
        if(count($sources)>=10)break;
    }
    return $sources;
}
