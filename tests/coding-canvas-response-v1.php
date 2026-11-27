<?php
declare(strict_types=1);

ob_start();require __DIR__.'/coding-canvas-jobs-v1.php';ob_end_clean();
require_once dirname(__DIR__).'/includes/coding-canvas-response-v1.php';
vp3_coding_canvas_job_v1($pdo,$owner,7,11,$replacementJob['run_id'],'stop');
$checks=0;
$before=vp3_coding_canvas_read_v1($pdo,$owner,7,$canvasId);
$base=$before['revision']['id'];
$job=vp3_coding_canvas_queue_v1($pdo,$owner,7,11,agent_event_uuid_v1920(),'Rebuild the heading',$canvasId,$base);
$lease=$claim($job['run_id']);$received=['html'=>'<h1>Received new code</h1>','css'=>'h1{color:green}','js'=>''];
$raw=json_encode(['title'=>'Rebuilt heading']+$received,JSON_THROW_ON_ERROR);
$calls=0;
$result=vp3_coding_canvas_execute_v1($pdo,$owner,$lease,static function($db,$u,$pin,$id,$key,$prompt,$previous,$diagnostic,$context)use(&$calls,$raw):never{
    $calls++;check($context['before_dispatch'](),'Dispatch proof is live after quota admission');
    check($context['provider_checkpoint'](['provider'=>'anthropic','text'=>$raw,'terminal'=>false,'duration_ms'=>81000,'failure'=>'provider_timeout','message_id'=>'msg_received','request_id'=>'req_received']),
        'The current lease persists received source before a provider failure');
    throw new Vp3CodingCanvasFailureV1('coding_provider_timeout');
});
check(!$result['ok']&&$calls===1,'An uncertain attempt is settled without another generation');
$run=vp3_coding_canvas_job_row_v1($pdo,$owner,$job['run_id']);
check(vp3_coding_canvas_response_decode_v1($run)['text']===$raw,'The canonical private run retains received source');
check(!str_contains(json_encode(agent_workflow_public_run_v1400($pdo,$run,true)),'Received new code'),'Generic workflow history excludes received code');
$view=vp3_coding_canvas_job_v1($pdo,$owner,7,11,$job['run_id'],'job');
check($view['state']==='failed'&&str_contains($view['reason'],'timed out')&&!str_contains(json_encode($view),'Received new code'),'Progress exposes a typed reason without copying private source');
$detail=vp3_coding_canvas_response_view_v1($pdo,$owner,7,11,$job['run_id'])['received_source'];
check($detail['text']===$raw&&$detail['can_recover']&&!$detail['terminal'],'The owner can inspect valid received code without a fabricated success receipt');
rejects(fn()=>vp3_coding_canvas_response_view_v1($pdo,$foreign,7,11,$job['run_id']),403,'A different owner cannot view received code');
rejects(fn()=>vp3_coding_canvas_response_view_v1($pdo,$owner,8,12,$job['run_id']),403,'A different Agent cannot view received code');
rejects(fn()=>vp3_coding_canvas_response_view_v1($pdo,$owner,7,12,$job['run_id']),403,'The response stays in its original Chat');
check(!vp3_coding_canvas_response_save_v1($pdo,$owner,$run,$lease,$before['revision']['source_hash'],['text'=>'late overwrite']),'A settled lease cannot overwrite its received response');
$request=agent_event_uuid_v1920();
$saved=vp3_coding_canvas_response_recover_v1($pdo,$owner,7,11,$job['run_id'],$request);
check($saved['revision']['artifact']===$received&&$saved['revision']['id']===max(array_column($before['revisions'],'id'))+1&&!$saved['revision']['test_passed'],'Explicit recovery creates an untested source draft');
check($saved['canvas']['active_revision']===$before['canvas']['active_revision']&&$saved['state']['revision']===$before['state']['revision']
    &&$saved['state']['value']==$before['state']['value'],'Recovery preserves the working version and current app records');
