<?php
declare(strict_types=1);

/**
 * VP3 Phase 18.3 — Manual Calendar Video Meetings + Guest Admission.
 *
 * User Calendar remains the canonical event store. Video Meetings owns room,
 * participant and invitation state. This layer connects a user-created calendar
 * event to the existing Video Meetings runtime without introducing a second
 * scheduling or invitation system.
 */
const VP3_VIDEO_MEETINGS_MANUAL_V1830='video-meetings-manual-v1830-20260915';

function video_meeting_manual_schema_ready_v1830(?PDO $pdo=null): bool
{
    $pdo??=db();
    return $pdo
        && table_exists('video_meeting_access_settings')
        && column_exists('video_meeting_access_settings','meeting_id')
        && column_exists('video_meeting_access_settings','guest_access_mode');
}

function video_meeting_manual_ensure_schema_v1830(?PDO $pdo=null): void
{
    $pdo??=db();
    if(!$pdo)throw new RuntimeException('Database connection is unavailable.');
    if(!video_meeting_schema_ready_v1800($pdo))video_meeting_ensure_schema_v1800($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS video_meeting_access_settings (
      meeting_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
      guest_access_mode VARCHAR(24) NOT NULL DEFAULT 'invite_only',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_video_meeting_access_settings_meeting FOREIGN KEY (meeting_id) REFERENCES video_meetings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function video_meeting_guest_access_mode_v1830(PDO $pdo,array $meeting): string
{
    if(!video_meeting_manual_schema_ready_v1830($pdo))return 'invite_only';
    $meetingId=(int)($meeting['id']??0);if($meetingId<1)return 'invite_only';
    $stmt=$pdo->prepare('SELECT guest_access_mode FROM video_meeting_access_settings WHERE meeting_id=? LIMIT 1');
    $stmt->execute([$meetingId]);$mode=strtolower(trim((string)$stmt->fetchColumn()));
    return in_array($mode,['invite_only','email_gate'],true)?$mode:'invite_only';
}

function video_meeting_set_guest_access_mode_v1830(PDO $pdo,array $meeting,string $mode): string
{
    if(!video_meeting_manual_schema_ready_v1830($pdo))throw new RuntimeException('Manual meeting access settings are not ready. Run the VP3 database upgrade.');
    $meetingId=(int)($meeting['id']??0);if($meetingId<1)throw new RuntimeException('Meeting is unavailable.');
    $mode=strtolower(trim($mode));if(!in_array($mode,['invite_only','email_gate'],true))$mode='invite_only';
    $pdo->prepare("INSERT INTO video_meeting_access_settings (meeting_id,guest_access_mode) VALUES (?,?) ON DUPLICATE KEY UPDATE guest_access_mode=VALUES(guest_access_mode),updated_at=CURRENT_TIMESTAMP")
        ->execute([$meetingId,$mode]);
    return $mode;
}

function video_meeting_public_guest_url_v1830(array $meeting): string
{
    $path='/meeting-guest.php?meeting='.rawurlencode((string)($meeting['public_id']??''));
    if(function_exists('video_meeting_secure_external_url_v1801'))return video_meeting_secure_external_url_v1801($path);
    return video_meeting_absolute_url_v1800($path);
}

function video_meeting_manual_parse_attendees_v1830(string $raw,int $limit=50): array
{
    $limit=max(1,min(100,$limit));$items=[];$seen=[];
    foreach(preg_split('/[\r\n;]+/u',$raw)?:[] as $line){
        $line=trim($line);if($line==='')continue;
        $name='';$email='';
        if(preg_match('/^\s*"?([^"<>]*)"?\s*<\s*([^<>]+)\s*>\s*$/u',$line,$m)){
            $name=trim((string)$m[1]);$email=strtolower(trim((string)$m[2]));
        }else{
            $email=strtolower(trim($line));
        }
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter attendees as one email per line, optionally Name <email@example.com>.');
        if(isset($seen[$email]))continue;
        $seen[$email]=true;
        $name=trim(preg_replace('/\s+/u',' ',$name)??'');
        $items[]=['display_name'=>mb_strimwidth($name,0,190,''),'email'=>mb_strimwidth($email,0,190,'')];
        if(count($items)>$limit)throw new RuntimeException('A manual VP3 meeting can invite up to '.$limit.' attendees at a time.');
    }
    return $items;
}

function video_meeting_manual_new_attendee_emails_v1830(PDO $pdo,array $meeting): array
{
    $emails=[];
    foreach(video_meeting_participants_v1800($pdo,(int)($meeting['id']??0)) as $participant){
        if((string)($participant['role']??'')!=='attendee')continue;
        $email=strtolower(trim((string)($participant['email']??'')));if($email!=='')$emails[$email]=true;
    }
    return $emails;
}

/**
 * Create/update a Video Meeting linked to an existing canonical User Calendar
 * event. This function performs only durable database work. Provider API calls
 * and guest email delivery are intentionally deferred until after commit.
 */
function video_meeting_manual_sync_event_v1830(PDO $pdo,array $owner,array $event,array $attendees,string $guestAccessMode='invite_only'): array
{
    if(!video_meeting_manual_schema_ready_v1830($pdo))throw new RuntimeException('Manual Video Meetings are not ready. Run the VP3 database upgrade.');
    $ownerId=(int)($owner['id']??0);$eventId=(int)($event['id']??0);
    if($ownerId<1||$eventId<1||(int)($event['owner_user_id']??0)!==$ownerId)throw new RuntimeException('Calendar event is unavailable.');
    if(!empty($event['all_day']))throw new RuntimeException('VP3 video meetings require a start and end time; turn off All-day event.');
    $status=(string)($event['status']??'active');if($status==='cancelled')throw new RuntimeException('A cancelled calendar event cannot start a video meeting.');

    $meeting=video_meeting_for_calendar_event_v1800($pdo,$eventId);
    $isNew=!$meeting;$existingEmails=$meeting?video_meeting_manual_new_attendee_emails_v1830($pdo,$meeting):[];
    if(!$meeting){
        $meeting=video_meeting_create_v1800($pdo,$owner,[
            'calendar_event_id'=>$eventId,
            'skip_owner_calendar'=>true,
            'title'=>(string)$event['title'],
            'description'=>(string)($event['description']??''),
            'start_at_utc'=>(string)$event['start_at_utc'],
            'end_at_utc'=>(string)$event['end_at_utc'],
            'timezone'=>(string)$event['timezone'],
            'agent_mode'=>'notes',
            'transcription_enabled'=>true,
            'recording_enabled'=>false,
        ]);
    }else{
        if((int)($meeting['owner_user_id']??0)!==$ownerId)throw new RuntimeException('This meeting belongs to another account.');
        if(in_array((string)($meeting['status']??''),['ended','processed','cancelled'],true))throw new RuntimeException('Completed or cancelled meetings cannot be rescheduled from Calendar.');
        $pdo->prepare("UPDATE video_meetings SET title=?,description=?,start_at_utc=?,end_at_utc=?,timezone=? WHERE id=? AND owner_user_id=?")
            ->execute([
                mb_strimwidth(trim((string)$event['title']),0,190,''),trim((string)($event['description']??''))?:null,
                (string)$event['start_at_utc'],(string)$event['end_at_utc'],(string)$event['timezone'],(int)$meeting['id'],$ownerId,
            ]);
        $meeting=video_meeting_row_v1800($pdo,(int)$meeting['id'])?:$meeting;
    }

    $mode=video_meeting_set_guest_access_mode_v1830($pdo,$meeting,$guestAccessMode);
    $newParticipants=[];
    foreach($attendees as $attendee){
        if(!is_array($attendee))continue;
        $email=strtolower(trim((string)($attendee['email']??'')));if($email===''||isset($existingEmails[$email]))continue;
        $participant=video_meeting_add_participant_v1800($pdo,$meeting,[
            'display_name'=>(string)($attendee['display_name']??''),'email'=>$email,'role'=>'attendee',
        ],false);
        $existingEmails[$email]=true;$newParticipants[]=$participant;
    }

    video_meeting_update_calendar_events_v1800($pdo,$meeting);
    return ['meeting'=>$meeting,'guest_access_mode'=>$mode,'new_participants'=>$newParticipants,'created'=>$isNew];
}

function video_meeting_manual_public_invitation_email_v1830(PDO $pdo,array $meeting,array $participant): bool
{
    $email=strtolower(trim((string)($participant['email']??'')));if(!filter_var($email,FILTER_VALIDATE_EMAIL))return false;
    if((int)($participant['user_id']??0)>0)return false;
    $owner=video_meeting_user_v1800($pdo,(int)($meeting['owner_user_id']??0));$ownerName=trim((string)($owner['display_name']??''))?:'VP3';
    $body=$ownerName." invited you to a VP3 video meeting.\n\n".(string)$meeting['title']."\n".(string)$meeting['start_at_utc']." UTC\n\nOpen the meeting page:\n".video_meeting_public_guest_url_v1830($meeting)."\n\nEnter this invited email address to continue:\n".$email."\n\nThe public meeting link does not grant access by itself.";
    if(function_exists('agent_appointment_lifecycle_email_v700'))return agent_appointment_lifecycle_email_v700($email,'Video meeting invitation: '.(string)$meeting['title'],$body);
    return false;
}

/** Run non-transactional delivery/sync after the calendar save commits. */
function video_meeting_manual_after_commit_v1830(PDO $pdo,array $result,bool $sendGuestEmails=false): array
{
    $meeting=is_array($result['meeting']??null)?$result['meeting']:[];$mode=(string)($result['guest_access_mode']??'invite_only');
    $sent=0;$failed=0;
    if(function_exists('video_meeting_external_calendar_sync_members_v1801')){
        try{video_meeting_external_calendar_sync_members_v1801($pdo,$meeting);}catch(Throwable $e){error_log('VP3 manual meeting member calendar sync failed: '.$e->getMessage());}
    }
    if($sendGuestEmails){
        foreach((array)($result['new_participants']??[]) as $participant){
            if(!is_array($participant)||(int)($participant['user_id']??0)>0)continue;
            try{
                $ok=$mode==='email_gate'
                    ?video_meeting_manual_public_invitation_email_v1830($pdo,$meeting,$participant)
                    :video_meeting_secure_invitation_email_v1800($pdo,$meeting,$participant);
                $ok?$sent++:$failed++;
            }catch(Throwable $e){$failed++;error_log('VP3 manual meeting guest invitation failed: '.$e->getMessage());}
        }
    }
    return ['emails_sent'=>$sent,'emails_failed'=>$failed];
}

function video_meeting_manual_cancel_event_v1830(PDO $pdo,array $owner,array $event): ?array
{
    $ownerId=(int)($owner['id']??0);$eventId=(int)($event['id']??0);if($ownerId<1||$eventId<1)return null;
    $meeting=video_meeting_for_calendar_event_v1800($pdo,$eventId);if(!$meeting)return null;
    if((int)($meeting['owner_user_id']??0)!==$ownerId)throw new RuntimeException('This meeting belongs to another account.');
    if((string)($meeting['status']??'')!=='cancelled'){
        $pdo->prepare("UPDATE video_meetings SET status='cancelled',cancelled_at=COALESCE(cancelled_at,UTC_TIMESTAMP()) WHERE id=? AND owner_user_id=?")
            ->execute([(int)$meeting['id'],$ownerId]);
        $meeting=video_meeting_row_v1800($pdo,(int)$meeting['id'])?:$meeting;
        video_meeting_update_calendar_events_v1800($pdo,$meeting);
    }
    return $meeting;
}

function video_meeting_manual_after_cancel_v1830(PDO $pdo,array $meeting): void
{
    video_meeting_livekit_delete_room_v1800($meeting);
    foreach(video_meeting_participants_v1800($pdo,(int)($meeting['id']??0)) as $participant){
        $uid=(int)($participant['user_id']??0);
        if($uid>0&&$uid!==(int)($meeting['owner_user_id']??0)&&function_exists('create_notification')){
            create_notification($uid,'video_meeting_cancelled','Video meeting cancelled',(string)$meeting['title'],url('/calendar.php'),'video_meeting',(int)$meeting['id']);
        }
    }
    if(function_exists('video_meeting_external_calendar_sync_members_v1801')){
        try{video_meeting_external_calendar_sync_members_v1801($pdo,$meeting);}catch(Throwable $e){error_log('VP3 manual meeting cancellation calendar sync failed: '.$e->getMessage());}
    }
}

function video_meeting_email_gate_rate_check_v1830(string $publicId): void
{
    $now=time();$key=strtolower($publicId);$rate=is_array($_SESSION['vp3_meeting_email_gate_rate'][$key]??null)?$_SESSION['vp3_meeting_email_gate_rate'][$key]:[];
    $window=(int)($rate['window_started']??0);$attempts=(int)($rate['attempts']??0);
    if($window<1||$now-$window>900){$window=$now;$attempts=0;}
    if($attempts>=10)throw new RuntimeException('Too many entry attempts. Try again later.');
    $_SESSION['vp3_meeting_email_gate_rate'][$key]=['window_started'=>$window,'attempts'=>$attempts+1];
}

function video_meeting_email_gate_claim_v1830(PDO $pdo,array $meeting,string $email): array
{
    if(video_meeting_guest_access_mode_v1830($pdo,$meeting)!=='email_gate')throw new RuntimeException('This meeting requires a private invitation link.');
    $publicId=strtolower(trim((string)($meeting['public_id']??'')));if(!preg_match('/^[a-f0-9]{32}$/',$publicId))throw new RuntimeException('Meeting is unavailable.');
    video_meeting_email_gate_rate_check_v1830($publicId);
    $email=strtolower(trim($email));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('That email is not eligible for guest entry.');
    $stmt=$pdo->prepare("SELECT * FROM video_meeting_participants WHERE meeting_id=? AND role='attendee' AND LOWER(email)=? AND invitation_status NOT IN ('cancelled','revoked','declined') ORDER BY id LIMIT 1");
    $stmt->execute([(int)$meeting['id'],$email]);$participant=$stmt->fetch();
    if(!$participant||(int)($participant['user_id']??0)>0)throw new RuntimeException('That email is not eligible for guest entry. If it belongs to a VP3 account, sign in first.');
    $end=strtotime((string)$meeting['end_at_utc'].' UTC')?:0;$now=time();$expires=min(max($now+7200,$end+14400),$now+2592000);
    $_SESSION['vp3_meeting_email_access'][$publicId]=['participant_id'=>(int)$participant['id'],'expires_at'=>$expires];
    return $participant;
}

function video_meeting_email_gate_access_v1830(PDO $pdo,?array $user,string $publicId): ?array
{
    if($user)return null;
    $publicId=strtolower(trim($publicId));if(!preg_match('/^[a-f0-9]{32}$/',$publicId))return null;
    $grant=is_array($_SESSION['vp3_meeting_email_access'][$publicId]??null)?$_SESSION['vp3_meeting_email_access'][$publicId]:null;
    if(!$grant||time()>(int)($grant['expires_at']??0)){unset($_SESSION['vp3_meeting_email_access'][$publicId]);return null;}
    $meeting=video_meeting_by_public_id_v1800($pdo,$publicId);if(!$meeting||video_meeting_guest_access_mode_v1830($pdo,$meeting)!=='email_gate')return null;
    $participantId=(int)($grant['participant_id']??0);if($participantId<1)return null;
    $stmt=$pdo->prepare("SELECT * FROM video_meeting_participants WHERE id=? AND meeting_id=? AND role='attendee' AND user_id IS NULL AND invitation_status NOT IN ('cancelled','revoked','declined') LIMIT 1");
    $stmt->execute([$participantId,(int)$meeting['id']]);$participant=$stmt->fetch();if(!$participant)return null;
    return ['meeting'=>$meeting,'participant'=>$participant,'is_organizer'=>false,'email_gate'=>true];
}
