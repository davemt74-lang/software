<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/profile-webmcp-rewards-v180.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}

$catalog=vp3_profile_webmcp_rewards_tool_catalog_v180();
t(count($catalog)===1,'9A exposes exactly one read-only Reward Wallet tool');
t(isset($catalog['vp3.rewards.wallet.get']),'Reward Wallet tool exists');
t(($catalog['vp3.rewards.wallet.get']['annotations']['readOnlyHint']??false)===true,'Reward Wallet is read-only');
t(($catalog['vp3.rewards.wallet.get']['annotations']['consequentialHint']??true)===false,'Reward Wallet is non-consequential');

$row=vp3_profile_webmcp_reward_row_v180([
  'id'=>77,'public_id'=>'reward-public-1','bucket'=>'inbox','status'=>'issued','reward_name'=>'Free Slice',
  'merchant_name'=>'Pizza Shop','campaign_name'=>'Welcome','value_label'=>'$5','quantity'=>1,'currency'=>'USD',
  'face_value_minor'=>500,'transferable'=>1,'expires_at'=>'2099-01-01 00:00:00','issued_at'=>'2026-09-29 00:00:00',
  'recipient_name'=>'Private Person','recipient_email'=>'private@example.com','credential'=>'SECRET','credential_hash'=>'HASH',
  'merchant_claim_code'=>'1234','crm_contact_id'=>9,'inventory_on_hand'=>12
]);
t(($row['public_id']??'')==='reward-public-1','public Reward issuance ID preserved');
t(($row['claimable']??false)===true,'active Inbox Reward is claimable');
foreach(['id','recipient_name','recipient_email','credential','credential_hash','merchant_claim_code','crm_contact_id','inventory_on_hand'] as $key){
  t(!array_key_exists($key,$row),$key.' must not project');
}
try{vp3_profile_webmcp_rewards_wallet_v180(new class extends PDO{public function __construct(){}},null);t(false,'anonymous wallet must fail');}
catch(RuntimeException $e){t(str_contains($e->getMessage(),'Sign in'),'anonymous wallet rejected');}
echo "PROFILE_WEBMCP_REWARDS_V180_PHP=PASS\n";
