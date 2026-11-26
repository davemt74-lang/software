# Gmail response deadlines and follow-through

This phase adds owner-chosen response deadlines to the existing Gmail composer, private draft queue, Sent view and exact thread watcher. It uses the existing Cloud worker and reviewed-send ledger. It does not change Phone or Operator behavior.

## Owner workflow

1. Open **Gmail → + New**, or open an email draft in the Chat canvas.
2. Choose **Set response deadline**, an exact date/time and timezone, and a priority. Optionally select **Prepare a follow-up draft if overdue**.
3. Save or review and send the email. The settings are part of the saved draft and its exact send approval. Response deadlines require the current Gmail read and follow-up permissions. The deadline must be in the future and within the existing thread watch's 30-day horizon.
4. Open **Sent → Follow-ups in this Chat** to check the saved outcome, change the deadline, pause/resume, or mark it resolved. **Check Gmail now** reads the exact thread and saves the result; it does not run a model in the HTTP request.
5. If the deadline passes, a successful complete thread check must establish that no matching reply was observed. The existing Cloud worker can then prepare one private draft, provided the selected Agent's Initiative policy is enabled and linked to the original private Chat.
6. The Agent announces the verified overdue result or the ready draft in that Chat. Open **View follow-up** or **Review follow-up**. Review, edit, attach images, and send through the same composer used by ordinary email drafts.
7. A confirmed follow-up send is recorded separately from completion of the matter. When reply watching is selected, the watcher advances to the newly sent message. Choose a new deadline explicitly before another automatic draft can be prepared.

Typed and web-voice commands such as **Show my email follow-ups** and **Show overdue emails** open the same Sent section. These commands do not infer a new response deadline. Dates are chosen in the visible timezone controls; skipped or repeated local clock times require an unambiguous choice.

The first release tracks sends originating in the current private VP3 Chat. Gmail Sent still reads the actual mailbox, including mail sent from other clients. An arbitrary external-client sent message does not acquire tracking authority just by appearing in Sent. The Agent's bounded Gmail workspace projection can summarize authorized tracked work across its private Chats.

## Evidence and outcomes

| Display | Required evidence |
| --- | --- |
| Waiting for reply | A saved deadline and a verified original send/watch. Waiting alone does not prove absence of a reply. |
| Overdue | The exact deadline elapsed, and a successful complete Gmail thread read verified the sent anchor and observed no matching reply. The check time is shown. |
| Needs checking | The provider read failed, was incomplete, had ambiguous linkage, or contained newer thread activity that made an automatic follow-up unsafe. |
| Reply received | An exact linked reply was observed and bound to the original private Chat. This does not automatically resolve the business matter. |
| Resolved | The owner explicitly marked the matter resolved. |
| Paused | An independent owner control that suppresses polling, drafting and notices for the tracked work. |
| Follow-up sent; waiting for reply | The canonical send ledger confirmed the follow-up. Another response deadline is required to authorize another automatic draft. |

The verifier requires the actual sent message, exact thread, account and external recipient, valid RFC linkage, valid message order and a complete provider response. Missing anchors, duplicate identifiers/critical headers, malformed timestamps, partial responses, revoked access and unavailable Gmail are not no-reply evidence. A later unmatched received message or a newer outgoing message, including one sent outside VP3, holds automatic preparation.

This establishes what was observed in that exact thread at the check time. It does not establish mailbox-wide absence, successful recipient delivery, whether the recipient read the email, or completion of the user's underlying objective.

## Durable state and authority

`agent_email_followthrough_v1` is a companion to `agent_connection_email_followups_v1`. The existing watch remains the exact owner/Agent/Chat/mailbox/verified-send binding and the scheduling authority. The companion stores the separate response deadline, source revision, control revision, provider observation, draft attempt, linked draft and announcement milestones.

- `revision` changes on owner controls and invalidates in-flight work.
- `source_revision` changes on a new response deadline. It preserves the source binding of an edited draft through pause/resume while requiring a fresh successful thread check before sending again.
- `state_version` advances on visible worker and owner changes so delayed browser reads cannot replace a more recent reply or control result.
- The response deadline, watch retention horizon, next poll, worker claim and model-attempt lease are separate values.
- Explicit deadline saves create a real generic owner request marker at the time of that user action. Background workers never create owner approval turns. One automatic draft uses that request once, enforced by the canonical draft table's unique owner/request key.
- A changed deadline does not erase an older draft or the user's edits. Its old source binding cannot authorize a send against the new deadline.
- List/status/context reads do not contact Gmail, generate text, send mail or create approvals.
- Lists use scoped signed pagination. An exact private notice can retrieve its own saved record beyond the first page without broadening mailbox or Chat access.
- Private owner, Agent, Chat, current grant and OAuth client checks are repeated across provider work and before writes. Participant sessions do not inherit this workspace.

