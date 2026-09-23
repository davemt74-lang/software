# VP3 Cognitive Runtime v23.80 — Domain Integration I

v23.80 connects the highest-value operational domains to the canonical event/session/cognitive foundation established in v23.70.

## Architecture

The phase keeps each subsystem authoritative for its own records:

```
Profile Agent / Scheduling / Booking / Calendar / Commerce / CRM / Notifications
                              ↓
                    agent_event_inbox
                              ↓
                  live-session projection
                              ↓
                 Cognitive Runtime modules
```

There is no second event ledger, Brain, CRM, calendar, booking store, commerce store, or notification system.

Domain events are **record-only** in this phase. They use `agent_event_ingest_v1920()` and are marked processed without invoking the legacy automatic Brain observation path. Selective episodic/durable-memory promotion remains a v24.00 responsibility.

## Integrated domains

### Profile Agent

Central `profile_event_create()` instrumentation emits normalized events for profile visits, return visits, Profile Agent conversations, booking/product intent, and booking/product conversion. Only owner-scoped references and compact outcome metadata are recorded.

### Scheduling, bookings, appointments

Schedule creation/update, availability changes, booking creation/cancellation, appointment lifecycle transitions and reschedules feed normalized schedule/booking/appointment events.

### Calendar

Canonical user-calendar create/update/cancel mutations emit owner-scoped calendar events. Derived temporal signals such as starting/conflict/overdue remain available for later Attention Engine evaluation without creating another calendar store.

### Commerce

The canonical commerce audit ledger is the primary lifecycle seam for order/payment/refund events. Product create/update is instrumented at the canonical product upsert path. Existing Universal Cards continue to own `product` and `commerce_order` object types; v23.80 does not double-register them.

### CRM / relationship intelligence

The owner-scoped Agent CRM relationship calculation now emits normalized opportunity changes. Human/admin CRM tables are not silently joined into personal cognition without a clear ownership boundary.

### Notifications

Notification creation/read state emits normalized events so notifications can become inputs to the future unified Attention Engine rather than an independent proactive authority.

## Live session projection

When the user has an open v23.70 live session, meaningful domain events update `last_external_event` and the compact recent-action projection. Background domain events do not create fake user activity, resume an idle session, or inflate active interaction time.

## Privacy and memory rules

- Event payloads contain compact state and object references, not full source records.
- Raw event payloads are not user-facing.
- Profile visitor/browser secrets are not copied into cognitive context.
- Guest email, phone, booking tokens, payment provider secrets, and metadata blobs are excluded from domain context projection.
- Normal page browsing remains ephemeral.
- Domain events do not automatically become durable Brain memories.

## Compatibility

v23.80 is additive. Existing mutation paths, cards, notifications, scheduling, Profile Agent, calendar, commerce and relationship intelligence remain operational. The integration is guarded with `function_exists()` so the domain subsystems remain usable during staged deployments.

## Release contract

The release gate verifies that v23.70 is present, canonical event infrastructure is available, all Domain Integration I bridges are loaded, the Cognitive Runtime modules are registered, and no second storage authority was introduced.
