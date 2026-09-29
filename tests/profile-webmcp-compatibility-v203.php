<?php
declare(strict_types=1);
const VP3_PROFILE_WEBMCP_MANIFEST_V100='vp3.profile.webmcp.v1';
const VP3_PROFILE_WEBMCP_RELEASE_V196='profile-webmcp-release-v196-20260929';
const VP3_PROFILE_WEBMCP_NEGOTIATION_CONTRACT_V200='vp3.profile.webmcp.negotiation.v1';
require dirname(__DIR__).'/includes/profile-webmcp-compatibility-v203.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
$catalog=[
 'vp3.profile.get'=>['annotations'=>['consequentialHint'=>false]],
 'vp3.booking.confirm'=>['annotations'=>['consequentialHint'=>true]],
 'vp3.booking.legacy'=>['annotations'=>['consequentialHint'=>true]],
];
$overrides=[
 'vp3.booking.confirm'=>['status'=>'deprecated','replacement_tool'=>'vp3.profile.get','sunset_at'=>'2027-01-31'],
 'vp3.booking.legacy'=>['status'=>'disabled'],
 'vp3.profile.get'=>['status'=>'active','minimum_runtime_build'=>'runtime-new'],
];
$r=vp3_profile_webmcp_compatibility_registry_v203($catalog,$overrides);
t($r['vp3.profile.get']['status']==='active','active default');
t($r['vp3.profile.get']['minimum_runtime_build']==='runtime-new','minimum runtime metadata');
t($r['vp3.booking.confirm']['status']==='deprecated','deprecated state');
t($r['vp3.booking.confirm']['execution_path']==='canonical_router','deprecated canonical router');
t($r['vp3.booking.confirm']['confirmation_policy']==='explicit_confirmation_and_idempotency','confirmation retained');
t($r['vp3.booking.legacy']['status']==='disabled','disabled state');
$filtered=vp3_profile_webmcp_apply_compatibility_v203(array_keys($catalog),$r);
t(in_array('vp3.booking.confirm',$filtered,true),'deprecated stays discoverable');
t(!in_array('vp3.booking.legacy',$filtered,true),'disabled removed');
// Production availability uses the canonical registry; explicit runtime-floor behavior is also covered statically at the router boundary.
echo "PROFILE_WEBMCP_COMPATIBILITY_V203_PHP=PASS\n";
