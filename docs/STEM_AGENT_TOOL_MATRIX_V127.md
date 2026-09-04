# Stonefellow Stem Studio Tool Matrix — Phase 1 v127

Runtime: `stem-tools-phase1-v127-20260826`

Phase 1 rule: **Stonefellow may not report a Studio edit as successful unless the execution bridge can verify the requested resulting state, or the operation has its own explicit completion signal (for example Studio Save).** Null, boolean, unknown, or unverified executor returns are never treated as success.

## Result contract

Every browser-side Studio command returns one of:

- `success` — requested state/action was confirmed.
- `no_change` — Studio was already in the requested state or the operation requires additional user input before a state change can complete.
- `failed` — the command ran or was attempted, but the requested result could not be confirmed.
- `unsupported` — no safe executable Studio tool exists for the requested command in the current runtime.
- `cancelled` — the user declined a confirmation-required action.

The Agent history API continues to store overall `success`, `failed`, or `cancelled`; v127 preserves per-command truth statuses inside the result text. `unsupported` maps to overall failed and `no_change` maps to overall success for legacy history compatibility.

## Phase 1 core controls

| Tool | Planner/API advertises | Server validates | Browser executes | Post-state verified | Phase 1 path |
| --- | --- | --- | --- | --- | --- |
| Play | Yes | Yes | Yes | Yes (`playing=true`) | v127 verified fallback |
| Pause / stop playback | Yes (`pause`) | Yes | Yes | Yes (`playing=false`) | v127 verified fallback |
| Relative seek | Yes (`v105_seek_relative`) | v105 direct | Yes | Yes (playhead) | translated to v127 absolute seek |
| Save | Yes | Yes | Yes | Yes (Studio saved signal) | v127 verified fallback |
| Tempo | Yes | Yes | Yes | Yes (`tempo`) | v127 verified fallback |
| Reset tempo | Yes | Yes | Yes | Yes (`tempo`) | v127 verified fallback |
| Stem mute/unmute | Yes | Yes | Yes | Yes (`stem.muted`) | v127 verified fallback |
| Stem solo/unsolo | Yes | Yes | Yes | Yes (`stem.solo`) | v127 verified fallback |
| Stem volume | Yes | Yes | Yes | Yes (`stem.volume`) | v127 verified fallback or verified legacy |
| Stem pan | Yes | Yes | Yes | Yes (`stem.pan`) | v127 verified fallback or verified legacy |
| Master volume | Yes | Yes | Yes | Yes (`master.volume`) | verified legacy/fallback |
| Bus volume | Yes | Yes | Yes | Yes (`bus.volume`) | verified legacy/fallback |
| Bus mute | Yes | Yes | Yes | Yes (`bus.muted`) | verified legacy/fallback |
| Track route/group | Yes | Yes | Yes | Yes (`stem.route`) | verified legacy |
| Metronome state/style | Yes | Yes | Yes | Yes (`metronome.enabled`) | verified v91/legacy |
| Metronome volume | Yes (`v105`) | v105 direct | Yes | Yes (`metronome.volume`) | v127 verified fallback |
| Monitor | Yes | Yes | Yes | Yes (Studio state/button) | v127 verified fallback |
| Arm recording stem | Yes | Yes | Yes | Yes (armed row/inspector) | v127 verified fallback |
| Record | Yes | Yes + confirmation | Yes | Yes (`recording`) | v127 verified fallback |

## Existing advanced commands retained

These commands remain on the existing v90/v91 bridge and are **not removed by Phase 1**:

- library, select, inspector, automation
- live_mix_on/off, live_track_on/off
- plugin_picker, plugin_param, plugin_bypass, plugin_remove
- track_trim, send, aux_return
- automation_point, automation_delete, automation_clear
- ui_click, ui_set, ui_select, ui_toggle
- clip_move, clip_trim, clip_gain, clip_fade, clip_mute, clip_split, clip_delete
- loop_set, loop_clear
- marker_add, region_add
- reset_mix, zoom, snap

Phase 1 normalizes their result objects through the same truth bridge. Advanced commands that already return structured success keep working. Commands that return no structured executor result are now `unsupported` rather than fake success. Full state-by-state verification for every advanced tool belongs to the next Stem Studio tool-coverage phase.

## Deliberately not claimed complete in Phase 1

- `save_as` can require a version name/dialog interaction, so opening that UI is not equivalent to a completed save.
- plugin parameter semantics and plugin-removal side effects need dedicated Phase 2 state assertions.
- clip and automation edits need a full arrangement-state verification matrix.
- recording setup beyond arm/monitor/start (input selection, punch, count-in, recording save/discard) belongs to the recording-focused phase.

## Non-regression acceptance

A future change fails the Phase 1 contract if any of the following becomes possible:

1. `executeAgentCommand()` returns `null` and Stonefellow reports success.
2. a command returns legacy `success` but the requested Studio state did not change and Stonefellow reports success.
3. a repeated idempotent command such as `play` while already playing is reported as a new edit instead of `no_change`.
4. an unknown command is reported as `Done`.
5. the Agent speaks the planner's optimistic answer before execution results are known.
6. a confirmation-required recording action is executed after the user cancels.
