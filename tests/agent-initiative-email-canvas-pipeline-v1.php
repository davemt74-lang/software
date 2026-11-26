<?php
declare(strict_types=1);

// Use the real settings/crypto and inject the model and generation responses at the worker boundaries.
define('VP3_EMAIL_CANVAS_PIPELINE_FIXTURE',true);
$emailFixture = sys_get_temp_dir().'/vp3-canvas-email-'.bin2hex(random_bytes(8));
define('STONEFELLOW_ROOT',$emailFixture);
mkdir($emailFixture.'/private',0700,true);
$emailFixtureKey = random_bytes(32);
file_put_contents($emailFixture.'/private/ai-key.php','<?php return '.var_export(base64_encode($emailFixtureKey),true).';');
register_shutdown_function(static function () use ($emailFixture): void {
    unlink($emailFixture.'/private/ai-key.php');
    rmdir($emailFixture.'/private');
    rmdir($emailFixture);
});
require_once dirname(__DIR__).'/includes/ai-settings.php';
$GLOBALS['imageSettings']['ai_openai_enabled'] = '1';
$GLOBALS['imageSettings']['ai_openai_api_key'] = ai_encrypt_secret_with_key('fixture-coding-provider-key',$emailFixtureKey);
require __DIR__.'/agent-initiative-canvas-pipeline-v1.php';

require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-v1.php';
require_once dirname(__DIR__).'/includes/agent-connection-gmail-inbox-initial-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-email-canvas-v1.php';
require_once dirname(__DIR__).'/includes/agent-activity-hub-v1.php';
require_once dirname(__DIR__).'/includes/coding-canvas-email-v1.php';