$again=vp3_coding_canvas_response_recover_v1($pdo,$owner,7,11,$job['run_id'],agent_event_uuid_v1920());
check($again['revision']['id']===$saved['revision']['id']&&count($again['revisions'])===count($saved['revisions']),'A replay after a lost response or reload returns the same imported draft');
check(vp3_coding_canvas_job_v1($pdo,$owner,7,11,$job['run_id'],'job')['state']==='failed'&&$calls===1,'Import does not claim the provider succeeded or charge another attempt');
rejects(fn()=>vp3_coding_canvas_activate_v1($pdo,$owner,7,$canvasId,$saved['revision']['id'],$before['canvas']['active_revision']),409,'Received source cannot become active before testing');

// Safe diagnostics use only the run's exact native ledger attribution.
foreach(['agent_id INT','conversation_id INT','run_id INT','total_tokens INT DEFAULT 0','latency_ms INT DEFAULT 0'] as $column)$pdo->exec('ALTER TABLE ai_execution_ledger ADD COLUMN '.$column);
$q=$pdo->prepare("INSERT INTO ai_execution_ledger(user_id,agent_id,conversation_id,run_id,service_key,service_job_key,service_status,usage_known,total_tokens,latency_ms) VALUES (1,7,11,?,'code_repair',?,'unknown',0,0,81000)");
$q->execute([$job['run_id'],hash('sha256','run:'.$job['run_id'])]);
$diagnostic=vp3_coding_canvas_job_v1($pdo,$owner,7,11,$job['run_id'],'job')['provider_attempt'];
check($diagnostic===['status'=>'unknown','usage_known'=>false,'tokens'=>null,'duration_ms'=>81000],'Unknown token usage is shown as unconfirmed, never zero');
$pdo->prepare('UPDATE ai_execution_ledger SET user_id=2 WHERE run_id=?')->execute([$job['run_id']]);
check(vp3_coding_canvas_provider_attempt_v1($pdo,$run)===null,'Foreign ledger rows do not become run diagnostics');
$pdo->prepare('UPDATE ai_execution_ledger SET user_id=1,usage_known=1,total_tokens=340,service_status=\'completed\' WHERE run_id=?')->execute([$job['run_id']]);
check(vp3_coding_canvas_provider_attempt_v1($pdo,$run)['tokens']===340,'Confirmed usage remains available even when later source validation failed');

$stale=vp3_coding_canvas_queue_v1($pdo,$owner,7,11,agent_event_uuid_v1920(),'Another rebuild',$canvasId,$saved['revision']['id']);
$staleLease=$claim($stale['run_id']);$staleRow=vp3_coding_canvas_job_row_v1($pdo,$owner,$stale['run_id']);
check(vp3_coding_canvas_response_save_v1($pdo,$owner,$staleRow,$staleLease,$saved['revision']['source_hash'],['text'=>json_encode(['title'=>'Another']+array_replace($received,['css'=>'h1{color:blue}']),JSON_THROW_ON_ERROR)]),'A new run has its own response checkpoint');
vp3_coding_canvas_job_v1($pdo,$owner,7,11,$stale['run_id'],'stop');
$next=vp3_coding_canvas_source_save_v1($pdo,$owner,7,11,$canvasId,$saved['revision']['id'],$saved['revision']['source_hash'],agent_event_uuid_v1920(),array_replace($received,['css'=>'h1{color:red}']),$saved['revision']['id'],$saved['canvas']['active_revision']);
check(!vp3_coding_canvas_response_view_v1($pdo,$owner,7,11,$stale['run_id'])['received_source']['can_recover'],'A newer owner draft disables the old response import');
rejects(fn()=>vp3_coding_canvas_response_recover_v1($pdo,$owner,7,11,$stale['run_id'],agent_event_uuid_v1920()),409,'A stale response cannot replace a newer draft');
check(vp3_coding_canvas_response_artifact_v1(['text'=>'{"title":"Partial","html":"<h1>'])===null,'Incomplete source remains inspectable without an import action');
echo 'CODING_CANVAS_RESPONSE=PASS ('.$checks." assertions)\n";
