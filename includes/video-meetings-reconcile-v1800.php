<?php
declare(strict_types=1);

/**
 * Eventual-consistency bridge from canonical Agent Scheduling bookings into
 * durable VP3 Video Meetings. This does not introduce another scheduler: it
 * observes the authoritative booking rows and idempotently projects video-room
 * state, invitations and calendar links.
 */
function video_meeting_reconcile_booking_v1800(PDO $pdo,array $booking,bool $deliverNewInvite=true): ?array
{
    if(!video_meeting_schema_ready_v1800($pdo)||(int)($booking['id']??0)<1)return null;
    $existing=video_meeting_for_booking_v1800($pdo,(int)$booking['id']);
    $isVideo=(string)($booking['location_type']??'')==='vp3_video'||(bool)$existing;
    if(!$isVideo)return null;

    if(in_array((string)($booking['status']??''),['cancelled','no_show'],true)){
        if($existing){
            if(!empty($existing['transcription_enabled'])&&function_exists('video_meeting_transcription_finalize_v1800'))video_meeting_transcription_finalize_v1800($pdo,$existing);
            video_meeting_cancel_for_booking_v1800($pdo,$booking);
        }
        return $existing;
    }

    $oldStart=(string)($existing['start_at_utc']??'');
    $oldEnd=(string)($existing['end_at_utc']??'');
    $meeting=video_meeting_sync_booking_v1800($pdo,$booking,$existing?'updated':'created');
    if(!$meeting)return null;
    if(!empty($meeting['transcription_enabled'])&&function_exists('video_meeting_transcription_ensure_session_v1800'))video_meeting_transcription_ensure_session_v1800($pdo,$meeting);

    // The meeting sync writes the stable owner join URL back onto the canonical
    // booking. Re-load before updating Google/Microsoft so the same VP3 link is
    // carried into the owner's connected calendar event.
    try{
        $reload=$pdo->prepare('SELECT * FROM agent_scheduling_bookings WHERE id=? AND owner_user_id=? LIMIT 1');
        $reload->execute([(int)$booking['id'],(int)$booking['owner_user_id']]);$fresh=$reload->fetch()?:$booking;
        if(function_exists('agent_calendar_sync_booking_v500'))agent_calendar_sync_booking_v500($pdo,$fresh);
    }catch(Throwable $ignored){}

    $participants=video_meeting_participants_v1800($pdo,(int)$meeting['id']);
    if(!$existing&&$deliverNewInvite){
        foreach($participants as $participant){
            if((string)$participant['role']!=='attendee')continue;
            if(trim((string)$participant['email'])!=='')video_meeting_email_invitation_v1800($pdo,$meeting,$participant);
        }
    }elseif($existing&&($oldStart!==(string)$meeting['start_at_utc']||$oldEnd!==(string)$meeting['end_at_utc'])){
        foreach($participants as $participant){
            if((string)$participant['role']!=='attendee')continue;
            $uid=(int)($participant['user_id']??0);
            if($uid>0&&function_exists('create_notification')){
                create_notification($uid,'video_meeting_changed','Video meeting updated',(string)$meeting['title'].' · '.(string)$meeting['start_at_utc'].' UTC',url('/meeting.php?meeting='.(string)$meeting['public_id']),'video_meeting_change',(int)$meeting['id']);
            }
            if(trim((string)$participant['email'])!=='')video_meeting_email_invitation_v1800($pdo,$meeting,$participant,'Video meeting updated');
        }
    }
    return $meeting;
}

function video_meeting_reconcile_recent_bookings_v1800(PDO $pdo,int $limit=40): int
{
    if(!video_meeting_schema_ready_v1800($pdo)||!table_exists('agent_scheduling_bookings'))return 0;
    $limit=max(1,min(100,$limit));$count=0;
    try{
        $stmt=$pdo->query("SELECT b.*
          FROM agent_scheduling_bookings b
          LEFT JOIN video_meetings m ON m.booking_id=b.id
          WHERE (b.location_type='vp3_video' OR m.id IS NOT NULL)
            AND (b.end_at_utc>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) OR b.updated_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY))
          ORDER BY b.updated_at DESC,b.id DESC
          LIMIT {$limit}");
        foreach($stmt->fetchAll()?:[] as $booking){
            try{if(video_meeting_reconcile_booking_v1800($pdo,$booking,true))$count++;}catch(Throwable $e){error_log('VP3 video meeting booking reconciliation failed: '.$e->getMessage());}
        }
    }catch(Throwable $e){error_log('VP3 video meeting reconciliation query failed: '.$e->getMessage());}
    return $count;
}

function video_meeting_boot_v1800(): void
{
    static $registered=false;if($registered)return;$registered=true;
    if(PHP_SAPI==='cli')return;
    register_shutdown_function(static function(): void {
        try{
            $pdo=db();
            if(!$pdo||$pdo->inTransaction()||!video_meeting_schema_ready_v1800($pdo))return;
            video_meeting_reconcile_recent_bookings_v1800($pdo,30);
        }catch(Throwable $e){error_log('VP3 Video Meetings shutdown reconciliation failed: '.$e->getMessage());}
    });
}
