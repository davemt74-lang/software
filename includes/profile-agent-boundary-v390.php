<?php
declare(strict_types=1);

/**
 * VP3 v3.90 canonical Profile Agent conversation boundary.
 *
 * Public Profile Agent conversations belong to exactly one profile owner, one
 * exact user-owned Profile Agent, and one visitor browser/member session.
 * A conversation id never grants or changes that authority by itself.
 */
const VP3_PROFILE_AGENT_BOUNDARY_V390='vp3-profile-agent-boundary-v390-20260910';

function vp3_profile_agent_status_v390(array $conversation): string
{
    $status=(string)($conversation['status']??'open');
    return in_array($status,['open','owner_joined','resolved'],true)?$status:'open';
}

function vp3_profile_agent_public_conversation_v390(
    PDO $pdo,
    int $conversationId,
    int $ownerUserId,
    int $profileAgentId,
    int $profileSessionId,
    bool $forUpdate=false
): ?array {
    if($conversationId<1||$ownerUserId<1||$profileAgentId<1||$profileSessionId<1)return null;
    $sql='SELECT c.* FROM profile_agent_conversations c '
        .'WHERE c.id=? AND c.owner_user_id=? AND c.profile_agent_id=? AND c.profile_session_id=? LIMIT 1'
        .($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$conversationId,$ownerUserId,$profileAgentId,$profileSessionId]);
    $row=$stmt->fetch();
    return $row?:null;
}

/**
 * Owner-side access intentionally does not require the currently selected
 * Profile Agent. Owners may inspect and answer their historical visitor threads
 * after changing or retiring the Agent that originally represented them.
 */
function vp3_profile_agent_owner_conversation_v390(PDO $pdo,int $conversationId,int $ownerUserId,bool $forUpdate=false): ?array
{
    if($conversationId<1||$ownerUserId<1)return null;
    $sql='SELECT c.* FROM profile_agent_conversations c WHERE c.id=? AND c.owner_user_id=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$conversationId,$ownerUserId]);
    $row=$stmt->fetch();
    return $row?:null;
}

function vp3_profile_agent_agent_may_reply_v390(array $conversation): bool
{
    return vp3_profile_agent_status_v390($conversation)==='open';
}

/**
 * A visitor returning to a resolved thread explicitly reopens that same exact
 * owner + Agent + session conversation. Owner takeover is sticky until the owner
 * deliberately hands the thread back by setting status to open.
 */
function vp3_profile_agent_prepare_visitor_turn_v390(PDO $pdo,array $conversation): array
{
    $status=vp3_profile_agent_status_v390($conversation);
    if($status==='resolved'){
        $stmt=$pdo->prepare("UPDATE profile_agent_conversations SET status='open',updated_at=NOW() WHERE id=? AND owner_user_id=? AND profile_agent_id=? AND profile_session_id=? AND status='resolved'");
        $stmt->execute([(int)$conversation['id'],(int)$conversation['owner_user_id'],(int)$conversation['profile_agent_id'],(int)$conversation['profile_session_id']]);
        $conversation['status']='open';
    }
    return $conversation;
}

function vp3_profile_agent_public_state_v390(array $conversation): array
{
    return [
        'conversation_id'=>(int)($conversation['id']??0),
        'profile_agent_id'=>(int)($conversation['profile_agent_id']??0),
        'profile_session_id'=>(int)($conversation['profile_session_id']??0),
        'status'=>vp3_profile_agent_status_v390($conversation),
        'agent_may_reply'=>vp3_profile_agent_agent_may_reply_v390($conversation),
    ];
}
