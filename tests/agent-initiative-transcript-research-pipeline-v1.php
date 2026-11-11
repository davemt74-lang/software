<?php
declare(strict_types=1);

require __DIR__.'/agent-initiative-scope-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-transcripts-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-transcript-research-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-research-canvas-v1.php';
require_once dirname(__DIR__).'/includes/agent-top-of-mind-v1.php';

$user = ['id'=>1, 'is_active'=>1];
scope_command($user, 17, 170, 'off');
scope_command($user, 17, 170, 'on');
$binding = vp3_initiative_policy_v1($user, 17);
$scope = vp3_initiative_tools_scope_v1($pdo, $user, $binding);
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

$before = count(scope_runs(1, 17, 'initiative.prepare'));
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
$fallbackRuns = array_slice(scope_runs(1,17,'initiative.prepare'),$before);
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
        $jobs = [];
        foreach ($remaining as $primary) {
            $jobs[] = ['tool'=>'topic.research', 'primary'=>$primary, 'refs'=>[$primary],
                'focus'=>'Use the private Supplier Delta quote', 'report'=>true];
        }
        return json_encode(['jobs'=>$jobs], JSON_THROW_ON_ERROR);
    });
$runs = array_slice(scope_runs(1, 17, 'initiative.prepare'), $before);
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
    ]);
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
    'the completed worker receipt saves exact finding provenance and verified public citations while Chat is away');
$attention = array_column(vp3_top_of_mind_v1($pdo,$user,17,170)['items'],'state','run_id');
scope_check(($attention[$runId]??null)==='ready_to_report',
    'Top of Mind shows the verified transcript research result while private delivery is pending');

$ageReports->execute([$oldReportAt]);
$activity->execute(['{"visible":true,"conversation_id":170}']);
scope_check(vp3_initiative_deliver_pending_v1($pdo, $user, 17),
    'the saved research result reaches the linked private Chat when the owner returns');
$action->execute([$runId]);
$delivered = json_decode((string)$action->fetchColumn(), true, 24, JSON_THROW_ON_ERROR);
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
echo "AGENT_INITIATIVE_TRANSCRIPT_RESEARCH_PIPELINE_V1=PASS\n";
