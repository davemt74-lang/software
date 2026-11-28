<?php
declare(strict_types=1);

// Real source scope, rules storage, scheduler, workflow receipts and Chat
// delivery. The Gmail connector and physical model route are fixtures.
define('VP3_COORDINATION_PIPELINE_FIXTURE', true);
require __DIR__.'/agent-coordination-pipeline-v1.php';

function get_tracks(): array { return []; }

$agentId = 7;
$chatId = 11;
if ($mysql) $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
$binding = vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>$chatId],'on',null)['initiative_policy'];
// Isolate routing scenarios by starting after the day's earlier brief assessment.
require_once dirname(__DIR__).'/includes/agent-morning-brief-v1.php';
$briefDay=vp3_morning_brief_local_v1($pdo,$owner,new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d');
vp3_initiative_in_scope_v1($owner,$agentId,static fn()=>agent_brain_v122_upsert_system_memory(
    $owner,'initiative_sweep','morning-brief:'.$briefDay,'Prior daily brief fixture assessment',['local_day'=>$briefDay]));
coordination_fixture_rule($pdo,$owner,'curiosity','open_question','watch');
if ($mysql) {
    $pdo->exec('ALTER TABLE agent_workflow_runs RENAME COLUMN paused_at TO fixture_paused_at');
    // This fixture changes the installation shape inside one request. Earlier
    // readiness checks may already have populated the positive metadata cache.
    schema_metadata_state($pdo)->columns['agent_workflow_runs']=[];
    try {
        $partialSchemaHealth = vp3_initiative_worker_health_v1($pdo,$owner,$agentId,$chatId);
        canvas_pipeline_check($partialSchemaHealth['assessment_read']==='complete' && $partialSchemaHealth['enabled']
            && $partialSchemaHealth['pump_available']
            && $partialSchemaHealth['policy_revision']===$binding['revision'],
            'native assessment health and Chat pump availability survive without optional workflow pause controls');
    } finally {
        $pdo->exec('ALTER TABLE agent_workflow_runs RENAME COLUMN fixture_paused_at TO paused_at');
        schema_metadata_state($pdo)->columns['agent_workflow_runs']=[];
    }
}
coordination_fixture_gmail_setup($pdo,$owner,$mysql);
$gmailRef = coordination_fixture_gmail_observe($pdo,$owner);
$initialChatCount = canvas_pipeline_count('chat_messages','conversation_id=11');
coordination_fixture_presence('busy');
coordination_fixture_schedule($owner);
$runs = coordination_fixture_runs('coordination.review');
$first = $runs[0];
$ask = ['specialist'=>'gmail','trigger'=>'needs_attention','action'=>'ask'];
canvas_pipeline_check(count($runs) === 1 && $first['goal']['primary'] === $gmailRef
    && ($first['goal']['coordination_route'] ?? null) === $ask
    && ($first['goal']['coordination_saved'] ?? null) === true,
    'one saved specialist finding matches the default ask rule without requiring a second specialist');
$inferenceCalls = 0;
$model = static function () use (&$inferenceCalls): never {
    ++$inferenceCalls;
    throw new LogicException('The exact saved route does not invoke a model.');
};
$result = vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($first['id']),$model);
$firstReceipt = coordination_fixture_receipt($first['id']);
canvas_pipeline_check(($result['ok'] ?? false) === true && $inferenceCalls === 0
    && $firstReceipt['initiative_delivery'] === 'delivered'
    && canvas_pipeline_count('chat_messages','conversation_id=11') === $initialChatCount+1,
    'a verified timestamped question reaches the saved Chat while the owner is busy');
vp3_initiative_deliver_pending_v1($pdo,$owner,$agentId);
canvas_pipeline_check(canvas_pipeline_count('chat_messages','conversation_id=11') === $initialChatCount+1,
    'rechecking pending delivery cannot replay the busy-Chat handoff');

coordination_fixture_gmail_observe($pdo,$owner,2,'The vendor asks for a revised arrival time.');
coordination_fixture_schedule($owner);
$queuedBeforeRuleChange=coordination_fixture_runs('coordination.review');
$queuedBeforeRuleChange=end($queuedBeforeRuleChange);
canvas_pipeline_check($queuedBeforeRuleChange['id']!==$first['id']
    && $queuedBeforeRuleChange['goal']['coordination_route']===$ask,
    'a changed saved finding queues a distinct ask under the current rule');
$preparedQuestion=vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($queuedBeforeRuleChange['id']),$model,null,false);
canvas_pipeline_check(!empty($preparedQuestion['ok'])&&$inferenceCalls===0
    && coordination_fixture_receipt($queuedBeforeRuleChange['id'])['initiative_delivery']==='pending'
    && canvas_pipeline_count('chat_messages','conversation_id=11')===$initialChatCount+1,
    'the worker can save a verified question for the separate delivery pass without posting it');
coordination_fixture_rule($pdo,$owner,'gmail','needs_attention','watch');
coordination_fixture_presence('quiet');
canvas_pipeline_check(!vp3_initiative_deliver_pending_v1($pdo,$owner,$agentId)
    && coordination_fixture_receipt($queuedBeforeRuleChange['id'])['initiative_delivery']==='withheld'
    && canvas_pipeline_count('chat_messages','conversation_id=11')===$initialChatCount+1,
    'a rule edit to watch withdraws an already verified pending question before it reaches Chat');
$initialChatCount++;

$summary = 'The vendor moved delivery to Thursday before the rehearsal.';
coordination_fixture_gmail_observe($pdo,$owner,3,$summary);
coordination_fixture_schedule($owner);
$watchSnapshot = vp3_coordination_sources_v1($pdo,$owner,$binding,[$gmailRef]);
$watchSettings = vp3_coordination_rules_v1($pdo,$owner,$agentId);
canvas_pipeline_check(($watchSnapshot['items'][$gmailRef]['summary'] ?? null) === $summary
    && vp3_coordination_route_v1($watchSettings,'gmail',['needs_attention'])['action'] === 'watch'
    && canvas_pipeline_count('chat_messages','conversation_id=11') === $initialChatCount,
    'watch keeps the current specialist finding available for Top of Mind without a Chat post');

coordination_fixture_rule($pdo,$owner,'gmail','needs_attention','ask');
$GLOBALS['pipelineRuntimePlan'] = ['route'=>'vp3_cloud','blocked'=>true,'effective_preference'=>'vp3_cloud',
    'cloud'=>['ready'=>false,'reason'=>'cloud_balance_exhausted'],'home'=>['supported'=>false]];
canvas_pipeline_check(vp3_initiative_inference_health_v1($pdo,$owner,$agentId)['reason'] === 'quota_exhausted',
    'the foreground test uses an actually blocked model route');
$pdo->exec("DELETE FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7 AND memory_type='initiative_sweep' AND subject='coordination-scan'");
vp3_initiative_pump_v1($pdo,$owner,$agentId,$chatId,$binding['revision'],$model);
$runs = coordination_fixture_runs('coordination.review');
$asked = end($runs);
$askedReceipt = coordination_fixture_receipt($asked['id']);
$askedId = (int)$askedReceipt['initiative_message_id'];
$askedMessage = coordination_fixture_message($askedId);
$askedContext = json_decode((string)$askedMessage['context_json'],true,32,JSON_THROW_ON_ERROR);
$output = $askedReceipt['initiative_tool_result']['output'];
canvas_pipeline_check($asked['goal']['coordination_route'] === $ask && ($asked['goal']['coordination_saved'] ?? null) === true
    && $asked['goal']['sources'] === $output['evidence_revisions'] && $output['state'] === 'needs_owner'
    && $output['summary'] === $summary && $output['question'] === 'Can the vendor arrive earlier?'
    && ($askedContext['initiative_work']['followthrough']['state'] ?? null) === 'open'
    && canvas_pipeline_count('chat_messages','conversation_id=11') === $initialChatCount+1 && $inferenceCalls === 0,
    'the ordinary foreground pump starts a linked assistant question from saved evidence during a model outage');

$nextChatId = 14;
$pdo->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES(14,1,7,'Another private Chat')");
$nextBinding = vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>$nextChatId],'on',null)['initiative_policy'];
coordination_fixture_presence('quiet',$nextChatId);
$sameAgent = vp3_coordination_sources_v1($pdo,$owner,$nextBinding,[$gmailRef]);
$siblingAgent = vp3_coordination_sources_v1($pdo,$owner,['agent_id'=>8,'owner_user_id'=>1,'conversation_id'=>12],[$gmailRef]);
canvas_pipeline_check(isset($sameAgent['items'][$gmailRef]) && $siblingAgent['items'] === [],
    'the linked mailbox supplies the active private Chat of the same Agent only');
vp3_initiative_pump_v1($pdo,$owner,$agentId,$nextChatId,$nextBinding['revision'],$model);
canvas_pipeline_check(canvas_pipeline_count('chat_messages','conversation_id=14') === 0
    && canvas_pipeline_count('chat_messages','conversation_id=11') === $initialChatCount+1,
    'switching to another Chat of the same Agent does not replay a verified unchanged handoff');

