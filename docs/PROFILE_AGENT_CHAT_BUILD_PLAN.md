# Profile Agent, owner Chat and visitor conversations

## Product relationship

The Profile Agent is the owner's public conversation UI. It speaks using the
owner's approved public knowledge. A visitor conversation is a separate durable
thread from the owner's private Agent Chat. The main Chat canvas displays a
reference to that exact visitor thread in a live card, and an explicit reply from
that card goes back to that visitor. Normal private Chat content is never
forwarded implicitly.

The requested experience is an interactive doorbell. Announce arrival once per
visitor session. A visitor message offers the available owner an opportunity to
answer. Start a server-owned 15-second pickup deadline after confirmed delivery
of the visible visitor card. An owner answer or explicit claim takes control. If
the owner is unavailable, delivery fails, or the deadline expires, the Profile
Agent continues automatically. Owner pickup and automatic takeover must compete
atomically so that both cannot answer the same pending turn. Once the Agent is
handling the conversation, do not impose another 15-second delay on each turn.

Availability requires fresh, reachable owner Chat activity and the owner's
Available/Away/Do Not Disturb preference. An optional authorized home-presence
signal may contribute; a running HomeServer is not proof that the owner can
answer. Owners can join, delegate, and resolve individual visitor conversations.

Visitor speech uses ElevenLabs through the existing provider integration. Assign
a stable visitor voice different from the contacted owner's/Agent's voice. Use
an explicitly supplied visitor voice preference or profile sex/gender selection
to choose the requested voice category. Do not infer sex/gender from names,
photos, or audio. Unknown guests use a selectable/default visitor preset. Keep
speaker labels and voice assignment separate from identity/authentication.

The owner and visitor can also talk directly. An accepted live-text thread
supports an in-browser voice call. Either participant can offer a call, the
other must accept, and each controls their own microphone. The owner stays in
the main Chat canvas and the visitor stays in Profile chat. A missed or declined
call keeps the accepted text thread available.

Live calls carry the participants' actual voices. The owner can enable Profile
Agent assistance in Chat Settings and the visitor sees a notice before joining.
The Agent transcribes opted-in calls and displays private call notes and research
to the owner. Browser calling and Twilio telephone calls use separate routes.

## Build order and acceptance

Each section receives its own reviewed change, automated acceptance evidence,
green PR checks, merge, and verified flat production ZIP. A 10/10 score means all
ten stated section gates passed; it is not a claim that all future features or
all production conditions have been verified. Mission change alerts remain on
hold. The Cloud work does not change HomeServer source or Stonefellow playback.

| Section | Scope | Status |
| --- | --- | --- |
| PA1 | Profile mutation permissions; visitor/account conversation isolation; fresh public authority; exact owner reply target; separate external Agent Messaging histories | Complete: PR #694 merged; all 13 PR and 14 post-merge workflows passed |
| PA2 | Payment webhook recovery; late payment reconciliation; durable fulfillment retry; accurate review/payment cards | Complete: PR #695 merged; all 13 PR and 14 post-merge workflows passed |
| PA3 | Availability, exact visible-card acknowledgement, 15-second pickup, durable automatic takeover, and functional main Chat visitor cards with explicit text reply/claim/delegate/resolve | Released in PRs #696 and #697 |
| PA3D | Visitor chooses Agent chat or requests live owner text and the owner accepts or declines using existing Chat Settings | Released in PR #698 |
| PA3C | Browser voice, optional Profile Agent transcription, sourced research and the owner activity drawer | Implementation and acceptance below |
| PA4A | Arrival/chat announcements and distinct ElevenLabs visitor voices | Planned |
| PA4B | Notification and Brain state; snooze/wake; routing; replay and projection repair | Planned |
| PA5 | Ordinary public Profile conversation checkout using the canonical Commerce order/payment/receipt path | Planned |

### PA1: conversation and permission foundation

The audit reproduced owner mutations over GET, member history surviving a
shared-browser account change, a late public response after authority changed,
new-thread rate-limit resets, and reverse UI responses that displayed thread A
while submitting to thread B. External Agent Messaging history was grouped by
the aggregate Radar contact, allowing separate grants for the same registered
Agent to enter one model history.

