# VP3 Browser Companion v22.30

Chrome Manifest V3 companion for VP3 Browser Share and the Source Feed layer: This Page, Following, source/user follows, comments, read state, Private/Team/Public publishing, plus highlighted text, screenshot regions, source-media moments, and voice commentary.

## v22.30 Browser Research Agent

v22.30 turns the bounded v22.20 multi-site runtime into an evidence-aware research workspace without adding unrestricted scraping or a second Research database.

The research loop is:

**Research goal → approved source plan → gather → structured extraction → compare → conflict/corroboration → gaps → draft Research save / Agent handoff**

Authority and execution:

- v21.90 remains the outer delegation authority. Browser Research must be explicitly selected as an allowed action.
- v22.00 remains the canonical Browser Agent Runtime and workflow timeline.
- v22.20 remains the cross-site transport and approved-domain envelope. A Research mission cannot add a domain that was not already delegated.
- At mission start, the active Agent may prioritize and explain a source plan, but the server filters that plan back to the exact approved-domain set before the mission is created.
- Browser Research is a canonical user checkpoint in the existing Agent Workflow. It does not create a parallel automation authority system.

Transient extraction:

- Chrome captures a bounded readable-text snapshot from the active approved page only when the user runs Analyze current page.
- The snapshot is sent directly by the service worker for that extraction call; it is not stored in sidepanel state.
- Planning and extraction use a non-persistent Agent protocol turn. The machine instruction, raw model JSON, and ephemeral Browser source URL are not written into Agent Chat history; v22.30 writes back only clean mission summaries.
- The server validates the SHA-256 fingerprint of the transient full page URL and re-checks the approved domain policy.
- Page text is passed through the existing ephemeral Browser Context → Agent Chat path. Browser Context is removed from persisted Agent message context by the existing Agent Chat runtime.
- The Agent is instructed to return a strict structured payload with observed claims, normalized values, short evidence excerpts, directness, freshness/as-of dates, source kind, and explicit research gaps.

Durable mission evidence:

- Mission storage does not contain raw page URLs, raw page text, browser history, cookies, credentials, tab titles, or selectors.
- Each analyzed page stores approved domain, page fingerprint, content hash, duplicate-group hash, optional canonical VP3 Source/version references, source kind, and freshness date.
- Each claim stores a stable claim key, observed statement/value, evidence state, independent support count, primary-source count, direct-evidence count, and freshest dated evidence.
- Evidence excerpts are bounded to 700 characters and retain domain, page fingerprint, directness, source type, and as-of date.
- Direct evidence is server-grounded against the transient readable page text; if the Agent returns an excerpt that is not present on the page, v22.30 downgrades it to inferred rather than counting it as direct evidence.
- If an analyzed page does not resolve to a canonical VP3 Source/version, the mission records an explicit fingerprint-only citation gap instead of pretending the evidence is canonically pinned.

Evidence states are deterministic descriptions, not truth scores:

- **Single source** — one non-duplicate evidence group supports the observed value.
- **Corroborated** — the same observed value appears in at least two non-duplicate evidence groups.
- **Conflicted** — different observed values exist for the same normalized claim key.
- Primary-source, directness, corroboration, and freshness are shown as factual signals. v22.30 does not compute an overall truth score or silently choose a winner.
- Freshness is descriptive only. A newer source is not automatically treated as more correct.

Duplicate and conflict behavior:

- Pages with the same content hash share a duplicate group and do not count as independent corroboration.
- Different values for the same claim key remain separate variants and are marked conflicted.
- Conflict notifications use generic copy and do not speak the conflicting values through Agent Voice.
- Research gaps are retained explicitly so the mission can show what remains unestablished.

Research persistence:

- A mission can run without immediately choosing a Research project.
- When collection authority ends, an active mission becomes Ready: analysis stops, but the already-collected structured evidence can still be explicitly saved as draft Research.
- Save draft to Research is an explicit user action and requires Research/Knowledge write capability.
- Saving creates draft canonical Research Findings and a draft Research Report.
- Saving Findings, evidence links, Report items and the mission completion marker is atomic inside one database transaction; a failed save rolls back rather than leaving a partially materialized Research draft.
- When a page resolves to a canonical VP3 Source/version, the corresponding Research source item is linked as evidence.
- Supporting source items use Research support evidence links; alternate conflicting source variants use conflict links.
- v22.30 does not auto-publish Findings or Reports.

