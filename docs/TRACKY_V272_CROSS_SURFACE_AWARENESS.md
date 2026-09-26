# Tracky V2.72 — Cross-Surface Physical Awareness & Proactive Agent Integration

## Purpose

V2.72 takes the governed physical context introduced in V2.70/V2.71 and lets existing VP3 surfaces use it without creating a Tracky-specific notification, voice, automation or feed stack.

Canonical flow:

`Tracky governed event → Cognitive observation → Cognitive Attention → Agent Now / notification / proactive Agent Chat / opt-in voice`

## Surface policy

Tracky stores its surface policy in the existing `user_plugin_installations.settings_json` row. Defaults are conservative:

- Agent Now: enabled for meaningful object, environment, routine, health and safety events
- proactive Agent Chat: enabled for health and safety attention events
- Tracky voice: disabled until explicitly enabled
- automation trigger publication: enabled, but no action is executed
- cross-plugin physical-context access: disabled until explicitly enabled

Global Agent voice, quiet/focus state, interruption state and the existing Cognitive Attention budget always remain authoritative.

## Now

Tracky does not create a second Now feed. A physical event becomes a canonical Cognitive observation with a `physical_event` display card. The existing Cognitive Feed selects it, and V2.72 applies a Tracky policy filter plus a site/class grouping key to suppress repetitive physical noise.

## Agent Chat and voice

Only events selected by Cognitive Attention become Tracky notifications. Notifications use `source_type=tracky_event`, so delivery is deduplicated by the existing notification authority and retains a direct link to the same physical event.

Proactive Agent Chat consumes the normal attention-notification stream. Voice consumes the normal Cognitive Presentation notification cursor. A Tracky-specific voice hook additionally requires the user's Tracky voice policy; global Agent voice must also be enabled.

## Alert lifecycle

Physical alerts are not stored in a new Tracky alert ledger. Lifecycle is derived from the canonical event and notification systems:

- new — current attention event with no acknowledgement
- acknowledged — its canonical notification has been read
- superseded — a newer event in the same physical group replaced it
- resolved — a newer recovery/healthy/connected event closed it

When a new same-group event arrives, prior active Cognitive observations and unread Tracky notifications are retired so stale alerts do not keep resurfacing.

## Automation trigger foundation

V2.72 publishes governed trigger metadata for room, person, object, environment, routine, health and possible-safety events. Trigger payloads contain only cloud-approved physical meaning. They do not call the workflow executor or any physical device-control API.

Physical execution and active perception remain reserved for the future OTRO/Cloud contract join.

## Cross-plugin SDK

Other enabled VP3 plugins can request a `physical_context.v1` current-context summary only when the user explicitly enables cross-plugin access. The SDK is owner-scoped, read-only and excludes raw perception and event history by default.

## Privacy

V2.72 inherits V2.70's fail-closed cloud boundary. Raw frames, continuous video/audio, face embeddings, camera source URIs and local filesystem evidence do not enter proactive surfaces.

Possible safety signals preserve uncertainty in user-facing wording and do not claim emergency-monitoring certainty.

## Simulator

Deterministic scenarios cover arrival, departure, object movement, camera failure/recovery, possible safety conditions, duplicate delivery and outage/reconnect. These are release fixtures for cross-surface regression testing.
