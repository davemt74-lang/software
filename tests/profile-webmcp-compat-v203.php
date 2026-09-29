<?php
declare(strict_types=1);
const VP3_PROFILE_WEBMCP_RELEASE_V196='release-v1';
require dirname(__DIR__).'/includes/profile-webmcp-compat-v203.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
$catalog=['vp3.a'=>[],'vp3.b'=>[]];
$r=vp3_profile_webmcp_tool_registry_v203($catalog);
t(($r['vp3.a']['state']??'')==='active','default active');
t(vp3_profile_webmcp_tool_available_v203('vp3.a',$catalog,0),'active available');
t(!vp3_profile_webmcp_tool_available_v203('vp3.missing',$catalog,0),'missing unavailable');
$s=vp3_profile_webmcp_tool_registry_summary_v203($catalog);
t(($s['counts']['active']??0)===2,'active count');
t(($s['contract']??'')===VP3_PROFILE_WEBMCP_COMPAT_CONTRACT_V203,'contract');
echo "PROFILE_WEBMCP_COMPAT_V203_PHP=PASS\n";