Agent follow-through:

- Open research chat returns to the canonical Agent Chat conversation used for the extraction mission.
- Draft Knowledge, Draft CRM enrichment, and Draft follow-up task only prefill an Agent Chat prompt. They do not save Knowledge, modify CRM, create Tasks, send messages, or commit external actions.
- Saved Research projects already render through the existing Research cognitive-card path in Agent Chat.

Bounded mission defaults and hard limits:

- up to 5 approved source domains
- up to 12 analyzed pages
- up to 60 structured claims
- up to 120 minutes, never beyond the underlying Browser Runtime expiry
- one active Research mission per Browser Runtime

v22.30 remains research-oriented. Consequential submissions, sends, bookings, purchases, publishing, destructive changes, and similar external commitments stay outside this phase.

## v22.20 Multi-Site Workflow Automation

v22.20 extends the controlled v22.10 Web interaction layer into a bounded **multi-site runtime** without turning Browser Companion into unrestricted browser automation.

The cross-site loop is:

**Goal → Approved domain → Observe / Act / Verify → Handoff permit → Approved domain → Continue → VP3 checkpoint → Complete**

Authority and runtime model:

- **v21.90 remains authoritative.** Domains, actions, risk budget, maximum steps and expiration are still the outer delegation envelope.
- **v22.00 remains the canonical runtime.** Multi-site activity is attached to the same Agent Workflow and runtime timeline.
- **v22.10 remains the interaction primitive.** Same-domain page controls still use Controlled Web Interaction. Crossing domains is a separate v22.20 handoff primitive.
- **Multi-site handoff is explicit delegation authority.** It must be selected when the job is approved, and the delegation must contain at least two approved domains.
- **A canonical multi-site checkpoint is added to the Agent Workflow.** Cross-site work happens while that checkpoint is active, then the user marks the checkpoint complete.

Cross-domain controls:

- Approved domains are limited to the existing v21.90 domain list.
- Every cross-domain move receives a short-lived, one-time server permit.
- Raw destination URLs remain local in Chrome; VP3 stores URL fingerprints, source/target domains and verification status.
- Same-approved-domain redirects are allowed and recorded as verified redirects; redirects to any unapproved domain fail closed.
- Runtime-owned tabs may be reused for their approved domain. Browser Companion does not close unrelated user tabs.
- Popups opened from runtime-owned tabs are watched; unapproved destinations are stopped and runtime-owned popups are closed.
- Chrome may use the user's existing signed-in browser session, but v22.20 does not read or persist cookies, passwords, MFA codes, access tokens or session tokens.
- Site access still requires Chrome host permission for the approved destination.

Per-domain policy:

- Each approved domain has a **Browse**, **Delegated**, or **Blocked** runtime policy.
- The policy can only narrow or restore actions already inside the original delegation envelope; it cannot add a new skill or domain.
- Secondary domains begin in Browse mode. Promoting one to Delegated is an explicit user action.
- Browse mode permits only the approved low-risk browse/handoff skills.
- Delegated mode permits the subset of Web skills already approved globally for the job.

Runtime coordination:

- Cross-domain handoffs, tab references, domain visits and download observations are attached to the canonical Browser Runtime.
- Server tab records use opaque hashes plus approved domains; they do not store URLs or titles.
- Runtime-owned external tabs are tracked separately from the v22.00 VP3-object tab references.
- Handoff, tab and interaction counts are bounded from the approved delegation and hard-capped.
- The Runtime Map shows current domain, approved domain policies, visit counts, handoff receipts, active runtime tabs, structured facts and download receipts.

Structured task facts:

- v22.20 can hold small, explicit task facts such as company names, identifiers, dates, prices or status values with the approved source domain and page fingerprint.
- Facts are **task-scoped**, expire with runtime authority and are removed when the runtime closes.
- Credentials, authentication secrets, payment/account data and health/medical data are blocked from structured fact storage.
- When two approved sources provide different values for the same fact key, both are marked as a **conflict** rather than silently choosing one.
- Conflicts use the existing proactive notification pipeline, so Agent Voice may announce that source data needs review without speaking the conflicting values.

Download awareness:

