# Email Specialist build outline

Email owns an open matter from the first relevant message through the verified outcome. The Main Agent coordinates the conversation. Calendar, Tasks, Research, and other specialists can take assignments and return results to the same matter.

## Existing VP3 foundation

| Area | Available before this build | Email loop work |
| --- | --- | --- |
| Inbox and follow-up | Gmail inbox checkpoints, header review, saved batches, message receipts, and reply watches run through the Cloud worker. | Connect new messages, relevant sent activity, and reply-watch results to a durable matter. Track the latest completed scan independently from the latest batch with messages. |
| Conversation | Chat supports exact-message reads, new drafts, threaded replies, and verified sends. | Bind proactive offers and later conversational turns to the exact authorized message. Add body and thread assessment, user corrections, and contextual next actions. |
| Jobs and approval | The job engine records claims, heartbeats, dependencies, retries, and receipts. Existing email, Calendar, and Tasks handlers enforce scoped authority and review. | Save the reason Email is waiting, the next event or time that will wake it, and the outcome each delegated specialist returned. |
| User settings | Agent Brain can edit and reset a private Main Agent Soul. The Cloud package excludes Markdown files. | Give Main and Email independent histories. Make the packaged Email default and its runtime loader verifiable in a Cloud deployment. |
| User surfaces | Top of Mind shows inbox monitoring status and links to a saved review. Chat can surface updates. | Show one Email view with the current scan, important changes, available actions, and work that remains open. Record the underlying decisions and receipts in History. |

See the [inbox worker](../includes/agent-connection-gmail-inbox-v1.php), [reply watches](../includes/agent-connection-email-followup-v1.php), [Cloud worker](../agent-worker-v1910.php), [email Chat actions](../includes/agent-connection-email-chat-v1.php), [job engine](../includes/agent-job-engine-v1900.php), [Agent Brain](../includes/agent-brain-v82.php), [Top of Mind projection](../includes/agent-top-of-mind-v1.php), and [deployment packaging](../.github/workflows/production-deploy-package.yml).

## First implementation

The initial build adds private Main and Email Soul revisions, uses the active Email Soul for model-written drafts, and records the revision with the draft. The editor previews saved text. It also adds exact urgent offers in private Chat, accepts spoken or typed responses, and reads the selected message through local device speech when explicitly requested.

The draft writing preference release adds an explicit **Remember this writing preference** action after an owner saves wording changes to a generated draft. A single bounded suggestion is reviewed and edited by the owner before it is appended to the existing Email Soul. Gmail-local writing preferences provide editing, removal, saved-version previews, restore and reset. This works in the shared desktop composer and connected Phone Agent review. Ordinary draft saves do not invoke learning, and style changes do not alter existing email content or send approval. See [Gmail draft writing preferences](GMAIL-DRAFT-PREFERENCES-V1.txt) for the scope, deployment and acceptance gates.

The Gmail worker records completed scan totals and open thread matters under the current mailbox grant. Canonical sends and matched reply watches advance those matters. Top of Mind projects the resulting state and supports snooze, dismiss, resolve, and reopen. Snooze sets a matter's next review time. Existing Inbox Review batches and reply-watch notices still use their own delivery policies. An explicit Gmail cursor restart keeps the open matters for the same grant and discards any incomplete count. The new matter tables are installed through Setup and Database Upgrade.

## Inbox triage implementation

- Enable **Classify and prioritize inbox mail** under Gmail Inbox Review. The existing header review continues to work with its existing consent. With triage enabled, the worker groups routine mail and reads the bodies of a bounded set of candidates, such as a likely deadline or a reply to an open matter. The current assessment uses local rules for explicit English-language requests, deadlines, and changes in linked replies. It stores the reason and exact source reference. Unclear or unavailable evidence stays visible for review.
- Top of Mind shows new-mail counts, actionable items, and inspectable groups. A correction applies to one message unless the user explicitly saves a rule for that sender. Chat receives actionable changes and keeps routine groups and historical catch-up in Top of Mind. Read a message in private Chat through its exact source.
- Optional catch-up covers up to the last seven days. An expired cursor also starts a bounded recovery window. Both preserve prior receipts and open matters and show incomplete coverage. A valid Gmail page token lets the user continue in Connections. The worker checks tracked threads for a reply sent directly in Gmail or a message archived there. A verified reply closes the draft offer for that exact inbound and advances the matter. Archive records the mailbox state independently.
- Before an approved reply goes out, the worker compares its exact inbound anchor with the current Gmail thread. A newer inbound returns the draft to review. The user can open that message and approve the revised draft. A reply arriving after the check can still race with Gmail's separate send request. If a send might already have gone out, the worker reconciles its Gmail receipt before attempting delivery again.

