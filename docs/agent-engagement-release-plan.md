# Agent engagement release plan

Starting main: `ffb30844a875bda26f85897e476f38755f5d6edb` (PR #725). Main remains the upgrade baseline. The Agent core starts through accepted ordinary Chat activity; optional Knowledge and sensing source choices are not core activation switches.

Conversational follow-through is built on the revalidated `main` after the feed-only merge `9ed47a7323aaf70a7a4429b83ef70613b1eeeb75` (PR #726).

The existing Initiative workflow, authorized domain executors, receipts, saved Chat and cognitive feedback remain canonical. Each section must pass its acceptance checks, independent review, all applicable official GitHub checks, exact-head merge verification, post-merge workflows and original production-artifact verification. A score applies to that defined section and evidence, not to every aspect of the installed application.

## Section 1: conversation, decision, work and outcome

Purpose: connect a saved proactive post to the owner's natural reply, a durable decision, permitted continuation and a verified result in the same Chat.

1. The reply anchor is the exact owned saved Initiative message, corroborated by its real workflow/action/receipt and source revisions. Message prose alone cannot grant authority.
2. Selecting Reply binds that message, revision, owner, Agent and conversation in the existing composer for typed and voice paths, on desktop and mobile.
3. Natural replies can discuss, advance permitted private work, defer, decline or close the conversation item. Ambiguity is clarified; unrelated ordinary requests retain their existing behavior.
4. Existing pending task/navigation/payment/media/domain confirmations cannot be consumed by an unrelated Initiative reply. Accepting a discussion never supplies blanket approval for writes, spending, publication, calls or outbound messages.
5. Durable state lives with the canonical saved post and workflow events. The original completed preparation remains completed; continuations use the existing queue and receipts.
6. A requested private continuation can actually execute, produce a verified result, and return to its exact Chat. Queued, failed, withheld and completed states remain distinct.
7. Duplicate requests, simultaneous tabs and lost responses cannot start the same continuation twice. Stale proposal revisions and conflicting replies cannot overwrite current decisions.
8. Current account, participant, Agent, conversation, source and policy authority is checked before effects, after generation, at delivery and before replay. No database transaction spans model or network inference.
9. Recent authorized engagement decisions/outcomes reach planning and check-ins, without treating silence as disinterest or generating new work from the Agent's own repeated reports.
10. Real endpoint, browser and native database race tests pass; exact-head PR and post-merge checks are officially green; the original deploy ZIP matches the merged tree and manifest.

Status: implementation in progress. Score is pending acceptance evidence. Installed-site behavior beyond the owner's observed status is not claimed verified.

The flow uses ordinary Chat's automatic Agent core and the existing composer. Explicit Pause and individual source permissions remain authoritative. A private continuation prepares work using its verified original sources; domain actions retain their own permission and confirmation contracts.

Interrupted-request recovery requires a trusted saved request anchor. A process killed between saving the owner turn and saving that anchor leaves an uncertain request that fails closed; retrying it creates no replacement turn, inference or job. Completed-work retries preserve the original historical acknowledgment and project the verified current outcome without rewriting history or executing work again.

The **Top of Mind** feed introduced in PR #726 adds live work status inside the floating Agent's existing Brain view. It reads canonical work records and shows authorized objectives, actual progress, waiting states and verified outcomes. Its acceptance criteria and evidence limits are documented in `docs/agent-top-of-mind-release.md`. Optional Brain memory permissions remain separate from the core work feed. Conversational follow-through continues in this section and was not included in the feed-only revision. The follow-through release also corrects the feed deadline acceptance fixture: the held request must belong to the current Brain view after its initial projection settles; an obsolete view is correctly cancelled. Final certification uses the corrected exact-head and post-merge checks.

## Section 2: shared capability adapters

Connect existing foreground executors to the same planner through a declared capability hierarchy. Each adapter defines source selection, read/private preparation/review/action class, authorization, compute, cost and retention requirements, current-source revalidation, canonical execution receipt, and the next owner decision. Start with bounded reads and useful private preparation; consequential actions retain their current confirmation contracts. Calendar and Mission context already participate and should not be rebuilt as isolated engagement engines.

The first audited candidates are Office → Transcripts → read/saved analysis, Missions → completed evidence/private analysis, and Commerce → owned products/media references/private content preparation. Missing or stale transcript analysis must be observable as a situation; new analysis still uses its explicit domain request and processing policy. Product-to-media associations require semantic source revisions and exact named-Agent permissions, and metadata does not prove unseen image contents or missing promotion.

Connected Sites' existing private-answer redaction applies before any background persistence. A future ephemeral-only adapter must keep remote answers and derived private content out of workflow goals/results, receipts, Brain and replay caches, or obtain an explicit processing and retention contract first. Exposing connection metadata is not evidence that a remote business was queried or assessed.

## Section 3: developing situation and engagement memory

Reuse the existing situation, memory and cognitive feedback records for related work, active goals, unresolved questions, explicit preferences, accepted/deferred/declined proposals and actual outcomes. Feed authorized summaries back into planning. Distinguish observations from learned preferences and preserve account/Agent/source isolation, decay, correction and deletion.

## Section 4: broader useful account work

Incrementally connect transcript and call follow-through, product-to-content/image/campaign relationships, connected-business activity and permitted media interests. Work from real current relationships; do not infer missing promotion or configuration from absent linkage. Preserve ordinary account setup, tasks, calendar, Missions and Profile attention as peers rather than making meeting readiness the backbone.

## Section 5: coordinated specialists and delivery

Reuse existing Missions, specialist work and the image specialist rail. Choose concurrent independent work within cost/capacity and dependency limits, retain real progress and cancellation, and consolidate verified results into main Chat. Mobile progress stays in Chat. Work selection, silent text and spoken interruption remain separate decisions; no new catch-up dashboard or mandatory activation gate.

## Section 6: installed runtime proof

Trace the installed account's actual source observation, assessment, selected work, execution receipt, delivery, owner response and outcome. Verify closed-browser scheduling through the real configured worker, provider/account readiness, failures and bounded recovery. Repository code and an original verified ZIP cannot by themselves establish hosting cron or a particular installed account's live configuration.
