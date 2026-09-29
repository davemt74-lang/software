<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/profile-webmcp-observability-v205.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
t(vp3_profile_webmcp_correlation_id_v205('abcDEF_123')==='abcDEF_123','correlation id');
t(vp3_profile_webmcp_correlation_id_v205('bad id')==='','bad correlation rejected');
t(vp3_profile_webmcp_stage_v205('webmcp_agent_planned')==='intent','agent plan stage');
t(vp3_profile_webmcp_stage_v205('webmcp_tool_called','vp3.booking.prepare')==='prepare','prepare stage');
t(vp3_profile_webmcp_stage_v205('webmcp_confirmation_required','vp3.booking.confirm')==='confirmation','confirmation stage');
t(vp3_profile_webmcp_stage_v205('webmcp_returned')==='return','return stage');
$lat=vp3_profile_webmcp_latency_summary_v205([
 ['surface'=>'native_profile','tool'=>'vp3.profile.get','duration_ms'=>10],
 ['surface'=>'native_profile','tool'=>'vp3.profile.get','duration_ms'=>30],
 ['surface'=>'external_site','tool'=>'vp3.profile.get','duration_ms'=>20],
]);
t(count($lat)===2,'latency groups');
t(($lat[0]['avg_ms']??0)>=20,'latency avg');
echo "PROFILE_WEBMCP_OBSERVABILITY_V205_PHP=PASS\n";
