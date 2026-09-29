<?php
declare(strict_types=1);

require dirname(__DIR__).'/includes/profile-webmcp-release-v196.php';

function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}

$catalog=[
    'vp3.profile.get'=>[
        'capability'=>'profile',
        'input_schema'=>['type'=>'object'],
        'annotations'=>['readOnlyHint'=>true,'consequentialHint'=>false],
    ],
    'vp3.booking.confirm'=>[
        'capability'=>'booking',
        'input_schema'=>['type'=>'object'],
        'annotations'=>['readOnlyHint'=>false,'consequentialHint'=>true],
    ],
];
$registry=[
    'profile'=>['surfaces'=>['native_profile','external_site','agent_brain']],
    'booking'=>['surfaces'=>['native_profile','external_site','agent_brain']],
];
$policy=['always'=>['vp3.profile.get'],'booking'=>['vp3.booking.confirm']];
$audit=vp3_profile_webmcp_release_catalog_audit_v196($catalog,$registry,$policy);
t(!empty($audit['ok']),'valid release catalog');
t($audit['tool_count']===2,'tool count');
t(vp3_profile_webmcp_release_surface_v196('agent_brain')['execution_allowed']===false,'Agent Brain planning only');
t(vp3_profile_webmcp_release_surface_v196('native_profile')['execution_allowed']===true,'native execution');
t(vp3_profile_webmcp_release_descriptor_v196()['continuity']['return_single_use']===true,'single use return');
$bad=$catalog;$bad['broken']=['capability'=>'missing','input_schema'=>['type'=>'string'],'annotations'=>[]];
$badAudit=vp3_profile_webmcp_release_catalog_audit_v196($bad,$registry,$policy);
t(empty($badAudit['ok']),'invalid catalog rejected');
t(in_array('unknown_capability:broken',$badAudit['errors'],true),'unknown capability caught');

echo "PROFILE_WEBMCP_RELEASE_V196_PHP=PASS\n";
