<?php
declare(strict_types=1);

// Execute the production upgrade page with isolated migration dependencies.
// Real PDO/SQLite and the production TV schema exercise the connection and readiness path.
if (!isset($argv[1])) {
    foreach (['post', 'already-ready', 'missing-db', 'csrf', 'get', 'state-only', 'records-only'] as $mode) {
        $command = escapeshellarg(PHP_BINARY).(php_ini_loaded_file() ? ' -c '.escapeshellarg(php_ini_loaded_file()) : '').' '.escapeshellarg(__FILE__).' '.escapeshellarg($mode);
        passthru($command, $status);
        if ($status !== 0) exit($status);
    }
    echo "UPGRADE_DATABASE_CONNECTION=PASS (7 cases)\n";
    exit;
}

$mode = $argv[1];
$fixture = new PDO('sqlite::memory:');
$fixture->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$fixture->exec("CREATE TABLE existing_content (value TEXT); INSERT INTO existing_content VALUES ('preserve me')");
require dirname(__DIR__).'/includes/tv-watching-v1.php';
if ($mode === 'already-ready') tv_schema_v1($fixture);
if ($mode === 'state-only') $fixture->exec('CREATE TABLE tv_watching_state_v1 (owner_user_id BIGINT PRIMARY KEY)');
if ($mode === 'records-only') $fixture->exec('CREATE TABLE tv_watching_records_v1 (owner_user_id BIGINT)');
$calls = []; $dbCalls = 0; $flashes = []; $redirects = [];
function db(): ?PDO { $GLOBALS['dbCalls']++; return $GLOBALS['mode'] === 'missing-db' ? null : $GLOBALS['fixture']; }
function setting(...$args): string { return '1'; }
function table_exists(string $name): bool {
    if (!in_array($name, ['tv_watching_state_v1', 'tv_watching_records_v1'], true)) return true;
    $q = $GLOBALS['fixture']->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
    $q->execute([$name]); return (bool)$q->fetchColumn();
}
function verify_csrf(): bool { return $GLOBALS['mode'] !== 'csrf'; }
function require_permission(string $permission): void { if ($permission !== 'users.manage') throw new RuntimeException('Upgrade permission changed'); }
function flash(...$args): void { $GLOBALS['flashes'][] = $args; }
function redirect(string $url): void { $GLOBALS['redirects'][] = $url; }
function url(string $path): string { return $path; }
function e(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function csrf_field(): string { return ''; }
function migration_dependency(string $name, array $args): bool {
    if (str_contains($name, 'schema_ready') || str_ends_with($name, '_ready') || $name === 'column_exists' || $name === 'personal_capability_seeded_v242') return true;
    if (str_contains($name, 'schema') || str_contains($name, 'seed') || str_contains($name, 'migrate') || $name === 'ai_haiku55_upgrade_defaults') $GLOBALS['calls'][] = $name;
    return true;
}

$source = file_get_contents(getenv('VP3_UPGRADE_SOURCE') ?: dirname(__DIR__).'/upgrade.php');
// Replace includes only; keep the real permission, CSRF, migration order, error handling and rendering.
$source = preg_replace('/^\s*require(?:_once)?\s+[^;]+;\R/m', "\n", $source);
$tokens = token_get_all($source); $functions = [];
foreach ($tokens as $i => $token) {
    if (!is_array($token) || $token[0] !== T_STRING) continue;
    $next = $i + 1; while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) $next++;
    $previous = $i - 1; while ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) $previous--;
    if (($tokens[$next] ?? null) !== '(' || (is_array($tokens[$previous] ?? null) && in_array($tokens[$previous][0], [T_FUNCTION, T_NEW, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true))) continue;
    $name = $token[1];
    if ($name === 'vp3_upgrade_complete' || function_exists($name) || class_exists($name)) continue;
    $functions[$name] = in_array($name, ['knowledge_r6_ensure_schema', 'knowledge_r7_ensure_schema', 'knowledge_r8_ensure_schema', 'workspace_native_schema_v1'], true);
}
foreach ($functions as $name => $typed) {
    if ($typed) eval('function '.$name.'(PDO $connection, ...$args) { if ($connection !== $GLOBALS["fixture"]) throw new RuntimeException("Wrong upgrade connection"); return migration_dependency(__FUNCTION__, [$connection, ...$args]); }');
    else eval('function '.$name.'(...$args) { return migration_dependency(__FUNCTION__, $args); }');
}

$_SERVER['REQUEST_METHOD'] = in_array($mode, ['get', 'state-only', 'records-only'], true) ? 'GET' : 'POST';
// Reproduce the reported deployment: bootstrap does not supply a page-local PDO.
$pdo = null;
ob_start(); eval(preg_replace('/^<\?php\s*/', '', $source)); $html = ob_get_clean();
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
if (in_array($mode, ['post', 'already-ready'], true)) {
    check($error === '', 'Upgrade POST failed: '.$error);
    check($dbCalls === 1 && $pdo === $fixture, 'Upgrade must acquire its connection before the first migration');
    foreach (['social_feed_schema_v1', 'knowledge_r6_ensure_schema', 'knowledge_r7_ensure_schema', 'knowledge_r8_ensure_schema', 'workspace_native_schema_v1', 'artist_music_v185_ensure_schema'] as $name) check(in_array($name, $calls, true), 'Upgrade did not reach '.$name);
    check(table_exists('tv_watching_state_v1') && table_exists('tv_watching_records_v1') && $complete, 'Upgrade skipped TV storage');
    check(count($flashes) === 1 && $redirects === ['/admin/users.php'], 'Successful upgrade did not finish normally');
} elseif ($mode === 'missing-db') {
    check($error === 'Database connection is unavailable.' && $calls === [], 'Unavailable database must stop before all migrations');
    check(str_contains($html, $error) && $flashes === [] && $redirects === [], 'Database failure was hidden or marked complete');
} elseif ($mode === 'csrf') {
    check($error === 'Session expired. Please try again.' && $dbCalls === 0 && $calls === [], 'CSRF rejection allowed upgrade work');
} else {
    check(!$complete && $dbCalls === 0 && $calls === [], 'Missing TV storage was marked ready or GET performed migrations');
    check(str_contains($html, 'Run Upgrade'), 'Missing TV storage must expose the upgrade control');
}
check($fixture->query('SELECT value FROM existing_content')->fetchColumn() === 'preserve me', 'Upgrade changed existing content');
echo 'PASS '.$mode."\n";
