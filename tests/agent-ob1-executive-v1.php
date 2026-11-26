<?php
declare(strict_types=1);

define('VP3_BRAINSTORM_PIPELINE_FIXTURE',true);
require __DIR__.'/agent-initiative-brainstorm-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-ob1-executive-v1.php';
require_once dirname(__DIR__).'/includes/agent-top-of-mind-specialists-v1.php';
$executiveStart=$checks;
$binding=vp3_initiative_policy_v1($owner,7);
// Finish isolation from the reusable fixture, without changing its receipts.
$pdo->exec("UPDATE agent_workflow_runs SET status='cancelled' WHERE owner_user_id=1
    AND capability_key='initiative.plan' AND status IN ('approved','queued')");
$evidence=brainstorm_pipeline_evidence();
$ref=array_key_first(array_filter($evidence,static fn(array $row):bool=>$row['kind']==='task'));
canvas_pipeline_check($ref!==null,'Executive review starts from a real authorized task source');
$enqueue=static function(string $key)use($pdo,$owner,$binding,$ref):int{
    $current=brainstorm_pipeline_evidence();$payload=brainstorm_pipeline_payload($current);
    $revision=vp3_ob1_executive_revision_v1($pdo,$owner,$binding);
    if($revision!=='')$payload['executive_revision']=$revision;
    vp3_initiative_enqueue_v1($pdo,$owner,'initiative.plan',$payload,
        hash('sha256','executive-regression-'.$key));
    $rows=canvas_pipeline_runs('initiative.plan');return (int)end($rows)['id'];
};
$plan=$enqueue('first');
canvas_pipeline_check(vp3_ob1_assessment_pending_v1($pdo,$owner,$binding),
    'An automatic general assessment is visible on the existing canonical queue');
$beforePlans=count(canvas_pipeline_runs('initiative.plan'));
$pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
vp3_initiative_schedule_v1($pdo,$owner,7,false);
canvas_pipeline_check(count(canvas_pipeline_runs('initiative.plan'))===$beforePlans,
    'Source reviews cannot pile a competing ready assessment onto the same owner Agent and Chat');
$revision=vp3_ob1_executive_revision_v1($pdo,$owner,$binding);
$modelCalls=0;
$assign=static function()use(&$modelCalls,$ref):string{
    ++$modelCalls;return json_encode(['jobs'=>[['tool'=>'work.prepare_private','primary'=>$ref,'refs'=>[$ref],
        'focus'=>'Prepare a focused next step for the current objective','report'=>false]]],JSON_THROW_ON_ERROR);
};
$beforeChildren=count(canvas_pipeline_runs('initiative.prepare'));
$planClaim=canvas_pipeline_claim($plan);
canvas_pipeline_check(vp3_ob1_assessment_pending_v1($pdo,$owner,$binding,true),
    'An exact active general assessment blocks additional automatic scheduling');
canvas_pipeline_check(vp3_initiative_execute_v1($pdo,$owner,$planClaim,$assign,null,false)['ok'],
    'The canonical supervisor commits a real assignment cycle');
$children=canvas_pipeline_runs('initiative.prepare');$child=end($children);$childId=(int)$child['id'];
canvas_pipeline_check(count($children)===$beforeChildren+1&&json_decode($child['goal'],true)['ob1_assessment_run_id']===$plan,
    'A new preparation keeps the exact server-owned supervising assessment');
$cycle=vp3_ob1_executive_cycle_v1($pdo,$owner,$binding);
canvas_pipeline_check($cycle['assessment_run_id']===$plan&&$cycle['assigned']===1&&$cycle['counts']['queued']===1
    &&$cycle['state']==='in_progress','The executive agenda reads the canonical queued child without inventing completion');
$planResult=canvas_pipeline_result($plan);
canvas_pipeline_check($planResult['ob1_assignment_run_ids']===[$childId]
    &&!isset(agent_workflow_public_json_v1400(json_encode($planResult,JSON_THROW_ON_ERROR))['ob1_assignment_run_ids']),
    'The private supervising receipt records exact child admissions while ordinary workflow projections omit them');
canvas_pipeline_check(!isset(agent_workflow_public_json_v1400('{"ob1_assignment_run_ids":[0,"1",1]}',true)['ob1_assignment_run_ids']),
    'Malformed or coerced private child identities cannot become canonical admissions');
canvas_pipeline_check(vp3_ob1_executive_revision_v1($pdo,$owner,$binding)===$revision,
    'Assignment creation does not wake its own supervising loop');
$claim=canvas_pipeline_claim($childId);
canvas_pipeline_check(vp3_ob1_executive_cycle_v1($pdo,$owner,$binding)['counts']['working']===1,
    'Only the exact current action and a live lease count as working');
canvas_pipeline_check(vp3_initiative_execute_v1($pdo,$owner,$claim,static function()use(&$modelCalls):string{
    ++$modelCalls;return '{"draft":"I prepared a focused next step for the current task."}';
},null,false)['ok'],'The real private executor returns one completed preparation receipt');
$ready=vp3_ob1_executive_cycle_v1($pdo,$owner,$binding);
canvas_pipeline_check($ready['state']==='prepared'&&$ready['counts']['prepared']===1&&$ready['counts']['failed']===0,
    'A completed preparation is distinct from approval or success of the owner objective');
$preparedRevision=vp3_ob1_executive_revision_v1($pdo,$owner,$binding);
canvas_pipeline_check($preparedRevision===$revision,'A newly completed outcome respects its own 30-second review floor');
$pdo->prepare('UPDATE agent_workflow_actions SET completed_at=? WHERE run_id=?')->execute([gmdate('Y-m-d H:i:s',time()-31),$childId]);
$preparedRevision=vp3_ob1_executive_revision_v1($pdo,$owner,$binding);
canvas_pipeline_check($preparedRevision!==$revision,'A verified returned outcome changes the next executive assessment identity');
$q=$pdo->prepare('SELECT id,result_json FROM agent_workflow_receipts WHERE run_id=? ORDER BY id DESC LIMIT 1');
$q->execute([$childId]);$receipt=$q->fetch(PDO::FETCH_ASSOC);
$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute([$receipt['result_json'].' ',$receipt['id']]);
$unverified=vp3_ob1_executive_cycle_v1($pdo,$owner,$binding);
canvas_pipeline_check($unverified['state']==='needs_review'&&$unverified['counts']['prepared']===0
    &&$unverified['counts']['unverified']===1,'A byte-mismatched receipt cannot become executive completion under native text collation');
$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute([$receipt['result_json'],$receipt['id']]);
$emptyResult='{"effect_state":"private_draft_ready"}';
$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute([$emptyResult,$receipt['id']]);
$pdo->prepare('UPDATE agent_workflow_actions SET result_json=? WHERE run_id=?')->execute([$emptyResult,$childId]);
canvas_pipeline_check(vp3_ob1_executive_cycle_v1($pdo,$owner,$binding)['counts']['unverified']===1,
    'Even byte-matched completion rows need a canonical private preparation payload');
$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute([$receipt['result_json'],$receipt['id']]);
$pdo->prepare('UPDATE agent_workflow_actions SET result_json=? WHERE run_id=?')->execute([$receipt['result_json'],$childId]);
$beforeMessages=canvas_pipeline_count('chat_messages');$beforeRuns=canvas_pipeline_count('agent_workflow_runs');
if(!$mysql)$pdo->exec('PRAGMA query_only=ON');
try{
    $read=vp3_ob1_executive_cycle_v1($pdo,$owner,$binding);
    $health=vp3_initiative_worker_health_v1($pdo,$owner,7,11);
}finally{if(!$mysql)$pdo->exec('PRAGMA query_only=OFF');}
canvas_pipeline_check($read===$ready&&($health['executive_cycle']['assessment_run_id']??null)===$plan
    &&canvas_pipeline_count('chat_messages')===$beforeMessages&&canvas_pipeline_count('agent_workflow_runs')===$beforeRuns&&$modelCalls===2,
    'Executive and Top of Mind reads neither run a model nor write, deliver or advance work');
$foreign=$binding;$foreign['conversation_id']=13;
canvas_pipeline_check(vp3_ob1_executive_cycle_v1($pdo,$owner,$foreign)===null
    &&vp3_ob1_executive_cycle_v1($pdo,['id'=>2,'is_active'=>1],$binding)===null,
    'Another Chat or owner cannot borrow an assignment cycle');
$changedGoal=json_decode($child['goal'],true);$changedGoal['ob1_assessment_run_id']=$plan-1;
$pdo->prepare('UPDATE agent_workflow_runs SET goal=? WHERE id=?')->execute([json_encode($changedGoal,JSON_THROW_ON_ERROR),$childId]);
canvas_pipeline_check(vp3_ob1_executive_cycle_v1($pdo,$owner,$binding)['counts']['unverified']===1,
    'A missing or unrelated parent cannot supply a completion count');
$pdo->prepare('UPDATE agent_workflow_runs SET goal=? WHERE id=?')->execute([$child['goal'],$childId]);
// A changed objective permits different source-bound work; fail it through the real executor.
$pdo->prepare('UPDATE agent_memory_items SET memory_text=? WHERE id=?')
    ->execute(['A changed objective needing an outcome review.',(int)substr($ref,strpos($ref,':')+1)]);
$failedPlan=$enqueue('second');
canvas_pipeline_check(vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($failedPlan),$assign,null,false)['ok'],
    'A fresh source revision can produce its own subsequent assignment');
