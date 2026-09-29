<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/profile-webmcp-admin-v202.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
$rows=[
 ['event_name'=>'webmcp_tool_called','surface'=>'native_profile','client_runtime_build'=>'native-1','negotiation_mode'=>'negotiated'],
 ['event_name'=>'webmcp_tool_completed','surface'=>'native_profile','client_runtime_build'=>'native-1','negotiation_mode'=>'negotiated'],
 ['event_name'=>'webmcp_tool_failed','surface'=>'external_site','tool'=>'vp3.profile.get','result_code'=>'TEMP','client_runtime_build'=>'external-1','negotiation_mode'=>'legacy_v1'],
 ['event_name'=>'webmcp_tool_denied','surface'=>'external_site','client_runtime_build'=>'external-1','negotiation_mode'=>'legacy_v1'],
 ['event_name'=>'webmcp_confirmation_required','surface'=>'native_profile','client_runtime_build'=>'native-1','negotiation_mode'=>'negotiated'],
];
$s=vp3_profile_webmcp_admin_summarize_v202($rows);
t($s['calls']===1,'calls');
t($s['completed']===1,'completed');
t($s['failures']===1,'failures');
t($s['denied']===1,'denied');
t($s['confirmation_required']===1,'confirmations');
t($s['failure_rate_percent']===66.7,'failure rate');
t(($s['runtime_distribution']['native-1']??0)===3,'native runtime count');
t(empty($s['contains_sensitive_payload']),'safe projection');
echo "PROFILE_WEBMCP_ADMIN_V202_PHP=PASS\n";
