<?php
declare(strict_types=1);

/**
 * VP3 Phase 18.12 — Meeting Search & Memory.
 *
 * Search is intentionally derived from finalized, reviewed Meeting Intelligence.
 * Raw transcript text, organizer notes, participant email addresses and private
 * HomeServer material are never copied into this index.
 */
const VP3_VIDEO_MEETINGS_MEMORY_V18120='video-meetings-memory-v18120-20260916';
const VP3_VIDEO_MEETINGS_MEMORY_APP_V18120='meeting_memory_v18120';
const VP3_VIDEO_MEETINGS_MEMORY_ARTIFACT_V18120='meeting_memory_index';
const VP3_VIDEO_MEETINGS_MEMORY_SCAN_LIMIT_V18120=150;
const VP3_VIDEO_MEETINGS_MEMORY_RESULT_LIMIT_V18120=12;
const VP3_VIDEO_MEETINGS_MEMORY_CURSOR_LIMIT_V18120=96;

require_once __DIR__.'/video-meetings-v1800.php';
require_once __DIR__.'/video-meetings-intelligence-v1820.php';
require_once __DIR__.'/video-meetings-intelligence-hybrid-v1890.php';
require_once __DIR__.'/video-meetings-followthrough-v18100.php';
require_once __DIR__.'/video-meetings-memory-v18120-part1.php';
require_once __DIR__.'/video-meetings-memory-v18120-part2.php';
require_once __DIR__.'/video-meetings-memory-v18120-part3.php';
