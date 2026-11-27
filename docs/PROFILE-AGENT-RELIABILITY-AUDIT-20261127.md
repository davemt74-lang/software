# Profile Agent and Messages reliability audit

Date: 2026-11-27

Baseline: `8f39d8e2c31620567b8fe68c0a0a97157c92eb60` (`main`)

Candidate branch: `codex/profile-agent-reliability-20261127`

## Scope and result

This audit covers the public Profile Agent chat, native visits and announcements, owner Inbox, Agent Radar, the main member Messages page, Profile image editing and owner preview, and the Profile specialist's analytics reports. It also adds an opt-in entry from the public chat to an assigned Twilio phone line. The follow-up adds explicit owner response modes, durable requests for owner input, filing reviewed owner answers into the existing Knowledge folders, and the remaining free image allowance.

The candidate repairs reproduced application failures and adds regression coverage. The evidence comes from source review, real isolated SQL and PHP HTTP fixtures, and Chromium running production markup and scripts with controlled services. It does not establish the condition of a deployed site's database, provider credentials, worker configuration, microphone permissions, or Twilio routing. No production database was modified, no paid image/model request was submitted, and no telephone call was placed.

## Confirmed defects and repairs

| Area | Reproduced defect | Candidate behavior |
| --- | --- | --- |
| Public chat startup | Restricted browser storage could throw before the chat initialized. A storage write error could mislabel a successful server send as an uncertain failure. | Optional local storage is guarded. A confirmed message receipt remains confirmed even when local persistence fails. |
| Browser history and recovery | Returning through the back/forward cache could leave an inactive chat runtime. Initial state failures could leave stale error text. | Restore revalidates the visitor session and restores ordinary unsent text only in its matching conversation namespace. Successful reads clear obsolete startup errors. |
| Owner Inbox loading | Inbox initialization awaited slow Radar work. A single pending request could hold the workspace indefinitely. | Inbox and Radar load independently. Shared private reads have a 15-second bound; writes have a 45-second bound and do not retry automatically after an uncertain result. |
| Owner takeover | An owner replying directly from Inbox after a missed live offer could receive HTTP 409 because the expired Main Chat offer was treated as a prerequisite. | Explicit authenticated Inbox actions take control of the exact owned conversation. Take over, Let Agent handle, and Resolve are visible controls. Main Chat's live-offer path retains its availability and 15-second pickup rules. |
| Competing Agent replies | Manual takeover must invalidate an already running Agent response. | The canonical transition increments the responder epoch and clears the execution lease. Publication checks continue to suppress an obsolete answer and its completion receipt. |
| Visit identity | A retained CRM visitor association could survive logout/account changes and be used as the current visit identity. Arrival proof and announcements then failed verification. | Entry events use the current native visitor identity and disclosure, establish current source proof, and deduplicate by the correct principal and Agent. CRM association does not substitute for current authorization. |
| Interleaved visits | Credentials B, C, then B in one retained CRM session could select C's recent event and insert B's duplicate key. | Recent-event lookup filters the exact source credential before choosing the event. |
| Radar classification | Owner previews could reach automated visitor classification; automated visitor notifications did not land on the Radar view. | Owner visits are excluded first. Native automated visitor notifications open Radar. |
| Radar session contention | Expensive relationship refresh held PHP's session lock, serializing unrelated private requests. | Read work releases the lock, then reopens the same session without replacing cookies and verifies current account and permission before returning private results. |
| Analytics and settings recovery | Traffic/conversion reads and image settings could remain pending without a useful recovery path. | Analytics and image settings reads are bounded. Errors expose retry/reload behavior, stale reports are not replaced by invented zero totals, and uncertain image settings saves preserve the draft. |
| Messages missing threads | A `NOT (...)` predicate on a nullable request join excluded ordinary direct conversations without a request record. | Ordinary conversations appear while incoming pending requests remain in Requests. |
| Messages long history | Opening a conversation returned its oldest 120 messages; later replies could appear missing. | Open shows the latest 120, Load earlier pages backward, and bounded catch-up fetches newer messages in order. |
| Messages live state | There was no dependable visible-page transcript catch-up; late thread reads and send acknowledgements could affect the wrong selection. | Polling, exact deep links, per-thread drafts, request generations, and a separate fetched cursor preserve selection, drafts, and concurrent incoming messages. |
| Messages read/request/privacy state | Empty threads attempted a message-0 read cursor; hidden request controls could remain visible; saving message policy overwrote follower visibility. | Empty reads avoid the invalid cursor, pending request controls and composers follow their actual state, and unrelated visibility preferences are preserved. |
| Messages account changes | An old page could read or act under a newly signed-in account while retaining previous content and drafts. | Messages and Shares bind requests to the rendered account. A mismatch or logout clears private UI and drafts, stops polling/sending, and requires reload. Shared-card writes and media-list reads use the same binding; media-only account denial clears the card immediately. Older clients without the optional header retain their authenticated behavior. |
| Messages mobile use | Inbox and transcript competed for the phone viewport. | A selected conversation uses one pane with Back navigation and a visible composer, verified at 320 and 390 pixels. |

