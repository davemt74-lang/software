<?php
declare(strict_types=1);

require_once __DIR__.'/agent-media-playback-v1.php';
require_once __DIR__.'/agent-initiative-followthrough-v1.php';

/** Attach a live Player start to the exact accepted private offer. */
function vp3_media_offer_native_start_v1(PDO $pdo,array $user,array $action,int $trackId): ?array
{
    $messageId=$action['offer_message_id']??null;
    if(!$pdo->inTransaction()||!is_int($messageId)||$messageId<1||($action['track_id']??null)!==$trackId
        ||!in_array($action['operation']??null,['play','resume'],true)
        ||!vp3_media_playback_verified_action_v1($user,$action))return null;

    vp3_initiative_owner_lock_v1($pdo,$user);
    $cid=$action['conversation_id'];$agent=$action['agent_id'];$owner=(int)$user['id'];
    [$live,$principal]=vp3_media_playback_user_v1($pdo,$user,$agent,$cid);
    if($action['operation']==='resume'&&!vp3_media_playback_history_allowed_v1($pdo,$live,$principal))return null;
    $origin=vp3_initiative_followthrough_origin_load_v1($pdo,$live,$principal,$cid,$messageId,true);
    $state=$origin['state'];$goal=$origin['goal'];$primary=$goal['primary']??null;
    $expected='media:'.($action['operation']==='resume'?'resume':'track').':'.$trackId;
    $specialist=$goal['coordination_route']['specialist']??null;
    if(($goal['tool']??null)!=='coordination.review'||($goal['coordination_route']['action']??null)!=='ask'
        ||!(($specialist==='media'&&$primary===$expected&&in_array($state['state'],['owner_resolved','completed'],true)&&$state['last_intent']==='advance'
                &&($goal['sources'][$primary]??null)===$action['revision'])
            ||($specialist==='casual'&&in_array($primary,['casual:music','casual:media'],true)
                &&$action['operation']==='play'&&$state['state']==='owner_resolved'&&$state['last_intent']==='resolve'))
        ||!vp3_initiative_engagement_reply_verified_v1($pdo,$origin['user'],$cid,$messageId,$state))return null;

    $replyId=$state['last_reply_message_id'];
    $reply=$pdo->prepare("SELECT context_json FROM chat_messages WHERE conversation_id=? AND id>? AND role='assistant'
        AND user_id IS NULL ORDER BY id LIMIT 1");
    $reply->execute([$cid,$replyId]);
    $context=json_decode((string)$reply->fetchColumn(),true);
    $marker=$context['media_offer_decision_v1']??null;
    $runId=(int)$origin['run']['id'];
    if(($context['chat_user_message_id']??null)!==$replyId||($marker['kind']??null)!=='native'
        ||($marker['track_id']??null)!==$trackId
        ||($context['initiative_followthrough']??null)!==vp3_initiative_followthrough_outcome_v1($origin)
        ||vp3_media_offer_native_event_v1($pdo,$owner,$runId,$messageId,$replyId,$trackId)!==null)return null;

    return ['owner_id'=>$owner,'run_id'=>$runId,'message_id'=>$messageId,'reply_message_id'=>$replyId,'track_id'=>$trackId];
}

function vp3_media_offer_native_record_v1(PDO $pdo,array $start,int $sessionId,int $sessionTrackId): void
{
    if(!$pdo->inTransaction()||$sessionId<1||$sessionTrackId<1)return;
    agent_workflow_event_v1400($pdo,$start['owner_id'],$start['run_id'],'initiative_media_playback_started',
        'completed','completed','executor','The VP3 Player started the accepted track.',
        array_diff_key($start,['owner_id'=>true,'run_id'=>true])+['session_id'=>$sessionId,'session_track_id'=>$sessionTrackId]);
}

function vp3_media_offer_native_played_v1(PDO $pdo,array $origin,array $replyContext): bool
{
    $marker=$replyContext['media_offer_decision_v1']??null;
    $track=$marker['track_id']??null;
    $replyId=$origin['state']['last_reply_message_id'];
    if(($marker['kind']??null)!=='native'||!is_int($track)||$track<1||($replyContext['chat_user_message_id']??null)!==$replyId
        ||($replyContext['initiative_followthrough']??null)!==vp3_initiative_followthrough_outcome_v1($origin)
        ||!table_exists('track_play_sessions')||!table_exists('track_play_events'))return false;
    $owner=(int)$origin['user']['id'];$run=(int)$origin['run']['id'];
    $message=(int)$origin['message']['id'];
    $event=vp3_media_offer_native_event_v1($pdo,$owner,$run,$message,$replyId,$track);
    if($event===null||!is_int($event['session_id']??null)||!is_int($event['session_track_id']??null))return false;
    $read=$pdo->prepare("SELECT 1 FROM track_play_sessions p JOIN track_play_events e ON e.session_id=p.id
        WHERE p.id=? AND p.user_id=? AND p.track_id=?
            AND p.source_context IN ('agent_chat','agent_player') AND e.event_type='start' LIMIT 1");
    $read->execute([$event['session_id'],$owner,$event['session_track_id']]);
    return $read->fetchColumn()!==false;
}

function vp3_media_offer_native_event_v1(PDO $pdo,int $owner,int $run,int $message,int $reply,int $track): ?array
{
    $query=$pdo->prepare("SELECT metadata_json FROM agent_workflow_events WHERE owner_user_id=? AND run_id=?
        AND event_type='initiative_media_playback_started' AND actor_kind='executor' ORDER BY id DESC LIMIT 8");
    $query->execute([$owner,$run]);
    foreach($query->fetchAll(PDO::FETCH_COLUMN) as $raw){
        $event=json_decode((string)$raw,true);
        if(($event['message_id']??null)===$message&&($event['reply_message_id']??null)===$reply
            &&($event['track_id']??null)===$track)return $event;
    }
    return null;
}