- Chrome observes downloads only while a multi-site runtime is active.
- The server stores source domain, file extension, MIME type, byte count and a filename fingerprint—not the file URL or raw filename.
- Browser Companion never executes downloaded files.

v22.20 intentionally does not add credential capture, login/MFA bypass, silent domain expansion, arbitrary JavaScript, generalized browsing-history collection or uncontrolled cross-site form submission.

## v22.10 Controlled Web Interaction Runtime

v22.10 extends the v22.00 Browser Agent Runtime with a **controlled semantic interaction layer** for the currently approved web page.

The interaction loop is:

**Observe controls → Resolve semantic target → Preview → Authorize → Act → Verify → Recover / Continue**

The v21.90 delegation still defines authority. Web interaction skills must be explicitly included in the approved action scope, the active domain must be in the approved domain list, the interaction must fit the approved risk budget, and the runtime must still be active.

Registered interaction skills:

- **Click control**
- **Focus field**
- **Type into field**
- **Clear field**
- **Select option**
- **Toggle checkbox/radio**
- **Scroll to control**
- **Open same-domain link**
- **Submit form**

Safety and privacy boundaries:

- Chrome never accepts arbitrary JavaScript, arbitrary CSS selectors, or a user-supplied script payload.
- DOM observation is bounded to semantic interactive controls such as buttons, links, inputs, selects and accessible role-based controls.
- Element handles are ephemeral Chrome-side keys. Durable receipts store only hashes/fingerprints, control kind, action, domain, risk, checkpoint state and verification result.
- Raw DOM, page text, selectors and typed values are not persisted to VP3.
- Type/select/toggle operations require the delegation's **medium** risk budget.
- Password, payment-card, security-code, authentication-token, banking and medical/health fields stay manual and are blocked from runtime mutation.
- Submit, send, publish, delete, purchase, booking, transfer, account-change, sharing and similar consequential controls are upgraded to explicit checkpoints.
- Web interaction permits are one-time and short-lived.
- Every interaction is rechecked server-side immediately before Chrome claims its execution permit.
- Chrome independently rechecks sensitive/dangerous semantics before changing the page.
- Stale controls are reacquired by semantic fingerprint; ambiguous or missing targets fail closed.
- Clicks and submissions must show a verifiable DOM/state/navigation change or the runtime records the interaction as unverified.
- v22.10 link navigation is same-domain only. Cross-site orchestration remains reserved for the next multi-site runtime phase.
- The runtime interaction count is bounded from the approved delegation step budget and hard-capped.
- Interaction events flow into the existing v22.00 runtime timeline and Agent Workflow view.

The Runtime workspace includes **Scan controls**, semantic control selection, local-only value entry, interaction preview, explicit checkpoint confirmation, execution results and fingerprint-only receipts.

## v22.00 Browser Agent Runtime

v22.00 turns the bounded v21.90 delegation loop into a reusable **Browser Agent Runtime**.

The user still starts with a natural-language job and explicit v21.90 authority envelope. Starting the job creates/attaches a canonical runtime session with a declarative Browser Skill registry, task-scoped observations, cross-tab target references, a runtime timeline, verification state and bounded recovery/replanning.

The execution loop is:

**Goal → Plan → Runtime → Observe → Act → Verify → Replan / Checkpoint → Complete**

Key boundaries:

- **v21.90 remains authoritative.** Runtime cannot expand the approved Source, allowed actions, risk budget, step limit or expiration.
- **Planner and executor are separated.** Replanning can revise remaining steps only inside the original authority envelope; execution goes through registered skills and existing canonical VP3 services.
- **Every skill declares capability, risk, target types, verification mode and checkpoint behavior.** Live capabilities and durable VP3 targets are rechecked before each step.
- **Observations are task-scoped and expiring.** Runtime stores canonical VP3 references, high-level state and fingerprints only—not page URLs, titles, selected text, page text, excerpts or page content.
- **Cross-tab state is reference-only.** The server stores opaque tab keys plus authorized VP3 target references, never a browser-history ledger.
- **Recovery is explicit.** Pause, Resume, Cancel, Retry, Skip and bounded Replan are available without widening authority.
- **Consequential writes still checkpoint.** Task, Knowledge and Team-share work remains user-confirmed.
- **Notifications and Agent Voice are reused.** Runtime checkpoint/failure/completion events use the existing VP3 notification pipeline, so voice behavior still follows the existing Agent Voice setting.
- **Agent Workflows is canonical.** Runtime status and timeline are visible alongside the underlying durable Agent Workflow run.

