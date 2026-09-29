<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/profile-webmcp-confirmation-v192.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}

$intentId=str_repeat('a',32);
$prepared=vp3_profile_webmcp_action_envelope_v192('vp3.booking.prepare',[
  'intent_id'=>$intentId,
  'confirmation_token'=>'signed-token',
  'expires_at_unix'=>time()+600,
  'intent'=>['event_slug'=>'demo'],
  'preview'=>['event'=>['title'=>'Demo'],'start_at_utc'=>'2026-10-01T18:00:00Z'],
  'confirmation_required'=>true,
]);
t(($prepared['action']['contract']??'')===VP3_PROFILE_WEBMCP_ACTION_CONTRACT_V192,'contract');
t(($prepared['action']['phase']??'')==='prepared','prepared phase');
t(($prepared['action']['confirm_tool']??'')==='vp3.booking.confirm','confirm pair');
t(($prepared['action']['intent_id']??'')===$intentId,'intent id');
t(($prepared['action']['confirmation']['token']??'')==='signed-token','token preserved for runtime');
t(!empty($prepared['action']['expires_at_utc']),'UTC expiry');

$choice=vp3_profile_webmcp_action_envelope_v192('vp3.commerce.checkout.prepare',[
  'provider_selection_required'=>true,
  'confirmation_required'=>false,
]);
t(($choice['action']['phase']??'')==='needs_input','provider selection phase');
t(empty($choice['action']['requires_confirmation']),'provider selection not confirmation');

$checkout=vp3_profile_webmcp_action_envelope_v192('vp3.commerce.checkout.prepare',[
  'intent_id'=>$intentId,'confirmation_token'=>'x','expires_at_unix'=>time()+600,
  'intent'=>[],'preview'=>[],'confirmation_required'=>true,
]);
t(!empty($checkout['action']['confirmation']['requires_terms_acceptance']),'checkout terms gate');

$done=vp3_profile_webmcp_action_envelope_v192('vp3.rewards.transfer.confirm',['idempotent_replay'=>true]);
t(($done['action']['phase']??'')==='completed','completed phase');
t(!empty($done['action']['idempotent_replay']),'replay preserved');

$cases=[
 ['Scheduling confirmation expired. Prepare the action again.','CONFIRMATION_EXPIRED',true,false],
 ['That checkout confirmation is already in progress. Retry with the same idempotency key.','ACTION_IN_PROGRESS',false,true],
 ['Idempotency key was already used for a different checkout.','IDEMPOTENCY_CONFLICT',false,false],
 ['Campaign confirmation does not match this session or action.','CONFIRMATION_MISMATCH',true,false],
 ['Reward confirmation ledger is unavailable.','ACTION_UNAVAILABLE',false,false],
];
foreach($cases as [$message,$code,$reprepare,$reuse]){
  $e=vp3_profile_webmcp_action_error_v192(new RuntimeException($message),'fallback');
  t(($e['result_code']??'')===$code,$code.' mapping');
  t((bool)($e['payload']['action']['reprepare_required']??false)===$reprepare,$code.' reprepare');
  t((bool)($e['payload']['action']['reuse_same_idempotency_key']??false)===$reuse,$code.' reuse key');
}
echo "PROFILE_WEBMCP_CONFIRMATION_V192_PHP=PASS\n";
