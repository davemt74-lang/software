<?php
declare(strict_types=1);

// Exercise the native worker, receipts, Chat follow-through, and specialist
// adapters. Model responses and the mailbox connection are fixtures.
define('VP3_EMAIL_CANVAS_PIPELINE_FIXTURE', true);
$fixtureRoot = sys_get_temp_dir().'/vp3-coordination-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT', $fixtureRoot);
mkdir($fixtureRoot.'/private', 0700, true);
$fixtureKey = random_bytes(32);
file_put_contents($fixtureRoot.'/private/ai-key.php', '<?php return '.var_export(base64_encode($fixtureKey), true).';');
register_shutdown_function(static function () use ($fixtureRoot): void {
    unlink($fixtureRoot.'/private/ai-key.php');
    rmdir($fixtureRoot.'/private');
    rmdir($fixtureRoot);
});
require_once dirname(__DIR__).'/includes/ai-settings.php';
$GLOBALS['imageSettings']['ai_openai_enabled'] = '1';
$GLOBALS['imageSettings']['ai_openai_api_key'] = ai_encrypt_secret_with_key('fixture-coordination-key', $fixtureKey);
require __DIR__.'/agent-initiative-canvas-pipeline-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-sources-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-followthrough-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-model-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-engagement-status-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-office-entry-work-v1.php';
require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-initial-v1.php';
require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-status-v1.php';
require_once dirname(__DIR__).'/includes/agent-email-triage-v1.php';
require_once dirname(__DIR__).'/includes/agent-email-running-report-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-chat-v1.php';
require_once dirname(__DIR__).'/includes/agent-coordination-rules-v1.php';
require_once dirname(__DIR__).'/includes/ai-usage-accounting-v032.php';
require_once dirname(__DIR__).'/includes/cognitive-runtime-v500.php';
require_once dirname(__DIR__).'/includes/agent-chat-runtime-v2160.php';
require_once dirname(__DIR__).'/includes/agent-activity-hub-v1.php';

function coordination_fixture_infer(string $prompt, string $protocol): string
{
    canvas_pipeline_check(!db()->inTransaction(), 'the model runs outside receipt locks');
    $request = json_decode($prompt, true, 32, JSON_THROW_ON_ERROR);
    return json_encode(($GLOBALS['coordinationModel'])($request, $protocol), JSON_THROW_ON_ERROR);
}

