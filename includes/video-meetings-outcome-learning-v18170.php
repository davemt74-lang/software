<?php
declare(strict_types=1);

/**
 * VP3 Phase 18.17 — Meeting Outcome Learning & Adaptive Prep.
 *
 * Learns only from organizer-owned Phase 18.16 outcome states. Patterns are
 * aggregate and non-personal: action kind + agenda source + priority. No
 * participant scoring, participant email, transcript, private notes, or
 * HomeServer history are used. Learning is advisory and never executes actions.
 */
const VP3_VIDEO_MEETINGS_OUTCOME_LEARNING_V18170='video-meetings-outcome-learning-v18170-20260916';
const VP3_VIDEO_MEETINGS_OUTCOME_MIN_EVIDENCE_V18170=3;

require_once __DIR__.'/video-meetings-followthrough-intelligence-v18160.php';
require_once __DIR__.'/video-meetings-outcome-learning-v18170-part1.php';
require_once __DIR__.'/video-meetings-outcome-learning-v18170-part2.php';
