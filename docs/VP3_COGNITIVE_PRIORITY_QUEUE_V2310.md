# VP3 Cognitive Operations v23.10 — Unified Agent Priority Queue

## Purpose

v23.10 turns the v23.00 cognitive operations picture into one bounded operating queue without creating another scheduler, worker, workflow ledger, Brain, feed, notification system, or execution authority.

The queue is a **projection of the exact cards already selected by Cognitive Feed v5.30**. It therefore preserves the existing Agent Now attention budget and Universal Card authorization boundary.

Canonical flow:

**All Systems Listening → Cognitive Runtime → Agent Brain / Cognitive Feed → v23.10 Priority Queue projection → Agent Now → existing execution runtimes → verification / learning**

## Existing systems remain authoritative

- Agent Work Queue v17.2 owns durable workflow execution state.
- Agent Work Control v17.3 owns workflow pause/resume/cancel/retry/schedule/priority controls.
- Agent Work Dependencies v17.4 owns workflow blockers/dependencies/delegation.
- Phase 19 remains the scheduler/claim/lease/retry/receipt execution engine.
- Cognitive Feed v5.30 owns the bounded Agent Now item set.
- Agent Brain remains the cross-system cognitive priority context.
- Universal Cards remain the object rendering and reauthorization boundary.
- Cognitive Planning/Orchestration remain proposal/follow-through authorities.
- Domain systems remain factual authorities.

v23.10 writes none of those states.

## Unified lanes

Every currently selected Cognitive Feed item is projected into exactly one queue lane:

1. **Needs Attention**
   - approval waits
   - blocked workflows
   - failed/retrying workflows
   - existing Cognitive Feed attention items

2. **Next Up**
   - scheduled workflows
   - meetings
   - calendar commitments
   - existing Cognitive Feed next-up items

3. **Priorities**
   - executing/active workflows
   - goals
   - Brain priorities
   - accepted plans/orchestration
   - existing Cognitive Feed priorities

4. **Opportunities**
   - evidence-backed opportunities
   - proposed plans
   - recommendations

5. **Waiting**
   - paused workflows
   - deliberately deferred work represented by an existing card

6. **Recent Changes**
   - recently completed workflow cards when selected
   - current Cognitive Feed recent-change items

## Workflow integration

The Cognitive Feed workflow provider now reads the canonical v17.2 Work Queue model when available.

That adds the real workflow lanes to cognition:
- active
- approval
- paused
- blocked
- scheduled
- failed/retry
- completed

The provider still emits ordinary `workflow` Universal Card requests. v23.10 does not invent a new workflow object or copy workflow state into a new table.

Workflow candidate ranking is deterministic and uses:
- queue lane urgency
- existing canonical `work_priority`
- current workflow state/progress

No LLM controls workflow ordering. No client receives the internal numeric queue score.

## Queue ordering

The Priority Queue uses a deterministic tuple:

1. lane precedence
2. attention state
3. existing Cognitive Feed score
4. canonical workflow priority when present
5. source update time

The numeric values are server-private. The client receives only the resulting order.

Workflow timestamps have two separate contracts: raw UTC database timestamps drive cognitive ordering/fingerprints, while localized strings are presentation-only and use the user's calendar timezone. A timezone/display change therefore cannot reorder work or create a false cognitive-state change.

## Client contract

The queue appears inside the existing Agent Now canvas above the existing Universal Card sections.

Maximum queue rows: 12.

Every row points to an item already present in the same bounded Cognitive Feed response. Selecting a row scrolls/focuses the existing card. The queue therefore does not create a second item surface, does not render hidden extra work, and cannot bypass the existing 12-item attention budget.

Public queue row fields:
- key
- lane
- lane label
- title
- status
- reason
- source
- authority
- attention
- updated_at

No internal score, prompt, raw context, tool payload, credential, model reasoning, or hidden workflow state is exposed.

## Safety invariants

- no database schema
- no queue persistence
- no new scheduler or worker
- no automatic workflow mutation
- no automatic external writes
- no model-granted authority
- no approval bypass
- no execution bypass
- no duplicate Agent Brain
- no duplicate Cognitive Feed
- queue rows must correspond to currently selected authorized cards
- Universal Card rendering remains the detailed object surface
- workflow controls continue through v17.3/v17.4/Phase 19
- hidden Cognitive Feed items remain hidden from the queue
- workflow cognitive ordering/fingerprints use raw canonical UTC timestamps
- localized workflow time strings are presentation-only
