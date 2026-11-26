<?php
declare(strict_types=1);

require __DIR__.'/../includes/agent-specialist-model-v1.php';

// Only external authorization/credential boundaries are fixtures. Preference,
// policy, ownership, revision, transaction and funding reads use real SQLite.
function has_permission(string $permission, ?array $user = null): bool
{
    return (int)($user[match ($permission) {'account.access'=>'account_access', 'chat.access'=>'chat_access', default=>'unknown'}] ?? 0) === 1;
}
function subscription_has_entitlement(array $user, string $entitlement): bool
{
    return $entitlement === 'main_ai.access' && (int)($user['main_ai_access'] ?? 0) === 1;
}
function ai_provider_configuration_failure(string $provider): ?array
{
    return in_array($provider, $GLOBALS['specialist_disabled_providers'], true) ? ['failure_code'=>'fixture_unavailable'] : null;
}
function ai_decrypt_secret(string $value): string { return $value === '' ? '' : 'private-fixture-'.$value; }
function vp3_participant_scope_v1(): ?array { return $GLOBALS['specialist_participant']; }

$checks = 0;
function specialist_same(mixed $actual, mixed $expected, string $description): void
{
    $GLOBALS['checks']++;
    if ($actual !== $expected) throw new RuntimeException($description.' ('.json_encode($actual).')');
}
function specialist_rejects(string $class, ?int $code, callable $call, string $description): void
{
    $GLOBALS['checks']++;
    try { $call(); }
    catch (Throwable $error) {
        if ($error instanceof $class && ($code === null || $error->getCode() === $code)) return;
        throw new RuntimeException($description.': '.$error::class.' '.$error->getCode().' '.$error->getMessage(), 0, $error);
    }
    throw new RuntimeException($description.': accepted unexpectedly');
}

$GLOBALS['specialist_disabled_providers'] = [];
$GLOBALS['specialist_participant'] = null;
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, is_active INTEGER, account_access INTEGER, chat_access INTEGER, main_ai_access INTEGER);
    INSERT INTO users VALUES (1,1,1,1,1),(2,1,1,1,1),(3,0,1,1,1),(4,1,0,1,1),(5,1,1,0,1),(6,1,1,1,0);
    CREATE TABLE user_agents (id INTEGER PRIMARY KEY, owner_user_id INTEGER, is_active INTEGER, retired_at TEXT);
    INSERT INTO user_agents VALUES (11,1,1,NULL),(12,2,1,NULL),(13,1,0,NULL),(14,1,1,'2026-11-25');
    CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
    CREATE TABLE user_llm_preferences (user_id INTEGER PRIMARY KEY, route TEXT, provider TEXT);
    CREATE TABLE user_llm_credentials (user_id INTEGER, provider TEXT, encrypted_key TEXT, model TEXT, PRIMARY KEY(user_id,provider));");
$owner = ['id'=>1, 'is_active'=>1, 'account_access'=>1, 'chat_access'=>1, 'main_ai_access'=>1];
$other = ['id'=>2];
$a = ['provider'=>'openai', 'model'=>'shared-model'];
$b = ['provider'=>'anthropic', 'model'=>'shared-model'];
$c = ['provider'=>'openai', 'model'=>'registered-but-not-routed'];
$selection = static fn(array $primary, ?array $fallback = null): array => compact('primary', 'fallback');
foreach ([[$a, 'Model A'], [$b, 'Model B'], [$c, 'Model C']] as [$target, $label]) {
    vp3_ai_service_save_model_v1($pdo, $target['provider'], $target['model'], $label, ['initiative']);
}

$initial = vp3_specialist_model_state_v1($pdo, $owner, 11, 'tasks');
specialist_same($initial['state'], 'inherit', 'An absent preference delegates unchanged to the Agent runtime.');
specialist_same($initial['options'], [], 'No configured service route exposes no explicit options.');
specialist_same($initial['options_reason'], 'service_route_unconfigured', 'Legacy dynamic model defaults stay owned by the existing runtime.');
specialist_rejects(RuntimeException::class, 409,
    fn()=>vp3_specialist_model_save_v1($pdo, $owner, 11, 'tasks', $initial['revision'], $selection($a)), 'Cannot pin outside a configured service ceiling');
