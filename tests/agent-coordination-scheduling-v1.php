<?php
declare(strict_types=1);

define('VP3_EMAIL_CANVAS_PIPELINE_FIXTURE', true);
require __DIR__.'/agent-initiative-canvas-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-sources-v1.php';
require_once dirname(__DIR__).'/includes/agent-activity-hub-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-model-v1.php';

$pdo->exec('CREATE TABLE agent_scheduling_schedules (id INTEGER PRIMARY KEY,owner_user_id INTEGER,
    agent_id INTEGER,name VARCHAR(100),is_active INTEGER,is_default INTEGER)');
$pdo->exec('CREATE TABLE agent_scheduling_event_types (id INTEGER PRIMARY KEY,minimum_notice_minutes INTEGER)');
$pdo->exec('CREATE TABLE agent_scheduling_availability (id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE agent_scheduling_overrides (id INTEGER PRIMARY KEY)');
$pdo->exec('CREATE TABLE agent_scheduling_bookings (id INTEGER PRIMARY KEY,owner_user_id INTEGER,
    schedule_id INTEGER,agent_id INTEGER,event_title VARCHAR(190),start_at_utc DATETIME,end_at_utc DATETIME,
    organizer_timezone VARCHAR(80),status VARCHAR(24),lifecycle_status VARCHAR(24),public_token CHAR(64),
    buffer_before_minutes INTEGER,buffer_after_minutes INTEGER,guest_name VARCHAR(190),guest_email VARCHAR(190),
    location_value VARCHAR(190),source VARCHAR(40))');
$pdo->exec('CREATE TABLE user_calendar_events (id INTEGER PRIMARY KEY,owner_user_id INTEGER,title VARCHAR(190),
    description TEXT,location VARCHAR(190),start_at_utc DATETIME,end_at_utc DATETIME,timezone VARCHAR(80),all_day INTEGER,
    recurrence VARCHAR(16),agent_notifications INTEGER,source VARCHAR(32),source_reference VARCHAR(190),
    status VARCHAR(24),created_by_agent_id INTEGER,updated_at DATETIME)');
$pdo->exec("INSERT INTO agent_scheduling_schedules VALUES (81,1,7,'Planning',1,1),(82,1,8,'Other Agent',1,0),
    (83,2,9,'Other owner',1,1)");

$now = new DateTimeImmutable('now',new DateTimeZone('UTC'));
$start = $now->modify('+1 day')->setTime(18,0);
$startSql = $start->format('Y-m-d H:i:s');
$endSql = $start->modify('+30 minutes')->format('Y-m-d H:i:s');
$booking = $pdo->prepare("INSERT INTO agent_scheduling_bookings VALUES (?,?,?,?,?,?,?,'America/Denver',?,NULL,
    'PRIVATE_BOOKING_TOKEN',0,0,'Riley Park','PRIVATE_GUEST_EMAIL','','manual')");
$booking->execute([811,1,81,7,'Vendor check-in',$startSql,$endSql,'pending']);
$booking->execute([812,1,81,null,'Last meeting',$start->modify('-2 days')->format('Y-m-d H:i:s'),
    $start->modify('-2 days +30 minutes')->format('Y-m-d H:i:s'),'completed']);
$booking->execute([813,1,82,8,'OTHER_AGENT_BOOKING',$startSql,$endSql,'confirmed']);
$booking->execute([814,2,83,9,'OTHER_OWNER_BOOKING',$startSql,$endSql,'confirmed']);
$booking->execute([815,1,81,8,'OTHER_AGENT_SAME_SCHEDULE',$startSql,$endSql,'confirmed']);
$booking->execute([816,1,81,7,'OUTSIDE_PLANNING_WINDOW',$start->modify('+10 days')->format('Y-m-d H:i:s'),
    $start->modify('+10 days +30 minutes')->format('Y-m-d H:i:s'),'confirmed']);

$binding = ['agent_id'=>7,'owner_user_id'=>1,'conversation_id'=>11];
$pending = 'planning:scheduling:booking:811';
$completed = 'planning:scheduling:booking:812';
$discovered = vp3_coordination_sources_v1($pdo,$owner,$binding,null,$now);
canvas_pipeline_check(array_keys($discovered['items']) === [$pending,$completed],
    'Planning discovers upcoming and recently closed appointments in its own schedule only');
$row = $discovered['items'][$pending];
canvas_pipeline_check($row['specialist'] === 'planning' && $row['status'] === 'pending' && $row['priority'] === 'high'
    && $row['start_at'] === $start->format(DATE_ATOM)
    && str_contains($row['summary'],$start->setTimezone(new DateTimeZone('America/Denver'))->format('g:i A T'))
    && str_contains($row['summary'],'Riley Park')
    && str_contains($row['url'],'workspace_booking=811') && $discovered['items'][$completed]['status'] === 'completed'
    && !str_contains(json_encode($discovered,JSON_THROW_ON_ERROR),'PRIVATE_'),
    'Planning evidence reports canonical booking status and exact local time without guest contact data or tokens');
$planning = vp3_agent_activity_hub_v1($pdo,$owner,7,11,'planning');
$sections = array_column($planning['sections'],null,'id');
$cards = array_column($sections['appointments']['items'],null,'id');
canvas_pipeline_check(isset($sections['calendar']) && ($cards[$pending]['actions'][0]['href'] ?? null) === $row['url'],
    'the Planning rail includes Calendar and the same authorized appointment with its direct link');

$exact = vp3_coordination_sources_v1($pdo,$owner,$binding,[$pending,'planning:scheduling:booking:813'], $now);
canvas_pipeline_check($exact['items'] === [$pending=>$row], 'exact reread preserves the revision and refuses an unscoped booking');

$policy = vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>11],'on',null)['initiative_policy'];
$pdo->exec("UPDATE agent_scheduling_bookings SET status='confirmed' WHERE id=811");
vp3_initiative_schedule_v1($pdo,$owner,7);
$coordinationRuns = "source_kind='agent_initiative' AND source_key LIKE 'coordination:%'";
canvas_pipeline_check(!array_intersect($discovered['coverage'],['limited','unavailable'])
    && canvas_pipeline_count('agent_workflow_runs',$coordinationRuns) === 0,
    'ordinary standalone appointments stay in Planning when source coverage is healthy');