Primary implementation files are `profile-agent.js`, `profile-agent-portal.js`, `includes/profile-agent-handoff-pa3.php`, `includes/profile-agent-runtime.php`, `api/agent-radar.php`, `profile-agent-analytics.js`, `profile-image-settings-v1.js`, `includes/human-messaging-v370.php`, `messages-v320.js`, and their related APIs and styles.

## Response modes and submitted owner requests

Profile Agent settings now expose **Automated** and **User responses**. The setting belongs to the Profile owner and is saved with a revision, so a stale settings page cannot silently replace a newer choice. Existing installations retain Automated until the owner chooses otherwise. A partially installed request ledger cannot turn a saved User responses choice back into Automated.

| Selected mode / result | Saved behavior | Visitor experience |
| --- | --- | --- |
| User responses | The exact visitor question becomes a durable owner request without knowledge retrieval or model inference. | A saved Status message says "Submitted to [owner]. Waiting for a response." |
| Automated, supported answer | The Agent uses the currently permissioned context. Source access and response policy are checked again before publishing. | The supported Agent answer appears in the conversation. |
| Automated, missing information or unsupported action | A structured `needs_owner` result becomes the same durable owner request. Model text cannot claim that a request was submitted. | The server's submitted/waiting Status message appears. |
| Automated, unavailable service, exhausted quota, or invalid structured output | The question is retained for the owner with its actual reason. | The visitor receives the same saved waiting receipt, rather than a fabricated answer. |

Cloud and HomeServer Profile inference share an explicit two-field answer contract. Unstructured provider output fails closed to an owner request. Native queued chat and signed synchronous external callers use the same classification and policy rules. The explicit image-edit path remains a separate visitor action.

Requests are deduplicated by the exact owned visitor message. Waiting does not mean the owner has joined live, and a Status receipt is not an Agent reply or successful inference completion. Inbox and Main Chat show the request state, while the existing attention, reply, resolve, and delegation actions operate on the canonical conversation. An owner reply to an earlier turn does not clear a later unanswered request or its attention. If multiple text questions are active when inference must hand off, each is saved as an owner request.

Changing the response mode or taking over fences an already running Agent answer. An owner may explicitly return a native queued request to the Agent when Automated is enabled; another knowledge gap reopens the same owner request. For a synchronous external conversation, enabling Agent responses affects future messages and does not invent a background retry of the earlier question.

Storage consists of `profile_agent_response_settings_v1` and `profile_agent_owner_requests_v1`, installed by Setup/Database Upgrade. Their receipt message uses a distinct stored role; public and owner histories render it as Status and do not expose the hidden conversation authority row.

Implementation: `includes/profile-agent-responses-v1.php`, `includes/profile-agent-answer-v1.php`, the existing accepted-turn/public-service/handoff helpers, and the existing visitor, Inbox, and Main Chat scripts.

## Reviewed owner answers in the existing Knowledge system

After the owner answers a submitted question, **Save to Knowledge** appears beside that owner reply in Inbox and Main Chat. The owner reviews the original question and reply, edits the retained title and content, chooses an existing personal Knowledge folder (or Unfiled), and confirms the save. An older answered question remains available after newer questions arrive. If one reply answers several submitted questions, the owner selects which question to retain.

