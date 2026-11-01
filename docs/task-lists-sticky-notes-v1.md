# Tasks, named lists and sticky notes v1

An explicit task save persists through the canonical Agent Brain task service. Named lists hold task references in the same authenticated owner and Agent namespace. Existing task reads, status history and completion remain authoritative.

“Add pizza dough to my prep list” checks a case/whitespace normalized exact list name. An existing list receives the item. A missing list produces a frozen 15-minute proposal and asks before creating the list and item. Only the latest assistant's server-written pending reference in that owner/Agent/conversation can be confirmed. Tokens are never adopted from browser or model fields. Expired, cancelled, consumed, foreign and superseded proposals cannot execute. A model interprets language through the existing shared capability request, rather than a phrase-based task fallback. Actual task IDs may be attached without copying their contents.

List writes serialize on the owner row and save the canonical task, membership and consumed proposal in a single transaction. Normalized membership keys prevent duplicate adds. Reads do not create lists. Account, conversation, Brain access, Agent namespace and per-record policy are rechecked at execution. The new inventory advertises tasks.lists only when its storage and canonical Brain scope are ready. Existing reminders and transcripts retain their services.

“Show prep list” and “Show reminders” return note references. Typed, voice streaming and legacy responses share the same server adapter. Notes fetch current authorized contents from a POST/CSRF protected read endpoint. HTML-like titles render as text. Checkbox actions submit exact task/reminder IDs through Chat; they reflect saved state on refresh. Reminder completion remains limited to a current due occurrence. Future schedules can still be managed through Chat.

Notes support pointer dragging, touch dragging, arrow-key movement, refresh, collapse and close. Browser placement stores references/positions only, under account and Agent keys; titles/items are not cached in local storage. At most eight notes open together. Notes stay within the viewport after resizing. Failed access clears displayed contents; delayed loads cannot reopen a closed note. Closing a note never deletes records.

Run **upgrade.php once** after deploying. It adds list, membership and confirmation tables; it does not replace existing tasks/reminders. This release changes VP3 Cloud only.

## Acceptance criteria

1. Explicit standalone task save produces a verified canonical receipt without a reminder confirmation loop.
2. Missing lists ask first and create neither a list nor task before confirmation.
3. Existing lists accept items; repeated normalized adds do not duplicate them.
4. Confirmations enforce expiry, cancellation, consumption and conversation/Agent boundaries.
5. Existing tasks attach by reference; canonical completion appears in the note.
6. Task/membership failure rolls back atomically.
7. Show commands return current read-only note references across typed/voice/legacy endpoints.
8. Notes enforce ownership, per-record policy, CSRF and live permissions.
9. Browser movement, viewport bounds, persistence, safe text rendering and delayed-load fencing pass desktop/mobile acceptance.
10. Existing capability, reminder, transcript and recovery checks remain green before merge.

Automated endpoint language interpretation and browser APIs use controlled fixtures; deployed model interpretation and a physical microphone still require user acceptance. SQLite tests exercise actual task storage/services; MySQL's owner-row lock is additionally exercised by the concurrent acceptance test in CI. No claim of production user data testing is made.