v22.00 intentionally does **not** add unrestricted DOM clicking, form submission or arbitrary site automation. Those belong to a later controlled Web Interaction Runtime after this execution core is proven reliable.

## v21.90 Delegated Browser Workflows

Browser Companion now has a first-class **Delegate** workspace for handing the Agent a bounded multi-step job.

A delegation starts with a user-authored instruction plus explicit limits: allowed domains, allowed actions, maximum steps, risk budget, and expiration. **Preview plan** is read-only. **Start delegation** is the explicit approval that creates the canonical VP3 Agent Workflow run and the Browser delegation envelope.

The persistent Browser delegation table stores only the Agent namespace, canonical workflow/run reference, source VP3 reference, approved domain/action constraints, plan hash, step/time/risk limits, progress and lifecycle timestamps. The user-authored task itself lives in the canonical Agent Workflow goal. Passive browsing history, page URLs, selected text, page text, excerpts and page content are not copied into the delegation ledger.

Low-risk steps may continue automatically after the displayed plan is approved. Source follow/unfollow uses the existing canonical Source Feed service and verifies the returned state. Related Research/Profile/CRM navigation is generated from a freshly reauthorized VP3 object, is limited to the connected VP3 installation, and is verified against the active tab before the step completes.

Task drafting, Knowledge drafting, and Team sharing remain **checkpoints**. The delegated runner can hand the user into the existing Agent or Team-share surface, but it cannot silently create, assign, save, send, or publish those downstream objects.

Delegations support **Pause, Resume, Cancel, bounded Retry, checkpoint completion, expiration, progress, and recovery**. Chrome does not keep a durable task database: reopening the Delegate workspace reloads canonical server state and continues from the Agent Workflow/Browser delegation ledger.

The delegated workflow loop is:

**Intent → Plan → Delegate → Execute → Checkpoint → Verify → Recover → Complete**

The existing v21.40 proactive notification/Agent Voice system remains the notification surface for canonical workflow changes; v21.90 does not create a second Chrome-side voice or notification authority.

## v21.80 Agent Execution & Follow-Through

Browser Companion now has an explicit **Execute** workspace that turns authorized page context into bounded VP3 actions using the lifecycle **Propose → Confirm → Execute → Verify**.

The durable execution ledger is reference-only. It stores the signed-in user, Agent namespace, VP3 target reference, action key, risk/confirmation state, lifecycle timestamps, attempts, and verification state. It does **not** store browser history, page URLs, page titles, selected text, page content, summaries, excerpts, or Agent prompts.

Candidate discovery and proposal are based on the current v21.30 bounded page context. Before execution, the server independently revalidates that context, re-derives authorized VP3 relationships, checks the live Browser capability set, and requires the ticket target/action to still be a valid current-page candidate.

Low-risk reversible Source follow/unfollow actions execute through the existing canonical Source Feed service and are immediately verified against returned server state. Task, Knowledge, and Team-share operations remain explicit handoffs: Browser Companion can prepare the next step, but it does not silently create, publish, send, assign, or save the downstream object.

Task and Knowledge handoffs can open the canonical Browser Agent Workspace with a prepared prompt. The prompt is **not auto-sent**. Team sharing opens the existing annotation/share flow, where the user still chooses the destination and publishes. Safe related Research/Profile/CRM navigation can also be prepared and opened through the same execution lifecycle.

The Execute workspace shows recent Browser execution tickets plus active Cognitive Orchestration v5.60 runs so interrupted work can be resumed from canonical VP3 state without adding a Chrome-side task or history database.

## v21.70 Cross-Web Cognitive Memory

Browser Companion now has an explicit **Memory** view for cross-web Agent continuity.

This phase does **not** turn browsing history into Agent memory. Opening a page, highlighting text, asking Agent, or refreshing Now creates no Browser Memory approval.

Memory candidates are derived only from durable VP3 objects already related to the current page and already authorized for the signed-in user:

- recognized Browser Sources with an authorized share/follow relationship
- Research projects the user can access
- Knowledge items the user can currently read
- public active VP3 profiles
- CRM contacts only for existing CRM admins

