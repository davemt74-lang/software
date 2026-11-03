<?php
declare(strict_types=1);
require __DIR__.'/fixtures/profile-handoff-pa3.php';

/** Independent canonical binding/history checks, using the real claimant and executor. */
$checks=0;
function binding_check_pa3(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);
    $GLOBALS['checks']++;echo 'PASS '.$label."\n";
}
function binding_answers_pa3(): int{return pa3_count('profile_agent_messages',"sender_type='agent'");}
function binding_completed_pa3(): int{return pa3_count('agent_workflow_receipts',"status='completed'");}

$mutations=[
    'run Agent'=>"UPDATE agent_workflow_runs SET agent_id=8",
    'run source'=>"UPDATE agent_workflow_runs SET source_key='profile-turn:999'",
    'run source hash'=>"UPDATE agent_workflow_runs SET source_hash='".str_repeat('f',40)."'",
    'run dedupe'=>"UPDATE agent_workflow_runs SET dedupe_key='".str_repeat('e',64)."'",
    'run workflow'=>"UPDATE agent_workflow_runs SET workflow_type='image_generation'",
    'run origin'=>"UPDATE agent_workflow_runs SET origin='chat'",
    'run execution target'=>"UPDATE agent_workflow_runs SET execution_target='homeserver'",
    'run rejected approval'=>"UPDATE agent_workflow_runs SET requires_approval=1,approval_status='rejected'",
    'action identity'=>"UPDATE agent_workflow_actions SET action_key='profile-turn:999'",
    'action sequence'=>"UPDATE agent_workflow_actions SET sequence_no=2",
    'action type'=>"UPDATE agent_workflow_actions SET action_type='read'",
    'action target'=>"UPDATE agent_workflow_actions SET execution_target='homeserver'",
    'action approval'=>"UPDATE agent_workflow_actions SET requires_approval=1",
];
foreach($mutations as $label=>$sql){
    pa3_reset();$admission=pa3_admit();$id=(int)$admission['handoff']['turn_id'];$pdo->exec($sql);
    vp3_profile_handoff_dispatch_pa3($pdo,$id);
    binding_check_pa3(count($GLOBALS['pa3ModelCalls'])===0&&binding_answers_pa3()===0&&binding_completed_pa3()===0,$label.' reassignment is rejected before inference and publication');
    vp3_profile_handoff_dispatch_pa3($pdo,$id);
    binding_check_pa3(count($GLOBALS['pa3ModelCalls'])===0,$label.' corruption cannot become an automatic paid retry');
}
foreach(['run Agent','run source','run rejected approval','action identity','action approval'] as $label){
    pa3_reset();$admission=pa3_admit();$id=(int)$admission['handoff']['turn_id'];
    $GLOBALS['pa3ModelHook']=static function()use($pdo,$mutations,$label):void{$pdo->exec($mutations[$label]);};
    vp3_profile_handoff_dispatch_pa3($pdo,$id);
    binding_check_pa3(count($GLOBALS['pa3ModelCalls'])===1&&binding_answers_pa3()===0&&binding_completed_pa3()===0,'late '.$label.' reassignment cannot publish generated content');
}

foreach(['lease_token','worker_id'] as $field){
    pa3_reset();$admission=pa3_admit();$turn=vp3_profile_handoff_row_pa3($pdo,(int)$admission['handoff']['turn_id']);$owner=pa3_owner();
    $claim=agent_job_claim_run_v1900($pdo,$owner,(int)$turn['run_id'],'cloud',agent_worker_cloud_worker_id_v1910(1),300);
    if(!is_array($claim))throw new RuntimeException('Fixture did not receive canonical claim.');
    $claim[$field]=$field==='lease_token'?str_repeat('f',64):'cloud:foreign-worker';
    vp3_profile_handoff_execute_claim_pa3($pdo,$owner,$claim);
    binding_check_pa3(count($GLOBALS['pa3ModelCalls'])===0&&binding_answers_pa3()===0,'forged '.$field.' cannot invoke inference or publish');
}

pa3_reset();$first=pa3_admit('Question one about public hours');$cid=(int)$first['conversation_id'];pa3_age();$second=pa3_admit('FUTURE_VISITOR_QUESTION_SHOULD_NOT_ENTER_FIRST_JOB',$cid);
vp3_profile_handoff_dispatch_pa3($pdo,(int)$first['handoff']['turn_id']);
$firstHistory=json_encode($GLOBALS['pa3ModelCalls'][0]['history'],JSON_THROW_ON_ERROR);
binding_check_pa3(!str_contains($firstHistory,'FUTURE_VISITOR_QUESTION_SHOULD_NOT_ENTER_FIRST_JOB'),'accepted history stops at the exact saved target message');
binding_check_pa3(str_contains($firstHistory,'Question one about public hours'),'bounded history retains its own accepted visitor message');
vp3_profile_handoff_dispatch_pa3($pdo,(int)$second['handoff']['turn_id']);
binding_check_pa3(binding_answers_pa3()===2&&binding_completed_pa3()===2,'serial pending turns each publish once after their predecessor completes');

