# Durable proposal revisits

Upgrade baseline: main `55817a8041d90f40e2de66e959577122266213cc` (reviewed Agent actions, PR #731).

Section 2A connects an explicit scheduled revisit to its original saved proposal. It reuses the reminder ledger, workflow ledger, owner-scoped Agent policy, worker, current source readers and main Chat delivery. It adds no parallel scheduler or core enable switch.

## Behavior

- Plain “later” remains an unscheduled deferral.
- A timed revisit uses actual bound owner turns to clarify missing date, time or timezone. It presents exact normalized details before approval.
- Reminder, original-proposal backlink and canonical action receipt commit together. Rescheduling and cancellation preserve the original provenance and use the same reminder.
- A single nullable, uniquely indexed `followup_action_id` on the existing reminder points to the creating workflow action. Its immutable goal and receipt already hold the complete owner/Agent/conversation/proposal/request binding. Timer and occurrence state remain solely in the reminder tables.
- The cloud worker ticks reminders before selecting owners, so a closed browser does not prevent preparation. Concurrent tickers converge on one existing workflow per current occurrence/context. The existing occurrence lease bounds reassessment polling to once per minute so blocked/preparing items yield worker capacity to other due work.
- At wake, the original item IDs are re-read under current authority. Closed tasks can be recognized as completed. Changed records cause a fresh assessment; removed/revoked records withhold old content. Live camera/microphone observations cannot silently become durable capture grants.
- Preparation uses structured current facts and a natural Agent response. Scheduling never approves the original business action.
- The existing Chat delivery/presence controls present the result when appropriate. One committed Chat message owns the occurrence; generic reminder text, notification and speech paths exclude it. Fulfilled revisits remain in history without occupying open attention or the active reminder capacity limit.
- Cancel/reschedule, policy/Agent/account changes and stale source results are checked again before inference and delivery. Failed or unavailable follow-ups remain visible as limited worker status and canonical workflow history; they are not reported as successful.

## Acceptance criteria

Each criterion is required; release certification requires all ten.

1. Immutable original owner/Agent/Chat/proposal/request provenance survives normal reminder progress and reload.
2. Missing/ambiguous timing clarifies through actual saved replies; plain defer creates no schedule.
3. A schedule is reported saved only after the native reminder and real workflow receipt commit.
4. Native reminder, server backlink, proposal state and receipt are atomic; rollback leaves no orphan.
5. Duplicate replies/tickers and lost acknowledgments cannot duplicate the commitment or delivery.
6. Reschedule/cancellation and changes during generation prevent stale work or delivery.
7. Fresh current reads handle changed/completed records; permission loss, deleted sources and foreign Agents disclose no old content.
8. Desktop/mobile use the same main Chat review and delivery, including natural timing answers and retiring old approvals.
9. Actual MySQL concurrency, closed-browser preparation and real browser interaction tests pass alongside existing regressions.
10. Exact-head GitHub checks, merge/post-merge checks and original production-package verification pass before release.

## Validation

Focused suites: `agent-initiative-followups-v1.php`, `agent-initiative-followups-mysql.php`, and `initiative-followups-e2e.mjs`. Existing action, reminder, source, HTTP/streaming, Chat and workflow suites remain required. The screenshot workflow budget is increased to 20 minutes because two observed font-package downloads consumed the former 10-minute budget before tests started; no assertions are removed.

## Subsequent work

Section 2B binds Mission/call conditions to exact canonical dependency and completion receipts, with failed/cancelled/deleted prerequisites remaining blocked. The next product work connects private product creation, commercial completion, marketing assets, reviewed publication and verified Profile/compatible-channel promotion. Neither is claimed as part of this time-based revisit release.
