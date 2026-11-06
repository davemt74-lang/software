# Mission completion proposal revisits

Upgrade baseline: main `1f351691b92b` (PR #732). Section 2B1 adds a specific Cloud Mission completion as a condition for revisiting an exact saved Agent proposal. It reuses the existing reminder, occurrence, workflow, source refresh, main Chat review and delivery paths.

The owner reviews the focus, exact server-supplied Mission reference and completion milestone before approval. Saving the follow-up neither starts nor approves the Mission. Plain “later” remains unscheduled. Ambiguous or unavailable prerequisites require clarification; arbitrary predicates and generated completion claims cannot create conditions.

A conditional commitment has no owner deadline. The existing occurrence lease provides bounded polling while the browser is closed. Failed, cancelled, deleted, replaced or revoked prerequisites remain blocked. The precise Mission configuration and immutable identity must still agree, and every action must have its actual matching completed Cloud receipt. Saved model output is explicitly identified as historical assessment, not new research.

The condition is stored on the native reminder and bound into the creating action plan and its receipts. Reminder, occurrence, backlink and both ledgers commit atomically. Wake, completion and delayed Chat delivery reread the original records and the prerequisite completion proof. Changed proof, cancellation or permission loss prevents stale delivery. Conditional commitments can be cancelled or deleted through existing tools; replacement requires cancellation and a fresh review. Time-based rescheduling cannot silently turn a condition into a deadline.

## Acceptance criteria

1. Reviewed owner, Agent, original proposal, exact Mission identity and milestone survive reload and replay.
2. Saving grants no Mission execution or original business-action approval.
3. Native commitment, occurrence, backlink and receipts commit atomically; final rejection leaves no orphan.
4. No invented date; ordinary reminder and Agent source projections distinguish a condition from an overdue timer.
5. Failed/cancelled/deleted/replaced/revoked prerequisites cannot qualify; workflow status without matching per-action receipts is insufficient.
6. Independent native tickers converge on one canonical current reassessment and one committed main Chat result.
7. Fresh original records, source grants, prerequisite configuration and completion receipts are checked at wake and delivery.
8. Cancellation during inference prevents stale delivery; System/named/foreign Agent authority remains isolated by the canonical broker and exact dependency reader.
9. Actual MySQL integration/race/rollback and real desktop/mobile Chat tests pass alongside timed-follow-up and action/source regressions.
10. Exact-head official PR checks, merge/post-merge checks and original production-artifact verification pass before release certification.

## Validation and limits

`tests/agent-initiative-followup-conditions-mysql.php` exercises real Mission preparation/review/execution receipts, conditional Chat commitments, independent ticker processes, native worker delivery, cancellation and transaction rollback. `tests/initiative-followups-e2e.mjs` covers real timed and conditional Chat journeys on desktop/mobile, with actual canonical Mission and follow-up workers and controlled inference. Deployment-specific cron, live providers and the installed account are not certified by repository tests.

Saved-report and Profile-call milestones require separate domain certification and are not advertised by this section. Product creation, marketing assets and reviewed Profile/compatible-channel promotion remain the subsequent commerce work.
