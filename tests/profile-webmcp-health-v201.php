<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/profile-webmcp-health-v201.php';
function t(bool $v,string $m):void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
$components=[
 vp3_profile_webmcp_health_component_v201('resolver',true),
 vp3_profile_webmcp_health_component_v201('router',true),
];
$ok=vp3_profile_webmcp_health_rollup_v201($components,['registered_tool_count'=>4],null,4,4);
t($ok['status']==='healthy','healthy');
$drift=vp3_profile_webmcp_health_rollup_v201($components,null,null,5,4);
t($drift['status']==='degraded','registration drift');
$bad=vp3_profile_webmcp_health_rollup_v201([$components[0],vp3_profile_webmcp_health_component_v201('ledger',false)],null,null,5,0);
t($bad['status']==='unavailable','component failure');
$projection=vp3_profile_webmcp_health_event_projection_v201(['event_type'=>'webmcp_tool_failed','occurred_at'=>'x','details_json'=>json_encode(['tool'=>'vp3.profile.get','result_code'=>'ERR','registered_tool_count'=>3,'expected_tool_count'=>4,'secret'=>'no'])]);
t(($projection['result_code']??'')==='ERR','result code');
t(!array_key_exists('secret',$projection),'no arbitrary payload');
echo "PROFILE_WEBMCP_HEALTH_V201_PHP=PASS\n";