$pdo->exec("UPDATE agent_scheduling_bookings SET status='pending' WHERE id=811");
$pdo->exec("DELETE FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7 AND memory_type='initiative_sweep' AND subject='coordination-scan'");
vp3_initiative_schedule_coordination_v1($pdo,$owner,$policy,false);
$runs = $pdo->query('SELECT id,goal FROM agent_workflow_runs WHERE '.$coordinationRuns)->fetchAll(PDO::FETCH_ASSOC);
$goal = json_decode($runs[0]['goal'] ?? '{}',true,32,JSON_THROW_ON_ERROR);
$route = ['specialist'=>'planning','trigger'=>'pending_confirmation','action'=>'ask'];
canvas_pipeline_check(count($runs) === 1 && $goal['binding'] === $policy && $goal['primary'] === $pending
    && $goal['sources'] === [$pending=>$row['revision']] && $goal['coordination_coverage'] === vp3_coordination_scan_coverage_v1($discovered['coverage'])
    && ($goal['coordination_route'] ?? null) === $route && ($goal['coordination_saved'] ?? false) === true,
    'one pending appointment matches the saved confirmation rule with inference unavailable');
$run = (int)$runs[0]['id'];
$readPlanning = static fn(int $cid=11): array => array_column(
    vp3_top_of_mind_work_v1($pdo,$owner,7,$cid,true)['specialists'],null,'id')['planning'];
canvas_pipeline_check($readPlanning()['state']==='queued' && $readPlanning()['run_id']===$run,
    'the lightweight rail exposes the exact queued Planning coordination');
$question = 'Would you like me to prepare the confirmation for “Vendor check-in”?';
$inferenceCalls = 0;
$model = static function() use (&$inferenceCalls): never {
    ++$inferenceCalls;
    throw new LogicException('The exact saved appointment rule does not invoke a model.');
};
$before = canvas_pipeline_count('chat_messages','conversation_id=11');
$presence = $pdo->prepare('UPDATE agent_activity_state SET last_heartbeat_at=CURRENT_TIMESTAMP,details_json=? WHERE user_id=1');
$presence->execute(['{"visible":true,"conversation_id":11,"chat_focused":true,"chat_busy":true}']);
$pdo->failGenericSchedule = true;
try {
    $result = vp3_initiative_pump_v1($pdo,$owner,7,11,$policy['revision'],$model);
} finally {
    $pdo->failGenericSchedule = false;
}
canvas_pipeline_check(!empty($result['ok']) && canvas_pipeline_result($run)['initiative_delivery'] === 'pending'
    && canvas_pipeline_count('chat_messages','conversation_id=11') === $before,
    'the normal foreground pump verifies the pending decision despite a failure in generic assessment scheduling');