This action creates an ordinary personal Knowledge note using `personal_knowledge_store`, the existing Knowledge item/chunk tables, and the existing personal folder system. It does not create a second knowledge repository or change any sharing policy. The current **Profile Agent > Knowledge Access > Knowledge Base** Allow and audience settings, together with existing item and Agent rules, determine whether the saved note may enter a visitor's answer. The save dialog points back to those existing controls. Saving does not enable Knowledge access or change its audience.

The original owner reply remains a conversation message; it is not saved to Knowledge automatically. The owner must perform the separate review/save action. The server verifies current account and Knowledge-management permissions, the owned folder, and the exact saved question and owner reply. Retrying a confirmed save returns the same note without overwriting later edits made in Knowledge. Further editing and permission management use the existing Knowledge and Knowledge Access screens.

The new `profile_agent_knowledge_saves_v1` table records source provenance and idempotency, rather than a separate permission model. Its source references and owner action metadata stay in the private owner workspace. Visitor histories receive no Knowledge action metadata or private note identifiers.

Implementation: `includes/profile-agent-knowledge-v1.php`, `profile-owner-knowledge-v1.js`, and the owner actions in `api/profile-agent.php`.

## Twilio entry in public Profile chat

The new **Call by phone** action opens a native `tel:` destination for an owner-approved assigned Twilio number. The incoming call follows that number's existing Phone routing, browser-answering configuration, voicemail, and recording settings. This is a phone-app handoff; it does not implement anonymous browser-to-PSTN media inside the Profile widget. Existing native Profile browser audio/video remains governed by the separate call system.

The owner enables the action under **Profile Agent > Agent > Profile phone calls**. Publication starts off. The owner selects an eligible assigned line; an arbitrary destination cannot be submitted. The saved binding includes number ID, Agent ID, and E.164 number. Public reads verify current assignment, active Agent, public/enabled Profile, account access, and the voice allowance before projecting the number. Private personal callback details are not part of this projection.

The browser rereads approval when opening/restoring the chat and before handing off a click. A changed destination requires another deliberate click. Revocation removes the link. Settings require owner/account binding, CSRF, and the exact revision; an uncertain save requires a reload before another write. Canonical number reassignment revokes publication, including a line that later returns to its former owner.

Storage is the new `profile_phone_settings_v1` table, installed explicitly by Setup/Database Upgrade. Public GETs do not create or repair schema. `vp3_upgrade_complete()` includes the new table in readiness. Scripts and styles use content-based versions.

Implementation: `includes/profile-phone-v1.php`, `api/profile-phone-v1.php`, `profile-phone-v1.js`, `profile-phone-settings-v1.js`, and `profile-phone-v1.css`.

## Image module findings

The existing public image workflow already provides approved free catalogs and reference thumbnails, subject upload, an edit request in the conversation, progress, saved result download, and revisions of an exact saved result. The owner has a separate private reference-generation preview. These paths were audited and exercised; they were not replaced by another image subsystem.

The verified boundaries include current public catalog approval, exact visitor/conversation ownership, source-image grants, frozen reference attribution, request idempotency, quota admission, revision lineage, and rejection of results after owner takeover or authority revocation. A lost acknowledgement recovers the original request instead of submitting another paid request. Owner preview does not create a fake visitor conversation.

The concrete image repair in this candidate is settings recovery: a 15-second request bound, explicit retry, preserved input after an uncertain save, account-denial cleanup, and recovery after browser history restoration. The regression suite covers the actual image composer at desktop and phone widths, result downloads, revisions, account changes, settings, and owner generation preview. Provider responses are controlled test responses; live image-provider configuration still needs installed-site acceptance.

The Image tab now also displays **free image renders remaining**, using the same saved UTC-day admissions as the existing render limits. The displayed number is the lower of the visitor's remaining daily limit and the Profile's remaining shared limit. Initial edits and revisions consume their existing allowance; failed or cancelled admissions retain the existing counting policy. The browser refreshes the server value after requests and does not decrement a separate local counter. A reset time accompanies a known balance; unavailable data remains unknown, not zero or an invented full allowance.

A read-only collection request recognizes an existing member or guest identity without creating a visit, conversation, or new visitor token. Owner preview does not invent a visitor allowance. Late or failed older collection reads cannot replace a newer authoritative balance.

## Profile specialist analytics inventory