The user must click **Remember** on a specific object. VP3 then stores only a reference approval: user ID, Agent namespace, target type, target ID, target scope, and approval/revocation timestamps. It does not store the page URL, page title, page body, selected text, source excerpt, relationship summary, or a browsing-history record.

Each approval becomes a registered `browser_memory_ref` Cognitive object. Cognitive Memory v5.70 records continuity around that reference, while the Browser Memory module reauthorizes and resolves the underlying VP3 object on every read.

**Forget** revokes the approval and removes the Browser-approved occurrence from Cognitive Memory v5.70. If that Browser Memory continuity thread has no remaining occurrences, the thread is deleted; otherwise its aggregates are recalculated. Re-approval creates a fresh user-approved signal.

The Memory view is Agent-specific. It shares the same resolved Agent namespace as the Browser Agent Workspace, so approved references do not silently move between Agents.

## v21.60 Browser Agent Workspace

Browser Companion now includes an inline **Agent** workspace backed by the same canonical VP3 Agent Chat conversations and send runtime used on the website.

The web `/api/chat-v236.php` send path and the durable-token Browser adapter both delegate to `vp3_agent_chat_send_v2160()`. Tools, calendar/meeting awareness, Knowledge retrieval, HomeServer/cloud routing, AI usage accounting, Cognitive cards, sources, media authorization, and assistant-message persistence therefore have one implementation.

Chrome stores no conversation database or Agent memory. The selected conversation ID exists only in sidepanel page memory; conversation lists, message history, new messages, and cross-surface updates are read from VP3. A four-second poll while the Agent tab is visible pulls only messages newer than the last canonical message ID.

**Use current page as temporary context** attaches the bounded v21.30 Browser context to that single turn. VP3 revalidates the page and relationships on the server, and the full Browser context remains excluded from persisted Agent message metadata.

The Browser Agent endpoint uses the durable device token, live `agent.message`, and live `chat.access` authorization on every request. It does not accept CSRF/session credentials from Chrome and does not create a separate Agent identity.

## v21.50 Contextual Quick Actions

The active page, selection, link, image, audio, or video can now enter VP3 through a single contextual quick-action surface without creating a second Chrome-side memory or persistence system.

Right-click **VP3** actions include Ask Agent, Summarize, Compare with Knowledge, Research, Knowledge, Task, Share with Team, and Annotate/Capture. The same actions are available in the **This Page** sidebar toolbar.

Agent-oriented actions reuse the v21.30 ephemeral page-context contract and server-proposed Cognitive Runtime suggestions. Chrome does not construct its own prompts or execute Cognitive tools autonomously.

Share with Team and Annotate open the existing explicit Browser Companion composer. The selected content is held only in `chrome.storage.session` for up to five minutes and is consumed once by the sidepanel. Nothing is persisted until the user chooses a destination/visibility and publishes.

Research, Knowledge, and Task quick actions are confirmation-first Agent proposals. They do not create durable records directly from a context-menu click.

Link and media context is preserved. Audio/video right-click targets are passed as bounded source references; image/link targets remain temporary selected context. Existing v21.30 server validation decides what reaches Agent Chat.

## v21.40 Proactive Notifications + Agent Voice

Browser Companion can now surface high-value VP3 interruptions through Chrome without creating a second notification or voice authority.

VP3 remains authoritative for notification ownership, current permissions, Cognitive importance, Agent Voice preference, and cross-device delivery state. Chrome polls at most once per minute and can claim only one server-authorized interruption at a time.

Visual delivery includes canonical notification attention plus high-priority Cognitive Feed attention items. Canonical notification projections already present in the Cognitive Feed are suppressed so the same event cannot appear twice.

The server owns a cross-device delivery ledger keyed by user + event. Claims are short-lived and device-bound, which prevents multiple connected browsers from independently displaying the same interruption. Open, dismiss, snooze, visual delivery, voice delivery, and retry state are all server-owned.

Sensitive categories use minimal Chrome notification text and are never spoken. The delivery ledger stores that redacted representation rather than duplicating the sensitive title/body.

When VP3's existing **Agent Voice** setting is enabled, eligible interruptions use the configured premium ElevenLabs Agent Voice through a durable-token voice adapter and MV3 offscreen audio document. The voice endpoint accepts only a currently pending server-owned event key—never arbitrary text. Voice delivery is acknowledged only after audio playback reaches `ended`.

