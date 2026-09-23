# VP3 Cognitive Runtime v25.60 — Budget Guardrails & Spend Governance

v25.60 turns the economic evidence introduced in v25.50 into explicit, user-controlled autonomous-spend governance.

Authority:

**v25.60 Budget Governance → v25.50 Economics → v25.40 Commitment Protection → v25.30 Replan → v25.20 Resource Budget → v25.10 Optimize → v24.90 Forecast → v24.80 Admission → v24.70 Materialize/Repair → Phase 19 Claim/Lease/Execute/Receipt**

## Explicit policies only

VP3 does not invent a monetary or token budget.

A budget exists only after the signed-in user explicitly creates one. Policies may be scoped to:

- account,
- goal,
- Agent,
- project/workflow source key.

Periods are daily, weekly or monthly.

Each policy may contain:

- a configured-rate USD estimate cap,
- a VP3 Cloud token cap,
- or both,
- a warning threshold,
- soft or hard enforcement.

There is no default hard cap.

## Durable governance, not a second spend ledger

v25.60 adds two owner-scoped tables:

- `cognitive_budget_policies_v2560` — mutable user configuration,
- `cognitive_budget_decisions_v2560` — append-only policy/override audit.

Those tables do **not** record usage as a replacement ledger.

Actual recorded usage continues to come from `ai_execution_ledger`; package allowance, credits, outstanding token reservations and actual cloud enforcement remain with `subscription_ai_balance()`, subscription quota and the AI gateway.

## Period accounting

Budget windows are deterministic UTC calendar periods:

- daily,
- Monday–Sunday weekly,
- calendar month.

Each policy reads its current-period usage from the canonical AI execution ledger.

Goal budgets use canonical goal → workflow-run lineage. Agent budgets use `ai_execution_ledger.agent_id`. Project budgets use the existing workflow `source_key` as the project/source identity.

## Projection

v25.60 combines:

- current-period canonical usage,
- v25.50 cloud cost/token history,
- v24.90/v25.20 remaining work units,
- the currently active autonomous Cloud portfolio.

It derives planned remaining cost/tokens and run-rate period-end projections.

These are estimates. Configured-rate cost remains an estimate, not an invoice.

Shared workflow economics remain proportionally attributed by v25.50.

## Soft policies

Soft budgets never block work.

When projected use reaches the user-selected warning threshold, v25.60 adds a small bounded planning pressure to otherwise-safe autonomous Cloud work.

Manual, supervised, HomeServer, and protected commitment work are not silently deprioritized by a soft cost preference.

## Hard policies

A hard policy may hold **new autonomous Cloud work only** when:

- the recorded cap is already exhausted,
- admitting the next planned item would exceed the cap,
- a hard USD cap cannot be guaranteed because actual or projected pricing is unknown.

The hold is admission governance, not execution.

v24.80 refuses new claim/materialization/remediation admission for held goals. v25.20 does not reserve capacity for them. v25.30 reports `request_budget_approval`.

Phase 19 also removes a linked autonomous-goal run from its bounded claim-candidate window when the canonical hard cap is already exhausted. It does not alter the workflow row or fabricate a different status.

Already-executing work is never cancelled by v25.60.

## Commitments

An explicit hard budget can conflict with a v25.40 commitment.

VP3 keeps the commitment visible and reports a **commitment/budget conflict**. It does not silently discard the commitment and it does not silently exceed the hard budget.

The user can grant a bounded override.

## Overrides

Overrides are explicit user decisions recorded in the append-only budget audit.

They may target a goal, workflow run or whole policy scope.

An override expires no later than the current budget period. It does not:

- increase the configured budget,
- add tokens,
- purchase tokens,
- change a subscription package,
- modify a deadline,
- switch executors,
- change workflow approval state.

Revocation is another append-only decision.

## User controls

`/budget-governance.php` provides:

- policy creation/update,
- soft/hard enforcement,
- USD estimate/token caps,
- daily/weekly/monthly periods,
- policy disabling,
- current/projected usage,
- held-goal review,
- bounded goal overrides,
- governance audit history.

## Presentation

Agent Brain gains **Budget Governance** with policy health, remaining budget, projected burn, held goals, commitment conflicts and overrides.

Agent Brief, Proactive Now and bounded Working Context use the same canonical projection.

Agent History remains actual Agent Chat conversation history.

## Failure behavior

If v25.60 is unavailable, v25.50/v25.40/v25.30/v25.20/v24.80 and Phase 19 retain their prior behavior.

Budget governance cannot deadlock non-autonomous work or replace subscription enforcement.
