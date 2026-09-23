# VP3 Cognitive Runtime v24.80 — Autonomous Portfolio Coordination

v24.80 coordinates multiple explicitly autonomous VP3 goals without creating a second scheduler or project system.

The core rule is:

> **Portfolio coordination decides admission and sequencing pressure. Existing VP3 authorities still own goals, dependencies, approvals, worker claims, leases, execution, receipts, verification, and remediation.**

## Why this phase exists

v24.70 can continue one autonomous goal safely. Once several autonomous goals are active, they can still compete for the same Cloud/HomeServer capacity or independently create substantially overlapping next-step work.

v24.80 adds one bounded portfolio view over those existing systems.

It answers:

- Which autonomous goal should receive the next available worker slot?
- Which already-defined roadmap milestone may be materialized next?
- Which goal is a prerequisite for other goals?
- Are two not-yet-materialized milestones substantially overlapping?
- Are multiple goals sharing the same canonical objective?
- Is work being held because Cloud or HomeServer capacity is already committed?

## No new portfolio database

v24.80 creates no schema and stores no portfolio decisions.

It derives its state from:

- `agent_goals`
- `agent_goal_objectives`
- `agent_goal_milestones`
- `agent_workflow_runs`
- `agent_workflow_run_dependencies`
- existing objective portfolio signals
- existing Phase 19 worker activity

The coordination projection is rebuilt from current canonical state.

## Coordination score

Each non-manual goal gets a deterministic coordination score:

- user goal priority — 30%
- deadline pressure — 25%
- current execution state — 18%
- cross-goal dependency leverage — 17%
- verified completion proximity — 10%

The score does not grant execution authority. It only determines portfolio ordering and admission when multiple autonomous goals could advance.

## Worker capacity

v24.80 derives lightweight Cloud and HomeServer capacity:

- configured maximum concurrency,
- currently active leased jobs,
- remaining free slots,
- recent paired HomeServer state.

The capacity projection deliberately does **not** refresh the live HomeServer capability registry.

That is important because worker polling must not cause portfolio analysis to perform another live capability round trip.

The existing Phase 19 worker runtime remains authoritative immediately before execution and still checks:

- current worker readiness,
- capability availability,
- owner identity,
- lease identity and freshness,
- approval state,
- execution target.

## Claim admission

The existing v19.00 durable claimant asks v24.80 one question before it attempts to lease an objective-step workflow:

**Is this all-autonomous portfolio work currently admitted for this executor?**

The gate applies only when:

- the candidate is an `objective_step`,
- it is linked through its canonical objective to active goals,
- every active goal sharing that objective is in `autonomous` mode.

The gate does not apply when:

- the run is not objective-step work,
- no goal owns it,
- any linked goal is manual,
- any linked goal is supervised.

This preserves user-controlled and mixed-authority work.

Objective parent verification is not portfolio gated.

If the portfolio projection itself is unavailable or throws unexpectedly, the claimant fails open to the existing Phase 19 authorization/dependency/lease controls. Portfolio coordination must never deadlock already-authorized durable work because an advisory projection failed.

## Fair use of worker slots

For each executor, v24.80 looks at currently claimable autonomous goals and the executor's free slots.

When several goals compete, it prefers higher coordination scores while also preferring a goal that does not already have active work on that executor before giving another slot to a goal already executing.

This creates project-level fairness without moving the actual queue or lease authority out of Phase 19.

If only one autonomous goal is runnable, it can still use additional capacity normally.

## Admission before new objective creation

Free worker slots are first reserved conceptually for already-created claimable work.

Only remaining capacity can admit a new `needs_objective` roadmap milestone.

This prevents the cognitive loop from continually creating additional project work while existing canonical workflows are already waiting for available workers.

v24.80 then delegates admitted materialization to v24.70.

v24.80 never creates an objective directly.

## Overlap protection

For two autonomous goals that both still need their next objective, v24.80 compares:

- the goal text,
- the next roadmap milestone title.

If similarity is at or above the v24.80 threshold, the lower-ranked new materialization is held for review.

This overlap rule applies **only before new objective work exists**.

It does not:

- cancel an existing objective,
- pause existing workflows,
- suppress canonical verification,
- rewrite dependencies,
- merge goals automatically.

## Shared objectives

An objective may already contribute to more than one goal.

v24.80 detects that through `agent_goal_objectives` and treats it as shared canonical work.

It does not create a duplicate objective merely because more than one goal depends on the same outcome.

If one of the sharing goals is manual or supervised, claim admission passes through rather than imposing autonomous-only gating.

## Cross-goal dependencies

v24.80 maps active objective parents and child workflows back to their owning goals, then reads the existing `agent_workflow_run_dependencies` graph.

If work for Goal B depends on canonical work owned by Goal A:

- Goal B records Goal A as a portfolio blocker.
- Goal A gains dependency leverage in the coordination score.

No new dependency edge is written.

The existing dependency graph remains authoritative.

## Autonomous repairs

Existing failed-work repair remains owned by v24.70 and Phase 17.6.

v24.80 only chooses which repair-needed autonomous goals enter the bounded v24.70 pass.

All v24.70 rules remain:

- low-risk only,
- approval-free only,
- cancelled work requires the user,
- remediation cycles are bounded,
- replacement is idempotent,
- resulting work still uses normal Phase 19 execution.

## Working Context

v24.20 gains one bounded `portfolio` section.

It contains safe coordination metadata such as:

- goal ID/title,
- execution mode/state,
- coordination score,
- executor,
- coordination action,
- hold reason,
- cross-goal blockers,
- shared-work references,
- aggregate worker capacity.

It is data-only and grants no instruction, approval, or execution authority.

## Agent Brain and Agent Brief

Agent Brain adds **Portfolio Coordination** with:

- held goals,
- claim-admitted goals,
- admitted new objectives,
- admitted repairs,
- Cloud free capacity,
- HomeServer free capacity,
- per-goal coordination score/action,
- overlap/dependency/shared-work indicators.

Agent Brief can surface the portfolio focus and whether that goal is admitted or held.

The existing **History** feed remains canonical conversation history. Portfolio arbitration is not synthetic chat history.

## Relationship to v24.70

v24.70 remains the mutation authority for long-horizon autonomous work.

Its background runner now accepts an optional bounded portfolio policy:

- allowed goal IDs,
- allowed action per goal,
- objective materialization budget,
- remediation budget.

When no policy is supplied, v24.70 retains its prior behavior.

The Agent Brain loop uses v24.80 when available and falls back to v24.70 if the new coordinator is unavailable.

## Cognitive stack

- v24.00 — Memory
- v24.10 — Attention
- v24.20 — Working Context
- v24.30 — Turn Orchestration
- v24.40 — Goal & Task Continuity
- v24.50 — Proactive Follow-Through & Cross-Surface Handoff
- v24.60 — Autonomous Work Supervision
- v24.70 — Governed Autonomous Remediation & Long-Horizon Project Execution
- **v24.80 — Autonomous Portfolio Coordination**
