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
    bool $forUpdate=false,
    ?array $authority=null
): ?array {
    if($conversationId<1||$ownerUserId<1||$profileAgentId<1||$profileSessionId<1)return null;
    if(!vp3_profile_agent_conversation_authority_valid_v1($authority,$ownerUserId))return null;
    $sql='SELECT c.* FROM profile_agent_conversations c '
        .'WHERE c.id=? AND c.owner_user_id=? AND c.profile_agent_id=? AND c.profile_session_id=? LIMIT 1'
        .($forUpdate&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);
    $stmt->execute([$conversationId,$ownerUserId,$profileAgentId,$profileSessionId]);
    $row=$stmt->fetch();
    if(!$row||!in_array($row['status']??null,['open','owner_joined','resolved'],true)||(int)($row['visitor_user_id']??0)!==(int)$authority['visitor_user_id'])return null;
    $binding=$pdo->prepare("SELECT context_json FROM profile_agent_messages WHERE conversation_id=? AND sender_type='system' ORDER BY id ASC LIMIT 2");
    $binding->execute([$conversationId]);$rows=$binding->fetchAll(PDO::FETCH_COLUMN);
    if(count($rows)!==1)return null;
    $stored=json_decode((string)$rows[0],true);
    if(!vp3_profile_agent_conversation_authority_valid_v1(is_array($stored)?$stored:null,$ownerUserId)
       ||(int)($stored['profile_agent_id']??0)!==$profileAgentId
       ||(int)($stored['profile_session_id']??0)!==$profileSessionId
       ||(int)$stored['visitor_user_id']!==(int)$authority['visitor_user_id']
       ||!hash_equals((string)$stored['transport'],(string)$authority['transport'])
       ||!hash_equals((string)$stored['credential_hash'],(string)$authority['credential_hash']))return null;
    return $row;
}

function vp3_profile_agent_conversation_authority_valid_v1(?array $authority,int $ownerUserId): bool
{
    return is_array($authority)&&($authority['contract']??null)==='vp3.profile-conversation-authority.v1'
        &&in_array($authority['transport']??null,['native','external_site'],true)
        &&($authority['owner_user_id']??null)===$ownerUserId
        &&is_int($authority['visitor_user_id']??null)&&$authority['visitor_user_id']>=0
        &&is_string($authority['credential_hash']??null)
        &&(bool)preg_match('/^[a-f0-9]{64}$/D',$authority['credential_hash']);
}

function vp3_profile_agent_conversation_bind_v1(PDO $pdo,array $conversation,array $authority): void
{
    if(!$pdo->inTransaction()||!vp3_profile_agent_conversation_authority_valid_v1($authority,(int)$conversation['owner_user_id'])
       ||(int)($conversation['visitor_user_id']??0)!==$authority['visitor_user_id'])throw new RuntimeException('Conversation authority could not be established.');
    $binding=$authority+['profile_agent_id'=>(int)$conversation['profile_agent_id'],'profile_session_id'=>(int)$conversation['profile_session_id']];
    $binding['response_token']=bin2hex(random_bytes(16));
    // A hidden binding row does not represent a message and creates no event or notification.
    $pdo->prepare("INSERT INTO profile_agent_messages (conversation_id,sender_type,sender_user_id,message,context_json) VALUES (?,'system',NULL,'',?)")
        ->execute([(int)$conversation['id'],json_encode($binding,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
}

/** A hidden conversation binding also fences retained synchronous inference.
 * Rotate only under the canonical conversation lock. A status returning to
 * "open" must not revive a response started before the owner intervened.
 */
function vp3_profile_agent_response_token_v1(PDO $pdo,int $conversationId,bool $rotate=false): string
{
    if($rotate&&!$pdo->inTransaction())throw new RuntimeException('Response fencing requires a transaction.');
    $q=$pdo->prepare("SELECT id,context_json FROM profile_agent_messages WHERE conversation_id=? AND sender_type='system' ORDER BY id ASC LIMIT 2");
    $q->execute([$conversationId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    if(count($rows)!==1||strlen((string)$rows[0]['context_json'])>65536)return '';
    $binding=json_decode((string)$rows[0]['context_json'],true);
    if(!is_array($binding)||!vp3_profile_agent_conversation_authority_valid_v1($binding,(int)($binding['owner_user_id']??0)))return '';
    if($rotate){
        $binding['response_token']=bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE profile_agent_messages SET context_json=? WHERE id=? AND conversation_id=? AND sender_type='system'")
            ->execute([json_encode($binding,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),(int)$rows[0]['id'],$conversationId]);
    }
    $token=$binding['response_token']??'';
    return is_string($token)&&preg_match('/^[a-f0-9]{32}$/D',$token)?$token:'';
}

/**
 * Owner-side access intentionally does not require the currently selected
 * Profile Agent. Owners may inspect and answer their historical visitor threads
 * after changing or retiring the Agent that originally represented them.
 */
function vp3_profile_agent_owner_conversation_v390(PDO $pdo,int $conversationId,int $ownerUserId,bool $forUpdate=false): ?array
{
    if($conversationId<1||$ownerUserId<1)return null;
    $sql='SELECT c.* FROM profile_agent_conversations c WHERE c.id=? AND c.owner_user_id=? LIMIT 1'.($forUpdate&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'?' FOR UPDATE':'');
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