canvas_pipeline_check($readPlanning()['state']==='ready_to_report'&&$readPlanning()['run_id']===$run,
    'a newly finished Planning coordination reports that a verified finding is waiting to post');
$ageAction=$pdo->prepare('UPDATE agent_workflow_actions SET completed_at=? WHERE run_id=?');
$ageAction->execute([gmdate('Y-m-d H:i:s',time()-300),$run]);
canvas_pipeline_check($readPlanning()['state']==='ready_to_report'
    && vp3_top_of_mind_engagements_v1($pdo,$owner,7,11,'planning')['open_engagements']===[],
    'an older prepared question stays ready to report until delivery and is not yet an open engagement');
canvas_pipeline_check($inferenceCalls === 0, 'the standalone question uses the saved appointment and rule without calling a model');
$presence->execute(['{"visible":true,"conversation_id":11,"chat_focused":true,"chat_busy":false}']);
canvas_pipeline_check(vp3_initiative_deliver_pending_v1($pdo,$owner,7) && !vp3_initiative_deliver_pending_v1($pdo,$owner,7),
    'the standalone decision reaches the linked Chat once when the owner is ready');
$receipt = canvas_pipeline_result($run);
$chat = $pdo->prepare("SELECT message,context_json FROM chat_messages WHERE id=? AND conversation_id=11 AND role='assistant'");
$chat->execute([$receipt['initiative_message_id']]);
$message = $chat->fetch(PDO::FETCH_ASSOC);
$context = json_decode($message['context_json'] ?? '{}',true,32,JSON_THROW_ON_ERROR);
canvas_pipeline_check(str_contains($message['message'] ?? '',$question) && $receipt['initiative_delivery'] === 'delivered'
    && $context['initiative_output']['evidence_revisions'] === [$pending=>$row['revision']]
    && $context['initiative_work']['followthrough']['state'] === 'open'
    && canvas_pipeline_count('chat_messages','conversation_id=11') === $before+1,
    'the proactive assistant turn carries the real decision, exact booking revision and reply link');
$pdo->exec("DELETE FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7 AND memory_type='initiative_sweep' AND subject='coordination-scan'");
vp3_initiative_schedule_coordination_v1($pdo,$owner,$policy,false);
canvas_pipeline_check(canvas_pipeline_count('agent_workflow_runs',$coordinationRuns) === 1,
    'the next normal scan does not restart a verified unchanged appointment decision');

$booking->execute([817,1,81,7,'Confirm revised check-in',$start->modify('+3 days')->format('Y-m-d H:i:s'),
    $start->modify('+3 days +30 minutes')->format('Y-m-d H:i:s'),'pending']);
$resumedRef = 'planning:scheduling:booking:817';
$pause = static fn(string $state): array => vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>11],$state,null)['initiative_policy'];
$scan = static function(array $policy) use ($pdo,$owner): void {
    $pdo->exec("DELETE FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7 AND memory_type='initiative_sweep' AND subject='coordination-scan'");
    vp3_initiative_schedule_coordination_v1($pdo,$owner,$policy,false);
};
$scan($policy);
$runs = vp3_coordination_runs_v1($pdo,$owner,$policy);
$oldRun = (int)$runs[0]['id'];
$presence->execute(['{"visible":true,"conversation_id":11,"chat_focused":true,"chat_busy":true}']);
$claim=canvas_pipeline_claim($oldRun);
$working=$readPlanning();
$foreign=false;
try{$readPlanning(12);}catch(RuntimeException $error){$foreign=$error->getCode()===403;}
canvas_pipeline_check($working['state']==='working'&&$working['run_id']===$oldRun
    &&strtotime($working['lease_expires_at'])>time()&&$foreign
    &&!str_contains(json_encode($working,JSON_THROW_ON_ERROR),'Confirm revised check-in'),
    'Planning coordination shows a verified live lease and no private text in the exact Chat');
