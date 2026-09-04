# Stonefellow v66 Code Review — Agent Chat Live Updates

## Scope

Review covers Producer-share activity events, Supervisor listening events,
authenticated Chat polling, live activity UI behavior, notification
deduplication, privacy scoping, and regressions against v65/v64.

## Review result

**10 / 10 for the v66 Agent Chat live-update scope.**

This score is based on source review, complete PHP/JavaScript syntax checks and
targeted structural/regression validation. It does not claim a live production
browser/session test was performed from inside this build environment.

## Architecture

v66 deliberately reuses the existing `notifications` table rather than adding a
second event table.

Advantages:

- events are durable
- existing user scoping is reused
- no new SQL migration
- notification bell and Agent Chat can reflect the same underlying event
- shared-host compatibility remains simple

The Agent Chat API returns only the authenticated user's matching event rows.

## Event sources

### Track shared with Producer

Edit Track creates:

- `producer_track_share` for the Producer
- `agent_track_share` for the acting track manager / track owner

Unsharing also creates `agent_track_share`.

### Supervisor listening

`api/playback.php` creates `agent_supervisor_listen` when a signed-in Supervisor
starts a playback session.

The track owner is the primary recipient. If the track has no owner, the
existing permission fanout is used for default track managers.

`source_type=supervisor_play_session` and the playback session ID prevent
duplicates for one session.

## Polling

`api/chat.php?action=activity`:

- requires `chat.access`
- requires CSRF
- filters by the current user ID
- returns recent 7-day activity on initial load
- returns only IDs after the cursor on subsequent polls
- caps each incremental response at 30 events
- returns unread bell count in the same request

Browser interval: **3 seconds**.

Hidden tabs skip interval requests and refresh immediately on visibility return.
Polling also restarts after back/forward-cache page restoration.

## Chat UI

Agent activity is not inserted into a normal saved conversation. It uses a
separate sticky Agent Updates surface so:

- activity is not accidentally deleted with a conversation
- switching conversations does not remove operational updates
- normal AI conversation history stays semantically clean
- recent operational events remain visible while chatting

## Security / privacy

A user cannot poll another user's activity because `user_id` is derived only
from the authenticated session.

The client cannot submit a target user ID.

Existing track visibility and Producer access rules are unchanged.

## Regression validation

Preserved:

- full Agent Chat conversation history/delete behavior
- playable Chat media
- playback analytics
- Producer sharing
- Producer scoped Studio editing
- Producer export
- live recording
- mute/solo repair
- coordinated seek
- Save / Save As
- Load Song / Open Project

## Database

No SQL changes are required for v66.
