# Stonefellow v101 — Agent Chat continuity and shared production notes

## Outcome

Agent Chat now greets each signed-in user by first name, introduces itself, restores the conversation the user was actively working in, and presents unread ecosystem changes after a meaningful idle or logout boundary. Activity state distinguishes working, paused, idle, and explicit logout while retaining the linked Agent Chat conversation across Chat, Stem Studio, and Video Editor.

Production notes are collaboration records. Both the track page and Stem Studio REGION notes write an assistant message into each authorized collaborator's current Agent Chat and create a live notification for everyone except the author. REGION notes retain their time range, author, full text, and a deep link back to the selected range in Stem Studio.

## Database upgrade

Run the normal `/upgrade.php` flow before using REGION notes. It adds the following nullable fields and index to `track_notes`:

- `region_start_seconds DECIMAL(12,4)`
- `region_end_seconds DECIMAL(12,4)`
- `idx_track_notes_region (track_id, region_start_seconds, created_at)`

The equivalent one-time SQL is in `upgrade-stonefellow-v101.sql`.

## Safe QA sequence

1. Sign in as a producer assigned to a track and open Agent Chat conversation A, then conversation B. Reload Chat and confirm B returns.
2. Open Stem Studio from B, make an edit, return to Chat, and confirm B is still active.
3. Add a REGION note over a loop range. Confirm it appears on the timeline after reload and deep-links back to that range.
4. Sign in as the assigned producer, a supervisor, and an unrelated producer. Confirm the assigned producer and supervisor receive the Agent Chat note and notification; the unrelated producer receives neither.
5. Go idle or log out, create a track share/edit/note from another authorized account, then return. Confirm the personalized briefing names the new ecosystem activity.
6. Delete a production note from the track page. Confirm the source record disappears while the already-delivered Agent Chat message remains as collaboration history.

## Deploy readiness

The deploy zip must include both new PHP files, the v101 SQL migration, and the v101 cache-busted JavaScript references. Do not enable REGION note QA until `/upgrade.php` reports the access schema ready.
