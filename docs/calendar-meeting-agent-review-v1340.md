# Calendar, scheduling, meeting, and research review

Scope: `davemt74-lang/software` at #613 plus the calendar v13.40 changes, and `davemt74-lang/otro` at `b656e6556328e4fdd3015735c99c6d6503edc0b5` plus its matching workspace editor changes. This is a source and test review of these paths, not a claim that the live installation, provider credentials, meeting server, or Windows executable has been exercised.

## Findings

1. **Standalone personal-calendar chat management was incomplete.** `includes/user-calendar-agent-v1300.php` handled list/create/confirmation. Its explicit-intent matcher accepted remove/delete/cancel, but those operations had no implementation. Booking management in `includes/agent-scheduling-tools-v460.php` is a separate path and already supports create/reschedule/cancel. The v13.40 changes add owner-scoped personal-event updates and series cancellation with explicit confirmation, revision checks, and linked-meeting protection. Calendar events and appointment bookings remain separate canonical objects.

2. **HomeServer has existing scheduling tools and skills.** `app/services/vp3_scheduling_tools.py` defines `vp3.schedule.overview`, `vp3.schedule.availability`, and `vp3.booking.create/reschedule/cancel`, plus `vp3.calendar-review`, `vp3.find-time`, and `vp3.booking-management` skills. `app/services/vp3_scheduling_agent.py` installs model-facing adapters. These require the existing connector, current permissions, policy, and owner approval for writes. Their presence in source does not prove a particular installation is configured.

3. **HomeServer calendar writes already have a governed mission path; the basic Chat tool list is narrower.** `app/services/tools.py` and `task_calendar_continuity.py` have governed `calendar.create/update/delete`. `agent_mission_actions.py` exposes calendar creation and updates to assigned specialists through the existing approval engine; `agent_mission_chat_tasks.py` requires investigation reads before updates, and `agent_mission_chat_routes.py` prepares reviewed tasks from Chat. The base `app/services/agent_tools.py` separately exposes `homeserver_calendar_list` without dedicated native-calendar write adapters. This is not a missing calendar backend. The next check is an end-to-end ordinary Chat request through the existing task/mission or scheduling route, including current policy, authority, confirmation, and observable delivery. Calendar deletion is not in the specialist mission action allowlist; its existing governed approval path remains separate.

4. **Meeting capabilities already exist, but are spread across multiple surfaces.** Cloud has video-meeting creation/invitations, agenda items, notes/objectives, transcription, live-agent questions, intelligence, action preparation/approval/execution, meeting memory, cross-meeting commitments, and prep/follow-up workflows. Relevant entry points include `api/video-meeting-intelligence.php`, `api/video-meeting-agenda.php`, `api/video-meeting-actions.php`, `api/video-meeting-live-agent.php`, `includes/agent-meeting-workflows-v1410.php`, and `includes/video-meetings-memory-v18120*.php`. General Chat includes meeting memory, but these APIs are not equivalent to one complete model-callable meeting-management skill. Do not infer that every meeting UI action is callable from ordinary Chat.

5. **Transcript web research exists and is gated by workflow settings.** `includes/transcription-workflow-config.php` collects model-suggested research queries, checks `web_research`, and calls `artist_listening_v237_research()` only when the existing freshness gate is due. The Research workflow enables web research; standard workflows default it off. `includes/transcription-app-registry.php` gates repeated work using query hashes, topics, and transcript growth. This is analysis-triggered research, not a guaranteed immediate research task for every new person mention.

6. **Meeting attendees and mentioned names are not a complete contact-research pipeline.** `video_meeting_transcription_ensure_session_v1800()` records the meeting identity but does not populate CRM contact/lead associations from attendees. `transcription_app_crm_context_v301()` matches explicit stored contact IDs, lead IDs, or exact email addresses; a spoken name alone is intentionally not treated as proven identity. Existing CRM suggestions do not automatically create or change contacts. A missing bridge must resolve authorized attendee/contact context and distinguish ambiguous name mentions before public research; it must retain the source transcript segment and reviewed identity.

