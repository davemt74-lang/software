<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/profile-webmcp-rewards-v180.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
$c=vp3_profile_webmcp_rewards_tool_catalog_v180();
t(isset($c['vp3.loyalty.status.get']),'loyalty status tool exists');
t(($c['vp3.loyalty.status.get']['annotations']['readOnlyHint']??false)===true,'loyalty status read-only');
t(($c['vp3.loyalty.status.get']['annotations']['consequentialHint']??true)===false,'loyalty status non-consequential');
t(($c['vp3.loyalty.status.get']['capability']??'')==='rewards','loyalty status uses authenticated Rewards capability');
echo "PROFILE_WEBMCP_REWARDS_V184_PHP=PASS\n";
