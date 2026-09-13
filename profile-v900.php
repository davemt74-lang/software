<?php
declare(strict_types=1);

/**
 * Public vanity profile route.
 *
 * Keep one canonical renderer. Profile Commerce, Scheduling and the relevance-
 * driven tab model are owned by profile.php so the public route cannot drift
 * into a second profile implementation.
 */
require __DIR__.'/profile.php';