vp3_ai_service_save_route_v1($pdo, 'initiative', $a, $b);
$serviceBefore = vp3_ai_service_read_route_v1($pdo, 'initiative');
$initial = vp3_specialist_model_state_v1($pdo, $owner, 11, 'tasks');
specialist_same(array_column($initial['options'], 'provider'), ['openai', 'anthropic'], 'Provider is part of the identity when model names match.');
specialist_same($pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key LIKE 'vp3_specialist_model_v1_%'")->fetchColumn(), 0, 'Reading does not create preference records.');
$tasks = vp3_specialist_model_save_v1($pdo, $owner, 11, 'tasks', $initial['revision'], $selection($a));
$planningInitial = vp3_specialist_model_state_v1($pdo, $owner, 11, 'planning');
$planning = vp3_specialist_model_save_v1($pdo, $owner, 11, 'planning', $planningInitial['revision'], $selection($b, $a));
specialist_same($tasks['targets'], [$a + ['funding'=>'system']], 'Tasks resolves its own configured model.');
specialist_same($planning['targets'], [$b + ['funding'=>'system'], $a + ['funding'=>'system']], 'Planning resolves a distinct primary and ordered explicit fallback.');
specialist_same($planning['reason'], 'requires_runtime_admission', 'Configuration resolution does not assert inference readiness or execution.');
specialist_same($planning['compute'], 'inherit', 'A model preference cannot override compute privacy.');
specialist_same(vp3_ai_service_read_route_v1($pdo, 'initiative'), $serviceBefore, 'Specialist changes do not rewrite global service routes.');
specialist_same(vp3_specialist_model_state_v1($pdo, $other, 12, 'tasks')['state'], 'inherit', 'Another account retains its own settings.');
specialist_same(vp3_specialist_model_state_v1($pdo, $owner, 0, 'tasks')['state'], 'inherit', 'The system Agent has an independent namespace.');
$same = vp3_specialist_model_save_v1($pdo, $owner, 11, 'planning', $planning['revision'], $selection($b, $a));
specialist_same($same['revision'], $planning['revision'], 'An identical reviewed save preserves its revision.');
specialist_rejects(RuntimeException::class, 409,
    fn()=>vp3_specialist_model_save_v1($pdo, $owner, 11, 'planning', $tasks['revision'], null), 'A revision cannot cross specialist scope');
specialist_rejects(RuntimeException::class, 409,
    fn()=>vp3_specialist_model_save_v1($pdo, $owner, 0, 'tasks', $tasks['revision'], null), 'A revision cannot cross Agent scope');

foreach ([12, 13, 14, -1] as $agentId) {
    specialist_rejects(RuntimeException::class, 403, fn()=>vp3_specialist_model_state_v1($pdo, $owner, $agentId, 'tasks'), 'Foreign, inactive, retired and negative Agent scopes fail');
}
foreach ([3, 4, 5] as $userId) {
    specialist_rejects(RuntimeException::class, 403,
        fn()=>vp3_specialist_model_state_v1($pdo, ['id'=>$userId] + $owner, 0, 'tasks'), 'Database authority defeats forged snapshot grants');
}
specialist_rejects(RuntimeException::class, 403,
    fn()=>vp3_specialist_model_state_v1($pdo, ['id'=>'1foreign'], 0, 'tasks'), 'Malformed owner identifiers are not coerced');
specialist_rejects(RuntimeException::class, 403,
    fn()=>vp3_specialist_model_state_v1($pdo, $owner, 11, 'coding'), 'This foundation does not replace Coding preferences');
$GLOBALS['specialist_participant'] = ['participant_id'=>1];
specialist_rejects(RuntimeException::class, 403,
    fn()=>vp3_specialist_model_state_v1($pdo, $owner, 11, 'tasks'), 'A participant cannot use private owner settings');
$GLOBALS['specialist_participant'] = null;

foreach ([$selection(['provider'=>'ollama','model'=>'local']), $selection(['provider'=>'https://example.invalid','model'=>'x']),
    $selection($a + ['endpoint'=>'https://example.invalid']), $selection($a, $a), ['primary'=>$a],
    ['primary'=>$a, 'fallback'=>null, 'funding'=>'own_key']] as $invalid) {
    specialist_rejects(InvalidArgumentException::class, null,
        fn()=>vp3_specialist_model_save_v1($pdo, $owner, 11, 'tasks', $tasks['revision'], $invalid), 'Untrusted selection shape or identity is rejected');
}
foreach ([$c, ['provider'=>'openai','model'=>'not-registered']] as $denied) {
    specialist_rejects(RuntimeException::class, 403,
        fn()=>vp3_specialist_model_save_v1($pdo, $owner, 11, 'tasks', $tasks['revision'], $selection($denied)), 'Registration alone cannot bypass current service targets');
}
specialist_same($pdo->inTransaction(), false, 'Rejected saves release their own transaction.');

vp3_ai_service_save_rule_v1($pdo, 'user', 1, 'initiative', ['allowed_models'=>['anthropic:shared-model']]);
specialist_same(vp3_specialist_model_resolve_v1($pdo, $owner, 11, 'tasks')['state'], 'blocked', 'An unselected service fallback is never silently added.');
specialist_same(vp3_specialist_model_resolve_v1($pdo, $owner, 11, 'planning')['targets'], [$b + ['funding'=>'system']], 'Revoked fallback is removed from the explicit candidate list.');
vp3_ai_service_save_rule_v1($pdo, 'user', 1, 'initiative', []);
$tasks = vp3_specialist_model_save_v1($pdo, $owner, 11, 'tasks', $tasks['revision'], $selection($a, $b));
$GLOBALS['specialist_disabled_providers'] = ['openai'];
$resolved = vp3_specialist_model_resolve_v1($pdo, $owner, 11, 'tasks');
specialist_same($resolved['targets'], [$b + ['funding'=>'system']], 'An explicit fallback is eligible only under the same live policy and funding.');
specialist_same($resolved['reason'], 'primary_unavailable_fallback_selected', 'A fallback selection reports why the primary is unavailable.');
$GLOBALS['specialist_disabled_providers'] = [];
foreach ([['enabled'=>false], ['daily_tokens'=>0], ['background_enabled'=>false]] as $rule) {
    vp3_ai_service_save_rule_v1($pdo, 'user', 1, 'initiative', $rule);
    specialist_same(vp3_specialist_model_resolve_v1($pdo, $owner, 11, 'tasks')['state'], 'blocked', 'Disabled service, exhausted configured budget and background denial block all targets.');
}
specialist_same(vp3_specialist_model_resolve_v1($pdo, $owner, 11, 'tasks', false)['state'], 'resolved', 'Background restrictions are not misreported as foreground permission.');
vp3_ai_service_save_rule_v1($pdo, 'user', 1, 'initiative', []);
vp3_ai_service_save_route_v1($pdo, 'initiative', null, null);
$resolved = vp3_specialist_model_resolve_v1($pdo, $owner, 11, 'tasks');
specialist_same([$resolved['state'], $resolved['reason']], ['blocked', 'service_route_unconfigured'], 'Removing a configured route never turns an explicit preference into inheritance.');
vp3_ai_service_save_route_v1($pdo, 'initiative', $a, $b);

$pdo->exec("INSERT INTO user_llm_preferences VALUES (1,'own_key','openai');
    INSERT INTO user_llm_credentials VALUES (1,'anthropic','owned-b','shared-model'), (2,'openai','foreign-a','shared-model');");
specialist_same(vp3_specialist_model_resolve_v1($pdo, $owner, 11, 'planning')['reason'], 'funding_changed', 'A saved selection cannot silently change who pays.');
specialist_rejects(RuntimeException::class, 403,
    fn()=>vp3_specialist_model_save_v1($pdo, $owner, 11, 'planning', $planning['revision'], $selection($a)), 'Another owner credential cannot fund this choice');
$planning = vp3_specialist_model_save_v1($pdo, $owner, 11, 'planning', $planning['revision'], $selection($b));
specialist_same($planning['targets'], [$b + ['funding'=>'own_key']], 'An explicit resave pins the current personal funding choice.');
specialist_same(str_contains(json_encode($planning), 'owned-b') || str_contains(json_encode($planning), 'private-fixture'), false, 'No credentials enter member state.');
$pdo->exec('ALTER TABLE user_llm_preferences RENAME TO unavailable_funding');
specialist_same(vp3_specialist_model_resolve_v1($pdo, $owner, 11, 'planning')['state'], 'blocked', 'Missing funding storage cannot convert a personal-funded selection to the legacy system default.');
$pdo->exec('ALTER TABLE unavailable_funding RENAME TO user_llm_preferences');
$pdo->exec("DELETE FROM user_llm_credentials WHERE user_id=1");
specialist_same(vp3_specialist_model_resolve_v1($pdo, $owner, 11, 'planning')['state'], 'blocked', 'Credential removal blocks the saved model.');
$pdo->exec("UPDATE user_llm_preferences SET route='system',provider='' WHERE user_id=1");
specialist_same(vp3_specialist_model_resolve_v1($pdo, $owner, 11, 'planning')['reason'], 'funding_changed', 'Personal funding never silently reverts to subscription usage.');
$planning = vp3_specialist_model_save_v1($pdo, $owner, 11, 'planning', $planning['revision'], $selection($b));
$pdo->exec('ALTER TABLE user_llm_preferences RENAME TO unavailable_funding');
specialist_same(vp3_specialist_model_resolve_v1($pdo, $owner, 11, 'planning')['state'], 'blocked', 'Missing funding storage cannot authorize a system-funded saved selection.');
$pdo->exec('ALTER TABLE unavailable_funding RENAME TO user_llm_preferences');
$pdo->exec('UPDATE users SET main_ai_access=0 WHERE id=1');
specialist_same(vp3_specialist_model_resolve_v1($pdo, $owner, 11, 'planning')['reason'], 'account_not_entitled', 'Current entitlement is checked even when the supplied snapshot claims access.');
$pdo->exec('UPDATE users SET main_ai_access=1,chat_access=0 WHERE id=1');
specialist_rejects(RuntimeException::class, 403,
    fn()=>vp3_specialist_model_resolve_v1($pdo, $owner, 11, 'planning'), 'A revoked Chat permission invalidates resolution');
$pdo->exec('UPDATE users SET chat_access=1 WHERE id=1');

$old = $planning['revision'];
$planning = vp3_specialist_model_save_v1($pdo, $owner, 11, 'planning', $old, $selection($a));
specialist_rejects(RuntimeException::class, 409,
    fn()=>vp3_specialist_model_save_v1($pdo, $owner, 11, 'planning', $old, $selection($b)), 'A stale tab cannot overwrite a newer selection');
specialist_same(vp3_specialist_model_resolve_v1($pdo, $owner, 11, 'planning')['selection'], $selection($a), 'The winning preference remains intact.');
$pdo->beginTransaction();
$beforeChanges = $pdo->query('SELECT total_changes()')->fetchColumn();
$beforeSchema = $pdo->query('PRAGMA schema_version')->fetchColumn();
$resolved = vp3_specialist_model_resolve_v1($pdo, $owner, 11, 'planning');
specialist_same($resolved['revision'], $planning['revision'], 'Admission can reread the exact preference revision.');
specialist_same($pdo->query('SELECT total_changes()')->fetchColumn(), $beforeChanges, 'Resolution performs no writes.');
specialist_same($pdo->query('PRAGMA schema_version')->fetchColumn(), $beforeSchema, 'Resolution performs no schema changes.');
specialist_same($pdo->inTransaction(), true, 'Resolution preserves the caller transaction.');
specialist_rejects(LogicException::class, null,
    fn()=>vp3_specialist_model_save_v1($pdo, $owner, 11, 'planning', $planning['revision'], null), 'Save does not take over a caller transaction');
specialist_same($pdo->inTransaction(), true, 'Rejected nested save does not roll back the caller.');
$pdo->rollBack();

$key = vp3_specialist_model_key_v1(1, 11, 'planning');
$getRaw = static function () use ($pdo, $key): string {
    $q = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=?'); $q->execute([$key]); return $q->fetchColumn();
};
$setRaw = static fn(string $raw) => $pdo->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([$raw, $key]);
$original = $getRaw();
$originalRecord = json_decode($original, true, 12, JSON_THROW_ON_ERROR);
foreach (['{broken', json_encode(array_replace($originalRecord, ['owner_user_id'=>2])),
    json_encode(array_replace($originalRecord, ['agent_id'=>0])), json_encode(array_replace($originalRecord, ['specialist'=>'tasks'])),
    json_encode(array_replace($originalRecord, ['version'=>'unsupported'])), json_encode(array_replace($originalRecord, ['funding'=>null]))] as $broken) {
    $setRaw($broken);
    specialist_rejects(RuntimeException::class, 503,
        fn()=>vp3_specialist_model_resolve_v1($pdo, $owner, 11, 'planning'), 'Corrupt or foreign stored configuration cannot become inheritance');
}
$setRaw($original);
$pdo->exec('ALTER TABLE settings RENAME COLUMN setting_value TO unavailable_value');
specialist_rejects(RuntimeException::class, 503,
    fn()=>vp3_specialist_model_resolve_v1($pdo, $owner, 11, 'planning'), 'Unreadable settings schema fails closed');
$pdo->exec('ALTER TABLE settings RENAME COLUMN unavailable_value TO setting_value');
$pdo->exec("CREATE TRIGGER fail_specialist_save BEFORE UPDATE ON settings
    WHEN NEW.setting_key LIKE 'vp3_specialist_model_v1_%' BEGIN SELECT RAISE(ABORT, 'fixture write failure'); END;");
specialist_rejects(PDOException::class, null,
    fn()=>vp3_specialist_model_save_v1($pdo, $owner, 11, 'planning', $planning['revision'], null), 'Failed storage cannot partially clear a selection');
specialist_same($pdo->inTransaction(), false, 'Failed write rolls back its own transaction.');
specialist_same($getRaw(), $original, 'Failed write preserves the original record and revision.');
$pdo->exec('DROP TRIGGER fail_specialist_save');
$planning = vp3_specialist_model_save_v1($pdo, $owner, 11, 'planning', $planning['revision'], null);
specialist_same([$planning['state'], $planning['selection'], $planning['funding']], ['inherit', null, null], 'Explicit reset removes the model/funding pin.');
specialist_same($planning['revision'] === $old, false, 'Reset does not reuse a stale revision.');
$pdo->exec('DROP TABLE settings');
specialist_rejects(RuntimeException::class, 503,
    fn()=>vp3_specialist_model_resolve_v1($pdo, $owner, 11, 'planning'), 'Missing storage does not restore inherited authorization');

echo 'Specialist model foundation: '.$checks." ownership, policy, funding, revision and recovery checks passed.\n";
