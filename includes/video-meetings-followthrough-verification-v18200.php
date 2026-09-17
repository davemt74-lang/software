<?php
declare(strict_types=1);

/**
 * VP3 Phase 18.20 — Follow-Through Verification & Closure.
 *
 * Separates canonical action completion from verification that the intended
 * meeting outcome was actually achieved. Verification criteria come from the
 * organizer-owned follow-through plan / immutable Plan-to-Action handoff.
 * Canonical evidence is advisory; closure requires explicit organizer review.
 */
const VP3_VIDEO_MEETINGS_FOLLOWTHROUGH_VERIFICATION_V18200='video-meetings-followthrough-verification-v18200-20260916';
const VP3_VIDEO_MEETINGS_VERIFICATION_WINDOW_V18200=120;

require_once __DIR__.'/video-meetings-plan-action-handoff-v18190.php';
require_once __DIR__.'/video-meetings-followthrough-intelligence-v18160.php';
require_once __DIR__.'/video-meetings-followthrough-verification-v18200-part1.php';
require_once __DIR__.'/video-meetings-followthrough-verification-v18200-part2.php';
