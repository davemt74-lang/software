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
    if(in_array((string)($participant['invitation_status']??''),['cancelled','revoked','declined'],true))return false;
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

    // When the organizer selects Phase 18.3 email-gate mode, old unbound guest
    // bearer links are no longer an alternate door around the email check.
    // Member-bound invite links still work because VP3 account identity remains
    // the stronger authorization boundary for those participants.
    if($access&&$inviteToken!==''&&function_exists('video_meeting_guest_access_mode_v1830')){
        $meeting=is_array($access['meeting']??null)?$access['meeting']:[];
        $participant=is_array($access['participant']??null)?$access['participant']:[];
        if((int)($participant['user_id']??0)===0&&video_meeting_guest_access_mode_v1830($pdo,$meeting)==='email_gate')return null;
    }

    // Phase 18.3 email-gated public links create a server-side session grant
    // after the invited guest proves the email address. No bearer capability is
    // added to the public URL, and signed-in members continue to use identity.
    if(!$access&&!$user&&$inviteToken===''&&$publicId!==''&&function_exists('video_meeting_email_gate_access_v1830')){
        $access=video_meeting_email_gate_access_v1830($pdo,$user,$publicId);
    }
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

/**
 * Outbound meeting links are security capabilities, so production email/ICS
 * must use the configured canonical origin instead of trusting HTTP_HOST.
 * Localhost remains available for development without weakening production.
 */
function video_meeting_public_origin_v1801(): string
{
    global $config;
    $base=rtrim(trim((string)($config['site']['base_url']??'')),'/');
    if($base!==''){
        $parts=parse_url($base);
        $scheme=strtolower((string)($parts['scheme']??''));$host=(string)($parts['host']??'');$path=(string)($parts['path']??'');
        if(!in_array($scheme,['http','https'],true)||$host===''||($path!==''&&$path!=='/')||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])){
            throw new RuntimeException('VP3 site.base_url is invalid. Configure a root canonical public origin before sending meeting invitations.');
        }
        $port=isset($parts['port'])?':'.(int)$parts['port']:'';
        return $scheme.'://'.$host.$port;
    }

    $host=strtolower(trim((string)($_SERVER['HTTP_HOST']??'')));
    $hostOnly=preg_replace('/:\d+$/','',$host)??$host;
    $local=in_array($hostOnly,['localhost','127.0.0.1','[::1]','::1'],true);
    if(!$local)throw new RuntimeException('Configure site.base_url before sending VP3 meeting invitations.');
    $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
    return $scheme.'://'.$host;
}

function video_meeting_secure_external_url_v1801(string $path): string
{
    return video_meeting_public_origin_v1801().url($path);
}

function video_meeting_secure_invite_url_v1801(array $participant): string
{
    return video_meeting_secure_external_url_v1801('/meeting.php?invite='.rawurlencode((string)$participant['invite_token']));
}

function video_meeting_secure_ics_url_v1801(array $participant): string
{
    return video_meeting_secure_external_url_v1801('/meeting-ics.php?invite='.rawurlencode((string)$participant['invite_token']));
}

function video_meeting_secure_invitation_email_v1800(PDO $pdo,array $meeting,array $participant,string $prefix='Video meeting invitation'): bool
{
    $email=strtolower(trim((string)($participant['email']??'')));if(!filter_var($email,FILTER_VALIDATE_EMAIL))return false;
    $owner=video_meeting_user_v1800($pdo,(int)$meeting['owner_user_id']);$ownerName=trim((string)($owner['display_name']??''))?:'VP3';
    $memberBound=(int)($participant['user_id']??0)>0;
    $body=$ownerName." invited you to a VP3 video meeting.\n\n".(string)$meeting['title']."\n".(string)$meeting['start_at_utc']." UTC\n\nJoin meeting:\n".video_meeting_secure_invite_url_v1801($participant)."\n\nAdd to calendar:\n".video_meeting_secure_ics_url_v1801($participant)."\n\n";
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