Pause, resolution, grant changes, deleted requests, disabled owners/Agents, Chat reassignment and expired claims suppress stale provider/model results. Post-send receipt reconciliation still records a real confirmed send after a later owner control, without undoing that control or issuing another send.

## Automatic preparation and reviewed send

Automatic preparation uses `vp3_initiative_infer_v1` with background accounting and transient context. It respects the actual selected transport and current Initiative policy. The prompt contains the owner's saved deadline instruction/settings, a bounded excerpt of the exact sent message, the verified check and the owner's email Soul. It does not receive unrelated Chat history or the whole mailbox.

The persisted attempt and pre-dispatch callback permit one external inference dispatch per source revision. An uncertain attempt cannot silently dispatch again on the next five-minute poll or a transport fallback. Preparation state explains disabled policy, different linked Chat, unavailable model/source, active work and failures. An explicit new deadline action is required to authorize a new automatic attempt after a consumed failure.

The saved draft uses `outbound_followup`, a distinct source binding. The ordinary received-message reply validator continues to reject SENT parents. The follow-up recipient and subject come from the verified original outgoing message; only the body and permitted attachments are edited. Source inspection uses the existing sanitized styled-email reader.

Immediately before the first Gmail POST, the send ledger rechecks the active source revision, owner control, exact original outgoing message and complete thread. A new reply, newer outgoing email or unverifiable thread blocks dispatch. The body, attachments, source binding, recipient and watch choice participate in the review/receipt identity. A lost acknowledgement reconciles the same RFC Message-ID; it cannot create a second POST.

Automatic follow-through never sends a draft by itself. Existing unrelated Gmail Auto Send rules retain their separate authority.

## Announcements and shared awareness

The Agent posts an overdue milestone or a ready-draft milestone in the original private Chat. A ready draft can replace the initial overdue announcement when both are completed in one worker pass. Milestones are idempotent and coalesced; ordinary repeated polling does not repeat the same announcement. Existing traffic-aware Gmail spacing and priority govern competing items.

Notifications remain generic and link back to the private Chat. Exact source text remains in its authorized reader/draft. The Gmail workspace and Top of Mind project the canonical deadline outcome, verification freshness, preparation state and owner controls. The older Inbox matter attention label and a stopped/expired legacy watch are not promoted to verified no-reply evidence.

## Deployment

Install the complete Cloud deployment package and run **Database Upgrade**. The new companion table is installed through the existing Connections installer, used by both setup and upgrade. Keep the existing Cloud worker running; the work does not depend on an open browser.

The deployment package preserves the site's existing `config.php`, environment files, `private/` and `uploads/`. No secrets, test fixtures or development tools belong in that package. Its generated `VP3_RELEASE.json` identifies the exact merged commit.

## Acceptance and release gates

| Gate | Acceptance condition |
| --- | --- |
| Saved settings | Deadline, timezone, priority and automatic-draft choice survive save/reopen and invalidate old approval when changed. |
| Evidence | No overdue/draft authorization from missing, partial, ambiguous, stale or failed provider reads. |
| Scope | Current owner/Agent/private Chat/grant/client and original owner request are required at every action boundary. |
| Controls | Deadline changes, pause/resume and resolve cancel stale work while preserving saved edits and immutable send outcomes. |
| Preparation | One metered background dispatch and one review draft per explicit source revision; no synthetic owner turns or silent retry. |
| Send | Exact outbound source, fixed recipient/subject, live thread preflight, immutable receipt and no duplicate POST on reconciliation. |
| Awareness | Private Chat milestones, paced notifications and provider-free workspace/Top of Mind projections use the same canonical facts. |
| UI | Mobile and desktop composer, Sent controls, source inspection, late-response isolation and native timezone controls are verified. |
| Compatibility | Existing draft queue, Sent reader, inbound replies, watches, writing, styling, attachments and shared Chat checks stay green. |
| Release | Exact PR head checks, post-merge checks, screenshot review and deployment archive/commit/content verification pass before the phase is called complete. |

Focused tests:

- `tests/agent-email-followthrough-mysql.php`
- `tests/agent-email-followthrough-source-v1.php`
- `tests/agent-email-followthrough-draft-notice-v1.php`
- `tests/agent-gmail-followthrough-context-v1.php`
- `tests/gmail-followthrough-browser.mjs`
- `tests/agent-top-of-mind-followthrough-v1.mjs`

The Gmail workflow also runs the existing queue, Sent, workspace, HomeServer summary, composer, attachments, display, send ledger, provider/writing, private Chat recovery and browser regressions. The production packaging workflow asserts that the new server helpers and shared UI assets are present.
