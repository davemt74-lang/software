<?php
declare(strict_types=1);
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
function vp3_profile_webmcp_transport_id_v130(string $v): string{return trim($v);}
require dirname(__DIR__).'/includes/profile-webmcp-rewards-v180.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
$c=vp3_profile_webmcp_rewards_tool_catalog_v180();
t(isset($c['vp3.rewards.claim.prepare']),'claim prepare tool exists');
t(isset($c['vp3.rewards.claim.confirm']),'claim confirm tool exists');
t(($c['vp3.rewards.claim.prepare']['annotations']['consequentialHint']??true)===false,'claim prepare non-consequential');
t(($c['vp3.rewards.claim.confirm']['annotations']['consequentialHint']??false)===true,'claim confirm consequential');
foreach(['vp3.rewards.claim.prepare','vp3.rewards.claim.confirm'] as $name)t(($c[$name]['capability']??'')==='rewards',$name.' rewards capability');
echo "PROFILE_WEBMCP_REWARDS_V182_PHP=PASS\n";
