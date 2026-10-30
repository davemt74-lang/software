# Voice and Chat reminders — v1.354

Reminders are managed entirely through the existing personal Agent Chat and voice runtime. There is no separate setup form or reminder dashboard.

Examples:

- “Remind me to stretch tomorrow at 8 AM.”
- “Remind me to do yoga every weekday at 7 AM.”
- “What reminders do I have?”
- “Move reminder #2 to tomorrow at 10 AM.”
- “Snooze that reminder for ten minutes.”
- “That’s done.” / “Dismiss it.”
- “Cancel the yoga reminder.” / “Delete reminder #2.”

The configured LLM interprets language using the current UTC time, browser timezone, owner reminder records, current occurrences, and any unfinished clarification. A missing time or ambiguous target prompts a question. The server validates the selected operation, owned ID, current revision, schedule, timezone and recurrence. A success reply comes from the committed record. Arbitrary model text, screen text and reminder titles cannot execute commands.

Supported repeats: once, daily, weekdays, weekly. Supported delivery: Chat, notification, both (default). Spoken announcements use the existing Agent Voice setting, selected voice and temporary response-listening window. No new microphone owner is introduced. Eyes need not be on. Done and Dismiss affect the current occurrence; Cancel stops the series. Snoozing does not move the regular repeating schedule.

## Scheduling and delivery

A reminder and its occurrences have one Cloud owner ledger. Existing `notifications` and `chat_messages` carry deliveries. The originating conversation receives the Chat message if it still exists. The current visible page receives an orb bubble and, when Agent Voice is on and the agent is idle, a spoken question. A response can use the active conversation's delivered reminder reference. Personal reminders do not enter the separate proactive notification classifier, preventing a second presentation.

The browser checks every 15 seconds while a VP3 page is visible and the user is not typing/recording/waiting for an answer. The API runs due deliveries and atomically leases presentation to one tab. Cancel, reschedule and snooze invalidate old presentation leases. Stop and page unload fence late client results. Voice is best effort subject to browser autoplay/permission rules; committed Chat/notification records remain available.

For server-side materialization while no browser is open, run the CLI entry point every minute through the host's existing cron facility:

`php /absolute/path/to/VP3/cron/agent-reminders-v1.php`

No browser-closed push, SMS, email, or continuous audio alarm is promised. Without cron, the next visible VP3 page materializes overdue reminders. Repeating reminders coalesce missed intervals into one catch-up occurrence and then advance to the next future local time. A second scheduler/tab cannot duplicate that occurrence. Daily/weekly schedules follow local wall-clock time through DST, skip nonexistent times, and fire once for a folded local time.

## Deployment

Upload the complete Cloud package, run `upgrade.php`, and hard refresh. The upgrade installs four owner-scoped reminder tables and due indexes. Existing account deletion cascades this reminder state. Deleting a reminder removes its schedule, occurrences and linked notifications; ordinary conversation history and idempotency receipts remain historical records.

HomeServer source is staged locally only. Its owner Chat and voice can use the same paired Cloud ledger, subject to Cloud scope, current pairing and read-only restrictions. No second HomeServer reminder scheduler is added. Private/read-only/third-party conversations cannot export requests to the paired reminder service. No HomeServer publication or repository workflows are authorized for this build.

## Acceptance

1. With Eyes off, type “Remind me to stretch in two minutes.” Verify the saved time and timezone.
2. Ask “What reminders do I have?” and verify the same saved record.
3. Leave the page visible and idle. At the due time, verify one Chat/notification delivery and one spoken question if Agent Voice is on.
4. Say “Snooze that reminder for one minute.” Verify another delivery after one minute; then say “That’s done.”
5. Create a weekday reminder, complete today's occurrence, and verify its next delivery remains scheduled.
6. Open two VP3 tabs: only one claims the spoken presentation. With Agent Voice off, the reminder still appears silently.
7. Cancel or delete through Chat; refresh and verify the change persists.

Automated validation covers SQLite schedules/lifecycle/ownership, HTTP authentication/CSRF/owner boundaries, Chromium presentation lifecycle, actual typed/spoken canonical Chat transport, and real MySQL schema plus parallel scheduler/presentation workers. Provider output is fixture-controlled; live provider interpretation and physical microphone playback still require deployment testing.
