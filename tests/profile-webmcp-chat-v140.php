<?php
declare(strict_types=1);

final class VP3ProfileAgentPublicException extends RuntimeException
{
    public function __construct(
        public readonly string $publicCode,
        string $message,
        public readonly int $httpStatus=400
    ){ parent::__construct($message); }
}
function vp3_profile_webmcp_transport_id_v130(string $value): string
{
    $value=trim($value);
    return preg_match('/^[A-Za-z0-9_-]{8,96}$/',$value)?$value:'';
}

require dirname(__DIR__).'/includes/profile-webmcp-chat-v140.php';

function t(bool $value,string $message): void { if(!$value){fwrite(STDERR,"FAIL: {$message}\n");exit(1);} }

$now=1700000000;
$property=[
    'id'=>44,'owner_user_id'=>123,'domain'=>'example.com','is_active'=>1,
    'public_key'=>str_repeat('a',40),'verification_token'=>str_repeat('b',64),
];
$profile=['user_id'=>123,'username'=>'demo'];
$origin='https://shop.example.com';
$session='WebMCP_Session_12345678';

$issued=vp3_profile_webmcp_chat_grant_create_v140($property,$profile,$origin,$session,$now);
t(str_contains($issued['grant'],'.'),'signed grant shape');
t($issued['expires_at_unix']===$now+VP3_PROFILE_WEBMCP_CHAT_GRANT_SECONDS_V140,'grant ttl');
$payload=vp3_profile_webmcp_chat_grant_verify_v140($property,$profile,$origin,$session,$issued['grant'],$now+30);
t($payload['property_id']===44,'property binding');
t($payload['owner_user_id']===123,'owner binding');
t($payload['profile_username']==='demo','profile binding');
t($payload['origin_hash']===hash('sha256',$origin),'origin hash');
t($payload['session_hash']===hash('sha256',$session),'session hash');
t(!array_key_exists('verification_token',$payload),'secret not in payload');
t(!array_key_exists('public_key',$payload),'public key not in payload');

$tampered=substr($issued['grant'],0,-1).($issued['grant'][-1]==='A'?'B':'A');
try{vp3_profile_webmcp_chat_grant_verify_v140($property,$profile,$origin,$session,$tampered,$now+30);t(false,'tamper must fail');}
catch(VP3ProfileAgentPublicException $e){t($e->publicCode==='CHAT_GRANT_INVALID','tamper code');}

try{vp3_profile_webmcp_chat_grant_verify_v140($property,$profile,'https://evil.example',$session,$issued['grant'],$now+30);t(false,'origin mismatch must fail');}
catch(VP3ProfileAgentPublicException $e){t($e->publicCode==='CHAT_GRANT_MISMATCH','origin mismatch code');}

try{vp3_profile_webmcp_chat_grant_verify_v140($property,$profile,$origin,'Other_Session_12345678',$issued['grant'],$now+30);t(false,'session mismatch must fail');}
catch(VP3ProfileAgentPublicException $e){t($e->publicCode==='CHAT_GRANT_MISMATCH','session mismatch code');}

try{vp3_profile_webmcp_chat_grant_verify_v140($property,['user_id'=>123,'username'=>'other'],$origin,$session,$issued['grant'],$now+30);t(false,'profile mismatch must fail');}
catch(VP3ProfileAgentPublicException $e){t($e->publicCode==='CHAT_GRANT_MISMATCH','profile mismatch code');}

try{vp3_profile_webmcp_chat_grant_verify_v140($property,$profile,$origin,$session,$issued['grant'],$now+VP3_PROFILE_WEBMCP_CHAT_GRANT_SECONDS_V140+1);t(false,'expired grant must fail');}
catch(VP3ProfileAgentPublicException $e){t($e->publicCode==='CHAT_GRANT_EXPIRED'&&$e->httpStatus===419,'expiry code');}

$inactive=$property;$inactive['is_active']=0;
try{vp3_profile_webmcp_chat_grant_create_v140($inactive,$profile,$origin,$session,$now);t(false,'inactive property cannot mint grant');}
catch(RuntimeException $e){t(true,'inactive denied');}

$badSecret=$property;$badSecret['verification_token']='short';
try{vp3_profile_webmcp_chat_grant_create_v140($badSecret,$profile,$origin,$session,$now);t(false,'invalid secret cannot mint grant');}
catch(RuntimeException $e){t(true,'invalid secret denied');}

echo "PROFILE_WEBMCP_CHAT_V140_PHP=PASS\n";