coordination_fixture_rule($pdo,$owner,'gmail','needs_attention','report');
$reportSummary = 'The vendor moved delivery to Friday before the rehearsal.';
coordination_fixture_gmail_observe($pdo,$owner,4,$reportSummary);
$pdo->exec("DELETE FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7 AND memory_type='initiative_sweep' AND subject='coordination-scan'");
vp3_initiative_pump_v1($pdo,$owner,$agentId,$nextChatId,$nextBinding['revision'],$model);
$runs = coordination_fixture_runs('coordination.review');
$report = end($runs);
$reportReceipt = coordination_fixture_receipt($report['id']);
$reportOutput = $reportReceipt['initiative_tool_result']['output'];
$reportMessage = coordination_fixture_message((int)$reportReceipt['initiative_message_id']);
canvas_pipeline_check(($report['goal']['coordination_route']['action'] ?? null) === 'report'
    && (int)$reportMessage['conversation_id'] === $nextChatId && $reportOutput['summary'] === $reportSummary
    && $reportOutput['question'] === '' && !str_contains($reportMessage['message'],'Question for you:')
    && $inferenceCalls === 0,
    'report delivers the new verified fact once without creating a question or requiring inference');
$pdo->exec("DELETE FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7 AND memory_type='initiative_sweep' AND subject='coordination-scan'");
vp3_initiative_pump_v1($pdo,$owner,$agentId,$nextChatId,$nextBinding['revision'],$model);
canvas_pipeline_check(canvas_pipeline_count('chat_messages','conversation_id=14') === 1
    && canvas_pipeline_count('chat_messages','conversation_id=11') === $initialChatCount+1,
    'rescanning unchanged evidence leaves one report in the active conversation');

unset($GLOBALS['pipelineRuntimePlan']);
coordination_fixture_rule($pdo,$owner,'gmail','needs_attention','watch');
coordination_fixture_prepare_office($pdo,$owner,'task:601',true);
canvas_pipeline_check(canvas_pipeline_count('chat_messages','conversation_id=14') === 1,
    'even a native Tasks report flagged as waiting on the owner stays private until shared routing');
$task = vp3_coordination_sources_v1($pdo,$owner,$nextBinding,['office:task:601'])['items']['office:task:601'];
$taskAsk = ['specialist'=>'tasks','trigger'=>'waiting_owner','action'=>'ask'];
canvas_pipeline_check(($task['trigger'] ?? null) === 'waiting_owner' && $task['question'] === 'Is a later vendor delivery acceptable?',
    'the verified native Tasks report supplies a specific owner question');
$GLOBALS['pipelineRuntimePlan'] = ['route'=>'vp3_cloud','blocked'=>true,'effective_preference'=>'vp3_cloud',
    'cloud'=>['ready'=>false,'reason'=>'cloud_balance_exhausted'],'home'=>['supported'=>false]];
$pdo->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=14");
$pdo->exec("DELETE FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7 AND memory_type='initiative_sweep' AND subject='coordination-scan'");
vp3_initiative_pump_v1($pdo,$owner,$agentId,$nextChatId,$nextBinding['revision'],$model);
$runs = coordination_fixture_runs('coordination.review','office:task:601');
$taskRun = end($runs);
$taskReceipt = coordination_fixture_receipt($taskRun['id']);
$taskMessage = coordination_fixture_message((int)$taskReceipt['initiative_message_id']);
canvas_pipeline_check($taskRun['goal']['coordination_route'] === $taskAsk && (int)$taskMessage['conversation_id'] === $nextChatId
    && str_contains($taskMessage['message'],$task['question']) && $inferenceCalls === 0
    && canvas_pipeline_count('chat_messages','conversation_id=14') === 2,
    'the shared loop delivers the exact saved Tasks question once through the same foreground pump');

$pdo->exec('DELETE FROM agent_connection_gmail_inbox_initial_v1 WHERE owner_user_id=1');
$mailbox = agent_connection_gmail_inbox_bound_scope_v1($pdo,$owner);
$noAnchorSummary = 'The rehearsal team can accept delivery an hour earlier.';
coordination_fixture_gmail_observe($pdo,$owner,5,$noAnchorSummary);
coordination_fixture_rule($pdo,$owner,'gmail','needs_attention','ask');
$specialist = agent_email_running_report_view_v1($pdo,$mailbox);
canvas_pipeline_check(agent_connection_gmail_inbox_update_destination_v1($pdo,$mailbox) === null
    && ($specialist['findings'][0]['summary'] ?? null) === $noAnchorSummary,
    'the Gmail specialist has a running finding before any legacy review or batch was posted');

$siblingPrincipal = vp3_initiative_principal_v1($pdo,$owner,8);
$siblingBinding = vp3_initiative_chat_v1($pdo,$owner,$siblingPrincipal,['conversation_id'=>12],'on',null)['initiative_policy'];
$sibling = vp3_coordination_sources_v1($pdo,$owner,$siblingBinding);
canvas_pipeline_check(isset($sibling['items'][$gmailRef]),
    'without a pinned destination another enabled private Agent Chat can review the same owner mailbox');
$foreignDenied=false;
try{vp3_coordination_sources_v1($pdo,['id'=>2,'is_active'=>1],$siblingBinding);}
catch(RuntimeException $error){$foreignDenied=$error->getCode()===403;}
canvas_pipeline_check($foreignDenied,'another owner cannot borrow the saved mailbox Chat binding');

coordination_fixture_presence('busy',$nextChatId);
canvas_pipeline_check(isset(vp3_coordination_sources_v1($pdo,$owner,$nextBinding)['items'][$gmailRef])
    && isset(vp3_coordination_sources_v1($pdo,$owner,$siblingBinding)['items'][$gmailRef]),
    'saved authorized Chat bindings can prepare Gmail findings independently of browser typing or voice');
coordination_fixture_presence('away',$nextChatId);
canvas_pipeline_check(isset(vp3_coordination_sources_v1($pdo,$owner,$nextBinding)['items'][$gmailRef]),
    'a hidden Chat retains its saved owner-authorized background review binding');
coordination_fixture_presence('quiet',$nextChatId);

$pdo->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=14");
$pdo->exec("DELETE FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7 AND memory_type='initiative_sweep' AND subject='coordination-scan'");
vp3_initiative_pump_v1($pdo,$owner,$agentId,$nextChatId,$nextBinding['revision'],$model);
$recoveredRuns = coordination_fixture_runs('coordination.review',$gmailRef);
$recovered = end($recoveredRuns);
canvas_pipeline_check($recovered['id'] !== $report['id'] && $recovered['status'] === 'completed',
    'a saved Gmail finding is admitted without a legacy destination');
$recoveredReceipt = coordination_fixture_receipt($recovered['id']);
$recoveredId = (int)$recoveredReceipt['initiative_message_id'];
$recoveredMessage = coordination_fixture_message($recoveredId);
canvas_pipeline_check((int)$recoveredMessage['conversation_id'] === $nextChatId
    && str_contains($recoveredMessage['message'],$noAnchorSummary) && $inferenceCalls === 0
    && canvas_pipeline_count('chat_messages','conversation_id=14') === 3,
    'the ordinary foreground pump delivers the saved question without a legacy Gmail destination or new model call');

$thirdChat = 15;
$pdo->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES(15,1,7,'Third private Chat')");
$thirdBinding = vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>$thirdChat],'on',null)['initiative_policy'];
coordination_fixture_presence('quiet',$thirdChat);
$hydrated = vp3_coordination_chat_history_v1($pdo,$owner,$agentId,$nextChatId,[$recoveredMessage]);
vp3_initiative_pump_v1($pdo,$owner,$agentId,$thirdChat,$thirdBinding['revision'],$model);
canvas_pipeline_check($hydrated[0]['message'] === $recoveredMessage['message']
    && canvas_pipeline_count('chat_messages','conversation_id=15') === 0,
    'after a same-Agent Chat switch the exact historical message still verifies and does not replay');

require_once dirname(__DIR__).'/includes/agent-casual-checkin-v1.php';
coordination_fixture_calendar($pdo);
$testNoon = new DateTimeImmutable('today 12:00:00',new DateTimeZone('UTC'));
$soon = $testNoon->modify('+1 hour')->format('Y-m-d H:i:s');
$later = $testNoon->modify('+3 hours')->format('Y-m-d H:i:s');
$event = $pdo->prepare("INSERT INTO user_calendar_events VALUES(?,?,?,'','',?,?,'UTC',0,'none',1,
    'user','','active',NULL,CURRENT_TIMESTAMP)");
$event->execute([703,1,'Earlier appointment',$testNoon->modify('-2 hours')->format('Y-m-d H:i:s'),
    $testNoon->modify('-1 hour')->format('Y-m-d H:i:s')]);