function canvas_pipeline_email_seed(PDO $pdo, array $owner, bool $mysql): void
{
    putenv('VP3_GMAIL_CLIENT_ID=canvas-email-fixture');
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
    agent_connection_gmail_inbox_activity_install_v1($pdo);

    $grant = str_repeat('d',64);
    $pdo->prepare("INSERT INTO agent_connections_v1 VALUES (88,1,'gmail','connected',1,?,?)")
        ->execute([$grant,agent_connections_client_fingerprint_v1()]);
    $pdo->prepare("INSERT INTO agent_connection_gmail_inbox_v1
        (owner_user_id,connection_id,authorization_key,enabled,rules_json,revision,enabled_at_ms,history_id,last_status)
        VALUES (1,88,?,1,?, ?,?,'111','waiting')")
        ->execute([$grant,json_encode(['triage_enabled'=>true],JSON_THROW_ON_ERROR),str_repeat('e',32),(time()-60)*1000]);
    canvas_pipeline_email_observe($pdo,$owner,1);
}

function canvas_pipeline_email_observe(PDO $pdo, array $owner, int $generation, int $messageCount=3): void
{
    if ($generation>1) {
        $grant = hash('sha256','fixture-gmail-generation-'.$generation);
        $pdo->prepare('UPDATE agent_connections_v1 SET authorization_key=?,read_enabled=1 WHERE owner_user_id=1')->execute([$grant]);
        $pdo->prepare('UPDATE agent_connection_gmail_inbox_v1 SET authorization_key=?,enabled=1,enabled_at_ms=? WHERE owner_user_id=1')
            ->execute([$grant,(time()-60)*1000]);
    }
    $policy = agent_connection_gmail_inbox_policy_v1($pdo,1);
    $messages = [];
    for ($i=1;$i<=$messageCount;$i++) {
        $message = [
            'message_id'=>'email_canvas_fixture_message_'.$generation.'_'.$i,'thread_id'=>'email_canvas_fixture_thread_'.$generation.'_'.$i,
            'received_at_ms'=>(string)((time()-30+$i)*1000),'from'=>'canvas-fixture@example.test',
            'subject'=>'EMAIL_CANVAS_PRIVATE_SUBJECT '.$i,'body'=>'EMAIL_CANVAS_PRIVATE_BODY',
            'important'=>true,'reply'=>false,'labels'=>['INBOX'],
            'assessment'=>['status'=>'assessed','reason'=>'deadline','context'=>'business','sender'=>'organization',
                'deadline_date'=>gmdate('Y-m-d',time()+$i*86400)],
        ];
        $messages[agent_connection_gmail_inbox_key_v1($policy,'message:'.$message['message_id'])] = $message;
    }
    agent_commerce_atomic_pa2($pdo,static function () use ($pdo,$owner,$policy,$messages): void {
        $review = agent_connection_gmail_inbox_review_v1($pdo,$policy,$messages,agent_commerce_time_pa2());
        agent_connection_gmail_inbox_batch_save_v1($pdo,$policy,json_encode($review,JSON_THROW_ON_ERROR));
        $scope = agent_connection_gmail_inbox_bound_scope_v1($pdo,$owner);
        canvas_pipeline_check(agent_connection_gmail_inbox_update_post_v1($pdo,$scope,7,11)===1,
            'the real Gmail review commits accepted deadlines and links the exact Agent Chat');
    });
}

function canvas_pipeline_email_model(string $ref): Closure
{
    return static function (string $prompt,string $protocol) use ($ref): string {
        $input = json_decode($prompt,true,32,JSON_THROW_ON_ERROR);
        canvas_pipeline_check(!str_contains($prompt,'EMAIL_CANVAS_PRIVATE_') && !str_contains($prompt,'canvas-fixture@example.test')
            && !str_contains($prompt,'email_canvas_fixture_thread_'), 'email discovery and preparation use minimal facts instead of private email content');
        if ($protocol==='vp3.initiative-canvas.v1') {
            canvas_pipeline_check(($input['email_canvas']['workflow']??null)==='deadline_board'
                && array_column($input['evidence']??[],'ref')===[$ref]
                && ($input['mode']??null)==='propose_private_canvas' && !db()->inTransaction(),
                'the canonical Canvas preparation receives the verified email workflow outside receipt locks');
            return json_encode(['title'=>'Email deadline board','prompt'=>'Show the open email deadlines in date order.',
                'draft'=>'I found several open email requests with deadlines that could use a single view.'],JSON_THROW_ON_ERROR);
        }
        throw new RuntimeException('Unexpected email Canvas protocol '.$protocol);
    };
}

function canvas_pipeline_email_generate(PDO $pdo, array $owner, array $pin, string $jobId, string $requestId, string $prompt): array
{
    canvas_pipeline_check(str_contains($prompt,'CanvasSources.email.get()')
        && !str_contains($prompt,'EMAIL_CANVAS_PRIVATE_') && !str_contains($prompt,'canvas-fixture@example.test'),
        'the coding model receives the email interface and goal while live records stay out of its prompt');
    return ['title'=>'Email deadline board','artifact'=>['html'=>'<main><h1>Email deadlines</h1><p id="state"></p></main>',
        'css'=>'','js'=>'CanvasUI.text("#state",String(CanvasSources.email.get().rows.length));']];
}

function canvas_pipeline_email_prepare(array $owner, string $ref): int
{
    $pdo = db();
    $pdo->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep';
        DELETE FROM agent_memory_items WHERE user_id=1 AND user_agent_id=7 AND memory_type='initiative_sweep' AND subject='opportunity-scheduling';
        UPDATE agent_activity_state SET last_heartbeat_at=CURRENT_TIMESTAMP;
        UPDATE chat_messages SET created_at='2000-01-01 00:00:00' WHERE conversation_id=11");
    $model = canvas_pipeline_email_model($ref);
    // A general OB1 assessment can independently review a creative starter.
    // This workflow already knows its app; it needs no Canvas discovery planner.
    $discoveryPlans=static fn():array=>array_filter(canvas_pipeline_runs('initiative.plan'),
        static fn(array $run):bool=>(json_decode((string)$run['goal'],true)['opportunity_lane']??null)==='app_suggestions');
    $beforePlans = count($discoveryPlans());
    $beforePreparations = count(canvas_pipeline_runs('initiative.prepare'));
    vp3_initiative_schedule_v1($pdo,$owner,7);
    $preparations = canvas_pipeline_runs('initiative.prepare');
    $prepared = (int)end($preparations)['id'];
    $goal=json_decode((string)end($preparations)['goal'],true,32,JSON_THROW_ON_ERROR);
    $binding=vp3_initiative_policy_v1($owner,7);
    $evidence=vp3_initiative_email_canvas_evidence_v1($pdo,$owner,$binding,[$ref]);
    canvas_pipeline_check(count($preparations)===$beforePreparations+1
        && count($discoveryPlans())===$beforePlans
        && ($goal['tool']??null)==='canvas.prepare' && ($goal['primary']??null)===$ref
        && ($goal['binding']??null)===$binding && ($goal['sources']??null)===[$ref=>$evidence[$ref]['revision']]
        && ($goal['canonical_sources']??null)===$goal['sources'] && ($goal['opportunity_lane']??null)==='app_suggestions'
        && ($goal['email_canvas_work_key']??null)===vp3_initiative_email_canvas_work_key_v1($ref),
        'the actual scheduler directly admits one exact email workflow without an extra Canvas discovery assessment');
    canvas_pipeline_check(!array_filter(canvas_pipeline_runs('initiative.plan'),
        static fn(array $run):bool=>isset(json_decode((string)$run['goal'],true)['sources'][$ref])),
        'an independent OB1 assessment cannot duplicate the exact private email Canvas workflow');
    canvas_pipeline_check(vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($prepared),$model)['ok'],
        'the actual Canvas adapter prepares the email opportunity through its canonical receipt');
    return $prepared;
}