function coordination_fixture_presence(string $state, int $chat = 11): void
{
    $details = ['visible'=>$state !== 'away', 'conversation_id'=>$chat,
        'chat_focused'=>$state !== 'away', 'chat_busy'=>$state === 'busy'];
    db()->prepare("UPDATE agent_activity_state SET surface='chat',context_key=?,activity_state='idle',
        last_heartbeat_at=CURRENT_TIMESTAMP,idle_since_at=CURRENT_TIMESTAMP,details_json=? WHERE user_id=1")
        ->execute(['chat:'.$chat, json_encode($details, JSON_THROW_ON_ERROR)]);
}

function coordination_fixture_schedule(array $user): void
{
    db()->exec("DELETE FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7 AND memory_type='initiative_sweep'
        AND subject IN ('coordination-scan','office-entry-scan','last-review')");
    vp3_initiative_schedule_v1(db(), $user, 7);
}

function coordination_fixture_runs(string $tool, ?string $primary = null): array
{
    $runs = [];
    foreach (canvas_pipeline_runs('initiative.prepare') as $row) {
        $goal = json_decode((string)$row['goal'], true, 32, JSON_THROW_ON_ERROR);
        if (($goal['tool'] ?? null) !== $tool || ($primary !== null && ($goal['primary'] ?? null) !== $primary)) continue;
        $runs[] = ['id'=>(int)$row['id'], 'status'=>$row['status'], 'goal'=>$goal,'execution_target'=>$row['execution_target']];
    }
    return $runs;
}

function coordination_fixture_receipt(int $run): array
{
    $result = canvas_pipeline_result($run);
    $action = db()->prepare("SELECT result_json FROM agent_workflow_actions WHERE run_id=? AND status='completed'");
    $action->execute([$run]);
    $rows = $action->fetchAll(PDO::FETCH_COLUMN);
    canvas_pipeline_check(count($rows) === 1 && json_decode($rows[0], true, 32, JSON_THROW_ON_ERROR) === $result,
        'the completed action and canonical execution receipt agree');
    return $result;
}

function coordination_fixture_message(int $id): array
{
    $query = db()->prepare('SELECT * FROM chat_messages WHERE id=?');
    $query->execute([$id]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Missing expected Chat message.');
    return $row;
}

function coordination_fixture_reply(string $text): int
{
    db()->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(11,1,'user',?)")->execute([$text]);
    return (int)db()->lastInsertId();
}

function coordination_fixture_domain_state(): array
{
    $queries = [
        'tasks'=>"SELECT * FROM agent_memory_items WHERE memory_type IN ('task','commitment') ORDER BY id",
        'calendar'=>'SELECT * FROM user_calendar_events ORDER BY id',
        'gmail'=>'SELECT * FROM agent_email_running_report_v1 ORDER BY thread_key',
        'reminders'=>'SELECT * FROM agent_reminders_v1 ORDER BY id',
        'lists'=>'SELECT * FROM agent_task_lists_v1 ORDER BY id',
    ];
    return array_map(static fn(string $query): array=>db()->query($query)->fetchAll(PDO::FETCH_ASSOC), $queries);
}

function coordination_fixture_calendar(PDO $pdo): array
{
    $pdo->exec("CREATE TABLE user_calendar_events(id INTEGER PRIMARY KEY,owner_user_id INTEGER,title VARCHAR(190),
        description TEXT,location VARCHAR(190),start_at_utc DATETIME,end_at_utc DATETIME,timezone VARCHAR(80),all_day INTEGER,
        recurrence VARCHAR(16),agent_notifications INTEGER,source VARCHAR(32),source_reference VARCHAR(190),
        status VARCHAR(24),created_by_agent_id INTEGER,updated_at DATETIME)");
    $start = gmdate('Y-m-d H:i:s', time()+86400);
    $end = gmdate('Y-m-d H:i:s', time()+90000);
    $insert = $pdo->prepare("INSERT INTO user_calendar_events VALUES(?,?,?,?,'Launch room',?,?,'UTC',0,'none',1,
        'user','','active',NULL,CURRENT_TIMESTAMP)");
    $insert->execute([701,1,'Vendor launch rehearsal','The vendor delivery must arrive before the rehearsal.', $start, $end]);
    $insert->execute([702,2,'OTHER_OWNER_CALENDAR_FIXTURE','Do not include this calendar.', $start, $end]);
    return ['planning:calendar:event:701', $start];
}

function coordination_fixture_model(array $refs, string $question, string $nextStep, string $summary, ?int $expireRun = null): Closure
{
    return static function(array $request, string $protocol) use ($refs, $question, $nextStep, $summary, $expireRun): array {
        $sources = array_column($request['specialist_reports'] ?? [], null, 'ref');
        $sourceRefs = array_keys($sources);
        sort($sourceRefs);
        $expected = $refs;
        sort($expected);
        canvas_pipeline_check($protocol === 'vp3.initiative-draft.v1' && $request['response_kind'] === 'coordination_review'
            && $sourceRefs === $expected
            && $sources[$refs[0]]['specialist'] === 'gmail' && $sources[$refs[1]]['specialist'] === 'tasks'
            && $sources[$refs[2]]['specialist'] === 'planning'
            && str_contains($sources[$refs[0]]['summary'], 'vendor')
            && str_contains($sources[$refs[1]]['summary'], 'vendor')
            && str_contains($sources[$refs[2]]['summary'], 'rehearsal')
            && !str_contains(json_encode($request, JSON_THROW_ON_ERROR), 'OTHER_OWNER_CALENDAR_FIXTURE')
            && !str_contains(json_encode($request, JSON_THROW_ON_ERROR), 'COORDINATION_TASK_PRIVATE_BODY'),
            'one coordination inference sees verified Gmail, Tasks, and Planning findings in the linked scope');
        canvas_pipeline_check(count($request['related_specialist_refs']) === 2
            && in_array('rehearsal',$request['related_specialist_refs'][$refs[1]] ?? [],true)
            && in_array('rehearsal',$request['related_specialist_refs'][$refs[2]] ?? [],true)
            && isset($sources[$refs[0]]['received_at'],$sources[$refs[0]]['due_at'],$sources[$refs[1]]['due_at'],
                $sources[$refs[2]]['start_at'],$sources[$refs[2]]['end_at'],$sources[$refs[2]]['timezone']),
            'the proactive review receives an exact relationship, Gmail deadline, task deadline, and Planning timing');
        if ($expireRun !== null) {
            db()->prepare("UPDATE agent_workflow_runs SET lease_expires_at='2000-01-01 00:00:00' WHERE id=?")->execute([$expireRun]);
        }
        return ['title'=>'Vendor delivery and launch rehearsal', 'summary'=>$summary, 'next_step'=>$nextStep,
            'question'=>$question, 'state'=>'needs_owner', 'refs'=>[$request['primary'], ...array_diff($refs, [$request['primary']])]];
    };
}

function coordination_fixture_prepare_office(PDO $pdo, array $owner, string $nativeRef, bool $ownerAttention=false,
    array $taskMetadata=[], string $question='Is a later vendor delivery acceptable?'): void
{
    $task = $pdo->prepare("INSERT INTO agent_memory_items(id,user_id,user_agent_id,memory_type,subject,memory_text,memory_hash,
        memory_scope_version,confidence,occurrence_count,first_seen_at,last_seen_at,is_active,metadata_json)
        VALUES(?,1,?,'task',?,?,?,410,.95,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,1,?)");
    $metadata = json_encode($taskMetadata + ['task_status'=>'open', 'due_at'=>gmdate('Y-m-d', time()-86400),
        'source_kind'=>'explicit_user'], JSON_THROW_ON_ERROR);
    $id = (int)substr($nativeRef,strlen('task:'));
    $task->execute([$id,7,'Confirm vendor delivery before rehearsal',
        'COORDINATION_TASK_PRIVATE_BODY. Have the vendor confirm delivery before the launch rehearsal.', 'coordination-task-'.$id, $metadata]);
    $task->execute([$id+1,8,'OTHER_AGENT_TASK_FIXTURE','Do not include this task.', 'coordination-task-'.($id+1), $metadata]);
    coordination_fixture_schedule($owner);
    $officeRuns = coordination_fixture_runs('tasks.read', $nativeRef);
    canvas_pipeline_check(count($officeRuns) === 1 && ($officeRuns[0]['goal']['office_entry'] ?? false),
        'the regular scheduler starts a real per-task specialist report');
    $GLOBALS['coordinationModel'] = static function(array $request, string $protocol) use ($ownerAttention,$question): array {
        canvas_pipeline_check($protocol === VP3_INITIATIVE_OFFICE_REPORT_PROTOCOL_V1 && $request['response_kind'] === 'office_entry_review',
            'the task report is prepared by its native specialist worker');
        return ['office_disposition'=>'review','draft'=>'The vendor has yet to confirm delivery for the rehearsal.', 'next_step'=>'Prepare an alternate delivery plan.',
            'followup_question'=>$question, 'action_plan'=>['Check the rehearsal timing.'],
            'owner_attention'=>$ownerAttention];
    };
    canvas_pipeline_check(!empty(vp3_initiative_execute_v1($pdo, $owner, canvas_pipeline_claim($officeRuns[0]['id']), 'coordination_fixture_infer')['ok']),
        'the Tasks report is saved by the canonical leased worker');
    $office = coordination_fixture_receipt($officeRuns[0]['id']);
    canvas_pipeline_check($office['initiative_delivery'] === 'private',
        'the private specialist report is available as evidence without independently posting a question');
}

function coordination_fixture_gmail_setup(PDO $pdo, array $owner, bool $mysql): void
{
    putenv('VP3_GMAIL_CLIENT_ID=coordination-fixture');
    putenv('VP3_GMAIL_CLIENT_SECRET=local-test-only');
    $connectionKey = $mysql ? 'BIGINT UNSIGNED' : 'INTEGER';
    $ownerKey = $mysql ? 'INT UNSIGNED' : 'INTEGER';
    $pdo->exec("CREATE TABLE agent_connections_v1 (
        id $connectionKey PRIMARY KEY,owner_user_id $ownerKey NOT NULL,provider VARCHAR(32) NOT NULL,
        status VARCHAR(20) NOT NULL,read_enabled INTEGER NOT NULL,authorization_key CHAR(64) NOT NULL,
        client_fingerprint CHAR(64) NOT NULL)".($mysql ? ' ENGINE=InnoDB' : ''));
    agent_connection_gmail_inbox_install_v1($pdo);
    agent_connection_gmail_inbox_receipts_install_v1($pdo);
    agent_connection_gmail_inbox_initial_install_v1($pdo);
    agent_email_matters_install_v1($pdo);
    agent_email_triage_install_v1($pdo);
    agent_email_running_report_install_v1($pdo);
    agent_connection_gmail_inbox_activity_install_v1($pdo);

    $grant = str_repeat('d',64);
    $pdo->prepare("INSERT INTO agent_connections_v1 VALUES (88,1,'gmail','connected',1,?,?)")
        ->execute([$grant,agent_connections_client_fingerprint_v1()]);
    $pdo->prepare("INSERT INTO agent_connection_gmail_inbox_v1
        (owner_user_id,connection_id,authorization_key,enabled,rules_json,revision,enabled_at_ms,history_id,last_status)
        VALUES (1,88,?,1,?, ?,?,'111','waiting')")
        ->execute([$grant,json_encode(['triage_enabled'=>true,'content_analysis'=>true],JSON_THROW_ON_ERROR),
            str_repeat('e',32),(time()-3600)*1000]);

    $scope = agent_connection_gmail_inbox_initial_scope_v1($pdo,$owner);
    canvas_pipeline_check(($scope['state'] ?? '') === 'ready', 'the Gmail fixture has an authorized analysis grant');
    $runId = str_repeat('a',32);
    $ref = agent_connection_gmail_inbox_chat_ref_v1($scope,$runId);
    $context = json_encode(['source'=>'agent_connection_gmail_first_review',
        'gmail_first_review_v1'=>['run_id'=>$runId,'report_ref'=>$ref]],JSON_THROW_ON_ERROR);
    $now = agent_commerce_time_pa2();
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json,created_at)
        VALUES (11,NULL,'assistant','Saved Gmail connection marker.',?,?)")->execute([$context,$now]);
    $delivery = ['run_id'=>$runId,'report_ref'=>$ref,'agent_id'=>7,'conversation_id'=>11,'message_id'=>(int)$pdo->lastInsertId()];
    $pdo->prepare("INSERT INTO agent_connection_gmail_inbox_initial_v1
        (owner_user_id,connection_id,generation_id,rules_hash,run_id,days,started_at_ms,phase,report_json,updated_at)
        VALUES (1,88,?,?,?,3,?,'ready',?,?)")
        ->execute([$scope['generation'],$scope['rules_hash'],$runId,(time()-1800)*1000,
            json_encode(['chat_delivery'=>$delivery],JSON_THROW_ON_ERROR),$now]);
}

function coordination_fixture_gmail_observe(PDO $pdo, array $owner, int $version=1,
    ?string $summary='The vendor moved the delivery to the morning of the launch rehearsal.', string $state='open'): string
{
    $policy = agent_connection_gmail_inbox_policy_v1($pdo,(int)$owner['id']);
    $message = [
        'message_id'=>'coordination_vendor_message_'.$version,'thread_id'=>'coordination_vendor_thread',
        'received_at_ms'=>(string)((int)$policy['enabled_at_ms']+($version+1)*1000),'from'=>'vendor@example.test',
        'subject'=>'Vendor delivery change','important'=>true,'reply'=>$version>1,'labels'=>['INBOX'],
        'assessment'=>['status'=>'assessed','reason'=>'deadline','context'=>'business','sender'=>'organization',
            'deadline_date'=>gmdate('Y-m-d',time()+86400)],
    ];
    $key = agent_email_matters_source_key_v1($policy,'thread',$message['thread_id']);
    agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$policy,$message,$key,$summary,$state): void {
        $now = agent_commerce_time_pa2();
        $triage = agent_email_triage_record_v1($pdo,$policy,$message,$now);
        if ($triage === null) return;
        agent_email_running_report_enqueue_v1($pdo,$policy,$message,$triage,$now);
        if ($summary === null) return;
        // Seed the sealed provider analysis for the exact native triage item.
        $finding = ['subject'=>$message['subject'],'summary'=>$summary,'category'=>'decision','priority'=>'high','state'=>$state,
            'next_step'=>'Confirm the delivery window before the rehearsal.','followup_question'=>'Can the vendor arrive earlier?',
            'item_ref'=>$triage['id'],'source_is_evidence'=>true,
            'handoff'=>$state === 'open' ? 'needs_attention' : 'none'];
        $save = $pdo->prepare('UPDATE agent_email_running_report_v1 SET finding_enc=?,finding_priority=2,finding_state=?,
            analyzed_at=?,due_at=NULL,failures=0 WHERE thread_key=? AND source_triage_id=?');
        $save->execute([ai_encrypt_secret(json_encode($finding,JSON_THROW_ON_ERROR)),$state,$now,$key,$triage['id']]);
        canvas_pipeline_check($save->rowCount() === 1, 'Gmail writes one current encrypted finding for the native thread');
    });
    return 'gmail:'.$key;
}

function coordination_fixture_rule(PDO $pdo, array $owner, string $specialist, string $trigger, string $action): array
{
    $settings = vp3_coordination_rule_settings_v1($pdo,$owner,7,11);
    return vp3_coordination_rule_settings_v1($pdo,$owner,7,11,$settings['revision'],[$specialist=>[$trigger=>$action]]);
}

if (defined('VP3_COORDINATION_PIPELINE_FIXTURE')) return;

$agentId = 7;
$chatId = 11;
$taskRef = 'task:601';
$pdo->exec("ALTER TABLE ai_execution_ledger ADD COLUMN homeserver_state VARCHAR(30) DEFAULT 'not_used'");
$pdo->exec("ALTER TABLE ai_execution_ledger ADD COLUMN fallback_used INTEGER DEFAULT 0");
$pdo->exec("INSERT INTO chat_messages(conversation_id,role,message,created_at)
    VALUES(11,'assistant','I am here to review your work.','2000-01-01 00:00:00')");
vp3_task_lists_schema_v1($pdo);
reminder_schema_v1($pdo);
$enabled = vp3_initiative_chat_v1($pdo, $owner, $principal, ['conversation_id'=>$chatId], 'on', null);
canvas_pipeline_check(!empty($enabled['ok']), 'the owner enables the real scoped Initiative policy');
$binding = $enabled['initiative_policy'];
coordination_fixture_presence('away');
coordination_fixture_rule($pdo,$owner,'tasks','overdue','watch');
coordination_fixture_rule($pdo,$owner,'tasks','waiting_owner','watch');
coordination_fixture_prepare_office($pdo, $owner, $taskRef);
[$calendarRef, $originalStart] = coordination_fixture_calendar($pdo);
coordination_fixture_gmail_setup($pdo, $owner, $mysql);
$gmailRef = coordination_fixture_gmail_observe($pdo, $owner);
$refs = [$gmailRef, 'office:'.$taskRef, $calendarRef];
$discovered = vp3_coordination_sources_v1($pdo, $owner, $binding);
canvas_pipeline_check(count($discovered['items']) === 3 && !array_diff($refs, array_keys($discovered['items'])),
    'the live adapters find the owned Calendar event, exact Tasks receipt, and sealed Gmail finding');
$gmailOnly = vp3_coordination_sources_v1($pdo,$owner,$binding,specialistOnly:'gmail');
canvas_pipeline_check($gmailOnly['items'] === [$gmailRef=>$discovered['items'][$gmailRef]]
    && vp3_coordination_discovery_summary_v1($gmailOnly) === ['gmail'=>['coverage'=>'ready','found'=>1]],
    'Gmail diagnostics reads the same scoped evidence as the scheduler and returns aggregate discovery facts');
$pdo->failGmailSource = true;
try {
    $partialDiscovery = vp3_coordination_discovery_summary_v1(vp3_coordination_sources_v1($pdo,$owner,$binding));
    $debugDelivery = agent_connection_gmail_diagnostics_active_delivery_v1($pdo,$owner,$binding);
    canvas_pipeline_check($partialDiscovery['gmail'] === ['coverage'=>'unavailable','found'=>0,'failure'=>'storage_error']
        && $partialDiscovery['tasks']['found'] === 1 && $partialDiscovery['planning']['found'] === 1
        && $debugDelivery[0] === 'blocked' && $debugDelivery[2] === 'gmail_findings_unavailable'
        && str_contains($debugDelivery[1],'database could not read') && !str_contains($debugDelivery[1],'PRIVATE_FAILURE_FIXTURE'),
        'a Gmail source read error leaves other specialists available and exposes only a safe read-failure reason');
} finally {
    $pdo->failGmailSource = false;
}
$otherAgentBinding = array_replace($binding, ['agent_id'=>8, 'conversation_id'=>12]);
$otherSources = vp3_coordination_sources_v1($pdo, $owner, $otherAgentBinding, [$gmailRef, 'office:'.$taskRef]);
canvas_pipeline_check($otherSources['items'] === [],
    'the same owner’s sibling Agent cannot read the linked Gmail or Tasks findings');

$noisyStart = (new DateTimeImmutable($originalStart,new DateTimeZone('UTC')))->modify('+2 days');
$pdo->prepare("INSERT INTO user_calendar_events VALUES(704,1,'General vendor meeting',
    'Prepare a calendar update and review the latest meeting notes.','',?,?,'UTC',0,'none',1,'user','','active',NULL,CURRENT_TIMESTAMP)")
    ->execute([$noisyStart->format('Y-m-d H:i:s'),$noisyStart->modify('+1 hour')->format('Y-m-d H:i:s')]);
$firstStart = new DateTimeImmutable($originalStart,new DateTimeZone('UTC'));
$pdo->prepare("INSERT INTO user_calendar_events VALUES(705,1,'Permit appointment',
    'Inspect the roof at city hall.','',?,?,'UTC',0,'none',1,'user','','active',NULL,CURRENT_TIMESTAMP)")
    ->execute([$firstStart->modify('-30 minutes')->format('Y-m-d H:i:s'),$firstStart->modify('+10 minutes')->format('Y-m-d H:i:s')]);
$unrelated = 'planning:calendar:event:704';
$conflict = 'planning:calendar:event:705';
$cohort = vp3_coordination_sources_v1($pdo,$owner,$binding)['items'];
$relations = vp3_coordination_gmail_related_v1($cohort[$gmailRef],$cohort);
canvas_pipeline_check(isset($cohort[$unrelated]) && !isset($relations[$unrelated]),
    'a current owned event with generic vendor and calendar terms does not match the Gmail finding');
$overlapSources = vp3_coordination_scan_gmail_ask_sources_v1($cohort,$gmailRef,$relations);
canvas_pipeline_check($cohort[$conflict]['related_ref'] === $calendarRef && $relations[$conflict] === []
    && isset($overlapSources[$conflict],$overlapSources[$calendarRef]) && !isset($overlapSources[$unrelated]),
    'the Gmail review admits the exact independently verified overlapping event even when it has no shared title');
$pdo->exec('DELETE FROM user_calendar_events WHERE id=705 AND owner_user_id=1');
$cohort = vp3_coordination_sources_v1($pdo,$owner,$binding)['items'];

$originalQuestion = 'Should I plan around the original rehearsal if the vendor needs an extra day?';
$originalNext = 'Compare a one-day vendor delay with the current rehearsal time.';
$originalSummary = 'The vendor has not confirmed delivery. The rehearsal and the open confirmation task depend on that delivery.';
$chatBefore = canvas_pipeline_count('chat_messages', 'conversation_id=11');
$domains = coordination_fixture_domain_state();
coordination_fixture_schedule($owner);
$runs = coordination_fixture_runs('coordination.review');
canvas_pipeline_check(count($runs) === 1 && $runs[0]['goal']['binding'] === $binding
    && !array_diff($refs, array_keys($runs[0]['goal']['sources'])) && count($runs[0]['goal']['sources']) === count($refs)
    && ($runs[0]['goal']['coordination_route'] ?? null) === ['specialist'=>'gmail','trigger'=>'needs_attention','action'=>'ask']
    && $runs[0]['goal']['report'] === true && $runs[0]['execution_target'] === 'cloud'
    && vp3_initiative_inference_health_v1($pdo, $owner, $agentId)['route'] === 'vp3_cloud',
    'the normal Initiative scheduler starts one source-bound cross-specialist review');
$engagement = vp3_initiative_engagement_status_v1($pdo,$owner,$agentId,$chatId,
    ['enabled'=>true,'policy_revision'=>$binding['revision']]);
$schedulerObservation = $engagement['specialist_scan'];
$unlinkedEngagement = vp3_initiative_engagement_status_v1($pdo,$owner,8,12,
    ['enabled'=>true,'policy_revision'=>$binding['revision']]);
canvas_pipeline_check(($schedulerObservation['outcome']??null)==='queued_chat_handoff'
    && $schedulerObservation['specialists']['gmail']['found'] === 1
    && $schedulerObservation['specialists']['gmail']['candidate_count'] === 1
    && $schedulerObservation['specialists']['gmail']['queued'] === 1
    && $schedulerObservation['specialists']['planning']['found'] === 2
    && $schedulerObservation['specialists']['tasks']['found'] === 1
    && !str_contains(json_encode($schedulerObservation,JSON_THROW_ON_ERROR),$gmailRef)
    && $unlinkedEngagement['specialist_scan'] === null,
    'Initiative Debug exposes the latest scheduler discovery and selected handoff only to its bound Agent and Chat');
$fallback = vp3_coordination_saved_result_v1($runs[0]['goal'],array_intersect_key($cohort,$runs[0]['goal']['sources']),
    $runs[0]['goal']['coordination_route'])['output'];
canvas_pipeline_check($fallback['evidence_revisions'] === $runs[0]['goal']['sources']
    && str_contains($fallback['summary'],'Vendor launch rehearsal')
    && str_contains($fallback['summary'],'Confirm vendor delivery before rehearsal')
    && !str_contains($fallback['summary'],'General vendor meeting') && $fallback['question'] === 'Can the vendor arrive earlier?',
    'the outage fallback preserves the real decision and both related records without claiming an unrelated connection');
$pdo->exec('DELETE FROM user_calendar_events WHERE id=704 AND owner_user_id=1');
$domains = coordination_fixture_domain_state();
$firstRun = $runs[0]['id'];
$situation = $runs[0]['goal']['coordination_id'];
if (!$mysql) {
    // The portable queue fixture omits the production created_at default.
    $pdo->prepare("UPDATE agent_workflow_runs SET created_at=CURRENT_TIMESTAMP WHERE id=? AND created_at=''")
        ->execute([$firstRun]);
}
canvas_pipeline_check(preg_match('/\A[a-f0-9]{64}\z/D', $situation) === 1,
    'the linked findings have a durable situation identity');
canvas_pipeline_check($runs[0]['goal']['primary'] === $gmailRef
    && canvas_pipeline_count('chat_messages', 'conversation_id=11') === $chatBefore,
    'the Gmail finding enters the shared scheduler without an independent Chat post');

$GLOBALS['coordinationModel'] = coordination_fixture_model($refs, $originalQuestion, $originalNext, $originalSummary, $firstRun);
$lost = vp3_initiative_execute_v1($pdo, $owner, canvas_pipeline_claim($firstRun), 'coordination_fixture_infer');
canvas_pipeline_check(($lost['reason'] ?? null) === 'execution_no_longer_active'
    && canvas_pipeline_count('agent_workflow_receipts', "run_id=$firstRun AND status='completed'") === 0
    && canvas_pipeline_count('chat_messages', 'conversation_id=11') === $chatBefore,
    'a lease lost during inference cannot commit a result or post to Chat');
canvas_pipeline_check(agent_job_recover_expired_v1900($pdo, $owner, 1, $firstRun)['recovered'] === 1,
    'the native worker recovers the same review after its lease expires');
$pdo->prepare("UPDATE agent_workflow_runs SET next_attempt_at='2000-01-01 00:00:00' WHERE id=?")->execute([$firstRun]);
$pdo->prepare("UPDATE agent_workflow_actions SET available_at='2000-01-01 00:00:00' WHERE run_id=?")->execute([$firstRun]);
coordination_fixture_schedule($owner);
canvas_pipeline_check(count(coordination_fixture_runs('coordination.review')) === 1,
    'a scheduler pass does not create a replacement run during native lease recovery');
$GLOBALS['coordinationModel'] = coordination_fixture_model($refs, $originalQuestion, $originalNext, $originalSummary);
canvas_pipeline_check(!empty(vp3_initiative_execute_v1($pdo, $owner, canvas_pipeline_claim($firstRun), 'coordination_fixture_infer')['ok']),
    'reclaiming the original run saves a verified coordination result');
$first = coordination_fixture_receipt($firstRun);
$firstOutput = $first['initiative_tool_result']['output'];
canvas_pipeline_check($first['effect_state'] === 'private_draft_ready' && $first['initiative_delivery'] === 'pending'
    && $firstOutput['type'] === 'initiative_coordination' && $firstOutput['situation_id'] === $situation
    && $firstOutput['state'] === 'needs_owner' && $firstOutput['summary'] === $originalSummary
    && $firstOutput['next_step'] === $originalNext && $firstOutput['question'] === $originalQuestion
    && count($firstOutput['evidence_revisions']) === 3
    && canvas_pipeline_count('agent_workflow_receipts', "run_id=$firstRun AND status='completed'") === 1
    && coordination_fixture_domain_state() === $domains,
    'one verified result retains the analysis, next step, question and source revisions without modifying owner records');

coordination_fixture_presence('busy');
canvas_pipeline_check(!vp3_initiative_deliver_pending_v1($pdo, $owner, $agentId)
    && canvas_pipeline_count('chat_messages', 'conversation_id=11') === $chatBefore,
    'the saved question waits while its owner is busy');
coordination_fixture_presence('quiet');
canvas_pipeline_check(vp3_initiative_deliver_pending_v1($pdo, $owner, $agentId)
    && !vp3_initiative_deliver_pending_v1($pdo, $owner, $agentId),
    'the owner’s quiet Chat receives the pending question exactly once');
$firstPosted = coordination_fixture_receipt($firstRun);
$firstMessage = (int)$firstPosted['initiative_message_id'];
$firstChat = coordination_fixture_message($firstMessage);
$chatContext = json_decode($firstChat['context_json'], true, 32, JSON_THROW_ON_ERROR);
canvas_pipeline_check((int)$firstChat['conversation_id'] === $chatId && $firstChat['role'] === 'assistant'
    && str_contains($firstChat['message'], $originalQuestion) && str_contains($firstChat['message'], $originalNext)
    && ($chatContext['initiative_output'] ?? null) === $firstOutput
    && !array_diff($refs, array_column($chatContext['sources'], 'source'))
    && canvas_pipeline_count('chat_messages', 'conversation_id=11') === $chatBefore+1
    && canvas_pipeline_count('chat_messages', 'conversation_id IN (12,13)') === 0,
    'main Chat receives the actual finding and question with exact provenance in the linked conversation');

$shownChat = vp3_coordination_chat_history_v1($pdo, $owner, $agentId, $chatId, [$firstChat])[0];
canvas_pipeline_check($shownChat === $firstChat, 'Chat history shows the verified finding while all specialist evidence is current');
$visible = vp3_coordination_visible_v1($pdo, $owner, $agentId, $chatId);
canvas_pipeline_check(count($visible) === 1 && $visible[0]['id'] === $situation
    && $visible[0]['message_id'] === $firstMessage && $visible[0]['question'] === $originalQuestion
    && ($visible[0]['routing'] ?? null) === $runs[0]['goal']['coordination_route'],
    'the Orb projection and main Chat agree on the matched rule and current message');
$orbScope = vp3_agent_activity_scope_v1($pdo,$owner,$agentId,$chatId);
$orbSummary = static fn(): array => vp3_agent_activity_summary_v1($pdo,$orbScope,new DateTimeImmutable('now'),
    vp3_agent_activity_coordination_v1($pdo,$orbScope));
$summaryBefore = $orbSummary();
$updatesBefore = array_column($summaryBefore['updates'],null,'specialist_id');
canvas_pipeline_check($summaryBefore['overview'] === $originalSummary
    && count($updatesBefore) === 3 && isset($updatesBefore['gmail'],$updatesBefore['tasks'],$updatesBefore['planning'])
    && !str_contains(json_encode($summaryBefore,JSON_THROW_ON_ERROR),'COORDINATION_TASK_PRIVATE_BODY'),
    'the Orb summary combines the verified coordination analysis with current findings from all three specialists');
canvas_pipeline_check(vp3_coordination_visible_v1($pdo, $owner, 8, 12) === []
    && vp3_coordination_visible_v1($pdo, ['id'=>2,'is_active'=>1], 9, 13) === [],
    'unrelated Agents and accounts have no access to the coordination finding');
$foreignChat = vp3_coordination_chat_history_v1($pdo, ['id'=>2,'is_active'=>1], 9, 13, [$firstChat])[0];
canvas_pipeline_check(!str_contains($foreignChat['message'], $originalSummary)
    && !str_contains($foreignChat['context_json'], $gmailRef),
    'replaying the exact saved Chat finding under another account withholds the content and evidence');
$actionProof = $pdo->prepare("SELECT id,result_json FROM agent_workflow_actions WHERE run_id=? AND status='completed'");
$actionProof->execute([$firstRun]);
$savedAction = $actionProof->fetch(PDO::FETCH_ASSOC);
$fakeAction = json_decode($savedAction['result_json'], true, 32, JSON_THROW_ON_ERROR);
$fakeAction['initiative_tool_result']['output']['summary'] = 'A summary without a matching receipt.';
$changeProof = $pdo->prepare("UPDATE agent_workflow_actions SET result_json=? WHERE id=? AND run_id=?");
$changeProof->execute([json_encode($fakeAction, JSON_THROW_ON_ERROR), $savedAction['id'], $firstRun]);
try {
    canvas_pipeline_check(vp3_coordination_visible_v1($pdo, $owner, $agentId, $chatId) === []
        && vp3_coordination_chat_history_v1($pdo, $owner, $agentId, $chatId, [$firstChat])[0]['message'] !== $firstChat['message'],
        'a mismatched action and receipt cannot prove a finding in either the Orb or Chat history');
} finally {
    $changeProof->execute([$savedAction['result_json'], $savedAction['id'], $firstRun]);
}

coordination_fixture_gmail_observe($pdo, $owner);
$afterDuplicate = vp3_coordination_sources_v1($pdo, $owner, $binding);
coordination_fixture_schedule($owner);
canvas_pipeline_check($afterDuplicate['items'][$gmailRef]['revision'] === $discovered['items'][$gmailRef]['revision']
    && count(coordination_fixture_runs('coordination.review')) === 1
    && canvas_pipeline_count('chat_messages', 'conversation_id=11') === $chatBefore+1,
    'a repeated Gmail observation and due scan neither change the finding revision nor queue or post duplicate work');

$replacementStart = (new DateTimeImmutable($originalStart, new DateTimeZone('UTC')))->modify('+1 day');
$pdo->prepare('UPDATE user_calendar_events SET start_at_utc=?,end_at_utc=?,updated_at=CURRENT_TIMESTAMP WHERE id=701 AND owner_user_id=1')
    ->execute([$replacementStart->format('Y-m-d H:i:s'), $replacementStart->modify('+1 hour')->format('Y-m-d H:i:s')]);
$afterChange = vp3_coordination_sources_v1($pdo, $owner, $binding, [$calendarRef]);
canvas_pipeline_check($afterChange['items'][$calendarRef]['revision'] !== $discovered['items'][$calendarRef]['revision']
    && vp3_coordination_visible_v1($pdo, $owner, $agentId, $chatId) === [],
    'an edited Calendar event invalidates the prior question in the current Orb projection');
$summaryChanged = $orbSummary();
$updatesChanged = array_column($summaryChanged['updates'],null,'specialist_id');
canvas_pipeline_check(!isset($summaryChanged['overview']) && $updatesChanged['planning'] !== $updatesBefore['planning']
    && $updatesChanged['gmail'] === $updatesBefore['gmail'] && $updatesChanged['tasks'] === $updatesBefore['tasks'],
    'the Orb summary reflects the changed Planning evidence and drops its superseded overview on the next read');
$hiddenChat = vp3_coordination_chat_history_v1($pdo, $owner, $agentId, $chatId, [$firstChat])[0];
canvas_pipeline_check($hiddenChat['message'] === 'The information behind this Top of Mind finding is no longer available.'
    && !str_contains($hiddenChat['context_json'], $originalQuestion) && !str_contains($hiddenChat['context_json'], $gmailRef),
    'Chat history withholds the outdated content and source links after the same edit');
$domains = coordination_fixture_domain_state();
$staleText = 'The original rehearsal works.';
$stalePreflight = vp3_initiative_followthrough_request_v1($pdo, $owner, $principal, $chatId, $staleText);
canvas_pipeline_check(($stalePreflight['initiative_reply']['message_id'] ?? null) === $firstMessage,
    'ordinary Chat still recognizes the exact historical question before handling an outdated answer');
$staleReplyId = coordination_fixture_reply($staleText);
$GLOBALS['coordinationModel'] = static function (): never {
    throw new LogicException('An outdated source must not reach the model or start an action.');
};
$staleReply = vp3_initiative_followthrough_reply_v1($pdo, $owner, $principal, $chatId, $staleReplyId, null, $staleText, null, 'coordination_fixture_infer');
canvas_pipeline_check(($staleReply['advisory_context']['coordination_source_changed'] ?? null) === true
    && ($staleReply['initiative_followthrough']['state'] ?? null) === 'stale'
    && ($staleReply['initiative_followthrough']['continuation_run_id'] ?? null) === 0
    && count(coordination_fixture_runs('coordination.review')) === 1 && coordination_fixture_domain_state() === $domains,
    'a stale ordinary Chat answer receives a safe advisory without inference, a continuation, or a domain action');
$activeAgent = vp3_agent_chat_resolve_agent_v380($pdo, $owner, $agentId);
$staleAck = vp3_agent_chat_followthrough_finish_v2160($pdo, $owner, $activeAgent, $principal, $chatId, $staleReplyId,
    $staleText, 'text', $staleReply);
canvas_pipeline_check($staleAck['ok'] && str_contains($staleAck['answer'], 'information behind that Top of Mind question changed')
    && !str_contains($staleAck['answer'], $originalQuestion)
    && canvas_pipeline_count('ai_execution_ledger', "conversation_id=11 AND agent_id=7 AND source='vp3_tool'") === 1,
    'the ordinary Chat runtime saves a safe acknowledgment for the stale owner answer');

$updatedQuestion = 'Should I prepare the fallback delivery plan for the rescheduled rehearsal?';
$updatedNext = 'Outline a delivery fallback using the rescheduled rehearsal time.';
$updatedSummary = 'The rehearsal moved one day. The vendor confirmation task remains open, so a fallback is still useful.';
coordination_fixture_presence('quiet');
coordination_fixture_schedule($owner);
$runs = coordination_fixture_runs('coordination.review');
canvas_pipeline_check(count($runs) === 2 && $runs[1]['goal']['coordination_id'] === $situation
    && $runs[1]['goal']['sources'][$calendarRef] === $afterChange['items'][$calendarRef]['revision'],
    'a changed source triggers a reassessment of the same situation with the new revision');
$secondRun = $runs[1]['id'];
$GLOBALS['coordinationModel'] = coordination_fixture_model($refs, $updatedQuestion, $updatedNext, $updatedSummary);
canvas_pipeline_check(!empty(vp3_initiative_execute_v1($pdo, $owner, canvas_pipeline_claim($secondRun), 'coordination_fixture_infer')['ok']),
    'the scoped worker saves the updated cross-specialist analysis');
$second = coordination_fixture_receipt($secondRun);
if ($second['initiative_delivery'] === 'pending') {
    vp3_initiative_deliver_pending_v1($pdo, $owner, $agentId);
    $second = coordination_fixture_receipt($secondRun);
}
$secondMessage = (int)$second['initiative_message_id'];
canvas_pipeline_check($secondMessage > $firstMessage && str_contains(coordination_fixture_message($secondMessage)['message'], $updatedQuestion),
    'the current question returns to main Chat after reassessment');

$ownerAnswer = 'The later rehearsal works. Please prepare that fallback.';
$preflight = vp3_initiative_followthrough_request_v1($pdo, $owner, $principal, $chatId, $ownerAnswer);
canvas_pipeline_check(($preflight['initiative_reply']['message_id'] ?? null) === $secondMessage && !$preflight['explicit'],
    'ordinary typed Chat binds the answer to the current exact question without a button');
$answerId = coordination_fixture_reply($ownerAnswer);
$GLOBALS['coordinationModel'] = static function(array $request, string $protocol)
    use ($ownerAnswer, $answerId, $secondMessage, $secondRun, $updatedQuestion, $refs): array {
    canvas_pipeline_check($protocol === 'vp3.initiative-reply.v1'
        && $request['owner_message'] === ['message_id'=>$answerId,'text'=>$ownerAnswer]
        && $request['proposal']['message_id'] === $secondMessage && $request['proposal']['run_id'] === $secondRun
        && $request['coordination']['question'] === $updatedQuestion
        && !array_diff($refs, array_column($request['proposal']['source_summaries'], 'ref'))
        && $request['action_catalog'] === [] && $request['action_resources'] === [],
        'the exact owner answer reaches the governed Chat classifier with no authority to change a source');
    return ['intent'=>'advance', 'focus'=>'Prepare the private delivery fallback for the rescheduled rehearsal.'];
};
$answer = vp3_initiative_followthrough_reply_v1($pdo, $owner, $principal, $chatId, $answerId, null, $ownerAnswer, null, 'coordination_fixture_infer');
$followthrough = $answer['initiative_followthrough'];
$continuation = $followthrough['continuation_run_id'];
$runs = coordination_fixture_runs('coordination.review');
canvas_pipeline_check($answer['handled'] && $followthrough['message_id'] === $secondMessage && $followthrough['state'] === 'queued'
    && ($answer['advisory_context']['private_work_only'] ?? null) === true
    && count($runs) === 3 && $runs[2]['id'] === $continuation && $runs[2]['goal']['coordination_id'] === $situation
    && $runs[2]['goal']['followthrough']['reply_message_id'] === $answerId
    && $runs[2]['goal']['sources'] === $second['initiative_tool_result']['output']['evidence_revisions'],
    'the owner response queues one private continuation for the same verified finding and exact source set');
$pdo->prepare("INSERT INTO chat_messages(conversation_id,role,message,context_json) VALUES(11,'assistant',?,?)")
    ->execute(['I will prepare the fallback for review.', json_encode(['initiative_followthrough'=>$followthrough,
        'initiative_coordination_reply_v1'=>true], JSON_THROW_ON_ERROR)]);

$preparedSummary = 'Fallback prepared for the later rehearsal: reserve the early delivery slot and use the alternate carrier if the vendor misses confirmation.';
$GLOBALS['coordinationModel'] = static function(array $request, string $protocol)
    use ($ownerAnswer, $updatedQuestion, $refs, $preparedSummary): array {
    canvas_pipeline_check($protocol === 'vp3.initiative-draft.v1' && $request['response_kind'] === 'coordination_work'
        && $request['owner_reply'] === $ownerAnswer && $request['previous_finding']['question'] === $updatedQuestion
        && !array_diff($refs, array_column($request['specialist_reports'], 'ref')),
        'the native worker receives the actual owner answer, prior finding and verified specialist evidence');
    return ['title'=>'Vendor delivery fallback', 'summary'=>$preparedSummary, 'next_step'=>'Review the draft fallback.',
        'question'=>'', 'state'=>'resolved', 'refs'=>[$request['primary'], ...array_diff($refs, [$request['primary']])]];
};
canvas_pipeline_check(!empty(vp3_initiative_execute_v1($pdo, $owner, canvas_pipeline_claim($continuation), 'coordination_fixture_infer')['ok']),
    'the native continuation completes after the owner answer');
$completed = coordination_fixture_receipt($continuation);
if ($completed['initiative_delivery'] === 'pending') {
    vp3_initiative_deliver_pending_v1($pdo, $owner, $agentId);
    $completed = coordination_fixture_receipt($continuation);
}
$completionMessage = (int)$completed['initiative_message_id'];
$completionChat = coordination_fixture_message($completionMessage);
$current = vp3_coordination_visible_v1($pdo, $owner, $agentId, $chatId);
canvas_pipeline_check($completed['effect_state'] === 'private_draft_ready' && $completionMessage > $answerId
    && str_contains($completionChat['message'], $preparedSummary) && (int)$completionChat['conversation_id'] === $chatId
    && count($current) === 1 && $current[0]['id'] === $situation && $current[0]['state'] === 'resolved'
    && $current[0]['message_id'] === $completionMessage && coordination_fixture_domain_state() === $domains,
    'the verified private result returns to the same Chat and replaces the same Orb finding without changing owner records');

coordination_fixture_schedule($owner);
canvas_pipeline_check(count(coordination_fixture_runs('coordination.review')) === 3,
    'another due pass cannot repeat the resolved situation when specialist evidence is unchanged');

coordination_fixture_gmail_observe($pdo, $owner, 2, null);
$partialSnapshot = vp3_coordination_sources_v1($pdo, $owner, $binding);
canvas_pipeline_check(!isset($partialSnapshot['items'][$gmailRef]) && $partialSnapshot['coverage']['gmail'] === 'limited',
    'a new Gmail message makes its previous sealed analysis unavailable while the new message is waiting for review');
coordination_fixture_schedule($owner);
$runs = coordination_fixture_runs('coordination.review');
$partial = $runs[3] ?? [];
$remainingRefs = ['office:'.$taskRef, $calendarRef];
canvas_pipeline_check(count($runs) === 4 && $partial['goal']['coordination_id'] === $situation
    && array_keys($partial['goal']['sources']) === $remainingRefs
    && $partial['goal']['coordination_coverage']['gmail'] === 'limited',
    'incomplete Gmail coverage reopens the same situation with exact evidence from the other available specialists');
$partialSummary = 'The rehearsal and confirmation task are current. A new vendor email is awaiting analysis.';
$GLOBALS['coordinationModel'] = static function(array $request, string $protocol) use ($remainingRefs, $partialSummary): array {
    $supplied = array_column($request['specialist_reports'], 'ref');
    sort($supplied);
    $expected = $remainingRefs;
    sort($expected);
    canvas_pipeline_check($protocol === 'vp3.initiative-draft.v1' && $request['coverage']['gmail'] === 'limited'
        && $supplied === $expected && !isset($request['previous_finding']),
        'the reassessment receives limited coverage and available reports without recycling stale email analysis');
    return ['title'=>'Vendor delivery under review', 'summary'=>$partialSummary, 'next_step'=>'Wait for the current vendor email finding.',
        'question'=>'', 'state'=>'watching', 'refs'=>$remainingRefs];
};
$chatCount = canvas_pipeline_count('chat_messages', 'conversation_id=11');
canvas_pipeline_check(!empty(vp3_initiative_execute_v1($pdo, $owner, canvas_pipeline_claim($partial['id']), 'coordination_fixture_infer')['ok'])
    && coordination_fixture_receipt($partial['id'])['initiative_delivery'] === 'private'
    && canvas_pipeline_count('chat_messages', 'conversation_id=11') === $chatCount,
    'the incomplete assessment saves useful private context without re-asking the owner');

coordination_fixture_gmail_observe($pdo, $owner, 3, 'The vendor confirmed delivery for the rescheduled rehearsal.', 'resolved');
coordination_fixture_schedule($owner);
$runs = coordination_fixture_runs('coordination.review');
$restored = $runs[4] ?? [];
canvas_pipeline_check(count($runs) === 5 && $restored['goal']['coordination_id'] === $situation
    && $restored['goal']['coordination_coverage']['gmail'] === 'ready' && isset($restored['goal']['sources'][$gmailRef]),
    'the latest verified Gmail analysis rejoins the same situation after partial coverage');
$GLOBALS['coordinationModel'] = static function(array $request) use ($refs): array {
    canvas_pipeline_check($request['coverage']['gmail'] === 'ready' && $request['previous_finding']['state'] === 'watching'
        && !array_diff($refs, array_column($request['specialist_reports'], 'ref')),
        'the restored assessment sees the same situation and all three available specialists');
    return ['title'=>'Vendor delivery confirmed', 'summary'=>'The vendor confirms the timing and the rehearsal remains scheduled.',
        'next_step'=>'Keep the confirmation task in view.', 'question'=>'', 'state'=>'watching', 'refs'=>$refs];
};
canvas_pipeline_check(!empty(vp3_initiative_execute_v1($pdo, $owner, canvas_pipeline_claim($restored['id']), 'coordination_fixture_infer')['ok']),
    'the native worker saves the restored cross-specialist evidence');

$lateStart = $replacementStart->modify('+2 days');
$pdo->prepare("INSERT INTO user_calendar_events SELECT 703,owner_user_id,'Vendor fallback check',
    'Confirm the alternate delivery carrier for the launch rehearsal.',location,?,?,timezone,all_day,
    recurrence,agent_notifications,source,source_reference,status,created_by_agent_id,CURRENT_TIMESTAMP
    FROM user_calendar_events WHERE id=701 AND owner_user_id=1")->execute([
        $lateStart->format('Y-m-d H:i:s'),$lateStart->modify('+1 hour')->format('Y-m-d H:i:s'),
    ]);
$lateRef = 'planning:calendar:event:703';
coordination_fixture_schedule($owner);
$runs = coordination_fixture_runs('coordination.review');
canvas_pipeline_check(count($runs) === 5 && isset(vp3_coordination_sources_v1($pdo,$owner,$binding)['items'][$lateRef]),
    'a routine calendar entry remains available as evidence without queueing an independent review');

coordination_fixture_gmail_observe($pdo, $owner, 4, 'The vendor withdrew its delivery confirmation for the rehearsal.');
coordination_fixture_rule($pdo,$owner,'gmail','needs_attention','prepare');
coordination_fixture_presence('away');
$pdo->prepare("UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE id=?")->execute([$completionMessage]);
$beforeAutomaticWork = coordination_fixture_domain_state();
coordination_fixture_schedule($owner);
$runs = coordination_fixture_runs('coordination.review');
$assignment = $runs[5] ?? [];
$assignmentRefs = [...$refs, $lateRef];
$assignmentFocus = 'Prepare a private fallback checklist for the rehearsal without changing any records.';
canvas_pipeline_check(count($runs) === 6 && $assignment['goal']['coordination_id'] === $situation
    && $assignment['goal']['coordination_priority'] === 'high'
    && ($assignment['goal']['coordination_route']['action'] ?? null) === 'prepare',
    'a significant update wakes the same situation under the edited private-preparation rule');
$GLOBALS['coordinationModel'] = static function(array $request) use ($assignmentRefs, $assignmentFocus): array {
    canvas_pipeline_check(!array_diff($assignmentRefs,array_column($request['specialist_reports'],'ref')),
        'a routine calendar entry can support related actionable evidence');
    return ['title'=>'Prepare the vendor fallback', 'summary'=>'The vendor withdrew confirmation and the rehearsal is still scheduled.',
        'next_step'=>$assignmentFocus, 'question'=>'', 'state'=>'agent_work', 'refs'=>$assignmentRefs,
        'work_specialist'=>'tasks'];
};
canvas_pipeline_check(!empty(vp3_initiative_execute_v1($pdo, $owner, canvas_pipeline_claim($assignment['id']), 'coordination_fixture_infer')['ok'])
    && coordination_fixture_receipt($assignment['id'])['initiative_delivery'] === 'private',
    'the assessment saves a private assignment when an Agent can prepare the next step independently');
$runs = coordination_fixture_runs('coordination.review');
$automatic = $runs[6] ?? [];
coordination_fixture_schedule($owner);
$current = vp3_coordination_visible_v1($pdo, $owner, $agentId, $chatId);
canvas_pipeline_check(count($runs) === 7 && count(coordination_fixture_runs('coordination.review')) === 7
    && $automatic['goal']['coordination_work'] === ['parent_run_id'=>$assignment['id'], 'specialist'=>'tasks']
    && $automatic['goal']['coordination_id'] === $situation && $automatic['goal']['sources'] === $assignment['goal']['sources']
    && count($current) === 1 && $current[0]['state'] === 'agent_work' && $current[0]['work_status'] === 'queued',
    'one linked native Agent Task is queued and shown while duplicate scans are suppressed');
$automaticSummary = 'Fallback checklist prepared: verify the alternate carrier, reserve an earlier window and check the rehearsal handoff.';
$GLOBALS['coordinationModel'] = static function(array $request, string $protocol)
    use ($assignmentRefs, $assignmentFocus, $automaticSummary): array {
    canvas_pipeline_check($protocol === 'vp3.initiative-draft.v1' && $request['response_kind'] === 'coordination_work'
        && $request['work_specialist'] === 'tasks' && $request['assignment'] === $assignmentFocus
        && !isset($request['owner_reply']) && !array_diff($assignmentRefs, array_column($request['specialist_reports'], 'ref')),
        'the Agent Task worker receives its exact assignment and current evidence without inventing owner authorization');
    return ['title'=>'Vendor fallback checklist', 'summary'=>$automaticSummary,
        'next_step'=>'Review the checklist before the rehearsal.', 'question'=>'', 'state'=>'resolved', 'refs'=>$assignmentRefs];
};
$beforeAutomaticChat = canvas_pipeline_count('chat_messages', 'conversation_id=11');
canvas_pipeline_check(!empty(vp3_initiative_execute_v1($pdo, $owner, canvas_pipeline_claim($automatic['id']), 'coordination_fixture_infer')['ok'])
    && coordination_fixture_receipt($automatic['id'])['initiative_delivery'] === 'private'
    && canvas_pipeline_count('chat_messages', 'conversation_id=11') === $beforeAutomaticChat,
    'the verified Agent Task result follows the private preparation rule');
coordination_fixture_presence('quiet');
canvas_pipeline_check(!vp3_initiative_deliver_pending_v1($pdo, $owner, $agentId)
    && canvas_pipeline_count('chat_messages', 'conversation_id=11') === $beforeAutomaticChat,
    'quiet Chat does not publish work prepared under a private rule');
$automaticReceipt = coordination_fixture_receipt($automatic['id']);
$current = vp3_coordination_visible_v1($pdo, $owner, $agentId, $chatId);
canvas_pipeline_check(str_contains($current[0]['summary'] ?? '', $automaticSummary)
    && count($current) === 1 && $current[0]['id'] === $situation && $current[0]['state'] === 'resolved'
    && (int)$automaticReceipt['initiative_message_id'] === 0 && coordination_fixture_domain_state() === $beforeAutomaticWork,
    'the automatic result is visible in the same Orb finding and no owner record was modified');
echo "Coordination pipeline passed $checks checks.\n";
