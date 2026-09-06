<?php
declare(strict_types=1);

/**
 * Canonical VP3 subscription runtime entry point.
 *
 * Keep the public function surface stable while separating storage/bootstrap,
 * package access/assignment, and AI quota accounting into focused modules.
 */
require_once __DIR__ . '/subscription-schema.php';
require_once __DIR__ . '/subscription-access.php';
require_once __DIR__ . '/subscription-quota.php';
