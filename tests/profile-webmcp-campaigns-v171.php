<?php
declare(strict_types=1);
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

function vp3_profile_webmcp_transport_id_v130(string $value): string {$value=trim($value);return preg_match('/^[A-Za-z0-9_-]{8,96}$/',$value)?$value:'';}
function vp3_profile_webmcp_b64url_encode_v140(string $value): string {return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
function vp3_profile_webmcp_b64url_decode_v140(string $value): string {if(!preg_match('/^[A-Za-z0-9_-]+$/',$value))return '';$pad=(4-(strlen($value)%4))%4;$d=base64_decode(strtr($value,'-_','+/').str_repeat('=',$pad),true);return is_string($d)?$d:'';}

require dirname(__DIR__).'/includes/profile-webmcp-campaigns-v170.php';
function t(bool $v,string $m): void {if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}

$catalog=vp3_profile_webmcp_campaigns_tool_catalog_v170();
t(count($catalog)>=5,'Campaign catalog must retain all five 8B trusted tools');
t(($catalog['vp3.campaign.participation.prepare']['annotations']['consequentialHint']??true)===false,'prepare must be non-consequential');
t(($catalog['vp3.campaign.participation.confirm']['annotations']['consequentialHint']??false)===true,'confirm must be consequential');

$profile=['user_id'=>12,'username'=>'demo'];
$telemetry=['webmcp_session_id'=>'CampaignSession_12345678'];
$property=['id'=>44,'is_active'=>1,'public_key'=>str_repeat('a',40),'verification_token'=>str_repeat('b',64)];
$ctx=vp3_profile_webmcp_campaign_context_v170($profile,'external_site',$telemetry,'',$property,'https://shop.example.com');
t($ctx['property_id']===44,'external property binding');
t($ctx['session_hash']===hash('sha256','CampaignSession_12345678'),'session binding');
t($ctx['origin_hash']===hash('sha256','https://shop.example.com'),'origin binding');

$intent=['campaign_id'=>7,'campaign_slug'=>'summer','name'=>'Guest','email'=>'guest@example.com','phone'=>'','birthday'=>'','social_handle'=>'','proof_url'=>'','referral_ref'=>'','marketing_consent'=>true,'reward_public_id'=>'','campaign_state_hash'=>str_repeat('1',64)];
$action=['owner_user_id'=>12,'profile_username'=>'demo','surface'=>'external_site','property_id'=>44,'session_hash'=>$ctx['session_hash'],'intent_id'=>str_repeat('c',32),'operation'=>'campaign.participate','payload_hash'=>vp3_profile_webmcp_payload_hash_v150($intent),'expires_at_unix'=>time()+600];
$token=vp3_profile_webmcp_campaign_token_v170($action,$ctx);
t(!str_contains($token,'guest@example.com'),'confirmation token must not expose participant email');
t(!str_contains($token,'Guest'),'confirmation token must not expose participant name');
$verified=vp3_profile_webmcp_campaign_token_verify_v170($token,$ctx,'campaign.participate',$intent);
t($verified['intent_id']===str_repeat('c',32),'valid Campaign confirmation token');

$tampered=substr($token,0,-1).($token[-1]==='A'?'B':'A');
try{vp3_profile_webmcp_campaign_token_verify_v170($tampered,$ctx,'campaign.participate',$intent);t(false,'tampered token must fail');}catch(RuntimeException $e){t(true,'tamper rejected');}
$changed=$intent;$changed['marketing_consent']=false;
try{vp3_profile_webmcp_campaign_token_verify_v170($token,$ctx,'campaign.participate',$changed);t(false,'changed intent must fail');}catch(RuntimeException $e){t(true,'intent mismatch rejected');}
$other=$ctx;$other['origin_hash']=hash('sha256','https://evil.example');
try{vp3_profile_webmcp_campaign_token_verify_v170($token,$other,'campaign.participate',$intent);t(false,'origin mismatch must fail');}catch(RuntimeException $e){t(true,'origin mismatch rejected');}

echo "PROFILE_WEBMCP_CAMPAIGNS_V171_PHP=PASS\n";
