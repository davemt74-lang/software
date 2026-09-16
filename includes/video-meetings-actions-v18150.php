<?php
declare(strict_types=1);

/**
 * VP3 Phase 18.15 — Meeting Action Execution.
 *
 * Converts organizer-approved Phase 18.14 agenda follow-ups into durable,
 * explicitly-approved domain actions. Canonical VP3 Task, Calendar, CRM and
 * email primitives remain authoritative; no parallel executor is introduced.
 */
const VP3_VIDEO_MEETINGS_ACTIONS_V18150='video-meetings-actions-v18150-20260916';
const VP3_VIDEO_MEETINGS_ACTION_STATUS_V18150=['needs_review','approved','executing','executed','failed','completed'];

require_once __DIR__.'/video-meetings-agenda-v18140.php';
require_once __DIR__.'/agent-work-control-v173.php';
require_once __DIR__.'/user-calendar-v1300.php';
require_once __DIR__.'/crm-v180.php';
require_once __DIR__.'/agent-appointment-lifecycle-v700.php';
require_once __DIR__.'/video-meetings-actions-v18150-part1.php';
require_once __DIR__.'/video-meetings-actions-v18150-part2.php';
require_once __DIR__.'/video-meetings-actions-v18150-part3.php';
