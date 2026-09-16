<?php
declare(strict_types=1);

/**
 * VP3 Phase 18.16 — Meeting Follow-Through Intelligence.
 *
 * Monitors the durable Phase 18.15 execution ledger and canonical VP3 results.
 * It does not create or execute external side effects. Completion confirmation
 * is delegated back to the canonical Phase 18.15 completion path.
 */
const VP3_VIDEO_MEETINGS_FOLLOWTHROUGH_INTELLIGENCE_V18160='video-meetings-followthrough-intelligence-v18160-20260916';
const VP3_VIDEO_MEETINGS_FOLLOWTHROUGH_WINDOW_V18160=80;

require_once __DIR__.'/video-meetings-actions-v18150.php';
require_once __DIR__.'/video-meetings-followthrough-v18100.php';
require_once __DIR__.'/video-meetings-followthrough-intelligence-v18160-part1.php';
require_once __DIR__.'/video-meetings-followthrough-intelligence-v18160-part2.php';