The existing completed-period implementation has 21 measures across five areas. Source adapters live in `includes/profile-analytics-period-v1.php` and `includes/profile-image-analytics-v1.php`.

| Area | Evidence source | Meaning and limit |
| --- | --- | --- |
| Visits | `profile_events`, application-clock proof, native visitor proof | Saved Profile visit events and distinct disclosed visitor/browser principals. These are not raw page loads or a count of unique people. Recognized contacts require current disclosure and source proof. |
| Conversations and replies | Native conversation/messages, visit sessions, handoff turns/operations, workflow actions and receipts | Accepted turns, active/new conversations, exact owner takeovers, verified owner/Agent replies, and median first-reply delay. Unanswered and unverifiable cohorts remain separate. Agent replies require matching successful receipts. |
| Native calls | `profile_calls_pa3c` and native call-integrity checks | Offers, acceptance, direct/assisted breakdowns, and recorded terminal outcomes. The delay is offer-to-accept time, not talk duration. |
| Commerce | Clocked conversion events plus frozen order terms, checkout attempts, payment/refund evidence and provider connections | Recorded conversions, verified paid orders, and refunds are distinct. Values remain grouped by currency. A click or saved conversion value is not proof of payment. |
| Images | Image edit records, exact admission/history, successful reply receipts, and frozen catalog snapshots | Verified completed free requests, initial creations, completed revisions, and dated failed requests. Retrying one request does not create another completion. |

### Periods, ranking and delivery

Completed reports use 1-, 7-, or 30-day local periods. The default cutoff is the start of the current local date, with an inclusive start and exclusive end. The prior comparison has the same number of calendar days. Owner timezone, scheduling timezone, and an explicitly labelled UTC fallback determine boundaries. A fixed report reference retains its cutoff and timezone; later corrections to underlying evidence can still revise the result.

The same evidence feeds the private Profile specialist workspace, its concise report summary, scheduled coordination sources, and explicit catch-up. Relevant files are `includes/profile-analytics-period-ref-v1.php`, `includes/profile-specialist-workspace-v1.php`, `includes/agent-profile-analytics-summary-v1.php`, `includes/agent-coordination-profile-v1.php`, and `includes/agent-catch-up-profile-analytics-v1.php`.

Image reports rank the top ten catalogs/reference images by verified completed creations, show truncation, and keep initial creations separate from revisions. Multiple references can contribute to one request; their use counts cannot be summed as distinct requests. Frozen source snapshots supply the labels. Current image allowance is a separate UTC-day view of admitted requests, including pending or failed admissions that consume allowance.

### Distinct dashboards and unavailable evidence

The rolling Radar property dashboard uses 7/30/90-day selections and reports human/Agent traffic, events, pages, referrers, WebMCP outcomes, and device/browser summaries. The conversion-intelligence view reads recorded visits, intents, and conversion value for today and rolling comparisons. Its `recorded_conversion_value`/`session_first_touch`/`period_event_ratio` basis is separate from verified payment receipts in completed-period reports.

Complete measures expose a value. Partial evidence exposes an observed value with coverage. Unavailable or unconfigured sources retain nulls and reasons. Comparisons require usable complete values on both sides; an empty median is not zero delay. The candidate's timeout fixes preserve these distinctions and let the owner retry stalled reads.

**Twilio reporting limit:** the current Profile call adapter reads native `profile_calls_pa3c` activity. A `tel:` click does not establish Twilio connection, acceptance, duration, or outcome and is not counted as a completed native call. Twilio activity and recording reports remain in the existing Phone workspace. Correlating signed Twilio call receipts to a public Profile conversation would be additional reporting work.

## Validation evidence

A combined regression run on the integrated candidate passed across Messages, visits, handoff, attention, notifications, analytics, Radar, owner APIs, native calls, images, phone UI, and browser recovery. The final narrow account/credential fixes were then verified with their affected suites. Additional subsystem runs and independent review covered:

