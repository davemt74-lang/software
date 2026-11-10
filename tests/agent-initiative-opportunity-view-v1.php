<?php
declare(strict_types=1);

require __DIR__.'/agent-initiative-brainstorm-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-opportunity-view-v1.php';
$viewStart=$checks;
function opportunity_view_check_empty(callable $read,string $label): void
{
    try{$result=$read();canvas_pipeline_check($result===[],$label);}
    catch(RuntimeException $error){canvas_pipeline_check(in_array($error->getCode(),[403,409],true),$label);}
}
function opportunity_view_snapshot(): string
{
    $rows=[];
    foreach(['agent_workflow_runs','agent_workflow_actions','agent_workflow_receipts','agent_workflow_events','agent_memory_items','chat_messages'] as $table){
        $rows[$table]=db()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }
    return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
}

vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>11],'on',null);
$pdo->prepare("INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,subject,memory_text,memory_hash,memory_scope_version,
    confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json)
    VALUES(30001,1,7,'task','Separate private context','An independently saved detail available to the assessment','brainstorm-view-context',410,.95,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,1,?)")
    ->execute([json_encode(['task_status'=>'open','due_at'=>gmdate('Y-m-d H:i:s',time()-60)],JSON_THROW_ON_ERROR)]);
$viewEvidence=brainstorm_pipeline_evidence();
canvas_pipeline_check(isset($viewEvidence['task:20001'],$viewEvidence['task:30001']),'the assessment really receives both its declared and undeclared context sources');
$viewPayload=brainstorm_pipeline_payload($viewEvidence);$viewPayload['attention_revision']=bin2hex(random_bytes(16));
$viewRun=brainstorm_pipeline_enqueue($viewPayload);
$viewObjective='Consider a focused review board';
$viewRationale='The recurring preparation task could benefit from a reusable checklist after discussing its purpose.';
$GLOBALS['pipelineModel']=static function(string $prompt,string $protocol)use($viewObjective,$viewRationale):string{
    $packet=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
    canvas_pipeline_check($protocol==='vp3.initiative-plan.v1'&&in_array('task:30001',array_column($packet['evidence'],'ref'),true),
        'the actual model has whole-snapshot context beyond the one declared idea source');
    return json_encode(['jobs'=>[],'opportunities'=>[['tool'=>'canvas.prepare','primary'=>'task:20001','refs'=>['task:20001'],
        'objective'=>$viewObjective,'rationale'=>$viewRationale]]],JSON_THROW_ON_ERROR);
};
canvas_pipeline_check(vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($viewRun))['ok'],'the real model-generated opportunity has a completed canonical assessment receipt');
$inferences=count($GLOBALS['pipelineInference']);$beforeRead=opportunity_view_snapshot();
$view=vp3_initiative_opportunities_v1($pdo,$owner,7);
canvas_pipeline_check(count($view)===1&&$view[0]['run_id']===$viewRun&&$view[0]['objective']===$viewObjective
    &&$view[0]['rationale']===$viewRationale&&$view[0]['state']==='considered'&&$view[0]['conversation_id']===11,
    'the read model projects the saved idea and owner-facing why from the exact completed assessment');
canvas_pipeline_check(count($GLOBALS['pipelineInference'])===$inferences&&opportunity_view_snapshot()===$beforeRead,
    'reading Brainstorm opportunities starts no inference and writes no workflow, memory or Chat data');
canvas_pipeline_check(array_keys($view[0])===['id','run_id','objective','rationale','state','conversation_id'],
    'the UI receives bounded explanatory text without private source maps or execution grants');
require_once dirname(__DIR__).'/includes/agent-top-of-mind-v1.php';
$assessed=array_values(array_filter(vp3_top_of_mind_v1($pdo,$owner,7,11)['items'],
    static fn(array $item):bool=>$item['run_id']===$viewRun&&$item['kind']==='assessment'));
canvas_pipeline_check(count($assessed)===1&&$assessed[0]['state']==='assessed',
    'the typed private Brainstorm receipt remains a verified completed assessment in Top of Mind');

$pdo->exec("UPDATE agent_memory_items SET memory_text='A materially changed hidden context detail' WHERE id=30001");
opportunity_view_check_empty(static fn()=>vp3_initiative_opportunities_v1(db(),$GLOBALS['owner'],7),
    'changing a source outside the model-declared refs withdraws the entire old rationale');
$pdo->exec("UPDATE agent_memory_items SET memory_text='An independently saved detail available to the assessment' WHERE id=30001");
canvas_pipeline_check(count(vp3_initiative_opportunities_v1($pdo,$owner,7))===1,'restoring the exact authorized snapshot makes its saved idea readable again');
$pdo->exec('UPDATE agent_memory_items SET user_agent_id=8 WHERE id=30001');
opportunity_view_check_empty(static fn()=>vp3_initiative_opportunities_v1(db(),$GLOBALS['owner'],7),
    'revoking an undeclared source from this Agent also withdraws the assessment text');