$event->execute([704,2,'Private other appointment',$soon,$later]);
// Use a synthetic review clock with a guaranteed interval before midnight.
$pdo->prepare('UPDATE user_calendar_events SET start_at_utc=?,end_at_utc=? WHERE id=701')
    ->execute([$testNoon->modify('+1 day')->format('Y-m-d H:i:s'),$testNoon->modify('+1 day 1 hour')->format('Y-m-d H:i:s')]);
$event->execute([705,1,'Today’s venue review',$soon,$testNoon->modify('+2 hours')->format('Y-m-d H:i:s')]);
$event->execute([706,1,'Later today',$later,$testNoon->modify('+4 hours')->format('Y-m-d H:i:s')]);
$exact = static fn(string $ref): array => vp3_coordination_sources_v1($pdo,$owner,$thirdBinding,[$ref],$testNoon)['items'];
canvas_pipeline_check($exact('casual:today') === [] && $exact('casual:resume') === [],
    'casual context stays unavailable until the owner opts in');
vp3_casual_checkin_save_v1($pdo,1,true);
$today = $exact('casual:today')['casual:today'] ?? [];
canvas_pipeline_check(($today['source_ref'] ?? null) === 'planning:calendar:event:705'
    && ($today['question'] ?? null) === 'Want to see what’s next today?'
    && ($today['source_title'] ?? null) === 'Today’s venue review'
    && ($today['source_summary'] ?? null) === 'It starts at 1:00 PM UTC.'
    && !str_contains($today['summary'],'venue review'),
    'the calendar check-in uses the nearest verified event today and reserves its details for the reply');
$pdo->exec("UPDATE user_calendar_events SET status='cancelled' WHERE id=705");
$replacement = $exact('casual:today')['casual:today'] ?? [];
canvas_pipeline_check(($replacement['source_ref'] ?? null) === 'planning:calendar:event:706'
    && ($replacement['revision'] ?? null) !== $today['revision'],
    'a calendar cancellation invalidates the previous check-in’s evidence');
$pdo->exec("UPDATE user_calendar_events SET status='cancelled' WHERE id=706");
canvas_pipeline_check($exact('casual:today') === [], 'past, foreign, and tomorrow’s events do not trigger today’s check-in');

vp3_casual_checkin_save_v1($pdo,1,false);
unset($GLOBALS['pipelineRuntimePlan']);
coordination_fixture_prepare_office($pdo,$owner,'task:850',false,['task_status'=>'in_progress','due_at'=>''],'');
vp3_casual_checkin_save_v1($pdo,1,true);
$resume = $exact('casual:resume')['casual:resume'] ?? [];
canvas_pipeline_check(($resume['source_ref'] ?? null) === 'office:task:850'
    && ($resume['source_summary'] ?? null) === 'Prepare an alternate delivery plan.'
    && ($resume['question'] ?? null) === 'Want to pick up where you left off?'
    && !str_contains($resume['summary'],'vendor'),
    'a work check-in requires an in-progress owned task and its verified specialist next step');
$pdo->exec("DELETE FROM user_calendar_events WHERE id IN (701,703,704,705,706)");
$pdo->prepare('INSERT INTO settings(setting_key,setting_value) VALUES(?,?)')->execute([
    'casual_checkin_delivery_owner_1',json_encode(['delivered_at'=>time(),'kind'=>'today'],JSON_THROW_ON_ERROR),
]);
coordination_fixture_gmail_observe($pdo,$owner,6,'The rehearsal team needs the delivery time today.');
$GLOBALS['pipelineRuntimePlan'] = ['route'=>'vp3_cloud','blocked'=>true,'effective_preference'=>'vp3_cloud',
    'cloud'=>['ready'=>false,'reason'=>'cloud_balance_exhausted'],'home'=>['supported'=>false]];
$resetScan = static fn() => $pdo->exec("DELETE FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7
    AND memory_type='initiative_sweep' AND subject='coordination-scan'");
$resetScan();
vp3_initiative_pump_v1($pdo,$owner,$agentId,$thirdChat,$thirdBinding['revision'],$model);
$latestGmail = coordination_fixture_runs('coordination.review',$gmailRef);
$latestGmail = end($latestGmail);
canvas_pipeline_check($latestGmail['goal']['binding'] === $thirdBinding
    && canvas_pipeline_result($latestGmail['id'])['initiative_delivery'] === 'delivered'
    && coordination_fixture_runs('coordination.review','casual:resume') === [],
    'a current verified Gmail question is delivered before an eligible casual check-in');
$pdo->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=15");
$resetScan();
vp3_initiative_pump_v1($pdo,$owner,$agentId,$thirdChat,$thirdBinding['revision'],$model);
$resumeRuns = coordination_fixture_runs('coordination.review','casual:resume');
$resumeRun = $resumeRuns[0] ?? null;
$receipt = $resumeRun ? canvas_pipeline_result($resumeRun['id']) : [];
canvas_pipeline_check(count($resumeRuns) === 1 && ($receipt['initiative_delivery'] ?? null) === 'delivered'
    && ($receipt['initiative_tool_result']['output']['question'] ?? null) === 'Want to pick up where you left off?',
    'the shared foreground pump posts the contextual task check-in after actionable work');
$taskBeforeReply = $pdo->query('SELECT metadata_json FROM agent_memory_items WHERE id=850')->fetchColumn();
$reply = vp3_agent_chat_send_v2160($pdo,$owner,vp3_agent_chat_resolve_agent_v380($pdo,$owner,$agentId),$principal,
    ['message'=>'yes','conversation_id'=>$thirdChat],true,null,true);
canvas_pipeline_check(($reply['initiative_followthrough']['state'] ?? null) === 'owner_resolved'
    && str_contains($reply['answer'],'Confirm vendor delivery before rehearsal')
    && str_contains($reply['answer'],'Prepare an alternate delivery plan.')
    && ($reply['actions'][0]['url'] ?? null) === '/account.php#agent-brain'
    && $pdo->query('SELECT metadata_json FROM agent_memory_items WHERE id=850')->fetchColumn() === $taskBeforeReply,
    'an ordinary yes opens the existing task workspace and gives the verified next step without editing the task');

vp3_casual_checkin_save_v1($pdo,1,false);
unset($GLOBALS['pipelineRuntimePlan']);
$warmChat = 16;
$pdo->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES(16,1,7,'Warm private Chat')");
$warmBinding = vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>$warmChat],'on',null)['initiative_policy'];
coordination_fixture_presence('quiet',$warmChat);
$warmSummary = 'A follow-up from the vendor needs a decision before the rehearsal.';
coordination_fixture_gmail_observe($pdo,$owner,7,$warmSummary);
$taskRef = 'office:task:601';
$sources = vp3_coordination_sources_v1($pdo,$owner,$warmBinding,[$taskRef,$gmailRef])['items'];
$revisions = array_column($sources,'revision','ref');
ksort($revisions);
$privateSituation = vp3_coordination_scan_id_v1($owner,$warmBinding,$taskRef);
$privateRoute = ['specialist'=>'tasks','trigger'=>'waiting_owner','action'=>'prepare'];
$rules = vp3_coordination_rules_v1($pdo,$owner,$agentId);
vp3_coordination_rule_settings_v1($pdo,$owner,$agentId,$warmChat,$rules['revision'],['tasks'=>['waiting_owner'=>'prepare']]);
$privateGoal = [
    'binding'=>$warmBinding,'tool'=>'coordination.review','coordination_id'=>$privateSituation,
    'primary'=>$taskRef,'sources'=>$revisions,'focus'=>'Compare verified specialist evidence privately.',
    'report'=>true,'coordination_coverage'=>vp3_coordination_scan_coverage_v1(vp3_coordination_sources_v1($pdo,$owner,$warmBinding)['coverage']),
    'coordination_priority'=>'normal','coordination_route'=>$privateRoute,
    'coordination_routes'=>[$gmailRef=>$ask,$taskRef=>$privateRoute],'coordination_saved'=>false,
];
ksort($privateGoal['coordination_routes']);
vp3_initiative_enqueue_v1($pdo,$owner,'initiative.prepare',$privateGoal,hash('sha256','warm-private'));
$earlier = coordination_fixture_runs('coordination.review',$taskRef);
$earlier = end($earlier);
$privateModel = static fn(): string => json_encode([
    'title'=>'Private vendor notes','summary'=>'Retained the vendor evidence as context for the private task.',
    'next_step'=>'','question'=>'','state'=>'watching','refs'=>[$taskRef,$gmailRef],
],JSON_THROW_ON_ERROR);
$private = vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($earlier['id']),$privateModel);
$privateProof = coordination_fixture_receipt($earlier['id']);
canvas_pipeline_check($private['ok'] && $privateProof['initiative_delivery']==='private'
    && isset($privateProof['initiative_tool_result']['output']['evidence_revisions'][$gmailRef])
    && canvas_pipeline_count('chat_messages','conversation_id=16')===0,
    'the same Chat previously used this exact Gmail finding only as context for a private Tasks review');

