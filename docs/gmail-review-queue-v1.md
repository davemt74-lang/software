# Gmail draft review queue and source-aware review

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
