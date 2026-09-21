# VP3 Cognitive Operations Core v23.00

## Purpose

v23.00 connects VP3's existing All Systems Listening/event ingress, Cognitive Runtime, Agent Brain, Agent Now/Chat canvas, planning/orchestration, Browser Companion, notifications and Agent Voice into one coherent cognitive operations contract.

It does **not** create a second brain, feed, event ledger, task queue, scheduler, worker, approval engine, execution engine or memory store.

The canonical operating loop remains:

**Observe → Correlate → Remember → Prioritize → Plan → Surface → Act through existing authority → Verify → Learn**

## System roles

### All Systems Listening / event ingress
Owns **what happened**.

Subsystems publish bounded, verified events through the existing Agent Event Infrastructure and Cognitive Runtime event envelope. Event ingress must not decide global priority, execute actions, or invent canonical state.

### Cognitive Runtime
Owns **structured meaning**.

The existing v5.00–v5.70 runtime authorizes object references, builds bounded context, stores observations, connects relationships, renders Universal Cards, learns presentation outcomes, proposes plans, orchestrates approved follow-through and preserves cross-time continuity.

### Agent Brain
Owns **cross-system prioritization context**.

Brain remains the existing persistent priority/cognitive state. v23.00 consumes Brain output alongside meetings, calendar, workflows, goals, notifications, observations, plans, orchestration and memory. v23.00 does not create a competing score or durable priority table.

### Agent Now / Agent Chat
Owns **human presentation and conversation**.

The main Chat canvas remains the primary operating surface. v23.00 adds a compact operations status projection to the existing Cognitive Feed rather than creating another dashboard.

### Execution systems
Own **doing work**.

Existing tools, Agent Workflows, Browser Runtime, delegated workflows, transaction authorization, Calendar/Meetings, CRM and other canonical systems remain the only execution authorities. A work item never grants authority.

### Verification + Learning
Own **what happened afterward**.

Existing outcome/learning and canonical subsystem state determine whether work changed, completed, failed, reopened or should be suppressed.

## Canonical work-item projection

A v23.00 work item is a **projection**, not a persisted object.

Required fields:

- `key` — existing Cognitive Feed item key
- `fingerprint` — existing source-derived fingerprint
- `lane` — `needs_attention | next_up | priorities | opportunities | waiting | recent_changes`
- `source` — existing candidate source
- `authority` — canonical subsystem that owns the fact
- `priority_score` — existing Cognitive Feed/Brain-derived score; v23.00 does not independently rank
- `reason` — bounded existing rationale
- `object_ref` — currently authorized canonical object reference when available
- `proposed_action_ids` — registered Cognitive Runtime actions only
- `requires_approval` — advisory projection of the existing candidate/action boundary
- `attention` — existing attention state
- `updated_at` — source time
- `execution_boundary` — always existing-runtime-only

The projection is rebuilt from currently authorized state on every read. Hidden feed items remain a presentation preference and do not mutate source authority.

## Source-of-truth rule

v23.00 never turns a model conclusion into a fact.

Examples:

- transaction state → Transaction subsystem
- meeting state → Meetings/Calendar
- workflow state → Agent Workflows
- CRM/customer state → CRM
- research evidence → Research/Source versions
- message state → Messaging/Team Chat
- goal state → Goals
- cognitive observation → Cognitive Runtime
- priority context → Agent Brain
- execution result → owning executor + verification receipt

The LLM may synthesize, explain, compare and propose. It cannot grant permission, approve an action, rewrite canonical state or claim external completion.

## All Systems Listening contract

All Systems Listening must enter through the existing verified event/cognitive pathway:

1. canonical subsystem changes,
2. bounded event enters existing event infrastructure,
3. Cognitive Runtime resolves authorized object references,
4. observation/context is derived,
5. Brain/Cognitive Feed determines relevance,
6. v23.00 projects the current authorized operating picture,
7. Agent Now/Chat, Browser Companion, notifications or Voice may present it according to existing presentation rules.

No raw browser history, cookies, credentials, unrestricted page content or unbounded subsystem payload is introduced by v23.00.

## Agent Now integration

The existing `vp3_cognitive_feed_compose_v530()` response gains an `operations` projection containing:

- active listening source count
- source/authority list
- work-item count
- lane counts
- attention count
- proposal/plan count
- execution boundary
- top bounded work-item projections

Agent Chat renders this as a small operations strip inside the existing **NOW — What deserves your attention** canvas.

Browser Companion already consumes the same canonical Cognitive Feed lineage; the operations projection therefore remains available without creating Chrome-only cognition.

## Non-negotiable invariants

- no parallel cognitive database
- no parallel event ledger
- no duplicate Brain
- no duplicate feed
- no duplicate scheduler/worker/queue
- no model-granted authority
- no automatic external writes
- no bypass of tool/workflow/browser/transaction approvals
- authorization is rechecked by existing Cognitive Runtime/card paths
- work items are references/projections, not copied canonical objects
- Agent Chat remains the primary human operating surface
- Cognitive Feed remains the presentation composition surface
- v23.00 may summarize existing ranking; it does not silently replace it

## v23.x continuation

- v23.00 — Cognitive Operations Core
- v23.10 — Unified Agent Priority Queue
- v23.20 — Proactive Opportunity Detection
- v23.30 — Cognitive Action Planning
- v23.40 — Proactive Agent Now + Agent Voice
- v23.50 — Outcome Learning & Cognitive Calibration
