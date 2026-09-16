<?php
declare(strict_types=1);

/**
 * VP3 Phase 18.10 — Post-Meeting Follow-Through.
 *
 * This is an orchestration/audit layer only. Canonical Tasks, Agent Brain,
 * Calendar, CRM, Scheduling follow-ups and Phase 14 workflows remain the
 * systems of record.
 */
const VP3_VIDEO_MEETING_FOLLOWTHROUGH_V18100='video-meeting-followthrough-v18100-20260915';
const VP3_VIDEO_MEETING_FOLLOWTHROUGH_APP_V18100='meeting_followthrough_v18100';
const VP3_VIDEO_MEETING_FOLLOWTHROUGH_ARTIFACT_V18100='post_meeting_action_queue';

require_once __DIR__.'/video-meetings-intelligence-handoff-v1890.php';
require_once __DIR__.'/user-calendar-v1300.php';
require_once __DIR__.'/crm-v180.php';
require_once __DIR__.'/user-agent-system-v236.php';
require_once __DIR__.'/agent-appointment-lifecycle-v700.php';
require_once __DIR__.'/transcription-intelligence-actions.php';
require_once __DIR__.'/agent-meeting-workflows-v1410.php';

require_once __DIR__.'/video-meetings-followthrough-v18100-part1.php';
require_once __DIR__.'/video-meetings-followthrough-v18100-part2.php';
require_once __DIR__.'/video-meetings-followthrough-v18100-part3.php';
require_once __DIR__.'/video-meetings-followthrough-v18100-part4.php';
require_once __DIR__.'/video-meetings-followthrough-v18100-part5.php';
require_once __DIR__.'/video-meetings-followthrough-v18100-part6.php';
