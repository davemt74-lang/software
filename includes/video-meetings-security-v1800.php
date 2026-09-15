<?php
declare(strict_types=1);

/**
 * A guest invitation is a bearer capability, but once an invitation has been
 * matched to a VP3 member we do not let a different signed-in account inherit
 * that member's identity. The organizer remains allowed to use owner links.
 */
function video_meeting_member_binding_allowed_v1800(?array $user,array $access): bool
{
    if(!$user)return true;
    $userId=(int)($user['id']??0);if($userId<1)return false;
    $meeting=is_array($access['meeting']??null)?$access['meeting']:[];
    $participant=is_array($access['participant']??null)?$access['participant']:[];
    if($userId===(int)($meeting['owner_user_id']??0))return true;
    $boundUserId=(int)($participant['user_id']??0);
    return $boundUserId<1||$boundUserId===$userId;
}