$lease=$pdo->prepare('UPDATE agent_workflow_runs SET lease_expires_at=? WHERE id=?');
$lease->execute(['2000-01-01 00:00:00',$oldRun]);
canvas_pipeline_check($readPlanning()['state']!=='working','an expired Planning claim no longer animates the rail');
$lease->execute([gmdate('Y-m-d H:i:s',strtotime($working['lease_expires_at'])),$oldRun]);
$verified = vp3_initiative_execute_v1($pdo,$owner,$claim,$model);
$oldGoal = json_decode($runs[0]['goal'],true,32,JSON_THROW_ON_ERROR);
canvas_pipeline_check(!empty($verified['ok']) && $oldGoal['primary'] === $resumedRef
    && canvas_pipeline_result($oldRun)['initiative_delivery'] === 'pending', 'the next real decision is verified before Pause');
$paused = $pause('off');
canvas_pipeline_check(!$paused['enabled'] && !vp3_initiative_deliver_pending_v1($pdo,$owner,7),
    'Pause prevents delivery of a verified pending decision');
$resumed = $pause('on');
canvas_pipeline_check($resumed['revision'] !== $policy['revision'] && !vp3_initiative_deliver_pending_v1($pdo,$owner,7)
    && canvas_pipeline_result($oldRun)['initiative_delivery'] === 'withheld', 'Resume retires the previous policy’s pending delivery');
$scan($resumed);
$runs = vp3_coordination_runs_v1($pdo,$owner,$resumed);
$newRun = (int)$runs[0]['id'];
$newGoal = json_decode($runs[0]['goal'],true,32,JSON_THROW_ON_ERROR);
canvas_pipeline_check(count($runs) === 3 && $newRun !== $oldRun && $newGoal['binding'] === $resumed
    && $newGoal['coordination_id'] === $oldGoal['coordination_id'] && $newGoal['sources'] === $oldGoal['sources'],
    'the resumed policy rechecks only the undelivered exact decision with the original situation ID');
$pdo->prepare("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE id=?")->execute([$receipt['initiative_message_id']]);
$presence->execute(['{"visible":true,"conversation_id":11,"chat_focused":true,"chat_busy":false}']);
$result = vp3_initiative_pump_v1($pdo,$owner,7,11,$resumed['revision'],$model);
$scan($resumed);
canvas_pipeline_check(!empty($result['ok']) && canvas_pipeline_result($newRun)['initiative_delivery'] === 'delivered'
    && canvas_pipeline_count('chat_messages','conversation_id=11') === $before+2
    && canvas_pipeline_count('agent_workflow_runs',$coordinationRuns) === 3,
    'the reverified decision arrives once after Resume without replaying the already delivered appointment');
$engagements = static fn(int $cursor=0): array => vp3_top_of_mind_engagements_v1($pdo,$owner,7,11,'planning',$cursor);
$firstPost=(int)$receipt['initiative_message_id'];
$secondPost=(int)canvas_pipeline_result($newRun)['initiative_message_id'];
$open=$engagements();
canvas_pipeline_check($open['open_engagements_status']==='ready'
    && array_column($open['open_engagements'],'message_id')===[$secondPost,$firstPost]
    && $open['open_engagements'][1]['question']===$question
    && str_contains($open['open_engagements'][1]['result_url'],'message_id='.$firstPost)
    && !str_contains(json_encode($open,JSON_THROW_ON_ERROR),'PRIVATE_'),
    'Planning lists both verified posted questions, including an old still-open one from the earlier policy revision');
canvas_pipeline_check(array_column($engagements($secondPost)['open_engagements'],'message_id')===[$firstPost]
    && vp3_top_of_mind_engagements_v1($pdo,$owner,7,11,'gmail')['open_engagements']===[],
    'older engagement pages are exclusive of the current pointer and scoped to the selected specialist');
$pausedAfterDelivery=$pause('off');
$whilePaused=array_column(vp3_top_of_mind_work_v1($pdo,$owner,7,11,true,includeEngagements:true)['specialists'],null,'id')['planning'];
canvas_pipeline_check($whilePaused['state']==='paused'&&array_column($whilePaused['open_engagements'],'message_id')===[$secondPost,$firstPost],
    'an explicit worker pause leaves both previously delivered questions accessible');
