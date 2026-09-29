<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/profile-webmcp-capability-resolver-v190.php';
function t(bool $v,string $m): void{if(!$v){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}}

$registry=vp3_profile_webmcp_capability_registry_v190();
foreach(['profile','profile_agent','booking','commerce','campaigns','rewards','social','messaging'] as $cap)t(isset($registry[$cap]),$cap.' registered');
t(in_array('external_site',$registry['campaigns']['surfaces'],true),'Campaigns available to external resolver');
t(!in_array('external_site',$registry['rewards']['surfaces'],true),'Rewards excluded from external resolver');
t(!in_array('external_site',$registry['social']['surfaces'],true),'Social excluded from external resolver');
t(!in_array('external_site',$registry['messaging']['surfaces'],true),'Messaging excluded from external resolver');

$caps=['profile'=>true,'profile_agent'=>true,'booking'=>true,'commerce'=>true,'campaigns'=>true,'rewards'=>true,'social'=>true,'messaging'=>true];
$all=vp3_profile_webmcp_surface_candidate_tools_v190('external_site',$caps,['features'=>['stateful_chat'=>true,'scheduling'=>true,'commerce'=>true,'campaigns'=>true]]);
t(in_array('vp3.profile.get',$all,true),'base profile tool');
t(in_array('vp3.agent.message.send',$all,true),'external chat enabled');
t(in_array('vp3.booking.confirm',$all,true),'external booking enabled');
t(in_array('vp3.commerce.checkout.confirm',$all,true),'external commerce enabled');
t(in_array('vp3.campaign.participation.confirm',$all,true),'external campaigns enabled');
foreach($all as $tool)t(!str_starts_with($tool,'vp3.rewards.')&&!str_starts_with($tool,'vp3.reward.')&&!str_starts_with($tool,'vp3.loyalty.'),'external policy excludes personal rewards/loyalty');

$read=vp3_profile_webmcp_surface_candidate_tools_v190('external_site',$caps,['features'=>[]]);
t(in_array('vp3.profile.get',$read,true),'read-only external profile remains');
t(!in_array('vp3.agent.message.send',$read,true),'chat requires feature flag');
t(!in_array('vp3.booking.confirm',$read,true),'booking requires feature flag');
t(!in_array('vp3.commerce.checkout.confirm',$read,true),'commerce requires feature flag');
t(!in_array('vp3.campaign.participation.confirm',$read,true),'campaign actions require feature flag');

t(vp3_profile_webmcp_surface_candidate_tools_v190('native_profile',$caps,[])===null,'native surface uses complete catalog');
t(vp3_profile_webmcp_surface_candidate_tools_v190('agent_brain',$caps,[])===null,'Agent Brain derives from same complete catalog');
echo "PROFILE_WEBMCP_CAPABILITY_RESOLVER_V190_PHP=PASS\n";
