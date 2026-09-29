<?php
declare(strict_types=1);
if(session_status()!==PHP_SESSION_ACTIVE)session_start();

function vp3_profile_webmcp_transport_id_v130(string $value): string {$value=trim($value);return preg_match('/^[A-Za-z0-9_-]{8,96}$/',$value)?$value:'';}
require dirname(__DIR__).'/includes/profile-webmcp-rewards-v180.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}

$catalog=vp3_profile_webmcp_rewards_tool_catalog_v180();
foreach(['vp3.rewards.transfer.contacts.list','vp3.reward.transfer.prepare','vp3.reward.transfer.confirm'] as $name)t(isset($catalog[$name]),$name.' exists');
t(($catalog['vp3.rewards.transfer.contacts.list']['annotations']['readOnlyHint']??false)===true,'contacts list is read-only');
t(($catalog['vp3.reward.transfer.prepare']['annotations']['consequentialHint']??true)===false,'prepare is non-consequential');
t(($catalog['vp3.reward.transfer.confirm']['annotations']['consequentialHint']??false)===true,'confirm is consequential');

$holder=[
 'id'=>77,'public_id'=>'reward-public','status'=>'issued','remaining_quantity'=>1,'expires_at'=>'2099-01-01 00:00:00',
 'recipient_contact_id'=>5,'recipient_user_id'=>12,'terms_snapshot_json'=>'{"transferable":true,"value_label":"$5"}','updated_at'=>'2026-09-29 00:00:00'
];
$contact=['id'=>44,'name'=>'Friend','email'=>'friend@example.com','vp3_user_id'=>0];
$h1=vp3_profile_webmcp_reward_transfer_state_hash_v182($holder,$contact);
$contact['email']='changed@example.com';
$h2=vp3_profile_webmcp_reward_transfer_state_hash_v182($holder,$contact);
t($h1!==$h2,'target contact changes must invalidate prepared transfer');
$holder['remaining_quantity']=0;
$h3=vp3_profile_webmcp_reward_transfer_state_hash_v182($holder,$contact);
t($h2!==$h3,'Reward holder state changes must invalidate prepared transfer');

$ctx=['owner_user_id'=>12,'profile_username'=>'demo','surface'=>'native_profile','session_hash'=>str_repeat('a',64),'secret'=>str_repeat('s',32)];
$intent=['reward_public_id'=>'reward-public','target_contact_id'=>44,'note'=>'Enjoy','state_hash'=>str_repeat('1',64)];
$action=['owner_user_id'=>12,'profile_username'=>'demo','surface'=>'native_profile','session_hash'=>$ctx['session_hash'],'intent_id'=>str_repeat('c',32),'operation'=>'reward.transfer','payload_hash'=>vp3_profile_webmcp_payload_hash_v150($intent),'expires_at_unix'=>time()+600];
$token=vp3_profile_webmcp_rewards_token_v182($action,$ctx);
t(!str_contains($token,'friend@example.com'),'confirmation token must not expose target email');
$verified=vp3_profile_webmcp_rewards_token_verify_v182($token,$ctx,'reward.transfer',$intent);
t($verified['intent_id']===str_repeat('c',32),'valid transfer token');
$changed=$intent;$changed['target_contact_id']=45;
try{vp3_profile_webmcp_rewards_token_verify_v182($token,$ctx,'reward.transfer',$changed);t(false,'changed intent must fail');}catch(RuntimeException $e){t(true,'changed intent rejected');}
echo "PROFILE_WEBMCP_REWARDS_V182_PHP=PASS\n";
