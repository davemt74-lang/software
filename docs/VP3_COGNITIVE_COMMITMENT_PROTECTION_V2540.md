# VP3 Cognitive Runtime v25.40 — Commitment & Deadline Protection

v25.40 makes existing user/customer commitments visible to the cognitive portfolio without creating a new commitment system.

Authority:

**v25.40 Commitment Protection → v25.30 Replan → v25.20 Resource Budget → v25.10 Optimize → v24.90 Forecast → v24.80 Admission → v24.70 Materialize/Repair → Phase 19 Claim/Lease/Execute/Receipt**

## Canonical sources

The implementation audit found three existing commitment authorities and reuses them directly:

- **Goal commitments:** Phase 17.15 over canonical `agent_goals`, review history, objective verification, and goal target dates.
- **Meeting commitments:** Phase 18.23 Meeting Commitment Command lineage over meeting agenda follow-ups, follow-through plans, action handoffs, executions, monitoring, verification closures, and continuity.
- **Agent Brain tasks/commitments:** Phase 1.23 task lifecycle over `agent_memory_items`, including explicit user source metadata and due dates.

No `cognitive_commitments` table or parallel promise ledger is added.

## Commitment identity and strength

Each projected commitment has a stable source identity:

- `goal:<goal_id>`
- `meeting:<agenda_item_id>`
- `memory:<task_key>`

Strength is deterministic and source-aware. A ready meeting follow-up with a target and verification criterion is the strongest explicit promise. An active goal with a target date is an explicit goal commitment. Explicit user Agent Brain commitments are stronger than inferred task reminders.

Strength changes internal protection pressure only. It does not rewrite the source commitment.

## Deadline and conflict protection

v25.40 derives overdue, urgent, due-soon and scheduled states.

For Cloud/HomeServer commitments, a bounded lane simulation uses current canonical worker capacity and estimated work duration to identify capacity/deadline conflicts. The output is advisory policy evidence; no worker slot is leased by this layer.

Goal commitment pressure is attached to existing portfolio items before v25.30 replanning. v25.30 may therefore move a safe autonomous committed goal earlier.

v25.20 reservation scoring also consumes commitment pressure, allowing protected goal work to receive bounded admission reservations.

## Meeting task protection

When an explicitly approved meeting follow-up has already created a canonical Agent workflow run, v25.40 may prefer that **existing eligible run** inside the candidate window returned by Phase 19.

It does not add candidates, remove candidates, claim a job, create a lease, change the executor, bypass dependencies, or bypass approval.

The normal Phase 19 claim loop still validates every candidate and owns the lease.

Meeting calendar, CRM and email commitments remain visible as commitments but are not converted into Agent workflow work by v25.40.

## No silent promise changes

v25.40 cannot:

- move a deadline,
- cancel or extend a commitment,
- change an executor,
- approve work,
- create a task or objective,
- rewrite dependencies,
- mark an intended outcome complete.

Changes to source commitments remain with the existing user/organizer controls.

## Verified completion

Execution is not treated as success.

Verified completion is counted only when the canonical source says it is complete:

- goal commitment: verified goal achievement,
- meeting commitment: Phase 18.20 intended-outcome verification closure,
- Agent Brain task/commitment: canonical task lifecycle completion.

Meeting carry-forward is continuity, not completion.

## Cross-surface projection

Agent Brain gains **Commitment Protection** with Protected, At risk, Waiting on you, Conflicts, Verified complete, and source-specific commitment cards.

Agent Brief, Proactive Now and bounded Working Context use the same projection.

Agent History remains canonical conversation history.

## Failure behavior

If v25.40 is unavailable, the pre-v25.40 portfolio and Phase 19 candidate ordering remain intact. An unavailable protection projection cannot deadlock already-authorized work.