The worker acceptance fixture contains 23 promotions, a renewal deadline in the body, and a reply that asks to reschedule an open delivery. It verifies two body reads, one Chat update with the two actionable changes, deduplication on replay, quiet expired-cursor recovery, and zero new messages on the next complete empty scan. Browser fixtures cover group review, correction, and private Chat. The production worker and Gmail behavior still need a connected test mailbox run.

Editable Soul revisions already affect drafted text. Conversational preference learning, per-specialist interruption controls, and Calendar, Tasks, and Research assignments remain part of the wider Email loop.

Enable **Analyze email content for the running report** after Database Upgrade to add Cloud AI analysis to the existing triage. The worker queues uncertain or actionable messages and revisits analyzed threads when more mail arrives. It reads bounded excerpts, considers remembered sender preferences, and saves one current source-linked finding per thread. The expanded Gmail workspace shows the report with the importance, next step, and any question for the owner. Gmail can forward selected open findings to Needs attention or Agent work in Top of Mind. Routine mail stays available in triage groups. Turning off content analysis clears its pending work and saved findings.

## Souls and preferences

- Give each account a private Main Agent Soul and a private Soul for each enabled specialist. Start Main with the user's existing Soul when available and start Email with its shipped role default. Add other specialist roles as their runtimes adopt this contract.
- In Agent Brain, let the owner edit the active Soul, browse versions, restore an older version, or reset to the role default. Save, restore, and reset each append a revision. Show a clear conflict if a second session saves over the same starting version.
- Inherit account preferences such as tone, interruption policy, and quiet hours. Display the effective settings and their sources. A specialist can have an explicit preference for its own role. Use a sample email to preview priority, phrasing, and suggested action without creating a mailbox action.
- Use the active Soul for each new assessment and record its role and revision in the run. Keep matter state and reviewed actions keyed to their existing evidence and receipts. Apply live permission and grant checks when work resumes.
- Accept corrections conversationally. “Skip this email” applies to the selected matter. “Always tell me about emails from the General” can save an account-private sender preference after resolving the sender. Ask about scope when the user could mean either. Track durable preferences separately from a one-time disposition.

## The loop

1. **Observe.** Advance the authorized Gmail checkpoint through bounded pages. Record stable message identifiers once and count eligible new mail since the last completed scan. Relevant sent activity, replies, user instructions, due times, and specialist results can all wake an existing matter.
2. **Understand.** Read the current authorized body and relevant thread context when deciding what a message needs. Track the goal, request, deadline, current owner, and evidence that will establish success. Subject signals and sender preferences help prioritize. A model response proposes an interpretation and action for the runtime to validate. Store the exact source reference and concise findings needed to resume.
3. **Advance.** Take the smallest useful next step. Offer an exact read, save a draft, ask a focused clarification, request a review, or delegate scoped work. The parent matter keeps ownership while a specialist works and links the assignment to the originating message. VP3 checks the current owner, connection, grant, scope, and action policy.
4. **Verify.** Record the specific result at each milestone. A saved draft, a send verified by Gmail, an incoming reply, a calendar event, an invitation result, and a resolution each carry their own evidence. A partial result leaves the outstanding parts visible. If a call may have succeeded before the worker stopped, reconcile its stable operation identity against the provider or the destination record before another attempt.
5. **Wait or resolve.** Save who or what the matter is waiting for, the next relevant event or check, and a follow-up when warranted. Bound retries and model work per wake. When a reply or result arrives, compare it with the original goal and the latest evidence. Resolve on a verified outcome or an explicit user decision. Keep closure history when a material change reopens the matter.

### Conversational path

> **Agent** · David, you have an urgent email from the General. Want me to open it and read it to you?
>
> **David** · Yes.
>
> **Agent** · He needs you to confirm the delivery time by 3 PM. Want me to draft a reply?
>
> **David** · Tell him 2 PM works.
>
> **Agent** · I drafted “2 PM works for me. I’ll be ready then.” Want me to send it?

An affirmative applies to one current offer with an exact source in that conversation. Clarify if two offers compete or the conversation moved on. At review and execution, resolve the current thread, destination, and requested operation again. A newer message that changes the request, time, or recipients returns the matter to review with the relevant change explained. The existing send approval flow governs delivery.