if(defined('VP3_CURIOSITY_EMAIL_PIPELINE_FIXTURE'))return;
$enabled = vp3_initiative_chat_v1($pdo,$owner,$principal,['conversation_id'=>11],'on',null);
// This fixture exercises the email app lane; unrelated Curiosity questions stay in its report.
$emailRules=vp3_coordination_rule_settings_v1($pdo,$owner,7,11);
vp3_coordination_rule_settings_v1($pdo,$owner,7,11,$emailRules['revision'],['curiosity'=>['open_question'=>'watch']]);
canvas_pipeline_check(!empty($enabled['ok']) && !vp3_initiative_canvas_setting_v1($pdo,$owner,7)['enabled'],
    'the actual Initiative policy is enabled and Auto Build drafts defaults to off');
canvas_pipeline_email_seed($pdo,$owner,$mysql);
$emailBinding = vp3_initiative_policy_v1($owner,7);
$emailEvidence = vp3_initiative_email_canvas_evidence_v1($pdo,$owner,$emailBinding);
$emailRef = array_key_first($emailEvidence);
canvas_pipeline_check(count($emailEvidence)===1 && is_string($emailRef) && str_starts_with($emailRef,'email-canvas:')
    && vp3_initiative_email_canvas_evidence_v1($pdo,$owner,array_replace($emailBinding,['conversation_id'=>12]))===[],
    'discovery produces one verified opportunity for the exact linked Agent and Chat');

$beforeEmailCoding = canvas_pipeline_count('agent_workflow_runs',"source_kind='coding_canvas'");
$emailProposal = canvas_pipeline_result(canvas_pipeline_email_prepare($owner,$emailRef));
canvas_pipeline_check(($emailProposal['initiative_tool_result']['output']['status'] ?? '')==='proposal_ready'
    && ($emailProposal['initiative_delivery'] ?? '')==='delivered'
    && canvas_pipeline_count('agent_workflow_runs',"source_kind='coding_canvas'")===$beforeEmailCoding,
    'the automatically detected need becomes one private Chat proposal while Auto Build is off');

$emailSetting = vp3_initiative_canvas_setting_v1($pdo,$owner,7);
vp3_initiative_canvas_setting_v1($pdo,$owner,7,true,$emailSetting['revision']);
$emailBinding = vp3_initiative_policy_v1($owner,7);
canvas_pipeline_check(array_key_first(vp3_initiative_email_canvas_evidence_v1($pdo,$owner,$emailBinding))===$emailRef,
    'the same still-current email opportunity becomes eligible when the owner enables Auto Build');
$emailAuto = canvas_pipeline_result(canvas_pipeline_email_prepare($owner,$emailRef));
$emailRun = (int)($emailAuto['initiative_tool_result']['output']['coding_run_id'] ?? 0);
canvas_pipeline_check($emailRun>0 && canvas_pipeline_count('agent_workflow_runs',"source_kind='coding_canvas'")===$beforeEmailCoding+1,
    'Auto Build admits one Coding job for the verified email workflow without a new Chat request');
$emailJob = vp3_coding_canvas_job_view_v1($pdo,vp3_coding_canvas_job_row_v1($pdo,$owner,$emailRun));
canvas_pipeline_check(vp3_coding_canvas_execute_v1($pdo,$owner,canvas_pipeline_claim($emailRun),'canvas_pipeline_email_generate')['ok'],
    'the canonical worker saves the automatically requested draft independently of the Chat view');
