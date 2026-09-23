# VP3 Cognitive Runtime v25.20 — Resource Budgeting & Capacity Reservations

v25.20 protects bounded autonomous capacity around important deadlines without creating a second scheduler, worker pool, queue, or lease system.

Authority remains:

**v25.20 Resource Budget → v25.10 Optimize → v24.90 Forecast/Sequence → v24.80 Admission → v24.70 bounded autonomous mutation → Phase 19 Claim/Lease/Execute/Receipt**

## Derived reservations

Reservations are calculated from canonical goal evidence every time the portfolio is projected. They are not persisted as worker locks.

The planner considers:

- target deadline,
- user priority,
- v25.10 optimization score,
- dependency leverage,
- verified progress,
- current Cloud/HomeServer pressure,
- current blockers and approval requirements.

Only autonomous goals with a real target date inside the bounded planning horizon are eligible.

## Reservation windows

Each selected goal receives a bounded reservation window derived from its expected work units and executor. The current defaults are:

- planning horizon: 72 hours,
- minimum reservation lead: 30 minutes,
- maximum reservation lead: 8 hours,
- maximum reserved slots per executor: 2,
- executor reservation ceiling: at most 50% of configured concurrency, with a minimum of one slot when capacity is one.

A reservation may be:

- **planned** — selected but its protection window has not started,
- **active** — its admission slot is currently protected,
- **conditional** — its window is open, but dependencies or user approval still block activation.

Conditional reservations do not hold a worker slot.

## Admission behavior

v24.80 remains the portfolio admission authority.

When an active reservation exists:

1. a claimable reserved goal receives its protected autonomous admission slot first;
2. if that reserved goal is not yet claimable, the slot stays unavailable to unrelated autonomous claims;
3. a reserved goal that needs its next objective may still materialize that objective using its protected budget;
4. remaining unreserved capacity continues through the existing v24.80 ordering.

Manual or supervised shared work is unchanged.

## What v25.20 does not do

v25.20 does not:

- call a worker,
- claim a job,
- create a lease token,
- alter execution targets,
- approve actions,
- persist reservations,
- create a reservation table,
- replace Phase 19 concurrency checks,
- bypass dependencies or approval gates.

The actual runtime still checks live worker readiness and concurrency immediately before Phase 19 claims and executes work.

## Failure behavior

Resource budgeting fails open to the existing v24.80 admission logic. An unavailable budget projection cannot deadlock already-authorized durable work.

## Presentation

Agent Brain gains **Capacity Reservations**, including active/planned/conditional counts and per-goal reservation windows.

Agent Brief exposes the current resource-budget focus.

Proactive Now and Working Context use the same canonical projection.

**Agent History remains actual Agent Chat conversation history.**
