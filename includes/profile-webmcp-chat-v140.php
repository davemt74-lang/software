<?php
declare(strict_types=1);

const VP3_PROFILE_WEBMCP_CHAT_V140='profile-webmcp-chat-v140-20260928';
const VP3_PROFILE_WEBMCP_CHAT_GRANT_SECONDS_V140=1200;

/** Request-local proof, populated only by successful signature verification. */
function vp3_profile_webmcp_verified_chat_grant_v1(?array $set=null,bool $clear=false): ?array
{
    static $verified=null;
    if($clear)$verified=null;
    if($set!==null)$verified=$set;
    return $verified;
}

function vp3_profile_webmcp_chat_property_fingerprint_v1(array $property): string
{
    $fields=[];foreach(['id','owner_user_id','property_type','domain','public_key','verification_token','is_active'] as $key)$fields[$key]=(string)($property[$key]??'');
    return hash('sha256',json_encode($fields,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
}

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
    vp3_profile_webmcp_verified_chat_grant_v1(null,true);
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
        !empty($property['is_active']),
        (int)($property['owner_user_id']??0)===(int)($profile['user_id']??0),
        (int)($payload['property_id']??0)===(int)($property['id']??0),
        (int)($payload['owner_user_id']??0)===(int)($profile['user_id']??0),
        hash_equals((string)($payload['profile_username']??''),(string)($profile['username']??'')),
        hash_equals((string)($payload['origin_hash']??''),hash('sha256',$origin)),
        $sessionId!==''&&hash_equals((string)($payload['session_hash']??''),hash('sha256',$sessionId)),
    ];
    if(in_array(false,$checks,true))throw new VP3ProfileAgentPublicException('CHAT_GRANT_MISMATCH','Connected-site chat grant does not match this site session.',403);
    vp3_profile_webmcp_verified_chat_grant_v1([
        'payload'=>$payload,'origin'=>$origin,
        'property_fingerprint'=>vp3_profile_webmcp_chat_property_fingerprint_v1($property),
        'credential_hash'=>hash_hmac('sha256','profile-conversation-v1|'.$payload['owner_user_id'].'|'.$payload['property_id'].'|'.$payload['origin_hash'].'|'.$payload['session_hash'],vp3_profile_webmcp_chat_grant_secret_v140($property)),
    ]);
    return $payload;
}

function vp3_profile_webmcp_external_chat_authorized_v1(PDO $pdo,array $ctx,bool $lock=false): bool
{
    $proof=$ctx['external_chat_proof']??null;$verified=vp3_profile_webmcp_verified_chat_grant_v1();
    if(!is_array($proof)||!is_array($verified)||!hash_equals((string)($proof['credential_hash']??''),(string)($verified['credential_hash']??'')))return false;
    $payload=$proof['payload']??[];$owner=(int)($ctx['owner_user_id']??0);
    if((int)($payload['exp']??0)<time()||(int)($payload['iat']??0)>time()+60
       ||(int)($payload['owner_user_id']??0)!==$owner
       ||(string)($payload['profile_username']??'')!==(string)($ctx['profile']['username']??'')
       ||(int)($ctx['visitor']['id']??0)!==0)return false;
    $q=$pdo->prepare('SELECT * FROM vp3_radar_properties WHERE id=? AND owner_user_id=? LIMIT 1'.($lock&&$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'?' FOR UPDATE':''));
    $q->execute([(int)($payload['property_id']??0),$owner]);$property=$q->fetch(PDO::FETCH_ASSOC);
    if(!$property||empty($property['is_active'])||($property['property_type']??'')!=='external'
       ||!hash_equals((string)$proof['property_fingerprint'],vp3_profile_webmcp_chat_property_fingerprint_v1($property)))return false;
    if(!function_exists('vp3_profile_webmcp_external_origin_v120'))return false;
    try{$origin=vp3_profile_webmcp_external_origin_v120($property,(string)$proof['origin']);}catch(Throwable $e){return false;}
    return hash_equals((string)$payload['origin_hash'],hash('sha256',$origin))
        &&hash_equals((string)$proof['credential_hash'],(string)($ctx['conversation_authority']['credential_hash']??''));
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
    $proof=vp3_profile_webmcp_verified_chat_grant_v1();$payload=$proof['payload']??[];
    $sessionToken=vp3_profile_webmcp_transport_id_v130($webmcpSessionId);
    if(!is_array($proof)||(int)($payload['owner_user_id']??0)!==$owner
       ||(int)($payload['property_id']??0)!==(int)($property['id']??0)
       ||!hash_equals((string)($payload['session_hash']??''),hash('sha256',$sessionToken))
       ||$sessionToken==='')throw new VP3ProfileAgentPublicException('CHAT_GRANT_INVALID','Connected-site chat grant is invalid.',403);
    $ownerUser=$owner>0?profile_user_row($pdo,$owner):null;
    if(!$ownerUser
       || !personal_capability_has_v242('profile_agent.access',$ownerUser)
       || !personal_capability_has_v242('profile_chat.access',$ownerUser)){
        throw new VP3ProfileAgentPublicException('PROFILE_AGENT_UNAVAILABLE','This Profile Agent chat is not available.',404);
    }
    $agent=profile_active_agent($pdo,$profile);
    if(!$agent)throw new VP3ProfileAgentPublicException('PROFILE_AGENT_UNAVAILABLE','This Profile Agent is not available.',404);
    $session=vp3_profile_webmcp_external_profile_session_v140($pdo,$property,$profile,$webmcpSessionId);
    $ctx=[
        'profile'=>$profile,
        'owner_user_id'=>$owner,
        'owner_user'=>$ownerUser,
        'visitor'=>null,
        'agent'=>$agent,
        'agent_id'=>(int)$agent['id'],
        'session'=>$session,
        'session_id'=>(int)$session['id'],
        'external_chat_proof'=>$proof,
        'conversation_authority'=>[
            'contract'=>'vp3.profile-conversation-authority.v1','transport'=>'external_site',
            'owner_user_id'=>$owner,'visitor_user_id'=>0,'credential_hash'=>$proof['credential_hash'],
        ],
    ];
    return vp3_profile_agent_public_live_v1($pdo,$ctx);
}

function vp3_profile_webmcp_chat_start_v140(PDO $pdo,array $ctx): array
{
    return vp3_profile_agent_public_start_v1($pdo,$ctx);
}
