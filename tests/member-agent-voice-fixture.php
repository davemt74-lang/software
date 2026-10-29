<?php
declare(strict_types=1);
function current_user(): array { return ['id'=>1,'display_name'=>'Dave']; }
function db(): ?PDO { return new PDO('sqlite::memory:'); }
function chat_settings_agent_voice_enabled_v237(...$args): bool { return true; }
function has_permission(...$args): bool { return true; }
function user_has_role(...$args): bool { return true; }
function user_role_labels(...$args): array { return ['Admin']; }
function user_avatar_url(...$args): string { return ''; }
function user_initials(...$args): string { return 'DE'; }
function url(string $path): string { return $path; }
function csrf_token(): string { return 'test'; }
function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
require dirname(__DIR__).'/includes/member-navigation.php';
require dirname(__DIR__).'/includes/member-user-menu.php';
