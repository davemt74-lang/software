<?php
declare(strict_types=1);

/**
 * VP3 v0.30 — deterministic end-to-end compute provenance journey.
 *
 * This regression intentionally exercises the canonical production execution
 * normalizer used by Agent Chat while replacing only external persistence and
 * billing dependencies with deterministic fakes. No relay credentials, model
 * calls, or paid cloud tokens are used by CI.
 */

final class V030FakeStatement
{
    private array $row;

    public function __construct(array $row)
    {
        $this->row = $row;
    }

    public function execute(array $params = []): bool
    {
        return $params === [7, 'v030-trace'];
    }

    public function fetch(): array|false
    {
        return $this->row;
    }
}

final class V030FakeDb
{
    public function prepare(string $sql): V030FakeStatement
    {
        if (!str_contains($sql, 'FROM ai_usage_ledger')) {
            throw new RuntimeException('Unexpected synthetic query');
        }
        return new V030FakeStatement([
            'id' => 901,
            'provider' => 'openai',
            'model' => 'gpt-test',
            'input_tokens' => 30,
            'output_tokens' => 15,
            'total_tokens' => 45,
        ]);
    }
}

function subscription_ai_balance(array $user): array
{
    return ['unlimited' => false, 'remaining' => 955];
}

function agent_runtime_v125_trace_id(): string
{
    return 'v030-trace';
}

function db(): V030FakeDb
{
    return new V030FakeDb();
}

function table_exists(string $table): bool
{
    return $table === 'ai_usage_ledger';
}

function homeserver_agent_v018_last_attempt(): array
{
    return [
        'attempted' => true,
        'success' => false,
        'latency_ms' => 212,
        'failure_class' => 'timeout',
        'provider' => '',
        'model' => '',
    ];
}

require dirname(__DIR__) . '/includes/chat-execution-v019.php';

function v030_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function v030_round_trip(array $execution): array
{
    $source = chat_execution_v019_source($execution);
    $context = [
        'execution' => $execution,
        'sources' => [$source],
    ];

    $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $decoded = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
    v030_assert(is_array($decoded), 'persisted message context must decode');
    v030_assert(($decoded['execution']['source'] ?? '') === ($execution['source'] ?? ''), 'execution source must survive persistence');
    v030_assert(($decoded['sources'][0]['source'] ?? '') === 'compute-routing:v019', 'compute source chip must survive persistence');
    v030_assert(($decoded['sources'][0]['title'] ?? '') === ($source['title'] ?? ''), 'source title must survive persistence');

    foreach (['relay-secret', 'home-secret', 'bearer_token', 'provider_response', 'raw_error'] as $forbidden) {
        v030_assert(!str_contains($encoded, $forbidden), 'persisted provenance must not contain '.$forbidden);
    }
    return $decoded;
}

// Journey A: paired HomeServer executes locally and VP3 records zero cloud debit.
$local = chat_execution_v019_homeserver([
    'compute_source' => 'homeserver_local',
    'provider' => 'ollama',
    'model' => 'llama-test',
    'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30],
    'run_id' => 7001,
    'cloud_tokens_debited' => 0,
    'latency_ms' => 84,
]);

v030_assert(($local['source'] ?? '') === 'homeserver_local', 'healthy paired route must be HomeServer Local');
v030_assert(($local['homeserver'] ?? '') === 'connected', 'healthy local route must report connected HomeServer');
v030_assert(($local['fallback_used'] ?? true) === false, 'healthy local route must not report fallback');
v030_assert((int)($local['cloud_tokens_debited'] ?? -1) === 0, 'local route must debit zero VP3 cloud tokens');
v030_assert((int)($local['run_id'] ?? 0) === 7001, 'HomeServer run id must be retained');
$localReload = v030_round_trip($local);
$localTitle = (string)($localReload['sources'][0]['title'] ?? '');
v030_assert(str_contains($localTitle, 'Compute: HomeServer Local'), 'reload must still identify HomeServer Local');
v030_assert(str_contains($localTitle, 'ollama / llama-test'), 'reload must still identify local provider/model');
v030_assert(str_contains($localTitle, '84 ms HomeServer'), 'reload must still identify HomeServer latency');

// Journey B: paired HomeServer times out, canonical VP3 fallback uses the cloud
// ledger, retains the HomeServer failure reason, and exposes the cloud charge.
$fallback = chat_execution_v019_fallback(['id' => 7], true, true);
v030_assert(($fallback['source'] ?? '') === 'vp3_cloud', 'failed HomeServer route must use VP3 Cloud when ledger usage exists');
v030_assert(($fallback['homeserver'] ?? '') === 'unavailable', 'fallback must retain unavailable HomeServer state');
v030_assert(($fallback['fallback_used'] ?? false) === true, 'fallback must be explicit');
v030_assert(($fallback['fallback_reason'] ?? '') === 'homeserver_unavailable', 'fallback reason must be persisted');
v030_assert(($fallback['failure_class'] ?? '') === 'timeout', 'HomeServer timeout classification must be persisted');
v030_assert((int)($fallback['latency_ms'] ?? 0) === 212, 'HomeServer failure latency must be persisted');
v030_assert((int)($fallback['cloud_tokens_debited'] ?? 0) === 45, 'cloud debit must come from canonical ledger usage');
v030_assert((int)($fallback['cloud_balance_remaining'] ?? 0) === 955, 'remaining cloud balance must be persisted');
$fallbackReload = v030_round_trip($fallback);
$fallbackTitle = (string)($fallbackReload['sources'][0]['title'] ?? '');
v030_assert(str_contains($fallbackTitle, 'Compute: VP3 Cloud'), 'reload must identify VP3 Cloud fallback');
v030_assert(str_contains($fallbackTitle, 'HomeServer timeout → fallback'), 'reload must explain why local execution failed');
v030_assert(str_contains($fallbackTitle, '45 VP3 tokens charged'), 'reload must expose cloud token debit');
v030_assert(str_contains($fallbackTitle, '955 VP3 tokens left'), 'reload must expose cloud token balance');

// Journey C: an unpaired machine must be distinguishable from a paired outage.
$unpaired = chat_execution_v019_fallback(['id' => 7], false, true);
v030_assert(($unpaired['homeserver'] ?? '') === 'not_paired', 'unpaired state must not be collapsed into an outage');
v030_assert(($unpaired['fallback_reason'] ?? '') === 'homeserver_not_paired', 'unpaired fallback reason must be explicit');
v030_assert(($unpaired['failure_class'] ?? '') === 'homeserver_not_paired', 'unpaired failure class must be explicit');

print("VP3 v0.30 HomeServer runtime journey acceptance passed\n");
