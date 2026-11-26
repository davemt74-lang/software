# Adaptive Multi-Model Intelligence — Phase 1A audit, increment 1

Canonical plan: [VP3_ADAPTIVE_MULTI_MODEL_INTELLIGENCE.md](VP3_ADAPTIVE_MULTI_MODEL_INTELLIGENCE.md), PR #885.
Status: source-audit checkpoint; not implementation, runtime certification, or Phase 1A completion.
Baseline source snapshots:
- software cba60611cc57a896b2eda841a4bbba2360b55fb2
- Otro 806db3b26488c9efcf41031c7a80844c7c21cad7

## Findings and reuse map

| Area | Source evidence (paths relative to respective repo) | Finding / action |
|---|---|---|
| Cloud service catalog | software includes/ai-service-policy-v1.php:4–23, 85–145 | Existing registered model inventory and service routes; conversation, initiative, image_generation, code_build, code_repair wired; chat_canvas and eyes_vision marked unwired. Reuse service policy ceilings; do not confuse admin service config with owner specialist preferences. |
| Cloud routing | software includes/agent-runtime-routing-v420.php:128–223 | Existing agent transport preference auto/homeserver_only/vp3_cloud, readiness and fallback. Extend existing resolution rather than create parallel routing. |
| Initiative inference | software includes/agent-initiative-v1.php:272–276, 1793; includes/agent-initiative-model-v1.php:10–76,112–195 | Shared wrapper uses initiative service, agent ID, background flag and protocol. Local operation sends messages only; cloud service context includes job metadata. No specialist-specific local model selector on this path. Preserve 55-second budget, transient route recheck and existing error/usage semantics. |
| Specialist attribution | software includes/agent-initiative-specialist-registry-v1.php:10–108; includes/agent-coordination-source-registry-v1.php:16–107; includes/agent-initiative-runtime-v1.php:84,496 | Existing tool/goal attribution, source registry and job specialist projection. Use authoritative persisted assignment identity, not model-generated labels or display names. |
| Top of Mind | software includes/agent-top-of-mind-specialists-v1.php:631–779 | Existing status projection merges Gmail, Operator, location, image, coordination and job state. Reuse projection and existing notification flow; no new competing feed. |
| Job worker | software includes/agent-job-worker-v1900.php:13–43 | Existing polling/distributed execution seams; audit leases and cancellation before introducing model scheduling. |
| Compute receipts | software includes/homeserver-compute-v232.php; includes/homeserver-local-execution-v230.php:51–113,196 | Existing provider/model/route and failure metadata. Extend receipts with requested/actual model, assignment/stage and bounded reason, without prompt content. |
| Transient privacy | software includes/homeserver-https-relay-v1300.php:114–170,316–402,439; tests/homeserver-https-transient-inference-v1.php | Existing scoped transient delivery and cleanup. Preserve fail-closed migration, owner/device binding and no widening to other operations. |
| HomeServer providers | Otro app/services/providers.py:19–218,581–738; database/migrations/004_agent_brain.sql:1–14; database/migrations/012_inference_usage.sql | Ollama loopback discovery, configured model, automatic provider selection and overrides already exist. Explicit generate_for_provider seam exists for governed mission workers; inspect its callers before extension. Do not pass arbitrary model IDs into general provider selection. |
| HomeServer local relay | Otro app/services/remote_bridge.py:1277–1296 | agent.infer.local authenticates agent.chat, bounds messages, calls generate_ollama(messages), returns actual provider/model/usage and stateless/tools-disabled flags. No model selector here. Existing Ollama override seam can be reused after authorization and capability checks. |
| HomeServer owner UI/API | Otro app/brain_api.py:44–57,347–394; app/services/system_state.py:133–183 | Existing provider settings, discovery test and diagnostics. Extend these; no second provider control panel. Readiness from configured model is not proof of model availability or performance. |
| Agent identity and privacy | Otro app/services/agent_routing.py:16–103; app/services/context_chat.py:45–64,249–302; app/services/brain.py:219–257 | Agent model field, conversation binding, app grants and local-only override path exist. Do not create new personas for specialist model assignments. Preserve permissions and conversation identity. |
| Cognition | Otro app/services/cognitive_runtime.py:543–674 | Persistent cognitive job claim/process/tick/scheduler exists; not evidence of LLM resource-aware queue. Audit mission/worker orchestration before adding scheduling. |
| Pairing cardinality | software includes/homeserver-https-relay-v1300.php:32–64,240–247 | HTTPS sessions use user_id primary key and unique device_id. Multi-HomeServer concurrency is not supported by this session schema as written; defer migration to Phase 7. |
| Packaging | Otro installer/HomeServer.iss; HomeServer.spec; docs/INSTALLER_COMPUTER_CHECK.md | Existing PyInstaller/Inno Setup pipeline and staged disk budget. Model artifacts excluded from installer budget. Managed runtime/model installation and hardware probes still need audit; keep weights outside installer. |

