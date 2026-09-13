<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string)file_get_contents($root . '/includes/shared-folders-v161.php');
$api = (string)file_get_contents($root . '/api/shared-folders-v161.php');
$page = (string)file_get_contents($root . '/knowledge.php');

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void { if (!$ok) $failures[] = $message; };

$check(substr_count($service, 'created_by_user_id') >= 6, 'Shared-folder CRUD is not consistently owner scoped.');
$check(str_contains($service, 'WHERE id=? AND created_by_user_id=?'), 'Folder lookup/delete lacks id + owner scoping.');
$check(str_contains($service, 'WHERE created_by_user_id=? FOR UPDATE'), 'Transcript unfiling does not lock only the current owner records.');
$check(str_contains($page, "created_by_user_id=? AND knowledge_scope='personal'"), 'Knowledge move/delete paths are not owner + personal-scope constrained.');
$check(str_contains($page, 'personal_knowledge_resolve_folder_id'), 'Knowledge moves do not validate destination folder ownership.');
$check(str_contains($api, 'hash_equals(csrf_token(), $csrf)'), 'Shared-folder create API does not require the current CSRF token.');
$check(!str_contains($service . $api . $page, 'native_path'), 'Cloud folder surfaces must not accept native HomeServer paths.');

if ($failures) {
    fwrite(STDERR, "Knowledge Library v16.1 security contract failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Knowledge Library v16.1 security contract: PASS\n";
