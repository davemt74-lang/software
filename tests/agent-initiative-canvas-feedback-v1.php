<?php
declare(strict_types=1);

// Use real owner/Agent source grants, completed workflow receipts, immutable
// origin loading and the canonical decision/state writers. No history loader
// or authorization check is replaced with a fixture function.
require __DIR__.'/agent-initiative-scope-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-followthrough-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-engagement-feedback-v1.php';

$canvasFeedbackChecks=0;
function canvas_feedback_check(bool $ok,string $label): void
{
    if(!$ok)throw new RuntimeException($label);
    ++$GLOBALS['canvasFeedbackChecks'];echo 'PASS '.$label.PHP_EOL;
}

$q=$pdo->query("SELECT * FROM chat_messages WHERE conversation_id=170 AND role='assistant'
    AND JSON_EXTRACT(CASE WHEN JSON_VALID(context_json) THEN context_json ELSE '{}' END,'$.source')='agent_initiative'
    ORDER BY id DESC LIMIT 1");
$post=$q->fetch(PDO::FETCH_ASSOC);
if(!$post)throw new RuntimeException('A real completed Initiative report is required.');
$context=json_decode($post['context_json'],true,32,JSON_THROW_ON_ERROR);
$runId=(int)$context['initiative_run_id'];
$run=agent_workflow_row_v1400($pdo,1,$runId);
$goal=json_decode($run['goal'],true,32,JSON_THROW_ON_ERROR);
$primary=array_key_first($goal['sources']);$revision=$goal['sources'][$primary];
$goal['tool']='canvas.prepare';$goal['primary']=$primary;
$proposal=['title'=>'Launch checklist','prompt'=>'Prepare a private launch checklist from this current source.',
    'fingerprint'=>hash('sha256','canvas-feedback-fixture')];
$output=['type'=>'initiative_canvas','status'=>'proposal_ready','parent_run_id'=>$runId,
    'source_ref'=>$primary,'source_revision'=>$revision,'proposal'=>$proposal];
$normalized=agent_workflow_initiative_result_v1400(['output'=>$output]);
if(($normalized['output']['type']??null)!=='initiative_canvas')throw new RuntimeException('Canvas output registration is required.');
$output=$normalized['output'];
$pdo->prepare('UPDATE agent_workflow_runs SET goal=? WHERE id=?')
    ->execute([json_encode($goal,JSON_THROW_ON_ERROR),$runId]);
$q=$pdo->prepare('SELECT id,result_json FROM agent_workflow_actions WHERE run_id=? AND status=\'completed\'');
$q->execute([$runId]);$action=$q->fetch(PDO::FETCH_ASSOC);
$result=json_decode($action['result_json'],true,32,JSON_THROW_ON_ERROR);
$result['initiative_tool_result']['output']=$output;
unset($result['initiative_tool_result']['tool_receipt'],$context['tool_receipt']);
$json=json_encode($result,JSON_THROW_ON_ERROR);
$pdo->prepare('UPDATE agent_workflow_actions SET result_json=? WHERE id=?')->execute([$json,(int)$action['id']]);
$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE run_id=? AND action_id=?')
    ->execute([$json,$runId,(int)$action['id']]);
$context['initiative_output']=$output;
$pdo->beginTransaction();
try{
    vp3_initiative_owner_lock_v1($pdo,$one);
    vp3_initiative_followthrough_attach_v1($pdo,$one,17,170,$runId,(int)$post['id'],$post['message'],$context);
    $pdo->commit();
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
$principal=scope_principal($one,17);
$origin=vp3_initiative_followthrough_origin_load_v1($pdo,$one,$principal,170,(int)$post['id']);
canvas_feedback_check($origin['goal']['tool']==='canvas.prepare'
    &&$origin['context']['initiative_output']['type']==='initiative_canvas',
    'Canvas feedback starts from a real completed report and matching immutable receipt');

function canvas_feedback_decision(string $intent,string $text,?string $createdAt=null): int
{
    $pdo=$GLOBALS['pdo'];$owner=$GLOBALS['one'];$post=$GLOBALS['post'];$principal=$GLOBALS['principal'];
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,created_at) VALUES(170,1,'user',?,?)")
        ->execute([$text,$createdAt??gmdate('Y-m-d H:i:s')]);
    $id=(int)$pdo->lastInsertId();
    $pdo->beginTransaction();
    try{
        vp3_initiative_owner_lock_v1($pdo,$owner);
        $loaded=vp3_initiative_followthrough_origin_load_v1($pdo,$owner,$principal,170,(int)$post['id'],true);
        $state=$loaded['state'];$state['revision']++;$state['last_reply_message_id']=$id;
        $state['last_reply_hash']=hash('sha256',$text);$state['last_reply_explicit']=true;$state['last_intent']=$intent;
        $state['focus']='The owner responded to this Canvas proposal.';$state['updated_at']=gmdate(DATE_ATOM);
        $state['state']=match($intent){'defer'=>'deferred','decline'=>'declined','resolve'=>'owner_resolved',default=>'discussed'};
        vp3_initiative_followthrough_store_v1($pdo,$loaded,$state);
        agent_workflow_event_v1400($pdo,1,$GLOBALS['runId'],'initiative_owner_decision','open',$state['state'],'owner',
            'Owner responded to the exact saved Canvas proposal.',
            ['message_id'=>(int)$post['id'],'reply_message_id'=>$id,'revision'=>$state['revision'],'intent'=>$intent]);
        $pdo->commit();return $id;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}

$evidence=[$primary=>['ref'=>$primary,'revision'=>$revision,'title'=>'Current launch source']];
canvas_feedback_check(vp3_initiative_canvas_feedback_v1($pdo,$one,17,$evidence)===[],
    'a saved proposal without an owner decision invents no feedback');
$reply=canvas_feedback_decision('decline','I do not want a checklist for this.');
$decision=vp3_initiative_canvas_feedback_v1($pdo,$one,17,$evidence)[$primary]??[];
canvas_feedback_check(($decision['intent']??'')==='decline'&&($decision['disposition']??'')==='suppress'
    &&($decision['source_revision']??'')===$revision,
    'the exact owner decline suppresses the unchanged source revision');
scope_command($one,17,171,'on');
canvas_feedback_check(vp3_initiative_policy_v1($one,17)['conversation_id']===171
    &&vp3_initiative_canvas_feedback_v1($pdo,$one,17,$evidence)[$primary]===$decision,
    'switching the linked Chat preserves the selected Agent Canvas decision');
canvas_feedback_check(vp3_initiative_canvas_feedback_v1($pdo,$one,18,$evidence)===[]
    &&vp3_initiative_canvas_feedback_v1($pdo,$two,17,$evidence)===[],
    'another Agent or account cannot inherit this private decision');
$GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']=['owner'=>1,'agent'=>17,'participant'=>8,'revision'=>1];
try{$participant=vp3_initiative_canvas_feedback_v1($pdo,$one,17,$evidence);}
finally{unset($GLOBALS['VP3_PARTICIPANT_RESOURCE_SCOPE_V1']);}
canvas_feedback_check($participant===[],'participant presence cannot expose owner Canvas feedback');
canvas_feedback_check(vp3_initiative_canvas_feedback_v1($pdo,$one,17,
    [$primary=>['revision'=>hash('sha256','materially changed source')]])===[],
    'a materially new primary revision can be reconsidered');
canvas_feedback_check(vp3_initiative_canvas_feedback_v1($pdo,$one,17,
    ['unrelated-source'=>['revision'=>$revision]])===[],
    'a different source identity cannot inherit the decline');

$old=canvas_feedback_decision('defer','Leave this until I return to the launch.',gmdate('Y-m-d H:i:s',time()-40*86400));
$deferred=vp3_initiative_canvas_feedback_v1($pdo,$one,17,$evidence)[$primary]??[];
canvas_feedback_check(($deferred['intent']??'')==='defer'&&($deferred['disposition']??'')==='suppress',
    'a calendar-age timeout alone does not reopen an unchanged deferred proposal');
canvas_feedback_decision('resolve','That situation is already handled.');
canvas_feedback_check((vp3_initiative_canvas_feedback_v1($pdo,$one,17,$evidence)[$primary]['disposition']??'')==='suppress',
    'owner closure also suppresses an unchanged Canvas nudge without claiming a built app');
$reply=canvas_feedback_decision('discuss','How would that help my launch?');
$discussed=vp3_initiative_canvas_feedback_v1($pdo,$one,17,$evidence)[$primary]??[];
canvas_feedback_check(($discussed['intent']??'')==='discuss'&&($discussed['disposition']??'')==='positive'
    &&($discussed['build_permission']??null)===false,
    'conversation is advisory engagement rather than automatic build permission');
$reply=canvas_feedback_decision('advance','Please prepare that private draft.');
$advanced=vp3_initiative_canvas_feedback_v1($pdo,$one,17,$evidence)[$primary]??[];
canvas_feedback_check(($advanced['intent']??'')==='advance'&&($advanced['disposition']??'')==='positive'
    &&($advanced['build_permission']??null)===false,
    'a saved advance remains advisory here and must use the separate exact-owner build bridge');

$writes=(int)$pdo->query('SELECT total_changes()')->fetchColumn();$calls=count($GLOBALS['scopeInference']);
$scope=$GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null;$pdo->exec('PRAGMA query_only=ON');
$readonly=vp3_initiative_canvas_feedback_v1($pdo,$one,17,$evidence);
canvas_feedback_check($readonly[$primary]===$advanced&&(int)$pdo->query('SELECT total_changes()')->fetchColumn()===$writes
    &&count($GLOBALS['scopeInference'])===$calls&&($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410']??null)===$scope,
    'cross-Chat feedback performs no writes, inference or namespace changes');
canvas_feedback_check(!preg_match('/Launch checklist|private launch checklist|Please prepare|conversation_id|message_id/',json_encode($readonly,JSON_THROW_ON_ERROR)),
    'planner feedback contains no saved prompt, reply text or raw history payload');
$pdo->exec('PRAGMA query_only=OFF');

$pdo->prepare('UPDATE chat_messages SET message=? WHERE id=?')->execute(['Edited after decision',$reply]);
canvas_feedback_check(vp3_initiative_canvas_feedback_v1($pdo,$one,17,$evidence)===[],
    'editing the saved owner reply invalidates its historical decision');
$pdo->prepare('UPDATE chat_messages SET message=? WHERE id=?')->execute(['Please prepare that private draft.',$reply]);
$receipt=$pdo->query('SELECT result_json FROM agent_workflow_receipts WHERE run_id='.$runId)->fetchColumn();
$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE run_id=?')->execute(['{}',$runId]);
canvas_feedback_check(vp3_initiative_canvas_feedback_v1($pdo,$one,17,$evidence)===[],
    'a missing or mismatched completed receipt cannot establish Canvas feedback');
$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE run_id=?')->execute([$receipt,$runId]);
canvas_feedback_decision('decline','A future-dated fixture decision.',gmdate('Y-m-d H:i:s',time()+3600));
canvas_feedback_check(vp3_initiative_canvas_feedback_v1($pdo,$one,17,$evidence)===[],
    'future-dated saved replies are not used as current owner decisions');
canvas_feedback_check(vp3_initiative_canvas_feedback_v1($pdo,$one,17,[$primary=>['revision'=>'invalid']])===[],
    'an invalid current evidence revision is rejected without history lookup');

// A later owner decision must still be observed after an automatic proposal
// has queued its Coding job. Feedback itself never claims the job completed.
$output['status']='draft_queued';$output['coding_run_id']=99;
$output['canvas_id']='01234567-89ab-4cde-8f01-0123456789ab';
$output=agent_workflow_initiative_result_v1400(['output'=>$output])['output'];
$q=$pdo->prepare('SELECT context_json FROM chat_messages WHERE id=?');$q->execute([(int)$post['id']]);
$context=json_decode($q->fetchColumn(),true,32,JSON_THROW_ON_ERROR);$context['initiative_output']=$output;
$result['initiative_tool_result']['output']=$output;$json=json_encode($result,JSON_THROW_ON_ERROR);
$pdo->prepare('UPDATE agent_workflow_actions SET result_json=? WHERE id=?')->execute([$json,(int)$action['id']]);
$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE run_id=? AND action_id=?')
    ->execute([$json,$runId,(int)$action['id']]);
$pdo->beginTransaction();
try{
    vp3_initiative_owner_lock_v1($pdo,$one);
    vp3_initiative_followthrough_attach_v1($pdo,$one,17,170,$runId,(int)$post['id'],$post['message'],$context);
    $pdo->commit();
}catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
canvas_feedback_decision('decline','Please stop preparing this Canvas.');
$queued=vp3_initiative_canvas_feedback_v1($pdo,$one,17,$evidence)[$primary]??[];
canvas_feedback_check(($queued['intent']??'')==='decline'&&($queued['disposition']??'')==='suppress',
    'a queued Canvas proposal still exposes the later owner decline to dispatch and save guards');
echo 'AGENT_INITIATIVE_CANVAS_FEEDBACK_V1=PASS ('.$canvasFeedbackChecks.' cases)'.PHP_EOL;
