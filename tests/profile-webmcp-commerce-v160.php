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
require dirname(__DIR__).'/includes/profile-webmcp-commerce-v160.php';

function t(bool $v,string $m): void { if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);} }

$catalog=vp3_profile_webmcp_commerce_tool_catalog_v160();
t(count($catalog)===10,'Section 7 commerce catalog must expose ten trusted tools');
foreach(['vp3.commerce.checkout.confirm','vp3.commerce.refund.confirm'] as $name){
    t(($catalog[$name]['annotations']['consequentialHint']??false)===true,$name.' must be consequential');
}
foreach(['vp3.commerce.products.list','vp3.commerce.product.get','vp3.commerce.order.get','vp3.commerce.receipt.get','vp3.commerce.delivery.get','vp3.commerce.refund.status'] as $name){
    t(($catalog[$name]['annotations']['readOnlyHint']??false)===true,$name.' must be read-only');
}
t(($catalog['vp3.commerce.checkout.prepare']['annotations']['consequentialHint']??true)===false,'checkout prepare is non-consequential');
t(($catalog['vp3.commerce.refund.prepare']['annotations']['consequentialHint']??true)===false,'refund prepare is non-consequential');

$property=['id'=>44,'owner_user_id'=>12,'is_active'=>1,'public_key'=>str_repeat('a',40),'verification_token'=>str_repeat('b',64)];
$profile=['user_id'=>12,'username'=>'demo'];
$telemetry=['webmcp_session_id'=>'CommerceSession_12345678','interaction_id'=>'CommerceInteraction_12345678','agent_referral'=>''];
$ctx=vp3_profile_webmcp_commerce_context_v160($profile,'external_site',$telemetry,'',$property,'https://shop.example.com');
t($ctx['property_id']===44,'external property binding');
t($ctx['origin_hash']===hash('sha256','https://shop.example.com'),'external origin binding');
t($ctx['session_hash']===hash('sha256','CommerceSession_12345678'),'session binding');

$intent=[
 'product_id'=>7,'product_slug'=>'consulting','product_state_hash'=>str_repeat('1',64),
 'terms_digest'=>str_repeat('2',64),'payer_email'=>'buyer@example.com','connection_id'=>3,
 'receipt_token'=>str_repeat('c',64)
];
$action=[
 'owner_user_id'=>12,'profile_username'=>'demo','surface'=>'external_site','property_id'=>44,
 'session_hash'=>$ctx['session_hash'],'intent_id'=>str_repeat('d',32),'operation'=>'commerce.checkout',
 'payload_hash'=>vp3_profile_webmcp_payload_hash_v150($intent),'expires_at_unix'=>time()+600
];
$token=vp3_profile_webmcp_commerce_token_v160($action,$ctx);
$verified=vp3_profile_webmcp_commerce_token_verify_v160($token,$ctx,'commerce.checkout',$intent);
t($verified['intent_id']===str_repeat('d',32),'commerce token verify');
t(!str_contains($token,'buyer@example.com'),'signed confirmation token must not expose payer email');
t(!str_contains($token,str_repeat('c',64)),'signed confirmation token must not expose receipt token');

$tampered=substr($token,0,-1).($token[-1]==='A'?'B':'A');
try{vp3_profile_webmcp_commerce_token_verify_v160($tampered,$ctx,'commerce.checkout',$intent);t(false,'tampered token must fail');}
catch(RuntimeException $e){t(true,'tamper rejected');}

$changed=$intent;$changed['connection_id']=4;
try{vp3_profile_webmcp_commerce_token_verify_v160($token,$ctx,'commerce.checkout',$changed);t(false,'changed intent must fail');}
catch(RuntimeException $e){t(true,'payload mismatch rejected');}

$other=$ctx;$other['session_hash']=hash('sha256','OtherCommerceSession_12345678');
try{vp3_profile_webmcp_commerce_token_verify_v160($token,$other,'commerce.checkout',$intent);t(false,'session mismatch must fail');}
catch(RuntimeException $e){t(true,'session mismatch rejected');}

$otherOrigin=$ctx;$otherOrigin['origin_hash']=hash('sha256','https://evil.example');
try{vp3_profile_webmcp_commerce_token_verify_v160($token,$otherOrigin,'commerce.checkout',$intent);t(false,'origin mismatch must fail');}
catch(RuntimeException $e){t(true,'origin mismatch rejected');}

echo "PROFILE_WEBMCP_COMMERCE_V160_PHP=PASS\n";
