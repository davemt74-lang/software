<?php
declare(strict_types=1);

require_once __DIR__.'/../includes/ai-settings.php';
require_once __DIR__.'/../includes/agent-image-provider-v1.php';
require_once __DIR__.'/../includes/subscription-access.php';
require_once __DIR__.'/../includes/ai-service-policy-v1.php';

function subscription_schema_ready(?PDO $pdo = null): bool
{
    return true;
}

function policy_same(mixed $actual, mixed $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException($message.' Expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}

function policy_rejects(callable $action, string $message): void
{
    try {
        $action();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-11-09 12:00:00');
$pdo->exec("CREATE TABLE settings(setting_key TEXT PRIMARY KEY, setting_value TEXT);
    CREATE TABLE users(id INTEGER PRIMARY KEY);
    CREATE TABLE subscription_packages(id INTEGER PRIMARY KEY, slug TEXT, name TEXT, description TEXT,
        ai_tokens_monthly INT, trial_tokens INT, trial_days INT, is_trial INT, is_public INT);
    CREATE TABLE user_subscriptions(id INTEGER PRIMARY KEY, user_id INT, package_id INT, status TEXT,
        starts_at TEXT, ends_at TEXT);
    INSERT INTO users VALUES (1),(2),(3);
    INSERT INTO subscription_packages VALUES (10,'standard','Standard','',10000,0,0,0,1),
        (20,'premium','Premium','',20000,0,0,0,1);
    INSERT INTO user_subscriptions VALUES (1,1,10,'active','2026-11-01',NULL),
        (2,1,20,'active','2026-11-30',NULL),(3,2,20,'active','2026-11-01',NULL),
        (4,3,10,'active','2026-10-01','2026-11-08');");

$user = ['id' => 1, 'package_id' => 20];
$second = ['id' => 2];
$expired = ['id' => 3];
$service = 'conversation';
$primary = ['provider' => 'openai', 'model' => 'ft:private-conversation', 'funding' => 'system'];
$fallback = ['provider' => 'anthropic', 'model' => 'custom-conversation', 'funding' => 'own_key'];
$image = ['provider' => 'openai', 'model' => 'private-image', 'funding' => 'own_key'];
$legacy = ['provider' => 'openai', 'model' => 'gpt-5.4-mini', 'funding' => 'system'];

$policy = vp3_ai_service_policy_v1($pdo, $user, $service);
policy_same([$policy['configured'], $policy['enabled'], $policy['primary']], [false, true, null], 'No service setup preserves legacy routing.');
policy_same(vp3_ai_service_target_allowed_v1($policy, $legacy), true, 'Legacy policy accepts an existing text target.');
policy_same(vp3_ai_service_policy_v1($pdo, $user, 'unknown')['enabled'], false, 'Unknown services fail closed.');

policy_rejects(fn() => vp3_ai_service_save_route_v1($pdo, $service, $primary, null), 'Unregistered model accepted.');
policy_rejects(fn() => vp3_ai_service_save_model_v1($pdo, 'anthropic', 'unsupported-image', 'Image', ['image_generation']), 'Incompatible provider registered.');
policy_rejects(fn() => vp3_ai_service_save_model_v1($pdo, 'openai', "injected\nmodel", 'Invalid', [$service]), 'Invalid model ID registered.');
vp3_ai_service_save_model_v1($pdo, 'openai', $primary['model'], 'Private conversation', [$service, 'initiative']);
vp3_ai_service_save_model_v1($pdo, 'anthropic', $fallback['model'], 'Custom conversation', [$service]);
vp3_ai_service_save_model_v1($pdo, 'openai', $image['model'], 'Private image', ['image_generation']);
policy_same(count(vp3_ai_service_registered_models_v1($pdo)), 3, 'The inventory returns registered models.');
policy_same(vp3_ai_service_models_v1($pdo, 'image_generation')['openai'][$image['model']], 'Private image', 'Image models can coexist with Anthropic conversation models.');
policy_same(isset(vp3_ai_service_models_v1($pdo, $service)['openai'][$image['model']]), false, 'Image models do not leak into the text service.');
policy_rejects(fn() => vp3_ai_service_save_route_v1($pdo, $service, $image, null), 'Incompatible model routed to Conversation.');
policy_rejects(fn() => vp3_ai_service_save_route_v1($pdo, $service, null, $fallback), 'Fallback without primary accepted.');
policy_rejects(fn() => vp3_ai_service_save_route_v1($pdo, $service, $primary, $primary), 'Identical targets accepted.');
policy_rejects(fn() => vp3_ai_service_save_route_v1($pdo, $service, $primary + ['api_key' => 'secret'], null), 'A credential was accepted in a model route.');
vp3_ai_service_save_route_v1($pdo, $service, $primary, $fallback);
vp3_ai_service_save_route_v1($pdo, 'image_generation', $image, null);
policy_same(vp3_ai_service_read_route_v1($pdo, $service), compact('primary', 'fallback'), 'Route round trips with the exact provider, model and credential source.');
policy_same(vp3_ai_service_policy_v1($pdo, $user, 'image_generation')['primary'], $image, 'Personal funding is selectable for compatible image routes.');
policy_same(vp3_ai_service_target_allowed_v1(vp3_ai_service_policy_v1($pdo, $user, 'image_generation'), $fallback), false, 'Image policy excludes incompatible providers.');
policy_rejects(fn() => vp3_ai_service_remove_model_v1($pdo, 'anthropic', $fallback['model']), 'Referenced fallback was removed.');
policy_rejects(fn() => vp3_ai_service_save_model_v1($pdo, 'openai', $primary['model'], 'Still used', ['initiative']), 'Referenced model lost compatibility.');

$primaryId = $primary['provider'].':'.$primary['model'];
$fallbackId = $fallback['provider'].':'.$fallback['model'];
vp3_ai_service_save_rule_v1($pdo, 'site', 0, $service, [
    'enabled' => true, 'allowed_models' => [$primaryId, $fallbackId], 'allowed_funding' => ['system', 'own_key'],
    'monthly_tokens' => 8000, 'daily_tokens' => 2000, 'job_tokens' => 400, 'background_monthly_tokens' => 1000,
], 9);
vp3_ai_service_save_rule_v1($pdo, 'package', 10, $service, [
    'allowed_models' => [$fallbackId], 'allowed_funding' => ['own_key'], 'monthly_tokens' => 6000,
    'background_daily_tokens' => 100, 'job_tokens' => 250,
], 9);
vp3_ai_service_save_rule_v1($pdo, 'user', 1, $service, [
    'allowed_models' => [$primaryId, $fallbackId], 'allowed_funding' => ['system', 'own_key'],
    'monthly_tokens' => 9000, 'daily_tokens' => 1500, 'job_tokens' => 300, 'background_monthly_tokens' => 500,
], 9);
$policy = vp3_ai_service_policy_v1($pdo, $user, $service, true);
policy_same([$policy['configured'], $policy['enabled'], $policy['primary'], $policy['fallback']], [true, true, null, $fallback], 'Account rules cannot broaden the canonical active package model or credential source.');
policy_same([$policy['monthly_tokens'], $policy['daily_tokens'], $policy['job_tokens'], $policy['background_monthly_tokens'], $policy['background_daily_tokens']], [6000, 1500, 250, 500, 100], 'Effective caps take the most restrictive applicable value.');
policy_same(vp3_ai_service_target_allowed_v1($policy, $primary), false, 'The primary target remains disallowed.');
policy_same(vp3_ai_service_target_allowed_v1($policy, $fallback), true, 'The permitted fallback remains available.');
policy_same(vp3_ai_service_policy_v1($pdo, $second, $service)['monthly_tokens'], 8000, 'Other accounts do not inherit the account rule.');
policy_same(vp3_ai_service_policy_v1($pdo, $expired, $service)['monthly_tokens'], 8000, 'Expired subscriptions do not supply package rules.');
policy_same(vp3_ai_service_read_setting_v1($pdo, vp3_ai_service_rule_key_v1('user', 1, $service))['actor_id'], 9, 'Rule stores its administrative actor.');

vp3_ai_service_save_rule_v1($pdo, 'site', 0, 'initiative', ['background_enabled' => false]);
vp3_ai_service_save_rule_v1($pdo, 'user', 1, 'initiative', ['background_enabled' => true]);
policy_same(vp3_ai_service_policy_v1($pdo, $user, 'initiative', true)['reason'], 'background_disabled', 'A permissive account cannot override a background restriction.');
policy_same(vp3_ai_service_policy_v1($pdo, $user, 'initiative')['enabled'], true, 'Background restrictions leave foreground enabled.');
vp3_ai_service_save_rule_v1($pdo, 'site', 0, 'initiative', ['background_daily_tokens' => 0]);
policy_same(vp3_ai_service_policy_v1($pdo, $user, 'initiative', true)['reason'], 'service_budget_exhausted', 'Zero blocks the applicable background budget.');
policy_same(vp3_ai_service_policy_v1($pdo, $user, 'initiative')['enabled'], true, 'Zero background caps do not disable foreground usage.');
vp3_ai_service_save_rule_v1($pdo, 'site', 0, 'initiative', ['monthly_tokens' => 0]);
policy_same(vp3_ai_service_policy_v1($pdo, $user, 'initiative')['reason'], 'service_budget_exhausted', 'Zero foreground cap is a hard limit.');
vp3_ai_service_save_rule_v1($pdo, 'site', 0, 'initiative', ['allowed_funding' => []]);
policy_same(vp3_ai_service_policy_v1($pdo, $user, 'initiative')['reason'], 'service_model_forbidden', 'An empty allowlist denies legacy routes as well.');
vp3_ai_service_save_rule_v1($pdo, 'site', 0, $service, ['enabled' => false]);
vp3_ai_service_save_rule_v1($pdo, 'user', 1, $service, ['enabled' => true]);
$policy = vp3_ai_service_policy_v1($pdo, $user, $service);
policy_same([$policy['configured'], $policy['enabled'], $policy['reason']], [true, false, 'service_disabled'], 'An explicit route blocked by site policy is distinct from a legacy route.');
policy_rejects(fn() => vp3_ai_service_save_rule_v1($pdo, 'user', 999, $service, ['enabled' => true]), 'Missing account accepted.');
policy_rejects(fn() => vp3_ai_service_save_rule_v1($pdo, 'site', 0, $service, ['job_tokens' => '500']), 'String token cap accepted.');
policy_rejects(fn() => vp3_ai_service_save_rule_v1($pdo, 'site', 0, $service, ['job_tokens' => -1]), 'Negative token cap accepted.');

vp3_ai_service_save_rule_v1($pdo, 'site', 0, $service, []);
vp3_ai_service_save_route_v1($pdo, $service, null, null);
vp3_ai_service_remove_model_v1($pdo, 'anthropic', $fallback['model']);
policy_same(vp3_ai_service_policy_v1($pdo, $second, $service)['configured'], false, 'Clearing a route allows deliberate legacy routing.');
policy_same(isset(vp3_ai_service_models_v1($pdo, $service)['anthropic'][$fallback['model']]), false, 'Unused model removal is persisted.');
$config = vp3_ai_service_config_v1($pdo);
$config['routes'][$service] = ['primary' => $fallback, 'fallback' => null];
vp3_ai_service_write_setting_v1($pdo, VP3_AI_SERVICE_CONFIG_KEY_V1, $config);
$policy = vp3_ai_service_policy_v1($pdo, $second, $service);
policy_same([$policy['configured'], $policy['enabled'], $policy['reason']], [true, false, 'service_model_forbidden'], 'Unregistered stored routes fail closed.');
$config['routes'][$service] = null;
vp3_ai_service_write_setting_v1($pdo, VP3_AI_SERVICE_CONFIG_KEY_V1, $config);
$policy = vp3_ai_service_policy_v1($pdo, $second, $service);
policy_same([$policy['configured'], $policy['enabled'], $policy['reason']], [true, false, 'service_policy_unavailable'], 'Corrupt routes cannot re-enable legacy providers.');

echo "AI service policy tests passed\n";
