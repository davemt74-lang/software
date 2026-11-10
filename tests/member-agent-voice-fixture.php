<?php
declare(strict_types=1);
function current_user(): array { return ['id'=>1,'display_name'=>'Dave']; }
function db(): ?PDO { return new PDO('sqlite::memory:'); }
function chat_settings_agent_voice_enabled_v237(...$args): bool { return true; }
function chat_settings_agent_auto_greet_allowed_v237(...$args): bool { return true; }
function chat_settings_agent_auto_greet_schema_ready_v237(...$args): bool { return true; }
function chat_settings_agent_auto_greet_enabled_v237(...$args): bool { return false; }
function has_permission(...$args): bool { return true; }
function user_has_role(...$args): bool { return true; }
function user_role_labels(...$args): array { return ['Admin']; }
function user_avatar_url(...$args): string { return ''; }
function user_initials(...$args): string { return 'DE'; }
function url(string $path): string { return $path; }
function csrf_token(): string { return 'test'; }
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
require dirname(__DIR__).'/includes/member-navigation.php';
if (($argv[1] ?? '') === 'main-chat') {
    // Execute the shipped Chat header, including whichever account component it
    // actually renders. A shared-menu-only fixture missed the legacy Chat path.
    $user = current_user();
    $chatCreateItems = $chatNotifications = [];
    $chatCanAccessAccount = true;
    $chatNotificationCount = 0;
    $template = file_get_contents(dirname(__DIR__).'/chat-legacy-v108.php');
    $start = strpos($template, '<header class="chat-topbar">');
    $end = strpos($template, '<section class="chat-thread" id="chatThread">', $start);
    if ($start === false || $end === false) throw new RuntimeException('Chat header was not found.');
    $header = substr($template, $start, $end - $start);
    eval('?>'.str_replace('__DIR__', var_export(dirname(__DIR__), true), $header));
} else {
    require dirname(__DIR__).'/includes/member-user-menu.php';
}