| Test area | Key evidence |
| --- | --- |
| Profile chat/Inbox | 19 recovery-browser checks; 72 owner-thread browser checks; 136 live-chat HTTP checks; 92 owner API checks; 101 handoff checks. Eight additional in-flight inference checks confirmed manual claim/reply/resolve suppress obsolete Agent output. |
| Visits/announcements/Radar | 19 arrival checks; 17 concurrent-session Radar HTTP checks; 12 bounded-client checks; 123 speech checks; 79 attention checks; 35 notification checks; 158 Radar read checks. |
| Main Messages | Real SQL recovery suite; actual markup/runtime browser suite; 21 Messages/Shares account-binding HTTP checks including media-list isolation; retained human messaging, social architecture, Shares and Agent messaging contracts. |
| Image module | Catalog 57, edit 60, owner-generation 19, public HTTP 97, public browser 55, settings HTTP 21 and browser 19 checks; owner preview at desktop and phone sizes; image UI/source contracts. |
| Analytics | Completed-period 203, aggregate 31, analytics UI 25, reliability 19, completed-period browser 29, and specialist workspace browser 149 checks. |
| Public Twilio entry | 24 service checks, 18 HTTP checks, 15 browser checks including image-settings timeout recovery, and expanded canonical assignment-isolation checks. |

PHP/JavaScript syntax and whitespace checks are included. New regressions are registered in `.github/workflows/authority-section14.yml`. Existing package-entitlement CI runs the expanded assignment-isolation test on SQLite and MySQL. CI results should be checked on the exact PR head before merge; local SQLite results do not substitute for the native MySQL gate.

### Follow-up validation: response modes, Knowledge filing, and allowance

The integrated follow-up passed these targeted gates with real isolated SQL and HTTP handlers. Inference and image transports remain controlled test responses.

| Gate | SQLite | Native MariaDB 10.11.19 |
| --- | --- | --- |
| Response modes, durable requests, concurrent turns, and reply settlement | 284 checks | 284 checks |
| Synchronous native and signed external response policy | 127 checks | 127 checks |
| Image allowance and returning guest identity | 48 checks | 48 checks |
| Owner API authority and replies | 92 checks | 92 checks |
| Image API and personalized remaining allowance | 99 checks | 99 checks |
| Knowledge folders, source binding, existing access rules, and history metadata | 75 checks | 75 checks |
| Handoff API, including actual Knowledge preview/save requests | 90 checks | 90 checks |

The Knowledge HTTP cases exercise the actual owner API. They verify canonical note/chunk storage, exact folder and source, CSRF/method/owner checks, safe retries, and unchanged data policies, Agent rules, and capability grants. The save rejects attempted parallel sharing fields. The helper cases verify that a new note follows the existing Knowledge Access Allow/audience settings, including later revocation and relationship restrictions. Canonical Knowledge storage, policy precedence, and shared-index modules are unchanged.

Frontend gates passed 601 checks: visitor handoff VM 33, owner threads VM 44, doorbell VM 98, visitor response/live browser 82, image browser 66, owner Inbox browser 85, Main Chat browser 65, and Knowledge editor browser 28. The final Knowledge editor was visually checked at 1280 by 960 and 390 by 900; the review, folder selector, and actions fit without clipping. The saved note and existing Knowledge Access controls are linked directly.

Recovery Baseline on the prior PR head also identified one stale Radar source-contract assertion. It expected an inline owner expression after the endpoint had been changed to capture the authenticated owner before releasing the session lock. The corrected test requires the captured owner, canonical read, and fresh owner recheck. The contract and 17 real concurrent Radar API checks pass; the gate was retained.

Recovery Baseline and Section 14 include the new policy, structured inference, Knowledge, and allowance tests. Section 14 includes native MySQL and the new Knowledge browser suite. Run CI on the published follow-up head before merge.

## Deployment and installed-site acceptance

See `docs/PROFILE-AGENT-RELIABILITY-DEPLOY.txt`. Apply the source through the normal release process, preserve runtime configuration/data, and run Database Upgrade. Public phone publication remains off until an owner enables it.

Installed-site acceptance should cover an anonymous and signed-in visit, logout/return, arrival announcement with real Available/Away/DND behavior, Agent reply, live pickup and timeout, Inbox takeover/return/resolve, messages across two accounts and a long thread, image editing with an approved reference, and an exact completed-period report. For phone acceptance, explicitly enable one test line, place a real call, verify existing routing/voicemail, revoke publication, and confirm the chat link disappears. Provider connectivity and telephone outcomes can only be established by those live checks.
