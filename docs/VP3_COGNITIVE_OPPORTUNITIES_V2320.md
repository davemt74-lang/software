# VP3 Cognitive Operations v23.20 — Proactive Opportunity Detection

## Purpose

v23.20 adds evidence-backed cross-system opportunity detection to the existing VP3 cognitive loop.

It does **not** let an LLM invent opportunities. It does not create a new opportunity database, feed, queue, scheduler, worker, action runtime, or approval path.

Canonical flow:

**All Systems Listening → canonical subsystem state / relationships → deterministic opportunity detectors → Cognitive Runtime observation → Cognitive Feed / v23.10 queue → v5.50 proposal-only planning → existing execution authority → verification / learning**

## Opportunity truth model

An opportunity exists only when VP3 can point to structured, currently authorized evidence.

v23.20 uses:
- the exact object already represented by a current Cognitive Feed source candidate; and
- an explicit Cognitive Runtime relationship to another authorized object.

Relationship evidence must be:
- deterministic or user-confirmed;
- confidence >= 0.80;
- unexpired;
- readable by the current user/Agent namespace.

Model-inferred relationships are deliberately excluded from automatic opportunity creation.

## Initial deterministic patterns

### Prepare with related context

Anchor:
- meeting
- meeting brief

Related evidence:
- Research
- Knowledge
- CRM/contact/profile context
- transcription context

Result:
- opportunity to review related evidence before the meeting.

### Apply supporting evidence

Anchor:
- active goal
- active workflow

Related evidence:
- Research
- Knowledge

Result:
- opportunity to use already-authorized evidence to advance current work.

### Coordinate work with a commitment

Anchor:
- active goal
- active workflow

Related evidence:
- meeting
- calendar object

Result:
- opportunity to coordinate active work with the explicitly related commitment.

These patterns are conservative by design. v23.20 does not perform fuzzy name matching, page-text matching, semantic entity guessing, or model-generated relationship discovery.

## Existing Cognitive Runtime persistence

Detected opportunities are stored as normal `cognitive_observations_v500` rows:

- `category=opportunity`
- deterministic observation key derived from pattern + exact evidence refs
- bounded title/reason
- structured evidence refs only
- `source=cognitive_opportunity_v2320`
- no proposed tool/action IDs
- no raw page content
- no model reasoning

No v23.20 table is added.

The detector avoids rewriting an unchanged active observation on every feed refresh, so:
- `updated_at` does not churn;
- existing hide/suppression fingerprints remain stable;
- duplicate plans are not created.

When exact supporting evidence disappears from the current opportunity set, v23.20 resolves its own prior active observation instead of leaving a stale opportunity active.

## Planning and execution

The existing v5.50 planner sees the normal opportunity card and may create an **evaluate_opportunity** proactive plan.

That plan remains proposal-only.

v23.20:
- never registers an execution tool;
- never accepts a plan;
- never executes a plan;
- never writes to Calendar, Meetings, CRM, Research, Knowledge, workflow state, Browser Runtime, transactions, email, messaging, or external services.

All consequential work continues through existing VP3 authority and approval boundaries.

## Presentation

Opportunity observations enter the existing Cognitive Feed Opportunities lane and the existing v23.10 Unified Agent Priority Queue.

They are subject to:
- Cognitive Runtime presentation arbitration;
- Cognitive Feed 12-item attention budget;
- v5.40 learning/suppression;
- Hide / Show hidden behavior;
- Universal Card authorization;
- Browser Companion Now's same canonical feed lineage.

No separate opportunities dashboard is introduced.

## Safety invariants

- no LLM opportunity generation
- no fuzzy entity matching
- no model-inferred relationships used for automatic detection
- no new database table
- no new scheduler/worker/queue
- no automatic external writes
- no approval bypass
- no execution bypass
- exact evidence refs reauthorized before storage
- opportunity observation does not copy source object content
- unchanged detections do not churn fingerprints
- stale detector-owned opportunities resolve
- v23.00 and v23.10 remain authoritative integration/presentation layers
