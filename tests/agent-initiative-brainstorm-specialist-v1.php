<?php
declare(strict_types=1);

define('VP3_BRAINSTORM_PIPELINE_FIXTURE',true);
require __DIR__.'/agent-initiative-brainstorm-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-brainstorm-specialist-v1.php';
$creativeStart=$checks;
// The reusable fixture may have discovered independent creative work while
// proving other executors. Cancel only those synthetic jobs before this section.
foreach(canvas_pipeline_runs('initiative.prepare') as $prior){
    if((json_decode($prior['goal'],true)['tool']??null)==='brainstorm.explore'){
        $pdo->prepare("UPDATE agent_workflow_runs SET status='cancelled' WHERE id=?")->execute([$prior['id']]);
    }
}

$binding=vp3_initiative_policy_v1($owner,7);
$snapshot=vp3_initiative_in_scope_v1($owner,7,static fn()=>vp3_brainstorm_snapshot_v1(db(),$GLOBALS['owner'],$GLOBALS['binding']));
$catalog=vp3_initiative_specialist_catalog_v1([]);
canvas_pipeline_check(array_keys($snapshot['briefs'])===array_values(array_diff(array_keys($snapshot['briefs']),['ob1','brainstorm']))
    &&count($snapshot['briefs'])===count($catalog)-2,
    'Every registered domain specialist has a permitted brief or an explicit context gap');
canvas_pipeline_check(isset($snapshot['briefs']['image-generator'],$snapshot['briefs']['coding'],$snapshot['briefs']['media'],
    $snapshot['briefs']['operator'],$snapshot['briefs']['transcripts']),
    'Creative, media, communication and operational specialists participate in the same input contract');
$ref=vp3_brainstorm_ref_v1($owner,$binding);
$ideaRef=array_key_first(array_filter($snapshot['evidence'],static fn(array $source):bool=>in_array($source['kind'],['task','open_task'],true)));
canvas_pipeline_check($ideaRef!==null,'The real private task adapters supply current source-backed creative context');
$beforeMessages=canvas_pipeline_count('chat_messages');
$beforeRuns=count(canvas_pipeline_runs('initiative.prepare'));
vp3_initiative_in_scope_v1($owner,7,static function()use($owner,$binding):void{
    $lanes=[];
    foreach(VP3_INITIATIVE_OPPORTUNITY_LANES_V1 as $lane){
        if($lane!=='brainstorm')$lanes[$lane]=['checked_at'=>time(),'state'=>'no_opportunity'];
    }
    vp3_initiative_opportunity_save_v1($owner,$binding,$lanes);
    vp3_initiative_schedule_opportunities_v1(db(),$owner,$binding);
});
canvas_pipeline_check(count(canvas_pipeline_runs('initiative.prepare'))===$beforeRuns+1,
    'The creative specialist receives a canonical private assignment in the existing workflow engine');
$runs=canvas_pipeline_runs('initiative.prepare');$run=(int)end($runs)['id'];
$row=agent_workflow_row_v1400($pdo,1,$run);
canvas_pipeline_check(vp3_initiative_runtime_job_specialist_v1($row)==='brainstorm'
    &&json_decode($row['goal'],true)['sources']===[$ref=>$snapshot['revision']],
    'The saved Brainstorming job has its own identity and stores hashes rather than copied briefs');
canvas_pipeline_check(vp3_brainstorm_schedule_v1($pdo,$owner,$binding)==='already_handled',
    'Unchanged context does not enqueue another creative assignment');
