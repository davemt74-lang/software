<?php
declare(strict_types=1);

/**
 * Canonical VP3 subscription runtime entry point.
 *
 * Keep the public function surface stable while separating storage/bootstrap,
 * package access/assignment, AI quota accounting, and customer plan management
 * into focused modules.
 */
require_once __DIR__ . '/subscription-schema.php';
require_once __DIR__ . '/subscription-access.php';
require_once __DIR__ . '/subscription-quota.php';
require_once __DIR__ . '/subscription-self-service-schema.php';
require_once __DIR__ . '/subscription-self-service.php';