$emailDraft = vp3_coding_canvas_read_v1($pdo,$owner,7,$emailJob['canvas_id']);
$emailVersion = $emailDraft['revision']['id'];
$emailScope = vp3_agent_activity_scope_v1($pdo,$owner,7,11);
$outcomeRow = ['id'=>$emailJob['canvas_id'],'conversation_id'=>11,'draft_revision'=>$emailVersion,'run_id'=>$emailRun];
canvas_pipeline_check($emailDraft['canvas']['active_revision']===null && get_object_vars($emailDraft['state']['value'])===[]
    && vp3_agent_activity_canvas_outcome_v1($pdo,$emailScope,$outcomeRow)==='Draft version '.$emailVersion.' saved. Preview and workflow checks pending.',
    'Top of Mind verifies the exact saved receipt and distinguishes an untested draft from an active app');
vp3_coding_canvas_mark_tested_v1($pdo,$owner,7,$emailJob['canvas_id'],$emailVersion,$emailDraft['state']['revision']);
canvas_pipeline_check(vp3_agent_activity_canvas_outcome_v1($pdo,$emailScope,$outcomeRow)
    ==='Preview loaded for draft version '.$emailVersion.'. Workflow checks pending.',
    'the exact app-data revision can prove a preview without being treated as a passed workflow');
vp3_coding_canvas_email_test_v1($pdo,$owner,7,11,$emailJob['canvas_id'],$emailVersion,$emailDraft['state']['revision'],true);
canvas_pipeline_check(vp3_agent_activity_canvas_outcome_v1($pdo,$emailScope,$outcomeRow)
    ==='Preview loaded for draft version '.$emailVersion.'. Sample deadline check passed.',
    'Top of Mind presents the separate sample-check outcome against the same saved revision');
$activityCards = array_column(vp3_agent_activity_canvases_v1($pdo,$emailScope),null,'id');
$reviewAction = $activityCards['canvas:'.$emailJob['canvas_id']]['actions'][0] ?? [];
canvas_pipeline_check(($reviewAction['canvas_id'] ?? null)===$emailJob['canvas_id']
    && ($reviewAction['revision'] ?? null)===$emailVersion && ($reviewAction['conversation_id'] ?? null)===11
    && vp3_agent_activity_canvas_outcome_v1($pdo,$emailScope,array_replace($outcomeRow,['draft_revision'=>$emailVersion+1]))===null,
    'Top of Mind opens the exact saved revision and rejects an unverified version');
$emailRows = vp3_coding_canvas_email_data_v1($pdo,$owner,7,11,$emailJob['canvas_id'],$emailVersion);
$dueDates = array_column($emailRows['rows'] ?? [],'due_date');
$sortedDates = $dueDates;
sort($sortedDates);
canvas_pipeline_check(($emailRows['workflow'] ?? null)==='deadline_board' && count($dueDates)===3 && $dueDates===$sortedDates
    && count(array_unique(array_column($emailRows['rows'],'id')))===3
    && !str_contains(json_encode($emailRows,JSON_THROW_ON_ERROR),'EMAIL_CANVAS_PRIVATE_BODY'),
    'the exact private draft receives three distinct live rows ordered by deadline without mailbox bodies');
$settledCoverage = [];
$settledEvidence = vp3_initiative_evidence_v1($pdo,$owner,new DateTimeImmutable(),false,[],$settledCoverage,null,7);
canvas_pipeline_check(!isset($settledEvidence[$emailRef])
    && isset(vp3_initiative_email_canvas_evidence_v1($pdo,$owner,$emailBinding,[$emailRef])[$emailRef]),
    'later assessments skip the already built board while the exact evidence remains available for the saved version');
$pdo->exec("UPDATE agent_connections_v1 SET read_enabled=0 WHERE owner_user_id=1 AND provider='gmail'");
canvas_pipeline_check(vp3_coding_canvas_email_data_v1($pdo,$owner,7,11,$emailJob['canvas_id'],$emailVersion)===null
    && vp3_initiative_email_canvas_evidence_v1($pdo,$owner,$emailBinding)===[],
    'revoking the Gmail grant immediately clears discovery and the saved draft’s live data');

require_once dirname(__DIR__).'/includes/agent-initiative-email-canvas-start-v1.php';
$emailSetting=vp3_initiative_canvas_setting_v1($pdo,$owner,7);
vp3_initiative_canvas_setting_v1($pdo,$owner,7,false,$emailSetting['revision']);
canvas_pipeline_email_observe($pdo,$owner,3,1);
canvas_pipeline_check(vp3_initiative_email_canvas_evidence_v1($pdo,$owner,vp3_initiative_policy_v1($owner,7))===[]
    && vp3_initiative_email_canvas_start_available_v1($pdo,$owner,7,11)
    && !vp3_initiative_email_canvas_start_available_v1($pdo,$owner,7,12),
    'the manual email build appears only in the exact linked Chat and can start below the automatic threshold');