$calls=0;
$model=static function(string $prompt,string $protocol)use(&$calls,$ideaRef):string{
    ++$calls;$packet=json_decode($prompt,true,64,JSON_THROW_ON_ERROR);
    canvas_pipeline_check($protocol==='vp3.initiative-plan.v1'&&$packet['role']==='brainstorm'
        &&$packet['budget']['max_jobs']===0&&$packet['budget']['max_opportunities']===3&&!db()->inTransaction(),
        'Brainstorming receives its bounded creative role outside database locks');
    canvas_pipeline_check(isset($packet['specialists']['image-generator'],$packet['specialists']['media'])
        &&!str_contains($prompt,'brainstorm_context'),
        'The worker receives specialist briefs without a recursive supervisor or creative snapshot');
    return json_encode(['jobs'=>[],'opportunities'=>[['tool'=>'work.prepare_private','primary'=>$ideaRef,'refs'=>[$ideaRef],
        'objective'=>'Explore a reusable preparation workflow','rationale'=>'The current saved task can support a practical reusable workflow.']]],JSON_THROW_ON_ERROR);
};
$result=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($run),$model,null,false);
canvas_pipeline_check($result['ok']&&$calls===1,'One leased creative assignment commits one verified report');
$saved=canvas_pipeline_result($run);
canvas_pipeline_check(($saved['initiative_tool_result']['output']['type']??null)==='initiative_brainstorm_report'
    &&$saved['initiative_delivery']==='private'&&canvas_pipeline_count('chat_messages')===$beforeMessages,
    'Brainstorming returns ideas privately without posting every thought in Chat or executing an idea');
$authority=[];
$reports=vp3_initiative_specialist_reports_v1($pdo,$owner,$binding,$snapshot['evidence'],$authority);
$creative=array_values(array_filter($reports,static fn(array $report):bool=>$report['specialist']==='brainstorm'));
canvas_pipeline_check(count($creative)===1&&$creative[0]['ideas'][0]['primary']===$ideaRef
    &&$authority['brainstorm']['run_id']===$run,
    'OB1 receives the same receipt-verified ideas with the exact original source references');
$q=$pdo->prepare('SELECT id,result_json FROM agent_workflow_receipts WHERE run_id=? ORDER BY id DESC LIMIT 1');
$q->execute([$run]);$receipt=$q->fetch(PDO::FETCH_ASSOC);
$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute([$receipt['result_json'].' ',$receipt['id']]);
canvas_pipeline_check(vp3_brainstorm_report_v1($pdo,$owner,$binding)===null,
    'A byte-mismatched receipt cannot become an OB1 input under MySQL text collation');
$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute([$receipt['result_json'],$receipt['id']]);
vp3_initiative_specialist_reports_current_v1($pdo,$owner,$binding,$authority);
// Exercise the actual supervising assessment, not only a report-reader unit.
$planGoal=['binding'=>$binding,'sources'=>array_column($snapshot['evidence'],'revision','ref'),
    'canonical_sources'=>array_column($snapshot['evidence'],'revision','ref')];
vp3_initiative_enqueue_v1($pdo,$owner,'initiative.plan',$planGoal,hash('sha256','creative-to-supervisor-regression'));
$plans=canvas_pipeline_runs('initiative.plan');$supervisor=(int)end($plans)['id'];
$review=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($supervisor),
    static function(string $prompt,string $protocol)use($ideaRef):string{
        $packet=json_decode($prompt,true,64,JSON_THROW_ON_ERROR);
        $ideas=array_values(array_filter($packet['specialist_reports']??[],static fn(array $report):bool=>$report['specialist']==='brainstorm'));
        canvas_pipeline_check($protocol==='vp3.initiative-plan.v1'&&count($ideas)===1
            &&$ideas[0]['ideas'][0]['primary']===$ideaRef,
            'The actual OB1 model receives the private creative result and original source references');
        return json_encode(['jobs'=>[['tool'=>'work.prepare_private','primary'=>$ideaRef,'refs'=>[$ideaRef],
            'focus'=>'Evaluate the proposed reusable workflow','report'=>false]]],JSON_THROW_ON_ERROR);
    },null,false);
canvas_pipeline_check($review['ok']&&in_array(canvas_pipeline_result($supervisor)['effect_state'],
    ['assignments_queued','existing_assignments'],true)&&canvas_pipeline_count('chat_messages')===$beforeMessages,
    'OB1 evaluates returned ideas and admits or reuses exact follow-up work through canonical receipts');
canvas_pipeline_check(vp3_brainstorm_schedule_v1($pdo,$owner,$binding)==='already_handled',
    'A completed creative report does not manufacture fresh context and recursively brainstorm itself');