pa3_reset();$first=pa3_admit('What are the public hours?');$cid=(int)$first['conversation_id'];vp3_profile_handoff_dispatch_pa3($pdo,(int)$first['handoff']['turn_id']);pa3_age();
$second=pa3_admit('Thanks for that explanation',$cid);vp3_profile_handoff_dispatch_pa3($pdo,(int)$second['handoff']['turn_id']);
$meta=json_decode((string)$pdo->query("SELECT context_json FROM profile_agent_messages WHERE sender_type='agent' ORDER BY id DESC LIMIT 1")->fetchColumn(),true);
binding_check_pa3(in_array('knowledge:101',array_column($meta['source_dependencies']??[],'source'),true),'a later answer preserves the original approved source dependency');
$pdo->exec("UPDATE knowledge_items SET visibility='private',is_published=0 WHERE id=101");pa3_age();$third=pa3_admit('Please continue',$cid);vp3_profile_handoff_dispatch_pa3($pdo,(int)$third['handoff']['turn_id']);
$last=$GLOBALS['pa3ModelCalls'][array_key_last($GLOBALS['pa3ModelCalls'])];
binding_check_pa3(!str_contains(json_encode($last['history'],JSON_THROW_ON_ERROR),'PA3 approved public answer'),'revoked original and derived Agent answers are removed from subsequent model history');
binding_check_pa3(!str_contains(json_encode($GLOBALS['pa3ModelCalls'],JSON_THROW_ON_ERROR),'PRIVATE_CHAT_SECRET'),'canonical worker never substitutes private main Chat history');

pa3_reset();$admission=pa3_admit();$writes=[];
$pdo->afterSql=static function(string $sql)use(&$writes):void{if(preg_match('/^\s*(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP)\b/i',$sql))$writes[]=$sql;};
$readContext=vp3_profile_agent_public_context_v110($pdo,profile_by_username($pdo,'fixture-owner'),current_user(),true);
vp3_profile_agent_public_poll_service_v110($pdo,$readContext,(int)$admission['conversation_id']);$pdo->afterSql=null;
binding_check_pa3($writes===[]&&count($GLOBALS['pa3ModelCalls'])===0,'public poll context and projection perform no CRM writes, job transition or inference');

require_once dirname(__DIR__).'/includes/agent-work-control-v173.php';
pa3_reset();agent_work_control_ensure_schema_v173($pdo);$admission=pa3_admit();$id=(int)$admission['handoff']['turn_id'];$turn=vp3_profile_handoff_row_pa3($pdo,$id);$owner=pa3_owner();
$paused=agent_work_control_pause_v173($pdo,$owner,(int)$turn['run_id']);
binding_check_pa3(($paused['status']??null)==='paused','actual canonical owner pause saves its marked paused state');
vp3_profile_handoff_dispatch_pa3($pdo,$id);$job=agent_workflow_row_v1400($pdo,1,(int)$turn['run_id']);
binding_check_pa3(count($GLOBALS['pa3ModelCalls'])===0&&$job['status']==='paused'&&!empty($job['paused_at']),'visitor dispatch cannot silently resume an owner-paused job');
agent_work_control_resume_v173($pdo,$owner,(int)$turn['run_id']);vp3_profile_handoff_dispatch_pa3($pdo,$id);
binding_check_pa3(count($GLOBALS['pa3ModelCalls'])===1&&binding_answers_pa3()===1,'explicit canonical resume permits exactly one public response');

pa3_reset();agent_work_control_ensure_schema_v173($pdo);$admission=pa3_admit();$id=(int)$admission['handoff']['turn_id'];$turn=vp3_profile_handoff_row_pa3($pdo,$id);
$GLOBALS['pa3ModelHook']=static function()use($pdo,$turn):void{agent_work_control_pause_v173($pdo,pa3_owner(),(int)$turn['run_id']);throw new RuntimeException('Fixture transient provider failure');};
vp3_profile_handoff_dispatch_pa3($pdo,$id);$job=agent_workflow_row_v1400($pdo,1,(int)$turn['run_id']);$held=vp3_profile_handoff_row_pa3($pdo,$id);
binding_check_pa3($job['status']==='paused'&&$held['state']==='agent_ready'&&binding_answers_pa3()===0,'in-flight pause plus transient failure preserves a held retry instead of blocking the turn');
$GLOBALS['pa3ModelHook']=null;vp3_profile_handoff_dispatch_pa3($pdo,$id);
binding_check_pa3(count($GLOBALS['pa3ModelCalls'])===1,'held retry cannot invoke the provider until owner resumes');
agent_work_control_resume_v173($pdo,pa3_owner(),(int)$turn['run_id']);
// Make only the canonical retry clock due; the explicit resume and saved lease remain real.
$pdo->exec("UPDATE agent_workflow_runs SET next_attempt_at='2001-01-01 00:00:00'");$pdo->exec("UPDATE agent_workflow_actions SET available_at='2001-01-01 00:00:00'");
vp3_profile_handoff_dispatch_pa3($pdo,$id);
binding_check_pa3(count($GLOBALS['pa3ModelCalls'])===2&&binding_answers_pa3()===1&&binding_completed_pa3()===1,'resuming a held transient retry produces one canonical answer and completion receipt');

echo 'PROFILE_HANDOFF_BINDING_PA3=PASS '.$checks.' checks ('.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