$scanOutcome = null;
$updateGoal = $pdo->prepare('UPDATE agent_workflow_runs SET goal=? WHERE id=?');
$updateGoal->execute([json_encode(array_replace($earlier['goal'],['coordination_routes'=>'legacy']),JSON_THROW_ON_ERROR),$earlier['id']]);
$resetScan();
vp3_initiative_schedule_coordination_v1($pdo,$owner,$warmBinding,true,$scanOutcome);
$direct = array_values(array_filter(coordination_fixture_runs('coordination.review',$gmailRef),
    static fn(array $run): bool => $run['goal']['binding']===$warmBinding));
canvas_pipeline_check($scanOutcome==='queued_chat_handoff' && count($direct)===1,
    'a malformed historical route map cannot block a verified Gmail Ask or turn private evidence into delivery');
$updateGoal->execute([json_encode($earlier['goal'],JSON_THROW_ON_ERROR),$earlier['id']]);

$pdo->prepare("DELETE FROM agent_workflow_runs WHERE owner_user_id=1 AND id=?")->execute([$direct[0]['id']]);
vp3_initiative_enqueue_v1($pdo,$owner,'initiative.prepare',$privateGoal,hash('sha256','warm-private-running'));
$working = coordination_fixture_runs('coordination.review',$taskRef);
$working = end($working);
canvas_pipeline_claim($working['id']);
$resetScan();
$warmModel = static fn(): string => '{"invalid":"a model response must not suppress the verified saved question"}';
$warm = vp3_initiative_pump_v1($pdo,$owner,$agentId,$warmChat,$warmBinding['revision'],$warmModel);
$direct = array_values(array_filter(coordination_fixture_runs('coordination.review',$gmailRef),
    static fn(array $run): bool => $run['goal']['binding']===$warmBinding));
$warmReceipt = count($direct)===1 ? coordination_fixture_receipt($direct[0]['id']) : [];
$warmMessage = isset($warmReceipt['initiative_message_id']) ? coordination_fixture_message((int)$warmReceipt['initiative_message_id']) : [];
$tick = vp3_initiative_in_scope_v1($owner,$agentId,static fn()=>vp3_initiative_foreground_tick_v1($owner,$warmBinding));
canvas_pipeline_check($warm['ok'] && ($warmReceipt['initiative_delivery']??null)==='delivered'
    && str_contains($warmMessage['message']??'',$warmSummary)
    && ($tick['step']??null)==='preparation' && ($tick['run_id']??0)===(int)$direct[0]['id']
    && ($tick['coordination']['outcome']??null)==='queued_chat_handoff'
    && ($pdo->query('SELECT status FROM agent_workflow_runs WHERE id='.$working['id'])->fetchColumn())==='executing',
    'a ready Gmail handoff posts while unrelated private work keeps its lease and model output cannot suppress the saved question');
$resetScan();
vp3_initiative_schedule_coordination_v1($pdo,$owner,$warmBinding,true,$scanOutcome);
canvas_pipeline_check($scanOutcome==='handoff_already_handled' && canvas_pipeline_count('chat_messages','conversation_id=16')===1,
    'a repeated shared scan records an already handled exact handoff without reposting it');

coordination_fixture_gmail_observe($pdo,$owner,8,'The vendor needs a fresh decision for the rehearsal.');
$pdo->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=16");
$resetScan();
$pdo->failMediaSource = true;
try {
    $resolved = vp3_initiative_pump_v1($pdo,$owner,$agentId,$warmChat,$warmBinding['revision'],$warmModel);
} finally {
    $pdo->failMediaSource = false;
}
canvas_pipeline_check($resolved['ok']&&($resolved['coordination']??null)==='queued_chat_handoff'
    && canvas_pipeline_count('chat_messages','conversation_id=16')===2,
    'an optional media type failure leaves the verified Gmail handoff eligible for Chat');

$contextChat = 17;
$pdo->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES(17,1,7,'Specialist context Chat')");
$contextBinding = vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>$contextChat],'on',null)['initiative_policy'];
coordination_fixture_presence('quiet',$contextChat);
$pdo->exec("UPDATE agent_memory_items SET is_active=0 WHERE user_id=1 AND id IN (601,850)");
$contextTaskRef = 'office:task:860';
// The native Tasks fixture runs the shared scheduler. Assemble the new pair of
// findings before allowing Gmail to queue its own handoff for this scenario.
coordination_fixture_rule($pdo,$owner,'gmail','needs_attention','watch');
coordination_fixture_prepare_office($pdo,$owner,'task:860',true);
$contextSummary = 'The vendor offered delivery before the rehearsal and needs an owner response.';
coordination_fixture_gmail_observe($pdo,$owner,9,$contextSummary);
coordination_fixture_rule($pdo,$owner,'gmail','needs_attention','ask');
coordination_fixture_rule($pdo,$owner,'tasks','waiting_owner','ask');
$contextRevisions = array_column(vp3_coordination_sources_v1($pdo,$owner,$contextBinding,[$contextTaskRef,$gmailRef])['items'],'revision','ref');
ksort($contextRevisions);
$taskContextGoal = array_replace($privateGoal,[
    'binding'=>$contextBinding,'coordination_id'=>vp3_coordination_scan_id_v1($owner,$contextBinding,$contextTaskRef),
    'primary'=>$contextTaskRef,'sources'=>$contextRevisions,'coordination_route'=>$taskAsk,
    'coordination_routes'=>[$gmailRef=>$ask,$contextTaskRef=>$taskAsk],
    'coordination_coverage'=>vp3_coordination_scan_coverage_v1(vp3_coordination_sources_v1($pdo,$owner,$contextBinding)['coverage']),
]);
vp3_initiative_enqueue_v1($pdo,$owner,'initiative.prepare',$taskContextGoal,hash('sha256','posted-task-with-gmail-context'));
$taskContextRuns = coordination_fixture_runs('coordination.review',$contextTaskRef);
$taskContextRun = end($taskContextRuns);
$taskContextModel = static fn(): string => json_encode([
    'title'=>'Tasks decision','summary'=>'The rehearsal task references the vendor email.',
    'next_step'=>'Confirm the rehearsal task.',
    'question'=>'Should the rehearsal task be rescheduled?','state'=>'needs_owner','refs'=>[$contextTaskRef,$gmailRef],
],JSON_THROW_ON_ERROR);
$taskReview = vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($taskContextRun['id']),$taskContextModel);
$taskContextReceipt = coordination_fixture_receipt($taskContextRun['id']);
canvas_pipeline_check($taskReview['ok']&&$taskContextReceipt['initiative_delivery']==='delivered'
    && $taskContextReceipt['initiative_tool_result']['output']['evidence_revisions']===$contextRevisions,
    'a posted Tasks decision uses the exact Gmail finding as supporting context');
$gmailDebug = static fn(array $binding):array => agent_connection_gmail_diagnostics_active_delivery_v1($pdo,$owner,$binding);
$unassigned = $gmailDebug($contextBinding);
canvas_pipeline_check($unassigned[2]==='gmail_handoff_unassigned' && str_contains($unassigned[1],'No current Chat handoff'),
    'Gmail Debug does not mistake the supporting context in a posted Tasks question for a Gmail handoff');

$resetScan();
$contextResult = vp3_initiative_pump_v1($pdo,$owner,$agentId,$contextChat,$contextBinding['revision'],$warmModel);
$contextGmail = array_values(array_filter(coordination_fixture_runs('coordination.review',$gmailRef),
    static fn(array $run): bool => $run['goal']['binding']===$contextBinding));
$contextReceipt = count($contextGmail)===1 && $contextGmail[0]['status']==='completed'
    ? coordination_fixture_receipt($contextGmail[0]['id']) : [];
canvas_pipeline_check($contextResult['ok']&&($contextResult['coordination']??null)==='queued_chat_handoff'
    && ($contextReceipt['initiative_delivery']??null)==='delivered'
    && $contextReceipt['initiative_tool_result']['output']['evidence_revisions']===$contextRevisions
    && $contextReceipt['initiative_tool_result']['output']['situation_id']!==$taskContextGoal['coordination_id']
    && canvas_pipeline_count('chat_messages','conversation_id=17')===2,
    'a previously posted Tasks question with identical evidence does not consume Gmail’s separate Ask rule or situation');
$delivered = $gmailDebug($contextBinding);
canvas_pipeline_check($delivered[0]==='complete' && $delivered[2]==='gmail_handoff_delivered',
    'Gmail Debug reports delivery only for the verified Gmail primary post in that Chat');

$resetScan();
vp3_initiative_schedule_coordination_v1($pdo,$owner,$contextBinding,true,$scanOutcome);
canvas_pipeline_check($scanOutcome==='handoff_already_handled' && canvas_pipeline_count('chat_messages','conversation_id=17')===2
    && count(array_filter(coordination_fixture_runs('coordination.review',$gmailRef),
        static fn(array $run): bool => $run['goal']['binding']===$contextBinding))===1,
    'the exact Gmail handoff still deduplicates after a separate Tasks question');
