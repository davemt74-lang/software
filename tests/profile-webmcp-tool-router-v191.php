<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/profile-webmcp-tool-router-v191.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
t(defined('VP3_PROFILE_WEBMCP_TOOL_ROUTER_V191'),'router version constant');
t(function_exists('vp3_profile_webmcp_dispatch_v191'),'dispatcher exists');
t(function_exists('vp3_profile_webmcp_router_record_v191'),'router telemetry helper exists');
t(function_exists('vp3_profile_webmcp_router_elapsed_v191'),'elapsed helper exists');
$before=microtime(true);
$elapsed=vp3_profile_webmcp_router_elapsed_v191($before);
t($elapsed>=0,'elapsed is non-negative');
echo "PROFILE_WEBMCP_TOOL_ROUTER_V191_PHP=PASS\n";
