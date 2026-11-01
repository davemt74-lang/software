<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/agent-capability-chat-v1.php';
require dirname(__DIR__).'/includes/agent-tool-authorization-v400.php';
function has_permission($key,$user){return empty($GLOBALS['denied']);}
function knowledge_retrieval_v162_owner_session($user,$principal){return ($principal['kind']??'')==='system'||(($principal['kind']??'')==='user_agent'&&($principal['owner_user_id']??0)===$user['id']);}
$checks=0;function ck($ok,$why){if(!$ok)throw new RuntimeException($why);$GLOBALS['checks']++;echo "PASS $why\n";}
$snapshot=['capabilities'=>[['id'=>'voice.profiles.enroll'],['id'=>'capabilities.inspect']]];$u=['id'=>1];$p=['kind'=>'user_agent','owner_user_id'=>1];
$plan=vp3_capability_plan_v1('{"operation":"voice_profile","ids":["voice.profiles.enroll"],"action":"prepare"}',$snapshot);ck($plan['action']==='prepare','model may select only the local setup preparation');
foreach(['{"operation":"voice_profile","ids":["voice.profiles.enroll"],"action":"save"}','{"operation":"voice_profile","ids":["voice.profiles.enroll"],"action":"prepare","participant_id":99}','{"operation":"voice_profile","ids":["voice.profiles.enroll"],"action":"prepare","query":"audio bytes"}'] as $json){try{vp3_capability_plan_v1($json,$snapshot);ck(false,'forged setup rejected');}catch(InvalidArgumentException $e){ck(true,'setup rejects save, identity and raw-content selectors');}}
$r=vp3_voice_enrollment_prepare_v1($u,$p);ck($r['tool_receipt']['status']==='clarification_required'&&!$r['tool_receipt']['changes_applied'],'setup never claims that a voice profile was saved');
$a=vp3_agent_tool_internal_action_v400($r['actions'][0]+['auto'=>true,'url'=>'https://example.invalid'],$u,'Learn my voice');ck($a['type']==='voice_enrollment'&&!$a['auto']&&!isset($a['url']),'authorized card cannot auto-start recording or supply executable targets');
unset($GLOBALS['vp3_voice_enrollment_approved_v1']);ck(vp3_agent_tool_internal_action_v400(['type'=>'voice_enrollment'],$u,'hello')===null,'unapproved model or browser tags cannot issue a setup action');
$r=vp3_voice_enrollment_prepare_v1($u,['kind'=>'user_agent','owner_user_id'=>2]);ck(!$r['actions']&&$r['tool_receipt']['status']==='blocked','foreign Agent cannot prepare owner voice setup');
$r=vp3_voice_enrollment_prepare_v1($u,$p);$GLOBALS['denied']=true;ck(vp3_agent_tool_internal_action_v400($r['actions'][0],$u,'Learn my voice')===null,'revoked Chat permission removes the card at final authorization');
echo "VOICE_ENROLLMENT_TOOL=PASS ($checks cases)\n";