$resumed=$pause('on');
$newGoal['binding']=$resumed;
$otherSource=$pdo->prepare("UPDATE agent_scheduling_bookings SET status=? WHERE id=811");
$otherSource->execute(['cancelled']);
canvas_pipeline_check(array_column($engagements()['open_engagements'],'message_id')===[$secondPost],
    'a no-longer-current appointment withdraws just its question while another remains open');
$otherSource->execute(['pending']);
$storedContext=$pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?');
$withdrawn=$context;
$withdrawn['initiative_followthrough_state']['state']='owner_resolved';
$withdrawn['initiative_work']['followthrough']['state']='owner_resolved';
$storedContext->execute([json_encode($withdrawn,JSON_THROW_ON_ERROR),$firstPost]);
canvas_pipeline_check(array_column($engagements()['open_engagements'],'message_id')===[$secondPost],
    'a saved user resolution removes only its own question from the unanswered list');
$storedContext->execute([json_encode($context,JSON_THROW_ON_ERROR),$firstPost]);
$tamper=$pdo->prepare('UPDATE chat_messages SET message=? WHERE id=?');
$tamper->execute(['Different text with the original context.',$firstPost]);
canvas_pipeline_check(array_column($engagements()['open_engagements'],'message_id')===[$secondPost],
    'marker data alone cannot prove a question when the original Chat text differs from its receipt');
$tamper->execute([$message['message'],$firstPost]);

$setRetry = $pdo->prepare('UPDATE agent_workflow_runs SET next_attempt_at=? WHERE id=?');
$setAvailable = $pdo->prepare('UPDATE agent_workflow_actions SET available_at=? WHERE run_id=?');
$future = gmdate('Y-m-d H:i:s',time()+600);
for($i=0;$i<25;$i++){
    $id=hash('sha256','waiting-coordination-'.$i);
    vp3_initiative_enqueue_v1($pdo,$owner,'initiative.prepare',array_replace($newGoal,['coordination_id'=>$id]),$id);
    $delayed=(int)$pdo->query('SELECT MAX(id) FROM agent_workflow_runs')->fetchColumn();
    $setRetry->execute([$future,$delayed]);
}
$id=hash('sha256','action-not-available');
vp3_initiative_enqueue_v1($pdo,$owner,'initiative.prepare',array_replace($newGoal,['coordination_id'=>$id]),$id);
$waitingAction=(int)$pdo->query('SELECT MAX(id) FROM agent_workflow_runs')->fetchColumn();
$setAvailable->execute([$future,$waitingAction]);
canvas_pipeline_check(!vp3_coordination_pending_v1($pdo,$owner,$resumed)
    && vp3_initiative_runtime_runs_v1($pdo,1,7,$resumed,true,false,false,true,true)===[],
    'the future retry and future action consume neither coordination admission nor claim slots');

$startNew=$start->modify('+2 days +1 hour');
$booking->execute([818,1,81,7,'New appointment confirmation',$startNew->format('Y-m-d H:i:s'),
    $startNew->modify('+30 minutes')->format('Y-m-d H:i:s'),'pending']);
$pdo->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=11");
$scan($resumed);
$eligible=vp3_initiative_runtime_runs_v1($pdo,1,7,$resumed,true,false,false,true,true);
$dueGoal=json_decode($eligible[0]['goal']??'{}',true,32,JSON_THROW_ON_ERROR);
canvas_pipeline_check(count($eligible)===1&&($dueGoal['primary']??null)==='planning:scheduling:booking:818'
    &&vp3_coordination_pending_v1($pdo,$owner,$resumed),
    'one current planning decision can enter the queue behind more than 25 delayed jobs');
