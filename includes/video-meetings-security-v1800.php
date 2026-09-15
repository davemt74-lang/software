<?php
declare(strict_types=1);

/**
 * Guest invitations are bearer capabilities. Invitations that resolve to an
 * existing VP3 member are identity-bound and require that exact account to be
 * signed in; a leaked member link must never impersonate that member.
 */
function video_meeting_member_binding_allowed_v1800(?array $user,array $access): bool
{
    $meeting=is_array($access['meeting']??null)?$access['meeting']:[];
    $participant=is_array($access['participant']??null)?$access['participant']:[];
    $boundUserId=(int)($participant['user_id']??0);

    if($boundUserId>0){
        if(!$user)return false;
        $userId=(int)($user['id']??0);if($userId<1)return false;
        if($userId===(int)($meeting['owner_user_id']??0))return true;
        return $boundUserId===$userId;
    }

    // Unbound guest links remain bearer capabilities. A signed-in user may use
    // one, but the participant row remains the guest capability rather than
    // silently becoming that account's stored member identity.
    return true;
}

function video_meeting_secure_access_v1800(PDO $pdo,?array $user,string $publicId='',string $inviteToken=''): ?array
{
    $access=video_meeting_access_v1800($pdo,$user,$publicId,$inviteToken);
    if(!$access||!video_meeting_member_binding_allowed_v1800($user,$access))return null;

    // If the owner follows somebody else's bearer URL, always resolve back to
    // the organizer participant. This prevents an organizer from accidentally
    // joining LiveKit under an attendee identity or receiving attendee grants.
    $userId=(int)($user['id']??0);$meeting=is_array($access['meeting']??null)?$access['meeting']:[];
    if($userId>0&&$userId===(int)($meeting['owner_user_id']??0)){
        $stmt=$pdo->prepare("SELECT * FROM video_meeting_participants WHERE meeting_id=? AND user_id=? AND role='organizer' ORDER BY id LIMIT 1");
        $stmt->execute([(int)($meeting['id']??0),$userId]);$ownerParticipant=$stmt->fetch();
        if($ownerParticipant){$access['participant']=$ownerParticipant;$access['is_organizer']=true;}
    }
    return $access;
}

function video_meeting_secure_invitation_email_v1800(PDO $pdo,array $meeting,array $participant,string $prefix='Video meeting invitation'): bool
{
    $email=strtolower(trim((string)($participant['email']??'')));if(!filter_var($email,FILTER_VALIDATE_EMAIL))return false;
    $owner=video_meeting_user_v1800($pdo,(int)$meeting['owner_user_id']);$ownerName=trim((string)($owner['display_name']??''))?:'VP3';
    $memberBound=(int)($participant['user_id']??0)>0;
    $body=$ownerName." invited you to a VP3 video meeting.\n\n".(string)$meeting['title']."\n".(string)$meeting['start_at_utc']." UTC\n\nJoin meeting:\n".video_meeting_invite_url_v1800($participant)."\n\nAdd to calendar:\n".video_meeting_ics_url_v1800($participant)."\n\n";
    if($memberBound)$body.='This invitation is bound to your VP3 account. Sign in with '.$email.' before opening the meeting link.';
    else $body.='You do not need a VP3 account to use this guest invitation link. Keep the link private because it grants meeting access.';
    if(function_exists('agent_appointment_lifecycle_email_v700'))return agent_appointment_lifecycle_email_v700($email,$prefix.': '.(string)$meeting['title'],$body);
    return false;
}

function video_meeting_agent_worker_ready_v1800(): bool
{
    if(!video_meeting_livekit_ready_v1800())return false;
    $cfg=video_meeting_livekit_config_v1800();
    global $config;$livekit=is_array($config['livekit']??null)?$config['livekit']:[];
    $workerSecret=trim((string)(getenv('VP3_MEETING_WORKER_SECRET')?:($livekit['worker_secret']??'')));
    return trim((string)($cfg['agent_name']??''))!==''&&$workerSecret!=='';
}
