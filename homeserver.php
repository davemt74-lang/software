<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';
require_once __DIR__ . '/includes/vp3-marketing-pages.php';
redirect_logged_in_public_page();
vp3_render_marketing_page('homeserver');