$pdo->prepare("INSERT INTO agent_workflow_runs(owner_user_id,agent_id,source_kind,dedupe_key,title,goal,status)
    VALUES(1,7,'agent_initiative',?,'Media suggestion',?,'completed')")
    ->execute([hash('sha256','fixture-recent-media'),json_encode(['tool'=>'coordination.review',
        'coordination_route'=>['specialist'=>'media','action'=>'ask']],JSON_THROW_ON_ERROR)]);
$mediaRun=(int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at)
    VALUES(11,NULL,'assistant','Want to listen to a track?',?,NOW())")
    ->execute([json_encode(['source'=>'agent_initiative','initiative_run_id'=>$mediaRun],JSON_THROW_ON_ERROR)]);
canvas_pipeline_check(vp3_initiative_report_gate_v1($pdo,$owner,11)==='recent_proactive_post'
    &&vp3_initiative_report_gate_v1($pdo,$owner,11,true)==='ready',
    'a recent Media suggestion does not block a verified specialist handoff');
$fresh=vp3_initiative_pump_v1($pdo,$owner,7,11,$resumed['revision'],$model);
canvas_pipeline_check(!empty($fresh['ok'])&&canvas_pipeline_result((int)$eligible[0]['id'])['initiative_delivery']==='delivered'
    &&vp3_initiative_report_gate_v1($pdo,$owner,11,true)==='recent_proactive_post',
    'the foreground pump delivers a fresh Planning question despite a Media suggestion, then paces further work updates');

$outageId=819;
$nextId=820;
$parked=[];
$presence->execute(['{"visible":true,"conversation_id":11,"chat_focused":true,"chat_busy":true}']);
foreach([$outageId=>'Unavailable appointment source',$nextId=>'Available appointment source'] as $id=>$title){
    $when=$startNew->modify('+'.($id-$outageId+1).' hours');
    $booking->execute([$id,1,81,7,$title,$when->format('Y-m-d H:i:s'),$when->modify('+30 minutes')->format('Y-m-d H:i:s'),'pending']);
    $ref='planning:scheduling:booking:'.$id;
    $evidence=vp3_coordination_sources_v1($pdo,$owner,$resumed,[$ref])['items'][$ref];
    $situation=vp3_coordination_scan_id_v1($owner,$resumed,$ref);
    vp3_initiative_enqueue_v1($pdo,$owner,'initiative.prepare',array_replace($newGoal,[
        'primary'=>$ref,'coordination_id'=>$situation,'sources'=>[$ref=>$evidence['revision']],'coordination_routes'=>[$ref=>$route],
    ]),$situation);
    $parked[$id]=(int)$pdo->query('SELECT MAX(id) FROM agent_workflow_runs')->fetchColumn();
    vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($parked[$id]),$model);
}
canvas_pipeline_check(canvas_pipeline_result($parked[$outageId])['initiative_delivery']==='pending'
    && canvas_pipeline_result($parked[$nextId])['initiative_delivery']==='pending',
    'two current saved follow-ups wait for the linked Chat to become quiet');
$pdo->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=11");
$presence->execute(['{"visible":true,"conversation_id":11,"chat_focused":true,"chat_busy":false}']);
$pdo->unavailableAppointmentId=$outageId;
try{
    $sent=vp3_initiative_deliver_pending_v1($pdo,$owner,7);
}finally{
    $pdo->unavailableAppointmentId=null;
}
$unavailable=canvas_pipeline_result($parked[$outageId]);
canvas_pipeline_check($sent&&$unavailable['initiative_delivery']==='pending'
    &&($unavailable['initiative_delivery_reason']??null)==='result_unavailable'
    && canvas_pipeline_result($parked[$nextId])['initiative_delivery']==='delivered',
    'a temporary source failure is recorded while the next verified appointment reaches Chat');
canvas_pipeline_check(vp3_initiative_pending_results_v1($pdo,1,['id'=>7],true)===[],
    'the unavailable result observes a retry delay without blocking unrelated delivery');
$pdo->prepare("UPDATE agent_workflow_actions SET updated_at='2000-01-01 00:00:00' WHERE run_id=?")->execute([$parked[$outageId]]);
$pdo->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=11");
canvas_pipeline_check(vp3_initiative_deliver_pending_v1($pdo,$owner,7)
    && canvas_pipeline_result($parked[$outageId])['initiative_delivery']==='delivered'
    && !isset(canvas_pipeline_result($parked[$outageId])['initiative_delivery_reason']) && $inferenceCalls===0,
    'restored exact source evidence posts the original result and clears its waiting reason without inference');

$pdo->prepare("UPDATE agent_scheduling_bookings SET status='confirmed',lifecycle_status='rescheduled',
    start_at_utc=?,end_at_utc=? WHERE id=811")->execute([$start->modify('+1 day')->format('Y-m-d H:i:s'),
        $start->modify('+1 day +30 minutes')->format('Y-m-d H:i:s')]);
