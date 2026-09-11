# Agent Scheduling Tools v4.60 — quality review

## Initial review: 8.8 / 10

The first Phase 4 pass had the right security direction—canonical scheduling engine reuse, owner-scoped booking reads, two-turn mutation confirmation, audit logging, and music Booking Agent disambiguation—but it was not ready to merge.

Findings fixed before the PR gate:

- **Named Agent schedule scope:** the first pass always used the account default schedule. The final build prefers an active schedule explicitly bound to the selected Agent, then falls back to the account default active schedule.
- **Read-side effects:** the first pass called the default-schedule helper, which can create a schedule. The final read path only queries existing schedules; asking a calendar question never creates data.
- **Per-schedule booking isolation:** list/find/cancel/reschedule now scope by both authenticated owner and selected schedule.
- **Approval principal drift:** an approved mutation now re-resolves the Agent from the owner-scoped conversation and fails closed if it differs from the Agent that prepared the action.
- **Durable approval scope:** mutations require a real Agent Chat conversation; pending state is bound to user + conversation, expires after 15 minutes, and carries an unpredictable nonce.
- **Orphan confirmations:** `Confirm booking` without a pending scheduling action is handled explicitly instead of falling through to the legacy music Booking Agent.
- **Natural-language coverage:** direct requests such as `Book Sarah tomorrow at 2 PM` can extract the guest name; ambiguous bare-hour requests still require AM/PM clarification.
- **Atomic reschedule:** owner + schedule + event boundaries are rechecked, the schedule lock spans cancel/create, failure rolls back, and lineage is preserved.
- **Bearer-token isolation:** Agent tools never read or expose the public booking or cancellation bearer tokens used by the Phase 3 guest flow.

## Final score target: 10 / 10

Award 10/10 only when the dedicated v4.60 contract, PHP syntax checks, the existing Agent Tool Authorization gate, Agent Chat boundary tests, Scheduling v4.30/v4.40/v4.50 gates, Recovery Baseline, Production Deploy Package, and the full repository PR workflow set pass on the exact final PR head.

No Phase 4 schema change is required. It operates entirely on the scheduling schema already installed through `upgrade.php` in Phase 1.