$otherChat = 18;
$pdo->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES(18,1,7,'Reopened Agent Chat')");
$otherBinding = vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>$otherChat],'on',null)['initiative_policy'];
coordination_fixture_presence('quiet',$otherChat);
$handled = $gmailDebug($otherBinding);
canvas_pipeline_check($handled[0]==='complete' && $handled[2]==='gmail_handoff_already_handled'
    && canvas_pipeline_count('chat_messages','conversation_id=18')===0,
    'Gmail Debug distinguishes a matching handoff already delivered in another Chat from one posted here');

$historyChat = 19;
$pdo->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES(19,1,7,'Busy report history')");
$historyBinding = vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>$historyChat],'on',null)['initiative_policy'];
coordination_fixture_presence('quiet',$historyChat);
$pdo->exec("UPDATE agent_memory_items SET is_active=0 WHERE user_id=1 AND id=860");
coordination_fixture_gmail_observe($pdo,$owner,10,'The vendor moved delivery to the morning of the launch rehearsal.');
$event->execute([900,1,'Vendor launch rehearsal',gmdate('Y-m-d H:i:s',time()+86400),gmdate('Y-m-d H:i:s',time()+90000)]);
$historyContextRef = 'planning:calendar:event:900';
$historyRules = vp3_coordination_rules_v1($pdo,$owner,$agentId);
$recordReview = static function(array $refs,string $primary) use ($pdo,$owner,$historyBinding,$historyRules,$privateGoal): array {
    $snapshot = vp3_coordination_sources_v1($pdo,$owner,$historyBinding,$refs);
    $revisions = array_column($snapshot['items'],'revision','ref');
    $routes = array_map(static fn(array $item):array=>vp3_coordination_item_route_v1($historyRules,$item),$snapshot['items']);
    ksort($revisions);
    $situation = vp3_coordination_scan_id_v1($owner,$historyBinding,$primary);
    vp3_initiative_enqueue_v1($pdo,$owner,'initiative.prepare',array_replace($privateGoal,[
        'binding'=>$historyBinding,'coordination_id'=>$situation,'primary'=>$primary,'sources'=>$revisions,
        'coordination_route'=>$routes[$primary],'coordination_routes'=>$routes,
        'coordination_coverage'=>vp3_coordination_scan_coverage_v1(vp3_coordination_sources_v1($pdo,$owner,$historyBinding)['coverage']),
    ]),hash('sha256','history-replay-test-'.$primary));
    $run = vp3_coordination_runs_v1($pdo,$owner,$historyBinding,$situation,1)[0];
    $asking = $routes[$primary]['action']==='ask';
    $result = vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim((int)$run['id']),static fn():string=>json_encode([
        'title'=>'Review current schedule evidence','summary'=>'Review linked schedule reports.',
        'next_step'=>'Check the timing and confirm the related work.',
        'question'=>$asking?'Can the vendor deliver before rehearsal?':'', 'state'=>$asking?'needs_owner':'watching','refs'=>$refs,
    ],JSON_THROW_ON_ERROR));
    $receipt = coordination_fixture_receipt((int)$run['id']);
    canvas_pipeline_check(!empty($result['ok']) && $receipt['initiative_tool_result']['output']['evidence_revisions']===$revisions,
        'a completed historical review has a verified receipt covering all its original evidence');
    return $run;
};
$historyGmail = $recordReview([$gmailRef,$historyContextRef],$gmailRef);
for($group=0;$group<3;$group++){
    $refs=[];
    for($offset=0;$offset<4;$offset++){
        $id=910+$group*4+$offset;
        $start=time()+108000+($id-910)*3600;
        $event->execute([$id,1,'Planning agenda '.$id,gmdate('Y-m-d H:i:s',$start),gmdate('Y-m-d H:i:s',$start+1800)]);
        $refs[]='planning:calendar:event:'.$id;
    }
    $recordReview($refs,$refs[0]);
}
$pdo->exec('DELETE FROM user_calendar_events WHERE id BETWEEN 910 AND 921');
// Keep the original question's evidence valid, but outside bounded discovery.
// Deleted supporting evidence correctly makes a posted handoff repairable.
for($id=950;$id<958;$id++){
    $start=time()+3600+($id-950)*3600;
    $event->execute([$id,1,'Unrelated agenda '.$id,gmdate('Y-m-d H:i:s',$start),gmdate('Y-m-d H:i:s',$start+1800)]);
}
$initialHistoryMessages = canvas_pipeline_count('chat_messages','conversation_id=19');
$resetScan();
vp3_initiative_schedule_coordination_v1($pdo,$owner,$historyBinding,true,$scanOutcome);
canvas_pipeline_check($scanOutcome==='handoff_already_handled' && $initialHistoryMessages===1
    && canvas_pipeline_count('chat_messages','conversation_id=19')===$initialHistoryMessages
    && count(vp3_coordination_runs_v1($pdo,$owner,$historyBinding,null,36,'gmail'))===1,
    'an unchanged previously delivered Gmail finding does not repeat when optional history remains unchecked');
$newHistorySummary = 'The vendor now needs a response before the launch rehearsal.';
coordination_fixture_gmail_observe($pdo,$owner,11,$newHistorySummary);
$baselineRows = vp3_coordination_runs_v1($pdo,$owner,$historyBinding);
$historyBaselines = array_values(array_filter(array_map(static fn(array $row):?array=>vp3_coordination_scan_baseline_v1($pdo,$owner,$row),$baselineRows)));
$historyItems = vp3_coordination_scan_items_v1(vp3_coordination_sources_v1($pdo,$owner,$historyBinding));
[, $historyChecked] = vp3_coordination_scan_recover_refs_v1($pdo,$owner,$historyBinding,$historyItems,$historyBaselines);
canvas_pipeline_check(count($historyChecked)===10 && !isset($historyChecked[$historyContextRef])
    && $gmailDebug($historyBinding)[2]==='gmail_handoff_unassigned',
    'newer reports exhaust the history recheck budget while a changed verified Gmail finding has no assignment');
$resetScan();
// A completed specialist result wakes discovery, invalidating the admission
// checkpoint. Inspect its safe counters before the ordinary pump executes it.
vp3_initiative_schedule_coordination_v1($pdo,$owner,$historyBinding,true,$historyOutcome);
$historyCheckpoint = vp3_coordination_scan_checkpoint_v1($owner,$historyBinding);
$historyMailbox = $historyCheckpoint['specialists']['gmail']??[];
$historyTick = vp3_initiative_pump_v1($pdo,$owner,$agentId,$historyChat,$historyBinding['revision'],$warmModel);
$historyRun = vp3_coordination_runs_v1($pdo,$owner,$historyBinding,null,1,'gmail')[0];
$historyReceipt = coordination_fixture_receipt((int)$historyRun['id']);
canvas_pipeline_check($historyOutcome==='queued_chat_handoff' && $historyTick['ok'] && $historyReceipt['initiative_delivery']==='delivered'
    && (int)$historyRun['id']!==(int)$historyGmail['id']
    && $historyReceipt['initiative_tool_result']['output']['situation_id']===json_decode($historyGmail['goal'],true)['coordination_id']
    && $historyReceipt['initiative_tool_result']['output']['summary']===$newHistorySummary
    && !isset($historyReceipt['initiative_tool_result']['output']['evidence_revisions'][$historyContextRef]),
    'the current Gmail question reaches Chat under the original situation when older optional context cannot be checked');
canvas_pipeline_check($historyMailbox['found']===1 && $historyMailbox['chat_matches']===1 && $historyMailbox['candidate_count']===1
    && $historyMailbox['queued']===1 && $historyMailbox['already_handled']===0
    && !str_contains(json_encode($historyCheckpoint,JSON_THROW_ON_ERROR),$gmailRef)
    && vp3_coordination_scan_checkpoint_v1($owner,$otherBinding)===null
    && vp3_coordination_scan_checkpoint_v1($owner,$historyBinding)===null,
    'the safe admission checkpoint is scoped to the exact Chat policy and wakes again after verified completion');
$pdo->exec('DELETE FROM user_calendar_events WHERE id=900 OR id BETWEEN 950 AND 957');