$children=canvas_pipeline_runs('initiative.prepare');$failedChild=(int)end($children)['id'];
vp3_initiative_in_scope_v1($owner,7,static function()use($owner,$binding):void{
    agent_brain_v122_upsert_system_memory($owner,'initiative_sweep','last-review','Executive checkpoint.',
        ['conversation_id'=>$binding['conversation_id'],'revision'=>$binding['revision'],'checked_at'=>gmdate(DATE_ATOM)]);
});
$failed=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($failedChild),
    static fn():never=>throw new RuntimeException('Synthetic unusable preparation.',500),null,false);
$blocked=vp3_ob1_executive_cycle_v1($pdo,$owner,$binding);
canvas_pipeline_check(!$failed['ok']&&$blocked['assessment_run_id']===$failedPlan&&$blocked['state']==='needs_review'
    &&$blocked['counts']['failed']===1,'A verified failed preparation remains a blocker for OB1 outcome review');
$checkpoint=vp3_initiative_in_scope_v1($owner,7,static fn()=>agent_brain_v122_memory($GLOBALS['owner'],'initiative_sweep','last-review'));
$pdo->prepare('UPDATE agent_workflow_actions SET completed_at=? WHERE run_id=?')->execute([gmdate('Y-m-d H:i:s',time()-31),$failedChild]);
canvas_pipeline_check(!empty($checkpoint['metadata']['specialist_source_changed'])
    &&vp3_ob1_executive_revision_v1($pdo,$owner,$binding)!==$preparedRevision,
    'A child failure wakes the existing bounded review checkpoint with a different terminal outcome revision');
