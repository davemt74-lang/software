# VP3 Cognitive Runtime v25.30 — Autonomous Portfolio Replanning & Recovery

v25.30 adds a bounded recovery layer above the existing forecasting, optimization, reservation and admission stack.

Authority remains:

**v25.30 Replan → v25.20 Resource Budget → v25.10 Optimize → v24.90 Forecast/Sequence → v24.80 Admission → v24.70 bounded autonomous mutation → Phase 19 Claim/Lease/Execute/Receipt**

## What v25.30 detects

The recovery overlay evaluates the current verified portfolio for:

- deadline threat,
- unavailable Cloud/HomeServer capacity,
- dependency stalls,
- approval or user gates,
- semantic overlap,
- urgent capacity pressure.

It compares the already-optimized baseline order with a recovery order and exposes the exact rank delta and reason codes.

## Bounded recovery

When drift exists, v25.30 may reorder **existing autonomous goals only**.

It does not:

- create a goal or objective,
- rewrite dependencies,
- change an action executor,
- approve work,
- claim a job,
- create a lease,
- replace v24.80 admission,
- replace Phase 19,
- persist hidden model reasoning.

Manual and supervised work preserve their relative order.

## Capacity-loss recovery

When an authorized executor becomes unavailable, v25.30 marks the goal for **executor-unavailable escalation**. It does not silently switch Cloud work to HomeServer or HomeServer work to Cloud.

A different executor still requires the existing authorized system/user path.

## Deadline recovery

A deadline threat increases recovery pressure for otherwise safe autonomous work. The overlay can move that work earlier before v25.20 derives its capacity reservations.

This lets the reservation layer protect the recovered order without v25.30 acquiring lease or worker authority.

## Dependency recovery

Blocked work is not treated as executable merely because it has a high deadline score. It is marked for dependency-unlock handling and loses autonomous recovery eligibility until its canonical dependency graph permits progress.

Goals with high downstream dependency leverage may move earlier when they are otherwise safe to advance.

## Approval and user gates

Work requiring approval or user input is surfaced as **request_user_or_approval**. Replanning cannot bypass the gate.

## Reservation recovery

v25.20 reservations are derived rather than persisted. When a goal completes, blocks, changes deadline, loses eligibility, or disappears from the active portfolio, its old reservation is naturally absent from the next projection.

v25.30 therefore does not need a second durable reservation/replan ledger to clean up stale capacity.

## Observable plan delta

Agent Brain exposes:

- plan health,
- issue count,
- deadline threats,
- capacity loss,
- dependency stalls,
- rank changes,
- exact baseline rank → recovery rank changes,
- reason codes and recommended recovery action.

The comparison is deterministic and contains no hidden chain-of-thought.

## Failure behavior

If the v25.30 overlay is unavailable or errors, v24.80 keeps its proven existing ordering and v25.20 derives reservations from that order.

Already-authorized durable work cannot be deadlocked by the replanning projection.

## Presentation

Agent Brain gains **Portfolio Replanning**.

Agent Brief exposes current plan health and recovery focus.

Proactive Now and bounded Working Context consume the same canonical projection.

**Agent History remains actual Agent Chat conversation history.**
