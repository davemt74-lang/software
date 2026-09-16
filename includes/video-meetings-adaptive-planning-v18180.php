<?php
declare(strict_types=1);

/**
 * VP3 Phase 18.18 — Adaptive Meeting Planning.
 *
 * Adds organizer-owned follow-through planning metadata to agenda items. Outcome
 * learning is advisory only: VP3 never auto-assigns an owner, changes agenda
 * priority/action type, or executes an external action from this layer.
 */
const VP3_VIDEO_MEETINGS_ADAPTIVE_PLANNING_V18180='video-meetings-adaptive-planning-v18180-20260916';
const VP3_VIDEO_MEETINGS_PLANNING_LIMIT_V18180=80;

require_once __DIR__.'/video-meetings-outcome-learning-v18170.php';
require_once __DIR__.'/video-meetings-adaptive-planning-v18180-part1.php';
require_once __DIR__.'/video-meetings-adaptive-planning-v18180-part2.php';
