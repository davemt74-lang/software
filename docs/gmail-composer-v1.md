# Gmail composer and formatted email display

## User experience

The Gmail specialist popup has a **+** button to compose a new email and a **Drafts** button to reopen work saved in the current private Chat. The composer can move between a popup and the main chat canvas without rebuilding the email or losing edits.

The owner can enter a recipient, subject, and message; ask the Email Specialist to draft or revise the text; attach uploaded or generated images; save the draft; and send the exact reviewed version. Empty fields may be saved. Sending requires a valid recipient and complete content. The editor uses the connected Gmail account as the sender.

**Save draft saves privately in VP3 in the original Chat.** This release does not synchronize the draft to Gmail's native Drafts folder or request additional OAuth scopes. Explicitly saved drafts remain available until discarded. Existing short-lived agent proposals retain their old expiry until the owner saves them.

Commands such as **Compose email**, **Write a new email**, and **New email** open a saved composer in the chat canvas. Requests with an exact recipient and writing directions queue the existing metered Email Specialist. Live agent-generated `email_review` actions use the same composer. Background draft arrivals open the canvas only in the current private Chat when the owner is not already writing or using another canvas. Reopening Chat history does not open old drafts automatically.

Reply proposals preserve the recipient, subject, and exact original Gmail thread. The owner can edit or revise the reply and attach images. The existing newer-message and already-answered checks still run before sending.

## Images

Upload and generation reuse the private Media Library and existing image job infrastructure. The composer can also attach an existing owned image. Each email accepts up to five JPEG, PNG, or WebP images, with a combined limit of 10 MiB. Removing an attachment from the draft keeps the underlying media asset.

Attachments are actual MIME attachments. The frozen draft records each asset's owner-validated ID, filename, MIME type, byte count, and content hash. The sender verifies the file again before the first provider request. A changed or missing file requires the owner to update the draft. Receipt reconciliation after a possible send does not require the original file to remain present.

## Received email formatting

Explicit private message reads can return a presentation packet containing sanitized HTML and CSS. The reader preserves supported layout, tables, headings, colors, spacing, and links in an isolated iframe. The **Plain text** control restores the existing text representation. **Load remote images** applies only to the open email; initial display makes no sender-controlled network requests.

Email scripts, forms, embedded documents, external stylesheets, and CSS network requests are excluded. The iframe independently enforces a restrictive sandbox and content security policy. Parent code sizes it to its content so the surrounding reader supplies the scrolling surface. Links keep their safe destination and existing eligible link-open receipts. Images referenced only by MIME `cid:` attachments are not resolved by this reader; ordinary remote and bounded raster data images are supported. Oversized HTML falls back to text.

Presentation markup is transient browser output. Default provider reads for analysis remain text-only, and formatted content is not saved into general Chat history or Agent Brain.

## Runtime contract

`api/chat-email-v1.php` adds `compose_create`, `compose_list`, `compose_save`, and `compose_generate`. Existing review, revise, queued writing, confirmation, discard, and reconciliation actions remain supported. The same draft table and encrypted payload are reused, so this release adds no schema migration.

Creation is idempotent within owner, agent, and conversation and records a real generic user-message anchor. All changes are checked against the current draft revision and Gmail grant. `edit_revision` is a stable opaque fingerprint; short-lived review tokens can refresh without discarding matching unsaved edits. Explicitly saved drafts use validated composer metadata and `expires_at=0`; approval tokens still expire after ten minutes.

AI new-mail composition uses a persisted write lease, the active Email Soul, conversation-service accounting, and an immediate dispatch guard. It sees only saved draft text and the owner's directions. Generation does not choose recipients, send mail, or receive image bytes. The owner reviews the result through the normal send contract.

## Verification

The `Gmail Composer and Styled Email` workflow runs native MySQL draft/send scenarios, attachment/MIME cases, HTML/CSS sanitization, AI accounting/isolation regression, desktop and phone browser interactions, and the existing Gmail specialist and inbox reader suites. Browser screenshots are retained with the workflow run.

The feature is developed on a Gmail-specific branch. It does not modify phone/operator modules, their database schemas, or their workflows. Shared Chat integration is limited to loading the new surfaces, switching to the email canvas, and rendering explicitly authorized email results.

Provider references: [Gmail message creation and sending](https://developers.google.com/workspace/gmail/api/guides/sending), [Gmail draft creation scopes](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.drafts/create).
