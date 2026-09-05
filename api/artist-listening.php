<?php
declare(strict_types=1);

/**
 * Canonical Artist Listening API route.
 *
 * Retained recording metadata has always emitted /api/artist-listening.php.
 * The recovered repository retained the versioned v172 implementation but lost
 * this canonical entrypoint, leaving otherwise valid audio cards pointed at a
 * 404. Keep one route owner and let the versioned implementation handle auth,
 * upload, transcript actions, byte ranges, and private recording delivery.
 */
require __DIR__ . '/artist-listening-v172.php';
