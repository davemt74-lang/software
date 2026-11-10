<?php
declare(strict_types=1);

require __DIR__.'/fixtures/cloud-mission-isolated-mysql-v1.php';
if (getenv('VP3_CLOUD_SCHEDULE_DSN')) {
    putenv('VP3_IMAGE_TEST_DSN='.getenv('VP3_CLOUD_SCHEDULE_DSN'));
    putenv('VP3_IMAGE_TEST_USER='.getenv('VP3_CLOUD_SCHEDULE_USER'));
    putenv('VP3_IMAGE_TEST_PASSWORD='.getenv('VP3_CLOUD_SCHEDULE_PASSWORD'));
}
require __DIR__.'/fixtures/image-generation-v1.php';
require_once dirname(__DIR__).'/includes/coding-canvas-jobs-v1.php';

function personal_knowledge_available(array $user): bool { return personal_capability_has_v242('personal_knowledge.access', $user); }

// The PHP host may use a local timezone while the job database uses UTC.
date_default_timezone_set('Asia/Singapore');
$checks = 0;
function check(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
    $GLOBALS['checks']++;
}
function rejects(callable $fn, int $code, string $message): void
{
    try {
        $fn();
    } catch (Throwable $error) {
        check($error->getCode() === $code, $message.' ('.$error->getMessage().')');
        return;
    }
    throw new RuntimeException($message);
}

