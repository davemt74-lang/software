# VP3 Cognitive Runtime v24.50 — Proactive Follow-Through & Cross-Surface Handoff

v24.50 carries existing open work forward across VP3 surfaces without inventing another notification system or task queue.

## Architectural rule

v24.50 is a **follow-through policy and handoff projection**.

It reads open work from v24.40 and uses existing authorities for everything else:

- v24.40 — open goal/task/work continuity,
- v24.10 — attention, interruption budgets, focus/quiet behavior and central receipts,
- v24.20 — bounded authorized Working Context,
- v24.30 — turn type / response-shape policy,
- Cognitive Presentation v5.10 — Agent Chat presentation,
- Browser Companion v21.40 — notification claim, delivery, snooze, open and dismiss.

There is no new notification queue, delivery ledger, task queue, or execution authority.

## Stable follow-through identity

Each open continuity item receives a deterministic event key derived from:

- Agent namespace,
- continuity reference,
- normalized continuity state,
- whether it requires a user response,
- whether it requires approval.

The key intentionally does not change just because an underlying row timestamp changes.

That lets the existing v24.10 attention receipt and Browser Companion v21.40 delivery ledger suppress repeated alerts for the same semantic state.

A meaningful state transition creates a new key and can be surfaced again.

## Candidate states

v24.50 can represent open continuity in these states:

- Needs approval
- Waiting for user
- Repair needed
- Blocked
- Working
- Ready
- Needs planning
- Needs review
- Verifying
- Scheduled
- Tracking
- Paused
- Planned

The v24.40 source remains authoritative. When the source system closes, completes, cancels, verifies, or supersedes the work, it disappears from v24.40 and therefore disappears automatically from v24.50.

## Attention policy

Every potential interruption is evaluated by v24.10.

v24.50 does not decide that a user should be interrupted merely because work is open.

v24.10 still owns:

- interruption budget,
- quiet/focus suppression,
- repeat cooldown,
- critical bypass,
- user-response escalation,
- notification versus brief versus memory,
- voice eligibility.

Browser Companion performs the normal preview → claim → central reserve flow. v24.50 only contributes candidates.

When an older Cognitive Feed candidate has the same canonical continuity reference (for example `workflow:123` or `goal:45`), the extension candidate merger suppresses that older duplicate and keeps the v24.50 handoff candidate. Direct canonical notifications remain untouched.

## Working Context and Turn Orchestration

For the highest-value follow-through focus, v24.50 prepares a non-user proactive turn through v24.30.

That preparation assembles the ordinary v24.20 Working Context. Only a safe summary of the prepared turn is exposed:

- turn type,
- context build,
- context item count,
- context section counts,
- continuity reference,
- target surface.

The raw working-context packet and hidden model reasoning are not persisted or returned to the UI.

Proactive turn types remain the v24.30 vocabulary:

- `present_update`
- `ask_user`
- `request_approval`
- `remain_silent`

Approval and user-decision states take precedence once v24.10 allows presentation.

## Cross-surface handoff metadata

Each candidate carries:

- `continuity_ref`
- `source_surface`
- `target_surface`
- `handoff_reason`
- `handoff_status`
- `delivery_surface`
- `turn_type`

These are projection metadata only.

Examples:

- Browser follow-through needing a decision → Browser → Chat
- Meeting commitment needing review → Meetings → Chat
- Workflow approval → Chat → Chat
- Browser tracking with no user action → Browser → Browser, normally brief/memory unless attention policy escalates it

## Browser Companion

v24.50 candidates are added to the existing Browser Companion v21.40 candidate stream.

The existing extension delivery table remains the only Browser claim/delivery ledger. Stable event keys prevent duplicate delivery for the same continuity state.

Browser delivery still performs final v24.10 arbitration after claim, and releases the claim if the central attention engine denies presentation.

## Agent Chat presentation

Cognitive Presentation v5.10 includes the v24.50 focus in Agent Brief.

The user can see:

- what is being followed through,
- its state,
- source → target surface,
- the recommended turn type,
- the existing authoritative destination.

No hidden cognitive plumbing is exposed.

## While you were away

v24.50 compares open-work update timestamps to Cognitive Presentation's existing `last_meaningful_at`.

The Agent Brief can therefore summarize meaningful continuity changes since the user's last meaningful activity rather than dumping raw events.

This creates no new digest ledger. The existing persisted notification digest remains unchanged and authoritative for notification acknowledgements.

## Agent Brain

The Activity Center Open Work section uses the same v24.50 projection.

It can display:

- source → target surface,
- ready/deferred/quiet/delivered handoff state,
- cross-surface count,
- existing v24.40 work status.

This keeps Agent Brain diagnostic/presentation state aligned with actual runtime policy.

## Proactive Now

The existing v23.40 Proactive Now brief receives the v24.50 focus and counts.

It does not gain execution authority. It remains a selected-queue/proactive presentation summary.

## Privacy and authority

v24.50 stores no new content.

It does not persist:

- Working Context bodies,
- hidden model reasoning,
- browser page content,
- meeting transcripts,
- tool payloads.

Existing attention and Browser delivery ledgers persist only their existing policy/delivery metadata.

## Relationship to prior phases

- v24.00 — Memory
- v24.10 — Attention
- v24.20 — Working Context
- v24.30 — Turn Orchestration
- v24.40 — Goal & Task Continuity
- **v24.50 — Proactive Follow-Through & Cross-Surface Handoff**

The next phase can supervise long-running work for stalls, failures, dependencies, and safe replanning while keeping execution inside existing workflow authorities.
