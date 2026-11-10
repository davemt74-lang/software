<?php
declare(strict_types=1);

require __DIR__.'/../includes/coding-canvas-model-v1.php';

function ai_model_catalog(): array { return ['openai' => ['legacy-chat-model' => 'Legacy Chat']]; }
function ai_provider_enabled(string $provider): bool { return !in_array($provider, $GLOBALS['disabledProviders'], true); }
function ai_provider_api_key(string $provider): string { return 'system-fixture-'.$provider; }
function ai_decrypt_secret(string $value): string { return $value === '' ? '' : 'decrypted-fixture-'.$value; }

function coding_model_same(mixed $actual, mixed $expected, string $description): void
{
    if ($actual !== $expected) throw new RuntimeException($description);
}

function coding_model_rejects(int $status, callable $action): void
{
    try {
        $action();
    } catch (RuntimeException $error) {
        coding_model_same($error->getCode(), $status, $error->getMessage());
        return;
    }
    throw new RuntimeException('The coding model should have been rejected.');
}

$GLOBALS['disabledProviders'] = [];
$pdo = new PDO('sqlite::memory:');
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY);
    INSERT INTO users VALUES (1),(2);
    CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
    CREATE TABLE user_llm_preferences (user_id INTEGER PRIMARY KEY, route TEXT, provider TEXT);
    CREATE TABLE user_llm_credentials (user_id INTEGER, provider TEXT, encrypted_key TEXT, model TEXT, PRIMARY KEY (user_id,provider));");
$owner = ['id' => 1];
$other = ['id' => 2];
$openai = ['provider' => 'openai', 'model' => 'ft:code-a'];
$anthropic = ['provider' => 'anthropic', 'model' => 'code-b'];
$buildOnly = ['provider' => 'openai', 'model' => 'build-only'];
$stageLabels = VP3_AI_CODING_SERVICES_V1;
vp3_ai_service_save_model_v1($pdo, $buildOnly['provider'], $buildOnly['model'], 'Build Only', ['code_build']);
coding_model_same(vp3_coding_canvas_model_state_v1($pdo, $owner)['options'], [], 'Legacy and build-only models are excluded.');
try {
    vp3_ai_service_save_route_v1($pdo, 'code_build', $buildOnly, null);
    throw new RuntimeException('An Admin route must use a model approved for both stages.');
} catch (InvalidArgumentException) {
}
vp3_ai_service_save_model_v1($pdo, $openai['provider'], $openai['model'], 'Code A', $stageLabels);
vp3_ai_service_save_model_v1($pdo, $anthropic['provider'], $anthropic['model'], 'Code B', $stageLabels);
vp3_ai_service_save_route_v1($pdo, 'code_build', $openai, null);
vp3_ai_service_save_route_v1($pdo, 'code_repair', $openai, null);
$systemOpenAI = $openai + ['funding' => 'system'];
$systemAnthropic = $anthropic + ['funding' => 'system'];
coding_model_same(vp3_coding_canvas_model_resolve_v1($pdo, $owner), $systemOpenAI, 'Admin Build route supplies automatic choice.');

$pdo->beginTransaction();
coding_model_same(vp3_coding_canvas_model_resolve_v1($pdo, $owner), $systemOpenAI, 'Resolve also works inside the existing job transaction.');
coding_model_same($pdo->inTransaction(), true, 'Resolve preserves the caller transaction.');
$pdo->rollBack();

$state = vp3_coding_canvas_model_save_v1($pdo, $owner, $anthropic);
coding_model_same($state['selected'], $anthropic, 'The selected provider and model persist.');
coding_model_same(str_contains(json_encode($state), 'fixture'), false, 'Member state excludes API keys.');
coding_model_same(vp3_coding_canvas_model_resolve_v1($pdo, $other), $systemOpenAI, 'The other member retains their own choice.');
coding_model_same(vp3_coding_canvas_model_authorized_v1($pdo, $owner, $systemAnthropic, 'code_repair')['key'], 'system-fixture-anthropic', 'Repair honors the same exact authorized pin.');

vp3_coding_canvas_model_save_v1($pdo, $owner, $openai);
coding_model_same(vp3_coding_canvas_model_resolve_v1($pdo, $owner), $systemOpenAI, 'A new run uses the new model choice.');
coding_model_same(vp3_coding_canvas_model_authorized_v1($pdo, $owner, $systemAnthropic, 'code_repair')['key'], 'system-fixture-anthropic', 'Existing runs keep the earlier eligible pin.');
vp3_ai_service_save_rule_v1($pdo, 'user', 1, 'code_repair', ['allowed_models' => ['anthropic:code-b']]);
coding_model_rejects(409, fn() => vp3_coding_canvas_model_resolve_v1($pdo, $owner));
coding_model_rejects(403, fn() => vp3_coding_canvas_model_authorized_v1($pdo, $owner, $systemOpenAI, 'code_build'));
vp3_coding_canvas_model_save_v1($pdo, $owner, null);
coding_model_same(vp3_coding_canvas_model_resolve_v1($pdo, $owner), $systemAnthropic, 'Automatic resolves to the eligible intersection when a route default is restricted.');
vp3_ai_service_save_rule_v1($pdo, 'user', 1, 'code_repair', []);

$GLOBALS['disabledProviders'] = ['openai'];
coding_model_same(vp3_coding_canvas_model_resolve_v1($pdo, $owner), $systemAnthropic, 'Automatic excludes disabled system providers.');
$GLOBALS['disabledProviders'] = [];
$pdo->exec("INSERT INTO user_llm_preferences VALUES (1,'own_key','openai');
    INSERT INTO user_llm_credentials VALUES (1,'anthropic','personal-b','conversation-model'), (2,'openai','other-owner','conversation-model');");
coding_model_same(vp3_coding_canvas_model_resolve_v1($pdo, $owner), $anthropic + ['funding' => 'own_key'], 'Personal funding checks the exact member-owned provider key.');
coding_model_rejects(409, fn() => vp3_coding_canvas_model_authorized_v1($pdo, $owner, $systemAnthropic, 'code_repair'));
coding_model_rejects(409, fn() => vp3_coding_canvas_model_save_v1($pdo, $owner, $openai));
coding_model_same(vp3_coding_canvas_model_authorized_v1($pdo, $owner, $anthropic + ['funding' => 'own_key'], 'code_build')['key'], 'decrypted-fixture-personal-b', 'Dispatch gets the matching personal key.');
$pdo->exec("DELETE FROM user_llm_credentials WHERE user_id=1 AND provider='anthropic'");
coding_model_rejects(409, fn() => vp3_coding_canvas_model_authorized_v1($pdo, $owner, $anthropic + ['funding' => 'own_key'], 'code_repair'));

echo "Coding Agent model selection, policy intersection, pinning and funding passed.\n";
