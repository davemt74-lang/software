<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/profile-webmcp-sites-v204.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
t(VP3_PROFILE_WEBMCP_SITE_STALE_SECONDS_V204===604800,'stale window');
$reflection=new ReflectionFunction('vp3_profile_webmcp_site_state_v204');
t($reflection->getNumberOfParameters()===2,'site state signature');
echo "PROFILE_WEBMCP_SITES_V204_PHP=PASS\n";
