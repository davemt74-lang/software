<?php
declare(strict_types=1);
function url(string $path): string{return 'https://vp3.example'.$path;}
require dirname(__DIR__).'/includes/profile-webmcp-rewards-v180.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
$c=vp3_profile_webmcp_rewards_tool_catalog_v180();
t(isset($c['vp3.reward.get']),'Reward detail tool exists');
t(($c['vp3.reward.get']['annotations']['readOnlyHint']??false)===true,'Reward detail is read-only');
t(($c['vp3.reward.get']['annotations']['consequentialHint']??true)===false,'Reward detail is non-consequential');
$schema=$c['vp3.reward.get']['input_schema']??[];
t(in_array('reward_public_id',$schema['required']??[],true),'opaque Reward public ID required');
echo "PROFILE_WEBMCP_REWARDS_V181_PHP=PASS\n";