$changed = vp3_coordination_sources_v1($pdo,$owner,$binding,[$pending],$now)['items'][$pending];
canvas_pipeline_check($changed['status'] === 'rescheduled' && $changed['revision'] !== $row['revision']
    && $changed['start_at'] === $start->modify('+1 day')->format(DATE_ATOM), 'booking reschedule invalidates the prior evidence');
$pdo->exec('UPDATE agent_scheduling_schedules SET is_active=0 WHERE id=81');
canvas_pipeline_check(vp3_coordination_sources_v1($pdo,$owner,$binding,[$pending],$now)['items'] === [],
    'deactivating the bound schedule withdraws exact booking evidence');

$startPlan = 'planning:readiness:start';
$pdo->exec("DELETE FROM agent_scheduling_bookings WHERE owner_user_id=1;
    UPDATE agent_workflow_runs SET status='cancelled'
    WHERE source_kind='agent_initiative' AND source_key LIKE 'coordination:%' AND status IN ('approved','queued')");
$lookupPlan = static fn(): array => vp3_coordination_sources_v1($pdo,$owner,$resumed,[$startPlan],specialistOnly:'planning');
canvas_pipeline_check($lookupPlan()['items']===[],
    'an unconfigured or inactive scoped schedule cannot assert that Planning is empty');
$pdo->exec('UPDATE agent_scheduling_schedules SET is_active=1 WHERE id=81');
$planItem = $lookupPlan()['items'][$startPlan]??null;
canvas_pipeline_check(is_array($planItem)&&$planItem['priority']==='low'&&$planItem['url']===url('/calendar.php'),
    'an exact and successfully empty VP3 calendar and appointment view supplies a Planning invitation');
$setting = static fn(string $choice): array => vp3_coordination_rule_settings_v1($pdo,$owner,7,11,
    vp3_coordination_rule_settings_v1($pdo,$owner,7,11)['revision'],['planning'=>['start_plan'=>$choice]]);
$planRuns = static fn(): array => array_values(array_filter(vp3_coordination_runs_v1($pdo,$owner,$resumed),
    static fn(array $row):bool=>(json_decode($row['goal'],true,32,JSON_THROW_ON_ERROR)['primary']??null)===$startPlan));
$setting('watch');
$scan($resumed);
canvas_pipeline_check($planRuns()===[], 'the owner can keep an empty Planning invitation out of Chat');
$setting('ask');
$scan($resumed);
$planRun = $planRuns()[0]??[];
$planGoal = json_decode($planRun['goal']??'{}',true,32,JSON_THROW_ON_ERROR);
canvas_pipeline_check(count($planRuns())===1&&($planGoal['coordination_saved']??false)===true
    &&($planGoal['sources']??[])===[$startPlan=>$planItem['revision']],
    'the regular scan queues exactly one source-bound saved question for empty Planning');
$booking->execute([821,1,81,7,'A real appointment supersedes the empty plan',$startSql,$endSql,'confirmed']);
canvas_pipeline_check($lookupPlan()['items']===[],
    'a new actual appointment withdraws the empty-calendar proof before delivery');
$latePlan = vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim((int)$planRun['id']),$model);
canvas_pipeline_check(!$latePlan['ok']&&$inferenceCalls===0,
    'the existing worker rejects a stale Planning invitation without calling the model');
$pdo->exec("DELETE FROM agent_workflow_runs WHERE id=".(int)$planRun['id']);
$pdo->exec('DELETE FROM agent_scheduling_bookings WHERE id=821');
$pdo->exec("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=11");
$scan($resumed);
$freshPlan = $planRuns()[0];
$result = vp3_initiative_pump_v1($pdo,$owner,7,11,$resumed['revision'],$model);
$planReceipt = canvas_pipeline_result((int)$freshPlan['id']);
$scan($resumed);
canvas_pipeline_check(!empty($result['ok'])&&$planReceipt['initiative_delivery']==='delivered'
    &&str_contains($planReceipt['initiative_tool_result']['draft'],$planItem['question'])
    &&count($planRuns())===1&&$inferenceCalls===0,
    'the open-Chat worker asks what to plan once without provider tokens');

echo 'COORDINATION_SCHEDULING=PASS ('.$checks.' assertions, '.($mysql ? 'mysql' : 'sqlite').")\n";
