<?php
declare(strict_types=1);

// Reuse the canonical follow-through seeder, workflow claimant, completed
// receipts and source authorization. The provider transport is deterministic.
ob_start();
try {
    require __DIR__.'/agent-initiative-followthrough-v1.php';
} finally {
    ob_end_clean();
}
require_once dirname(__DIR__).'/includes/agent-activity-hub-v1.php';

class ActivitySuggestionsReadPDO extends InitiativeScopePDO
{
    public bool $guard = false;
    public int $writeAttempts = 0;

    private function assertRead(string $sql): void
    {
        if ($this->guard && !preg_match('/\A\s*(SELECT|PRAGMA|SHOW)\b/i', $sql)) {
            ++$this->writeAttempts;
            throw new RuntimeException('Activity suggestions attempted a database write.');
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->assertRead($query);
        return parent::prepare($query, $options);
    }

    public function exec(string $statement): int|false
    {
        $this->assertRead($statement);
        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->assertRead($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

$suggestionsChecks = 0;
function suggestions_check(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    ++$GLOBALS['suggestionsChecks'];
    echo 'PASS '.$label.PHP_EOL;
}
function suggestions_ids(array $result): array { return array_column($result['items'], 'id'); }
function suggestions_run_id(array $root): string { return 'initiative:'.(int)$root['run']['id']; }
function suggestions_contains(array $result, array $root): bool
{
    return in_array(suggestions_run_id($root), suggestions_ids($result), true);
}

$writer = db();
$writer->exec("UPDATE agent_workflow_runs SET status='cancelled' WHERE source_kind='agent_initiative'");
$roots = [];
for ($index = 1; $index <= 4; ++$index) {
    $roots[] = followthrough_seed_v1('Activity suggestion '.$index);
}
$scope = vp3_agent_activity_scope_v1($writer, $one, 17, 171);
$original = vp3_agent_activity_suggestions_v1($writer, $scope);
$expected = array_reverse(array_map('suggestions_run_id', array_slice($roots, -3)));
suggestions_check(suggestions_ids($original) === $expected,
    'the newest three actual verified preparations form the bounded suggestions');
foreach ($original['items'] as $card) {
    $runId = (int)substr($card['id'], strlen('initiative:'));
    $matched = array_values(array_filter($roots, static fn(array $root): bool => (int)$root['run']['id'] === $runId))[0];
    $messageId = (int)$matched['message']['id'];
    suggestions_check($card['kind'] === 'suggestion' && $card['state'] === 'completed'
        && $card['owner_state'] === 'open'
        && $card['actions'] === [[
            'kind'=>'chat', 'label'=>'Review suggestion', 'conversation_id'=>170, 'run_id'=>$runId,
            'href'=>url('/chat.php?agent=17&conversation_id=170').'&message_id='.$messageId, 'message_id'=>$messageId,
        ]],
        'a delivered preparation links its real run in the policy Chat rather than foreground Chat 171');
}

$otherAgent = vp3_agent_activity_scope_v1($writer, $one, 18, 180);
$otherOwner = vp3_agent_activity_scope_v1($writer, $two, 19, 190);
foreach ([$otherAgent, $otherOwner] as $foreignScope) {
    $foreign = vp3_agent_activity_suggestions_v1($writer, $foreignScope);
    suggestions_check(!array_intersect(suggestions_ids($foreign), array_map('suggestions_run_id', $roots))
        && !str_contains(json_encode($foreign, JSON_THROW_ON_ERROR), 'Activity suggestion'),
        'another Agent or account receives no private suggestion identifiers or text');
}

$latest = $roots[3];
$writer->beginTransaction();
try {
    $writer->prepare('DELETE FROM agent_workflow_receipts WHERE run_id=?')->execute([(int)$latest['run']['id']]);
    $missingReceipt = vp3_agent_activity_suggestions_v1($writer, $scope);
    suggestions_check(!suggestions_contains($missingReceipt, $latest)
        && !str_contains(json_encode($missingReceipt, JSON_THROW_ON_ERROR), 'Activity suggestion 4'),
        'removing the canonical completion receipt removes its suggestion and private objective');
} finally {
    $writer->rollBack();
}

$writer->beginTransaction();
try {
    $writer->prepare("UPDATE agent_workflow_receipts SET result_json='{}' WHERE run_id=?")
        ->execute([(int)$latest['run']['id']]);
    suggestions_check(!suggestions_contains(vp3_agent_activity_suggestions_v1($writer, $scope), $latest),
        'a receipt that disagrees with the saved action cannot endorse a suggestion');
} finally {
    $writer->rollBack();
}

$writer->beginTransaction();
try {
    $writer->exec('DELETE FROM initiative_scope_calendar WHERE owner_id=1');
    $removedSource = vp3_agent_activity_suggestions_v1($writer, $scope);
    suggestions_check(!array_intersect(suggestions_ids($removedSource), array_map('suggestions_run_id', $roots))
        && !str_contains(json_encode($removedSource, JSON_THROW_ON_ERROR), 'Activity suggestion'),
        'deleting the actual source revokes its historical prepared suggestions');
} finally {
    $writer->rollBack();
}
suggestions_check(suggestions_ids(vp3_agent_activity_suggestions_v1($writer, $scope)) === $expected,
    'restoring canonical receipt and source authority restores the same suggestions');

$acknowledged = [];
foreach (['discuss'=>'discussed', 'defer'=>'deferred', 'decline'=>'declined', 'resolve'=>'owner_resolved'] as $intent=>$state) {
    $root = followthrough_seed_v1('Acknowledged activity '.$intent);
    $decision = followthrough_decide_v1($root, $intent, 'Owner decision '.$intent);
    suggestions_check($decision['initiative_followthrough']['state'] === $state,
        'the canonical owner reply records '.$state);
    $acknowledged[] = $root;
}
$afterAcknowledgment = vp3_agent_activity_suggestions_v1($writer, $scope);
suggestions_check(!array_intersect(suggestions_ids($afterAcknowledgment), array_map('suggestions_run_id', $acknowledged))
    && !str_contains(json_encode($afterAcknowledgment, JSON_THROW_ON_ERROR), 'Acknowledged activity'),
    'discussed, deferred, declined and owner-resolved preparations leave the suggestion list');

$queuedRoot = followthrough_seed_v1('Queued private activity');
$advance = followthrough_decide_v1($queuedRoot, 'advance', 'Continue the verified private preparation');
$queued = vp3_agent_activity_suggestions_v1($writer, $scope);
$queuedCard = array_values(array_filter($queued['items'],
    static fn(array $card): bool => $card['id'] === suggestions_run_id($queuedRoot)))[0] ?? null;
suggestions_check($advance['initiative_followthrough']['state'] === 'queued'
    && $queuedCard !== null && $queuedCard['owner_state'] === 'queued' && $queuedCard['state'] === 'completed',
    'an actual queued continuation keeps its original verified preparation available');

$continuationId = (int)$advance['initiative_followthrough']['continuation_run_id'];
$completed = vp3_initiative_execute_v1($writer, $one, scope_claim($one, $continuationId),
    static fn(): string => '{"draft":"The verified private continuation is complete."}');
$resolvedRoot = vp3_initiative_followthrough_load_v1($writer, $one, $queuedRoot['principal'], 170,
    (int)$queuedRoot['message']['id']);
suggestions_check(!empty($completed['ok']) && $resolvedRoot['state']['state'] === 'completed'
    && !suggestions_contains(vp3_agent_activity_suggestions_v1($writer, $scope), $queuedRoot),
    'a real completed continuation removes its completed original proposal from new suggestions');
$readRoot = followthrough_seed_v1('A verified result remains available for read-only review');

$reader = new ActivitySuggestionsReadPDO('sqlite:'.$scopeFile, null, null, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
foreach (['NOW', 'UTC_TIMESTAMP'] as $clock) $reader->sqliteCreateFunction($clock, static fn()=>gmdate('Y-m-d H:i:s'));
$reader->sqliteCreateFunction('DATABASE', static fn()=>'main');
$reader->sqliteCreateFunction('IF', static fn($condition,$yes,$no)=>$condition?$yes:$no);
$reader->exec("ATTACH DATABASE ':memory:' AS information_schema;
    CREATE TABLE information_schema.statistics AS
    SELECT 'main' AS table_schema,tbl_name AS table_name,name AS index_name FROM main.sqlite_master WHERE type='index'");
$reader->exec('PRAGMA query_only=ON');
$reader->guard = true;
$beforeInference = count($GLOBALS['scopeInference'] ?? []);
$beforeChanges = (int)$reader->query('SELECT total_changes()')->fetchColumn();
$beforeScope = $GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410'] ?? null;
$pdo = $reader;
try {
    $firstRead = vp3_agent_activity_suggestions_v1($reader, $scope);
    $secondRead = vp3_agent_activity_suggestions_v1($reader, $scope);
    suggestions_check(suggestions_ids($firstRead) === suggestions_ids($secondRead)
        && suggestions_contains($firstRead, $readRoot) && count($firstRead['items']) <= 3,
        'repeated GET projections keep stable current suggestions within the three-card limit');
    suggestions_check($reader->writeAttempts === 0
        && (int)$reader->query('SELECT total_changes()')->fetchColumn() === $beforeChanges
        && count($GLOBALS['scopeInference'] ?? []) === $beforeInference
        && ($GLOBALS['VP3_AGENT_MEMORY_SCOPE_V410'] ?? null) === $beforeScope,
        'query-only suggestion reads attempt no writes or inference and restore incoming Agent scope');
} finally {
    $pdo = $writer;
}
echo 'AGENT_ACTIVITY_SUGGESTIONS_V1=PASS ('.$suggestionsChecks.' cases)'.PHP_EOL;
