# Gmail draft review, Sent mailbox, and shared workspace context

## Scope

This phase extends the Gmail composer delivered in PR #893. The owner needs to find agent-prepared replies after leaving the original Chat, understand why a draft exists, review its source, and revise the selected draft through ordinary Chat instructions.

The implementation remains scoped to Gmail and its shared Chat entry points. Chosen no-reply deadlines and outcome assessment are the next separate phase. The existing send ledger, explicit send action, reply watches, and sandboxed email reader remain the underlying services.

## Acceptance rubric

Each row is worth two points. Both acceptance conditions in a row must be verified for its full score. A 10/10 engineering score requires all rows plus the native database, browser, and existing Chat/Gmail regression gates. It describes the verified release scope; a connected production mailbox run is separate deployment evidence.

| Area | Acceptance conditions | Baseline before this phase |
| --- | --- | --- |
| Find pending work | Exact saved-state counts and bounded pagination; only the same owner's private Chats for the selected Agent can appear and open. | 0/2: the original list is limited to 50 drafts in the current Chat. |
| Understand a draft | Explain source/reason/priority without invented evidence; distinguish writing, ready, stale, unavailable, and terminal states. | 0/2: the original list shows subject, recipient, and basic status. |
| Preserve pending work | New organic proposals persist until disposition; manual saves, short-lived approval tokens, and existing expired proposals retain their intended authority. | 1/2: explicit saves persist, while organic proposals expire after 24 hours. |
| Review the source | Open the exact source with existing isolated HTML/CSS rendering; source reads recheck draft revision, private Chat, and Gmail grant after provider work. | 1/2: safe rendering exists, but the normal composer does not expose the original message. |
| Revise conversationally | Typed/spoken instructions bind to the selected draft version; stale/discard/late-response paths preserve newer edits and never fall through into a second send/model action. | 0/2: revisions are available through composer controls, without a selected-draft Chat command contract. |

**Baseline for this phase: 2/10.** This measures the new acceptance scope, not the quality of the already released composer.

## User experience

The Gmail specialist exposes pending draft work and opens a review queue across the selected Agent's private Chats. Each item links back to its original Chat; a queue row does not grant permission to review, edit, or send across conversations. Existing API callers that do not select an expanded queue scope remain in their current Chat.

The owner can inspect the original received email while reviewing a reply. The reader uses the existing sanitized presentation packet, script-free sandbox, plain-text fallback, and per-message remote-image controls. Presentation markup is transient and is not copied into Chat history or Agent Brain.

When a draft is selected, supported Chat instructions such as "make this draft shorter," "save this draft," and "discard this draft" use the same composer operations. The selected draft ID and edit revision are captured before asynchronous work. A failure or changed selection preserves typed instructions. Failed spoken commands report that the owner should check the editor and repeat the instruction; they do not become a typed Chat draft. An unrelated message continues through ordinary Chat.

The composer retains its popup and canvas surfaces. Dirty edits must be saved or explicitly set aside before navigation. Phone layouts use one surrounding scroll surface; source content is collapsible to keep the editor usable.

## Data and authority

- `compose_list` accepts an explicit queue scope, filter, page limit, and authenticated cursor. Default legacy callers remain scoped to their current Chat.
- Counts describe saved draft states. A pending count can include work that is writing, blocked, or needs a fresh review; it does not claim all rows are ready to send.
- Queue rows omit message bodies and approval tokens. Reopening a row obtains current authority in the original private Chat.
- New organic reply proposals carry validated durable composer metadata. Existing legacy expired proposals are not silently revived. Their history remains inspectable and they can be discarded.
- `compose_source` is read-only and requires the exact draft ID and edit revision. A different owner, Agent, Chat, grant, or revision cannot receive the source packet.
- Discard accepts an edit revision. A stale selected-draft instruction cannot remove a newer saved version; the database update also compares the persisted snapshot.
- Sending remains the existing explicit, reviewed action. These Chat instruction handlers never invoke Send or create a send approval.

## Verification record

The focused native acceptance fixture is `tests/agent-email-review-queue-v1.php`. It uses real MySQL tables and production helpers, with a fake Gmail transport and fake inference response. Its 84 assertions exercise pagination beyond the first page, cursor replay and tampering, owner/Agent/Chat separation, source reads and authority races, actual organic proposal creation, retention, expired approvals, and exact-revision discard.

The existing native composer (41), Gmail send ledger (128), and email writing (24) checks remain part of the gate. The Chat recovery fixture also covers selected-draft interception, no model fallback after rejection, preservation of typed instructions, spoken-command failure handling, fresh Chat initialization, cross-Chat navigation and load failure, and late-result isolation.

`tests/chat-email-review-queue-browser.mjs` exercises the actual composer, specialist popup, and isolated reader with mocked network boundaries. The Gmail workflow also runs the original composer, formatted reader, specialist, and inbox browser regressions. Review screenshots are retained under `email-review/` in the workflow artifact for desktop and phone inspection.

CI results and the final acceptance score belong to the pull request for this phase. A release is ready only after the required workflows pass and the generated desktop and phone screenshots have been reviewed. No real email is sent and no paid model/image generation is used by these tests.

## Workspace follow-up: visible drafts and Sent

The Gmail specialist keeps **View drafts** and its pending count at the top of the popup, beside the existing compose entry. The queue heading is **Drafts**. The original pending/all filters and private cross-Chat navigation remain available.

