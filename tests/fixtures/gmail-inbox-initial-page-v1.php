<?php
declare(strict_types=1);

// Render the shipped Connections page with an isolated account and review view.
// The browser fixture supplies the separate review API responses.
function db(): PDO { return $GLOBALS['fixtureDb']; }
function current_user(): array { return ['id'=>1,'display_name'=>'Fixture owner']; }
function require_permission(string $permission): void {}
function has_permission(string $permission, array $user): bool { return $user['id'] === 1; }
function system_agent_name(): string { return 'Longfellow'; }
function e(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function url(string $path): string { return $path; }
function csrf_token(): string { return 'fixture'; }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="fixture">'; }

function agent_connections_summary_v1(int $owner, string $provider): array
{
    return ['schema_ready'=>true,'provider_ready'=>true,'connection'=>[
        'id'=>9,'status'=>'connected','account_email'=>'owner@example.test',
        'revision'=>hash('sha256', str_repeat('a',64)),
        'permissions'=>['read'=>true,'send'=>true,'followups'=>false],
    ]];
}

function agent_connection_gmail_inbox_storage_ready_v1(PDO $pdo): bool { return true; }
function agent_connection_gmail_inbox_authority_v1(array $user, array $connection): bool { return true; }
function agent_connection_gmail_inbox_policy_v1(PDO $pdo, int $owner): array
{
    return ['connection_id'=>9,'authorization_key'=>str_repeat('a',64),'revision'=>str_repeat('b',32),
        'enabled'=>true,'rules'=>[],'last_status'=>'waiting'];
}

function agent_connection_gmail_inbox_initial_view_v1(PDO $pdo, array $user, bool $includeReport = false, ?string $expectedRevision = null): array
{
    if ($includeReport && $expectedRevision !== str_repeat('b',32)) {
        throw new RuntimeException('Connections must bind the report view to the rendered Inbox review settings.');
    }
    $review = $GLOBALS['fixtureReview'];
    if (!$includeReport) unset($review['report'], $review['start_token']);
    return $review;
}

function agent_connection_youtube_ready_v1(): bool { return false; }
function agent_connection_youtube_consent_ready_v1(PDO $pdo): bool { return false; }
function vp3_youtube_playback_ready_v1(PDO $pdo): bool { return false; }
function vp3_youtube_picks_ready_v1(PDO $pdo): bool { return false; }
function agent_connection_health_member_v1(PDO $pdo, int $owner, int $connection): ?array { return null; }
function agent_connection_health_platform_v1(PDO $pdo, string $provider): ?array { return null; }

$GLOBALS['fixtureDb'] = new PDO('sqlite::memory:');
$GLOBALS['fixtureReview'] = json_decode($argv[2], true, 16, JSON_THROW_ON_ERROR);
parse_str($argv[1] ?? '', $_GET);
$root = dirname(__DIR__,2);
if (($argv[1]??'') === '--chat-review-runtime') {
    // Keep the PHP-rendered Chat configuration in the browser test too.
    $chat = file_get_contents($root.'/chat.php');
    $start = strpos($chat, '$initiativeWorkbenchRuntime .= \'<script>window.VP3_GMAIL_INBOX_INITIAL_V1=');
    $end = $start === false ? false : strpos($chat, "\n}",$start);
    if ($start === false || $end === false) throw new RuntimeException('The Gmail Chat runtime is missing.');
    $initiativeWorkbenchRuntime = '';
    $user = current_user();
    $activeUserAgent = ['id'=>7];
    eval(str_replace('__DIR__',var_export($root,true),substr($chat,$start,$end-$start)));
    echo $initiativeWorkbenchRuntime;
    return;
}
$source = file_get_contents($root.'/connections.php');
$source = preg_replace('/^declare\(strict_types=1\);\R|^require(?:_once)? __DIR__ \. \'\/includes\/[^\']+\';\R/m', '', $source);
$source = str_replace(
    ["require __DIR__ . '/includes/main-sidebar.php';", "require __DIR__ . '/includes/member-header.php';", '__DIR__'],
    ["echo '<aside class=\"chat-sidebar\">VP3</aside>';", "echo '<header class=\"chat-topbar\">Connections</header>';", var_export($root, true)],
    $source
);
eval('?>'.$source);
