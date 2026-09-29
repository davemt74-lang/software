<?php
declare(strict_types=1);
if(session_status()!==PHP_SESSION_ACTIVE)session_start();
function vp3_profile_webmcp_transport_id_v130(string $v): string{return trim($v);}
require dirname(__DIR__).'/includes/profile-webmcp-rewards-v180.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}
$c=vp3_profile_webmcp_rewards_tool_catalog_v180();
foreach(['vp3.rewards.transfer.contacts.list','vp3.rewards.transfer.prepare','vp3.rewards.transfer.confirm'] as $name)t(isset($c[$name]),$name.' exists');
t(($c['vp3.rewards.transfer.contacts.list']['annotations']['readOnlyHint']??false)===true,'recipient list read-only');
t(($c['vp3.rewards.transfer.prepare']['annotations']['consequentialHint']??true)===false,'transfer prepare non-consequential');
t(($c['vp3.rewards.transfer.confirm']['annotations']['consequentialHint']??false)===true,'transfer confirm consequential');
$schema=$c['vp3.rewards.transfer.prepare']['input_schema']??[];
t(in_array('reward_public_id',$schema['required']??[],true),'reward public ID required');
t(in_array('recipient_public_id',$schema['required']??[],true),'recipient public ID required');
t(!isset($schema['properties']['contact_id']),'raw contact ID must not be accepted');
t(!isset($schema['properties']['recipient_email']),'free-form recipient email must not be accepted');
echo "PROFILE_WEBMCP_REWARDS_V183_PHP=PASS\n";