7. **Weekly calendar entries are not recurring video-meeting sessions.** The calendar recurrence projection can safely repeat personal time. The existing video-meeting lifecycle has its own identity, dates, invitations, and completion state. v13.40 prevents a weekly personal-calendar series from silently masquerading as repeated meeting invitations. Recurring video meetings need an explicit occurrence/session design before that combination is enabled.

8. **Pending confirmations needed coordination.** A prepared personal-calendar change could remain in its session namespace after a later booking draft was prepared. The v13.40 handler retires older booking drafts when preparing a calendar change and yields to a newer booking/team draft before interpreting a plain confirmation. Regression coverage verifies that confirming a newer booking cannot remove the earlier calendar event. A new explicit calendar change also retires an older draft before asking for missing details. Server-issued proposal IDs make creation retries safe after a lost acknowledgement.

9. **Confirmed timezone defect in conversational scheduling.** The shared parser used the server timezone to interpret weekday and named-month requests, then converted midnight into the account timezone. With a UTC server and a Phoenix account, “next Monday” became Sunday. The follow-up fix parses those dates in the account timezone and includes regression cases across server/account timezone combinations and invalid calendar dates. Explicit ISO dates and today/tomorrow retain their existing paths.

## Changes in the calendar section

- One time / Every week and Include agent notifications in the Cloud event editor.
- Existing events default to one time with priority notifications off. Upgrade adds columns without replacing stored events.
- Range-bounded weekly projections keep one canonical series; no duplicate rows, reminder jobs, or HomeServer-owned copies are created.
- Weekly occurrences retain the original local weekday/time across DST; a nonexistent spring wall time skips that week. All-day events retain local day boundaries.
- Calendar, agenda, Agent awareness, Brain priority candidates, booking conflicts, and federated reads use the recurrence projection.
- Occurrence-specific identities prevent one week's cooldown from suppressing another week. Cognitive cards resolve the selected occurrence rather than displaying the old series anchor.
- HomeServer's synced Cloud calendar editor uses matching controls and the existing revision-checked Cloud outbox. Cloud remains responsible for execution and notifications.
- Personal Calendar chat can create, move, change recurrence/priority, list, and remove events through existing canonical services. Linked video meetings stay in their meeting-aware editor.

## Validation and limits

Production-service tests cover persistence, upgrade compatibility, weekly and all-day DST, old series, overlap boundaries, distinct occurrence IDs, owner isolation, invalid options, exact retries, stopping a series, Brain priority candidates, and conversational prepare/confirm/update/cancel with stale revision rejection. HomeServer tests cover editable values, strict option validation, queueing, source identity, and prevention of duplicate native jobs. Browser acceptance checks the matching editor controls and their exact outgoing fields.

The full Recovery Baseline and GitHub checks are release gates. Provider HTTP, real microphone/camera capture, actual meeting transport, and a user installation's current configuration require live acceptance and are not inferred from passing source tests.

## Next implementation sections

1. Certify ordinary Chat creation and management through the existing calendar mission, scheduling skills, and approval paths. Repair missing dispatch or capability visibility where demonstrated; do not duplicate the implemented calendar backend. Align recurrence/priority fields where a native calendar action is used instead of a Cloud calendar replica.
2. Add a canonical meeting-management skill exposing the existing meeting operations with organizer/participant permissions and exact approved writes.
3. Add meeting-contact research continuity: authorized attendee resolution; transcript-segment references; explicit unresolved name candidates; opt-in automatic public research using existing research settings, durable deduplication, and update/invalidation after transcript correction. Publish sourced findings to the meeting and Agent Brain; never silently merge contacts by name.
4. Add recurring video-meeting sessions only after occurrence identity, invitations, edit scope, cancellation, and completed-session behavior are specified and tested.
