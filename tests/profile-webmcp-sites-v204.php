<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/profile-webmcp-sites-v204.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
t(VP3_PROFILE_WEBMCP_SITE_STALE_SECONDS_V204===604800,'stale window');
$reflection=new ReflectionFunction('vp3_profile_webmcp_site_state_v204');
 t($reflection->getNumberOfParameters()===2,'site state signature');
t(vp3_profile_webmcp_site_status_v204(false,true,'current',false,false,0)==='paused','paused');
t(vp3_profile_webmcp_site_status_v204(true,false,'current',false,false,0)==='unverified','unverified');
t(vp3_profile_webmcp_site_status_v204(true,true,'upgrade_required',false,false,0)==='upgrade_required','upgrade required');
t(vp3_profile_webmcp_site_status_v204(true,true,'current',true,false,0)==='stale','stale');
t(vp3_profile_webmcp_site_status_v204(true,true,'current',false,true,0)==='degraded','degraded');
t(vp3_profile_webmcp_site_status_v204(true,true,'current',false,false,1)==='origin_attention','origin attention');
t(vp3_profile_webmcp_site_status_v204(true,true,'current',false,false,0)==='healthy','healthy');
echo "PROFILE_WEBMCP_SITES_V204_PHP=PASS\n";
