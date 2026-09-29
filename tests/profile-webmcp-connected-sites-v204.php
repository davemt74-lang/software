<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/profile-webmcp-connected-sites-v204.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
$now=1000000;
t(vp3_profile_webmcp_connected_status_v204(false,true,'x','x',$now,$now)==='paused','paused');
t(vp3_profile_webmcp_connected_status_v204(true,false,'x','x',$now,$now)==='awaiting_verification','verification');
t(vp3_profile_webmcp_connected_status_v204(true,true,'old','new',$now,$now)==='outdated','outdated');
t(vp3_profile_webmcp_connected_status_v204(true,true,'','new',0,$now)==='awaiting_webmcp','unseen');
t(vp3_profile_webmcp_connected_status_v204(true,true,'new','new',$now-90000,$now)==='stale','stale');
t(vp3_profile_webmcp_connected_status_v204(true,true,'new','new',$now-60,$now)==='healthy','healthy');
echo "PROFILE_WEBMCP_CONNECTED_SITES_V204_PHP=PASS\n";
