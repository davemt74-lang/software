# VP3 Cognitive Runtime v25.10 — Strategic Portfolio Optimization

v25.10 compares multiple bounded portfolio strategies before v24.90 hands its advisory sequence to v24.80 admission.

Authority remains:

**v25.10 Optimize → v24.90 Forecast/Sequence → v24.80 Admission → v24.70 bounded autonomous mutation → Phase 19 Claim/Lease/Execute/Receipt**

## Strategy set

v25.10 evaluates four deterministic strategies over the same current goals and capacity:

- **Balanced** — blends current forecast score, user priority, deadline pressure, dependency leverage, efficiency and shared work.
- **Protect deadlines** — weights deadline pressure and priority most heavily.
- **Unlock dependencies** — favors goals that release downstream work.
- **Maximize throughput** — favors efficient work that can use available capacity without changing its authorized executor.

Every strategy keeps the same goals, execution modes and execution targets. Only autonomous ordering is optimized. Supervised and manual work keep their existing order.

## Measured comparison

Each scenario exposes observable metrics:

- projected deadline-risk count,
- projected total lateness,
- dependency-unlock value,
- strategic value,
- projected makespan,
- exact goal sequence.

The recommended strategy is selected deterministically with deadline risk first, then lateness, dependency unlock, strategic value and makespan.

The optimizer does not use hidden model reasoning to choose a strategy.

## Integration with v24.90

v24.90 still calculates forecast features and owns the sequence passed into v24.80. When v25.10 is available, v24.90 asks it to compare strategic orderings and returns the recommended ordering.

If v25.10 fails, v24.90 keeps its existing proven order.

v25.10 does not:

- create or lease jobs,
- change execution targets,
- approve work,
- create objectives,
- mutate dependencies,
- bypass v24.80 admission,
- replace Phase 19,
- create an optimization database or scheduler.

## v25.00 compatibility

v25.10 intentionally does **not** require a v25.00 calibration ledger. It consumes the current bounded v24.90 forecast evidence and remains compatible with future forecast-calibration work. Adding calibration later can improve duration evidence without changing v25.10 authority.

## Presentation

Agent Brain gains **Strategic Portfolio Optimization** showing the recommended strategy and all compared strategy metrics.

Agent Brief exposes the same recommended strategy and optimized focus.

Proactive Now uses the same projection.

Working Context receives one bounded optimization item with no instruction authority.

**Agent History remains actual Agent Chat conversation history.**

## Release invariants

- no new scheduler or execution authority,
- no optimization or calibration store,
- no executor mutation,
- no supervised/manual reordering,
- deterministic strategy comparison,
- v24.90 forecast authority preserved,
- v24.80 admission authority preserved,
- Phase 19 execution authority preserved,
- optimizer failure falls back to v24.90,
- Brain/Brief/Now share the same projection,
- production package includes v25.10 runtime and release authority files.
