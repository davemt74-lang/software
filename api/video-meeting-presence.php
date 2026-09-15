<?php
declare(strict_types=1);
require __DIR__.'/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$fail=static function(int $status,string $message): never {
    http_response_code($status);
    echo json_encode(['ok'=>false,'error'=>$message],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    exit;
};
if($_SERVER['REQUEST_METHOD']!=='POST')$fail(405,'POST required.');
$pdo=db();if(!$pdo||!video_meeting_schema_ready_v1800($pdo))$fail(503,'Video Meetings are not ready.');
$user=current_user();if($user&&!verify_csrf())$fail(403,'Session expired. Refresh the meeting and try again.');
$access=video_meeting_secure_access_v1800($pdo,$user,strtolower(trim((string)($_POST['meeting']??''))),strtolower(trim((string)($_POST['invite']??''))));
if(!$access)$fail(403,'This meeting invitation is not available to you.');
$action=strtolower(trim((string)($_POST['action']??'')));if(!in_array($action,['join','leave','end'],true))$fail(422,'Unknown meeting action.');
if($action==='end'&&empty($access['is_organizer']))$fail(403,'Only the meeting organizer can end this meeting.');

try{
    $dispatch=null;$processingStatus=null;
    $meeting=video_meeting_mark_presence_v1800($pdo,$access,$action);
    if($action==='join'){
        if(!empty($meeting['transcription_enabled']))video_meeting_transcription_ensure_session_v1800($pdo,$meeting);
        video_meeting_record_crm_attendance_v1800($pdo,$meeting,$access['participant'],'joined');
        if(!empty($access['is_organizer'])){
            // Dispatch resolves privacy/readiness immediately before execution.
            // Return that post-dispatch state so the browser never repaints an
            // older token-time route after the actual runtime decision.
            $result=video_meeting_livekit_agent_dispatch_v1800($pdo,$meeting);
            $dispatch=[
                'ok'=>!empty($result['ok']),
                'dispatched'=>!empty($result['dispatched']),
                'reason'=>mb_strimwidth((string)($result['reason']??''),0,80,''),
            ];
            if(!empty($meeting['transcription_enabled'])&&function_exists('video_meeting_homeserver_public_status_v1840')){
                $processingStatus=video_meeting_homeserver_public_status_v1840($pdo,$meeting,true);
                $reason=(string)$dispatch['reason'];
                if(in_array($reason,['homeserver_created','homeserver_already_running'],true)){
                    $processingStatus['route']='homeserver';$processingStatus['status']='ready';$processingStatus['reason_code']='homeserver_active';$processingStatus['ready']=true;$processingStatus['executor_available']=true;
                }elseif($reason==='homeserver_dispatch_failed'){
                    $processingStatus['route']='blocked';$processingStatus['status']='required_unavailable';$processingStatus['reason_code']='homeserver_dispatch_failed';$processingStatus['ready']=false;$processingStatus['homeserver_required']=true;
                }elseif(in_array($reason,['created','already_dispatched'],true)){
                    $processingStatus['route']='cloud';$processingStatus['status']='ready';$processingStatus['reason_code']='cloud_active';$processingStatus['ready']=true;
                }
            }
        }elseif(table_exists('notifications')){
            // Reclassify the notification produced by canonical presence as an
            // attention signal. The existing Notification -> Cognitive Loop ->
            // Agent Chat path now understands that a participant is waiting;
            // no meeting-specific cognition queue is introduced.
            $pdo->prepare("UPDATE notifications SET type='video_meeting_needs_attention_participant_joined' WHERE user_id=? AND source_type='video_meeting_participant' AND source_id=? AND type='video_meeting_participant_joined' ORDER BY id DESC LIMIT 1")
                ->execute([(int)$meeting['owner_user_id'],(int)$access['participant']['id']]);
        }
    }
    if($action==='leave')video_meeting_record_crm_attendance_v1800($pdo,$meeting,$access['participant'],'left');
    if($action==='end'){
        if(!empty($meeting['transcription_enabled']))video_meeting_transcription_finalize_v1800($pdo,$meeting);
        // Phase 18.2 deliberately does not execute cloud AI inside the presence
        // request. Ending media stays fast; the organizer client/review surface
        // runs canonical Transcription Intelligence under the existing privacy
        // guard, while this marker makes the durable post-meeting state ready.
        if(function_exists('video_meeting_intelligence_mark_ended_v1820'))video_meeting_intelligence_mark_ended_v1820($pdo,$meeting);
        if(table_exists('notifications')&&function_exists('create_notification')){
            $exists=$pdo->prepare("SELECT id FROM notifications WHERE user_id=? AND source_type='video_meeting' AND source_id=? AND type='video_meeting_needs_attention_intelligence_review' LIMIT 1");
            $exists->execute([(int)$meeting['owner_user_id'],(int)$meeting['id']]);
            if(!(int)$exists->fetchColumn()){
                create_notification(
                    (int)$meeting['owner_user_id'],
                    'video_meeting_needs_attention_intelligence_review',
                    'Meeting ready for review',
                    (string)$meeting['title'].' · Review Meeting Intelligence and follow-up.',
                    url('/meeting.php?meeting='.(string)$meeting['public_id'].'&review=1'),
                    'video_meeting',
                    (int)$meeting['id']
                );
            }
        }
    }
    echo json_encode([
        'ok'=>true,'status'=>(string)$meeting['status'],'agent_dispatch'=>$dispatch,'processing_status'=>$processingStatus,
        'intelligence_review_url'=>$action==='end'&&!empty($access['is_organizer'])?url('/meeting.php?meeting='.(string)$meeting['public_id'].'&review=1'):'',
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    error_log('VP3 meeting presence error: '.$e->getMessage());
    $fail(500,'Meeting status could not be updated.');
}
