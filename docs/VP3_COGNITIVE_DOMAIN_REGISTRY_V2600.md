# VP3 Cognitive Runtime v26.00 — Cognitive Domain Registry Foundation

## Purpose

v26.00 formalizes one universal domain contract on top of the cognitive architecture already in production. It does not replace the v5.00 runtime module registry, the v23.70 domain inventory, v19.20 canonical event ingress, v24.10 attention policy, v25.90 current-state materialization, or the v25.90 presentation firewall.

The registry is a bounded validation/orchestration projection. It adds a consistent way to answer which VP3 domain owns an event, which entity references are valid, what class of event occurred, whether it may participate in current state or attention, and whether it is safe to admit into canonical event ingress.

## Authority boundaries

- Runtime module registration: cognitive-runtime-v500.php
- Domain inventory: cognitive-domain-manifest-v2370.php
- Canonical events: agent_event_inbox through v19.20
- Live session: v23.70
- Attention: v24.10
- Working context: v24.20
- Current state: v25.90
- User-facing presentation: v25.90 Presentation Firewall
- Execution/claims/leases/receipts: Phase 19

v26.00 creates no new event ledger, domain database, Brain, memory store, learner, scheduler, queue, worker, approval authority, execution authority, or current-state store.

## Domain event contract

Each declared domain identifies its authoritative records, object types, event types, source aliases, current-state grouping, and presentation requirement. Events are deterministically classified as informational, actionable, approval_required, failure_recovery, completion, or outcome.

Unknown event types and malformed entity references are quarantined before ingress. They do not enter agent_event_inbox and therefore cannot influence current state, attention, or user-facing cognition.

## Entity/reference contract

Entity references are bounded to 16 per event and use the existing v5.00 object-reference normalization. A domain may reference its own authoritative object types plus explicitly declared related object types. This prevents a plugin from silently claiming ownership of another subsystem's records.

## Campaigns & Rewards reference domain

Campaigns & Rewards is the first new domain designed against v26.00. In this release it is a reference domain contract only:

- plugin key: campaigns_rewards
- implementation status: contract_ready
- plugin catalog exposure: not yet enabled
- business-table authority: not yet created

The contract reserves merchant account/location, campaign, reward, reward claim, and campaign customer identities plus canonical event families for campaign lifecycle, landing-page activity, engagement, conversion, rewards, and claims.

CRM contacts, Team members, and Profile identities are declared as related objects. They remain owned by their existing systems and are not duplicated into Campaigns & Rewards.

The next plugin build will create the business schema and UI against this contract, register the plugin only when those surfaces actually exist, and route plugin activity through the canonical v26.00 admission path.

## Presentation boundary

A domain event may contribute only bounded, normalized evidence to current state. All user-facing domain state continues through the v25.90 allowlist Presentation Firewall. Raw payloads, prompts, tool traces, credentials, confidence vectors, retrieval internals, and model reasoning remain non-user-facing.
