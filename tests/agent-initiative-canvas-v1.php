<?php
declare(strict_types=1);

// The canonical coding admission, leases, receipts, model pin and Knowledge storage are real.
// Only the Initiative policy/source reader and provider transport are bounded adapters.
function vp3_initiative_access_v1(PDO $pdo,array $user,array $binding): array
{
    if (($GLOBALS['canvasPolicies'][(int)($binding['agent_id'] ?? 0)] ?? null) !== $binding
        || empty($binding['enabled']) || $binding['owner_user_id'] !== (int)$user['id']) {
        throw new RuntimeException('Initiative access changed.',403);
    }
    return $user;
}
function vp3_initiative_current_sources_v1(PDO $pdo,array $user,array $sources,bool $screen,array $canonical=[],?int $aid=null,array $capability=[]): array
{
    $rows=[];
    foreach ($sources as $ref=>$revision) {
        $row=$GLOBALS['canvasEvidence'][$ref] ?? null;
        if (!$row || $row['revision'] !== $revision || !empty($GLOBALS['canvasSourceDenied'])) {
            throw new RuntimeException('Source access changed.',409);
        }
        $rows[$ref]=$row;
    }
    return $rows;
}
function vp3_initiative_canvas_feedback_v1(PDO $pdo,array $user,int $aid,array $evidence,?DateTimeImmutable $now=null): array
{
    return $GLOBALS['canvasFeedback'] ?? [];
}

