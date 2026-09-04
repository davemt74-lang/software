# Stonefellow v105 — Agent Operations, Studio Voice Commands & Credits Graph

## Agent Operations / Release Calendar

Stonefellow now treats release planning as a first-class Agent Brain tool rather than a passive calendar.

- Monthly Release Calendar for singles, EPs, albums, videos, shows and campaigns.
- Release plans include target date, status, priority, notes and a persistent Agent goal.
- Release work items support songs/masters, artwork, video, social, shows, email, SMS, press, distribution, documents, websites, deadlines and general tasks.
- Work may be assigned to the Artist workspace team and linked to an authorized track or show.
- Agent Brain context includes release progress, upcoming work, due-soon work, overdue work and available resources.
- Proactive Agent suggestions elevate blocked, due and overdue release work without waiting for the user to ask about the calendar.
- Agent Chat can open/list the Release Calendar and create a dated release plan from a direct request.

## Expandable Agent resource + integration layer

v105 introduces reusable infrastructure for third-party busy-work automation.

- `agent_resources` stores user-controlled references to contact lists, documents, websites, media, spreadsheets and future connector objects.
- `agent_integrations` stores connection/capability metadata only; provider credentials/tokens are intentionally not stored in the Release Calendar tables.
- `agent_work_actions` is an audited action queue for future Gmail, SMS, social publishing, document and other adapters.
- External side effects can require explicit approval before execution.
- The provider adapter registry allows future integrations to register capabilities and an executor without hard-coding every service into Release Calendar.
- The execution contract refuses unapproved actions, missing provider adapters, disconnected integrations and unsupported provider capabilities.

No Gmail/SMS provider is connected by v105 itself; v105 provides the common tool/runtime contract they will plug into.

## Studio voice commands

The Studio Agent continues using the existing v91 command bridge for production actions such as solo/mute, mixer changes, plugins, automation, recording and metronome state. v105 adds deterministic voice/text shortcuts for timing-sensitive actions:

- “solo vocals” — existing structured Studio command path.
- “go back ten seconds” / “go forward 10 seconds” — moves the live playhead.
- “mark this section” — creates a marker at the current playhead position.
- “turn metronome down/up” — adjusts metronome volume by 10%.
- “metronome volume 50%” — sets an exact metronome volume.
- “open the last note” — opens the latest REGION production note for the current track.

The new shortcuts are resolved deterministically in `api/stem-agent-v105.php`, while Studio history/result logging remains intact.

## Credits Graph

- New Admin → Credits Graph workspace.
- Automatically derives contributors from track ownership, Producer assignment, stem-project import activity and production-note participation.
- Supports explicit/manual credits for songwriters, performers, engineers and external contributors.
- Visual radial graph connects the track to contributors and contribution roles.
- Credits are available as Agent context for relevant questions.

## Security / scoping

- Release plans are scoped to the Artist workspace owner.
- Delegated Manager/Producer accounts resolve to their owning Artist workspace.
- Release POST requests enforce workspace ownership for assignees, tracks, shows, work items and resources.
- Agent action records are scoped to the owning workspace.
- Third-party credentials remain outside Release Operations storage.

## Database

Run `upgrade-stonefellow-v105.sql` once after deploying v105. The migration is idempotent and creates the Release Operations, resource/integration/action and structured credits tables plus the `release.manage` and `credits.manage` permissions.
