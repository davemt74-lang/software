<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_CHAT_V140='profile-webmcp-chat-v140-20260928';
const VP3_PROFILE_WEBMCP_CHAT_GRANT_SECONDS_V140=1200;

function vp3_profile_webmcp_b64url_encode_v140(string $value): string
{
    return rtrim(strtr(base64_encode($value),'+/','-_'),'=');
}

function vp3_profile_webmcp_b64url_decode_v140(string $value): string
{
    if(!preg_match('/^[A-Za-z0-9_-]+$/',$value))return '';
    $pad=(4-(strlen($value)%4))%4;
    $decoded=base64_decode(strtr($value,'-_','+/').str_repeat('=',$pad),true);
    return is_string($decoded)?$decoded:'';
}

function vp3_profile_webmcp_chat_grant_secret_v140(array $property): string
{
    $verification=trim((string)($property['verification_token']??''));
    $publicKey=trim((string)($property['public_key']??''));
    if(!preg_match('/^[a-f0-9]{64}$/i',$verification)||!preg_match('/^[a-f0-9]{40}$/i',$publicKey)){
        throw new RuntimeException('Connected-site chat authority is unavailable.');
    }
    return hash('sha256','vp3-webmcp-chat-v140|'.strtolower($publicKey).'|'.strtolower($verification),true);
}

