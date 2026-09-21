# VP3 Cognitive Operations v23.40 — Proactive Agent Now + Agent Voice

## Purpose

v23.40 makes the existing Agent Now canvas proactively summarize the unified cognitive state and lets Agent Voice include that broader state in **persisted return briefings** without creating a second voice-delivery system.

Canonical flow:

**All Systems Listening → Cognitive Runtime → v23.10 Priority Queue → v23.20 Opportunities → v23.30 Action Plans → v23.40 Proactive Brief → Agent Now → existing Agent Voice / notification delivery**

## Agent Now proactive brief

The main Agent Now canvas receives one bounded briefing derived only from the already-selected v23.10 Priority Queue.

It may summarize:
- items needing attention
- next-up commitments/work
- priorities
- opportunities
- waiting work
- active plans/orchestration

The brief never adds hidden work and never exceeds the existing Cognitive Feed item budget.

Visual focus items are references to existing queue/card keys only. Selecting one focuses the existing card.

## Greeting

The briefing uses:
- the signed-in user's display-name first name when available
- the existing Calendar timezone when available
- morning / afternoon / evening based on that timezone

No geolocation or inferred location is used.

## Agent Voice

v23.40 does **not** create standalone voice events.

Immediate Agent Voice alerts remain entirely owned by:
- Cognitive Presentation v5.10
- canonical VP3 notifications
- Browser Companion delivery v21.40
- the existing shared notification cursor

Pure opportunities remain visual/proposal-only and cannot independently interrupt the user.

When VP3 creates an existing **return digest** after the user has been away, v23.40 may append a short privacy-safe cognitive summary before that digest is persisted. Because the digest summary is stored once, Agent Chat and Browser Companion receive the same text through the same canonical voice cursor.

The appended voice context contains counts/categories only. It does not include:
- card titles
- source object content
- meeting names
- message text
- research excerpts
- transaction details
- hidden work
- model reasoning

Example:

> While you were away, 2 items need your attention. Your Agent brief also has 1 thing next up and 1 opportunity to review.

## Dedupe / interruption policy

- no v23.40 voice ledger
- no new voice cursor
- no second cross-device delivery path
- no standalone opportunity speech
- immediate alert wording remains unchanged
- return briefing enrichment is persisted before voice delivery
- Chrome and Agent Chat continue sharing the same existing voice cursor
- active Agent Chat suppression in Browser Companion remains unchanged

## Authority

v23.40 is presentation-only.

It:
- does not create plans
- does not accept plans
- does not execute tools
- does not mutate workflows
- does not write external systems
- does not grant approval
- does not change v23.10 ranking
- does not change v23.20 detection
- does not change v23.30 capability authority

## Safety invariants

- no new database schema
- no new notification table
- no new voice-delivery table
- no automatic external writes
- no approval bypass
- no hidden extra queue items
- visual brief uses selected Agent Now items only
- voice context is aggregate/count-only
- immediate canonical voice message stays unchanged
- opportunities cannot trigger standalone speech
- persisted return digest remains the shared cross-device spoken briefing authority
