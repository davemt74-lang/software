# VP3 Cognitive Runtime v25.90 — Unified Current State & Presentation Firewall

v25.90 formalizes the missing boundary between VP3's canonical event/session evidence and what the user actually sees.

## Purpose

VP3 already has one durable event ingress in `agent_event_inbox` (v19.20), one live-session authority in `agent_live_sessions_v2370` (v23.70), broad domain event adapters (v23.80/v23.90), unified working context (v24.20), and bounded decision calibration (v25.80). v25.90 does **not create another event ledger** and does not replace any of those systems.

It adds two read-only layers:

1. **Unified Current State** — ephemeral materialization of the newest trusted/verified domain events, canonical live-session state, and the legacy activity snapshot used only as a compatibility input.
2. **Presentation Firewall** — a strict allowlist that converts current-state evidence into a compact presentation object before it reaches Agent Brief, Agent Brain, or proactive presentation.

## Ephemeral materialization

Current state reads canonical sources at request time. It does not persist a second copy of events, working memory, session state, or model reasoning.

The materializer:

- reads trusted/verified `agent_event_inbox` rows;
- keeps only bounded recent state and the latest event per domain;
- decodes event payloads only to extract a small allowlist of canonical reference fields;
- never exposes arbitrary payload text;
- reads the canonical v23.70 live-session snapshot;
- may read `agent_activity_v94_snapshot()` only as compatibility evidence;
- marks explicit failure/request/approval signals as attention candidates;
- creates no scheduler, queue, lease, worker, approval, billing, memory, or execution authority.

## Presentation Firewall

The user-facing presentation object is allowlist-only:

- `type`
- `title`
- `summary`
- `status`
- `next_action`
- `action_label`
- same-origin `href`
- bounded metadata: domain, freshness, event type, surface

Raw JSON, raw event payloads, build/debug traces, system prompts, tool traces, confidence vectors, retrieval labels, credentials, tokens, memory arrays, and arbitrary metadata are not permitted in normal v25.90 presentation output.

External links are rejected by this presentation object. Existing explicit application surfaces remain responsible for their own authorized navigation.

## Authority boundaries

v25.90 is descriptive and presentational only.

- v19.20 remains the event-ingress authority.
- v23.70 remains the live-session authority.
- v24.10 remains attention-policy authority.
- v24.20 remains unified working-context authority.
- v5.40 remains relevance-learning authority.
- v23.50 remains proactive presentation-calibration authority.
- v25.80 remains decision-calibration authority.
- Phase 19 remains claim, lease, worker, execution, and receipt authority.

The current-state materializer cannot execute, approve, claim, schedule, bill, change a budget, move a deadline, change an executor, or write domain state.

## Cross-surface behavior

Working Context receives one bounded internal current-state item. Agent Brief, Agent Brain, and Proactive Now use the same materialized current-state projection. User-facing Agent Brief output receives only the firewalled presentation object, so internal evidence and UI copy have an explicit architectural boundary.

The intended return-session behavior is concise: surface the most relevant current condition or explicit attention candidate rather than dumping a raw event report.
