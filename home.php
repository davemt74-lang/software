<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_login();

$user = current_user();
if (!$user) redirect(url('/login.php'));

if (has_permission('chat.access', $user)) {
    $agent = trim((string)($_GET['agent'] ?? ''));
    $target = '/chat.php';
    if ($agent !== '' && (ctype_digit($agent) || strcasecmp($agent, 'system') === 0)) {
        $target .= '?agent=' . rawurlencode($agent);
    }
    redirect(url($target));
}

if (has_permission('account.access', $user)) redirect(url('/account.php'));
if (has_permission('admin.access', $user)) redirect(url('/admin/index.php'));
if (has_permission('investor.access', $user)) redirect(url('/investor.php'));
redirect(url('/index.php'));