| Gate | Required behavior | Evidence |
| --- | --- | --- |
| 1 | GET, HEAD, PUT and DELETE cannot perform owner mutations, even with a supplied token | Production API HTTP fixture and stored-state comparison |
| 2 | Owner mutations require authenticated current account/Profile access and valid POST-body CSRF | Production API HTTP fixture |
| 3 | Owner read/reply/status operations name and verify the exact owned conversation; historical owned Agent threads remain available | Real SQL boundary plus HTTP and browser fixtures |
| 4 | A visitor conversation is bound to its exact visitor principal and browser authority; guest/member/account switching cannot borrow history | Public service SQL fixture |
| 5 | Native and connected-site conversation authorities remain distinct and fail closed when binding cannot be proved | Public and connected-site service fixtures |
| 6 | Current public Profile, active selected Agent, owner/visitor permissions and shared context authority are checked across slow response generation | Revocation fixtures before output/persistence |
| 7 | External Agent Messaging uses the exact approved grant/caller history and public Profile model surface | External message authority SQL fixture |
| 8 | Starting another conversation does not reset visitor message admission limits | Per-session admission fixture |
| 9 | Reverse responses and background refresh cannot change reply targets or wipe drafts/unsaved settings | Actual browser tests of both owner UIs |
| 10 | Failures produce safe unsuccessful results; public/owner compatibility, SQLite, MySQL and supported PHP versions pass | HTTP failure injection, retained regressions and CI matrix |

PA1 keeps the existing `open`, `owner_joined`, and `resolved` lifecycle. The
15-second doorbell state machine is introduced in PA3A. Legacy public threads
whose visitor authority cannot be proved must start a new visitor conversation;
their historical messages remain available to the owner. Stable CRM recognition
is separate from permission to read a conversation.

The PA1 local suite passes 121 public-authority cases, 112 external-message
authority cases, 66 external-message HTTP checks, 79 owner API HTTP checks and
30 owner UI runtime assertions. These are controlled transport tests against
production functions and endpoints; they do not call a live model, voice or
payment provider. CI additionally runs the authority services with MySQL 8 on
PHP 8.1 and 8.3, the owner HTTP API with both database drivers, and both owner
interfaces in Chromium. MySQL adds a non-UTC database-clock admission case.

Agent answers retain bounded private source dependencies, including sources
inherited from earlier answers. A later turn cannot make a previously shared
source independent of its current permission merely by paraphrasing an older
answer. These dependencies are not displayed as visitor messages or credentials.

### PA2: payment reliability

The audit found payment webhook claims that could permanently consume an event
after transient processing failure. Local order expiry also needs reconciliation
with a provider that subsequently reports payment, and recorded payment needs a
durable, idempotent fulfillment obligation. The human confirmation card must
show the returned payment URL and distinguish order recorded, awaiting payment,
paid, fulfilled, refunded, expired, and failed. Seller terms and provider details
must stay visible before confirmation.

Use the existing Commerce ledger and provider adapters. Test signed webhook
retry, concurrent duplicate events, late payment, fulfillment recovery, and
accurate customer receipts without charging a live payment instrument.

Stripe, Square and PayPal already have separate adapters in this repository.
Their presence does not mean an owner has connected or enabled each provider.
Payment recovery preserves the selected connected merchant and the original
checkout terms. Stripe uses its exact Session and PaymentIntent; Square uses
its exact Order, tender and Payment; PayPal uses its exact Order and Capture.
Redirect parameters and an account-wide payment search are never settlement
evidence.

