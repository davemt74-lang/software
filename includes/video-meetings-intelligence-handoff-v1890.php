<?php
declare(strict_types=1);

require_once __DIR__.'/video-meetings-intelligence-hybrid-v1890.php';

/**
 * Publish the reviewed current Phase 18.9 snapshot to Agent Chat. This mirrors
 * the Phase 18.2 explicit-review boundary but reads the hybrid snapshot so a
 * HomeServer-produced final analysis is not replaced by stale cloud output.
 */
function video_meeting_intelligence_handoff_v1890(PDO $pdo,array $meeting,array $user): array
{
    if(!video_meeting_intelligence_owner_allowed_v1820($user,$meeting))throw new RuntimeException('Only the organizer can publish meeting intelligence to Agent Chat.');
    if(!function_exists('agent_chat_v101_append_ecosystem_message'))throw new RuntimeException('Agent Chat is unavailable.');
    $public=video_meeting_intelligence_public_state_v1890($pdo,$meeting);$sourceHash=(string)($public['source_hash']??'');
    if($sourceHash==='')throw new RuntimeException('The meeting transcript does not contain enough information to publish yet.');
    $state=video_meeting_intelligence_state_row_v1820($pdo,$meeting);
    $finalHash=(string)($state['final_source_hash']??'');
    if($finalHash===''||!hash_equals($finalHash,$sourceHash))throw new RuntimeException('Finalize and review the current meeting intelligence before sending it to Agent Chat.');
    if((string)($state['handoff_source_hash']??'')!==''&&hash_equals((string)$state['handoff_source_hash'],$sourceHash)){
        return ['published'=>false,'already_published'=>true,'conversation_id'=>(int)($state['handoff_conversation_id']??0)];
    }
    $sessionId=(int)($public['session_id']??0);$meetingId=(int)$meeting['id'];
    $hybrid=is_array($public['hybrid_intelligence']??null)?$public['hybrid_intelligence']:[];
    $source=in_array((string)($hybrid['source']??''),['homeserver','vp3_cloud'],true)?(string)$hybrid['source']:'vp3_cloud';
    $context=[
        'source'=>'video_meeting_intelligence','source_label'=>'Meeting Intelligence','video_meeting_id'=>$meetingId,
        'video_meeting_public_id'=>(string)$meeting['public_id'],'transcript_session_id'=>$sessionId,
        'meeting_intelligence'=>true,'meeting_intelligence_route'=>$source,'skip_brain_archive'=>true,'generated_at'=>gmdate('c'),
        'actions'=>[
            ['label'=>'Open meeting review','url'=>url('/meeting.php?meeting='.(string)$meeting['public_id'].'&review=1')],
            ['label'=>'Open full transcription intelligence','url'=>url('/artist-listening.php?session='.$sessionId)],
        ],
        'sources'=>[
            ['source'=>'video_meeting:'.$meetingId,'title'=>(string)$meeting['title']],
            ['source'=>'transcript:'.$sessionId,'title'=>'Meeting transcript #'.$sessionId],
        ],
    ];
    $text=video_meeting_intelligence_handoff_text_v1820($meeting,$public);
    $brief=trim((string)($public['snapshot']['agent_brief']??''));
    if($brief!=='')$text=mb_strimwidth($text."\n\nAgent Brief\n".$brief,0,20000,'…');
    $conversationId=agent_chat_v101_append_ecosystem_message($user,$text,$context);
    if($conversationId<1)throw new RuntimeException('Agent Chat did not accept the meeting intelligence handoff.');
    $pdo->prepare('INSERT INTO video_meeting_intelligence_state (meeting_id,transcript_session_id,handoff_source_hash,handoff_conversation_id,handoff_at,last_error) VALUES (?,?,?,?,NOW(),\'\') ON DUPLICATE KEY UPDATE transcript_session_id=VALUES(transcript_session_id),handoff_source_hash=VALUES(handoff_source_hash),handoff_conversation_id=VALUES(handoff_conversation_id),handoff_at=NOW(),last_error=\'\',updated_at=NOW()')
        ->execute([$meetingId,$sessionId,$sourceHash,$conversationId]);
    if(function_exists('create_notification')){
        try{create_notification((int)$meeting['owner_user_id'],'video_meeting_intelligence_ready','Meeting intelligence ready',(string)$meeting['title'],url('/meeting.php?meeting='.(string)$meeting['public_id'].'&review=1'),'video_meeting',$meetingId);}catch(Throwable $ignored){}
    }
    return ['published'=>true,'conversation_id'=>$conversationId,'route'=>$source];
}