$currentMessageId = (int)$historyReceipt['initiative_message_id'];
$currentQuestion = $historyReceipt['initiative_tool_result']['output']['question'];
$event->execute([930,1,'Vendor launch rehearsal',gmdate('Y-m-d H:i:s',time()+86400),gmdate('Y-m-d H:i:s',time()+90000)]);
$newContextRef = 'planning:calendar:event:930';
$withContext = vp3_coordination_sources_v1($pdo,$owner,$historyBinding,[$gmailRef,$newContextRef])['items'];
$withContextRevisions = array_column($withContext,'revision','ref');
ksort($withContextRevisions);
$sameDecisionGoal = array_replace(json_decode($historyRun['goal'],true,32,JSON_THROW_ON_ERROR),[
    'sources'=>$withContextRevisions,'coordination_saved'=>false,
    'coordination_routes'=>array_map(static fn(array $item):array=>vp3_coordination_item_route_v1($historyRules,$item),$withContext),
]);
$startReview = static function(string $question,string $key) use ($pdo,$owner,$historyBinding,$sameDecisionGoal,$withContextRevisions): array {
    vp3_initiative_enqueue_v1($pdo,$owner,'initiative.prepare',$sameDecisionGoal,hash('sha256',$key));
    $run = vp3_coordination_runs_v1($pdo,$owner,$historyBinding,$sameDecisionGoal['coordination_id'],1)[0];
    $result = vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim((int)$run['id']),static fn():string=>json_encode([
        'title'=>'Review the vendor delivery','summary'=>'The vendor response affects the launch rehearsal.',
        'next_step'=>'Check the rehearsal timing.','question'=>$question,'state'=>'needs_owner','refs'=>array_keys($withContextRevisions),
    ],JSON_THROW_ON_ERROR));
    canvas_pipeline_check($result['ok'],'the shared scheduler verifies the new review');
    return coordination_fixture_receipt((int)$run['id']);
};
$pdo->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=19");
$duplicateContext = $startReview($currentQuestion,'same-question-with-new-context');
$currentOrb = array_column(vp3_coordination_visible_v1($pdo,$owner,$agentId,$historyChat),null,'id')[$sameDecisionGoal['coordination_id']];
canvas_pipeline_check($duplicateContext['initiative_delivery']==='private' && $duplicateContext['initiative_delivery_reason']==='duplicate'
    && $duplicateContext['initiative_tool_result']['output']['evidence_revisions']===$withContextRevisions
    && canvas_pipeline_count('chat_messages','conversation_id=19')===2
    && $currentOrb['delivery']==='delivered' && $currentOrb['message_id']===$currentMessageId
    && $currentOrb['question']===$currentQuestion && count($currentOrb['sources'])===2,
    'related evidence refreshes Top of Mind and keeps the verified original Chat question without reposting it');
$newDecision = $startReview('Should the launch rehearsal move to accommodate the vendor?','new-question-with-same-context');
canvas_pipeline_check($newDecision['initiative_delivery']==='delivered' && (int)$newDecision['initiative_message_id']!==$currentMessageId
    && canvas_pipeline_count('chat_messages','conversation_id=19')===3,
    'a materially different owner question may reach the same Chat even with unchanged source revisions');

// Move an untouched question beyond the newest six reports and the first older page.
vp3_casual_checkin_save_v1($pdo,1,false);
coordination_fixture_rule($pdo,$owner,'gmail','needs_attention','watch');
coordination_fixture_rule($pdo,$owner,'tasks','waiting_owner','ask');
coordination_fixture_presence('quiet',$historyChat);
$taskInsert = $pdo->prepare("INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,subject,memory_text,memory_hash,
    memory_scope_version,confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json)
    VALUES(?,1,7,'task',?,'Write the fixture checklist for a vendor pickup.',?,410,.95,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,1,?)");
$scope = vp3_initiative_tools_scope_v1($pdo,$owner,$historyBinding);
$oldOfficeRef = 'task:1300';
$oldOfficeQuestion = 'Which pickup window should I put on the checklist?';
$officeQuestions = [1300=>$oldOfficeQuestion,1301=>'Which checklist goes in the pickup packet?',
    1304=>'Where should the pickup packet be stored?',1310=>'Should I use the afternoon pickup window?'];
for ($id=1300;$id<=1310;$id++) {
    $ref = 'task:'.$id;
    $taskInsert->execute([$id,'Prepare pickup checklist '.$id,'older-office-discovery-'.$id,
        json_encode(['task_status'=>'open','source_kind'=>'explicit_user'],JSON_THROW_ON_ERROR)]);
    $source = vp3_initiative_office_entry_source_v1($pdo,$scope,$historyBinding,$ref);
    $work = ['binding'=>$historyBinding,'sources'=>[$ref=>$source['revision']],'primary'=>$ref,'tool'=>'tasks.read',
        'focus'=>'Review the pickup checklist.','report'=>true,'office_entry'=>true];
    vp3_initiative_enqueue_v1($pdo,$owner,'initiative.prepare',$work,hash('sha256','older-office-discovery-'.$id));
    $reportRun = coordination_fixture_runs('tasks.read',$ref)[0];
    $question = $officeQuestions[$id] ?? '';
    $finished = vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($reportRun['id']),static fn():string=>json_encode([
        'office_disposition'=>'review','draft'=>'The vendor needs a pickup checklist.', 'next_step'=>'Write down the window.',
        'followup_question'=>$question,'action_plan'=>['Confirm the pickup plan.'],'owner_attention'=>false,
    ],JSON_THROW_ON_ERROR));
    if (empty($finished['ok'])) throw new RuntimeException('Could not prepare an actual Tasks report.');
}
$officeRef = 'office:'.$oldOfficeRef;
canvas_pipeline_check(!isset(vp3_coordination_sources_v1($pdo,$owner,$historyBinding)['items'][$officeRef])
    && vp3_coordination_sources_v1($pdo,$owner,$historyBinding,[$officeRef])['items'][$officeRef]['question']===$oldOfficeQuestion,
    'the older question is verified but lies beyond the newest report window');
$officeBeforeChat = canvas_pipeline_count('chat_messages','conversation_id=19');
for ($scan=0;$scan<7;$scan++) {
    // Simulate the same checkpoint invalidation as a new Gmail finding.
    $resetScan();
    vp3_initiative_pump_v1($pdo,$owner,$agentId,$historyChat,$historyBinding['revision'],$model);
}
$olderHandoffs = coordination_fixture_runs('coordination.review',$officeRef);
$newerHandoffs = coordination_fixture_runs('coordination.review','office:task:1310');
canvas_pipeline_check(count($newerHandoffs)===1 && count($olderHandoffs)===1
    && canvas_pipeline_result($olderHandoffs[0]['id'])['initiative_delivery']==='delivered'
    && canvas_pipeline_result($olderHandoffs[0]['id'])['initiative_tool_result']['output']['question']===$oldOfficeQuestion
    && count(coordination_fixture_runs('coordination.review','office:task:1301'))===1
    && count(coordination_fixture_runs('coordination.review','office:task:1304'))===1
    && canvas_pipeline_count('chat_messages','conversation_id=19')===$officeBeforeChat+count($officeQuestions) && $inferenceCalls===0,
    'bounded scans reach an untouched older Tasks question once without replaying the newer delivered question or running inference');

$twoOpenChat = 21;
$pdo->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES(21,1,7,'Open questions across specialists')");
$twoOpenPolicy = vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>$twoOpenChat],'on',null)['initiative_policy'];
coordination_fixture_presence('quiet',$twoOpenChat);
coordination_fixture_rule($pdo,$owner,'tasks','waiting_owner','watch');
coordination_fixture_rule($pdo,$owner,'gmail','needs_attention','ask');
coordination_fixture_gmail_observe($pdo,$owner,12,'The vendor needs the new delivery window confirmed.');
$resetScan();
vp3_initiative_pump_v1($pdo,$owner,$agentId,$twoOpenChat,$twoOpenPolicy['revision'],$model);
$mailRun = vp3_coordination_runs_v1($pdo,$owner,$twoOpenPolicy,null,1,'gmail')[0];
$mailReceipt = coordination_fixture_receipt((int)$mailRun['id']);
$mailPostId = (int)$mailReceipt['initiative_message_id'];
$openState = static fn(int $id): ?string => json_decode(coordination_fixture_message($id)['context_json'],true)['initiative_work']['followthrough']['state']??null;
canvas_pipeline_check($mailReceipt['initiative_delivery']==='delivered' && $openState($mailPostId)==='open',
    'the original verified Gmail question remains open without an owner reply');

$start = (new DateTimeImmutable('now',new DateTimeZone('UTC')))->modify('+3 days')->setTime(18,0);
$event->execute([940,1,'Piano lesson',$start->format('Y-m-d H:i:s'),$start->modify('+1 hour')->format('Y-m-d H:i:s')]);
$event->execute([941,1,'Ceramics class',$start->modify('+30 minutes')->format('Y-m-d H:i:s'),$start->modify('+90 minutes')->format('Y-m-d H:i:s')]);
$resetScan();
$secondTick = vp3_initiative_pump_v1($pdo,$owner,$agentId,$twoOpenChat,$twoOpenPolicy['revision'],$model);
$secondRun = vp3_coordination_runs_v1($pdo,$owner,$twoOpenPolicy,null,1,'planning')[0];
$secondReceipt = coordination_fixture_receipt((int)$secondRun['id']);
canvas_pipeline_check(($secondTick['coordination']??null)==='queued_chat_handoff'
    && $secondReceipt['initiative_delivery']==='delivered' && $openState($mailPostId)==='open'
    && canvas_pipeline_count('chat_messages','conversation_id=21')===2,
    'Planning posts its verified conflict while the independent Gmail question still awaits a reply');

