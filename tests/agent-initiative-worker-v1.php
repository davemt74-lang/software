<?php
declare(strict_types=1);

// Reuse the same disposable SQLite schema used by canonical image-job tests.
// The workflow claimant and receipt writer below are the production services.
$fixtureFile = tempnam(sys_get_temp_dir(), 'vp3-initiative-worker-');
if (!$fixtureFile) throw new RuntimeException('Could not create disposable SQLite fixture.');
putenv('VP3_IMAGE_TEST_DSN=sqlite:'.$fixtureFile);
require __DIR__.'/fixtures/image-generation-v1.php';
require_once dirname(__DIR__).'/includes/agent-memory-scope-v410.php';
require_once dirname(__DIR__).'/includes/agent-brain-v122.php';
require_once dirname(__DIR__).'/includes/agent-initiative-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-screen-v1.php';
require_once dirname(__DIR__).'/includes/knowledge.php';
$GLOBALS['initiativeBrainAccess']=true;
register_shutdown_function(static fn() => unlink($fixtureFile));

class InitiativeFixturePDO extends ImageFixturePDO
{
    public function prepare(string $sql, array $options = []): PDOStatement | false
    {
        if (str_contains($sql, 'INSERT INTO agent_memory_items') && str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
            [$sql] = explode('ON DUPLICATE KEY UPDATE', $sql, 2);
            $sql .= 'ON CONFLICT(user_id,memory_hash) DO UPDATE SET memory_text=excluded.memory_text,
                confidence=excluded.confidence,last_seen_at=NOW(),is_active=1,metadata_json=excluded.metadata_json,memory_scope_version=410';
        }
        return parent::prepare($sql, $options);
    }
}