Calendar receives an exact thread and time evidence. It distinguishes a due date, a proposed hold, and an invitation. It checks availability as needed and returns the relevant draft or reviewed action. A verified event save and each invitation outcome are recorded separately. Tasks and Research return their own scoped receipts and evidence through the same assignment relationship.

### Bulk classification

Give each message independent labels. A personal-interest newsletter may be marketing from a business and need no action. A purchase receipt from the same sender may affect a work deadline.

| Dimension | Starting labels and use |
| --- | --- |
| Context | Personal, business, personal interest, mixed, unknown. User labels and prior corrections establish account-specific context. |
| Purpose | Conversation, request, meeting, receipt or transaction, update, newsletter, promotion or marketing, other. Allow multiple labels when useful. |
| Sender | Individual, small business, larger organization, automated service, unknown. Use verified contacts and account rules when available. |
| Attention | Act soon, review, waiting, read later, informational, unknown. Compute at the matter level using commitments, deadlines, replies, watches, and user preferences. |

Start with existing Gmail categories, owner rules, sender patterns, and thread state. Group repeatable low-attention messages and give the user one count and a way to inspect the group. Use bounded body or thread analysis for unclear messages and evidence of a change that may require action. Keep a source and confidence for consequential decisions. Surface an actual deadline, active thread reply, or account-specific exception even when the batch is mostly promotions.

Allow users to correct one classification or save a reusable rule after choosing its scope. Keep classification decisions internal to VP3 unless the user separately chooses an authorized Gmail label or mailbox action.

Evaluate classification on mixed mailboxes with heavy promotional volume. Track missed actionable messages, routine messages that caused an interruption, counts after replays, and the share of messages that needed model analysis. Include sender exceptions and owner corrections. A low-attention label still leaves the message discoverable in the review.

### What each surface shows

| Surface | Projection |
| --- | --- |
| Top of Mind | One Email Specialist view with scan time, eligible new count, important changes, useful next actions, waiting party, and next check. A successful empty scan shows zero new messages and keeps open matters visible. Let the user snooze, dismiss, or reopen an exact matter. |
| Specialist rail and mobile | Keep Email accessible while enabled. Indicate actual scan, assessment, drafting, or delegation activity. Distinguish scheduled, waiting, paused, and worker unavailable. |
| Chat | Group new notices and surface relevant replies, review-ready actions, verified outcomes, and changes to the next step. Use one notice identity per meaningful change across restarts. |
| Voice | Apply account and specialist interruption preferences, quiet hours, active Chat availability, and Agent Voice settings. Open with a short offer. Read contents when the user asks. Preserve the same source and conversation binding as text. |
| History and Brain | History shows the source, decision, reviewed action, Soul revision, receipt, and status changes. Brain carries useful user decisions, commitments, and durable preferences into later assessments. Keep unnecessary message body text out of diagnostic events. |

Top of Mind, Chat, and voice have separate delivery preferences. Pausing delivery and pausing Email work are separate controls so a user can keep the specialist working quietly.

## Published practice and production evidence

