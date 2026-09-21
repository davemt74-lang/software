# VP3 Cognitive Operations v23.50 — Outcome Learning & Cognitive Calibration

## Purpose

v23.50 closes the current v23.x cognitive loop without creating another learner.

Canonical loop:

**Observe → Understand → Prioritize → Suggest → Plan → Act with approval → Verify canonical outcome → Learn → Calibrate proactive presentation**

v23.50 reuses:
- Cognitive Outcomes & Learning v5.40 feedback/outcome ledgers
- v5.50 plan decisions
- v5.60 orchestration handoff decisions and canonical outcome verification
- v23.10 ranking
- v23.40 proactive Agent Now presentation

No new learning table, outcome table, recommendation identity, model-owned preference store, scheduler, worker, or execution runtime is introduced.

## Corrected decision semantics

v23.50 makes cognitive decision events explicit:

- `plan_accepted` — the user chose to review/follow a proposed plan; counts as engagement, **not execution**
- `plan_dismissed` — the user rejected a proposal; counts as a negative relevance/preference signal
- `handoff_requested` — the user explicitly authorized the existing capability handoff; counts as action
- `handoff_postponed` — the user declined the handoff at that moment; timing/preference signal only
- `plan_completed` — the accepted plan later closed from canonical successful/resolved evidence

Existing canonical outcomes remain authoritative:
- `outcome_successful`
- `outcome_resolved`
- `outcome_unsuccessful`
- `outcome_ignored`

A plan accept never becomes an execution signal until the real handoff is requested.

## Existing v5.40 relevance learning

The existing v5.40 bounded relevance learner remains authoritative for ranking adjustments.

v23.50 only improves the inputs:
- plan accepted → engagement
- plan dismissed → ignored/negative relevance
- handoff requested → action
- canonical outcomes retain the strongest learning weight in v5.40
- handoff postponed and plan completed are retained in the same reference-only event ledger for presentation calibration, but do not invent new v5.40 profile columns

Existing v5.40 bounds remain frozen:
- profile factors: 0.90–1.10
- per-candidate learned adjustment: ±8
- learning can affect Priorities, Opportunities and Recent changes only
- Needs Attention / Next Up remain deterministic

## Cognitive calibration

v23.50 adds a read-only calibration projection over the existing v5.40 feedback ledger.

Window:
- last 60 days
- current owner + Agent namespace only
- explicit reference-only event types only

Signals:
- positive: plan accepted, handoff requested, plan completed, successful/resolved outcome
- conservative: plan dismissed, handoff postponed, unsuccessful/ignored outcome

Canonical outcomes carry more influence than proposal-level choices.

Calibration does not change scores or reorder the v23.10 queue. It may only reduce proactive presentation density when recent evidence consistently indicates that fewer proactive prompts are useful.

Modes:
- **calibrating** — fewer than 5 explicit signals; preserve current behavior
- **balanced** — mixed/neutral evidence; preserve current behavior
- **active** — strong positive outcome/action evidence; preserve current maximum of 3 focus items
- **conservative** — sustained negative/dismiss/postpone evidence; reduce the v23.40 focus preview to 1 item and suppress the optional cognitive suffix on return-digest speech

Even in conservative mode:
- Needs Attention remains present in the canonical Feed/Queue
- Next Up remains deterministic
- immediate canonical notifications and Agent Voice alerts are unchanged
- no item is hidden or removed from the underlying queue
- the user can still review the complete Agent Now feed

## Explainability

The proactive brief exposes:
- calibration mode
- evidence count
- aggregate positive / conservative signal counts
- a short explanation

It does not expose:
- hidden ranking scores
- chain of thought
- source content
- message text
- meeting titles
- research excerpts
- transaction details

## Safety invariants

- no new schema
- no second learner
- no model-generated preference updates
- no automatic external writes
- no approval bypass
- no execution bypass
- plan acceptance is not execution
- handoff request is not completion
- canonical outcomes remain strongest
- deterministic attention/next-up rules cannot be learned away
- calibration cannot reorder the queue
- calibration can only preserve or reduce proactive presentation density
- calibration cannot create new voice events