date_default_timezone_set('UTC');
$pdo = new InitiativeFixturePDO('sqlite:'.$fixtureFile, null, null, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn() => gmdate('Y-m-d H:i:s'));
$pdo->sqliteCreateFunction('IF', static fn($condition, $yes, $no) => $condition ? $yes : $no);
$pdo->sqliteCreateFunction('NOW', static fn() => gmdate('Y-m-d H:i:s'));
$pdo->exec("ALTER TABLE chat_conversations ADD COLUMN updated_at DATETIME;
    INSERT INTO chat_conversations(id,user_id,user_agent_id) VALUES (42,1,NULL),(43,2,NULL);
    CREATE TRIGGER initiative_system_agent_fixture AFTER INSERT ON agent_workflow_runs
        WHEN NEW.source_kind='agent_initiative' AND NEW.agent_id=0
        BEGIN UPDATE agent_workflow_runs SET agent_id=NULL WHERE id=NEW.id; END;
    CREATE TABLE agent_memory_items (
        id INTEGER PRIMARY KEY, user_id INTEGER, user_agent_id INTEGER, memory_type TEXT, subject TEXT, memory_text TEXT,
        memory_hash TEXT, memory_scope_version INTEGER, source_archive_id INTEGER, confidence REAL, occurrence_count INTEGER,
        first_seen_at TEXT, last_seen_at TEXT, is_active INTEGER, metadata_json TEXT, UNIQUE(user_id,memory_hash)
    );
    CREATE TABLE initiative_fixture_calendar(owner_id INTEGER PRIMARY KEY,title TEXT,starts_at TEXT)");

// Fixture adapters supply schema availability and an owned Calendar projection.
// Brain memory reads/writes still execute the production queries.
function agent_brain_schema_ready(): bool { return true; }
function agent_brain_normalize(string $text): string { return strtolower(trim($text)); }
function agent_brain_sentences(string $text): array { return [$text]; }
function vp3_participant_scope_v1(): ?array { return null; }
function calendar_schedule_awareness_snapshot_v1330(PDO $pdo, array $user, DateTimeImmutable $now, int $days, bool $includeHomeServer = true): array
{
    if ($includeHomeServer) throw new LogicException('Initiative must not query the paired calendar.');
    $stmt = $pdo->prepare('SELECT title,starts_at FROM initiative_fixture_calendar WHERE owner_id=?');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();
    $next = $row ? ['ref'=>'event:42', 'title'=>$row['title'], 'start_local'=>$row['starts_at']] : null;
    $upcoming = $next ? [$next] : [];
    $conflicts = [];
    if ($next && $user['id']===1 && !empty($GLOBALS['initiative_fixture_conflict'])) {
        $upcoming[] = ['ref'=>'event:43','title'=>'Mix session','start_local'=>$next['start_local']];
        $upcoming[] = ['ref'=>'event:44','title'=>'Release rehearsal','start_local'=>$next['start_local']];
        $conflicts[] = ['left_ref'=>'event:43','right_ref'=>'event:44','overlap_minutes'=>20];
    }
    return ['available'=>true, 'timezone'=>'UTC', 'upcoming'=>$upcoming,
        'current'=>null, 'conflicts'=>$conflicts, 'next'=>$next, 'starts_soon'=>$next!==null];
}

$checks = 0;
function initiative_worker_check(bool $ok, string $name): void
{
    if (!$ok) throw new RuntimeException($name);
    $GLOBALS['checks']++;
    echo "PASS $name\n";
}
function initiative_worker_runs(string $capability): array
{
    $stmt = db()->prepare("SELECT id,status FROM agent_workflow_runs WHERE owner_user_id=1 AND source_kind='agent_initiative' AND capability_key=? ORDER BY id");
    $stmt->execute([$capability]);
    return $stmt->fetchAll();
}
function initiative_worker_claim(array $user, int $runId): array
{
    $claim = agent_job_claim_run_v1900(db(), $user, $runId, 'cloud', agent_worker_cloud_worker_id_v1910((int) $user['id']));
    if (!$claim) throw new RuntimeException('Canonical worker could not claim the fixture run.');
    return $claim;
}
function initiative_worker_receipts(int $runId): array
{
    $stmt = db()->prepare('SELECT status,result_json FROM agent_workflow_receipts WHERE owner_user_id=1 AND run_id=? ORDER BY id');
    $stmt->execute([$runId]);
    return $stmt->fetchAll();
}
function initiative_worker_assistant_messages(): array
{
    return db()->query("SELECT conversation_id,message,context_json FROM chat_messages WHERE role='assistant' AND context_json IS NOT NULL ORDER BY id")->fetchAll();
}
function initiative_worker_expire_review(): void
{
    db()->exec("UPDATE agent_memory_items SET last_seen_at='2000-01-01 00:00:00' WHERE memory_type='initiative_sweep'");
}
function initiative_worker_new_review(array $user, string $title): int
{
    db()->prepare('UPDATE initiative_fixture_calendar SET title=? WHERE owner_id=?')->execute([$title,$user['id']]);
    initiative_worker_expire_review();
    vp3_initiative_schedule_v1(db(), $user);
    return (int) array_slice(initiative_worker_runs('initiative.plan'), -1)[0]['id'];
}
function initiative_worker_new_report(array $user, string $title, callable $model): int
{
    $plan = initiative_worker_new_review($user, $title);
    $result = vp3_initiative_execute_v1(db(), $user, initiative_worker_claim($user, $plan), $model);
    if (!$result['ok']) throw new RuntimeException('Fixture report plan did not complete.');
    return (int) array_slice(initiative_worker_runs('initiative.prepare'), -1)[0]['id'];
}

$user = ['id'=>1,'is_active'=>1];
$principal = vp3_agent_chat_principal_v380($user, null);
$starts = gmdate('Y-m-d H:i:s', time()+600);
$pdo->prepare('INSERT INTO initiative_fixture_calendar VALUES (1,?,?),(2,?,?)')
    ->execute(['Owner launch review', $starts, 'Foreign calendar entry', $starts]);
vp3_initiative_schedule_v1($pdo, $user);
initiative_worker_check(initiative_worker_runs('initiative.plan')===[], 'initiative defaults to no scheduled work');
$enabled = vp3_initiative_chat_v1($pdo, $user, $principal, ['conversation_id'=>42], 'on', null);
initiative_worker_check(str_contains($enabled['answer'], 'Initiative is on'), 'owner opt-in binds initiative to the current system Chat');

vp3_initiative_schedule_v1($pdo, $user);
vp3_initiative_schedule_v1($pdo, $user);
initiative_worker_expire_review();
vp3_initiative_schedule_v1($pdo, $user);
$plans = initiative_worker_runs('initiative.plan');
initiative_worker_check(count($plans)===1 && $plans[0]['status']==='approved', 'repeated and expired sweeps dedupe the same evidence in the canonical ledger');
initiative_worker_check(initiative_worker_assistant_messages()===[], 'queueing a review does not send a Chat message');

$planModel = static function (string $prompt, string $protocol): string {
    $rows = json_decode($prompt, true, 16, JSON_THROW_ON_ERROR);
    initiative_worker_check($protocol==='vp3.initiative-plan.v1' && count($rows)===1
        && $rows[0]['ref']==='calendar:event:42' && !isset($rows[0]['revision']) && !isset($rows[0]['url'])
        && !str_contains($prompt, 'Foreign calendar entry'), 'the planner sees only bounded current owner evidence');
    return '{"jobs":[{"tool":"work.prepare_private","primary":"calendar:event:42","refs":["calendar:event:42"],"focus":"Prepare questions for the launch review","report":true}]}';
};
$draftText = 'Private preparation: ask which mixes still need approval.';
$draftModel = static function (string $prompt, string $protocol) use ($draftText): string {
    $input = json_decode($prompt, true, 16, JSON_THROW_ON_ERROR);
    initiative_worker_check($protocol==='vp3.initiative-draft.v1' && $input['focus']==='Prepare questions for the launch review'
        && array_column($input['evidence'], 'ref')===['calendar:event:42'], 'the preparation receives the frozen assignment and revalidated sources');
    return json_encode(['draft'=>$draftText], JSON_THROW_ON_ERROR);
};

$planId = (int) $plans[0]['id'];
$planClaim = initiative_worker_claim($user, $planId);
$planResult = vp3_initiative_execute_v1($pdo, $user, $planClaim, $planModel);
$drafts = initiative_worker_runs('initiative.prepare');
initiative_worker_check($planResult['ok'] && count($drafts)===1 && count(initiative_worker_receipts($planId))===1
    && initiative_worker_assistant_messages()===[], 'a plan queues one private preparation and one receipt without posting a report');
$draftId = (int) $drafts[0]['id'];
$draftClaim = initiative_worker_claim($user, $draftId);
$draftResult = vp3_initiative_execute_v1($pdo, $user, $draftClaim, $draftModel);
$messages = initiative_worker_assistant_messages();
$receipt = initiative_worker_receipts($draftId);
initiative_worker_check($draftResult['ok'] && count($messages)===1 && count($receipt)===1
    && $receipt[0]['status']==='completed' && (int) $messages[0]['conversation_id']===42
    && $messages[0]['message']===$draftText, 'completed preparation commits exactly one report and its canonical receipt in the opted-in Chat');
initiative_worker_check(json_decode($receipt[0]['result_json'],true)['initiative_draft']===$draftText
    && !str_contains(json_encode($draftResult['receipt'],JSON_THROW_ON_ERROR),$draftText),
    'the canonical private draft is retained without leaking through the public workflow receipt');
$replay = vp3_initiative_execute_v1($pdo, $user, $draftClaim, static fn() => throw new LogicException('A finished lease must not replay inference.'));
initiative_worker_check(!$replay['ok'] && count(initiative_worker_assistant_messages())===1, 'replaying a consumed lease does not duplicate the report');

$afterReport = initiative_worker_new_report($user,'Another launch review before the next owner turn',$planModel);
$suppressed = vp3_initiative_execute_v1($pdo,$user,initiative_worker_claim($user,$afterReport),$draftModel);
initiative_worker_check($suppressed['ok'] && count(initiative_worker_assistant_messages())===1
    && json_decode(initiative_worker_receipts($afterReport)[0]['result_json'],true)['initiative_draft']===$draftText,
    'another report stays private until the owner engages again');

$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES (42,1,'user','Owner asks an unrelated question')");
$waiting = initiative_worker_new_report($user,'Launch review while the owner waits for a reply',$planModel);
$suppressed = vp3_initiative_execute_v1($pdo,$user,initiative_worker_claim($user,$waiting),$draftModel);
initiative_worker_check($suppressed['ok'] && count(initiative_worker_assistant_messages())===1
    && json_decode(initiative_worker_receipts($waiting)[0]['result_json'],true)['initiative_draft']===$draftText,
    'a report stays private while the owner waits for a Chat reply');

$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES (42,NULL,'assistant','Regular Chat reply')");
$nextDraft = initiative_worker_new_report($user,'Updated owner launch review',$planModel);
$pdo->exec("CREATE TRIGGER initiative_receipt_failure BEFORE INSERT ON agent_workflow_receipts
    WHEN NEW.status='completed' AND (SELECT COUNT(*) FROM chat_messages WHERE role='assistant' AND context_json IS NOT NULL)=2
    BEGIN SELECT RAISE(ABORT,'fixture receipt failure'); END");
$failed = vp3_initiative_execute_v1($pdo, $user, initiative_worker_claim($user, $nextDraft), $draftModel);
$receipt = initiative_worker_receipts($nextDraft);
initiative_worker_check(!$failed['ok'] && count(initiative_worker_assistant_messages())===1
    && count($receipt)===1 && $receipt[0]['status']==='failed', 'a failed success receipt rolls back the report before recording canonical failure');
$pdo->exec('DROP TRIGGER initiative_receipt_failure');

$stalePlan = initiative_worker_new_review($user,'Calendar before source changes');
$before = count(initiative_worker_runs('initiative.prepare'));
$stale = vp3_initiative_execute_v1($pdo,$user,initiative_worker_claim($user,$stalePlan),static function (string $prompt,string $protocol) use ($pdo,$planModel): string {
    $answer = $planModel($prompt,$protocol);
    $pdo->exec("UPDATE initiative_fixture_calendar SET title='Changed during inference' WHERE owner_id=1");
    return $answer;
});
initiative_worker_check(!$stale['ok'] && count(initiative_worker_runs('initiative.prepare'))===$before
    && count(initiative_worker_assistant_messages())===1, 'a source change during inference fences stale assignments before they enter the ledger');

$optOutPlan = initiative_worker_new_review($user,'Calendar before owner opt-out');
vp3_initiative_execute_v1($pdo,$user,initiative_worker_claim($user,$optOutPlan),$planModel);
$optOutDraft = (int) array_slice(initiative_worker_runs('initiative.prepare'),-1)[0]['id'];
$optedOut = vp3_initiative_execute_v1($pdo,$user,initiative_worker_claim($user,$optOutDraft),static function (string $prompt,string $protocol) use ($pdo,$user,$principal,$draftModel): string {
    $answer = $draftModel($prompt,$protocol);
    vp3_initiative_chat_v1($pdo,$user,$principal,['conversation_id'=>42],'off',null);
    return $answer;
});
initiative_worker_check(!$optedOut['ok'] && vp3_initiative_policy_v1($user)['enabled']===false
    && count(initiative_worker_assistant_messages())===1, 'opting out during draft inference fences the report and changes the live policy');

vp3_initiative_chat_v1($pdo,$user,$principal,['conversation_id'=>42],'on',null);
$quietPlan = initiative_worker_new_review($user,'Low-value calendar event');
$before = count(initiative_worker_runs('initiative.prepare'));
$quiet = vp3_initiative_execute_v1($pdo,$user,initiative_worker_claim($user,$quietPlan),static fn()=>'{"jobs":[]}');
$quietReceipt = json_decode(initiative_worker_receipts($quietPlan)[0]['result_json'],true);
initiative_worker_check($quiet['ok'] && $quietReceipt['effect_state']==='idle'
    && count(initiative_worker_runs('initiative.prepare'))===$before && count(initiative_worker_assistant_messages())===1,
    'quiet decisions record one idle receipt without creating assignments or Chat messages');

$GLOBALS['initiative_fixture_conflict'] = true;
$multiplePlan = initiative_worker_new_review($user,'Owner launch review alongside independent calendar overlap');
$multiple = vp3_initiative_execute_v1($pdo,$user,initiative_worker_claim($user,$multiplePlan),static function (string $prompt): string {
    $evidence = json_decode($prompt,true,16,JSON_THROW_ON_ERROR);
    return json_encode(['jobs'=>array_map(static fn(array $row): array => [
        'tool'=>'work.prepare_private','primary'=>$row['ref'],'refs'=>[$row['ref']],
        'focus'=>'Prepare a private next step for '.$row['title'],'report'=>false,
    ],$evidence)],JSON_THROW_ON_ERROR);
});
initiative_worker_check($multiple['ok'] && count(initiative_worker_runs('initiative.prepare'))===$before+2
    && count(initiative_worker_receipts($multiplePlan))===1 && count(initiative_worker_assistant_messages())===1,
    'two independently grounded assignments queue separately under one plan receipt');

$GLOBALS['initiativeScreenAccess'] = true;
$pdo->exec("CREATE TABLE artist_transcript_folders_v177 (id INTEGER PRIMARY KEY,created_by_user_id INTEGER,folder_name TEXT,
    sort_order INTEGER DEFAULT 0,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(created_by_user_id,folder_name));
    CREATE TABLE knowledge_items (id INTEGER PRIMARY KEY,track_id INTEGER,folder_id INTEGER,title TEXT,description TEXT,
    file_name TEXT,file_path TEXT,file_type TEXT,mime_type TEXT,file_size INTEGER,content_text TEXT,visibility TEXT,
    is_published INTEGER,created_by_user_id INTEGER,knowledge_scope TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE knowledge_chunks (id INTEGER PRIMARY KEY,knowledge_id INTEGER,chunk_index INTEGER,chunk_text TEXT);
    INSERT INTO chat_conversations(id,user_id,user_agent_id) VALUES (44,1,NULL);
    DELETE FROM initiative_fixture_calendar;");
screen_sessions_schema_v1($pdo);

function initiative_worker_screen_capture(PDO $pdo, array $user, int $number, string $note, array $options = []): array
{
    $sid = sprintf('00000000-0000-4000-8000-%012d', 100 + $number);
    $cid = sprintf('00000000-0000-4000-8000-%012d', 200 + $number);
    $session = screen_session_start_v1($pdo, $user, ['session_id'=>$sid,'title'=>'Release screen '.$number,
        'conversation_id'=>$options['conversation_id']??0]);
    $at = gmdate('Y-m-d\TH:i:s\Z',time()-($options['age']??0));
    $body = ['captured_at'=>$at,'surface'=>'browser',
        'note'=>$note,'image'=>'PRIVATE-PIXELS','transcript'=>'PRIVATE-TRANSCRIPT',
        'observation'=>['page_title'=>'PRIVATE-PAGE-TITLE','summary'=>'PRIVATE-OBSERVATION','visible_text'=>'PRIVATE-VISIBLE-TEXT']];
    $capture = screen_capture_store_v1($pdo,$user,$sid,$cid,$body);
    if (!empty($options['repeat'])) {
        $capture = screen_capture_store_v1($pdo,$user,$sid,sprintf('00000000-0000-4000-8000-%012d',400+$number),$body);
    }
    if ($options['ended']??true) {
        // This is the same status transition as the screen session API's end action.
        $pdo->prepare("UPDATE agent_screen_sessions_v1 SET status='ended' WHERE owner_user_id=? AND session_id=? AND status='active'")
            ->execute([$user['id'],$sid]);
    }
    return ['ref'=>'screen-followup:'.$sid,'session'=>$session,'capture'=>$capture];
}

function initiative_worker_screen_intent(PDO $pdo, array $user, string $sessionId): array
{
    foreach (screen_insights_feed_v1($pdo,$user,0) as $card) {
        if ($card['kind']==='intent' && $card['refs'][0]['session_id']===$sessionId) return $card;
    }
    throw new LogicException('Fixture screen note had no current intent insight.');
}

$screen = initiative_worker_screen_capture($pdo,$user,1,"  Compare the release\n  checklist tomorrow.  ",['repeat'=>true]);
$earlyScreenEvidence = vp3_initiative_screen_evidence_v1($pdo,$user,new DateTimeImmutable('now',new DateTimeZone('UTC')));
initiative_worker_check(array_keys($earlyScreenEvidence)===[$screen['ref']],
    'the reader finds the saved note before optional screen insight tables exist');
screen_insights_schema_v1($pdo);
initiative_worker_screen_capture($pdo,$user,2,'PRIVATE-ACTIVE-NOTE',['ended'=>false]);
initiative_worker_screen_capture($pdo,['id'=>2],3,'PRIVATE-FOREIGN-NOTE');
initiative_worker_screen_capture($pdo,$user,4,'PRIVATE-OTHER-AGENT-NOTE',['conversation_id'=>11]);
initiative_worker_screen_capture($pdo,$user,5,'');
initiative_worker_screen_capture($pdo,$user,6,'PRIVATE-OLD-NOTE',['age'=>8*86400]);
$unowned = initiative_worker_screen_capture($pdo,$user,7,'PRIVATE-UNOWNED-FOLDER-NOTE');
$pdo->prepare('UPDATE artist_transcript_folders_v177 SET created_by_user_id=2 WHERE id=?')->execute([$unowned['session']['folder_id']]);
$removed = initiative_worker_screen_capture($pdo,$user,8,'PRIVATE-DELETED-KNOWLEDGE-NOTE');
$pdo->prepare('DELETE FROM knowledge_items WHERE id=?')->execute([$removed['capture']['knowledge_id']]);
$withdrawn = initiative_worker_screen_capture($pdo,$user,9,'PRIVATE-WITHDRAWN-NOTE');
$pdo->prepare("INSERT INTO agent_screen_reviews_v1(owner_user_id,capture_id,state,note,updated_at) VALUES (1,?,'irrelevant','',?)")
    ->execute([$withdrawn['capture']['capture_id'],gmdate('Y-m-d\TH:i:s\Z')]);

$screenEvidence = static fn() => vp3_initiative_evidence_v1($pdo,$user,new DateTimeImmutable('now',new DateTimeZone('UTC')),
    !empty(vp3_initiative_policy_v1($user)['screen_enabled']));
$screenCommand = static fn(string $command,int $conversationId = 42) => vp3_initiative_chat_v1($pdo,$user,$principal,
    ['conversation_id'=>$conversationId],$command,null);
$screenRef = $screen['ref'];
$screenCommand('off');
$screenCommand('screen on');
initiative_worker_check($screenEvidence()===[],'screen follow-up cannot be enabled while base initiative is off');
$screenCommand('on');
$screenCommand('screen on',44);
initiative_worker_check($screenEvidence()===[],'base opt-in and another private Chat do not grant screen evidence');
$screenCommand('screen on');
$rows = $screenEvidence();
initiative_worker_check(array_keys($rows)===[$screenRef] && $rows[$screenRef]['kind']==='screen_followup'
    && $rows[$screenRef]['detail']==='Compare the release checklist tomorrow.'
    && $rows[$screenRef]['url']===$screen['session']['knowledge_url'],
    'screen opt-in folds repeated captures of a recent saved note into one owned, closed system session');
$GLOBALS['initiativeScreenAccess'] = false;
initiative_worker_check($screenEvidence()===[],'losing personal Knowledge access withdraws the screen source');
$GLOBALS['initiativeScreenAccess'] = true;
$screenCommand('screen off');
initiative_worker_check($screenEvidence()===[] && !empty(vp3_initiative_policy_v1($user)['enabled']),
    'turning screen follow-up off withdraws screen evidence while base initiative stays enabled');
$screenCommand('screen on');

$screenIntent = initiative_worker_screen_intent($pdo,$user,$screen['session']['session_id']);
$clearScreenDecision = $pdo->prepare('DELETE FROM agent_screen_insight_state_v1 WHERE owner_user_id=1 AND agent_id=0 AND insight_key=?');
foreach (['later'=>'Later','never'=>'Never'] as $decision=>$label) {
    screen_insights_decide_v1($pdo,$user,0,['key'=>$screenIntent['key'],'review_hash'=>$screenIntent['review_hash'],'decision'=>$decision]);
    initiative_worker_check($screenEvidence()===[],$label.' suppresses initiative evidence for the existing intent');
    $clearScreenDecision->execute([$screenIntent['key']]);
}

$otherRefs = $screenIntent['refs'];
$otherRefs[0]['revision']++;
$handoff = $pdo->prepare("INSERT INTO agent_screen_handoffs_v1(owner_user_id,handoff_id,agent_id,kind,status,refs_json,source_hash,updated_at)
    VALUES (1,'00000000-0000-4000-8000-000000000310',0,'research','prepared',?,?,?)");
$handoff->execute([json_encode($otherRefs,JSON_THROW_ON_ERROR),screen_insights_hash_v1($otherRefs),gmdate('Y-m-d\TH:i:s\Z')]);
initiative_worker_check(array_keys($screenEvidence())===[$screenRef],'a handoff for a different capture revision does not suppress the current note');
$pdo->prepare('UPDATE agent_screen_handoffs_v1 SET refs_json=?,source_hash=? WHERE owner_user_id=1')
    ->execute([json_encode($screenIntent['refs'],JSON_THROW_ON_ERROR),$screenIntent['review_hash']]);
initiative_worker_check($screenEvidence()===[],'an exact prepared handoff suppresses duplicate screen initiative work');
$pdo->exec('DELETE FROM agent_screen_handoffs_v1 WHERE owner_user_id=1');

$screenPlanModel = static function (string $prompt,string $protocol) use ($screenRef): string {
    $rows = json_decode($prompt,true,16,JSON_THROW_ON_ERROR);
    initiative_worker_check($protocol==='vp3.initiative-plan.v1' && count($rows)===1 && $rows[0]['ref']===$screenRef
        && $rows[0]['kind']==='screen_followup' && $rows[0]['detail']==='Compare the release checklist tomorrow.'
        && isset($rows[0]['saved_at']) && !array_diff(array_keys($rows[0]),['ref','kind','title','detail','saved_at']) && !str_contains($prompt,'PRIVATE-'),
        'planner receives the normalized explicit screen note without observations, pixels, transcript, URL, or capture metadata');
    return json_encode(['jobs'=>[['tool'=>'work.prepare_private','primary'=>$screenRef,'refs'=>[$screenRef],
        'focus'=>'Draft a private checklist follow-up','report'=>true]]],JSON_THROW_ON_ERROR);
};
$screenPlanCount = count(initiative_worker_runs('initiative.plan'));
initiative_worker_expire_review();
vp3_initiative_schedule_v1($pdo,$user);
initiative_worker_expire_review();
vp3_initiative_schedule_v1($pdo,$user);
$screenPlans = initiative_worker_runs('initiative.plan');
initiative_worker_check(count($screenPlans)===$screenPlanCount+1,'repeated sweeps deduplicate the same opted-in screen evidence');
$screenPlan = (int) $screenPlans[array_key_last($screenPlans)]['id'];
$screenPlanResult = vp3_initiative_execute_v1($pdo,$user,initiative_worker_claim($user,$screenPlan),$screenPlanModel);
initiative_worker_check($screenPlanResult['ok'],'an eligible screen note can queue private preparation');
$screenDrafts = initiative_worker_runs('initiative.prepare');
$screenDraft = (int) $screenDrafts[array_key_last($screenDrafts)]['id'];
$dismissed = vp3_initiative_execute_v1($pdo,$user,initiative_worker_claim($user,$screenDraft),static function (string $prompt,string $protocol) use ($pdo,$user,$screenIntent,$screenRef): string {
    $input = json_decode($prompt,true,16,JSON_THROW_ON_ERROR);
    initiative_worker_check($protocol==='vp3.initiative-draft.v1' && array_column($input['evidence'],'ref')===[$screenRef]
        && !str_contains($prompt,'PRIVATE-'),'private preparation is grounded in only the selected screen note');
    screen_insights_decide_v1($pdo,$user,0,['key'=>$screenIntent['key'],'review_hash'=>$screenIntent['review_hash'],'decision'=>'dismiss']);
    return '{"draft":"This draft must be discarded after dismissal."}';
});
$receipt = initiative_worker_receipts($screenDraft);
initiative_worker_check(!$dismissed['ok'] && $screenEvidence()===[] && count(initiative_worker_assistant_messages())===1
    && count($receipt)===1 && $receipt[0]['status']==='failed' && !str_contains((string)$receipt[0]['result_json'],'discarded after dismissal'),
    'dismissing a screen insight during inference fences its draft and any Chat report');

$reportScreen = initiative_worker_screen_capture($pdo,$user,10,'Outline the release checklist.');
$reportScreenRef = $reportScreen['ref'];
$screenReportDraft = initiative_worker_new_report($user,'',static function (string $prompt) use ($reportScreenRef): string {
    $rows = json_decode($prompt,true,16,JSON_THROW_ON_ERROR);
    initiative_worker_check(array_column($rows,'ref')===[$reportScreenRef],'a different current screen note can still be prepared after a dismissal');
    return json_encode(['jobs'=>[['tool'=>'work.prepare_private','primary'=>$reportScreenRef,'refs'=>[$reportScreenRef],
        'focus'=>'Outline the checklist','report'=>true]]],JSON_THROW_ON_ERROR);
});
$reportClaim = initiative_worker_claim($user,$screenReportDraft);
$reportText = 'Check the export, confirm its owner, and verify the release date.';
$reported = vp3_initiative_execute_v1($pdo,$user,$reportClaim,static fn()=>json_encode(['draft'=>$reportText],JSON_THROW_ON_ERROR));
$reportMessages = initiative_worker_assistant_messages();
$screenReport = $reportMessages[array_key_last($reportMessages)];
$sources = json_decode($screenReport['context_json'],true,16,JSON_THROW_ON_ERROR)['sources'];
initiative_worker_check($reported['ok'] && count($reportMessages)===2 && $screenReport['message']===$reportText
    && (int)$screenReport['conversation_id']===42 && count(initiative_worker_receipts($screenReportDraft))===1
    && count($sources)===1 && $sources[0]['source']===$reportScreenRef && $sources[0]['url']===$reportScreen['session']['knowledge_url'],
    'a current screen note can report exactly once to the opted-in Chat with its canonical source link');
$replayed = vp3_initiative_execute_v1($pdo,$user,$reportClaim,static fn()=>throw new LogicException('A finished screen lease must not replay.'));
initiative_worker_check(!$replayed['ok'] && count(initiative_worker_assistant_messages())===2,
    'replaying a finished screen claim does not duplicate its report');

$pdo->exec("INSERT INTO chat_messages(conversation_id,user_id,role,message) VALUES
    (42,1,'user','An owner turn makes another report eligible'),(42,NULL,'assistant','Regular Chat reply')");
foreach (['deletion'=>11,'opt-out'=>12] as $withdrawal=>$number) {
    $withdrawn = initiative_worker_screen_capture($pdo,$user,$number,'Prepare release follow-up '.$number.'.');
    $withdrawnRef = $withdrawn['ref'];
    $runId = initiative_worker_new_report($user,'',static function (string $prompt) use ($withdrawnRef): string {
        $rows = json_decode($prompt,true,16,JSON_THROW_ON_ERROR);
        if (!in_array($withdrawnRef,array_column($rows,'ref'),true)) throw new LogicException('The fixture source was unavailable before withdrawal.');
        return json_encode(['jobs'=>[['tool'=>'work.prepare_private','primary'=>$withdrawnRef,'refs'=>[$withdrawnRef],
            'focus'=>'Prepare release follow-up','report'=>true]]],JSON_THROW_ON_ERROR);
    });
    $result = vp3_initiative_execute_v1($pdo,$user,initiative_worker_claim($user,$runId),static function () use ($pdo,$user,$withdrawal,$withdrawn,$screenCommand): string {
        if ($withdrawal==='deletion') screen_session_delete_v1($pdo,$user,$withdrawn['session']['session_id'],false);
        else $screenCommand('screen off');
        return '{"draft":"Never commit this withdrawn screen draft."}';
    });
    $receipt = initiative_worker_receipts($runId);
    initiative_worker_check(!$result['ok'] && !isset($screenEvidence()[$withdrawnRef]) && count(initiative_worker_assistant_messages())===2
        && count($receipt)===1 && $receipt[0]['status']==='failed' && !str_contains((string)$receipt[0]['result_json'],'withdrawn screen draft'),
        'screen '.$withdrawal.' during inference fences the private draft and its otherwise eligible Chat report');
}

$settingRecordCounts = static fn(): array => array_map(
    static fn(string $table): int => (int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn(),
    ['chat_conversations','chat_messages','agent_workflow_runs']
);
$beforeSettings = $settingRecordCounts();
$policyBeforeRead = vp3_initiative_policy_v1($user);
$changesBeforeRead = (int)$pdo->query('SELECT total_changes()')->fetchColumn();
$off = vp3_initiative_screen_setting_v1($pdo,$user,42);
initiative_worker_check($off['available']===true && $off['enabled']===false && $off['revision']===$policyBeforeRead['revision']
    && (int)$pdo->query('SELECT total_changes()')->fetchColumn()===$changesBeforeRead,
    'structured status reads the exact linked policy without writing');
$elsewhere = vp3_initiative_screen_setting_v1($pdo,$user,44);
initiative_worker_check($elsewhere['available']===false && $elsewhere['enabled']===false && $elsewhere['reason']==='linked_elsewhere'
    && empty($elsewhere['revision']) && !array_diff(array_keys($elsewhere),['available','enabled','revision','reason']),
    'another owned system Chat sees linked_elsewhere without linked policy details');

$settingRejected = static function (callable $operation, ?int $expectedCode=null): bool {
    try {
        $result = $operation();
        return $expectedCode===null && ($result['available']??true)===false && empty($result['revision']);
    } catch (RuntimeException $error) {
        return $error->getCode()===($expectedCode??403);
    }
};
foreach ([43=>'foreign',11=>'named Agent',0=>'missing'] as $conversationId=>$scope) {
    initiative_worker_check($settingRejected(static fn()=>vp3_initiative_screen_setting_v1($pdo,$user,$conversationId))
        && $settingRejected(static fn()=>vp3_initiative_screen_setting_v1($pdo,$user,$conversationId,true,$off['revision']))
        && vp3_initiative_policy_v1($user)===$policyBeforeRead,
        'structured screen settings reject reads and writes for a '.$scope.' conversation');
}

$on = vp3_initiative_screen_setting_v1($pdo,$user,42,true,$off['revision']);
$enabledPolicy = vp3_initiative_policy_v1($user);
initiative_worker_check($on['available']===true && $on['enabled']===true && $on['revision']!==$off['revision']
    && $enabledPolicy['screen_enabled']===true && $enabledPolicy['revision']===$on['revision']
    && $enabledPolicy['enabled']===true && $enabledPolicy['conversation_id']===42,
    'structured opt-in updates the canonical policy and rotates its worker revision');
$changesBeforeRepeat = (int)$pdo->query('SELECT total_changes()')->fetchColumn();
$repeat = vp3_initiative_screen_setting_v1($pdo,$user,42,true,$on['revision']);
initiative_worker_check($repeat['enabled']===true && $repeat['revision']===$on['revision']
    && (int)$pdo->query('SELECT total_changes()')->fetchColumn()===$changesBeforeRepeat,
    'repeating the current setting neither writes nor rotates the revision');
initiative_worker_check($settingRejected(static fn()=>vp3_initiative_screen_setting_v1($pdo,$user,42,false,$off['revision']),409)
    && vp3_initiative_policy_v1($user)===$enabledPolicy && !$pdo->inTransaction(),
    'a stale setting save conflicts without changing policy or leaving a transaction');

$screenCommand('screen off');
$slashOff = vp3_initiative_screen_setting_v1($pdo,$user,42);
initiative_worker_check($slashOff['enabled']===false && $slashOff['revision']!==$on['revision'],
    'the existing slash command updates the same setting the checkbox reads');
foreach (['initiativeBrainAccess'=>'Brain','initiativeScreenAccess'=>'Knowledge'] as $entitlement=>$label) {
    $GLOBALS[$entitlement] = false;
    try {
        $blocked = $settingRejected(static fn()=>vp3_initiative_screen_setting_v1($pdo,$user,42,true,$slashOff['revision']));
    } finally {
        $GLOBALS[$entitlement] = true;
    }
    initiative_worker_check($blocked && vp3_initiative_screen_setting_v1($pdo,$user,42)===$slashOff,
        'structured enable requires current '.$label.' access');
}
$screenCommand('screen on');
$slashOn = vp3_initiative_screen_setting_v1($pdo,$user,42);
initiative_worker_check($slashOn['enabled']===true && $slashOn['revision']!==$slashOff['revision'],
    'slash command opt-in is visible to the structured status reader');
$GLOBALS['initiativeBrainAccess'] = $GLOBALS['initiativeScreenAccess'] = false;
try {
    $revokedState = vp3_initiative_screen_setting_v1($pdo,$user,42);
    initiative_worker_check($revokedState['available']===true && $revokedState['enabled']===true
        && $revokedState['revision']===$slashOn['revision'] && $revokedState['reason']==='access_unavailable',
        'revoked access keeps the enabled setting visible so the owner can turn it off');
    $revokedOff = vp3_initiative_screen_setting_v1($pdo,$user,42,false,$slashOn['revision']);
} finally {
    $GLOBALS['initiativeBrainAccess'] = $GLOBALS['initiativeScreenAccess'] = true;
}
$finalPolicy = vp3_initiative_policy_v1($user);
initiative_worker_check($revokedOff['enabled']===false && $finalPolicy['screen_enabled']===false
    && $finalPolicy['revision']!==$slashOn['revision'] && $finalPolicy['enabled']===true,
    'an owner can turn screen follow-ups off after Brain and Knowledge access are lost');
initiative_worker_check($settingRecordCounts()===$beforeSettings,
    'structured settings and their shared command update do not create Chats, messages, or jobs');

echo "AGENT_INITIATIVE_WORKER_V1=PASS ($checks cases)\n";
