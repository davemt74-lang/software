# VP3 Cognitive Runtime v24.70 — Governed Autonomous Remediation & Long-Horizon Project Execution

v24.70 lets VP3 continue an explicitly autonomous goal across existing roadmap milestones and safely remediate a narrow class of failed objective work.

The central design rule is simple:

> **Autonomy changes who may continue already-authorized work. It does not create a second execution system.**

## Canonical goal execution mode

The existing `agent_goals` table now carries:

- `execution_mode`
- `autonomy_updated_at`

Supported modes are:

- `manual` — observe only; new objective work requires an explicit user command.
- `supervised` — monitor, explain and recommend; no automatic objective materialization or remediation.
- `autonomous` — VP3 may continue an already-defined roadmap through the governed rules in this phase.

Every existing and newly created goal defaults to **manual**.

Changing mode is an explicit user action. Agent Chat accepts commands such as:

- `set goal #12 autonomous`
- `set goal #12 supervised`
- `set goal #12 manual`

## Long-horizon execution

An autonomous goal can continue across multiple roadmap milestones without requiring the user to say “continue” after each successful objective.

v24.70 does **not** invent a roadmap.

If the goal has no milestones, autonomy stops at `plan_missing` and asks for user review.

If an existing next milestone is advisory and has no objective, v24.70 may convert that already-defined milestone into a normal Phase 17.5 objective.

The new objective remains fully governed by the existing runtime:

1. Phase 17.5 decomposes the objective into canonical child workflows.
2. Each workflow receives its ordinary risk classification.
3. Work requiring approval remains `approval_pending`.
4. Dependencies remain authoritative.
5. Approved and dependency-clear work is claimed only by the existing Phase 19 Cloud/HomeServer worker runtime.
6. Worker leases, heartbeats, retry backoff and receipts remain unchanged.
7. The existing objective parent verification workflow runs only after child dependencies complete.
8. Verified success advances the roadmap to the next unresolved milestone.

v24.70 never directly calls a tool or claims a worker lease.

## Governed autonomous remediation

v24.70 can automatically repair one narrow failure class:

- the failed run belongs to the current canonical objective,
- the run is actually `failed`, not user-cancelled,
- risk is `low`,
- it does not require approval,
- no earlier canonical replacement already exists,
- the objective has not exceeded the bounded autonomous remediation-cycle limit.

The existing Phase 17.6 replacement mechanism creates the replacement workflow and rewires remaining dependencies.

The replacement workflow still runs through normal risk, approval, dependency, worker and receipt systems.

v24.70 will **not** automatically replace:

- cancelled work,
- medium/high-risk work,
- work waiting for approval,
- repeatedly failing objectives beyond the remediation-cycle limit.

Those cases remain user-facing supervision/follow-through items.

## Replacement idempotency

Phase 17.6 failed-work replacement is now filterable and idempotent.

Before replacing a failed child, the runtime checks for an existing `objective_replaced` event.

Once replaced, the failed workflow remains immutable historical evidence but is removed from the active objective-child projection.

This matters for verification: an old replaced failure must not keep the current objective permanently blocked after its replacement succeeds.

## Relationship to v24.60 supervision

v24.60 remains the health detector.

It detects:

- terminal failures,
- stale/expired execution,
- dependency blocking,
- verification stalls,
- replanning loops,
- authorization loss.

v24.70 is the next governed layer.

For explicitly autonomous goals, it may:

- materialize the next existing milestone,
- replace bounded low-risk failed objective work.

Everything else remains a supervision/follow-through escalation.

## Working Context

v24.20 now includes one bounded `autonomy` section for the System Agent.

It contains safe project-level state:

- goal ID/title,
- execution mode,
- current execution state,
- verified progress,
- recommended governed next action.

It does not include hidden model reasoning or grant execution authority.

## Agent Brain and Agent Brief

Agent Brain adds **Autonomous Projects** using the same v24.70 projection.

It shows:

- autonomous vs supervised mode,
- verified progress,
- current execution state,
- governed next action,
- whether the user is required.

Agent Brief can surface the current long-horizon goal.

The existing **History** tab is intentionally unchanged. Autonomous supervision/project-state changes do not become synthetic conversation turns; History continues to show canonical Agent Chat history.

## Background cognitive loop

The existing Agent Brain cognitive loop invokes v24.70 after v24.60 governed reconciliation.

A pass is bounded:

- at most 8 non-manual goals inspected,
- at most 2 milestone objectives materialized,
- at most 2 low-risk failed workflows replaced,
- at most 2 autonomous remediation cycles per objective.

This prevents one cognitive-loop pass from expanding a large amount of new work.

## Safety and authority boundaries

v24.70 creates no second:

- project database,
- scheduler,
- job queue,
- worker,
- lease system,
- retry engine,
- approval system,
- receipt ledger.

It never:

- auto-enables autonomy,
- invents a missing roadmap,
- approves work,
- resumes a paused goal,
- replaces a user-cancelled workflow,
- auto-repairs medium/high-risk work,
- steals a worker lease,
- bypasses dependencies,
- directly executes a tool.

## Cognitive stack

- v24.00 — Memory
- v24.10 — Attention
- v24.20 — Working Context
- v24.30 — Turn Orchestration
- v24.40 — Goal & Task Continuity
- v24.50 — Proactive Follow-Through & Cross-Surface Handoff
- v24.60 — Autonomous Work Supervision
- **v24.70 — Governed Autonomous Remediation & Long-Horizon Project Execution**
