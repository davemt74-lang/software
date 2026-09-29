<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/profile-webmcp-health-v201.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
$c=vp3_profile_webmcp_health_component_v201(true,'v1','ok');
t($c['status']==='ready','ready component');
t($c['ready']===true,'ready boolean');
t(!array_key_exists('token',$c),'no token field');
$r=vp3_profile_webmcp_health_component_v201(false,'','','x');
t($r['status']==='unavailable','unavailable component');
echo "PROFILE_WEBMCP_HEALTH_V201_PHP=PASS\n";
