<?php
declare(strict_types=1);
function vp3_profile_webmcp_transport_id_v130(string $v): string{return trim($v);}
require dirname(__DIR__).'/includes/profile-webmcp-campaigns-v170.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
$c=vp3_profile_webmcp_campaigns_tool_catalog_v170();
t(isset($c['vp3.campaign.participation.get']),'lifecycle lookup tool exists');
t(($c['vp3.campaign.participation.get']['annotations']['readOnlyHint']??false)===true,'lifecycle lookup is read-only');
t(($c['vp3.campaign.participation.get']['annotations']['consequentialHint']??true)===false,'lifecycle lookup is non-consequential');
$schema=$c['vp3.campaign.participation.get']['input_schema']??[];
t(($schema['properties']['participation_reference']['pattern']??'')==='^[a-f0-9]{32}$','opaque reference must be 128-bit hex intent authority');
echo "PROFILE_WEBMCP_CAMPAIGNS_V172_PHP=PASS\n";
