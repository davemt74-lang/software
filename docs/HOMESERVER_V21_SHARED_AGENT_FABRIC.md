# VP3 Cloud / HomeServer v2.1 — Shared Agent Fabric & Connection Cognition

## Shared release

VP3 Cloud HomeServer integration and the Windows HomeServer application share product release **2.1**.

Internal protocol identifiers such as `homeserver-https-pair-v1300.php`, `homeserver-https-poll-v1300.php`, and `homeserver-cloud-pairing-v1200.php` remain compatibility identifiers and are not renamed just to match the product release.

## One logical Agent environment

A paired VP3 Cloud + HomeServer account exposes one logical Agent context while preserving authoritative data ownership.

The federated v2.1 exchange covers:

- Agent Brain / memory
- Knowledge
- Contacts, including human/profile relationships and Agent CRM relationships
- Tasks and commitments
- Notifications
- HomeServer capability and connection state

Cloud and HomeServer exchange bounded, versioned snapshots through the existing authenticated outbound HTTPS session. Neither side bulk-overwrites the other's native tables.

## Connection state belongs to Agent Brain

Every material HomeServer connection transition is projected into the existing canonical cognitive event system. Normal heartbeats are deduplicated.

When HomeServer moves to `connection_error` or `disconnected`:

1. The transition is stored in the HomeServer Agent event ledger.
2. The canonical cognition bridge receives `homeserver.disconnected`.
3. Last round-trip verification is invalidated.
4. VP3 creates a `homeserver_needs_attention` notification.
5. Existing Agent Chat attention polling recognizes that notification and relays it as a priority user message.

Agent Chat itself refreshes HomeServer cognition during its attention poll, so disconnect detection is not dependent on the Settings screen being open.

When HomeServer reconnects, the state transition is also recorded in Agent Brain.

## Proven round trip

The Cloud Settings **Test Connection** control invokes authenticated `system.ping`.

A successful test records request ID, timestamp and measured latency and displays a Cloud → HomeServer → Cloud verified state.

## Shared data retrieval

Owner Agent Chat uses the HomeServer snapshot as additional Agent Brain context, grouped by source dataset and retaining HomeServer provenance.

HomeServer receives a Cloud-owned mirror for its local Agent, so the local Agent can reason across the user's approved Cloud memory, knowledge, contacts, tasks and notifications without those records becoming native HomeServer rows.

## Profile Agent boundary

Profile Agent uses the same HomeServer exchange but remains governed by `user_data_policy_can_use_v236()`.

The four new owner-controlled policy resources are:

- `homeserver_memory`
- `homeserver_knowledge`
- `homeserver_contacts`
- `homeserver_tasks`

All default to Profile Agent denied because the existing policy default has `profile_agent_allowed=false`. The owner must explicitly allow a resource, and viewer/audience rules still apply. HomeServer notifications are not exposed to Profile Agent.

## Packaging

Matching release artifacts are:

- `homeserver-v2.1.zip`
- `vp3-cloud-v2.1.zip`

HomeServer continues to use the exact verified PyInstaller + Inno Setup packaging architecture from v2.0.
