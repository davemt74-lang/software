<?php
declare(strict_types=1);

require __DIR__.'/agent-initiative-transcripts-v1.php';
require_once dirname(__DIR__).'/includes/agent-initiative-transcript-research-v1.php';

$beforeChecks = $checks;
$GLOBALS['transcriptActor'] = ['id' => 1, 'is_active' => 1];
$researchRef = static fn(int $session, string $plugin, string $section, string $item): string =>
    'transcript-research:'.$session.':'.hash('sha256', json_encode([$plugin, $section, $item], JSON_THROW_ON_ERROR));
$readResearch = static function (?array $refs = null, ?DateTimeImmutable $at = null) use ($pdo, $scope, $binding, $now): array {
    $coverage = [];
    $rows = vp3_initiative_transcript_research_evidence_v1($pdo, $scope, $binding, $at ?? $now, $refs, $coverage);
    return [$rows, $coverage['transcript_research'] ?? null];
};
$modules = [
    'qa' => ['unanswered' => [
        ['item_id' => 'qa-open', 'question' => 'What is the regional energy standard?', 'follow_up' => 'Check the current official requirements', 'evidence' => 'Page 1'],
        ['item_id' => 'qa-rejected', 'question' => 'Was that rejected?', 'review_state' => 'rejected'],
    ], 'answered' => [['item_id' => 'qa-answered', 'question' => 'What did the supplier say?', 'answer' => 'Next week']]],
    'basic' => ['open_questions' => [['item_id' => 'duplicate', 'text' => 'WHAT is the regional energy standard.']]],
    'followup' => ['items' => [
        ['item_id' => 'done', 'follow_up' => 'Already completed', 'status' => 'completed'],
        ['item_id' => 'tracked', 'follow_up' => 'Existing task', 'actions' => ['agent_task' => ['record_id' => 61, 'performed_at' => gmdate('c')]]],
        ['item_id' => 'open', 'follow_up' => 'Check the publicly reported warranty terms', 'status' => 'waiting'],
    ]],
    'opportunities' => ['items' => [['item_id' => 'o1', 'opportunity' => 'Published benchmark research', 'type' => 'research', 'validation_step' => 'Review official benchmark publications']]],
];
transcript_capability_mutate(static function (PDO $pdo) use ($modules, $segments): void {
    $pdo->exec('CREATE TABLE settings(setting_key TEXT PRIMARY KEY,setting_value TEXT)');
    $insert = $pdo->prepare('INSERT INTO artist_transcript_sessions_v172
        (id,created_by_user_id,conversation_id,title,status,started_at,last_activity_at,metadata_json) VALUES(?,?,?,?,?,?,?,?)');
    for ($id = 10001; $id <= 10010; $id++) {
        $date = $id === 10001 ? '2021-01-01 00:00:00' : '2026-11-05 00:00:00';
        $insert->execute([$id, 1, 170, 'Saved recording '.$id, 'draft', $date, $date, '{}']);
        $segments->execute([$id, $id, 'research-segment-'.$id, 'transcript', 'Owner', 'Private source text '.$id, $date, $date]);
        transcript_capability_analysis($id, $id === 10001 ? $modules : [
            'qa' => ['unanswered' => [['item_id' => 'qa-'.$id, 'question' => 'Public research opportunity '.$id]]],
        ]);
    }
});

$qaRef = $researchRef(10001, 'qa', 'unanswered', 'qa-open');
$duplicateRef = $researchRef(10001, 'basic', 'open_questions', 'duplicate');
$opportunityRef = $researchRef(10001, 'opportunities', 'items', 'o1');
[$rows, $coverage] = $readResearch([$qaRef]);
transcript_capability_check($rows === [] && $coverage === 'forbidden', 'research candidates require a current owner opt-in');
transcript_capability_mutate(fn(PDO $pdo) => vp3_transcript_research_save_v1($pdo, 1, true));

[$rows, $coverage] = $readResearch([$qaRef, $duplicateRef, $opportunityRef]);
$qa = $rows[$qaRef]['research'] ?? [];
transcript_capability_check($coverage === 'ready' && ($qa['question'] ?? '') === 'What is the regional energy standard?'
    && $qa['next_step'] === 'Check the current official requirements' && $qa['review_state'] === 'unreviewed'
    && str_contains($qa['source_url'], 'session=10001&plugin=qa&section=unanswered&item=qa-open&page=1'),
    'exact resolution preserves the original Q&A question, next check, review state and evidence link');
transcript_capability_check($rows[$duplicateRef]['research']['topic_key'] === $qa['topic_key']
    && $rows[$opportunityRef]['research']['kind'] === 'research',
    'equivalent open questions share a topic key while distinct research opportunities retain their own origins');
$original = $rows[$qaRef];

[$rows] = $readResearch([
    $researchRef(10001, 'qa', 'unanswered', 'qa-rejected'), $researchRef(10001, 'qa', 'answered', 'qa-answered'),
    $researchRef(10001, 'followup', 'items', 'done'), $researchRef(10001, 'followup', 'items', 'tracked'),
    $researchRef(10001, 'followup', 'items', 'open'),
]);
transcript_capability_check(array_keys($rows) === [$researchRef(10001, 'followup', 'items', 'open')],
    'answered, rejected, tracked and completed findings do not compete with open follow-ups');

$sessions = [];
for ($turn = 0; $turn < 5; $turn++) {
    [$rows, $coverage] = $readResearch(null, $now->modify('+'.($turn * 5).' minutes'));
    transcript_capability_check($coverage === 'limited' && count($rows) <= 8
        && count(array_unique(array_map(static fn(array $row): string => $row['research']['topic_key'], $rows))) === count($rows),
        'discovery bounds candidates and collapses equivalent topics within a rotating page');
    foreach ($rows as $row) $sessions[$row['research']['session_id']] = true;
}
transcript_capability_check(!array_diff(range(10001, 10010), array_keys($sessions)),
    'rotating discovery considers each saved recording, including the old recording');

transcript_capability_mutate(static function (PDO $pdo) use ($modules): void {
    $edited = $modules;
    $edited['qa']['unanswered'][0]['follow_up'] = 'Check the current official limits';
    transcript_capability_analysis(10001, $edited);
});
[$rows] = $readResearch([$qaRef]);
transcript_capability_check(isset($rows[$qaRef]) && $rows[$qaRef]['revision'] !== $original['revision']
    && $rows[$qaRef]['research']['topic_key'] === $qa['topic_key'],
    'editing an exact saved finding changes its proof while keeping its identity and equivalent topic key');
$editedRevision = $rows[$qaRef]['revision'];

transcript_capability_mutate(fn(PDO $pdo) => $pdo->exec("INSERT INTO user_agent_data_rules VALUES(17,'recording','10001','deny')"));
[$rows] = $readResearch([$qaRef]);
transcript_capability_check($rows === [], 'the exact source reference does not bypass current per-Agent recording denial');
transcript_capability_mutate(fn(PDO $pdo) => $pdo->exec('DELETE FROM user_agent_data_rules'));

transcript_capability_mutate(fn(PDO $pdo) => vp3_transcript_research_save_v1($pdo, 1, false));
[$rows, $coverage] = $readResearch([$qaRef]);
transcript_capability_check($rows === [] && $coverage === 'forbidden', 'withdrawn consent immediately removes the exact candidate');
transcript_capability_mutate(fn(PDO $pdo) => vp3_transcript_research_save_v1($pdo, 1, true));
[$rows] = $readResearch([$qaRef]);
transcript_capability_check(isset($rows[$qaRef]) && $rows[$qaRef]['revision'] !== $editedRevision,
    'a new opt-in starts a new proof and does not revive a previously admitted candidate');
transcript_capability_mutate(fn(PDO $pdo) => $pdo->exec("UPDATE artist_transcript_segments_v172 SET transcript_text='New source content' WHERE session_id=10001"));
[$rows] = $readResearch([$qaRef]);
transcript_capability_check($rows === [] && $GLOBALS['transcriptInference'] === 0,
    'a stale source suppresses its candidate and the read adapter has made no model call');

echo 'AGENT_INITIATIVE_TRANSCRIPT_RESEARCH_V1=PASS ('.($checks-$beforeChecks)." cases)\n";
