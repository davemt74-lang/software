# VP3 Cognitive Runtime v23.70 — Unified Live Session & Event Foundation

## Purpose

v23.70 consolidates VP3's existing Agent activity, durable event infrastructure, Agent Brain, Cognitive Runtime, Profile Agent, scheduling, commerce, CRM, meetings, Browser Companion, Knowledge, workflows, messaging, Studio, HomeServer and analytics into one architecture.

It does **not** create a second Agent Chat, second Brain, second feed, or second execution authority.

The canonical flow is:

```
VP3 domain systems
  → canonical durable events
  → live session state
  → authorized object references / relationships
  → working state + existing Brain / History
  → attention / opportunity evaluation
  → one proactive orchestrator
  → presentation boundary
  → existing Agent Chat / voice / notifications
```

## Invariants

1. Domain systems remain authoritative for their own records.
2. Live session state is a materialized projection, not a replacement for domain records.
3. Low-level activity/session events are recorded without automatic durable Brain promotion.
4. Brain promotion remains selective.
5. Raw event payloads, internal context, retrieval evidence, build identifiers and diagnostic state never become ordinary Agent Chat prose.
6. Browser page context remains ephemeral unless the user explicitly saves/acts on it.
7. Physical/HomeServer actions remain approval-governed.
8. Cognitive state never bypasses existing tool, permission, approval or execution boundaries.
9. Notifications are inputs to attention; they are not an independent cognitive authority.
10. One final orchestration/presentation path decides what reaches the user.

## v23.70 scope

- Reuse `agent_event_inbox` as the canonical durable event ledger.
- Promote Agent Event Infrastructure into the main bootstrap / upgrade path.
- Extend existing `agent_activity_state` / `agent_activity_events` with a durable live-session projection.
- Track session start, resume, end, surface transitions, idle/paused/active segments, accumulated durations and recent actions.
- Record meaningful Agent Chat actions into the durable event ledger without auto-promoting every activity event to Brain.
- Maintain a compact current session snapshot for later Cognitive State composition.
- Harden the Agent Chat presentation boundary so internal context can never be rendered as a source list or normal answer.
- Add a machine-readable integration manifest covering all major VP3 domains.

## Live-session model

`agent_live_sessions_v2370` stores one durable session projection with:

- session public ID
- owner and Agent namespace
- started / resumed / last activity / last heartbeat / ended timestamps
- active / idle / paused accumulated seconds
- interaction and resume counts
- current surface, context, conversation and focus references
- recent user / Agent / tool / browser / external actions
- compact JSON state for Knowledge, voice, browser, meeting, approvals, awaiting responses and active opportunities

`agent_live_session_segments_v2370` stores bounded temporal segments:

- active
- idle
- paused

Segments are closed on state/surface/context transitions. Long gaps start a new session rather than keeping an old session open forever.

## Event policy

The existing `agent_event_inbox` remains the only durable event ledger.

High-value domain events may be dispatched to registered handlers / workflow routing.

Low-level session events use a record-only path:

- stored and deduplicated
- marked processed
- available to History / diagnostics / later cognitive aggregation
- **not** automatically written as durable Brain memories

This prevents session heartbeats and idle transitions from polluting long-term memory.

## Domain integration inventory

The machine-readable source of truth is:

`includes/cognitive-domain-manifest-v2370.php`

It explicitly includes:

- session/activity
- Agent Chat
- Agent Brain / History
- Profile Agent
- scheduling
- bookings / appointments
- calendar
- products / orders / sales / refunds
- CRM / relationship intelligence
- meetings
- Browser Companion / browser transactions
- Knowledge / Research / annotations / claims
- workflows / tools / approvals
- team / messaging
- media / Studio / transcription
- HomeServer
- analytics / attribution
- notifications
- subscriptions / billing
- client release operations

No later phase should introduce a new cognitive silo for one of these domains.

## Presentation boundary

Internal evidence may be supplied to synthesis, but it must not be shown directly.

Forbidden normal-chat output includes:

- `Active cross-surface Agent context`
- `DATA ONLY`
- raw Agent Brain / History records
- confidence-ranked memory arrays
- task lifecycle dumps
- retrieved-history dumps
- raw JSON context
- internal build IDs
- system/retrieval/tool trace labels

If a remote answer violates the boundary, Agent Chat discards it and falls back to a safe user-facing response.

Internal sources are also excluded from the public source list.

## Next phases

- **v23.80 — Domain Integration I:** Profile Agent, booking, scheduling, calendar, appointments, products, orders/sales, CRM and notifications.
- **v23.90 — Domain Integration II:** Browser, Research, Knowledge, workflows/tools/approvals, teams/messages, Studio/transcription, HomeServer, analytics, subscriptions and release operations.
- **v24.00 — Entity Graph + Working Memory**
- **v24.10 — Unified Attention Engine**
- **v24.20 — Proactive Orchestrator**
- **v24.30 — Brain / History / diagnostics integration**
- **v24.40 — End-to-end Cognitive Agent hardening**

## Release gate

v23.70 is complete only when:

- integration manifest covers every domain above
- event infrastructure is part of normal setup/upgrade
- session schema installs cleanly
- activity transitions update live-session state
- Chat records user/Agent actions
- internal sources cannot leak through Chat answer or source rendering
- all applicable CI is green
- PR is merged
- merged main passes post-merge validation
- production deploy package is generated from exact merged main
