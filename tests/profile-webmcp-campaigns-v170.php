<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/profile-webmcp-campaigns-v170.php';
function t(bool $v,string $m): void { if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);} }
$catalog=vp3_profile_webmcp_campaigns_tool_catalog_v170();
t(count($catalog)>=3,'Campaign catalog must retain the three 8A discovery tools');
foreach(['vp3.campaigns.list','vp3.campaign.get','vp3.campaign.eligibility.get'] as $name){
  t(isset($catalog[$name]),$name.' exists');
  t(($catalog[$name]['annotations']['readOnlyHint']??false)===true,$name.' is read-only');
  t(($catalog[$name]['annotations']['consequentialHint']??true)===false,$name.' is non-consequential');
  t(($catalog[$name]['capability']??'')==='campaigns',$name.' uses campaigns capability');
}
$reward=vp3_profile_webmcp_campaign_reward_projection_v170(['public_id'=>'r1','title'=>'Reward','description'=>'Safe','reward_type'=>'coupon','value_label'=>'$5','claim_limit'=>1,'inventory_limit'=>999,'remaining_quantity'=>999,'internal_cost_minor'=>12]);
t(!array_key_exists('inventory_limit',$reward),'inventory limit must not leak');
t(!array_key_exists('remaining_quantity',$reward),'remaining inventory must not leak');
t(!array_key_exists('internal_cost_minor',$reward),'internal economics must not leak');
echo "PROFILE_WEBMCP_CAMPAIGNS_V170_PHP=PASS\n";