$manualRequest='014d38b4-eadc-4d59-b82a-5f4cb204f088';
$manual=vp3_initiative_email_canvas_start_v1($pdo,$owner,7,11,$manualRequest);
$manualPrepare=$manual['preparation_run_id'];
$manualRun=agent_workflow_row_v1400($pdo,1,$manualPrepare);
$manualGoal=json_decode((string)$manualRun['goal'],true,16,JSON_THROW_ON_ERROR);
canvas_pipeline_check(($manualGoal['binding']['allow_auto_drafts']??false)===false
    && vp3_initiative_email_canvas_start_verify_v1($pdo,$owner,$manualGoal)
    && !vp3_initiative_email_canvas_start_verify_v1($pdo,$owner,array_replace($manualGoal,['focus'=>'Altered goal']))
    && vp3_initiative_email_canvas_start_v1($pdo,$owner,7,11,$manualRequest)===$manual
    && !vp3_initiative_email_canvas_start_available_v1($pdo,$owner,7,11),
    'the explicit start seals the exact workflow, shares deduplication, and can safely be retried');
canvas_pipeline_check(vp3_initiative_execute_v1($pdo,$owner,canvas_pipeline_claim($manualPrepare),
    canvas_pipeline_email_model($manualGoal['primary']))['ok'],
    'the existing preparation worker continues after the owner starts the email board');
$manualResult=canvas_pipeline_result($manualPrepare)['initiative_tool_result']['output'];
$manualCodingRun=$manualResult['coding_run_id']??0;
canvas_pipeline_check($manualResult['status']==='draft_queued' && $manualCodingRun>0
    && vp3_coding_canvas_execute_v1($pdo,$owner,canvas_pipeline_claim($manualCodingRun),'canvas_pipeline_email_generate')['ok'],
    'the owner-started preparation and existing Coding Agent save a draft with Auto Build off');
$manualSaved=vp3_coding_canvas_job_view_v1($pdo,vp3_coding_canvas_job_row_v1($pdo,$owner,$manualCodingRun));
canvas_pipeline_check($manualSaved['state']==='ready'
    && count(vp3_coding_canvas_email_data_v1($pdo,$owner,7,11,$manualSaved['canvas_id'],$manualSaved['revision'])['rows']??[])===1,
    'the exact manually started version can access its verified email workflow');

$pdo->exec("INSERT INTO chat_conversations(id,user_id,user_agent_id,title) VALUES(18,1,7,'Another private Chat')");
$repairBlocked = false;
try {
    vp3_coding_canvas_queue_v1($pdo,$owner,7,18,'054d38b4-eadc-4d59-b82a-5f4cb204f088',
        'Keep the CanvasSources.email.get() workflow.',$manualSaved['canvas_id'],$manualSaved['revision']);
} catch (RuntimeException $error) {
    $repairBlocked = $error->getCode()===409 && str_contains($error->getMessage(),'original Chat');
}
canvas_pipeline_check($repairBlocked && vp3_coding_canvas_email_data_v1($pdo,$owner,7,18,$manualSaved['canvas_id'],$manualSaved['revision'])===null,
    'another private Chat for the same Agent cannot read or rebind the exact email connection');
$repair = vp3_coding_canvas_queue_v1($pdo,$owner,7,11,'054d38b4-eadc-4d59-b82a-5f4cb204f089',
    'Keep the CanvasSources.email.get() workflow and clarify the deadline.',$manualSaved['canvas_id'],$manualSaved['revision']);
$repaired = vp3_coding_canvas_execute_v1($pdo,$owner,canvas_pipeline_claim($repair['run_id']),'canvas_pipeline_email_generate');
$repairedView = vp3_coding_canvas_job_view_v1($pdo,vp3_coding_canvas_job_row_v1($pdo,$owner,$repair['run_id']));
canvas_pipeline_check($repaired['ok'] && $repairedView['revision']>$manualSaved['revision']
    && $repairedView['canvas_id']===$manualSaved['canvas_id']
    && count(vp3_coding_canvas_email_data_v1($pdo,$owner,7,11,$repairedView['canvas_id'],$repairedView['revision'])['rows']??[])===1,
    'an explicit repair preserves the email connection through the exact previous version and saved receipt');

echo 'INITIATIVE_EMAIL_CANVAS_PIPELINE=PASS ('.$checks.' email assertions, '.($mysql ? 'mysql' : 'sqlite').")\n";
