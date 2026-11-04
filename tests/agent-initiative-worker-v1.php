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

echo "AGENT_INITIATIVE_WORKER_V1=PASS ($checks cases)\n";
