# Profile Agent and Messages reliability audit

Date: 2026-11-27

Baseline: `8f39d8e2c31620567b8fe68c0a0a97157c92eb60` (`main`)

Candidate branch: `codex/profile-agent-reliability-20261127`

## Scope and result

This audit covers the public Profile Agent chat, native visits and announcements, owner Inbox, Agent Radar, the main member Messages page, Profile image editing and owner preview, and the Profile specialist's analytics reports. It also adds an opt-in entry from the public chat to an assigned Twilio phone line.

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

## Deployment and installed-site acceptance

See `docs/PROFILE-AGENT-RELIABILITY-DEPLOY.txt`. Apply the source through the normal release process, preserve runtime configuration/data, and run Database Upgrade. Public phone publication remains off until an owner enables it.

Installed-site acceptance should cover an anonymous and signed-in visit, logout/return, arrival announcement with real Available/Away/DND behavior, Agent reply, live pickup and timeout, Inbox takeover/return/resolve, messages across two accounts and a long thread, image editing with an approved reference, and an exact completed-period report. For phone acceptance, explicitly enable one test line, place a real call, verify existing routing/voicemail, revoke publication, and confirm the chat link disappears. Provider connectivity and telephone outcomes can only be established by those live checks.