$pdo->prepare("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE id=?")->execute([$mailPostId]);
vp3_initiative_pump_v1($pdo,$owner,$agentId,$twoOpenChat,$twoOpenPolicy['revision'],$model);
$secondReceipt = coordination_fixture_receipt((int)$secondRun['id']);
$secondPostId = (int)$secondReceipt['initiative_message_id'];
$resetScan();
vp3_initiative_pump_v1($pdo,$owner,$agentId,$twoOpenChat,$twoOpenPolicy['revision'],$model);
canvas_pipeline_check($secondReceipt['initiative_delivery']==='delivered' && $secondPostId!==$mailPostId
    && $openState($mailPostId)==='open' && $openState($secondPostId)==='open' && $inferenceCalls===0
    && canvas_pipeline_count('chat_messages','conversation_id=21')===2,
    'later polls retain both independent open questions without replaying either handoff');

$starterChat = 22;
$pdo->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES(22,1,7,'Try a specialist');");
$starterBinding = vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>$starterChat],'on',null)['initiative_policy'];
coordination_fixture_rule($pdo,$owner,'gmail','needs_attention','watch');
coordination_fixture_rule($pdo,$owner,'planning','conflict','watch');
vp3_casual_checkin_save_v1($pdo,1,false);
$GLOBALS['pipelineRuntimePlan'] = ['route'=>'vp3_cloud','blocked'=>true,'effective_preference'=>'vp3_cloud',
    'cloud'=>['ready'=>false,'reason'=>'cloud_balance_exhausted'],'home'=>['supported'=>false]];
$starterTurn = static function(string $context='{}') use ($pdo,$starterChat): void {
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES
        (?,1,'user','Continue the setup',?),(?,NULL,'assistant','Agent Chat is ready.','{}')")
        ->execute([$starterChat,$context,$starterChat]);
};
$starterRuns = static fn(): array => array_values(array_filter(coordination_fixture_runs('coordination.review'),
    static fn(array $run): bool => vp3_casual_starter_specialist_v1($run['goal']['primary'])!==null));
$tickStarter = static function() use ($pdo,$owner,$agentId,$starterChat,$starterBinding,$model,$resetScan): array {
    $resetScan();
    return vp3_initiative_pump_v1($pdo,$owner,$agentId,$starterChat,$starterBinding['revision'],$model);
};
$starterTurn('{"initiative_reply_anchor_v1":{"test":true}}');
coordination_fixture_presence('quiet',$starterChat);
$tickStarter();
canvas_pipeline_check($starterRuns()===[],'an exact response to existing work does not unlock specialist onboarding');
$starterTurn();
coordination_fixture_presence('busy',$starterChat);
$tickStarter();
canvas_pipeline_check($starterRuns()===[],'starter onboarding waits for a quiet focused Chat');
coordination_fixture_presence('quiet',$starterChat);
$tickStarter();
$runStarter = $starterRuns()[0]??null;
$starterResult = $runStarter!==null?coordination_fixture_receipt($runStarter['id']):[];
$starterId = (int)($starterResult['initiative_message_id']??0);
$kindStarter = substr($runStarter['goal']['primary']??'',strlen('casual:'));
$definition = VP3_SPECIALIST_STARTERS_V1[$kindStarter]??null;
canvas_pipeline_check(count($starterRuns())===1 && ($starterResult['initiative_delivery']??null)==='delivered'
    && $definition!==null && $openState($starterId)==='open'
    && coordination_fixture_message($starterId)['message']===$definition['question'] && $inferenceCalls===0,
    'an enabled Initiative posts a saved starter question with a receipt through the usual worker during a provider outage');
$tickStarter();
$secondStarter = $starterRuns()[1]??null;
$secondStarterResult = $secondStarter!==null?coordination_fixture_receipt($secondStarter['id']):[];
$secondStarterId = (int)($secondStarterResult['initiative_message_id']??0);
canvas_pipeline_check(count($starterRuns())===2 && $secondStarter['goal']['primary']!==$runStarter['goal']['primary']
    && ($secondStarterResult['initiative_delivery']??null)==='delivered'
    && $openState($starterId)==='open' && $openState($secondStarterId)==='open',
    'a distinct eligible starter can advance while the original remains open without repeating its specialist');
$starterState = json_decode(coordination_fixture_message($starterId)['context_json'],true)['initiative_work']['followthrough'];
$starterReply = vp3_agent_chat_send_v2160($pdo,$owner,vp3_agent_chat_resolve_agent_v380($pdo,$owner,$agentId),$principal,
    ['message'=>'yes','conversation_id'=>$starterChat,'initiative_reply'=>[
        'message_id'=>$starterId,'reply_ref'=>$starterState['reply_ref'],'revision'=>$starterState['revision']]],true,null,true);
$exampleAction=['type'=>'specialist_example','specialist'=>$definition['specialist'],'label'=>$definition['label']];
$acceptedRow=coordination_fixture_message($starterReply['assistant_message_id']);
$historyRow=vp3_coordination_chat_history_v1($pdo,$owner,$agentId,$starterChat,[$acceptedRow])[0];
canvas_pipeline_check(($starterReply['initiative_followthrough']['state']??null)==='owner_resolved'
    && $openState($starterId)==='owner_resolved' && $openState($secondStarterId)==='open'
    && ($starterReply['actions']??null)===[$exampleAction]
    && (json_decode($historyRow['context_json'],true)['actions']??null)===[$exampleAction]
    && $inferenceCalls===0,'accepting an explicitly selected starter resolves only its original question and preserves the example action on reload');
canvas_pipeline_check(!vp3_casual_checkin_allowed_v1($pdo,$owner,$starterChat,$kindStarter)
    && !vp3_casual_checkin_delivery_ready_v1($pdo,$owner,$starterChat,$kindStarter),
    'answering a starter cannot reopen that specialist’s one-time offer');

vp3_casual_checkin_save_v1($pdo,1,true);
putenv('VP3_GMAIL_REDIRECT_URI=https://example.test/connection-oauth.php');
$pdo->exec("ALTER TABLE agent_connections_v1 ADD COLUMN send_enabled INTEGER NOT NULL DEFAULT 1;");
$pdo->exec("ALTER TABLE agent_connections_v1 ADD COLUMN followups_enabled INTEGER NOT NULL DEFAULT 0;");
$pdo->exec("ALTER TABLE agent_connections_v1 ADD COLUMN account_email VARCHAR(254) NOT NULL DEFAULT 'sample@example.test';");
// The contextual fallback must prove that the real draft queue is empty. A
// one-column readiness placeholder cannot establish that after grounded offers.
if ($mysql) $pdo->exec('ALTER TABLE chat_conversations MODIFY id BIGINT UNSIGNED NOT NULL');
if (!column_exists('chat_conversations','title')) $pdo->exec("ALTER TABLE chat_conversations ADD COLUMN title VARCHAR(190) DEFAULT ''");
agent_connection_email_draft_schema_v1($pdo);
$pdo->exec('CREATE TABLE agent_connection_email_followups_v1(claim_key CHAR(64));');
$pdo->exec('CREATE TABLE agent_connection_sends_v1(operation_key VARCHAR(64));');
$contextualScope=vp3_agent_activity_scope_v1($pdo,$owner,$agentId,$starterChat);
canvas_pipeline_check(vp3_specialist_invitation_email_v1($pdo,$contextualScope,$starterBinding)['state']==='empty',
    'the native draft queue proves an empty mailbox before a generic contextual compose invitation');
$emailCount = count(coordination_fixture_runs('coordination.review','casual:email'));
$starterTurn();
$tickStarter();
$normalRuns = coordination_fixture_runs('coordination.review','casual:email');
$latestNormal = end($normalRuns);
canvas_pipeline_check(count($starterRuns())===2 && count($normalRuns)===$emailCount+1
    && coordination_fixture_receipt($latestNormal['id'])['initiative_delivery']==='delivered',
    'an eligible contextual check-in follows the starter after fresh ordinary activity');
$normalReply = vp3_agent_chat_send_v2160($pdo,$owner,vp3_agent_chat_resolve_agent_v380($pdo,$owner,$agentId),$principal,
    ['message'=>'No, thanks.','conversation_id'=>$starterChat],true,null,true);
canvas_pipeline_check(($normalReply['initiative_followthrough']['state']??null)==='declined',
    'the interleaved contextual check-in uses the original exact-response path');
$starterTurn();
$tickStarter();
canvas_pipeline_check(count($starterRuns())===3 && count($starterRuns())===count(array_unique(array_column(
    array_column($starterRuns(),'goal'),'primary'))) && count(coordination_fixture_runs('coordination.review','casual:email'))===$emailCount+1,
    'another ordinary owner turn gives a different starter a turn ahead of repetitive contextual check-ins');
unset($GLOBALS['pipelineRuntimePlan']);

// A duplicate starter is not evidence of a failed attempt. Exercise real PDO
// no-row results and canonical job transitions in a separate owner's Chats.
$retryOwner = ['id'=>2,'is_active'=>1];
$retryAgent = 9;
$retryPrincipal = vp3_agent_chat_principal_v380($retryOwner,
    vp3_agent_chat_resolve_agent_v380($pdo,$retryOwner,$retryAgent));