$GLOBALS['initiativeScreenAccess'] = true;
$mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
$folderKey = $mysql ? 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY';
$itemKey = $mysql ? 'INT UNSIGNED PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY';
$pdo->exec("CREATE TABLE settings (setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT);
    CREATE TABLE artist_transcript_folders_v177 (id $folderKey,created_by_user_id INT,folder_name VARCHAR(80),sort_order INT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE knowledge_items (id $itemKey, title VARCHAR(190), description TEXT, file_name VARCHAR(190),
        file_type VARCHAR(60), mime_type VARCHAR(80), file_size INT, content_text TEXT, visibility VARCHAR(20),
        is_published INT, created_by_user_id INT, knowledge_scope VARCHAR(20), folder_id BIGINT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP);
    CREATE TABLE ai_execution_ledger (id $itemKey,user_id INT,created_at DATETIME)");
vp3_ai_service_usage_ensure_schema_v1($pdo);
vp3_coding_canvas_schema_v1($pdo);
vp3_coding_canvas_schema_v1($pdo);
if (!$mysql) $pdo->exec('DELETE FROM agent_workflow_runs');

$owner = ['id' => 1, 'is_active' => 1];
$foreign = ['id' => 2, 'is_active' => 1];
$selected = ['provider' => 'openai', 'model' => 'gpt-coding-fixture-main'];
$replacement = ['provider' => 'anthropic', 'model' => 'claude-coding-fixture-alternate'];
foreach ([$selected, $replacement] as $model) {
    vp3_ai_service_save_model_v1($pdo, $model['provider'], $model['model'], $model['model'], ['code_build', 'code_repair']);
}
vp3_coding_canvas_model_save_v1($pdo, $owner, $selected);

$request = agent_event_uuid_v1920();
$job = vp3_coding_canvas_queue_v1($pdo, $owner, 7, 11, $request, 'Make a prep board');
$id = $job['run_id'];
check($job['state'] === 'queued' && $job['model'] === $selected + ['funding' => 'system'], 'Pinned model is frozen at admission');
check(vp3_coding_canvas_resume_v1($pdo, $owner, 7, 11) === $job, 'A reload resumes exactly the active Chat job');
check(vp3_coding_canvas_resume_v1($pdo, $owner, 8, 12) === null, 'A different Agent does not see the job');
rejects(fn() => vp3_coding_canvas_job_v1($pdo, $foreign, 7, 11, $id, 'job'), 404, 'A different owner cannot read the job');
rejects(fn() => vp3_coding_canvas_job_v1($pdo, $owner, 7, 12, $id, 'tick'), 404, 'A different Chat cannot tick the job');
rejects(fn() => vp3_coding_canvas_queue_v1($pdo, $owner, 8, 12, agent_event_uuid_v1920(), 'Second Canvas'), 409, 'One active coding job is allowed per owner');

vp3_coding_canvas_model_save_v1($pdo, $owner, $replacement);
check(vp3_coding_canvas_queue_v1($pdo, $owner, 7, 11, $request, 'Make a prep board') === $job, 'Replaying admission returns the original model and run');
rejects(fn() => vp3_coding_canvas_queue_v1($pdo, $owner, 7, 11, $request, 'Make a different board'), 409, 'One idempotency key cannot accept different work');
check(agent_workflow_public_run_v1400($pdo, vp3_coding_canvas_job_row_v1($pdo, $owner, $id))['goal'] === '', 'Private coding prompt is withheld from generic workflow history');

$claim = static fn(int $id): ?array => agent_job_claim_run_v1900($pdo, $owner, $id, 'cloud', agent_worker_cloud_worker_id_v1910(1), 180);
$source = ['html' => '<button>Chop onions</button>', 'css' => '', 'js' => ''];
$lease = $claim($id);
check($lease !== null && $claim($id) === null, 'Only one worker can claim the exact run');
$calls = 0;
$generate = function ($connection, $user, $pin, $jobId, $requestId, $prompt, $previous, $diagnostic, $context)
    use (&$calls, $selected, $id, $request, $source): array {
    $calls++;
    check($pin === $selected + ['funding' => 'system'] && (int)$jobId === $id && $requestId === $request, 'Dispatch receives the frozen provider selection');
    check($previous === null && $context === ['agent_id' => 7, 'conversation_id' => 11], 'Generation receives exact usage attribution and no app data');
    return ['title' => 'Prep board', 'artifact' => $source];
};
check(vp3_coding_canvas_execute_v1($pdo, $owner, $lease, $generate)['ok'], 'One worker saves the draft with its receipt');
check(!vp3_coding_canvas_execute_v1($pdo, $owner, $lease, $generate)['ok'] && $calls === 1, 'Replaying a settled lease does not call the provider');
$done = vp3_coding_canvas_job_v1($pdo, $owner, 7, 11, $id, 'job');
check($done['state'] === 'ready' && $done['revision'] === 1, 'A ready run points at its exact saved revision');
$canvasId = $done['canvas_id'];
$canvas = vp3_coding_canvas_read_v1($pdo, $owner, 7, $canvasId);
check($canvas['revision']['artifact'] === $source && $canvas['canvas']['folder_id'] > 0, 'The draft has a real private folder and code');
$canvas = vp3_coding_canvas_mark_tested_v1($pdo, $owner, 7, $canvasId, 1, $canvas['state']['revision']);
$canvas = vp3_coding_canvas_activate_v1($pdo, $owner, 7, $canvasId, 1, null);
vp3_coding_canvas_save_state_v1($pdo, $owner, 7, $canvasId, 1, $canvas['state']['revision'], ['onions' => true]);

$repair = vp3_coding_canvas_queue_v1($pdo, $owner, 7, 11, agent_event_uuid_v1920(), 'Fix the label', $canvasId, 1, 'Old label');
$repairLease = $claim($repair['run_id']);
$repairGenerator = function ($connection, $user, $pin, $jobId, $requestId, $prompt, $previous, $diagnostic)
    use ($source, $replacement): array {
    check($pin === $replacement + ['funding' => 'system'] && $previous === $source && $diagnostic === 'Old label', 'Repair gets the exact old source and current model');
    return ['title' => 'Changed title', 'artifact' => array_replace($source, ['html' => '<button>Prep onions</button>'])];
};
check(vp3_coding_canvas_execute_v1($pdo, $owner, $repairLease, $repairGenerator)['ok'], 'Repair saves a new private draft');
$repaired = vp3_coding_canvas_read_v1($pdo, $owner, 7, $canvasId, 2);
check($repaired['canvas']['active_revision'] === 1 && $repaired['canvas']['title'] === 'Prep board'
    && $repaired['state']['value']->onions === true, 'Repair preserves the active Canvas and live prep data');

$stopping = vp3_coding_canvas_queue_v1($pdo, $owner, 7, 11, agent_event_uuid_v1920(), 'Another build');
$stopLease = $claim($stopping['run_id']);
$stopped = vp3_coding_canvas_execute_v1($pdo, $owner, $stopLease, function () use ($pdo, $owner, $stopping, $source): array {
    check(vp3_coding_canvas_job_v1($pdo, $owner, 7, 11, $stopping['run_id'], 'stop')['state'] === 'stopped', 'Owner can stop while the provider is running');
    return ['title' => 'Stopped job', 'artifact' => $source];
});
check(!$stopped['ok'] && count(vp3_coding_canvas_list_v1($pdo, $owner, 7)) === 1, 'Late provider result after Stop cannot save a Canvas');

$failed = vp3_coding_canvas_queue_v1($pdo, $owner, 7, 11, agent_event_uuid_v1920(), 'Will fail');
$failedLease = $claim($failed['run_id']);
check(!vp3_coding_canvas_execute_v1($pdo, $owner, $failedLease, static function (): never {
    throw new RuntimeException('private provider output', 402);
})['ok'], 'Provider failure does not save a draft');
$failedView = vp3_coding_canvas_job_v1($pdo, $owner, 7, 11, $failed['run_id'], 'tick');
check($failedView['state'] === 'failed' && !str_contains($failedView['reason'], 'private provider output'), 'Failed jobs expose a safe status');
rejects(fn() => agent_job_retry_v1900($pdo, $owner, $failed['run_id']), 409, 'Generic retry cannot replay a metered request');

$interrupted = vp3_coding_canvas_queue_v1($pdo, $owner, 7, 11, agent_event_uuid_v1920(), 'Interrupted build');
$interruptedLease = $claim($interrupted['run_id']);
$pdo->prepare('UPDATE agent_workflow_runs SET lease_expires_at=? WHERE id=?')
    ->execute([gmdate('Y-m-d H:i:s', time() - 10), $interrupted['run_id']]);
$recovered = vp3_coding_canvas_job_v1($pdo, $owner, 7, 11, $interrupted['run_id'], 'job');
check($interruptedLease !== null && $recovered['state'] === 'failed' && $claim($interrupted['run_id']) === null,
    'Polling an interrupted worker settles its expired lease without repeating the request');

$abandoned = vp3_coding_canvas_queue_v1($pdo, $owner, 8, 12, agent_event_uuid_v1920(), 'Deleted Chat');
$pdo->exec('DELETE FROM chat_conversations WHERE id=12');
$replacementJob = vp3_coding_canvas_queue_v1($pdo, $owner, 7, 11, agent_event_uuid_v1920(), 'New Chat build');
check($replacementJob['state'] === 'queued'
    && vp3_coding_canvas_job_row_v1($pdo, $owner, $abandoned['run_id'])['status'] === 'cancelled',
    'Deleting an origin Chat does not strand queued work or prevent the next build');

echo 'CODING_CANVAS_JOBS=PASS ('.$checks." assertions)\n";