$forged=$saved['initiative_tool_result'];$forged['output']['input_specialists'][]='invented-specialist';
$denied=false;try{vp3_brainstorm_result_current_v1($pdo,$owner,$binding,json_decode($row['goal'],true),$forged);}
catch(RuntimeException $error){$denied=true;}
canvas_pipeline_check($denied,'A saved report cannot invent contribution from an unverified specialist');
$aggregate=vp3_initiative_in_scope_v1($owner,7,static function()use($ref):array{
    $coverage=[];return vp3_initiative_evidence_v1(db(),$GLOBALS['owner'],new DateTimeImmutable('now'),false,[],$coverage,[$ref],7);
});
$denied=false;try{vp3_initiative_plan_v1(json_encode(['jobs'=>[['tool'=>'canvas.prepare','primary'=>$ref,'refs'=>[$ref],
    'focus'=>'Forward all private briefs into an app','report'=>true]]],JSON_THROW_ON_ERROR),$aggregate,['canvas.prepare']);}
catch(InvalidArgumentException $error){$denied=true;}
canvas_pipeline_check($denied,'The aggregate private brief cannot bypass an individual downstream source or disclosure rule');
$denied=false;
try{
    vp3_brainstorm_prepare_v1(['primary'=>$ref,'refs'=>[$ref],'report'=>false,'focus'=>'Explore useful work'],$aggregate,
        static fn():string=>json_encode(['jobs'=>[['tool'=>'work.prepare_private','primary'=>$ideaRef,'refs'=>[$ideaRef],
            'focus'=>'Attempt direct dispatch','report'=>false]],'opportunities'=>[]],JSON_THROW_ON_ERROR));
}catch(InvalidArgumentException $error){$denied=true;}
canvas_pipeline_check($denied,'A model cannot turn the creative role into an executor by returning assignments');
$wrong=['id'=>2,'is_active'=>1];$denied=false;
try{vp3_brainstorm_snapshot_v1($pdo,$wrong,$binding);}catch(RuntimeException $error){$denied=true;}
canvas_pipeline_check($denied,'Another owner cannot borrow a creative context binding');
// Changing an exact task invalidates the complete creative report and its authority.
$id=(int)substr($ideaRef,strrpos($ideaRef,':')+1);
$pdo->prepare('UPDATE agent_memory_items SET memory_text=? WHERE id=?')->execute(['Changed task objective.',$id]);
$denied=false;try{vp3_initiative_specialist_reports_current_v1($pdo,$owner,$binding,$authority);}
catch(RuntimeException $error){$denied=true;}
canvas_pipeline_check($denied&&vp3_brainstorm_report_v1($pdo,$owner,$binding)===null,
    'Changed source evidence withdraws old ideas from OB1 before another inference or dispatch');
canvas_pipeline_check(vp3_brainstorm_schedule_v1($pdo,$owner,$binding)==='queued',
    'A meaningful source change permits a fresh creative assessment');
$runs=canvas_pipeline_runs('initiative.prepare');$stale=(int)end($runs)['id'];
$staleResult=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($stale),
    static function()use($ideaRef,$id):string{
        db()->prepare('UPDATE agent_memory_items SET memory_text=? WHERE id=?')->execute(['Changed during creative inference.',$id]);
        return json_encode(['jobs'=>[],'opportunities'=>[['tool'=>'work.prepare_private','primary'=>$ideaRef,'refs'=>[$ideaRef],
            'objective'=>'Never commit this stale idea','rationale'=>'This result was prepared from superseded context.']]],JSON_THROW_ON_ERROR);
    },null,false);
$q=$pdo->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');$q->execute([$stale]);
canvas_pipeline_check(!$staleResult['ok']&&!str_contains((string)$q->fetchColumn(),
    'Never commit this stale idea'),'A mid-inference source change cannot persist or dispatch a stale creative idea');
echo 'INITIATIVE_BRAINSTORM_SPECIALIST=PASS ('.($checks-$creativeStart).' cases)'.PHP_EOL;
