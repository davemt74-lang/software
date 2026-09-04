<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (!is_logged_in()) {
    redirect(url('/login.php'));
}

$user = current_user();
if ($user && has_permission('knowledge.manage', $user)) {
    redirect(url('/admin/knowledge.php'));
}
if ($user && has_permission('chat.access', $user)) {
    redirect(url('/chat.php'));
}
redirect(url('/account.php'));
