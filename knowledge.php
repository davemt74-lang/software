<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (is_logged_in() && has_permission('chat.access')) {
    redirect(url('/chat.php'));
}

redirect(url('/index.php'));