## Specialist coverage and outstanding call graph
Existing canonical identifiers must be reconciled with actual runtime entry points; report sources are not necessarily model calls. Initial source inventory:
- gmail: agent-email-triage-assessment-v1.php, agent-initiative-specialist-reports-v1.php, coordination registry; email vs gmail naming needs normalization.
- tasks/planning: agent-initiative-task-reports-v1.php, agent-initiative-office-entry-work-v1.php, coordination registry.
- research/transcripts: agent-initiative-research-canvas-v1.php, agent-initiative-transcript-report-v1.php, agent-initiative-transcript-research-v1.php.
- marketing: agent-marketing-review-v1.php and coordination registry.
- coding/canvas: tool registry canvas.prepare, service catalog code_build/code_repair; trace actual coding canvas dispatch separately.
- image-generator/eyes: agent-initiative-image-v1.php, agent-initiative-eyes-v1.php; distinguish image generation from text reasoning and vision.
- operator/location: Top of Mind status and operator specialist; trace live conversation inference separately from background assessments.
- media, connected-sites, profile, commerce, mission, curiosity, brainstorm, development: registry and/or Top of Mind entries; trace direct provider calls, deterministic paths and service-specific bypasses before claiming coverage.
- App Suggestions and any additional UI/debugger entries: reconcile against canonical IDs; do not invent duplicate specialists.
- Otro mission/team and physical/voice inference: existing explicit provider seam and context_chat path; audit actual callers and permissions separately.
Search connector returned no results for known symbols, so absence of results is not evidence of absence. Full call graph remains an explicit Phase 1A blocker. Use a real repository checkout with ripgrep or equivalent for complete enumeration.

## Proposed minimum contract (design only, not yet approved implementation)
1. Extend existing capability projection with a versioned, bounded inventory of eligible installed local model identifiers and readiness. Legacy clients continue using existing default.
2. Persist owner/agent/specialist assignment independently of admin service policy; derive specialist from authorized job, not prompt or remote caller. Resolve capability, privacy, budget and tool policy first. No arbitrary provider or endpoint URLs.
3. For an explicit local assignment, send a version-negotiated model selector through the existing stateless local inference operation only after verifying the device supports it. On old clients or unavailable model, return an explicit blocked/fallback decision; never silently claim requested model ran. Do not allow a local selector to choose a hosted provider.
4. On Otro, validate selected model against owner-approved inventory and actual availability; reuse generate_ollama model_override. Maintain stateless/tools-disabled semantics and local-only behavior. Do not widen paired-app permissions or expose private model data to unauthorized apps.
5. Cloud route selection must remain within service/provider policy and budget; reuse existing service context, accounting and execution receipts. Distinguish configured, resolved and actual model. Preserve default behavior when no specialist assignment exists.
6. Defer model installation, automatic switching, multi-device orchestration and advanced stages to their designated phases. No production code in this checkpoint.

## Concurrency / coordination
Recent open PR inventory included software #887–889, #853–855 (Operator), #847 (phone), #419 and Otro #138 (federation), software #248–255 (cognitive runtime), Otro #363–364 (wearable). Recheck actual changed-file intersections and latest heads before any implementation. Open PR is not proof of active work. Keep this checkpoint docs-only; no application code, migrations, deployment or specialist changes.

## Acceptance test plan for later implementation
- Existing default route unchanged on both old and new clients; explicit selector only when capability version supported.
- Two authorized specialists use distinct installed models without changing Agent identity, global provider defaults or working memory.
- Disabled/uninstalled/incompatible model fails explicitly; local-only never escalates to hosted provider; budget denial is enforced before provider call.
- Unauthorized caller/foreign owner/device, forged specialist ID, arbitrary URL/model and oversized selector rejected.
- Transient requests preserve existing cleanup and ownership; no prompt or secrets in receipts.
- Actual provider/model verified against execution evidence; failed or mismatched result never reported as successful requested model.
- Existing Operator, coding, image, voice, scheduling and Top of Mind regression tests continue to pass.
- Windows real runtime validation and installer upgrade tests required; mocks do not prove hardware support.

## Gate and score
This is a partial source-audit checkpoint, not a completed feature. Verified reuse mapping and explicit contract boundaries are valuable; complete specialist call graph, UI/runtime install audit, hardware/Inkling verification, actual test execution, compatibility and deployment remain unverified. Do not assign 10/10 or advance implementation based on this document alone. Phase 1A next: obtain checkout or equivalent complete source enumeration, finish call graph and test inventory, inspect active PR diffs, then publish a completion report. Phase 1B compatibility research may proceed independently; do not claim Inkling support without official artifacts and Windows evidence.
