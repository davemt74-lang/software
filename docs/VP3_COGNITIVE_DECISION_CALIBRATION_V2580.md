# VP3 Cognitive Runtime v25.80 — Portfolio Learning & Decision Calibration

v25.80 closes the portfolio planning loop by comparing prior numeric decisions with later canonical outcomes and applying conservative, evidence-gated calibration.

Authority:

**v25.80 Decision Calibration → v25.70 Outcome Value & ROI → v25.60 Budget Governance → v25.50 Economics → v25.40 Commitments → v25.30 Replan → v25.20 Capacity → v25.10 Optimize → v24.90 Forecast → v24.80 Admission → Phase 19 Claim/Lease/Execute/Receipt**

## This is not a second relevance learner

VP3 already has Cognitive Outcomes & Learning v5.40 and presentation calibration v23.50.

Those remain authoritative for:
- relevance learning,
- proactive presentation density,
- proposal/outcome feedback.

v25.80 does **not** create another general preference/relevance learner. It calibrates only numeric portfolio projections:

- forecast duration,
- remaining AI cost,
- remaining Cloud tokens,
- reliability of explicit value-planning pressure.

## Durable decision evidence

v25.80 adds two owner-scoped append-only tables:

- `cognitive_decision_snapshots_v2580` — what VP3 predicted/observed at a decision point,
- `cognitive_decision_settlements_v2580` — derived comparison against later canonical evidence.

These are calibration/audit evidence, not new goal, outcome, billing, usage, scheduler, worker or receipt authorities.

Snapshots record bounded fields such as:
- raw and calibrated completion windows,
- sequence rank,
- executor,
- raw and calibrated remaining AI cost/tokens,
- explicit value profile and expected value,
- budget hold state,
- reservation state,
- replan action,
- commitment protection.

No hidden chain-of-thought is stored.

## Bounded capture

Decision capture is:
- limited to the active portfolio,
- capped per refresh,
- bucketed into six-hour windows,
- fingerprinted,
- deduplicated with a unique snapshot key.

A changing decision can still create a new snapshot in the same bucket; an unchanged projection does not create endless duplicate records.

## Settlement

A decision snapshot settles only after the canonical goal review layer reports verified achievement.

The settlement derives:
- actual completion time,
- proportional goal-attributed AI cost/tokens from the canonical AI execution ledger,
- whether any actual request had unknown pricing,
- canonical/explicit realized value from v25.70,
- raw forecast error,
- calibrated forecast error,
- completion-window hit,
- cost/token projection ratios where evidence is complete,
- realized/expected value ratio,
- realized USD AI-cost ROI where both value and AI cost are valid.

Settlement does not mark a goal achieved. It consumes the existing verified achievement timestamp.

## Shared workflow attribution

Cost and token settlement reconstructs the goal's canonical objective/workflow lineage.

When workflow work is shared across goals, usage is proportionally attributed by the number of canonical goal links for the objective source hash. Shared work is not charged at 100% to every goal.

## Preventing one goal from dominating learning

v25.80 may retain several historical snapshots for one long-running goal.

Forecast, cost and token calibration factors use **only the latest settled snapshot per goal** within the rolling evidence window. One frequently observed goal therefore cannot overwhelm those samples.

Value reliability is different: for each value profile it uses the **expected value frozen in that profile's latest settled decision snapshot**, then compares it with the latest canonical v25.70 realized evidence. This means a confirmation or canonical conversion that arrives after goal completion can still improve reliability, while editing the profile later cannot rewrite what VP3 originally expected.

## Minimum evidence and bounds

No forecast/cost/token calibration factor changes behavior before at least **5 independent settled goals** provide valid evidence for that metric. Value reliability requires at least **5 independently verified value profiles with settled decision snapshots**.

A valid observed/predicted ratio of **0** is real evidence and is not discarded; the final factor is still clamped to the bounded range.

Rolling calibration window: **180 days**.

Bounds:
- forecast duration: **0.75–1.35**
- cost projection: **0.75–1.35**
- token projection: **0.75–1.35**
- value reliability: **0.70–1.15**

The median observed/predicted ratio is used, then clamped to these limits.

## Forecast calibration

v24.90 retains its raw completion window and then applies the bounded v25.80 duration factor per executor.

Both are exposed:
- raw earliest/likely/latest,
- calibrated earliest/likely/latest,
- calibration factor,
- evidence sample count.

Deadline risk uses the calibrated displayed forecast.

If v25.80 is unavailable or under-sampled, the factor is exactly 1.0 and v24.90 behavior is unchanged.

## Cost and token calibration

v25.80 captures raw projected remaining AI cost/tokens for every eligible active portfolio goal by reusing v25.60's pure projection function. This evidence capture does **not** require a budget policy or value profile and does not create either one.

v25.60 retains:
- raw projected remaining AI cost,
- raw projected remaining Cloud tokens.

With enough settled evidence, it applies bounded v25.80 factors before budget forecast pressure is evaluated.

Actual budget authority is unchanged. v25.80 never changes a configured cap, token balance, package, override or hard-policy semantics.

## Value calibration

v25.70 retains the user's expected value exactly as entered.

v25.80 never edits:
- expected money,
- expected score,
- realized value evidence.

With enough verified explicit outcomes, a bounded reliability factor scales only the **planning adjustment** produced from that expected value.

A protected commitment or hard budget hold still neutralizes value optimization exactly as before.

## Resource/replan learning

Snapshots retain reservation state and replan action so VP3 can compare those decisions with later completion accuracy.

These metrics are descriptive. v25.80 does not claim that a reservation or replan action caused an outcome and does not autonomously change capacity limits, reservation caps or execution modes from correlation alone.

## Accuracy

The Decision Calibration surface reports:
- settled-goal sample count,
- calibrated forecast window-hit rate,
- median calibrated forecast error,
- median raw forecast error,
- cost projection ratio,
- token projection ratio,
- value realization ratio,
- reservation-associated forecast error.

Historical settlement rows preserve the value evidence available when they settled. The active value-reliability factor uses the latest canonical v25.70 realization state against the immutable expected value captured in the latest settled snapshot for that value profile, so later verified value evidence is learned without retroactively changing the prediction.

This makes it possible to see whether calibration is improving the numeric planning layer.

## Failure behavior

All v25.80 integrations are bounded and fail open to prior behavior.

If the decision-calibration tables/runtime are unavailable:
- forecast factor = 1.0,
- cost factor = 1.0,
- token factor = 1.0,
- value reliability factor = 1.0.

**No already-authorized work can deadlock because decision calibration is unavailable.**

v25.80 cannot:
- approve work,
- create eligibility,
- alter executors,
- alter deadlines,
- change budgets,
- debit/refund tokens,
- claim/lease work,
- execute tools,
- write canonical goal outcomes.
