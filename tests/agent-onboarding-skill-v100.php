<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-onboarding-skill-v100.php';
function check_v100(bool $ok,string $reason): void{if(!$ok)throw new RuntimeException($reason);}
$state=['user'=>['id'=>12],'setup'=>['agent_named'=>false,'profile_username'=>false],'intelligence'=>['draft'=>[]],'activation'=>['items'=>[]]];
$skill=vp3_agent_onboarding_skill_state_v100($state);
check_v100($skill['task']['phase']==='essential'&&$skill['task']['next']['key']==='agent','Agent name must be first');
check_v100($skill['task']['key']==='vp3.onboarding.12','Stable task identity');
$state['intelligence']['draft']['agent_name']='Stonefellow';
check_v100(vp3_agent_onboarding_skill_state_v100($state)['task']['next']['key']==='agent','Draft must not imply persisted identity');
$state['setup']['agent_named']=true;
check_v100(vp3_agent_onboarding_skill_state_v100($state)['task']['next']['key']==='profile','Unique profile required next');
$state['setup']['profile_username']=true;
check_v100(vp3_agent_onboarding_skill_state_v100($state)['task']['status']==='completed','Optional integrations do not block Cloud essentials');
$state['activation']['items']['homeserver']=['selected'=>true,'label'=>'HomeServer','configured'=>false,'activation_status'=>'pending','priority'=>80,'setup_url'=>'/settings-homeserver.php'];
$skill=vp3_agent_onboarding_skill_state_v100($state);
check_v100($skill['task']['phase']==='optional'&&$skill['task']['next']['key']==='homeserver','Selected HomeServer becomes optional task');
check_v100(!$skill['execution']['auto_provision']&&$skill['execution']['requires_explicit_approval'],'Approval required');
$state['activation']['items']['homeserver']['activation_status']='complete';
$state['activation']['items']['homeserver']['configured']=true;
check_v100(vp3_agent_onboarding_skill_state_v100($state)['task']['status']==='completed','Canonical verification required');
$state['activation']['items']['homeserver']['activation_status']='deferred';
$state['activation']['items']['homeserver']['configured']=false;
check_v100(vp3_agent_onboarding_skill_state_v100($state)['task']['status']==='waiting','Deferred optional does not reopen essentials');

$state['workspace']=[
 'homeserver'=>['permitted'=>true,'available'=>true,'configured'=>true,'live'=>false,'status'=>'Paired, not connected','observed_state'=>'paired'],
 'voice_profile'=>['permitted'=>true,'available'=>true,'configured'=>false,'status'=>'Voice enrollment is optional']
];
$state['voice']=['clone_created'=>true,'clone_verified'=>false];
$readiness=vp3_agent_onboarding_skill_state_v100($state)['readiness'];
check_v100($readiness['homeserver']['configured']===true&&$readiness['homeserver']['live']===false,'Paired is distinct from connected');
check_v100($readiness['voice_profile']['configured']===false,'Voice eligibility is not enrollment');
check_v100($readiness['voice_clone']['verified']===false,'Created voice clone is not verified');
check_v100($readiness['visual_profile']['available']===false,'Do not invent visual identity enrollment');
echo "Agent onboarding skill: identity, verification, consent, resume PASS\n";
