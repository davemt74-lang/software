<?php
declare(strict_types=1);

if(session_status()!==PHP_SESSION_ACTIVE)session_start();

function vp3_profile_webmcp_transport_id_v130(string $value): string {
    $value=trim($value);return preg_match('/^[A-Za-z0-9_-]{8,96}$/',$value)?$value:'';
}
function vp3_profile_webmcp_b64url_encode_v140(string $value): string { return rtrim(strtr(base64_encode($value),'+/','-_'),'='); }
function vp3_profile_webmcp_b64url_decode_v140(string $value): string {
    if(!preg_match('/^[A-Za-z0-9_-]+$/',$value))return '';
    $pad=(4-(strlen($value)%4))%4;$d=base64_decode(strtr($value,'-_','+/').str_repeat('=',$pad),true);return is_string($d)?$d:'';
}

require dirname(__DIR__).'/includes/profile-webmcp-actions-v150.php';
require dirname(__DIR__).'/includes/profile-webmcp-scheduling-v150.php';

function t(bool $v,string $m): void { if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);} }

$a=['b'=>2,'a'=>['z'=>9,'x'=>1]];
$b=['a'=>['x'=>1,'z'=>9],'b'=>2];
t(vp3_profile_webmcp_payload_hash_v150($a)===vp3_profile_webmcp_payload_hash_v150($b),'canonical payload hashing');
t(strlen(vp3_profile_webmcp_idempotency_hash_v150(12,'booking.create','idem_key_1234'))===64,'idempotency hash');
try{vp3_profile_webmcp_idempotency_hash_v150(12,'booking.create','short');t(false,'short idempotency key must fail');}
catch(InvalidArgumentException $e){t(true,'short idempotency rejected');}

$nativeProof=str_repeat('a',64);
$externalProperty=['id'=>44,'owner_user_id'=>12,'is_active'=>1,'public_key'=>str_repeat('b',40),'verification_token'=>str_repeat('c',64)];
$profile=['user_id'=>12,'username'=>'demo'];
$telemetry=['webmcp_session_id'=>'Session_12345678','interaction_id'=>'Interaction_12345678','agent_referral'=>''];
$native=vp3_profile_webmcp_scheduling_context_v150($profile,'native_profile',$telemetry,$nativeProof,null,'');
$external=vp3_profile_webmcp_scheduling_context_v150($profile,'external_site',$telemetry,'',$externalProperty,'https://shop.example.com');
$nativeSecret1=$native['secret'];
$nativeWithDifferentProof=vp3_profile_webmcp_scheduling_context_v150($profile,'native_profile',$telemetry,str_repeat('f',64),null,'');
t(hash_equals($nativeSecret1,$nativeWithDifferentProof['secret']),'browser-visible proof must not derive signing secret');
t($native['surface']==='native_profile'&&$native['property_id']===0,'native context');
t($external['surface']==='external_site'&&$external['property_id']===44,'external context');
t($external['origin_hash']===hash('sha256','https://shop.example.com'),'origin binding');
t($native['session_hash']===hash('sha256','Session_12345678'),'session binding');
t(!hash_equals($native['secret'],$external['secret']),'surface secrets must differ');

$intent=[
 'event_type_id'=>7,'event_state_hash'=>str_repeat('1',64),'start_at_utc'=>'2030-01-02 18:00:00',
 'guest_timezone'=>'America/Phoenix','guest_name'=>'Guest','guest_email'=>'guest@example.com',
 'guest_phone'=>'','guest_notes'=>'','intake'=>[]
];
$action=[
 'owner_user_id'=>12,'profile_username'=>'demo','surface'=>'native_profile','property_id'=>null,
 'session_hash'=>$native['session_hash'],'intent_id'=>str_repeat('d',32),'operation'=>'booking.create',
 'payload_hash'=>vp3_profile_webmcp_payload_hash_v150($intent),'expires_at_unix'=>time()+600
];
$token=vp3_profile_webmcp_scheduling_token_v150($action,$native);
$verified=vp3_profile_webmcp_scheduling_token_verify_v150($token,$native,'booking.create',$intent);
t($verified['intent_id']===str_repeat('d',32),'intent token verify');
t(!str_contains($token,'guest@example.com'),'signed token must not expose guest email');
t(!str_contains($token,'Guest'),'signed token must not expose guest name');

$tampered=substr($token,0,-1).($token[-1]==='A'?'B':'A');
try{vp3_profile_webmcp_scheduling_token_verify_v150($tampered,$native,'booking.create',$intent);t(false,'tamper must fail');}
catch(RuntimeException $e){t(true,'tamper rejected');}

$changed=$intent;$changed['start_at_utc']='2030-01-02 19:00:00';
try{vp3_profile_webmcp_scheduling_token_verify_v150($token,$native,'booking.create',$changed);t(false,'payload change must fail');}
catch(RuntimeException $e){t(true,'payload mismatch rejected');}

$otherSession=$native;$otherSession['session_hash']=hash('sha256','Other_Session_12345678');
try{vp3_profile_webmcp_scheduling_token_verify_v150($token,$otherSession,'booking.create',$intent);t(false,'session change must fail');}
catch(RuntimeException $e){t(true,'session mismatch rejected');}

$expired=$action;$expired['expires_at_unix']=time()-1;
$expiredToken=vp3_profile_webmcp_scheduling_token_v150($expired,$native);
try{vp3_profile_webmcp_scheduling_token_verify_v150($expiredToken,$native,'booking.create',$intent);t(false,'expired intent must fail');}
catch(RuntimeException $e){t(true,'expiry rejected');}

echo "PROFILE_WEBMCP_SCHEDULING_V150_PHP=PASS\n";
