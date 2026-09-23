# VP3 Cognitive Runtime v24.60 — Autonomous Work Supervision

v24.60 monitors long-running VP3 work for stalls, failures, unmet dependencies, authorization loss, overdue verification, and repeated replanning.

It is autonomous about **supervision and governed reconciliation**, not autonomous about bypassing execution controls.

## Architectural rule

v24.60 creates no second:

- scheduler,
- task store,
- retry engine,
- execution queue,
- approval system,
- worker lease system.

It supervises the authorities VP3 already has.

## Existing authorities preserved

### Durable Agent workflows

The v19.00 durable job engine already owns:

- Cloud/HomeServer worker claims,
- one active lease,
- heartbeats,
- lease expiration,
- max attempts,
- retry backoff,
- receipt-backed results,
- pause/resume,
- terminal failure.

v24.60 uses `agent_job_recover_expired_v1900()` as its only automatic workflow-recovery mutation.

That existing function either:

1. releases an expired lease and schedules a bounded retry using the configured backoff/max-attempt rules, or
2. moves the workflow to terminal failure when attempts are exhausted.

v24.60 does **not** directly call the user-controlled workflow retry path and does not claim work for a worker.

### Cognitive Orchestration

v5.60 remains authoritative for accepted cognitive plans.

v24.60 may call the existing reconciliation function so authoritative outcomes can move a plan to:

- completed,
- closed,
- blocked,
- verification failed,
- needs replan.

Reconciliation does not execute an external tool. Handoff and approval boundaries remain intact.

### Objective verification

v17.6 remains authoritative for objective verification/remediation and replacement work.

v24.60 detects objective remediation state but does not independently invent replacement workflows.

## Supervision states

v24.60 can identify:

- `terminal_failure`
- `expired_execution_lease`
- `heartbeat_stale`
- `ready_unclaimed`
- `planning_stalled`
- `dependency_blocked`
- `waiting_approval`
- `waiting_user`
- `objective_remediation_pending`
- `replan_required`
- `replan_loop`
- `authorization_lost`
- `verification_overdue`
- `plan_stalled`
- `commitment_overdue`
- `verification_needs_attention`
- `planning_required`
- `review_required`

The source workflow/plan/meeting/goal remains authoritative. Supervision health is a projection.

## Automatic versus user-controlled response

### Governed automatic reconciliation

v24.60 can automatically:

- recover an **expired** durable execution lease through the existing v19.00 recovery path,
- reconcile an accepted cognitive plan against new authoritative outcome evidence.

### Never automatic in v24.60

v24.60 does not:

- retry a terminally failed workflow,
- approve work,
- claim work as a Cloud/HomeServer worker,
- execute a tool,
- override an active lease,
- restore missing authorization,
- accept a replan on the user's behalf.

Those states are surfaced through v24.50 follow-through and v24.10 attention.

## Stall detection

### Durable workflows

A workflow can be flagged when:

- an execution lease has expired,
- its heartbeat is stale while the lease is still active,
- it is approved, due, dependency-free, and remains unclaimed beyond the ready window,
- planning remains unchanged beyond the planning window,
- canonical workflow dependencies are unresolved,
- it reaches terminal failure.

An active lease is never stolen. A stale heartbeat before lease expiration is advisory only.

### Cognitive plans

A cognitive plan can be flagged when:

- verification remains open beyond the verification window,
- an active/blocked plan does not change state beyond the plan window,
- it requires replanning,
- it has entered repeated replanning,
- authorization to the source object is lost.

Three or more replans in a still-`needs_replan` plan is treated as a replan loop and escalated to user review.

## Working Context

v24.20 gets one bounded `supervision` section containing safe summaries of the most important current issues.

The section contains only:

- continuity reference,
- health state,
- severity,
- title,
- reason,
- recommended supervisor action,
- whether user/approval input is required.

It contains no raw tool payload, browser content, meeting transcript, hidden model reasoning, or worker credentials.

## Follow-through and attention

v24.50 uses v24.60 health as an overlay.

A health transition becomes part of the stable handoff event key. For example:

`workflow:42 / working / healthy`

and:

`workflow:42 / working / expired_execution_lease`

are different semantic states.

That allows a newly detected failure/stall to surface once even when the v24.40 continuity state itself still says `working`.

Actual presentation still goes through v24.10 Attention and the existing Browser/Presentation delivery ledgers.

## Background cognitive loop

The existing Agent Brain cognitive loop runs governed v24.60 reconciliation before ranking current priorities.

The loop records only reconciliation counts such as:

- expired workflows recovered,
- expired workflows moved to terminal failure,
- cognitive plans reconciled,
- reconciliation errors.

No tool execution is added to the cognitive loop.

## Agent Brain and Agent Brief

Agent Brain adds **Work Supervision** with:

- Critical count
- High count
- Needs you
- Auto-reconcile
- health state
- recommended supervisor action

Open Work cards also show their supervision health when present.

Agent Brief can surface the highest-priority supervision issue and route user-controlled review through Agent Chat.

No separate supervision history database is created; History continues to represent actual canonical Agent Chat turns rather than synthetic supervisory thought.

## Relationship to the cognitive stack

- v24.00 — Memory
- v24.10 — Attention
- v24.20 — Working Context
- v24.30 — Turn Orchestration
- v24.40 — Goal & Task Continuity
- v24.50 — Proactive Follow-Through & Cross-Surface Handoff
- **v24.60 — Autonomous Work Supervision**

The next phase can use this health layer for policy-governed remediation plans and long-horizon autonomous project execution without weakening the existing approval or execution boundaries.
