# VP3 Cognitive Runtime v25.70 — Outcome Value & ROI Optimization

v25.70 gives VP3 an explicit value side of the portfolio equation without allowing the model to invent monetary value.

Authority:

**v25.70 Outcome Value & ROI → v25.60 Budget Governance → v25.50 Economics → v25.40 Commitments → v25.30 Replan → v25.20 Capacity → v25.10 Optimize → v24.90 Forecast → v24.80 Admission → Phase 19 Claim/Lease/Execute/Receipt**

## Explicit value only

VP3 never converts priority, progress, urgency, model confidence or semantic importance into dollars.

A value profile exists only when the user explicitly creates one. Profiles can be scoped to:

- goal,
- workflow,
- project/workflow source key,
- Agent,
- meeting.

A profile is either:

- **money** — explicit expected value in one declared currency, or
- **score** — an explicit neutral 0–100 outcome value score.

No default value profile is created.

## Durable value evidence, not a second revenue ledger

v25.70 adds:

- `cognitive_value_profiles_v2570` — mutable user value configuration,
- `cognitive_value_events_v2570` — append-only explicit user realized-value confirmations/revocations.

These tables do not replace Profile revenue, commerce, billing or AI cost ledgers.

Canonical Profile booking/product revenue remains in `profile_events` and Profile Revenue Intelligence v1.80.

## Realization modes

### Manual confirmation

The user explicitly confirms the realized money or outcome score. The confirmation is append-only and may later be revoked with another event.

### Verified completion

Available only for goal, workflow and meeting scopes.

- goal verification comes from Phase 17.14's verified goal achievement,
- workflow verification comes from canonical objective verification,
- meeting verification requires the canonical Phase 18.20 follow-through closures for that meeting to be verified.

When the user explicitly chooses this mode, the declared expected value becomes a **declared completion value** after canonical verification. It is never labeled as revenue.

### Profile conversion

A monetary value profile can be explicitly linked to an existing Profile conversion target key.

v25.70 reads booking/product conversion events from the canonical `profile_events` ledger after the profile baseline and sums only matching-currency values.

This is canonical business evidence, not model inference.

## No hidden FX

VP3's AI cost estimate is USD.

Direct monetary ROI is calculated only when the declared/realized value is also USD and the relevant AI cost is fully known.

A non-USD monetary value remains visible but is not divided by USD AI cost. v25.70 performs no foreign-exchange conversion.

## Unknown cost

If any historical AI request linked to a goal has unknown pricing, direct expected/realized ROI is withheld instead of calculating from the known-cost subset.

Unknown cost does not become zero.

## Inherited value

An exact goal value profile is preferred.

If there is no goal profile, v25.70 may inherit an explicit value profile from exactly one linked:

1. workflow,
2. project/source key,
3. Agent.

If more than one matching profile exists at a precedence level, inheritance is marked ambiguous and no value planning adjustment is applied.

No model chooses between conflicting value definitions.

## Value-aware planning

v25.70 creates a bounded `value_planning_adjustment` for eligible autonomous work.

- score profiles use the explicit 0–100 score,
- USD monetary profiles use expected value relative to fully known estimated AI cost,
- manual/supervised work is neutral,
- a hard v25.60 budget hold is neutral,
- a protected v25.40 commitment is neutral,
- ambiguous value inheritance is neutral.

The adjustment can affect v25.30 relative replanning and v25.20 reservation scoring.

It cannot make an ineligible goal eligible and it cannot bypass a budget hold or commitment.

## Value at risk

An unrealized explicit value is marked at risk when the associated active goal has material execution risk such as:

- hard budget hold,
- protected commitment risk,
- approval/user gate,
- dependency/blocking state,
- repair/plan issue,
- urgent/overdue commitment deadline,
- another existing portfolio hold.

The value definition remains visible even when work is held.

## ROI semantics

For USD monetary profiles with fully known estimated AI cost:

`ROI % = (explicit expected or verified realized value - attributed AI cost) / attributed AI cost × 100`

This is **AI-cost ROI**, not full business-profit ROI. It does not include payroll, COGS, advertising, taxes or other costs unless those become canonical inputs in a future phase.

A zero AI cost is shown as a zero-cost outcome rather than an infinite numeric ROI.

Score profiles never masquerade as dollars.

## Calibration

v25.70 reports a median realized/expected ratio from verified explicit value profiles separately for money and scores.

Calibration is advisory only in v25.70. It does not rewrite the user's expected value.

That evidence is intended for v25.80 Portfolio Learning & Decision Calibration.

## User controls

`/outcome-value.php` provides:

- value-profile creation/update,
- scope selection,
- money or score value,
- realization mode,
- canonical Profile target linking,
- explicit realized-value confirmation/revocation,
- verified outcome state,
- expected/realized ROI when valid,
- value-at-risk review,
- calibration,
- append-only value evidence history.

## Presentation

Agent Brain gains **Outcome Value & ROI** showing explicit expected value, verified realized value, estimated AI cost, ROI, value at risk and calibration.

Agent Brief, Proactive Now and bounded Working Context consume the same projection.

Agent History remains canonical Agent Chat conversation history.

## Failure behavior

If v25.70 is unavailable, v25.60/v25.50/v25.40/v25.30/v25.20/v24.80 and Phase 19 retain their previous behavior.

**No already-authorized work can deadlock because value/ROI projection is unavailable.**