The specialist has **Inbox** and **Sent** tabs. Sent reads the connected Gmail mailbox, including messages sent from Gmail or another client. It does not present the local VP3 draft ledger as the entire Gmail Sent folder. Pages show bounded message previews, recipients, dates, the connected account, and the time of the read. Opening a row uses the existing isolated formatted email reader with a plain-text option. Sent browsing creates no draft, send approval, follow-up watch, or Chat model turn.

Normal inbox refreshes preserve the Sent page, selected message, and keyboard focus. They do not poll the provider's Sent mailbox. Closing the popup or changing owner, Agent, Chat, or participant context aborts pending work and clears private content. A changed mailbox binding or a fresh access rejection clears the old Sent result.

The Chat command **Show my sent emails** opens the same Sent view. In a new Chat, the canonical private conversation is initialized before opening Gmail. A separate typed message is preserved, and unsaved email changes use the existing save/discard navigation decision.

### Sent API contract

Both actions use the existing authenticated POST endpoint, `api/chat-email-v1.php`, and require the current owner key, Agent, private conversation, and CSRF token.

| Action | Additional input | Result |
| --- | --- | --- |
| `sent_list` | `limit` (default 10, maximum 20), optional `cursor` | `sent`: account, checked time, mailbox revision, bounded `items`, next cursor, and whether another page exists. |
| `sent_open` | Exact opaque `item_ref` returned by the list | Read-only `message`: sender, recipients, subject, date, plain text, and optional safe display packet. |

The provider list is constrained to the `SENT` label and excludes Spam/Trash. Every metadata/body response is checked for the expected message/thread and current Sent membership. A fixed before-time boundary carries across pages. The provider's result-size estimate is not shown as an exact total.

Message references and page cursors are encrypted, authenticated, session-bound, and scoped to the owner, Agent, Chat, connection, grant, OAuth application, and mailbox. They expire after 15 minutes. Live private-Chat and Gmail-read authority is checked before and after token refresh and provider reads. Read permission is sufficient for Sent; send permission is independent. This feature uses the existing schema.

Provider reference: [Gmail message listing](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages/list) and [exact message retrieval](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages/get).

## Shared Agent knowledge of Gmail work

`includes/agent-gmail-workspace-context-v1.php` provides one server-resolved workspace snapshot. The main Cloud Chat context, Top of Mind, and Gmail specialist consume the same projection. It contains capability availability, preferences, scan/report state, saved draft counts and bounded previews, locally verified send receipts, and reply-watch state. It performs no Gmail provider calls and no model calls.

| Evidence | Meaning and limits |
| --- | --- |
| Inbox scan/report | Saved findings and their observed time/coverage; a scan is not proof that every message in the mailbox was analyzed. |
| Draft queue | Current saved lifecycle counts for this owner's selected Agent; bounded previews omit bodies and approval tokens. |
| VP3 send receipt | A verified local send confirmation under its original private Chat and current connection; its receipt time is the time confirmation was recorded. |
| Gmail Sent view | Live provider messages fetched when requested; these are distinct from the local receipt inventory. |
| Reply/follow-up watch | Recorded watch/check/delivery state; a waiting or expired watch does not prove that nobody replied. |
| Preferences and actions | Current permission and policy state; image and writing provider readiness is checked when those actions are requested. |

The capability description explains View drafts, Sent, popup/canvas composition, manual and Agent-assisted writing, image attachments/generation, the reviewed Send flow, and existing follow-up controls. Mail subjects, source reasons, and other user content remain data rather than system instructions.

Automatic Cloud model context is limited to the current private owner Chat with the current capability snapshot and unrestricted Knowledge context. It is suppressed for participant contexts and restricted/off/project Knowledge scopes. Browser-supplied Gmail state cannot replace the server projection. Explicit Gmail tools and owner UI reads continue to use their existing independent grants.

The supported stateless HomeServer delegation route receives at most two compact summaries through its existing surface-event contract. These carry work counts, readiness, preferences, freshness, and coverage, without recipients, subjects, bodies, or the full workspace object. They are added only to the outgoing inference payload, after the canonical Chat context was captured for persistence. Restricted Knowledge and participant contexts omit them. Legacy stateful delegation is not represented as receiving this new context. No HomeServer repository change or new remote protocol is required.

The exact-message writing broker remains isolated to the original message or selected saved draft, current owner direction, and existing email Soul. Broad mailbox context is not inserted into an unrelated outgoing email.

### Additional acceptance gates

- View drafts remains visible above a long inbox report and opens the existing private queue.
- Sent shows actual mailbox messages, pages correctly, opens styled content, and preserves its selected page during inbox refresh.
- Current grant, OAuth, account, Chat, and participant changes invalidate retained mailbox content and references.
- Cloud context, Top of Mind, and the popup share verified work state with explicit coverage; unavailable data is not reported as zero work or a successful outcome.
- Supported HomeServer delegation receives only the bounded server summary, without changing saved history or broadening restricted Knowledge scope.
- Desktop/phone screenshots and existing composer, send, voice, and shared Chat regressions pass.

The added focused fixtures are `tests/agent-email-sent-v1.php`, `tests/agent-gmail-workspace-context-v1.php`, `tests/agent-gmail-homeserver-context-v1.php`, and `tests/gmail-sent-browser.mjs`. Final counts and exact-head workflow results are recorded in the pull request after validation.
