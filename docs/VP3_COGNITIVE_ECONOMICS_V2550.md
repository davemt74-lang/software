# VP3 Cognitive Runtime v25.50 — Cost & Resource Economics

v25.50 makes cost and token scarcity visible to the autonomous portfolio without creating another billing, token, scheduler, worker or execution authority.

Authority remains:

**v25.50 Economics → v25.40 Commitment Protection → v25.30 Replan → v25.20 Resource Budget → v25.10 Optimize → v24.90 Forecast → v24.80 Admission → v24.70 Materialize/Repair → Phase 19 Claim/Lease/Execute/Receipt**

## Canonical economic sources

v25.50 reuses existing authorities:

- `ai_execution_ledger` from AI Usage Accounting v0.32 for recorded provider/model usage, token counts, route information and `estimated_cost_micros`.
- the v0.32 configured rate catalog for cost estimation.
- `subscription_ai_balance()` for canonical token availability, usage, credits and reservations.
- the existing subscription/AI gateway runtime for actual entitlement and token enforcement.
- the v24.80 goal → workflow-run graph for cost attribution.

No `cognitive_costs`, `portfolio_budgets`, billing ledger or parallel token reservation table is added.

## Estimated cost semantics

`estimated_cost_micros` is a configured-rate estimate derived from recorded usage. It is **not a provider invoice** and is never presented as billed cost.

A null estimated cost means pricing is unknown. Unknown pricing is never converted to zero.

Local/HomeServer execution that the canonical ledger explicitly records as `local_zero` remains a known zero estimate.

## Shared workflow attribution

A single workflow run can serve multiple goals.

v25.50 counts that run once in the canonical ledger and proportionally attributes its recorded estimated cost/tokens across the currently linked goals for planning analysis.

The raw linked-run amount is still observable, but goal economics are explicitly non-additive. This prevents shared work from being economically charged at 100% to several goals at once.

## Cloud-relative efficiency

Autonomous cloud work with priced history is compared to the account's recorded cloud average, not to an average polluted by known-zero local execution.

The resulting economics signal is bounded and deliberately weak.

It is neutral when:

- the work is manual or supervised,
- the work targets HomeServer,
- the work is an explicit protected v25.40 commitment,
- pricing is unknown,
- token pressure is low.

## Token pressure

v25.50 reads the existing subscription balance and derives a planning state such as healthy, watch, constrained, critical or exhausted.

It does not reserve, debit, refund, purchase or manufacture tokens.

The existing subscription and AI gateway layers remain the only authorities that can actually allow or deny cloud AI use based on package/token state.

## Planning integration

v25.50 annotates the existing v24.80 portfolio before v25.30 recovery and v25.20 reservation planning.

For safe autonomous cloud work only, real priced history plus meaningful token pressure may create a small bounded planning adjustment.

This can influence relative ordering or reservation pressure. It cannot make an ineligible run eligible and cannot block a Phase 19 claim.

**Commitment protection always outranks economics.**

## No silent economic authority

v25.50 cannot:

- change an executor,
- move a deadline,
- change approval state,
- edit a subscription/package,
- reserve or debit tokens,
- claim or lease a workflow,
- execute a tool,
- cancel work,
- invent a monetary budget.

There is intentionally no default dollar budget because no canonical user budget exists yet.

## Presentation

Agent Brain gains **Cost & Resource Economics** with:

- 30-day ledger-estimated cost,
- unknown-priced request count,
- recorded tokens and charged cloud tokens,
- quota state and remaining tokens,
- per-goal proportional attributed estimate,
- relative cloud cost index,
- bounded planning adjustment,
- commitment-exempt state.

Agent Brief, Proactive Now and bounded Working Context use the same projection.

Agent History remains canonical conversation history.

## Failure behavior

If v25.50 is unavailable, v25.40/v25.30/v25.20/v24.80 and Phase 19 retain their existing behavior.

No already-authorized work can deadlock because an economics projection failed.
