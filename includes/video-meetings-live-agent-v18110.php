<?php
declare(strict_types=1);

/**
 * VP3 Phase 18.11 — Live Meeting Agent.
 *
 * Adds an organizer-controlled live Agent session to the existing meeting
 * surface. The meeting Agent reuses canonical VP3 Agent runtime/inference,
 * Meeting Intelligence and the existing LiveKit media worker. It never creates
 * a second chat/task/CRM/calendar system and never executes external actions
 * directly from a live turn.
 */
const VP3_VIDEO_MEETING_LIVE_AGENT_V18110='video-meetings-live-agent-v18110-20260915';
const VP3_VIDEO_MEETING_LIVE_AGENT_APP_V18110='meeting_live_agent_v18110';
const VP3_VIDEO_MEETING_LIVE_AGENT_ARTIFACT_V18110='live_agent_session';

require_once __DIR__.'/video-meetings-intelligence-hybrid-v1890.php';
require_once __DIR__.'/video-meetings-agent-v1800.php';
require_once __DIR__.'/video-meetings-live-agent-v18110-part1.php';
require_once __DIR__.'/video-meetings-live-agent-v18110-part2.php';
require_once __DIR__.'/video-meetings-live-agent-v18110-part3.php';
require_once __DIR__.'/video-meetings-live-agent-v18110-part4.php';