function vp3_profile_webmcp_chat_grant_create_v140(array $property,array $profile,string $origin,string $webmcpSessionId,int $now=0): array
{
    $now=$now>0?$now:time();
    $propertyId=(int)($property['id']??0);
    $owner=(int)($property['owner_user_id']??0);
    $username=(string)($profile['username']??'');
    $sessionId=vp3_profile_webmcp_transport_id_v130($webmcpSessionId);
    if($propertyId<1||$owner<1||$username===''||$sessionId===''||empty($property['is_active'])){
        throw new RuntimeException('Connected-site chat authority is unavailable.');
    }
    $payload=[
        'v'=>1,
        'property_id'=>$propertyId,
        'owner_user_id'=>$owner,
        'profile_username'=>$username,
        'origin_hash'=>hash('sha256',$origin),
        'session_hash'=>hash('sha256',$sessionId),
        'iat'=>$now,
        'exp'=>$now+VP3_PROFILE_WEBMCP_CHAT_GRANT_SECONDS_V140,
    ];
    $encoded=vp3_profile_webmcp_b64url_encode_v140(json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    $sig=hash_hmac('sha256',$encoded,vp3_profile_webmcp_chat_grant_secret_v140($property),true);
    return [
        'grant'=>$encoded.'.'.vp3_profile_webmcp_b64url_encode_v140($sig),
        'expires_at_utc'=>gmdate('c',$payload['exp']),
        'expires_at_unix'=>$payload['exp'],
    ];
}

function vp3_profile_webmcp_chat_grant_verify_v140(
    array $property,array $profile,string $origin,string $webmcpSessionId,string $grant,int $now=0
): array {
    $now=$now>0?$now:time();
    $parts=explode('.',$grant);
    if(count($parts)!==2)throw new VP3ProfileAgentPublicException('CHAT_GRANT_INVALID','Connected-site chat grant is invalid.',403);
    [$encoded,$sigEncoded]=$parts;
    $sig=vp3_profile_webmcp_b64url_decode_v140($sigEncoded);
    $expected=hash_hmac('sha256',$encoded,vp3_profile_webmcp_chat_grant_secret_v140($property),true);
    if($sig===''||!hash_equals($expected,$sig))throw new VP3ProfileAgentPublicException('CHAT_GRANT_INVALID','Connected-site chat grant is invalid.',403);
    $json=vp3_profile_webmcp_b64url_decode_v140($encoded);
    $payload=$json!==''?json_decode($json,true):null;
    if(!is_array($payload)||($payload['v']??null)!==1)throw new VP3ProfileAgentPublicException('CHAT_GRANT_INVALID','Connected-site chat grant is invalid.',403);
    if((int)($payload['exp']??0)<$now)throw new VP3ProfileAgentPublicException('CHAT_GRANT_EXPIRED','Connected-site chat grant expired. Refresh the site capability manifest.',419);
    if((int)($payload['iat']??0)>$now+60)throw new VP3ProfileAgentPublicException('CHAT_GRANT_INVALID','Connected-site chat grant is invalid.',403);
    $sessionId=vp3_profile_webmcp_transport_id_v130($webmcpSessionId);
    $checks=[
        (int)($payload['property_id']??0)===(int)($property['id']??0),
        (int)($payload['owner_user_id']??0)===(int)($profile['user_id']??0),
        hash_equals((string)($payload['profile_username']??''),(string)($profile['username']??'')),
        hash_equals((string)($payload['origin_hash']??''),hash('sha256',$origin)),
        $sessionId!==''&&hash_equals((string)($payload['session_hash']??''),hash('sha256',$sessionId)),
    ];
    if(in_array(false,$checks,true))throw new VP3ProfileAgentPublicException('CHAT_GRANT_MISMATCH','Connected-site chat grant does not match this site session.',403);
    return $payload;
}

function vp3_profile_webmcp_external_profile_session_v140(PDO $pdo,array $property,array $profile,string $webmcpSessionId): array
{
    $owner=(int)($profile['user_id']??0);
    $propertyId=(int)($property['id']??0);
    $sessionId=vp3_profile_webmcp_transport_id_v130($webmcpSessionId);
    if($owner<1||$propertyId<1||$sessionId==='')throw new VP3ProfileAgentPublicException('PROFILE_SESSION_UNAVAILABLE','Connected-site chat session is unavailable.',503);
    $sessionKey=hash('sha256','webmcp-chat-v140|'.$owner.'|'.$propertyId.'|'.$sessionId);
    $stmt=$pdo->prepare('INSERT INTO profile_visit_sessions (owner_user_id,visitor_user_id,session_key,identity_disclosed,view_count) VALUES (?,NULL,?,0,0) ON DUPLICATE KEY UPDATE last_seen_at=NOW()');
    $stmt->execute([$owner,$sessionKey]);
    $get=$pdo->prepare('SELECT * FROM profile_visit_sessions WHERE owner_user_id=? AND session_key=? LIMIT 1');
    $get->execute([$owner,$sessionKey]);
    return $get->fetch()?:throw new VP3ProfileAgentPublicException('PROFILE_SESSION_UNAVAILABLE','Connected-site chat session is unavailable.',503);
}

function vp3_profile_webmcp_external_chat_context_v140(PDO $pdo,array $property,array $profile,string $webmcpSessionId): array
{
    $owner=(int)($profile['user_id']??0);
    $ownerUser=$owner>0?profile_user_row($pdo,$owner):null;
    if(!$ownerUser
       || !personal_capability_has_v242('profile_agent.access',$ownerUser)
       || !personal_capability_has_v242('profile_chat.access',$ownerUser)){
        throw new VP3ProfileAgentPublicException('PROFILE_AGENT_UNAVAILABLE','This Profile Agent chat is not available.',404);
    }
    $agent=profile_active_agent($pdo,$profile);
    if(!$agent)throw new VP3ProfileAgentPublicException('PROFILE_AGENT_UNAVAILABLE','This Profile Agent is not available.',404);
    $session=vp3_profile_webmcp_external_profile_session_v140($pdo,$property,$profile,$webmcpSessionId);
    return [
        'profile'=>$profile,
        'owner_user_id'=>$owner,
        'owner_user'=>$ownerUser,
        'visitor'=>null,
        'agent'=>$agent,
        'agent_id'=>(int)$agent['id'],
        'session'=>$session,
        'session_id'=>(int)$session['id'],
    ];
}

function vp3_profile_webmcp_chat_start_v140(PDO $pdo,array $ctx): array
{
    $owner=(int)$ctx['owner_user_id'];$agentId=(int)$ctx['agent_id'];$sessionId=(int)$ctx['session_id'];
    $stmt=$pdo->prepare("SELECT * FROM profile_agent_conversations
      WHERE owner_user_id=? AND profile_agent_id=? AND profile_session_id=?
        AND status IN ('open','owner_joined')
      ORDER BY id DESC LIMIT 1");
    $stmt->execute([$owner,$agentId,$sessionId]);
    $conversation=$stmt->fetch();
    if(!$conversation)$conversation=profile_agent_conversation_create($pdo,$ctx['profile'],$ctx['agent'],$ctx['session']);
    return [
        'agent'=>vp3_profile_agent_public_agent_projection_v110($ctx),
        'conversation'=>vp3_profile_agent_public_state_v390($conversation),
        'messages'=>profile_agent_messages($pdo,(int)$conversation['id']),
    ];
}