Chrome shares the canonical Cognitive Presentation voice cursor with Agent Chat. It speaks the exact canonical aggregated voice candidate and stores the corresponding through-id before playback, so one Chrome alert cannot silently skip another web Agent Voice item.

If an Agent Chat tab is active, Chrome suppresses spoken interruption while retaining visual delivery. Current page URL/title may temporarily boost relevance and add a “related to this page” notification context message; page context is not written to the delivery ledger.

Notification actions remain bounded to **Open VP3**, contextual canonical routes, **Snooze 15 min**, and dismiss. Browser notifications never execute Cognitive Runtime tools.

## v21.30 Contextual Agent Actions

Browser Companion Now can temporarily use the active web page as **ephemeral Agent context**.

The extension sends only a bounded context envelope: normalized URL/canonical URL, title, up to 12 KB of selected text, a SHA-256 fingerprint of normalized page text, lightweight description/author/site/language metadata, and an optional media reference. The page body itself is not transmitted as contextual metadata.

VP3 resolves that page against the signed-in user's current permissions and can surface authorized relationships including existing Source annotations, Team conversations, Research projects, Knowledge, calendar/meeting items, public VP3 profiles, and admin-only CRM contacts/companies. Existing Cognitive Runtime cards are context-scored on the server so related goals, workflows, meetings, opportunities, and recent changes rise within their existing bounded sections.

The Now canvas adds a **Working with** strip, relationship evidence, evidence-based context insights, **Ignore page / Use page**, and server-proposed contextual actions.

**Ask Agent about this page** uses a fragment-only handoff to Agent Chat. Agent Chat immediately removes the fragment from the address bar, keeps the context only in page memory, visibly shows a removable temporary context strip, and attaches it to the next Agent turn. The Agent server discards client relationship claims, revalidates the page identity, re-resolves relationships for the signed-in user, and removes the full browser context from persisted Agent message metadata.

Merely viewing a page creates no VP3 Source, Browser Share, annotation, Research item, Knowledge item, task, contact, or memory. Persistence remains behind explicit VP3 actions and canonical permissions.

## v21.20 Agent Now / Cognitive Sidebar

Browser Companion now opens on **Now**, a Chrome presentation of the same bounded VP3 Cognitive Feed used by Agent Chat.

The extension does not calculate priorities, opportunities, meeting urgency, reminder relevance, or suggested actions. The VP3 server composes the feed from the canonical Cognitive Runtime and reauthorizes every Universal Display Card before returning it to Chrome.

The Now feed can surface:

- **Needs attention** — approvals, failures, live/imminent meetings, timely responses, and high-priority cognitive observations.
- **Next up** — upcoming meetings, bookings, and calendar work.
- **Priorities** — active goals, workflows, Agent Brain priorities, accepted plans, and orchestration progress.
- **Opportunities** — evidence-backed recommendations and proactive plans.
- **Recent changes** — meaningful unread activity plus significant recurring/reopened memory threads.

Browser Companion supports **Why?**, **Hide**, **Show hidden**, canonical object links, and Cognitive Outcomes & Learning feedback using the dedicated `browser_companion_now` surface identity.

Proactive plans can be **Accepted for review** or **Dismissed** in Chrome. Acceptance remains proposal-only: the extension never executes a Cognitive Runtime tool. Tool/prompt card actions are deliberately downgraded to **review in Agent Chat**, where normal VP3 permissions, confirmation, approval, risk, and execution boundaries remain authoritative.

The v21.20 integration branch contains both Browser Companion v21.00/v21.10 and VP3 Cognitive Runtime v5.00–v5.70. After deploying the server overlay, run `upgrade.php` once so the durable browser authorization-code table and Cognitive Runtime tables are all current.

## v21.10 account-aware shell

The connected sidebar now treats VP3 as the only account authority. It renders the current live display name, role, Team access summary, and capability-aware feature state from the same `/api/extension-me.php` and canonical destination endpoints already used by Browser Companion.