| Gate | Required behavior | Evidence |
| --- | --- | --- |
| 1 | Reject invalid signatures, malformed or oversized payloads and conflicting provider identities before financial effects | Signed production webhook HTTP fixture and provider evidence suite |
| 2 | Retry transient and abandoned processing failures; processed duplicates remain harmless | Recovery SQL and HTTP fixtures |
| 3 | Fence competing workers and retain early delivery before the checkout response is stored | Expired-lease tests, scheduled recovery and independent MySQL processes |
| 4 | Verify exact original merchant, checkout, payment, amount and currency; preserve deposit and balance lineage | Provider evidence suite, real ledger tests and checkout creation tests |
| 5 | Record verified late money once, require review, and never recreate a cancelled reservation automatically | Elapsed/expired/cancelled payment and appointment fixtures |
| 6 | Commit financial receipt and durable fulfillment/projection obligations together, including caller-owned transactions | Failure injection, nested rollback and post-commit recovery tests |
| 7 | Resume interrupted fulfillment without duplicate domain transitions or confirmation notifications; keep manual fulfillment manual | Actual appointment adapter, lifecycle and notification queue tests |
| 8 | Distinguish financial, fulfillment, review and refund-request status; protect delivery while review is required | Customer status, private delivery and HTTP fixtures |
| 9 | Show complete canonical price, provider and cancellation/refund terms before confirmation; require new review after terms change | Controlled native UI and real Chromium tests |
| 10 | Reuse the same confirmed intent on uncertain retries; prevent duplicate submit and stale results; retain private/external authorization boundaries | Native runtime, Chromium, retained contracts and PHP 8.1/8.3 with MySQL 8 |

Recovery adds storage to Commerce. Fresh setup installs it through the canonical
Commerce schema function. Existing installations use the application's Upgrade
page before processing payments with this release. A partial or failed upgrade
keeps payment processing unavailable; it cannot acknowledge a payment without
the recovery tables. Re-running a completed upgrade does not rescan historical
checkouts. Legacy identifiers are backfilled only where existing records prove
their meaning; an ambiguous completed legacy Square checkout requires review.

Run the existing `cron/commerce-v800.php` regularly for provider reconciliation
and durable recovery, including cases where no browser returns. Browser request
housekeeping performs bounded local work and does not make unrelated pages wait
for provider network calls. A lost hosted-checkout creation response is retried
by the same prepared caller intent with its stored provider request and key;
cron does not create new hosted checkout sessions. Tests use fixture provider responses and do not
charge, capture or refund a live payment instrument.

Provider contracts informing this section:

- [Stripe webhook delivery and duplicate events](https://docs.stripe.com/webhooks)
- [Stripe Checkout fulfillment](https://docs.stripe.com/checkout/fulfillment)
- [Square retrieving a payment from an Order](https://developer.squareup.com/docs/payments-api/retrieve-payments)
- [PayPal webhook delivery](https://developer.paypal.com/api/rest/webhooks/rest/)

### PA1R / PA2R: independent re-audit

Re-audited merged `main` at `50699a2e1012e19379d81c0e1b317a90d2575e61`,
including the actual owner endpoint, public authority checks, external grant
history, provider evidence adapters, recovery transactions and customer review
state. Re-ran the existing focused suites: 408 messaging/isolation checks and
551 Commerce checks passed locally with SQLite and controlled transports.
This verifies those bounded cases; it does not establish live provider setup.

The review found an additional responder race: owner pickup and delegation
could return a retained synchronous conversation to `open` before old inference
finished. That old response then published, even if the owner had answered.
The hidden canonical authority binding now carries a rotating response token.
Visitor admission and owner operations invalidate older synchronous inference;
the publication transaction checks the token as well as current authority.
Both native and external synchronous paths have regression coverage. A retained
native caller entering a durable thread joins that same queue instead of
starting competing inference. No second messaging store is introduced.

The PA3 review also found a mutable CRM session could name a different member
on an older visitor card. Labels now require the session's disclosed principal
to match the admitted turn. Duplicated browser tabs can inherit sessionStorage;
each document now receives a separate presence identity while the server keeps
the original pickup deadline. These cases extend the acceptance suites.

The expanded local authority suite passes 127 SQLite cases. The MySQL matrix
also includes the non-UTC clock case. Commerce code is preserved. The remaining
known attention snooze/replay and Brain projection concerns remain scheduled;
this text release does not claim those features are complete.

### PA3: owner pickup and main Chat canvas

PA3A introduces explicit pending pickup, owner handling, Agent handling, and
resolved states with a durable deadline and compare-and-set ownership. Handle
missing delivery acknowledgement, page reload, multiple owner tabs, multiple
visitors, owner disconnect, and owner/Agent completion races. Preserve the visitor
thread when control changes. Label owner and Agent replies honestly.

PA3B renders the visitor's identity/pseudonym, page, recent messages, status and
pickup countdown in a card within the main canvas. Provide Answer, Send,
Delegate, and Resolve controls. Keep drafts and card focus when messages arrive
or the owner continues private Chat. PA3A and the text portion of PA3B ship
together because the visible card is necessary to prove delivery before the
15-second clock starts. Explicit voice commands such as “Tell Jamie …” join PA4
and must resolve to one current visitor conversation before sending.

Native Profile requests enter the durable handoff path. Retained native service
callers entering an existing durable thread join its canonical queue. External
WebMCP and older direct threads retain their synchronous contract, including
current public authority, source rechecks and the response-token fence. They do
not acquire the native browser's conversation credentials or an implicit
owner-forwarding path.

The execution path uses the existing Agent job engine. A bounded exact-turn
POST can dispatch eligible work on shared hosting; a filtered worker supports
background recovery without waiting behind unrelated image jobs. Read-only
polling never starts paid inference. Worker retries can repeat inference after
an uncertain provider response, but fencing permits only one published reply.

| Gate | Required behavior | Evidence |
| --- | --- | --- |
| 1 | Save the exact visitor message, attention, handoff turn and canonical work atomically; retries reuse the original request | Real SQL failure injection and production HTTP |
| 2 | Use fresh visible main Chat presence and Available/Away/DND; reject stale heartbeats and unavailable owner signals | Per-tab sequence, visibility, account and Agent authority fixtures |
| 3 | Acknowledge only the exact owned visible card, delivery token, turn revision, private conversation and tab | Actual HTTP plus production UI in DOM and Chromium |
| 4 | First acknowledged delivery sets exactly 15,000 server milliseconds; duplicates and other tabs cannot reset it | Frozen server clock, boundary and multi-tab tests |
| 5 | Owner operation and timeout have one versioned winner; replayed owner replies cannot send twice | Independent MySQL workers with observed row-lock contention in both orders |
| 6 | Owner pickup/reply/resolve and changed canonical job authority fence late generated replies | Paused inference and exact run/action/approval/lease mutation fixtures |
| 7 | Recheck current public Profile, accepted visitor and every source dependency before inference and publication | Real authority queries, transitive history revocation, retained PA1 tests |
| 8 | Recover abandoned work and failed answer/receipt commits; distinguish saved, waiting, running and confirmed replies | Canonical job leases, rollback, uncertain response and reload fixtures |
| 9 | Isolate visitors, tabs and ordered turns; Agent handling avoids repeated pickup delays; old epochs cannot restart | SQL, mobile/browser and stale-response fixtures |
| 10 | Only an explicit targeted card reply reaches the visitor; ordinary private text/voice stays private | Actual HTTP, main Chat and native widget boundary tests |

Fresh Setup installs the handoff storage and canonical job schema. Existing
deployments should run Upgrade once; the native admission path also initializes
missing handoff tables outside its transaction when the existing job engine is
ready. A partial schema cannot accept a visitor message. Reload both main Chat
and Profile pages after extracting the updated ZIP.

For background recovery, the existing runner accepts
`php agent-worker-v1910.php --executor=profile`. It has a separate execution lock
from slow general Cloud work and can be scheduled through the deployment's
existing CLI/cron arrangement. Active visitor pages drive eligible exact-turn
POST dispatch, so a dedicated daemon is not required for the interactive path.
If every browser closes, automatic processing waits for the configured worker.
The 15-second deadline determines eligibility; provider response time is extra.
Explicit canonical job pause/resume remains authoritative.

The local PA3 focused suite passes 101 core handoff checks, 46 canonical binding
and provenance checks, 24 recovery checks, 57 production HTTP checks, 50 owner
UI runtime assertions, and 28 native visitor UI assertions (306 total). The
expanded Upgrade fixture passes 24 cases, and the retained Recovery Baseline
passes. The PR additionally runs the SQL and HTTP fixtures with MySQL 8 on PHP
8.1 and 8.3, including independent-process takeover races, plus both actual
browser interfaces in Chromium with mobile viewport checks. These controlled
tests do not call a live model, voice, payment or media provider.

### PA3D: explicit Agent or live owner text chat

The public Profile chat defaults to Agent chat. A visitor can check a live-text
request option only while the server reports the owner available. The checkbox
prepares the next message's mode; it does not silently create a request. Existing
Online/Offline, Allow user-to-user chat, Profile Agent acceptance and fresh
visible main Chat presence remain authoritative. The rail's Chat Settings form
keeps its layout and controls. Existing PA3 Away/DND can narrow availability.

An available live request enters the existing acknowledged 15-second pickup
window. The owner card offers Accept/Decline. Acceptance or a targeted owner
reply pauses automatic Agent responses. Decline, elapsed pickup, visitor end,
owner end, or lost owner availability returns the same public conversation to
the Agent. A delayed follow-up from an already closed live chat cannot start
another request; only a fresh explicit choice can. Agent-only messages start
without an owner pickup delay. Message
speaker labels distinguish visitor, Agent and owner. Private Chat stays private.

| Gate | Required behavior | Evidence required |
| --- | --- | --- |
| 1 | New widget defaults to Agent chat and sends an explicit immutable Agent/live intent; old pending retries retain their original mode | Native runtime and reload/uncertain-response fixtures |
| 2 | Public live availability reuses current Online and user-chat preferences plus exact fresh Chat presence, without private metadata or schema writes | Production SQL setting/permission and safe-projection fixtures |
| 3 | Agent-only turns skip pickup and do not ring a new live visitor card; ordinary Profile inbox/history remain available | Canonical SQL, owner-list and native widget fixtures |
| 4 | Available live requests reuse the exact server-owned 15-second deadline; repeated messages, reloads and two tabs cannot extend it | Deadline, delivery and multi-tab fixtures |
| 5 | Accept/reply, decline and timeout have one canonical winner; late Agent inference cannot compete | Version/lease fences, controlled inference and MySQL races |
| 6 | Either side can end the direct chat and return to Agent in the same thread; stale visitor end cannot terminate a newer request | Exact-principal, revision, retry and transcript fixtures |
| 7 | Offline, disabled user chat, revoked Profile/Agent access and disconnects cannot accept new live chats; cleanup still works | Settings/presence and in-flight revocation fixtures |
| 8 | Mode changes and retries cannot duplicate messages, reinterpret a saved request, revive obsolete work or mix visitors | Atomic admission, failed commit, retry and two-visitor fixtures |
| 9 | Both UIs show truthful requested/accepted/declined/ended states and real speaker names with inert text; drafts and private composer remain isolated | DOM and real Chromium, including mobile viewport |
| 10 | Supported PHP/SQLite/MySQL, retained contracts, required CI and post-merge checks pass; flat deployment ZIP matches merged source | PHP 8.1/8.3, MySQL 8, Chromium and release verification |

This section adds durable choice/status columns through the existing handoff
schema installer. Existing installations run Upgrade once. Missing fields fail
closed; public GET reads never initialize storage. Live media and synthesized
visitor announcements remain later sections.

### PA3C: Profile voice calls and Agent assistance

PA3C implements the browser audio path. Open an accepted live-text conversation
to offer a call. Both participants act on the call card before media starts.
The server binds the room, expiring microphone grant and connected browser tab to
the exact owner and native visitor. Live text stays available during and after
the call. Leaving the text thread closes its call and queues room cleanup.

Enable **Agent-assisted phone chat** in the existing Chat Settings gear. This
defaults to off and affects new calls. An assisted offer displays the named
Profile Agent and transcription notice in public Profile chat. The right rail
shows the Agent and opens a slide-out with speaker-labeled transcript, sourced
public research and an **Agent assistance** switch. The switch affects only
the current call. Pausing fences transcript writes and research responses in
the same transaction. The worker unsubscribes on its next lease refresh and
enforces a hard eight-second local lease if the server cannot be reached. The
human call and already saved material stay available. Re-enabling a disclosed
call creates a new capture revision. A stale switch response cannot revive a
newer paused revision.

The updated LiveKit worker assigns the owner's and visitor's authorized audio
tracks to independent streaming speech-to-text sessions. Final transcript
segments go directly into the owner's existing Transcriptions workspace without
retaining call audio. The drawer links to the canonical transcript for timeline,
entities, action and Knowledge workflows. An owner-entered research question
uses the existing public research provider and stores cited results for this
call. The question is the only call content passed to public research. The ring
pulses only for confirmed worker capture or an active research request.

Run Cloud Upgrade once to install the preference, call and assistance tables.
Configure the existing VP3 LiveKit settings and run the existing Profile runner
for durable room cleanup. Deploy the updated LiveKit worker before enabling
`VP3_PROFILE_CALL_AGENT_ENABLED=1`. Assistance requires the owner to have
Transcriptions access and a Cloud-compatible compute policy. A paired Automatic
account uses an explicit VP3 Cloud preference for this guest audio path. See the
[worker setup](../workers/vp3-meeting-agent/README.md).

| Gate | Required behavior | Evidence |
| --- | --- | --- |
| 1 | Default-off saved preference, exact named visitor notice and compatible existing settings | SQL and Chromium settings tests |
| 2 | Exact owner, visitor, conversation and browser authority | Production HTTP tests for sessions, grants, blocks and password changes |
| 3 | One accepted call, deadline, replay handling and microphone-only room grants | SQLite and MySQL-compatible HTTP fixtures and independent-connection race |
| 4 | Controls, keyboard and mobile, no mic while ringing, local teardown and fallback | Chromium and controlled media transport |
| 5 | Separate human audio tracks and canonical speaker-labeled transcript with bounded worker authority | Worker runtime and production HTTP ingest |
| 6 | Owner-only right rail, paginated transcript, truthful activity and cited research | Chromium, SQL and production HTTP |
| 7 | Per-call pause, resume, stale switch/worker/research fencing and preserved human media | HTTP, worker and research regression fixtures |
| 8 | Existing live-text, meeting, settings and recovery contracts, with a complete flat archive layout | Local regression and production packaging smoke |
| 9 | Exact-head PHP 8.1/8.3, MySQL 8 and Chromium CI, merged source and verified GitHub deploy ZIP | PR, main and package evidence |
| 10 | Live two-device WebRTC audio and streaming STT with overlap, pause/resume and disconnect | Supervised staging call with configured LiveKit and worker |

#### Future media and voice participation

Camera sharing requires separate publication permission and controls. Spoken
Agent answers need their own output track and turn-taking policy. Twilio and
SIP can support a later telephone path with carrier setup, caller authority,
and cost disclosure. Keep live-provider certification separate from controlled
SQL, HTTP and browser test results.

Provider contracts for the call adapter:

- [LiveKit access tokens and room/source grants](https://docs.livekit.io/frontends/reference/tokens-grants/)
- [LiveKit participant management and removal](https://docs.livekit.io/intro/basics/rooms-participants-tracks/participants/)
- [LiveKit camera and microphone publication](https://docs.livekit.io/transport/media/publish/)

### PA4: speech, notifications and Brain

PA4A adds visitor voice assignment, distinct owner/visitor speaker cues, one
arrival announcement per session, and safe playback interruption/queue behavior.
Reuse existing ElevenLabs credentials and provider settings. A voice selection
must not grant access to another account's saved voice clone.

PA4B makes presented/read/snoozed/handled/ignored states consistent. Snoozed
attention must wake after expiry even if it was previously presented. A stale
poll result cannot present an item already handled or ignored. Replay cannot
silently switch the owner's private Chat conversation. Failed reads cannot be
reported as “caught up.” Repair a missed Brain projection from durable event
evidence, with visitor, Agent and owner statements and actions attributed
separately.

### PA5: checkout in ordinary Profile conversation

Checkout already exists in Profile Commerce pages and native WebMCP tools. The
ordinary public Profile chat currently supplies approved product information and
checkout links; it does not yet perform the whole canonical checkout flow in a
conversation card.

Build product selection, a complete price/provider/terms review, explicit buyer
confirmation, the canonical checkout handoff, verified payment status, and
receipt/delivery cards into the same visitor thread. The Agent may prepare a
purchase, but only the buyer's explicit confirmation authorizes it. Preserve
owner handoff during a sale. Do not infer payment success from a browser return
or an Agent's completion text. Keep appointments on the Scheduling payment path
and supported products within the existing Commerce fulfillment capabilities.

## Validation and deployment contract

Each section is released only after its gates and exact-head PR checks are
green. GitHub records the merge and postmerge checks; the flat production ZIP's
`VP3_RELEASE.json` identifies the exact commit and packaging run. Update the
existing deployment download only after verifying the artifact digest, archive
integrity and every packaged source file against that exact merged source tree.
