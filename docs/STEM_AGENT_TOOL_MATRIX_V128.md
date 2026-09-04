# Stonefellow Stem Studio Tool Matrix — Phase 2 v128

Runtime: `stem-tools-phase2-v128-20260826`

Phase 2 builds on the v127 truth contract. The v127 layer still blocks null/unstructured/fake success. The v128 layer adds **command-specific verification for advanced Studio operations** so a generic state diff is no longer enough for the tools below.

## Result rule

For an advanced command to return `success`, the exact requested Studio state must be observable after execution. If the legacy bridge reports success but the requested field or object does not match, v128 returns `failed`.

`no_change` is used for idempotent requests already in the requested state. Reset Mix also returns `no_change` when the observable mix state is already neutral instead of manufacturing another successful edit.

## Advanced mixer and plugin tools

| Tool | Execution path | Dedicated verification |
| --- | --- | --- |
| Track trim | v90 Studio bridge | `stem.trim` equals requested dB |
| AUX send A/B | v90 Studio bridge | `stem.send_a` / `stem.send_b` equals requested value |
| Track route/group | v90 Studio bridge | `stem.route` equals requested route |
| Add plugin (`plugin_picker`) | v128 real Studio plugin-picker UI fallback | target plugin count increases by one and requested plugin type appears |
| Plugin parameter | v90 Studio bridge | requested plugin parameter equals requested value |
| Plugin bypass/enable | v90 Studio bridge | plugin `enabled` equals inverse of requested bypass state |
| Remove plugin | v91 Studio bridge + confirmation | exact remaining plugin sequence equals the pre-state with the requested index removed |
| AUX return A/B | v91 Studio bridge | `master.aux_a` / `master.aux_b` equals requested value |
| Reset mix | v91 Studio bridge + confirmation | mute/solo state is neutral and the observable mix fingerprint changed; already-neutral/no-change state is reported as `no_change` |

`plugin_picker` previously existed in the planner/sanitizer without an executable structured bridge. v128 resolves the target stem through the real Studio selection state, opens the existing plugin picker, chooses the real plugin-directory button, then verifies the plugin object appeared on the target stem.

Plugin removal fingerprints type, enabled state and parameters for every remaining plugin. Removing a different plugin can no longer pass merely because the array became one item shorter.

## Automation tools

| Tool | Verification |
| --- | --- |
| Add automation point | target automation lane contains requested time/value |
| Delete automation point | exact remaining time/value sequence equals the pre-state with the requested index removed |
| Clear automation | target lane is empty |

Automation clear remains confirmation-required. Indexed delete rejects a wrong-point deletion even when the lane count decreases by one.

## Arrangement / clip tools

| Tool | Verification |
| --- | --- |
| Move clip | clip start equals requested/clamped start |
| Trim left/right | resulting clip start/end matches requested edge time |
| Clip gain | `gain_db` matches requested value |
| Fade in/out | requested fade value is reflected, bounded by clip duration |
| Clip mute | clip muted state matches request |
| Split clip | same-stem count increases by one **and** two resulting pieces tile the original clip with a boundary at the captured playhead |
| Delete clip | requested clip disappears and total clip count decreases by one |

Clip delete remains confirmation-required. Split verification captures the playhead before execution so adding an unrelated clip cannot satisfy the command.

## Timeline tools

| Tool | Verification |
| --- | --- |
| Set loop | loop active + start/end match request |
| Clear loop | loop inactive |
| Add marker | marker count increases and requested time/label exists |
| Add REGION note | region count increases and requested range exists after server save |
| Zoom | timeline zoom equals requested value |
| Snap | snap mode equals requested mode |

REGION note creation continues to use the existing server-backed sharing path before the local region object is accepted as complete.

## Generic UI commands

`ui_set`, `ui_select`, and `ui_toggle` are verified against the current control manifest after execution. `ui_click` must cause an observable control-state change; a click that produces no observable effect cannot be reported as successful.

## Destructive / confirmation-sensitive operations preserved

The server sanitizer continues to require user confirmation for:

- `clip_delete`
- `automation_clear`
- `plugin_remove`
- `reset_mix`
- recording operations already covered by Phase 1

The Agent UI still performs the confirmation before invoking the browser command.

## Out of scope for Phase 2

These remain for later focused phases rather than being overclaimed here:

- live-output recording arm (`live_mix_on/off`, `live_track_on/off`)
- recording input-device selection, punch, count-in, take save/discard
- `save_as` completion when a name/dialog interaction is required
- semantic audio analysis / mix-quality recommendations
- conversational references such as “that vocal” or “bring it back” beyond current planner/state resolution

## Phase 2 non-regression acceptance

A future change fails the v128 contract if any of the following becomes possible:

1. a send/trim/route/plugin command reports success but the exact target field is wrong;
2. `plugin_picker` is planned but has no executable path;
3. an indexed plugin/automation removal deletes the wrong object but reports success;
4. a clip split reports success without dividing the requested clip at the captured playhead;
5. a clip edit reports success while the requested geometry/state does not match;
6. a loop, marker, REGION, zoom or snap command succeeds without the exact timeline state being observed;
7. a destructive advanced command bypasses its required confirmation;
8. the Phase 1 v127 false-success protections are removed or bypassed.
