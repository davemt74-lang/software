# CD0 architectural discovery
Historical reviewed main snapshots: software 23cf52eced5dc1990291ddadf439d07f5b42d7f7; Otro 99122fd605aee617d164fa5e57d28f0cabdafbbc. Discovery included tree inspection and selected source/schema/docs; not a complete security audit or executed tests.
## Cloud integration map
- includes/phone-tracking-v1.php: hashed credentials, owner-bound rows, generation/revision, 300s invites, 60s fresh location, 30-day idle/90-day maximum, location/history off by default. Explicit schema install via upgrade.php. Preserve native authorization.
- includes/extension-device-token-v2100.php: durable extension tokens, 300s code; includes/homeserver-device-code-v1.php: 900s authorization, private verifier. Preserve credentials.
- includes/agent-worker-runtime-v1910.php: existing job authority, leases, receipts, capability routing; configured cloud concurrency 4/local 1, not measured live capacity. agent-worker-v1910.php CLI; browser ticks are not unattended workers.
- includes/agent-initiative-specialist-registry-v1.php; includes/agent-unified-specialist-followthrough-v1.php (explicit profile/media/marketing scope in reviewed adapter); includes/agent-top-of-mind-v1.php: extend rather than fork.
- includes/agent-initiative-model-v1.php and agent-inference-budget-v1.php: routing/service policy and time limits, not proof of per-device budgets.
- includes/plugin-registry-v320.php, plugin-lifecycle-v360.php; docs/APPS_PLUGINS_SECTION16.md: application lifecycle and release-bound approvals. docs/cloud-missions-v1.md and task-lists-sticky-notes-v1.md: canonical work/task references.
- .github/workflows/production-deploy-package.yml excludes live config, private, uploads, env, tests, tools and markdown; administrative tooling needs separate deployment consideration.
## Otro integration map
- app/services/room_device_automation.py, app/room_device_api.py, migration 023: registry, drivers, bounded commands, owner approval, receipts. Lights/outlets/fans/thermostats are governed; other categories discovery-only in v0.60. No arbitrary physical commands.
- app/services/action_policy.py: read_only, safe_automatic, approval_required, sensitive_high_impact; devices.command approval-only.
- app/services/hardware_adapters.py: vp3-hw-v1 serial protocol, handshake, reconnect, normalized events, status lights. Not universal network HAL.
- app/services/device_audio.py: 16kHz mono capture/stream/playback, buffered capture max 60s. Network adapter needed.
- app/services/device_rollout.py, migrations 027–029: controlled releases, fleet inventory, certification, privacy faults, hardware experience singleton. Existing executable package format is not embedded firmware format.
- migrations 016–020: app scopes, collaboration, action policy, local apps; 024–026: routines, ambient learning and orchestration; docs/VP3_OS_AMBIENT_INTELLIGENCE_V080.md and VP3_OS_AMBIENT_ORCHESTRATION_V090.md: proposals only, explicit activation and physical approvals, suppress feedback loops.
- migrations 031–032, 038: shared context, native authority, mirror links, cursors, tombstones, reconciliation. Device-specific adapter needed.
- migration 059: homeserver_apps registry with lifecycle and source types; 068 and app/services/workspace_sync.py: account-bound offline replicas, not execution queues; 070–079 mission contracts and outcomes.
- app/services/providers.py, migration 012: Ollama loopback, Anthropic/OpenAI/OpenRouter, model overrides, cancellation and usage attribution. No proof of complete specialist model selection or device budgets.
- telephony/operator_worker_protocol.py: signed frames, freshness, leases, simulation-only capabilities. Reuse patterns, not operator authority.
## Remaining verification
Trace actual call graphs, schemas, auth middleware, announcement IDs, migrations and active PRs before implementation. No runtime tests, production VPS inspection, physical hardware certification or exhaustive security review performed. Existing docs describe intended behavior, not proof of deployed functionality.