- No browser-side profile or account database is introduced.
- No login/session polling is reintroduced.
- Opening/focusing the side panel or using **Refresh account** re-reads current VP3 identity and capabilities.
- Removing Browser Companion permissions in VP3 collapses protected extension UI without disconnecting the durable device token.
- Restoring permissions in VP3 makes them available again on the next account refresh.
- **Open VP3** and **Extension settings** provide direct navigation without duplicating VP3 account settings inside Chrome.
- Composition controls are hidden when `team.share.create` is not currently granted; read-oriented tabs follow `team.chat.read`.

## Capture and share

1. Load the extension in Chrome.
2. Open the side panel and connect the browser to VP3.
3. Approve the connection in the VP3 tab that opens.
4. Open any normal HTTP(S) page and choose one or more capture types:
   - highlight text;
   - **Screenshot region** and drag over the visible area to keep;
   - **Media moment** on a detected YouTube, audio, or video page and set a source timestamp window up to 90 seconds;
   - **Voice commentary** to record an audio note up to 90 seconds.
5. Choose a Team or conversation, optionally add a note, and share.
6. Use **Ask VP3**, **Save to Knowledge**, **Create Task**, **Open source**, or **Open in VP3 Messages**.

The unified **VP3** right-click menu from v21.50 replaces the old standalone selection-share command.

## What rich capture stores

Browser Share remains the canonical share/message object. Rich capture does not place screenshots or audio blobs into Browser Share text fields.

- Screenshot bytes and voice commentary are private media attachments linked to the Browser Share.
- YouTube/audio/video moments are source references with bounded start/end timestamps. v20.40 does not download or rip third-party media streams.
- If a share contains no highlighted text, the canonical Browser Share receives only a short readable fallback such as “Screenshot captured from …” while the actual media remains in the private media layer.
- Media reads always re-resolve the current Browser Share authorization. Deleting the share or losing conversation access also removes access to the attachment.
- Binary attachment retries are idempotent per Browser Share, media kind, and content hash.

## Private media storage

The default private media directory is outside the application web root:

`../vp3-private/browser-share-media`

A production installation can override it in `config.php`:

```php
'uploads' => [
    // existing upload settings...
    'browser_share_private_root' => '/absolute/private/path/browser-share-media',
],
```

The PHP/web worker user needs read/write access to this directory. Files are created with private permissions and are served only through the live-authorized VP3 media endpoint.

After deploying v20.40, run the normal `/upgrade.php` flow once to install `browser_share_media_v2040` and `browser_share_media_jobs_v2040`.

## Media inspection worker

Binary screenshot/commentary uploads enter `processing` state and are inspected by the CLI worker before becoming `ready`:

```bash
php jobs/browser-share-media-worker-v2040.php 20
```

The optional numeric argument controls the maximum number of jobs processed in that run, capped at 50. A normal production scheduler can invoke this command repeatedly. Failed inspections retry up to three times with a delay and then move to `failed`.

## Local Chrome installation

Open `chrome://extensions`, enable **Developer mode**, choose **Load unpacked**, and select this `browser-companion` directory. The CI package `vp3-browser-companion-extension-v22.10.zip` is directly loadable/extractable and contains `manifest.json` at the ZIP root.

The default VP3 site is `https://vp3.me`. Another HTTPS VP3 installation can be selected in Extension Settings. Local development may use `http://localhost` or `http://127.0.0.1`; Chrome asks for explicit access to the selected origin.

## Connection security

The connection flow is intentionally simple:

1. Click **Connect to VP3** in the extension.
2. Chrome opens VP3's normal website sign-in/approval flow with `chrome.identity.launchWebAuthFlow()`.
3. VP3 redirects Chrome's private `chromiumapp.org` callback with a five-minute, one-time authorization code.
4. The extension exchanges that code once for a random durable device token.
5. The device token is stored only in `chrome.storage.local` and is sent as the Bearer credential for Browser Companion API requests.

The extension never receives or stores the user's VP3 password. It does not poll for approval, store a second device credential, or mint/refresh 60-minute extension sessions.

VP3 remains the source of truth for the account. On panel load the extension calls `/api/extension-me.php`, so the current display name, role and effective capabilities come from the live VP3 account. Each protected API request also recalculates effective capabilities against the user's current VP3 permission matrix.

Production VP3 installations should configure the published extension's exact `chrome-extension://...` origin. For unpacked development builds, the existing `extension_allow_unlisted_chrome_origins` site setting can be enabled temporarily. Production should remain fail-closed.