The loop follows published production patterns for tool-driven agents. Anthropic recommends checking tool results as work progresses, pausing at human checkpoints, and setting stopping conditions. OpenAI recommends layered guardrails, approval where appropriate, and escalation after bounded failures. It also recommends workflow-level evaluation of actual tool choices and handoffs. [Anthropic guidance](https://www.anthropic.com/research/building-effective-agents) · [OpenAI agent guide](https://openai.com/business/guides-and-resources/a-practical-guide-to-building-ai-agents/) · [OpenAI workflow evaluations](https://developers.openai.com/api/docs/guides/agent-evals)

LangGraph describes persisting a workflow across human interruptions and making external effects idempotent on replay. VP3 already has durable leases and receipts. Email needs to show that the same matter resumes after a restart, stale claim, approval pause, or uncertain provider result. [LangGraph interrupts](https://docs.langchain.com/oss/python/langgraph/interrupts)

Google specifies a full synchronization when Gmail rejects an expired history cursor with HTTP 404. Triage recovers through a bounded inbox window and preserves message deduplication, existing open matters, and the last reliable scan indicator. Mail outside that window may need an explicit review. Header-only review retains its manual restart behavior. [Gmail synchronization](https://developers.google.com/workspace/gmail/api/guides/sync)

These sources provide design guidance and provider behavior. Production readiness comes from verified scenarios and observed behavior after deployment. An unfamiliar outcome stays unresolved with a visible reason and the available next step.

## Acceptance scenarios

| Scenario | Expected evidence |
| --- | --- |
| Clean scan, burst of new mail, repeated page, or duplicate delivery event | Counts reflect each eligible message once. Replays leave one matter and one notice per meaningful change. A partial scan has its own status. A complete empty scan shows zero. |
| Expired Gmail cursor, provider timeout, rate limit, or worker outage | Resume from a valid checkpoint or run a deduplicated resync. Preserve the last successful timestamp. Record retry time and expose a clear blocked state if the attempt budget is exhausted. |
| Urgent, routine, or ambiguous mail | Priority is grounded in authorized source content and applicable preferences. Routine mail can stay in Top of Mind. Ask a focused question when the next action is unclear. |
| A large promotional batch contains one active commitment or transactional deadline | Group the routine items, preserve exact counts, surface the exception with its evidence, and spend inference only where it can change the decision. |
| One sender sends both marketing and important account updates, or the sender type is unclear | Classify each message on its own evidence and use unknown where warranted. A sender-type label alone cannot suppress a follow-up. |
| User corrects a group, changes a standing rule, or resets the Email Soul | The selected scope changes future decisions. Open commitments, source links, and the reasons for earlier decisions remain traceable. |
| Body unavailable, message removed, or email content requests unrelated access | Record the missing or untrusted evidence. Restrict decisions to the actual source and current authority. Keep the matter available for review when useful. |
| User answers “yes” to an exact alert, returns later, or has two offers | The current unambiguous offer opens the matching message. A stale or ambiguous reference asks for clarification and exposes no unrelated message. |
| User corrects a decision, quiet hours begin, or another device is speaking | Apply the exact correction scope. Enforce interruption settings and issue at most one spoken offer for the same change. Retain the authorized Top of Mind view. |
| Two accounts edit Souls, a stale tab saves, or a user restores an older version | Each account and specialist reads its own settings and history. A stale edit gets a conflict. Restore creates a recoverable revision, and the next assessment uses it. |
| New reply arrives after a draft or changes the participants | Show the material change. Verify the exact target and reviewed content again before delivery. Preserve a single matter. |
| User approves, edits, declines, snoozes, or revokes access | Execute the matching current approval once. A changed plan gets its own review. Record the owner's decision and next wake. Revocation prevents source access and further actions. |
| Send succeeds, is rejected, or loses its acknowledgment | Save the verified Gmail receipt for success. Show a known rejection clearly. Reconcile uncertain delivery using the operation identity and show uncertainty until verified. |
| Time or timezone is unclear, the calendar is busy, or a specialist is unavailable | Surface the missing fact, conflict, or blocked capability and offer the next authorized step. Keep the originating matter open. |
| Calendar or Tasks completes, fails, or returns a partial result | Link the assignment, domain receipts, and remaining work. A saved event and invitation delivery are independently verifiable. |
| Reply arrives, deadline passes, or the user acts in Gmail | Reopen or advance based on the new evidence. Recognize verified external activity. Distinguish a completed send, a received reply, and satisfaction of the original goal. |
| Worker stops during execution or two workers claim the same work | Resume the exact durable matter. One effective action and notice are recorded. History reflects the retry or recovered receipt. |
| Model output is invalid, context is insufficient, or an outcome is unfamiliar | Validate the proposed action. Ask for context or record an unresolved state after bounded recovery. Keep the evidence and owner-visible next step. |

## Build sequence

1. Add private versioned Main and Email Souls and a persisted matter backed by existing Gmail sources. Prove that an urgent notice opens the correct mail in Chat and that a saved user change affects the next assessment.
2. Connect verified inbox, send, reply, and delegated action outcomes to the matter. Exercise an urgent alert through body assessment, user correction, review, verified send or delegated result, follow-up, and resolution. Interrupt the run and restore a Soul version during the scenario.
3. Project scan and matter status into Top of Mind, Chat, History, Brain, and the specialist rail. Exercise empty scans, mute and snooze, voice preferences, and restart deduplication.
4. Verify real Cloud worker check-ins and provider recovery in a test mailbox. Run the scenario matrix with reproducible fixtures and record the expected and observed result for each. Evaluate the next specialist against its existing loop and the same ownership and receipt expectations.