vp3_casual_checkin_save_v1($pdo,2,false);
$ownerRunsBeforeRetry = canvas_pipeline_count('agent_workflow_runs','owner_user_id=1');
$retryBinding = static function(int $cid) use ($pdo,$retryOwner,$retryAgent,$retryPrincipal): array {
    $pdo->prepare('INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES(?,2,9,?)')
        ->execute([$cid,'Starter retry regression']);
    $policy = vp3_initiative_chat_v1($pdo,$retryOwner,$retryPrincipal,['conversation_id'=>$cid],'on',null)['initiative_policy'];
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES
        (?,2,'user','Show me what my Agent can do.','{}'),(?,NULL,'assistant','Your private Chat is ready.','{}')")
        ->execute([$cid,$cid]);
    $pdo->exec('DELETE FROM agent_activity_state WHERE user_id=2');
    $pdo->prepare("INSERT INTO agent_activity_state VALUES(2,'chat',?,'idle',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,?)")
        ->execute(['chat:'.$cid,json_encode(['visible'=>true,'conversation_id'=>$cid,'chat_focused'=>true,'chat_busy'=>false],JSON_THROW_ON_ERROR)]);
    return $policy;
};
$admitRetryStarter = static function(array $policy) use ($pdo,$retryOwner,$retryAgent): ?string {
    return vp3_initiative_in_scope_v1($retryOwner,$retryAgent,static function() use ($pdo,$retryOwner,$policy): ?string {
        return agent_commerce_atomic_pa2($pdo,static function() use ($pdo,$retryOwner,$policy): ?string {
            $discovery = [];
            return vp3_coordination_admit_casual_v1($pdo,$retryOwner,$policy,[],[],$discovery);
        });
    });
};
$retryImageRuns = static function(array $policy) use ($pdo): array {
    $read = $pdo->query("SELECT * FROM agent_workflow_runs WHERE owner_user_id=2 AND capability_key='initiative.prepare' ORDER BY id");
    return array_values(array_filter($read->fetchAll(),static function(array $run) use ($policy): bool {
        $goal = json_decode($run['goal'],true,32,JSON_THROW_ON_ERROR);
        return ($goal['primary']??null)==='casual:starter-image' && ($goal['binding']??null)===$policy;
    }));
};
$claimRetryStarter = static fn(int $run): array => agent_job_claim_run_v1900($pdo,$retryOwner,$run,'cloud',
    agent_worker_cloud_worker_id_v1910(2),180) ?: throw new RuntimeException('Could not claim the actual retry-regression starter.');
$noFailedStarter = static function(array $run) use ($pdo): bool {
    $read = $pdo->prepare("SELECT updated_at FROM agent_workflow_runs WHERE owner_user_id=? AND dedupe_key=? AND status='failed'");
    $read->execute([(int)$run['owner_user_id'],$run['dedupe_key']]);
    return $read->fetchColumn()===false;
};

$approvedPolicy = $retryBinding(23);
canvas_pipeline_check($admitRetryStarter($approvedPolicy)==='queued_checkin' && count($retryImageRuns($approvedPolicy))===1,
    'a real starter is admitted for the second owner’s exact Agent and private Chat');
$approvedStarter = $retryImageRuns($approvedPolicy)[0];
canvas_pipeline_check($approvedStarter['status']==='approved' && $noFailedStarter($approvedStarter),
    'an approved original starter has no matching failed row in actual PDO');
$admitRetryStarter($approvedPolicy);
canvas_pipeline_check(count($retryImageRuns($approvedPolicy))===1 && $retryImageRuns($approvedPolicy)[0]===$approvedStarter,
    'repeated admission does not throw or retry an approved exact starter while another specialist may be eligible');
$completedStarter = vp3_initiative_execute_v1($pdo,$retryOwner,$claimRetryStarter((int)$approvedStarter['id']),$model,null,false);
$completedReceipt = coordination_fixture_receipt((int)$approvedStarter['id']);
$completedOriginal = $retryImageRuns($approvedPolicy)[0];
canvas_pipeline_check(!empty($completedStarter['ok']) && $completedOriginal['status']==='completed'
    && $completedReceipt['initiative_delivery']==='pending' && $noFailedStarter($completedOriginal),
    'the actual starter worker completes with a bound private receipt and still has no failed row');
$admitRetryStarter($approvedPolicy);
canvas_pipeline_check(count($retryImageRuns($approvedPolicy))===1
    && $retryImageRuns($approvedPolicy)[0]===$completedOriginal && canvas_pipeline_count('chat_messages','conversation_id=23')===2,
    'repeated admission neither retries nor posts a completed exact starter');

$failedPolicy = $retryBinding(24);
$admitRetryStarter($failedPolicy);
$failedStarter = $retryImageRuns($failedPolicy)[0];
$failedClaim = $claimRetryStarter((int)$failedStarter['id']);
$failure = agent_worker_cloud_result_v1910($pdo,$retryOwner,$failedClaim,false,'Starter transport unavailable.',
    ['effect_state'=>'not_executed'],'fixture_transport_unavailable',false);
$failedReceipt = $pdo->prepare('SELECT * FROM agent_workflow_receipts WHERE id=?');
$failedReceipt->execute([$failure['receipt_id']]);
$failedReceipt = $failedReceipt->fetch();
canvas_pipeline_check($retryImageRuns($failedPolicy)[0]['status']==='failed' && $failedReceipt['status']==='failed'
    && (int)$failedReceipt['run_id']===(int)$failedStarter['id']
    && (int)$failedReceipt['action_id']===(int)$failedClaim['action']['id']
    && (int)$failedReceipt['attempt_no']===(int)$failedClaim['action']['attempt_count'],
    'a real leased worker failure creates the exact failed attempt receipt before recovery is considered');
// Only elapsed time is controlled: statuses, claims, results and dedupes above
// and below are produced by the real runtime, not seeded success/failure rows.
$pdo->prepare('UPDATE agent_workflow_runs SET updated_at=? WHERE id=? AND owner_user_id=2')
    ->execute([gmdate('Y-m-d H:i:s',time()-30),$failedStarter['id']]);
$admitRetryStarter($failedPolicy);
canvas_pipeline_check(count($retryImageRuns($failedPolicy))===1 && $retryImageRuns($failedPolicy)[0]['status']==='failed',
    'a recent failed starter remains unretried during the recovery cooldown');
$pdo->prepare('UPDATE agent_workflow_runs SET updated_at=? WHERE id=? AND owner_user_id=2')
    ->execute([gmdate('Y-m-d H:i:s',time()-180),$failedStarter['id']]);
canvas_pipeline_check($admitRetryStarter($failedPolicy)==='queued_checkin' && count($retryImageRuns($failedPolicy))===2,
    'a retry-eligible failed starter receives exactly one canonical recovery run');
[$failedOriginal,$recoveryStarter] = $retryImageRuns($failedPolicy);
canvas_pipeline_check($failedOriginal['status']==='failed' && $recoveryStarter['status']==='approved'
    && $failedOriginal['goal']===$recoveryStarter['goal'] && (int)$recoveryStarter['agent_id']===$retryAgent
    && $failedOriginal['dedupe_key']!==$recoveryStarter['dedupe_key']
    && json_decode($recoveryStarter['goal'],true,32,JSON_THROW_ON_ERROR)['binding']===$failedPolicy,
    'the one recovery has its own dedupe while retaining the exact authorized owner, Agent, Chat and source payload');
$admitRetryStarter($failedPolicy);
$admitRetryStarter($failedPolicy);
canvas_pipeline_check(count($retryImageRuns($failedPolicy))===2
    && $retryImageRuns($failedPolicy)[0]===$failedOriginal && $retryImageRuns($failedPolicy)[1]===$recoveryStarter
    && canvas_pipeline_count('chat_messages','conversation_id=24')===2,
    'repeated admission cannot multiply the recovery or rewrite the original failed work');

$cancelledPolicy = $retryBinding(25);
$admitRetryStarter($cancelledPolicy);
$cancelledStarter = $retryImageRuns($cancelledPolicy)[0];
agent_job_cancel_v1900($pdo,$retryOwner,(int)$cancelledStarter['id']);
$cancelledOriginal = $retryImageRuns($cancelledPolicy)[0];
canvas_pipeline_check($cancelledOriginal['status']==='cancelled' && $noFailedStarter($cancelledOriginal),
    'an owner-cancelled starter is distinct from a failed attempt');
$admitRetryStarter($cancelledPolicy);
canvas_pipeline_check(count($retryImageRuns($cancelledPolicy))===1
    && $retryImageRuns($cancelledPolicy)[0]===$cancelledOriginal
    && canvas_pipeline_count('agent_workflow_runs','owner_user_id=1')===$ownerRunsBeforeRetry && $inferenceCalls===0,
    'cancelled work is never recovered and the retry scenarios leave the other owner’s work unchanged without inference');

echo 'COORDINATION_ROUTING_PIPELINE=PASS ('.$checks.' assertions, '.($mysql ? 'mysql' : 'sqlite').")\n";
