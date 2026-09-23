# VP3 Cognitive Runtime v24.00 — Memory Promotion & Episodic Consolidation

v24.00 closes the memory gap intentionally left by v23.80 and v23.90.

The earlier phases normalized business and cross-surface events into the canonical event inbox but marked them `record_only` and `brain_promotion_deferred`. v24.00 decides what should remain a short-lived episode and what is important enough to become durable Agent Brain memory.

## Memory architecture

```
record-only domain event
        ↓
Cognitive Memory v5.70 occurrence
        ↓
deterministic promotion policy
        ↓
promotion receipt
        ↓
selected durable Agent Brain memory
```

There is no second Brain and no second event ledger.

- `agent_event_inbox` remains the event ingress.
- `cognitive_memory_occurrences_v570` remains the reference-first episodic layer.
- `cognitive_memory_promotion_receipts_v2400` records the decision and why.
- `agent_memory_items` remains the durable semantic Brain store.

## What becomes durable

High-salience terminal events can promote immediately:

- Profile Agent booking/product conversions
- completed or no-show bookings
- paid orders
- completed or failed refunds
- attributed conversions
- published Research reports
- completed tracked browser transactions

The promoted memory contains a compact semantic statement and an authorized object reference. It never copies the full event payload.

## What requires recurrence

Potential patterns do not become durable on the first occurrence. The default threshold is **3 occurrences within 30 days**.

Current recurrence-gated examples include:

- relationship risk changes
- relationship opportunity changes
- booking cancellations
- booking reschedules
- refund requests
- meaningful tracked-transaction changes

Booking/refund recurrence can span separate authoritative records. Relationship/browser recurrence remains object-specific.

## What stays episodic or suppressed

Routine state is intentionally not durable Brain memory. Examples include notification reads, ordinary messages, profile visits, calendar edits, schedule edits, media asset creation and ordinary tool completion.

Some meaningful but non-durable events remain episodic only, such as first-party referral attribution, analytics spikes, HomeServer connect/disconnect, team membership state, payment-received-before-paid, and appointment payment changes.

## Privacy and authorization

v24.00 only scans events carrying both:

- `record_only: true`
- `brain_promotion_deferred: true`

The engine revalidates the primary cognitive object reference before creating an episode or promotion. Raw message text, browser page text/URLs/fingerprints, HomeServer credentials, Knowledge/Research content, tool prompts/results and raw domain payloads are not copied into durable memory.

Concrete object IDs are not embedded directly in the Brain subject; the subject uses a short hash identity. Current source truth is still resolved from the authoritative domain when needed.

## Scope

Domain promotion writes to the owner/system Brain scope explicitly. It does not inherit whichever named Agent happens to be active when the cognitive loop runs.

## Idempotency

Every source event can receive one promotion receipt per owner/namespace. Re-running the cognitive loop cannot duplicate the same episode decision or durable promotion.

## Existing dispatched events

The legacy event-dispatch path remains unchanged for events explicitly routed through `agent_event_dispatch_v1920()`. v24.00 applies only to the deferred record-only domain events introduced by v23.80/v23.90.

## Next phase

v24.10 can now build the unified Attention Engine on top of a cleaner distinction between:

- transient signals,
- episodic context,
- recurring patterns,
- durable memory.