Disconnecting calls the VP3 self-revoke endpoint before the local device token is removed. Revocation immediately invalidates that token.

## Privacy and safety boundaries

- Browser Share accepts only HTTP(S) source URLs.
- Selection payloads remain bounded to 32,768 UTF-8 bytes; notes remain bounded to 4,096 UTF-8 bytes.
- Screenshot uploads are bounded to 8 MB and PNG/JPEG/WebP.
- Voice commentary uploads are bounded to 16 MB and an explicit audio MIME allowlist.
- Region screenshots are cropped locally from Chrome's visible-tab capture.
- Source-media windows are clamped server-side to 90 seconds and store timestamps rather than copied third-party media.
- The extension does not request `tabCapture` and does not call media-element `captureStream()`.
- **Ask VP3** passes only the Browser Share public ID into the web handoff. VP3 re-resolves authorization and current share state before Agent Chat receives the content.
- Knowledge and Task actions continue to reuse the existing Personal Knowledge and Agent Workflow systems.

## Phase 6 — This Page + Following

- **This Page** resolves the canonical source without storing passive browsing history. A Source row is persisted only when a user explicitly publishes or follows.
- **Following** is generated from followed Sources and VP3 users using the same Browser Share + publication model used by the website.
- Delivery destination and feed visibility are independent. Visibility is **Private**, **Team**, or **Public** and is always enforced server-side.
- Source versions use SHA-256 page-text fingerprints when Browser Companion can inspect the page; VP3 stores the fingerprint, not the page body.
- Comments/replies, Save, Add to Research, Follow/Unfollow, read state, Share with Team, Ask VP3, and Save to Knowledge re-resolve current authorization on every action.
- Public/source views use `/source.php`; annotation context uses `/annotation.php`.

After deploying v20.50, run `/upgrade.php` once to install the Browser Source Feed tables and backfill canonical Source links for existing Phase 4/5 Browser Shares.


## Phase 7 — Research Projects + Publishing

- **Add to Research** now opens a project chooser instead of only toggling a generic saved state.
- Send an annotation to the **Research Inbox**, assign it directly to an existing project, or **Create project + add** without leaving the sidebar.
- Project assignment references the canonical Browser Share, Source, and pinned Source Version; Research does not copy captured text into a second extension data store.
- Project roles are **Owner**, **Admin**, **Researcher**, and **Viewer**. Team projects also revalidate live Team membership.
- Assigning an annotation automatically adds its canonical Source to the project evidence base.
- Findings move from **Draft → Confirmed → Published** and can link supporting, conflicting, and contextual evidence with pinned Source Versions.
- Reports are composed from selected Findings, Sources, and annotations. Every publish creates a new immutable JSON snapshot with a SHA-256 integrity hash.
- Report visibility is **Private**, **Team**, or **Public**. Incompatible annotation text is redacted back to the pinned Source instead of leaking broader content.
- Unpublishing removes current public/team availability but preserves historical report versions and hashes.
- Canonical Source pages surface authorized published Research reports that cite that Source.

After deploying v20.60, run `/upgrade.php` once to install the Research Project, Finding, evidence, report, immutable report-version, and report-source provenance tables.


## Phase 8 — Live Rooms + Cloak Mode

- The sidebar now includes a **Live** tab for the current canonical Source.
- Start or join **Public** and **Team** rooms without introducing a separate source/capture store.
- Live messages use a lightweight cursor poll; presence is refreshed separately by heartbeat and expires when the participant disappears.
- **Cloak Mode** uses a room-specific pseudonym. Participant payloads never expose the cloaked user's VP3 user ID or real display name.
- Every message snapshots its cloaked/visible sender identity at send time, so disabling Cloak later does not retroactively deanonymize earlier cloaked messages.
- Public rooms can be read on the web without signing in; joining, presence, Cloak Mode, and posting require an authenticated VP3 account.
- Team rooms revalidate live Team authorization server-side.
- Sharing an annotation into Live never republishes it. Public rooms accept only already-Public annotations; Team rooms accept Public or same-Team annotations.
- Canonical Source pages surface active authorized Live Rooms.
- Website surfaces are `/live.php` and `/live-room.php`.

After deploying v20.70, run `/upgrade.php` once to install the Live Room, participant/presence, and message tables.
