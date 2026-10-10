# Runtime Reliability & Recovery — Cloud

Agent Chat and Agent Brain present HomeServer's scheduler state, checked/tick/success/failure timestamps and safe failure text. Interrupted runs show Needs review; workers running over five minutes are visible. Every proposed change still requires its exact separate approval.

A failed mission or schedule refresh invalidates cached healthy state in both views. Reconnection updates them together. Responses from older HomeServer builds without scheduler health are shown as unavailable. All supplied text is rendered with text nodes.

## Acceptance

The paired ten-point rubric is in HomeServer `RUNTIME_RELIABILITY_ACCEPTANCE.md`; 10/10 requires all gates on the exact release candidate, including green full CI, Windows installer certification and source/hash verification. Local browser execution may be restricted by the host; configured CI browser jobs remain required.

`node tests/agent-missions-cloud-a5c6-browser.mjs` exercises the production Chat script: reviewed schedule creation, confirmation, persistent lost-response retries, pause/resume/cancel, timestamped history, healthy/degraded state, review/slow-run presentation, inert supplied text, disconnect invalidation, reconnect and old-server compatibility. The existing HomeServer Runtime Journey workflow already runs this test and earlier specialist browser regressions.

Deploy Cloud first and then the matching HomeServer installer. Scheduling and durable execution remain owned by HomeServer; this release introduces no additional write authority or automatic replay.
