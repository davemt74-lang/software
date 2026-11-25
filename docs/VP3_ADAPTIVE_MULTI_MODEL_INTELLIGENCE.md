# VP3 Adaptive Multi-Model Intelligence — master development specification v1.0

Status: planning baseline, 2026-11-25. This document is the canonical cross-repository implementation plan, not a claim that proposed features are shipped.

## Objective
Extend the existing Windows HomeServer EXE and VP3 Cloud into one persistent, proactive Agent with interchangeable local/cloud models, per-specialist assignments, adaptive resource management and evidence-based improvement. Preserve identity, memory, objectives, permissions and work across model changes. Inkling 2 is a candidate integration, not a prerequisite for the general architecture.

## Repositories and existing evidence
- [Otro](https://github.com/davemt74-lang/Otro): Windows HomeServer, local inference, runtime, hardware, installer, local data. Inspect [providers.py](https://github.com/davemt74-lang/Otro/blob/main/app/services/providers.py), README, HomeServer.spec, installer/HomeServer.iss and existing tests. Initial inspection found Ollama discovery, loopback restrictions, model overrides, provider settings, tool calls, local-only mode, DPAPI, cognition scheduler, Whisper/Piper and usage ledger. Do not assume managed downloads or multi-model concurrency are implemented.
- [software](https://github.com/davemt74-lang/software): account configuration, Cloud inference, specialist orchestration, Top of Mind, Agent Chat, consolidated metrics. Inspect includes/homeserver-execution-routing-v220.php, includes/homeserver-compute-v232.php, includes/homeserver-agent-v018.php, includes/homeserver-capabilities-v024.php, includes/homeserver-https-relay-v1300.php, docs/HOMESERVER_V21_SHARED_AGENT_FABRIC.md and existing transient inference tests. Existing local inference operation agent.infer.local, authenticated relay, compute receipts and federation must be reused.
- Initial source inspection is not runtime certification. Verify current heads, existing contracts and concurrent PRs before implementation.

## Non-negotiable architecture
One Agent identity; specialists own expertise and workflows; models are replaceable engines; HomeServer owns local compute. Top of Mind coordinates attention, decisions and cross-specialist work. High-priority findings proactively reach Agent Chat; deduplicate and space reminders reasonably. Reuse existing notification, memory, permissions, tools, scheduler, usage and release systems. No duplicate orchestration engine. Preserve existing installs and paired accounts. Keep model weights outside the EXE. Never silently send local-only data to hosted providers or incur unauthorized costs. Model outputs are untrusted; enforce permissions outside models. No unrestricted computer access.

## Implementation phases and acceptance gates

### 1A — Cross-repository audit
Inventory Ollama installation/discovery, provider and model overrides, every specialist inference entry point, Cloud model selection, scheduler, Top of Mind/Chat, usage receipts, hardware detection, privacy, federation, model UI, pairing cardinality, Windows packaging and updates. Map actual files/functions/tests and distinguish implemented, partial, missing and runtime-unverified. Record baseline commit SHAs and gaps. Gate: evidence-backed reuse map and proposed contract changes; no speculative duplicate infrastructure.

### 1B — Inkling 2 compatibility
Verify official model identity, license, artifact hashes, quantizations, Windows Ollama/runtime support, tool calling, structured output, vision if supported, context and hardware requirements. Benchmark on representative Windows hardware. Do not advertise unverified specs or compatibility. If unsupported, retain existing Ollama and evaluate a narrowly scoped compatible adapter; do not block generic model assignment. Gate: reproducible successful inference and documented limits, or explicit blocker.

### 1C — Unified model registry
Stable provider/model/version identifiers, capability flags, availability, hardware requirements, provenance, license and actual compute source. Share versioned capability metadata across repositories without exposing secrets. Preserve existing provider defaults and overrides. Gate: compatible old/new clients and validated registry discovery.

### 2A — Model per specialist (first major user-facing priority)
Account default > Agent > specialist > cognitive stage > authorized assignment override, subject to non-overridable privacy, capability, budget and tool policies. Primary/fallback, automatic/manual/assisted/autonomous modes, locks, local-only/local-preferred/cloud-preferred policies, explicit escalation limits. Persist and synchronize settings with clear ownership and conflict handling. All actual inference entry points must honor resolved settings, not merely display them. Gate: two specialists demonstrably use different configured models; offline/unavailable models and forbidden fallback handled safely; identity and working memory unchanged.

### 2B — Settings UI
Existing specialist settings gear in Top of Mind plus central model assignment dashboard. Simple defaults and optional advanced controls; mobile responsive, no nested scrollbars. Show configured versus actual model, readiness, fallback reason and recent performance. Natural-language Agent controls use same validated settings API. Gate: persistence, reload, permission and browser tests.

### 2C — Cognitive stage assignments
Optional models for scan/classify, analyze, report, brainstorm, verify, draft and communication. Use deterministic code for routine checks. Preserve structured handoffs and evidence. Reasoning depth, creativity, context budget, initiative, verification, persistence and communication settings where supported. Per-specialist daily cloud allowance, max assignment cost/time, resource limits and escalation attempts. Gate: stage routing receipts and policy enforcement; default single-model behavior remains compatible.

### 3A — Local model management
Discover/install/import/update/remove compatible models; approved downloads, progress, cancellation/resume, disk preflight, integrity verification, version pinning, rollback and safe runtime lifecycle. Assess CPU/instructions, RAM, GPU/VRAM, disk, power, thermal/resource conditions where available. Benchmark actual performance; recommend appropriate quantization. Reuse Ollama and existing installer; do not bundle large weights. Gate: Windows installation/upgrade tests, interrupted downloads, insufficient space, offline and corrupt artifact handling.

### 3B — Compute Specialist
Register in existing specialist architecture; monitor model readiness, resources, queues, cost, failures and performance; research compatible models and recommend changes. Use existing assessment/report/brainstorm/verification loop. Forward actionable findings to Top of Mind, high priority to Agent Chat; approval for installations and material changes. Gate: real observed work, evidence-backed report, announcement deduplication and follow-through.

### 4A — Intelligent execution
Shared model pool, warmup/unload, context caching where safe, hardware-aware concurrency, bounded persistent queues, cancellation, recovery and idempotency. Prioritize active voice/chat over background jobs; avoid starvation; power-saving/balanced/performance/dedicated modes, scheduled overnight sessions, emergency stop. Group compatible jobs without leaking context between users. Gate: contention, restart, cancellation and priority tests; no duplicate side effects.

### 4B — Model collaboration
Optional researcher/strategist/reviewer teams, escalation on failed verification or unsupported capability, bounded retries, independent evidence checks. Do not use self-reported confidence alone. Preserve assignment state across models. Gate: measurable quality improvement on representative tasks and no unauthorized cloud transmission or tool actions.

### 5A — Performance learning
Record model/version, stage, latency, tokens, actual route, cost, resource usage, verified quality, tool success, user corrections and outcomes. Separate estimated savings from measured usage. Maintain specialist working, procedural and learning memory with provenance and retention controls. Gate: reproducible metrics and traceable outcomes, no fabricated scores.

### 5B — Controlled experiments
Compare models/prompts/context/scheduling on identical approved fixtures in read-only sandbox; no real-world actions. Record hypotheses, results, uncertainty, recommendation, approval, activation and rollback. Manual/assisted/autonomous changes limited to user-approved scope; never silently replace a locked model. Gate: repeatable evaluation and safe rollback.

### 6 — Collaborative specialists
Reuse existing assignments/missions and Top of Mind. Structured handoffs carry objective, evidence, priority, owner, dependencies, status and next action. Dynamic attention with starvation protection. Background cognition sessions and consolidated mission reports. Gate: end-to-end multi-specialist workflow with verified completion, deduplication and user-visible high-priority announcement.

### 7 — Personal AI network
Audit pairing before adding multiple HomeServers; explicit per-device permissions, capability-aware routing, offline operation, reconnect reconciliation, failover and state snapshots/recovery. Extend authorized paired-app inference for other products without widening access. Gate: multi-device isolation, offline/reconnect tests, no duplicate actions and safe revocation.

## Release and quality process
For each section: audit, implement, test, score, fix deficiencies, repeat; open scoped PRs in affected repositories and cross-link dependencies. Coordinate compatible deployment order and migrations. Merge only with required approvals and green checks on current head; verify post-merge CI. Produce verified Cloud deployment ZIP and HomeServer Windows EXE/installer from merged source, with checksums and upgrade notes when applicable. Do not claim live hardware verification from mocks.
Score one point each for reuse, functional completeness, security, privacy, UI, Agent integration, reliability/recovery, automated tests, backward compatibility and deployment verification. 10/10 requires evidence; explicitly report blocked or untested criteria, not invented scores.

## First milestone
Verified compatible local model integration (Inkling 2 if supported), per-specialist model assignment, and Compute Specialist foundation. Prioritize model assignment independently of Inkling compatibility. Existing installations and default routes must continue working.

## Coding agent handoff
Read this document and both repositories before changing code. Start at Phase 1A, produce an evidence-backed gap map and phase-specific acceptance tests, then implement in small compatible increments. Update this canonical plan with verified findings and link implementation PRs. Do not treat this documentation PR as implementation or merge authorization for future code.
