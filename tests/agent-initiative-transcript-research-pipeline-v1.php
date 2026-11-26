<?php
declare(strict_types=1);

require __DIR__.'/fixtures/initiative-scope-ready-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-transcripts-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-transcript-research-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-research-canvas-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-transcript-report-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-engagement-status-v1.php';
require_once dirname(__DIR__).'/includes/agent-top-of-mind-v1.php';
require_once dirname(__DIR__).'/includes/agent-planning-status-v1.php';

$user = ['id'=>1, 'is_active'=>1];
scope_command($user, 17, 170, 'off');
scope_command($user, 17, 170, 'on');
$binding = vp3_initiative_policy_v1($user, 17);
$scope = vp3_initiative_tools_scope_v1($pdo, $user, $binding);
$oldReportAt=gmdate('Y-m-d H:i:s',time()-121);
$ageReports=$pdo->prepare("UPDATE chat_messages SET created_at=? WHERE conversation_id=170
    AND role='assistant' AND context_json IS NOT NULL");
$planningStatus=static fn():array=>vp3_initiative_in_scope_v1($user,17,static fn():array=>
    vp3_planning_status_read_v1($pdo,$user,$scope['principal'],17,170,$binding));
$pdo->exec("CREATE TABLE IF NOT EXISTS settings(setting_key TEXT PRIMARY KEY,setting_value TEXT);
    CREATE TABLE artist_transcript_sessions_v172(id INTEGER PRIMARY KEY,created_by_user_id INTEGER,conversation_id INTEGER,
        title TEXT,status TEXT,started_at TEXT,last_activity_at TEXT,metadata_json TEXT);
    CREATE TABLE artist_transcript_segments_v172(id INTEGER PRIMARY KEY,session_id INTEGER,client_segment_key TEXT,
        segment_index INTEGER,segment_type TEXT,speaker_label TEXT,transcript_text TEXT,started_ms INTEGER,ended_ms INTEGER,
        confidence REAL,created_at TEXT,updated_at TEXT);
    CREATE TABLE artist_transcript_page_analysis_v237(session_id INTEGER,page_number INTEGER,source_hash TEXT,
        source_word_count INTEGER,start_segment_index INTEGER,end_segment_index INTEGER,analysis_json TEXT,provider TEXT,
        model TEXT,generated_at TEXT,PRIMARY KEY(session_id,page_number));
    CREATE TABLE artist_transcript_master_analysis_v237(session_id INTEGER PRIMARY KEY,source_hash TEXT,source_word_count INTEGER,
        page_count INTEGER,analyzed_page_count INTEGER,analysis_json TEXT,research_json TEXT,provider TEXT,model TEXT,generated_at TEXT);
    INSERT INTO artist_transcript_sessions_v172 VALUES
        (940201,1,170,'Private Supplier Delta discussion','draft','2020-01-01 00:00:00','2020-01-01 00:05:00','{}');
    INSERT INTO artist_transcript_segments_v172 VALUES
        (940202,940201,'fixture-segment',0,'transcript','Owner',
        'RAW PRIVATE RECORDING DETAILS. We discussed oven ventilation.',1000,5000,.9,'2020-01-01','2020-01-01')");
$items = [
    ['item_id'=>'public-guidance', 'question'=>'How should we evaluate oven ventilation for the private Supplier Delta quote?',
        'follow_up'=>'Find published ventilation guidance.', 'review_state'=>'unreviewed',
        'evidence_refs'=>[['page'=>1, 'label'=>'Page 1']]],
    ['item_id'=>'private-choice', 'question'=>'Which confidential supplier quote should we accept?',
        'follow_up'=>'Review the private proposals.', 'review_state'=>'unreviewed'],
    ['item_id'=>'revoked-research', 'question'=>'What are common oven maintenance requirements?',
        'follow_up'=>'Check manufacturer guidance.', 'review_state'=>'unreviewed'],
];
$map = artist_listening_transcript_page_map(artist_listening_v172_segments($pdo, 940201));
$master = ['modules'=>['qa'=>[
    'source_hash'=>$map['source_hash'],
    'generated_at'=>gmdate('c'),
    'result'=>['unanswered'=>$items],
]]];
$pdo->prepare("INSERT INTO artist_transcript_master_analysis_v237 VALUES(940201,?,?,?,?,?,'{}','fixture','fixture',?)")
    ->execute([$map['source_hash'], $map['total_words'], $map['page_count'], 0, json_encode($master, JSON_THROW_ON_ERROR), gmdate('c')]);

$activity = $pdo->prepare("UPDATE agent_activity_state SET context_key='chat:170',details_json=?,last_heartbeat_at=NOW() WHERE user_id=1");
$activity->execute(['{"visible":false,"conversation_id":170}']);
$evidence = static function () use ($pdo, $scope, $binding): array {
    $coverage = [];
    return vp3_initiative_transcript_research_evidence_v1(
        $pdo, $scope, $binding, new DateTimeImmutable('now', new DateTimeZone('UTC')), null, $coverage
    );
};
scope_check($evidence()===[], 'saved plugin suggestions cannot authorize public research without owner consent');
vp3_transcript_research_save_v1($pdo, 1, true);
$sources = $evidence();
$sourceByItem = [];
foreach ($sources as $ref => $source) {
    $sourceByItem[$source['research']['item_id']] = $ref;
}
scope_check(count($sources)===3 && isset($sourceByItem['public-guidance'], $sourceByItem['private-choice'], $sourceByItem['revoked-research'])
    && vp3_transcript_focus_id_v1($pdo, 1, 17, 170)===0,
    'an enabled owner’s older saved plugin findings are eligible with no open slideout');

// The shared scheduler may also prepare a coordination review for the same
// transcript. Count Research jobs by their real tool, while retaining exact
// cardinality and source checks so extra or misbound Research jobs still fail.
$researchRuns = static fn(): array => array_values(array_filter(scope_runs(1,17,'initiative.prepare'),
    static fn(array $run): bool => (json_decode($run['goal'],true,24,JSON_THROW_ON_ERROR)['tool']??null)==='topic.research'));
$before = count($researchRuns());
$ref = $sourceByItem['public-guidance'];
vp3_initiative_in_scope_v1($user,17,static fn()=>vp3_initiative_schedule_scoped_v1($pdo,$user,[$ref]));
$fallbackPlan = (int)array_slice(scope_runs(1,17,'initiative.plan'),-1)[0]['id'];
$fallbackPlanned = vp3_initiative_execute_v1($pdo,$user,scope_claim($user,$fallbackPlan),
    static function(string $prompt,string $protocol)use($ref):string{
        $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
        scope_check($protocol==='vp3.initiative-plan.v1'&&($input['tools']['topic.research']['source_refs']??null)===[$ref],
            'an exact saved transcript question is eligible for the normal assessment');
        return '{"jobs":[],"opportunities":[]}';
    });
$fallbackRuns = array_slice($researchRuns(),$before);
$fallbackGoal = json_decode((string)($fallbackRuns[0]['goal']??'{}'),true,24,JSON_THROW_ON_ERROR);
scope_check($fallbackPlanned['ok']&&count($fallbackRuns)===1&&($fallbackGoal['tool']??null)==='topic.research'
    && ($fallbackGoal['primary']??null)===$ref&&($fallbackGoal['sources']??null)===[$ref=>$sources[$ref]['revision']]
    && ($fallbackGoal['report']??null)===true,
    'an empty model assessment admits one exact authorized transcript research job');

$remaining = array_diff_key($sourceByItem,['public-guidance'=>true]);
$planId = scope_make_plan($user, 17, 'Transcript research pipeline fixture');
$planned = vp3_initiative_execute_v1($pdo, $user, scope_claim($user, $planId),
    static function (string $prompt, string $protocol) use ($remaining,$ref): string {
        $input = json_decode($prompt, true, 32, JSON_THROW_ON_ERROR);
        $eligible = $input['tools']['topic.research']['source_refs'] ?? [];
        scope_check($protocol==='vp3.initiative-plan.v1' && !array_diff($remaining, $eligible) && !in_array($ref,$eligible,true),
            'the normal assessment still offers exact saved plugin sources and excludes active research');
        $researchReports=array_values(array_filter($input['specialist_reports']??[],static fn(array $report):bool=>$report['specialist']==='research'));
        scope_check($researchReports===[[
            'specialist'=>'research','state'=>'queued','source_kind'=>'transcript_research',
        ]], 'the existing assessment sees active transcript research as context without a dispatchable ref');
        $jobs = [];
        foreach ($remaining as $primary) {
            $jobs[] = ['tool'=>'topic.research', 'primary'=>$primary, 'refs'=>[$primary],
                'focus'=>'Use the private Supplier Delta quote', 'report'=>true];
        }
        return json_encode(['jobs'=>$jobs], JSON_THROW_ON_ERROR);
    });
$runs = array_slice($researchRuns(), $before);
$runBySource = [];
foreach ($runs as $run) {
    $goal = json_decode($run['goal'], true, 24, JSON_THROW_ON_ERROR);
    $runBySource[$goal['primary']] = (int)$run['id'];
}
scope_check($planned['ok'] && count($runs)===3 && !array_diff($sourceByItem, array_keys($runBySource)),
    'model selections and the bounded fallback share the exact leased research queue');

$otherRef = 'transcript-research:940203:'.str_repeat('a',64);
$duplicate = array_replace($sources[$ref],['ref'=>$otherRef,'revision'=>str_repeat('b',64)]);
scope_check(vp3_initiative_unassigned_transcript_research_v1($pdo,1,$binding,$sources+[$otherRef=>$duplicate])===[],
    'active work coalesces equivalent questions across saved recording sources');
$classifier = dirname(__DIR__).'/includes/agent-transcript-research-plan-v1.php';
require_once $classifier;
foreach (['Supplier purchasing contact person@example.test','commercial ovens account number 12345678',
    'Supplier Delta quote','commercial oven prices Supplier Delta'] as $invalid) {
    $rejected = false;
    try {
        vp3_initiative_transcript_research_plan_v1($pdo,$scope,$sources[$ref],
            static fn():string=>json_encode(['decision'=>'research','topic'=>$invalid],JSON_THROW_ON_ERROR));
    } catch (RuntimeException $error) {
        $rejected = $error->getCode()===503;
    }
    scope_check($rejected,'the classifier refuses an unsafe query without resolving the saved question');
}
$runId = $runBySource[$ref];
$claim = scope_claim($user, $runId);
$topic = 'commercial oven ventilation';
$publicUrl = 'https://example.org/commercial-ovens/ventilation';
$quote = 'Ventilation design depends on the appliance and installation conditions.';
$searches = [];
$requests = [];
$completed = vp3_initiative_execute_v1($pdo, $user, $claim,
    static function (string $prompt, string $protocol) use ($topic, $publicUrl, $quote): string {
        $input = json_decode($prompt, true, 24, JSON_THROW_ON_ERROR);
        if ($protocol==='vp3.transcript-research-plan.v1') {
            scope_check(str_contains($input['question'], 'private Supplier Delta quote')
                && !str_contains($prompt, 'RAW PRIVATE RECORDING DETAILS'),
                'the private classifier receives the exact finding without copying the entire recording');
            return json_encode(['decision'=>'research', 'topic'=>$topic], JSON_THROW_ON_ERROR);
        }
        $citation = 'topic-web:'.hash('sha256', $publicUrl);
        scope_check($protocol==='vp3.topic-research.v1' && ($input['topic'] ?? null)===$topic
            && array_column($input['sources'], 'source')===[$citation] && !str_contains($prompt, 'Supplier Delta'),
            'public synthesis receives the derived topic and verified public source');
        return json_encode(['findings'=>[['source'=>$citation, 'quote'=>$quote]]], JSON_THROW_ON_ERROR);
    }, [
        'search'=>static function (string $query) use ($pdo, $user, $claim, $topic, $publicUrl, &$searches): array {
            $searches[] = $query;
            scope_check($query===$topic && agent_worker_cloud_active_row_v1910($pdo, $user, $claim)!==null,
                'only the derived public topic reaches search while the exact worker lease is active');
            return ['ok'=>true, 'results'=>[['url'=>$publicUrl, 'title'=>'Commercial oven ventilation']]];
        },
        'request'=>static function (string $url) use ($quote, &$requests): array {
            $requests[] = $url;
            return ['status'=>200, 'content_type'=>'text/plain',
                'body'=>$quote.' Confirm the applicable requirements with the manufacturer and local authority.'];
        },
    ], allowDelivery:false);
$action = $pdo->prepare('SELECT result_json FROM agent_workflow_actions WHERE run_id=?');
$receipt = $pdo->prepare('SELECT result_json FROM agent_workflow_receipts WHERE run_id=?');
$action->execute([$runId]);
$receipt->execute([$runId]);
$json = $action->fetchColumn();
$result = json_decode((string)$json, true, 24, JSON_THROW_ON_ERROR);
$tool = $result['initiative_tool_result'];
scope_check($completed['ok'] && $json===$receipt->fetchColumn() && $result['initiative_delivery']==='pending'
    && $tool['output']['source_ref']===$ref && $tool['output']['source_revision']===$sources[$ref]['revision']
    && $tool['output']['public_topic']===$topic && $searches===[$topic] && $requests===[$publicUrl]
    && array_column($tool['sources'], 'url')===[$publicUrl],
    'the preparation-only worker saves exact finding provenance and verified public citations before Chat publication');
$transcriptFollowups = static fn():array=>vp3_initiative_transcript_report_followups_v1($pdo,$user,17,170,940201);
scope_check($transcriptFollowups()===[], 'the saved recording does not display an undelivered Research result');
$reassessment=scope_make_plan($user,17,'Assess a verified transcript research report');
$reassessed=vp3_initiative_execute_v1($pdo,$user,scope_claim($user,$reassessment),
    static function(string $prompt,string $protocol)use($quote,$ref):string{
        $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
        $reports=array_values(array_filter($input['specialist_reports']??[],static fn(array $report):bool=>($report['state']??null)==='ready'));
        $researchEvidence=array_values(array_filter($input['evidence'],static fn(array $source):bool=>($source['kind']??null)==='specialist_result'));
        $dispatchableRefs=array_merge(...array_values(array_map(static fn(array $tool):array=>$tool['source_refs']??[],$input['tools'])));
        scope_check($protocol==='vp3.initiative-plan.v1'&&count($reports)===1
            &&($reports[0]['outcome']??null)==='read_complete'&&($reports[0]['page_excerpts']??null)===1
            &&str_contains($reports[0]['summary']??'',$quote)&&!in_array($ref,array_column($input['evidence'],'ref'),true)
            &&!in_array($ref,$dispatchableRefs,true)&&count($researchEvidence)===1&&$researchEvidence[0]['parent_source']===$ref
            &&($researchEvidence[0]['artifact']['facts'][0]['quote']??null)===$quote
            &&!str_contains($prompt,'RAW PRIVATE RECORDING DETAILS'),
            'reassessment receives the exact public finding and parent provenance without dispatching its assigned transcript source or copying recording text');
        return '{"jobs":[],"opportunities":[]}';
    });
scope_check($reassessed['ok'],'reasoning over a saved specialist result can finish without requeueing it');

$calendar=$pdo->query('SELECT * FROM initiative_scope_calendar WHERE owner_id=1')->fetch();
$chatContexts=$pdo->query("SELECT id,context_json FROM chat_messages WHERE conversation_id=170 AND role='user'")->fetchAll();
$setContext=$pdo->prepare('UPDATE chat_messages SET context_json=? WHERE id=?');
foreach($chatContexts as $chatContext)$setContext->execute(['{"skip_brain_archive":true}',$chatContext['id']]);
$pdo->exec('DELETE FROM initiative_scope_calendar WHERE owner_id=1');
$pdo->exec('UPDATE agent_memory_items SET is_active=0 WHERE id=1002');
// An old pending result can still be a current report after its reusable
// artifact ages out of discovery. Isolate that fallback using real saved rules
// and timestamps, without removing any source reader or receipt verification.
$priorRules=vp3_coordination_rules_v1($pdo,$user,17);
vp3_coordination_rule_settings_v1($pdo,$user,17,170,$priorRules['revision'],
    ['transcripts'=>['open_question'=>'watch','open_followup'=>'watch']]);
$researchTiming=$pdo->prepare('SELECT completed_at,updated_at FROM agent_workflow_actions WHERE run_id=?');
$researchTiming->execute([$runId]);$currentResearchTiming=$researchTiming->fetch();
$pdo->prepare('UPDATE agent_workflow_actions SET completed_at=?,updated_at=NOW() WHERE run_id=?')
    ->execute([gmdate('Y-m-d H:i:s',time()-1860),$runId]);
$due=static function()use($pdo,$user):void{
    $pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE user_id=1 AND memory_type='initiative_sweep'");
    vp3_initiative_schedule_v1($pdo,$user,17);
};
$planCount=count(scope_runs(1,17,'initiative.plan'));
$due();
$plans=scope_runs(1,17,'initiative.plan');
$reportPlan=end($plans);
$reportGoal=json_decode((string)$reportPlan['goal'],true,32,JSON_THROW_ON_ERROR);
scope_check(count($plans)===$planCount+1&&$reportGoal['sources']===[]&&is_string($reportGoal['report_only']??null),
    'a due sweep queues the verified specialist result even when all ordinary sources are exhausted');
$reportRunId=(int)$reportPlan['id'];
$failedAssessment=vp3_initiative_execute_v1($pdo,$user,scope_claim($user,$reportRunId),
    static fn(): never=>throw vp3_initiative_provider_failure_v1(['ok'=>false,'status'=>401]));
$planState=$pdo->prepare('SELECT status FROM agent_workflow_runs WHERE id=?');
$planStatus=static function()use($planState,$reportRunId): string{
    $planState->execute([$reportRunId]);
    return (string)$planState->fetchColumn();
};
scope_check(!$failedAssessment['ok']&&$failedAssessment['reason']==='model_unavailable'&&$planStatus()==='failed',
    'the report-only assessment records the terminal provider rejection');
$pdo->exec("CREATE TABLE ai_execution_ledger(id INTEGER PRIMARY KEY,user_id INT,agent_id BIGINT,conversation_id BIGINT,
    run_id BIGINT,cloud_ledger_id BIGINT,total_tokens INT,cloud_tokens_charged INT,cost_rate_source TEXT,created_at TEXT)");
require_once dirname(__DIR__).'/includes/ai-service-usage-v1.php';
vp3_ai_service_usage_ensure_schema_v1($pdo);
$meter=$pdo->prepare("INSERT INTO ai_execution_ledger(user_id,agent_id,conversation_id,run_id,service_key,service_stage,
    service_attempt_key,service_job_key,service_status,is_background,total_tokens,cloud_tokens_charged,cost_rate_source)
    VALUES(1,17,170,?,'initiative','vp3.initiative-plan.v1',?,?,?,1,0,0,?)");
$jobKey=hash('sha256','run:'.$reportRunId);
$rejectedKey=hash('sha256','report-only-rejection');
$pendingKey=hash('sha256','report-only-unconfirmed');
$meter->execute([$reportRunId,$rejectedKey,$jobKey,'rejected','provider_rejected']);
$due();
scope_check($planStatus()==='failed','a recently rejected report-only assessment waits for the recovery delay');
$pdo->prepare('UPDATE agent_workflow_actions SET completed_at=? WHERE run_id=?')
    ->execute([gmdate('Y-m-d H:i:s',time()-600),$reportRunId]);
$meter->execute([$reportRunId,$pendingKey,$jobKey,'unknown','unknown_usage']);
$due();
scope_check($planStatus()==='failed','a metered attempt with unknown usage prevents report-only recovery');
$pdo->prepare('DELETE FROM ai_execution_ledger WHERE service_attempt_key=?')->execute([$pendingKey]);
$setResearchReceipt=$pdo->prepare('UPDATE agent_workflow_receipts SET result_json=? WHERE run_id=?');
$setResearchReceipt->execute(['{}',$runId]);
$due();
scope_check($planStatus()==='failed','a saved report with a mismatched receipt cannot authorize recovery');
$setResearchReceipt->execute([$json,$runId]);
$due();
scope_check($planStatus()==='approved'&&count(scope_runs(1,17,'initiative.plan'))===count($plans),
    'the current authorized report and a no-charge rejection requeue the same assessment run');
$finding='The public ventilation guidance was found. Confirm the specific appliance before using it.';
$reported=vp3_initiative_execute_v1($pdo,$user,scope_claim($user,$reportRunId),
    static function(string $prompt,string $protocol)use($finding,$quote):string{
        $input=json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
        $reports=$input['specialist_reports']??[];
        scope_check($protocol==='vp3.initiative-plan.v1'&&$input['evidence']===[]&&$input['tools']===[]
            &&$input['budget']['max_jobs']===0&&count($reports)===1&&str_contains($reports[0]['summary']??'',$quote),
            'the report-only assessment receives one verified finding and no tool or write eligibility');
        return json_encode(['jobs'=>[],'opportunities'=>[],'finding'=>$finding],JSON_THROW_ON_ERROR);
    });
$coordination=vp3_top_of_mind_v1($pdo,$user,17,170)['coordination']??[];
$assessmentAttempts=$pdo->prepare('SELECT status,attempt_no FROM agent_workflow_receipts WHERE run_id=? ORDER BY id');
$assessmentAttempts->execute([$reportRunId]);
scope_check($reported['ok']&&($coordination['finding']??null)===$finding
    &&$assessmentAttempts->fetchAll()===[['status'=>'failed','attempt_no'=>1],['status'=>'completed','attempt_no'=>2]],
    'Top of Mind presents a grounded coordination finding from the exact saved specialist result');
$due();
scope_check(count(scope_runs(1,17,'initiative.plan'))===count($plans),
    'a later sweep cannot queue the same report-only assessment again');
$setResearchReceipt->execute(['{}',$runId]);
scope_check(!isset(vp3_top_of_mind_v1($pdo,$user,17,170)['coordination']['finding']),
    'a mismatched specialist receipt withdraws the coordination finding');
$setResearchReceipt->execute([$json,$runId]);
$pdo->prepare('UPDATE agent_workflow_actions SET completed_at=?,updated_at=? WHERE run_id=?')
    ->execute([$currentResearchTiming['completed_at'],$currentResearchTiming['updated_at'],$runId]);
$currentRules=vp3_coordination_rules_v1($pdo,$user,17);
vp3_coordination_rule_settings_v1($pdo,$user,17,170,$currentRules['revision'],$priorRules['rules']);
$pdo->prepare('INSERT INTO initiative_scope_calendar VALUES(?,?,?)')->execute([$calendar['owner_id'],$calendar['title'],$calendar['starts_at']]);
$pdo->exec('UPDATE agent_memory_items SET is_active=1 WHERE id=1002');
foreach($chatContexts as $chatContext)$setContext->execute([$chatContext['context_json'],$chatContext['id']]);

$attention = array_column(vp3_top_of_mind_v1($pdo,$user,17,170)['items'],'state','run_id');
$analysts=array_values(array_filter(scope_runs(1,17,'initiative.prepare'),static fn(array $row):bool=>
    (json_decode($row['goal'],true)['tool']??null)==='curiosity.analyze'));
scope_check(count($analysts)===1&&$analysts[0]['status']==='approved'&&!isset($attention[$analysts[0]['id']]),
    'The independent private analyst remains queued without consuming an actionable owner agenda slot');
scope_check(($attention[$runId]??null)==='ready_to_report',
    'Top of Mind shows the verified transcript research result while private delivery is pending');

$ageReports->execute([$oldReportAt]);
$activity->execute(['{"visible":true,"conversation_id":170}']);
scope_check(vp3_initiative_deliver_pending_v1($pdo, $user, 17),
    'the saved research result reaches the linked private Chat when the owner returns');
$action->execute([$runId]);
$deliveredJson = (string)$action->fetchColumn();
$delivered = json_decode($deliveredJson, true, 24, JSON_THROW_ON_ERROR);
$message = $pdo->prepare('SELECT id,role,message,context_json FROM chat_messages WHERE id=? AND conversation_id=170');
$message->execute([$delivered['initiative_message_id']]);
$history = vp3_initiative_research_canvas_history_v1($pdo, 1, 17, 170, [$message->fetch()]);
$context = json_decode($history[0]['context_json'], true, 24, JSON_THROW_ON_ERROR);
$sourceLinks = array_column($context['sources'], 'url', 'source');
$canvas = $context['research_canvas_v1'];
scope_check(($sourceLinks[$ref] ?? null)===$sources[$ref]['research']['source_url']
    && str_contains($sourceLinks[$ref], 'plugin=qa&section=unanswered&item=public-guidance&page=1') && $canvas['title']===$topic
    && ($context['initiative_followthrough_state']['state']??null)==='open'
    && array_column($canvas['sources'], 'url')===[$publicUrl] && !vp3_initiative_deliver_pending_v1($pdo, $user, 17),
    'Chat history retains the exact private finding link and public citation once');

$expectedFollowups = [[
    'plugin_id'=>'qa',
    'section_key'=>'unanswered',
    'item_id'=>'public-guidance',
    'summary'=>$delivered['initiative_draft'],
    'chat_url'=>vp3_top_of_mind_media_href_v1(17,170,$delivered['initiative_message_id']),
    'outcome'=>'read_complete',
    'sources'=>array_map(static fn(array $source):array=>['title'=>$source['title'],'url'=>$source['url']],$canvas['sources']),
]];
scope_check($transcriptFollowups()===$expectedFollowups,
    'the saved report projects the exact original plugin question, verified public findings, and linked Chat turn');
$statusMaster=artist_listening_v237_analysis_status($pdo,940201,$map)['master'];
$statusMaster=transcription_output_normalize_master_v306($pdo,940201,$statusMaster,[],false);
$statusModules=transcription_app_modules_v306($statusMaster['analysis'],$statusMaster);
$statusModules=transcription_deeper_normalize_modules_v307($statusModules,transcription_deeper_review_index_v307($statusMaster));
transcription_deeper_persist_modules_v307($pdo,940201,$statusModules);
scope_check($transcriptFollowups()===$expectedFollowups&&($evidence()[$ref]['revision']??null)===$sources[$ref]['revision'],
    'loading the report retains the original evidence page and verified follow-up through saved normalization');
scope_check(vp3_initiative_transcript_report_followups_v1($pdo,$user,17,171,940201)===[]
    &&vp3_initiative_transcript_report_followups_v1($pdo,$user,18,180,940201)===[]
    &&vp3_initiative_transcript_report_followups_v1($pdo,['id'=>2,'is_active'=>1],19,190,940201)===[]
    &&vp3_initiative_transcript_report_followups_v1($pdo,$user,17,170,940202)===[],
    'another Chat, Agent, owner or recording does not receive the saved report follow-up');
$setResearchReceipt->execute(['{}',$runId]);
scope_check($transcriptFollowups()===[], 'a mismatched worker receipt hides the saved report follow-up');
$setResearchReceipt->execute([$deliveredJson,$runId]);
$ageAction=$pdo->prepare('UPDATE agent_workflow_actions SET completed_at=?,updated_at=? WHERE run_id=?');
$ageAction->execute([$oldReportAt,$oldReportAt,$runId]);
$ageReports->execute([$oldReportAt]);
scope_check($transcriptFollowups()===$expectedFollowups, 'the saved report keeps its verified result after the Research rail window');
$paused=scope_command($user,17,170,'off');
$whilePaused=$transcriptFollowups();
$resumed=scope_command($user,17,170,'on');
scope_check(($paused['initiative_policy']['enabled']??null)===false&&$whilePaused===$expectedFollowups
    &&($resumed['initiative_policy']['revision']??null)!==$binding['revision']&&$transcriptFollowups()===$expectedFollowups,
    'pausing or resuming Initiative keeps the delivered result in the same private recording and Chat');
scope_command($user,17,171,'on');
scope_check($transcriptFollowups()===[]&&vp3_initiative_transcript_report_followups_v1($pdo,$user,17,171,940201)===[],
    'a new Initiative Chat binding does not inherit the old Chat result');
vp3_initiative_in_scope_v1($user,17,static fn()=>agent_brain_v122_upsert_system_memory($user,'initiative_policy','owner-opt-in','Owner initiative preference.',$binding));

$editedMaster=$master;
$editedMaster['modules']['qa']['result']['unanswered'][0]['question']='What is the manufacturer guidance for ventilation?';
$saveMaster=$pdo->prepare('UPDATE artist_transcript_master_analysis_v237 SET analysis_json=? WHERE session_id=940201');
$saveMaster->execute([json_encode($editedMaster,JSON_THROW_ON_ERROR)]);
scope_check($transcriptFollowups()===[], 'changing the source question invalidates the saved result even when the item ID is unchanged');
$saveMaster->execute([json_encode($master,JSON_THROW_ON_ERROR)]);

$outbound = 0;
$forbidOutbound = static function () use (&$outbound): array {
    ++$outbound;
    throw new LogicException('A skipped or revoked finding attempted public research.');
};
$transport = ['search'=>$forbidOutbound, 'request'=>$forbidOutbound];
$skipId = $runBySource[$sourceByItem['private-choice']];
$messageCount = count(scope_messages());
$skip = vp3_initiative_execute_v1($pdo, $user, scope_claim($user, $skipId),
    static function (string $prompt, string $protocol): string {
        scope_check($protocol==='vp3.transcript-research-plan.v1' && str_contains($prompt, 'confidential supplier quote'),
            'the worker classifies the exact private follow-up before any provider request');
        return '{"decision":"skip","topic":""}';
    }, $transport);
$action->execute([$skipId]);
$skipped = json_decode((string)$action->fetchColumn(), true, 24, JSON_THROW_ON_ERROR);
$skippedTool = $skipped['initiative_tool_result'];
scope_check($skip['ok'] && $skippedTool['output']['public_topic_unavailable']===true && $skippedTool['sources']===[]
    && $skippedTool['tool_receipt']['operation']==='prepare'
    && $skipped['initiative_delivery']==='private' && count(scope_messages())===$messageCount && $outbound===0,
    'a private-only finding skips public research and produces no Chat report');
$skippedRun=agent_workflow_row_v1400($pdo,(int)$user['id'],$skipId);
$skippedStatus=vp3_initiative_engagement_preparation_v1($pdo,(int)$user['id'],$skippedRun);
scope_check($skippedStatus['status']==='completed'&&$skippedStatus['delivery']==='private'
    &&$skippedStatus['delivery_reason']==='public_topic_unavailable'&&$skippedStatus['message_id']===null
    &&!str_contains(json_encode($skippedStatus,JSON_THROW_ON_ERROR),'supplier'),
    'the verified private-only decision explains that no public topic was approved without publishing the transcript question');

$revokedId = $runBySource[$sourceByItem['revoked-research']];
$revoked = vp3_initiative_execute_v1($pdo, $user, scope_claim($user, $revokedId),
    static function (string $prompt, string $protocol) use ($pdo): string {
        scope_check($protocol==='vp3.transcript-research-plan.v1' && str_contains($prompt, 'oven maintenance'),
            'the queued worker can lose its grant after classification begins');
        vp3_transcript_research_save_v1($pdo, 1, false);
        return '{"decision":"research","topic":"commercial oven maintenance"}';
    }, $transport);
scope_check(!$revoked['ok'] && $evidence()===[] && $outbound===0,
    'revocation during classification prevents any outbound request and removes the source from discovery');
scope_check($transcriptFollowups()===[], 'revoking transcript research also removes the completed result from the saved report');

require __DIR__.'/fixtures/meeting-prep-calendar-fixture.php';
require_once dirname(__DIR__).'/includes/agent-meeting-research-v1.php';
require_once dirname(__DIR__).'/includes/agent-meeting-research-agenda-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-research-status-v1.php';
if (!function_exists('vp3_agent_runtime_preference_readonly_v420')) {
    function vp3_agent_runtime_preference_readonly_v420(PDO $pdo,int $owner,int $agent): array
    {
        return ['effective_preference'=>'vp3_cloud'];
    }
}
function user_has_role(string $role,?array $user=null): bool
{
    return $role==='member';
}
function valid_role(string $role): bool { return $role==='member'; }
function personal_capability_catalog_v242(): array { return ['agent_brain.access'=>[]]; }
function personal_capability_default_roles_v242(): array { return []; }

$meetingNow = new DateTimeImmutable('now',new DateTimeZone('UTC'));
$pdo->exec('CREATE TABLE IF NOT EXISTS homeserver_connections(user_id INTEGER PRIMARY KEY,status TEXT,capabilities_json TEXT,
    relay_token_enc TEXT,homeserver_token_enc TEXT,device_id TEXT)');
$meeting = vp3_test_meeting_prep_seed_v1($pdo,$meetingNow,17);
$pdo->prepare('UPDATE video_meetings SET end_at_utc=? WHERE id=7913')
    ->execute([$meetingNow->modify('-2 days')->format('Y-m-d H:i:s')]);
$meetingQuestion = 'What published guidance covers freight container loading?';
$meetingMap = artist_listening_transcript_page_map(artist_listening_v172_segments($pdo,$meeting['transcript_id']));
$meetingMaster = ['modules'=>['qa'=>[
    'source_hash'=>$meetingMap['source_hash'],
    'generated_at'=>gmdate('c'),
    'result'=>['unanswered'=>[[
        'item_id'=>'freight-guidance','question'=>$meetingQuestion,'follow_up'=>'Research public freight container loading guidance.',
        'review_state'=>'unreviewed','evidence_refs'=>[['page'=>1,'label'=>'Page 1']],
    ],[
        'item_id'=>'freight-storage','question'=>'What published guidance covers freight storage inspections?',
        'follow_up'=>'Find published freight storage guidance.','review_state'=>'unreviewed',
    ]]],
]]];
$pdo->prepare("INSERT INTO artist_transcript_master_analysis_v237 VALUES(?,?,?,?,?,?,'{}','fixture','fixture',?)")
    ->execute([$meeting['transcript_id'],$meetingMap['source_hash'],$meetingMap['total_words'],$meetingMap['page_count'],0,
        json_encode($meetingMaster,JSON_THROW_ON_ERROR),gmdate('c')]);
vp3_transcript_research_save_v1($pdo,1,true);
$meetingCoverage = [];
$meetingSources = vp3_initiative_transcript_research_evidence_v1(
    $pdo,$scope,$binding,$meetingNow,null,$meetingCoverage,$meeting['transcript_id']);
$meetingRef = array_key_first($meetingSources);
$meetingTarget = vp3_meeting_research_target_v1($pdo,$scope,$meeting['transcript_id'],$meeting['target_agenda_id']);
scope_check(is_string($meetingRef) && $meetingSources[$meetingRef]['research']['question']===$meetingQuestion
    && ($meetingTarget['source_meeting_id']??null)===$meeting['prior_meeting_id']
    && ($meetingTarget['meeting_id']??null)===$meeting['meeting_id']
    && ($meetingTarget['agenda_item_id']??null)===$meeting['target_agenda_id'],
    'a saved native meeting question and its carried-forward open commitment resolve from existing records');

$activity->execute(['{"visible":false,"conversation_id":170}']);
$meetingRunCount = count($researchRuns());
$ambiguousPlan=vp3_meeting_research_plan_v1('Research freight from our last meeting');
$ambiguous=vp3_meeting_research_chat_v1($pdo,$user,$scope['principal'],170,$ambiguousPlan);
scope_check(($ambiguous['tool_receipt']['status']??null)==='clarification_required'
    && count($researchRuns())===$meetingRunCount,
    'an ambiguous meeting question asks for its exact wording and starts no Research run');
vp3_initiative_in_scope_v1($user,17,static fn()=>vp3_initiative_schedule_scoped_v1($pdo,$user,[$meetingRef]));
$meetingPlan = (int)array_slice(scope_runs(1,17,'initiative.plan'),-1)[0]['id'];
$meetingPlanned = vp3_initiative_execute_v1($pdo,$user,scope_claim($user,$meetingPlan),
    static function (string $prompt,string $protocol) use ($meetingRef): string {
        $input = json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
        scope_check($protocol==='vp3.initiative-plan.v1' && ($input['tools']['topic.research']['source_refs']??null)===[$meetingRef],
            'ordinary assessment can select the exact saved meeting transcript question');
        return '{"jobs":[],"opportunities":[]}';
    });
$meetingRuns = array_slice($researchRuns(),$meetingRunCount);
$meetingRun = (int)($meetingRuns[0]['id']??0);
scope_check(!empty($meetingPlanned['ok']) && count($meetingRuns)===1 && $meetingRun>0,
    'the existing Research queue owns a single run for the meeting transcript question');

$meetingPlan = vp3_meeting_research_plan_v1('Research freight container loading from our last meeting for agenda #'
    .$meeting['target_agenda_id'].' and bring it back to that follow-up');
scope_check(is_array($meetingPlan), 'the natural meeting request selects a saved question and exact agenda target');
$meetingGoal = $meetingRuns[0]['goal'];
foreach (range(1,2) as $attempt) {
    $linked = vp3_meeting_research_chat_v1($pdo,$user,$scope['principal'],170,$meetingPlan);
    scope_check(($linked['tool_receipt']['status']??null)==='read_complete'
        && ($linked['tool_receipt']['record_id']??null)===$meetingRun
        && count($researchRuns())===$meetingRunCount+1,
        'request '.$attempt.' returns the existing exact Research run without another queue entry');
}
$link = vp3_meeting_research_saved_link_v1($pdo,1,$meetingRun);
$events = agent_workflow_events_v1400($pdo,1,$meetingRun);
$userLinks = array_filter($events,static fn(array $event): bool=>
    $event['event_type']==='meeting_research_linked' && $event['actor_kind']==='user');
$meetingRuns = array_slice($researchRuns(),$meetingRunCount);
scope_check(count($userLinks)===1 && ($link['source']['source_ref']??null)===$meetingRef
    && ($link['target']??null)===$meetingTarget && $meetingRuns[0]['goal']===$meetingGoal,
    'the existing workflow records one exact meeting link without rewriting the Research goal');
$meetingView = vp3_meeting_research_target_current_v1($pdo,$scope,$meetingTarget);
$queuedMeeting = array_column(vp3_top_of_mind_v1($pdo,$user,17,170)['items'],null,'run_id')[$meetingRun]??[];
scope_check(is_array($meetingView) && ($queuedMeeting['state']??null)==='queued'
    && ($queuedMeeting['meeting_followup']??null)===$meetingView,
    'Top of Mind follows the same queued run and existing meeting commitment');
$nativeAgendaView = static function () use ($pdo,$user,$meeting): array {
    $rows = $pdo->prepare('SELECT * FROM video_meeting_agenda_items WHERE meeting_id=? AND owner_user_id=1');
    $rows->execute([$meeting['meeting_id']]);
    return vp3_meeting_research_agenda_view_v1($pdo,$user,video_meeting_row_v1800($pdo,$meeting['meeting_id']),$rows->fetchAll());
};
scope_check($nativeAgendaView()===[$meeting['target_agenda_id']=>['state'=>'queued']],
    'the native Agenda shows the same queued Research run only on its exact commitment');
$planningQueued=$planningStatus();
scope_check($planningQueued['state']==='waiting_specialist'&&$planningQueued['dependency_run_id']===$meetingRun&&$planningQueued['run_id']===null
    &&$planningQueued['lease_expires_at']===''&&$planningQueued['progress']===null,
    'Planning identifies its verified queued Research dependency without claiming its lease');

$meetingTopic = 'freight container loading safety';
$meetingUrl = 'https://example.org/freight/container-loading';
$meetingQuote = 'Distribute weight evenly and secure the cargo to prevent shifting.';
$meetingSearches = [];
$meetingClaim = scope_claim($user,$meetingRun);
scope_check($nativeAgendaView()===[$meeting['target_agenda_id']=>['state'=>'running']],
    'the native Agenda observes the same active worker lease');
scope_check($planningStatus()===$planningQueued,'a live Research lease remains a dependency wait for Planning');
$meetingComplete = vp3_initiative_execute_v1($pdo,$user,$meetingClaim,
    static function (string $prompt,string $protocol) use ($meetingTopic,$meetingUrl,$meetingQuote,$meetingQuestion): string {
        $input = json_decode($prompt,true,24,JSON_THROW_ON_ERROR);
        if ($protocol==='vp3.transcript-research-plan.v1') {
            scope_check(($input['question']??null)===$meetingQuestion && !str_contains($prompt,'Assign an owner for the revised delivery plan'),
                'only the existing exact transcript question enters the private Research classifier');
            return json_encode(['decision'=>'research','topic'=>$meetingTopic],JSON_THROW_ON_ERROR);
        }
        $citation = 'topic-web:'.hash('sha256',$meetingUrl);
        scope_check($protocol==='vp3.topic-research.v1' && ($input['topic']??null)===$meetingTopic
            && array_column($input['sources'],'source')===[$citation],
            'the same Research worker verifies the public source and finding');
        return json_encode(['findings'=>[['source'=>$citation,'quote'=>$meetingQuote]]],JSON_THROW_ON_ERROR);
    }, [
        'search'=>static function (string $query) use (&$meetingSearches,$meetingUrl): array {
            $meetingSearches[] = $query;
            return ['ok'=>true,'results'=>[['url'=>$meetingUrl,'title'=>'Freight container loading']]];
        },
        'request'=>static fn(string $url): array=>[
            'status'=>200,'content_type'=>'text/plain','body'=>$meetingQuote.' Follow the carrier guidance and verify local requirements.',
        ],
    ], allowDelivery:false);
$action->execute([$meetingRun]);
$receipt->execute([$meetingRun]);
$meetingResultJson = $action->fetchColumn();
$meetingResult = json_decode((string)$meetingResultJson,true,24,JSON_THROW_ON_ERROR);
$pendingMeeting = array_column(vp3_top_of_mind_v1($pdo,$user,17,170)['items'],null,'run_id')[$meetingRun]??[];
scope_check(!empty($meetingComplete['ok']) && $meetingResultJson===$receipt->fetchColumn()
    && ($meetingResult['initiative_delivery']??null)==='pending' && $meetingSearches===[$meetingTopic]
    && ($pendingMeeting['state']??null)==='ready_to_report' && ($pendingMeeting['meeting_followup']??null)===$meetingView,
    'one verified result waits for private delivery on the same meeting-linked Research run');
scope_check($nativeAgendaView()===[$meeting['target_agenda_id']=>['state'=>'ready']],
    'the native Agenda reports readiness before any Chat message exists');

$ageReports->execute([$oldReportAt]);
$activity->execute(['{"visible":true,"conversation_id":170}']);
scope_check(vp3_initiative_deliver_pending_v1($pdo,$user,17) && !vp3_initiative_deliver_pending_v1($pdo,$user,17),
    'the existing delivery path posts one meeting-linked result to the originating Chat');
$action->execute([$meetingRun]);
$meetingResult = json_decode((string)$action->fetchColumn(),true,24,JSON_THROW_ON_ERROR);
$meetingMessageId = (int)$meetingResult['initiative_message_id'];
$message->execute([$meetingMessageId]);
$meetingMessage = $message->fetch();
$meetingHistory = static function (int $owner,int $agent,int $conversation) use ($pdo,$meetingMessage): array {
    $rows = vp3_initiative_research_canvas_history_v1($pdo,$owner,$agent,$conversation,[$meetingMessage]);
    return json_decode($rows[0]['context_json'],true,24,JSON_THROW_ON_ERROR)['research_canvas_v1']??[];
};
$meetingCanvas = $meetingHistory(1,17,170);
$completedMeeting = array_column(vp3_top_of_mind_v1($pdo,$user,17,170)['items'],null,'run_id')[$meetingRun]??[];
$railMeeting = array_column(vp3_initiative_research_status_v1($pdo,$user,17,170)['specialists'],null,'run_id')[$meetingRun]??[];
$agenda = $pdo->prepare('SELECT status FROM video_meeting_agenda_items WHERE id=? AND owner_user_id=1');
$agenda->execute([$meeting['target_agenda_id']]);
scope_check(($meetingCanvas['meeting_followup']??null)===$meetingView && array_column($meetingCanvas['sources'],'url')===[$meetingUrl]
    && ($completedMeeting['state']??null)==='completed' && ($completedMeeting['meeting_followup']??null)===$meetingView
    && str_contains($completedMeeting['result_url']??'','&message_id='.$meetingMessageId)
    && ($railMeeting['meeting_followup']??null)===$meetingView && $agenda->fetchColumn()==='open',
    'History, Top of Mind and the Research rail agree on the exact verified result while the native commitment remains open');
scope_check($meetingHistory(1,17,171)===[] && $meetingHistory(1,18,180)===[] && $meetingHistory(2,19,190)===[],
    'copied Chat markers cannot expose meeting links or findings in a different Chat, Agent or account');

$linkedProposal = vp3_initiative_followthrough_load_v1($pdo,$user,$scope['principal'],170,$meetingMessageId);
scope_check(($linkedProposal['meeting_research']['target']??null)===$meetingTarget
    && ($linkedProposal['meeting_followup']??null)===$meetingView && $linkedProposal['state']['state']==='open',
    'the verified Research result opens the existing Planner follow-through with its exact meeting link');
// The scope fixture supplies host permissions; the reviewed action still uses
// the actual strict permission reader and canonical task writer.
define('STONEFELLOW_PERSONAL_CAPABILITIES_SEED','personal_capabilities_seed_v242');
$pdo->exec("ALTER TABLE users ADD COLUMN role TEXT DEFAULT 'member';
    CREATE TABLE permissions(permission_key TEXT);
    CREATE TABLE role_permissions(role TEXT,permission_key TEXT);
    CREATE TABLE user_account_types(user_id INTEGER,role TEXT,assigned_explicitly_at TEXT);
    INSERT INTO permissions VALUES('agent_brain.access');
    INSERT INTO role_permissions VALUES('member','agent_brain.access');
    INSERT INTO settings VALUES('personal_capabilities_seed_v242','1')");
vp3_task_lists_schema_v1($pdo);
$replyToMeeting = static function (string $query,array $proposal,callable $model) use ($pdo,$user,$scope,$meetingMessageId): array {
    $pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES(170,1,'user',?)")->execute([$query]);
    $replyId = (int)$pdo->lastInsertId();
    $ref = ['message_id'=>$meetingMessageId,'reply_ref'=>$proposal['reply_ref'],'revision'=>$proposal['revision']];
    return vp3_initiative_followthrough_reply_v1($pdo,$user,$scope['principal'],170,$replyId,$ref,$query,null,$model);
};
$taskTitle = 'Check freight loading guidance with the carrier';
$taskCount = $pdo->prepare("SELECT COUNT(*) FROM agent_memory_items WHERE user_id=1 AND user_agent_id=17
    AND memory_type='task' AND memory_text=?");
$taskCount->execute([$taskTitle]);
scope_check((int)$taskCount->fetchColumn()===0,'the native meeting research did not create a task by itself');
$review = $replyToMeeting('Create a task to '.$taskTitle,$linkedProposal['state'],
    static function (string $prompt,string $protocol) use ($taskTitle): string {
        $input = json_decode($prompt,true,24,JSON_THROW_ON_ERROR);
        scope_check($protocol==='vp3.initiative-reply.v1' && ($input['meeting_followup']['state']??null)==='open'
            && in_array('task.create',array_column($input['action_catalog'],'adapter'),true),
            'the Planner uses the existing reviewed action catalog for the open meeting follow-up');
        return json_encode(['intent'=>'advance','focus'=>$taskTitle,'action'=>['adapter'=>'task.create','arguments'=>['title'=>$taskTitle]]],JSON_THROW_ON_ERROR);
    });
$taskCount->execute([$taskTitle]);
$reviewedMeeting = array_column(vp3_top_of_mind_v1($pdo,$user,17,170)['items'],null,'run_id')[$meetingRun]??[];
scope_check(($review['advisory_context']['verified_action']['status']??null)==='approval_pending'
    && ($review['advisory_context']['meeting_followup']??null)===$meetingView && (int)$taskCount->fetchColumn()===0
    && ($reviewedMeeting['meeting_followup_action']??null)===['skill'=>'task','status'=>'approval_pending'],
    'the linked follow-up prepares the exact task review without creating the task');
scope_check($planningStatus()['state']==='waiting_owner'&&$planningStatus()['message_id']===$meetingMessageId,
    'Planning follows the existing owner review and exact Chat result');
$pdo->prepare("INSERT INTO chat_messages(conversation_id,user_id,role,message,context_json) VALUES(170,NULL,'assistant',?,?)")
    ->execute(['Review the task: '.$taskTitle,json_encode([
        'initiative_action'=>$review['advisory_context']['verified_action'],
        'initiative_followthrough'=>$review['initiative_followthrough'],
    ],JSON_THROW_ON_ERROR)]);
$approval = $replyToMeeting('Approve this task.',$review['initiative_followthrough'],
    static fn(): string=>'{"intent":"advance","focus":"Save the reviewed follow-up task.","action_decision":"approve"}');
$taskCount->execute([$taskTitle]);
$agenda->execute([$meeting['target_agenda_id']]);
$approvedMeeting = array_column(vp3_top_of_mind_v1($pdo,$user,17,170)['items'],null,'run_id')[$meetingRun]??[];
scope_check(($approval['advisory_context']['verified_action']['status']??null)==='completed'
    && ($approval['advisory_context']['domain_result']['verified']??null)===true && (int)$taskCount->fetchColumn()===1
    && ($approvedMeeting['meeting_followup_action']??null)===['skill'=>'task','status'=>'completed']
    && $agenda->fetchColumn()==='open' && count($researchRuns())===$meetingRunCount+1,
    'owner approval creates one verified task while the native commitment remains open and Research is not rerun');
scope_check($planningStatus()['state']==='ready'&&str_contains($planningStatus()['detail'],'saved and verified'),
    'Planning reports the verified task save without closing the native meeting commitment');

$oldMeetingAt = gmdate('Y-m-d H:i:s',time()-600);
$pdo->prepare('UPDATE agent_workflow_actions SET completed_at=?,updated_at=? WHERE run_id=?')
    ->execute([$oldMeetingAt,$oldMeetingAt,$meetingRun]);
scope_check($nativeAgendaView()===[$meeting['target_agenda_id']=>[
    'state'=>'ready','chat_url'=>vp3_top_of_mind_media_href_v1(17,170,$meetingMessageId),
]],'the same native commitment keeps the exact verified Chat report after the Research rail window');

$pdo->prepare("UPDATE video_meeting_agenda_items SET item_text='Confirm the revised freight guidance' WHERE id=?")
    ->execute([$meeting['target_agenda_id']]);
$staleMeeting = array_column(vp3_top_of_mind_v1($pdo,$user,17,170)['items'],null,'run_id')[$meetingRun]??[];
scope_check(!isset($meetingHistory(1,17,170)['meeting_followup']) && !isset($staleMeeting['meeting_followup']) && $nativeAgendaView()===[],
    'an edited commitment no longer presents the earlier research association as current');
scope_check(($planningStatus()['dependency_run_id']??null)!==$meetingRun,'Planning drops the dependency when its exact meeting source changes');
$newPlan=vp3_meeting_research_plan_v1('Could you research freight storage inspections from our last meeting?');
$newRun=vp3_meeting_research_chat_v1($pdo,$user,$scope['principal'],170,$newPlan);
$newRunId=(int)($newRun['tool_receipt']['record_id']??0);
$newLink=vp3_meeting_research_saved_link_v1($pdo,1,$newRunId);
scope_check($newRunId>$meetingRun && ($newRun['tool_receipt']['status']??null)==='read_complete'
    && count($researchRuns())===$meetingRunCount+2
    && ($newLink['source']['source_ref']??null)!==$meetingRef
    && ($newLink['target']['source_meeting_id']??null)===$meeting['prior_meeting_id'],
    'an exact new request queues one ordinary Research run when no existing assignment matches');
echo "AGENT_INITIATIVE_TRANSCRIPT_RESEARCH_PIPELINE_V1=PASS\n";