$nativeDsn=getenv('VP3_TEST_MYSQL_DSN');
if ($nativeDsn) {
    $nativeUser=getenv('VP3_TEST_MYSQL_USER') ?: 'root';
    $nativePassword=getenv('VP3_TEST_MYSQL_PASS') ?: '';
    $native=new PDO($nativeDsn,$nativeUser,$nativePassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $database='vp3_canvas_bridge_'.bin2hex(random_bytes(5));
    $native->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4');
    $dsn=preg_match('/(?:^|;)dbname=/', $nativeDsn)
        ? preg_replace('/dbname=[^;]*/','dbname='.$database,$nativeDsn) : rtrim($nativeDsn,';').';dbname='.$database;
    putenv('VP3_CLOUD_SCHEDULE_DSN='.$dsn);
    putenv('VP3_CLOUD_SCHEDULE_USER='.$nativeUser);
    putenv('VP3_CLOUD_SCHEDULE_PASSWORD='.$nativePassword);
    register_shutdown_function(static fn()=>$native->exec('DROP DATABASE IF EXISTS `'.$database.'`'));
}

require __DIR__.'/coding-canvas-jobs-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-canvas-v1.php';

vp3_coding_canvas_job_v1($pdo,$owner,7,11,$replacementJob['run_id'],'stop');
$bridgeChecks=0;
function canvas_check(bool $value,string $label): void
{
    if (!$value) throw new RuntimeException($label);
    $GLOBALS['bridgeChecks']++; echo 'PASS '.$label.PHP_EOL;
}
function canvas_reject(callable $work,int $code,string $label): void
{
    try { $work(); }
    catch (Throwable $error) { canvas_check($error->getCode()===$code,$label.' ('.$error->getMessage().')'); return; }
    throw new RuntimeException('Expected denial: '.$label);
}
function canvas_parent(bool $automatic=true): array
{
    $pdo=$GLOBALS['pdo'];$owner=$GLOBALS['owner'];
    $binding=['enabled'=>true,'conversation_id'=>11,'revision'=>bin2hex(random_bytes(16)),
        'owner_user_id'=>1,'agent_id'=>7,'knowledge_scope'=>['mode'=>'off','folder_id'=>0],'allow_auto_drafts'=>$automatic];
    $GLOBALS['canvasPolicies'][7]=$binding;
    $ref='task:41';$revision=hash('sha256','Prep board source');
    $GLOBALS['canvasEvidence']=[$ref=>['ref'=>$ref,'kind'=>'task','title'=>'Prep checklist','detail'=>'Track prep items','revision'=>$revision]];
    $GLOBALS['canvasFeedback']=[];$GLOBALS['canvasSourceDenied']=false;
    $goal=['binding'=>$binding,'sources'=>[$ref=>$revision],'canonical_sources'=>[],'capability_sources'=>[],
        'tool'=>'canvas.prepare','primary'=>$ref,'focus'=>'Prepare a simple private prep board','report'=>true];
    $dedupe=hash('sha256',random_bytes(32));
    $pdo->prepare("INSERT INTO agent_workflow_runs
        (owner_user_id,agent_id,workflow_type,origin,source_kind,source_key,source_hash,dedupe_key,title,goal,status,
         execution_target,capability_key,max_attempts,timeout_seconds)
        VALUES (1,7,'initiative','brain','agent_initiative',?,?,?,'Prepare a Canvas',?,'approved','cloud','initiative.prepare',1,180)")
        ->execute([$dedupe,substr($dedupe,0,40),$dedupe,json_encode($goal,JSON_THROW_ON_ERROR)]);
    $runId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO agent_workflow_actions
        (run_id,owner_user_id,sequence_no,action_key,action_type,label,status,execution_target,capability_key,max_attempts,timeout_seconds)
        VALUES (?,1,1,?,'execute','Prepare a Canvas','queued','cloud','initiative.prepare',1,180)")->execute([$runId,$dedupe]);
    $lease=agent_job_claim_run_v1900($pdo,$owner,$runId,'cloud',agent_worker_cloud_worker_id_v1910(1),180);
    $workflow=['run_id'=>$runId,'dedupe'=>$dedupe];
    $prepared=vp3_initiative_canvas_prepare_v1($pdo,$owner,$binding,$goal,$GLOBALS['canvasEvidence'],
        static fn()=>json_encode(['title'=>'Prep board','prompt'=>'Build a private prep checklist with an editable done checkbox.',
            'draft'=>'A small prep board could help keep the open items together. Would you like me to build it?'],JSON_THROW_ON_ERROR),$workflow);
    return compact('binding','goal','workflow','lease','prepared');
}
function canvas_commit(array $parent,bool $complete=true): array
{
    $pdo=$GLOBALS['pdo'];$owner=$GLOBALS['owner'];
    $pdo->beginTransaction();
    try {
        $result=vp3_initiative_canvas_commit_v1($pdo,$owner,$parent['binding'],$parent['goal'],$parent['prepared'],$parent['workflow']);
        vp3_initiative_canvas_result_current_v1($pdo,$owner,$parent['binding'],$parent['goal'],$result);
        if ($complete) agent_worker_cloud_result_v1910($pdo,$owner,$parent['lease'],true,'Canvas preparation saved.',
            ['effect_state'=>'private_draft_ready','initiative_tool_result'=>$result]);
        $pdo->commit();return $result;
    } catch (Throwable $error) { if($pdo->inTransaction())$pdo->rollBack();throw $error; }
}
function canvas_run(array $result,?callable $generate=null): array
{
    $pdo=$GLOBALS['pdo'];$owner=$GLOBALS['owner'];
    $id=$result['output']['coding_run_id'];
    $lease=agent_job_claim_run_v1900($pdo,$owner,$id,'cloud',agent_worker_cloud_worker_id_v1910(1),180);
    if (!$lease) throw new RuntimeException('Expected a canonical coding lease.');
    return vp3_coding_canvas_execute_v1($pdo,$owner,$lease,$generate ?? static fn()=>['title'=>'Prep board',
        'artifact'=>['html'=>'<label>Done <input type="checkbox" id="done"></label>','css'=>'label{display:block}',
            'js'=>'CanvasUI.on("#done","change",({checked})=>CanvasData.save({done:checked}));']]);
}

$off=canvas_parent(false);
$before=(int)$pdo->query("SELECT COUNT(*) FROM agent_workflow_runs WHERE source_kind='coding_canvas'")->fetchColumn();
$proposal=canvas_commit($off);
canvas_check($proposal['output']['status']==='proposal_ready'
    &&(int)$pdo->query("SELECT COUNT(*) FROM agent_workflow_runs WHERE source_kind='coding_canvas'")->fetchColumn()===$before,
    'default suggestions create no coding job and spend no coding request');
canvas_check(vp3_initiative_canvas_tool_result_v1(['output'=>$proposal['output']])['output']===$proposal['output'],
    'output-only Chat metadata preserves the exact typed proposal');
$invalid=$proposal;$invalid['output']['proposal']['prompt']=[];
canvas_check(vp3_initiative_canvas_tool_result_v1($invalid)===null,'malformed proposal metadata cannot create a display contract');
$invalid=$proposal;$invalid['output']['coding_run_id']=12;
canvas_check(vp3_initiative_canvas_tool_result_v1($invalid)===null,'a proposal cannot pretend to contain a queued build');

$rollback=canvas_parent();
canvas_reject(fn()=>vp3_initiative_canvas_commit_v1($pdo,$owner,$rollback['binding'],$rollback['goal'],$rollback['prepared'],$rollback['workflow']),409,
    'automatic admission cannot run outside its parent receipt transaction');
$pdo->beginTransaction();
$transient=vp3_initiative_canvas_commit_v1($pdo,$owner,$rollback['binding'],$rollback['goal'],$rollback['prepared'],$rollback['workflow']);
$replay=vp3_initiative_canvas_commit_v1($pdo,$owner,$rollback['binding'],$rollback['goal'],$rollback['prepared'],$rollback['workflow']);
canvas_check($transient['output']['coding_run_id']===$replay['output']['coding_run_id'],'repeated atomic admission retains one coding job');
canvas_check($pdo->inTransaction(),'coding admission never commits the caller receipt transaction');
$pdo->rollBack();
canvas_check((int)$pdo->query("SELECT COUNT(*) FROM agent_workflow_runs WHERE source_kind='coding_canvas'")->fetchColumn()===$before,
    'failure before the parent receipt rolls back the coding job as well');
agent_worker_cloud_result_v1910($pdo,$owner,$rollback['lease'],false,'Preparation rolled back.',[],'fixture_rollback');

$automatic=canvas_parent();$queued=canvas_commit($automatic);
$coding=vp3_coding_canvas_job_row_v1($pdo,$owner,$queued['output']['coding_run_id']);
$codingGoal=json_decode($coding['goal'],true,32,JSON_THROW_ON_ERROR);
canvas_check($queued['output']['status']==='draft_queued'&&$coding['origin']==='brain'
    &&$codingGoal['model']===$replacement+['funding'=>'system'],'automatic admission uses the canonical queue and selected coding model');
canvas_check(vp3_initiative_canvas_readiness_v1($pdo,$owner,$automatic['binding'])===['ready'=>false,'reason'=>'busy'],
    'a current Coding Agent job keeps additional suggestions out of the ready catalog');
canvas_reject(fn()=>vp3_initiative_canvas_origin_current_v1($pdo,$foreign,$codingGoal['initiative_origin'],(int)$coding['id']),409,
    'another owner cannot inherit automatic build authority');
$fake=$codingGoal['initiative_origin'];$fake['parent_goal_hash']=str_repeat('0',64);
canvas_reject(fn()=>vp3_initiative_canvas_origin_current_v1($pdo,$owner,$fake,(int)$coding['id']),409,
    'a changed parent instruction cannot authorize a coding request');
$finished=canvas_run($queued);
$draft=vp3_coding_canvas_read_v1($pdo,$owner,7,$queued['output']['canvas_id'],1);
canvas_check($finished['ok']&&$draft['canvas']['folder_id']>0&&$draft['canvas']['draft_revision']===1
    &&$draft['canvas']['active_revision']===null,'automatic execution saves a private Knowledge folder draft without activation');

$revoked=canvas_parent();$queued=canvas_commit($revoked);$calls=0;
$GLOBALS['canvasPolicies'][7]['allow_auto_drafts']=false;
$blocked=canvas_run($queued,static function()use(&$calls):array{$calls++;return [];});
canvas_check(!$blocked['ok']&&$calls===0,'turning Auto Build off before execution prevents any provider call');

$changed=canvas_parent();$queued=canvas_commit($changed);$calls=0;
$GLOBALS['canvasEvidence']['task:41']['revision']=hash('sha256','Changed prep task');
$blocked=canvas_run($queued,static function()use(&$calls):array{$calls++;return [];});
canvas_check(!$blocked['ok']&&$calls===0,'a changed source prevents paid generation');

$late=canvas_parent();$queued=canvas_commit($late);$calls=0;
$canvasCount=(int)$pdo->query('SELECT COUNT(*) FROM vp3_coding_canvases_v1')->fetchColumn();
$blocked=canvas_run($queued,static function()use(&$calls):array{
    $calls++;$GLOBALS['canvasPolicies'][7]['allow_auto_drafts']=false;
    return ['title'=>'Must not save','artifact'=>['html'=>'<p>Unapproved</p>','css'=>'','js'=>'']];
});
canvas_check(!$blocked['ok']&&$calls===1&&(int)$pdo->query('SELECT COUNT(*) FROM vp3_coding_canvases_v1')->fetchColumn()===$canvasCount,
    'late opt-out after provider completion prevents saving the returned draft');

$feedback=canvas_parent();$queued=canvas_commit($feedback);$calls=0;
$GLOBALS['canvasFeedback']=['task:41'=>['disposition'=>'suppress','intent'=>'defer']];
$blocked=canvas_run($queued,static function()use(&$calls):array{$calls++;return [];});
canvas_check(!$blocked['ok']&&$calls===0,'a saved postponed or declined source is not built automatically');

$receipt=canvas_parent();$queued=canvas_commit($receipt);$calls=0;
$q=$pdo->prepare("SELECT id,result_json FROM agent_workflow_receipts WHERE run_id=? AND status='completed'");
$q->execute([$receipt['workflow']['run_id']]);$saved=$q->fetch(PDO::FETCH_ASSOC);$value=json_decode($saved['result_json'],true);
$value['initiative_tool_result']['output']['coding_run_id']++;
$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE id=?')->execute([json_encode($value,JSON_THROW_ON_ERROR),$saved['id']]);
$blocked=canvas_run($queued,static function()use(&$calls):array{$calls++;return [];});
canvas_check(!$blocked['ok']&&$calls===0,'a mismatched completed parent receipt cannot authorize a coding request');

$stale=canvas_parent();$GLOBALS['canvasSourceDenied']=true;
canvas_reject(fn()=>canvas_commit($stale),409,'revocation between proposal inference and atomic admission blocks the build');
agent_worker_cloud_result_v1910($pdo,$owner,$stale['lease'],false,'Preparation source revoked.',[],'fixture_revoke');

$expired=vp3_coding_canvas_queue_v1($pdo,$owner,7,11,agent_event_uuid_v1920(),'An interrupted manual build');
$expiredLease=agent_job_claim_run_v1900($pdo,$owner,$expired['run_id'],'cloud',agent_worker_cloud_worker_id_v1910(1),180);
$pdo->prepare('UPDATE agent_workflow_runs SET lease_expires_at=? WHERE id=?')
    ->execute([gmdate('Y-m-d H:i:s',time()-10),$expired['run_id']]);
$nested=canvas_parent();
$before=(int)$pdo->query("SELECT COUNT(*) FROM agent_workflow_runs WHERE source_kind='coding_canvas'")->fetchColumn();
$pdo->beginTransaction();
$pdo->prepare("UPDATE agent_workflow_runs SET title='Uncommitted parent marker' WHERE id=?")->execute([$nested['workflow']['run_id']]);
canvas_reject(fn()=>vp3_initiative_canvas_commit_v1($pdo,$owner,$nested['binding'],$nested['goal'],$nested['prepared'],$nested['workflow']),409,
    'an expired existing Coding job blocks nested admission without trying its separate recovery transaction');
$q=$pdo->prepare('SELECT title FROM agent_workflow_runs WHERE id=?');$q->execute([$nested['workflow']['run_id']]);
canvas_check($pdo->inTransaction()&&$q->fetchColumn()==='Uncommitted parent marker'
    &&vp3_coding_canvas_job_row_v1($pdo,$owner,$expired['run_id'])['status']==='executing',
    'nested admission failure preserves the caller transaction and leaves expired recovery to its canonical worker');
$pdo->rollBack();
$q=$pdo->prepare('SELECT title FROM agent_workflow_runs WHERE id=?');$q->execute([$nested['workflow']['run_id']]);
canvas_check($q->fetchColumn()==='Prepare a Canvas'
    &&(int)$pdo->query("SELECT COUNT(*) FROM agent_workflow_runs WHERE source_kind='coding_canvas'")->fetchColumn()===$before,
    'the caller rollback retains no hidden parent marker or orphan coding admission');
vp3_coding_canvas_job_v1($pdo,$owner,7,11,$expired['run_id'],'job');
agent_worker_cloud_result_v1910($pdo,$owner,$nested['lease'],false,'Existing Coding request retained.',[],'fixture_busy');

vp3_ai_service_save_rule_v1($pdo,'user',1,'code_build',['background_enabled'=>false]);
canvas_check(vp3_coding_canvas_model_authorized_v1($pdo,$owner,$replacement+['funding'=>'system'],'code_build')['key']!=='',
    'disabling background coding preserves foreground model access');
$disabled=canvas_parent();
canvas_reject(fn()=>canvas_commit($disabled),403,'Admin background disable prevents automatic coding admission');
agent_worker_cloud_result_v1910($pdo,$owner,$disabled['lease'],false,'Background coding disabled.',[],'fixture_background');
vp3_ai_service_save_rule_v1($pdo,'user',1,'code_build',['background_daily_tokens'=>0]);
$budgeted=canvas_parent();
canvas_check(vp3_initiative_canvas_readiness_v1($pdo,$owner,$budgeted['binding'])===['ready'=>false,'reason'=>'budget_exhausted'],
    'the readiness catalog respects exhausted background token budgets');
canvas_reject(fn()=>canvas_commit($budgeted),402,'background token exhaustion blocks canonical admission');
agent_worker_cloud_result_v1910($pdo,$owner,$budgeted['lease'],false,'Background coding budget exhausted.',[],'fixture_budget');
vp3_ai_service_save_rule_v1($pdo,'user',1,'code_build',[]);

echo 'INITIATIVE_CANVAS=PASS ('.$bridgeChecks.' bridge assertions; '.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME).'; canonical coding assertions also ran)'.PHP_EOL;
