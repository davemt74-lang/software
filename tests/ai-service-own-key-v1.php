<?php
declare(strict_types=1);

define('STONEFELLOW_ROOT', sys_get_temp_dir().'/vp3-service-own-key-'.bin2hex(random_bytes(8)));
require __DIR__.'/../includes/ai-service-own-key-v1.php';
register_shutdown_function(static function (): void {
    @unlink(ai_master_key_file());
    @unlink(ai_master_key_file().'.lock');
    @rmdir(ai_private_dir());
    @rmdir(STONEFELLOW_ROOT);
});

function grant_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) throw new RuntimeException($message);
}

function grant_rejects(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE settings(setting_key TEXT PRIMARY KEY, setting_value TEXT);
    CREATE TABLE user_llm_credentials(user_id INTEGER,provider TEXT,encrypted_key TEXT,PRIMARY KEY(user_id,provider));
    CREATE TABLE user_llm_preferences(user_id INTEGER,route TEXT,provider TEXT);
    INSERT INTO user_llm_preferences VALUES(10,"system",""),(20,"own_key","openai");
    CREATE TABLE users(id INTEGER PRIMARY KEY);
    INSERT INTO users VALUES (10),(20)');
$first = ['id' => 10];
$second = ['id' => 20];
$provider = 'openai';
$model = 'service-test-model';
$key = 'fixture-personal-secret';
$replacement = 'fixture-replacement-secret';
$providerState = ['openai' => ['configured' => true]];
$target = ['provider' => $provider, 'model' => $model, 'funding' => 'own_key'];
vp3_ai_service_save_model_v1($pdo, $provider, $model, 'Service model', ['initiative', 'image_generation']);
vp3_ai_service_save_route_v1($pdo, 'initiative', $target, null);
vp3_ai_service_save_route_v1($pdo, 'image_generation', $target, null);
grant_rejects(fn() => vp3_ai_service_own_key_update_v1($pdo, $first, 'initiative', $provider, $model, true),
    'An owner without a credential approved a service.');
$save = $pdo->prepare('INSERT INTO user_llm_credentials VALUES(?,?,?)');
$save->execute([10, $provider, ai_encrypt_secret($key)]);
$save->execute([20, $provider, ai_encrypt_secret('fixture-other-secret')]);

grant_same(vp3_ai_service_own_key_v1($pdo, 20, 'initiative', $provider, $model), '', 'Chat BYOK does not authorize Initiative.');
vp3_ai_service_own_key_update_v1($pdo, $first, 'initiative', $provider, $model, true);
grant_same(vp3_ai_service_own_key_v1($pdo, 10, 'initiative', $provider, $model), $key, 'System-funded Chat can separately approve its personal key for Initiative.');
grant_same(vp3_ai_service_own_key_v1($pdo, 20, 'initiative', $provider, $model), '', 'A second account inherited the first approval.');
grant_same(vp3_ai_service_own_key_v1($pdo, 10, 'image_generation', $provider, $model), '', 'Approval applied to a different service.');
grant_same(vp3_ai_service_own_key_v1($pdo, 10, 'initiative', 'anthropic', $model), '', 'Approval applied to a different provider.');
grant_same(vp3_ai_service_own_key_v1($pdo, 10, 'initiative', $provider, 'other-model'), '', 'Approval applied to a different model.');
grant_rejects(fn() => vp3_ai_service_own_key_update_v1($pdo, $first, 'initiative', $provider, 'other-model', true),
    'An unavailable model received approval.');
vp3_ai_service_save_rule_v1($pdo, 'user', 20, 'initiative', ['background_enabled' => false], 10);
grant_rejects(fn() => vp3_ai_service_own_key_update_v1($pdo, $second, 'initiative', $provider, $model, true),
    'An account policy block was ignored.');

$public = vp3_ai_service_own_key_state_v1($pdo, $first, $providerState);
grant_same(array_column($public, 'approved', 'service'), ['initiative' => true, 'image_generation' => false],
    'The account UI did not expose per-service approval states.');
grant_same(array_keys($public[0]), ['service', 'label', 'provider', 'model', 'approved', 'can_approve'],
    'The account response exposed internal approval state.');

vp3_ai_service_save_route_v1($pdo, 'initiative', null, null);
$public = vp3_ai_service_own_key_state_v1($pdo, $first, $providerState);
grant_same([$public[0]['approved'], $public[0]['can_approve']], [true, false], 'An old approval could not be revoked after a route change.');
vp3_ai_service_own_key_update_v1($pdo, $first, 'initiative', $provider, $model, false);
grant_same(vp3_ai_service_own_key_v1($pdo, 10, 'initiative', $provider, $model), '', 'An explicit revocation did not take effect.');
vp3_ai_service_own_key_update_v1($pdo, $first, 'image_generation', $provider, $model, true);
$pdo->prepare('UPDATE user_llm_credentials SET encrypted_key=? WHERE user_id=10')->execute([ai_encrypt_secret($replacement)]);
grant_same(vp3_ai_service_own_key_v1($pdo, 10, 'image_generation', $provider, $model), '', 'A replacement credential inherited approval.');
vp3_ai_service_own_key_update_v1($pdo, $first, 'image_generation', $provider, $model, true);
grant_same(vp3_ai_service_own_key_v1($pdo, 10, 'image_generation', $provider, $model), $replacement, 'An owner could not reapprove a new key.');
$pdo->exec('DELETE FROM user_llm_credentials WHERE user_id=10');
grant_same(vp3_ai_service_own_key_v1($pdo, 10, 'image_generation', $provider, $model), '', 'A removed credential was still usable.');
echo "AI service personal key consent, policy, revision and owner isolation PASS\n";
