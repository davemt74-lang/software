# Current context in the proactive Agent conversation

Baseline: current main 02c44c034c23423aa2f2e3eaea8d77057ff9714a. The existing Initiative scheduler, workflow leases, receipts, main Chat, owner follow-through and Brain projection remain canonical.

The Agent receives actual authorized list membership and current explicitly supplied screen, Eyes and Ears observations. The model relates those observations to verified tasks, lists, Missions and conversation, then chooses whether useful private work or a focused check-in is warranted. Source counts and sound levels do not prescribe announcements. No separate sensing conversation or background work queue is introduced.

## Acceptance criteria

1. Current active sources are bound to the actual owner, Agent, saved conversation, document tab and live session before storage. Old-account, foreign-Agent, stale-tab and late responses cannot enter a different current context.
2. Screens contribute fresh descriptions, visible text, inferred observations and separately sourced tab transcripts/sound labels only while the existing per-share context control is enabled. Historical frames and stopped shares cannot masquerade as live context.
3. Eyes contributes provisional local presence, arrival/departure and already available nonidentity detector labels. The selected Agent decides naturally whether to greet. Independent automatic arrival speech is removed from canonical owner Chat. Camera capture, enrollment and visual sample permissions retain their existing boundaries.
4. Ears separates actual shared-tab speech/provider sound labels from local microphone energy and coarse silence. It never invents coughing, laughter, typing, speaker intent, gender, mood or identity from energy. No new microphone upload is introduced.
5. List-level reads use canonical list/member permissions, actual order/content/status/due details, complete semantic revisions and honest bounds. Historical task aliases remain verifiable, while a new assessment cannot schedule competing assignments for the same canonical task.
6. Current observations enter the existing planner and preparation/check-in tools as untrusted evidence. Real source-backed work completes through actual actions/receipts into the same Chat/Brain, and owner continuation rereads the original exact sources. Source changes, stop, expiry and revocation fence inference completion and delayed delivery.
7. Changed semantic live context can wake the existing assessment after a bounded 30-second cooldown; unchanged context retains the ordinary five-minute review cadence. Heartbeat clocks alone do not create another situation or inference loop.
8. Ordinary reports stay visible text. A model-selected brief check-in can request voice through the configured Agent voice, current source/permission checks and an at-most-once server claim. Two owner tabs cannot speak competing copies. Typing, active Agent work and sustained microphone activity can defer voice without suppressing valid text.
9. Raw media, embeddings and raw sensory packets never enter activity history, Brain, workflow goals/receipts or debug logs. The current cache rejects stale data and the existing worker scrubs expired packets. Model-derived private reports may persist in normal Chat and its memory. HomeServer inference uses an explicit transient transport contract with prompt/result cleanup, preserving configured routing; unsupported retention omits only live evidence.
10. Real desktop/mobile browser tests, source/permission/race cases and native MySQL migration/locking tests pass. The exact reviewed PR head is officially green on its validated current main base before merge. Post-merge workflows and the original production artifact are verified against the merged Git tree.

## Source and retention boundaries

The current-context cache lives in the existing activity row, with a strict 90-second source/receipt ceiling and source-specific freshness. Heartbeats can renew an actually current observation; they cannot renew the age of an old frame. Sensor/session changes are part of source revision identity. Model records omit account, Agent, conversation, tab and capture identifiers and operational clocks; receipts retain source references, revisions and read fingerprints instead of copying packets.

Explicit camera/listening/share controls still start capture. The existing per-share context control now names both conversational and proactive use. The separate finished-share saved-note setting keeps its historical meaning.

Shared-tab transcription uses the existing opted-in audio upload and speech provider request. Provider sound labels are bounded, timed and attributed to that shared tab; they are observations rather than verified facts about the owner. Local microphone activity is unclassified and requires an existing explicitly activated microphone.

The additive HomeServer transient flag is installed by the standard Database Upgrade and has a separate idempotent SQL migration. Older ordinary HomeServer work remains compatible. Source reads and final source validation never perform schema DDL. Existing execution-receipt schema checks remain outside source transactions. Raw application request payloads/responses are removed when the transient request is delivered/consumed, fails, expires or is revoked; provider-side retention remains governed by the configured provider.

Expiry scrubbing depends on the existing worker/heartbeat being invoked; source reads reject expiry independently. Repository and local browser/native CI evidence does not prove an installed host's cron, credentials, physical camera/microphone environment or deployment state.