$pdo->exec('UPDATE agent_memory_items SET user_agent_id=7 WHERE id=30001');
opportunity_view_check_empty(static fn()=>vp3_initiative_opportunities_v1(db(),['id'=>2,'is_active'=>1],7),'another owner cannot read the saved opportunities');
opportunity_view_check_empty(static fn()=>vp3_initiative_opportunities_v1(db(),$GLOBALS['owner'],8),'another selected Agent cannot inherit this assessment');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['participant_actor_id'=>99];
try{opportunity_view_check_empty(static fn()=>vp3_initiative_opportunities_v1(db(),$GLOBALS['owner'],7),'a participant context cannot access private Brainstorm explanations');}
finally{unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);}
$GLOBALS['imageDenied']=true;
try{opportunity_view_check_empty(static fn()=>vp3_initiative_opportunities_v1(db(),$GLOBALS['owner'],7),'current account access is rechecked before private text is returned');}
finally{$GLOBALS['imageDenied']=false;}

$actionQuery=$pdo->prepare('SELECT id,result_json FROM agent_workflow_actions WHERE run_id=?');$actionQuery->execute([$viewRun]);$viewAction=$actionQuery->fetch(PDO::FETCH_ASSOC);
$receiptQuery=$pdo->prepare('SELECT id,result_json FROM agent_workflow_receipts WHERE run_id=?');$receiptQuery->execute([$viewRun]);$viewReceipt=$receiptQuery->fetch(PDO::FETCH_ASSOC);
$updateAction=$pdo->prepare('UPDATE agent_workflow_actions SET result_json=? WHERE id=?');
$updateReceipt=$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?');
$updateAction->execute(['{}',$viewAction['id']]);
opportunity_view_check_empty(static fn()=>vp3_initiative_opportunities_v1(db(),$GLOBALS['owner'],7),'an action and receipt mismatch cannot release private opportunity text');
$updateAction->execute([$viewAction['result_json'],$viewAction['id']]);
$pdo->prepare("UPDATE agent_workflow_actions SET capability_key='initiative.prepare' WHERE id=?")->execute([$viewAction['id']]);
opportunity_view_check_empty(static fn()=>vp3_initiative_opportunities_v1(db(),$GLOBALS['owner'],7),'a completed action of another capability is not a Brainstorm assessment receipt');
$pdo->prepare("UPDATE agent_workflow_actions SET capability_key='initiative.plan' WHERE id=?")->execute([$viewAction['id']]);
$invalid=json_decode($viewAction['result_json'],true,32,JSON_THROW_ON_ERROR);
$invalid['initiative_tool_result']['output']['opportunities'][0]['id']=str_repeat('0',64);
$malformed=json_encode($invalid,JSON_THROW_ON_ERROR);
$updateAction->execute([$malformed,$viewAction['id']]);$updateReceipt->execute([$malformed,$viewReceipt['id']]);
opportunity_view_check_empty(static fn()=>vp3_initiative_opportunities_v1(db(),$GLOBALS['owner'],7),'matching but malformed typed receipt data cannot become a UI opportunity');
$updateAction->execute([$viewAction['result_json'],$viewAction['id']]);$updateReceipt->execute([$viewReceipt['result_json'],$viewReceipt['id']]);
$pdo->prepare("UPDATE agent_workflow_receipts SET status='failed' WHERE id=?")->execute([$viewReceipt['id']]);
opportunity_view_check_empty(static fn()=>vp3_initiative_opportunities_v1(db(),$GLOBALS['owner'],7),'a noncompleted canonical receipt supplies no completed assessment');
$pdo->prepare("UPDATE agent_workflow_receipts SET status='completed' WHERE id=?")->execute([$viewReceipt['id']]);
canvas_pipeline_check(count(vp3_initiative_opportunities_v1($pdo,$owner,7))===1,'the exact restored canonical action and receipt are accepted');

$newPayload=brainstorm_pipeline_payload($viewEvidence);$newPayload['attention_revision']=bin2hex(random_bytes(16));
$newViewRun=brainstorm_pipeline_enqueue($newPayload);
$GLOBALS['pipelineModel']=static fn()=>'{"jobs":[]}';
canvas_pipeline_check(vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($newViewRun))['ok'],'a later legacy-compatible quiet assessment still completes normally');
opportunity_view_check_empty(static fn()=>vp3_initiative_opportunities_v1(db(),$GLOBALS['owner'],7),
    'the latest empty assessment supersedes an older still-readable idea');
$pdo->exec("UPDATE agent_workflow_runs SET status='failed' WHERE id=".$newViewRun);
canvas_pipeline_check(count(vp3_initiative_opportunities_v1($pdo,$owner,7))===1,'the latest completed assessment is selected instead of an unrelated failed run');
$inferences=count($GLOBALS['pipelineInference']);
vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>11],'off',null);
opportunity_view_check_empty(static fn()=>vp3_initiative_opportunities_v1(db(),$GLOBALS['owner'],7),'a paused or replaced policy cannot expose old assessment opportunities');
canvas_pipeline_check(count($GLOBALS['pipelineInference'])===$inferences,'denied and superseded reads never invoke the model');
echo 'INITIATIVE_OPPORTUNITY_VIEW=PASS ('.($checks-$viewStart).' assertions, '.($mysql?'mysql':'sqlite').')'.PHP_EOL;