$reviewPlan=$enqueue('review');
$reviewed=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($reviewPlan),
    static function(string $prompt)use($failedPlan):string{
        $input=json_decode($prompt,true,64,JSON_THROW_ON_ERROR);
        canvas_pipeline_check($input['executive_review']['assessment_run_id']===$failedPlan
            &&$input['executive_review']['counts']['failed']===1
            &&!str_contains(json_encode($input['executive_review']),'Synthetic unusable'),
            'The actual OB1 model reviews the bounded canonical blocker without copied private error text');
        return '{"jobs":[]}';
    },null,false);
canvas_pipeline_check($reviewed['ok']&&vp3_ob1_executive_cycle_v1($pdo,$owner,$binding)['assessment_run_id']===$failedPlan,
    'An idle follow-up assessment does not erase unfinished older specialist work');
$retry=$enqueue('delayed');
$pdo->prepare('UPDATE agent_workflow_runs SET next_attempt_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s',time()+60),$retry]);
canvas_pipeline_check(!vp3_ob1_assessment_pending_v1($pdo,$owner,$binding),
    'A delayed retry retains backoff without preventing a fresh ready assessment');
$pdo->prepare('UPDATE agent_workflow_runs SET next_attempt_at=NULL WHERE id=?')->execute([$retry]);
canvas_pipeline_check(vp3_ob1_assessment_pending_v1($pdo,$owner,$binding),'A due retry participates in the shared executive turn');
$pdo->prepare("UPDATE agent_workflow_runs SET status='cancelled' WHERE id=?")->execute([$retry]);
// A real worker sweep must notice returned work while the Chat browser is closed.
require_once dirname(__DIR__).'/includes/agent-cognitive-loop-v310.php';
vp3_initiative_in_scope_v1($owner,7,static function()use($owner,$binding):void{
    agent_brain_v122_upsert_system_memory($owner,'initiative_sweep','last-review','Outcome review is due.',
        ['conversation_id'=>$binding['conversation_id'],'revision'=>$binding['revision'],
            'specialist_source_changed'=>1,'checked_at'=>gmdate(DATE_ATOM,time()-31)]);
});
$pdo->prepare("UPDATE agent_memory_items SET last_seen_at=? WHERE user_id=1 AND user_agent_id=7
    AND memory_type='initiative_sweep' AND subject='last-review'")->execute([gmdate('Y-m-d H:i:s',time()-31)]);
$pdo->exec("UPDATE agent_activity_state SET last_heartbeat_at='2000-01-01 00:00:00' WHERE user_id=1");
$beforeSweep=count(canvas_pipeline_runs('initiative.plan'));
vp3_initiative_sweep_v1($pdo,25);
$swept=canvas_pipeline_runs('initiative.plan');$sweptGoal=json_decode(end($swept)['goal'],true);
canvas_pipeline_check(count($swept)===$beforeSweep+1&&($sweptGoal['executive_revision']??null)===vp3_ob1_executive_revision_v1($pdo,$owner,$binding),
    'The server sweep queues an outcome review after the 30-second floor without a live browser');
vp3_initiative_sweep_v1($pdo,25);
canvas_pipeline_check(count(canvas_pipeline_runs('initiative.plan'))===count($swept),
    'Repeated server sweeps neither duplicate the ready assessment nor invoke inference');
echo 'OB1_EXECUTIVE=PASS ('.($checks-$executiveStart).' cases, '.($mysql?'mysql':'sqlite').')'.PHP_EOL;
