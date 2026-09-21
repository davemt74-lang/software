# VP3 Cognitive Stack v23.00–v23.30 — Backwards Code Audit & Hardening

## Scope

Audit order:

1. v23.30 — Cognitive Action Planning
2. v23.20 — Proactive Opportunity Detection
3. v23.10 — Unified Agent Priority Queue
4. v23.00 — Cognitive Operations Core

Audit criteria:

- canonical authority boundaries
- approval / execution fail-closed behavior
- bounded scans and lifecycle cleanup
- deterministic fingerprints / Hide suppression
- timestamp and ordering correctness
- duplicate-state risk
- cross-device / cross-surface consistency
- client exposure / private score boundaries
- compatibility with v5.00–v5.70 Cognitive Runtime
- compatibility with v17.2–v17.4 Agent Work controls
- regression-test coverage

## Pre-hardening scores

### v23.30 — 8.8 / 10

Strengths:
- no parallel executor or persistence
- source reauthorization
- live tool-registry lookup
- stale capability buttons already hidden
- canonical outcome verification preserved

Audit findings:
- a tool could remain in the same registry id while its owning module changed; the contract did not explicitly fail closed on module drift.
- live risk could increase after orchestration materialization without comparing the new risk to the stored handoff step.
- an accepted plan could materialize after a stricter live capability boundary appeared, rather than forcing re-review/replan.

Hardening:
- capability-module mismatch is now unavailable/fail-closed.
- newly-added approval or increased risk sets `boundary_changed`.
- boundary drift produces `replan_required`.
- v5.60 blocks materialization when the live boundary drifted.
- v5.60 rechecks risk/approval/tool compatibility immediately before handoff.
- orchestration cards hide incompatible handoff actions.

### v23.20 — 8.4 / 10

Strengths:
- deterministic relationships only
- model-inferred relationships excluded
- anchor/target reauthorization
- no new opportunity store
- bounded detection

Audit findings:
- global stale cleanup treated absence from a bounded 16-anchor / 8-detection scan as evidence that an opportunity disappeared. Valid but unscanned opportunities could be resolved.
- TTL refresh used the normal observation upsert, changing `updated_at`; because Cognitive Feed fingerprints include `updated_at`, an unchanged hidden opportunity could reappear after TTL refresh.
- relationship confidence from provider edges could carry greater precision than the observation store, causing false semantic-change refreshes.

Hardening:
- bounded absence is no longer used for stale resolution.
- cleanup resolves only objectively expired detector observations.
- scan result explicitly reports truncation and `cleanup=expired_only`.
- unchanged observations extend `valid_until` without touching `updated_at`.
- confidence is normalized through the Cognitive Runtime score contract before semantic comparison.

### v23.10 — 9.2 / 10

Strengths:
- projection over the selected 12-card Agent Now budget
- canonical v17.2 work state reused
- private ranking scores stay server-side
- queue rows focus existing Universal Cards
- no duplicate workflow store

Audit findings:
- the v17.2 queue returned presentation-formatted timestamps, and v23.10 reused them as cognitive `updated_at` / schedule fingerprint inputs.
- the feed forced UTC display formatting even when VP3 already had a user calendar timezone.
- a display timezone or localized date string could therefore affect cognitive tie-breaking/fingerprints.

Hardening:
- v17.2 now preserves raw `updated_at_utc` and `next_attempt_at_utc`.
- v23.10 uses raw UTC values for cognitive ordering/fingerprints.
- localized display strings remain presentation-only.
- queue display uses the user's existing calendar timezone when available.

### v23.00 — 10.0 / 10

No corrective defect found.

Verified:
- read-only projection only
- no second Brain/feed/queue/worker
- private rank scores removed before public projection
- client receives summary-only operations state
- no external writes or authority bypass
- existing Cognitive Feed remains the item surface

## Post-hardening target

Each layer must meet 10/10 only after the exact hardening head passes:

- PHP 8.1 syntax + contracts
- PHP 8.3 syntax + contracts
- v23.00 contract
- v23.10 contract
- v23.20 contract
- v23.30 contract
- Cognitive Runtime v5.00
- Cognitive Feed v5.30
- Learning v5.40
- Planning v5.50
- Orchestration v5.60
- Memory v5.70
- Agent Work Queue v17.2
- Work Control v17.3
- Work Dependencies v17.4
- Browser Companion Cognitive Now v21.20

## Frozen invariants

- no new database schema for v23.x
- no second event ledger
- no duplicate Brain
- no duplicate Cognitive Feed
- no duplicate workflow queue
- no new scheduler/worker
- no model-granted authority
- no automatic external writes
- no approval bypass
- no execution bypass
- handoff is never completion
- canonical outcome evidence remains required
- bounded absence is never treated as canonical deletion
- display localization cannot change cognitive identity/order
- Hide/suppression fingerprints change only for meaningful state/evidence changes
