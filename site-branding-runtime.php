<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

// VP3 is now the fixed product brand in the application/admin shells. Keep the
// persisted upload setting available for other brandable/public surfaces, but
// do not let an old uploaded Stonefellow logo override the canonical VP3 shell.
echo "/* VP3 application shell uses fixed text branding. */\n";
