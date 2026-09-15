<?php
declare(strict_types=1);

/**
 * VP3 Appointment Lifecycle + Automation v7.00
 *
 * Adds lifecycle state, intake, reminders, Agent preparation, post-meeting
 * follow-up and in-place calendar-safe rescheduling on top of the canonical
 * v4.30 booking store, v5.00 provider sync and v6.00 Team scheduling.
 */
const VP3_AGENT_APPOINTMENT_LIFECYCLE_V700='agent-appointment-lifecycle-v700-20260911';

require_once __DIR__.'/agent-appointment-lifecycle-v700-part1.php';
require_once __DIR__.'/agent-appointment-lifecycle-v700-part2.php';
require_once __DIR__.'/agent-appointment-lifecycle-v700-part3.php';
require_once __DIR__.'/agent-appointment-lifecycle-v700-part4.php';
require_once __DIR__.'/agent-appointment-lifecycle-v700-part5.php';
require_once __DIR__.'/agent-appointment-lifecycle-v700-part6.php';
require_once __DIR__.'/agent-appointment-lifecycle-v700-part7.php';
require_once __DIR__.'/agent-appointment-lifecycle-v700-part8.php';
require_once __DIR__.'/agent-meeting-workflows-v1410.php';
require_once __DIR__.'/agent-meeting-workflows-v1410-hardening.php';
require_once __DIR__.'/video-meetings-v1800.php';
require_once __DIR__.'/video-meetings-security-v1800.php';
require_once __DIR__.'/video-meetings-homeserver-v1801.php';
require_once __DIR__.'/video-meetings-calendar-v1801.php';
require_once __DIR__.'/video-meetings-agent-v1800.php';
require_once __DIR__.'/video-meetings-transcription-v1800.php';
require_once __DIR__.'/video-meetings-reconcile-v1800.php';

video_meeting_boot_v1800();