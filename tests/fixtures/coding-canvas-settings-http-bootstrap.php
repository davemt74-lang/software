<?php
declare(strict_types=1);

// The disposable member session, SQLite database and billing balance support
// the production controller, registry, model policy and credential storage.
define('STONEFELLOW_ROOT', dirname(__DIR__));
$source = getenv('VP3_CODING_SETTINGS_TEST_SOURCE');
require_once $source.'/includes/ai-settings.php';
require_once $source.'/includes/coding-canvas-model-v1.php';

function db(): PDO
{
    static $pdo;
    return $pdo ??= new PDO('sqlite:'.STONEFELLOW_ROOT.'/coding-model.sqlite');
}

function current_user(): ?array
{
    return isset($_SERVER['HTTP_X_FIXTURE_ANONYMOUS']) ? null
        : ['id'=>(int)($_SERVER['HTTP_X_FIXTURE_USER'] ?? 1), 'role'=>'member'];
}

function csrf_token(): string { return 'coding-settings-fixture'; }
function has_permission(string $permission, array $user): bool { return $permission === 'chat.access'; }
function subscription_has_entitlement(array $user, string $entitlement): bool { return $entitlement === 'main_ai.access'; }
function subscription_ai_balance(array $user, PDO $pdo): array { return ['unlimited'=>true]; }

function setting(string $key, mixed $fallback = null): mixed
{
    $statement = db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
    $statement->execute([$key]);
    $value = $statement->fetchColumn();
    return $value === false ? $fallback : $value;
}

if (!db()->query("SELECT 1 FROM sqlite_master WHERE name='users'")->fetchColumn()) {
    db()->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
        CREATE TABLE users (id INTEGER PRIMARY KEY); INSERT INTO users VALUES (1),(2);');
    $credential = ai_encrypt_secret('fixture-coding-system-credential');
    $save = db()->prepare('INSERT INTO settings (setting_key,setting_value) VALUES (?,?)');
    $save->execute(['ai_anthropic_enabled', '1']);
    $save->execute(['ai_anthropic_api_key', $credential]);
    vp3_ai_service_save_model_v1(db(), 'anthropic', '420', 'Custom fixture coder', VP3_AI_CODING_SERVICES_V1);
    vp3_coding_canvas_model_admin_save_v1(db(), ['anthropic:claude-haiku-5-5', 'anthropic:claude-sonnet-5'], 'anthropic:claude-sonnet-5');
}

if (isset($_SERVER['HTTP_X_FIXTURE_READ_ONLY'])) db()->exec('PRAGMA query_only=ON');
