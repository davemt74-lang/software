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

The owner and visitor can also talk directly. Live text uses the same explicit
visitor card and durable public conversation. PA3C adds an in-browser
voice call with optional video: either participant can offer a call, the other
must accept, and each controls their own microphone and camera. The owner stays
in the main Chat canvas; the visitor stays in the Profile chat. An unanswered or
declined call leaves the Agent available. Accepting a call atomically claims the
conversation so a late Agent answer cannot interrupt the people talking.

Live calls carry the participants' actual voices. ElevenLabs synthesis remains
for Agent speech and reading typed visitor messages. Recording, transcription,
and Agent participation are off for a direct call unless a later, explicit
feature enables them with clear participant notice. Browser calling is distinct
from calling a real telephone number through the existing Twilio integration.

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
| PA3D | Visitor chooses Agent chat or requests live owner text; owner accepts or declines using existing Chat Settings | Released in PR #698; acceptance gates below |
| PA3C | Direct browser voice and optional video between the exact visitor and owner; consent, call controls, reconnect, and Agent handoff | Released in PR #699; all 16 PR and 15 post-merge workflows passed |
| PA3C assistance | Profile Agent transcript and research on the canonical voice call, with pre-call notice and owner pause control | Production media gate below |
| PA4A | Arrival/chat announcements and distinct ElevenLabs visitor voices | Released in PR #701; all 16 PR and 15 post-merge workflows passed; 10/10 bounded acceptance gates |
| PA4B | Notification and Brain state; snooze/wake; routing; replay and projection repair | Implemented with the ten release gates below; exact-revision CI establishes acceptance |
| PA5 | Ordinary public Profile conversation checkout using the canonical Commerce order/payment/receipt path | Acceptance gates below |

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

The merged PA3 release is commit
`d1bd983fa41020beeb75c3e4701cd98d84dba0ff`. All 16 PR workflows and all
15 post-merge workflows passed. Both MySQL/PHP matrix jobs passed 33 recovery
checks including observed row-lock races; Chromium passed 21 main Chat and
11 native visitor checks, retaining PA1's 58 and PA2's 31 browser checks. The
flat production artifact from run `41691110705` was verified against all 1,745
application source files; SHA-256
`0192659045e5946fbe27d43730926e26b52b2aef3e95a9b5f5a51bde94690750`.

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
closed; public GET reads never initialize storage. Direct media follows in
PA3C, with synthesized visitor announcements in PA4A.

### PA3C: direct voice and video

Repository audit found an existing LiveKit media configuration, server-side
JWT signer, a room-deletion wrapper, and browser audio/video controls in
`includes/video-meetings-v1800.php`,
`api/video-meeting-token.php`, and `video-meetings-v1800.js`. Reuse the narrow
media infrastructure with a new Profile call authority adapter. An existing
meeting invitation is not proof of permission to join a visitor conversation;
the new adapter must verify the exact Profile visitor binding and owner on every
call mutation and token issue. Narrow CreateRoom, ListParticipants and
RemoveParticipant adapters are new work; the old participant list reads local
meeting records and does not administer provider participants. Do not create calendar appointments, paid
bookings, CRM identities, recordings, or Agent transcription merely to connect
two people.

This section initially supports explicitly enabled LiveKit Cloud projects with
secure transport. Short-lived join tokens and room deletion alone do not prove
immediate revocation of cached tokens on stock self-hosted LiveKit. Existing
meeting credentials do not silently enable public Profile calls. The Admin
surface separates configured credentials, an acknowledged human-only project
configuration, and the last successful API authentication check. The check does
not prove microphone/camera connectivity or the absence of a future deployed
worker. A human-only project must not auto-dispatch unnamed Agent workers or
automatically record rooms; an empty CreateRoom agents array does not suppress
unnamed auto-dispatch. Unexpected provider participants fail closed.

Each call retains an encrypted server-only snapshot of its original provider
configuration. Disabling calls or rotating credentials prevents further joins
and schedules closure using that original project, not the replacement project.
Ending a call denies new grants immediately, then the canonical job engine
retries participant revocation and room deletion until the provider has confirmed
closure. While that is pending, the interface reports cleanup pending rather
than claiming the other participant was disconnected. A configured recovery
worker is necessary when both browsers have closed.

The public widget offers **Voice call** and **Video call** only when the service
is ready. The main Chat card shows the caller, call type, and Accept/Decline.
The owner can make the same offer from the card. Neither a page visit nor an
incoming offer opens a microphone or camera. Voice calls start with camera off;
video requires the participant's explicit selection and browser permission.
The card shows ringing, connecting, live, reconnecting, ended, or unavailable
from actual call state, with mute, camera, and hang-up controls.

Use an immutable call ID and revision tied to the conversation, conversation
epoch, owner, and accepted visitor principal. Bind each participant token to one
call room and role, with only the allowed microphone/camera publication sources.
Use short-lived join tokens, never expose provider secrets, and do not treat
token expiry as disconnection of an already connected participant. On hang-up
or revocation, stop local tracks immediately, reject further tokens, close the
room through the provider, and retain a durable retry obligation if provider
cleanup fails. Ended rooms are never reused by a subsequent call. Account or
Profile authority changes must terminate/revoke the call rather than merely
hide its controls.

The owner can have one accepted live call at a time. Other visitor conversations
remain isolated and can continue with the Agent. Repeated offers are bounded
per visitor and owner and cannot restart the pickup window or create an
unlimited ringing stream. Decline/cancel and late acceptance compete against a
server-owned deadline. A failed connection gives both people a truthful status
and a clear path back to text or Agent assistance; it cannot silently leave the
conversation claimed forever. Ending a call preserves its text thread and
offers the owner explicit continued text handling or delegation.

After a failed or ended call, an explicit **Return to Agent** action is available
to the exact visitor or owner only while the handoff is still the one installed
by that call's acceptance. It must not override a later owner reply, claim or
resolution. Local media stops before returning to Agent assistance. Any pending
provider cleanup remains a separate durable obligation and never permits the
ended media session to resume.

| Gate | Required behavior | Evidence |
| --- | --- | --- |
| 1 | Exact current visitor and owner authority for every offer, accept, poll, token, and end; no borrowed meeting invitation | Real SQL and production HTTP isolation tests |
| 2 | Both parties explicitly agree; browser media starts only after local consent; voice never implicitly enables camera | DOM and Chromium permission/media fixtures |
| 3 | Room and participant grants cannot join another call or gain screen-share, moderation, recording, or Agent privileges | Token claim and forged-grant tests |
| 4 | One active call per owner; competing accepts, visitor retries, and multiple tabs cannot create duplicate sessions | Independent MySQL connection races and replay tests |
| 5 | Accepted call and Agent/owner turn handling have one winner; late model output cannot interrupt the call | Paused-provider and handoff race tests |
| 6 | No answer, decline, cancel, unavailable service, or failed media retains the visitor thread and a usable Agent/text fallback | Timeout and failure integration tests |
| 7 | Hang-up, logout, blocked visitor, or revoked Profile authority stops tracks, denies new tokens, and retries failed room closure | Browser teardown, revocation and durable recovery tests |
| 8 | Reload/reconnect cannot revive an ended call or switch visitors; current connection state remains accurate | Network interruption, stale event and mobile browser tests |
| 9 | Unconfigured LiveKit, denied microphone/camera, autoplay limits, and mobile layout have usable truthful states | Readiness and browser fixtures; live-device verification reported separately |
| 10 | Private Chat remains private; no implicit recording/transcription, synthesized voice substitution, or telephone purchase/dial | Cross-surface boundary tests and retained PA1/PA3 regressions |

The focused local suite passes **410 checks**: 153 production call-state and
recovery checks, 79 provider-contract checks, 85 actual HTTP API checks,
16 actual Admin HTTP checks, and 77 interface runtime assertions. Supporting
credential recovery passes 98 checks and the upgrade page passes 28 cases.
The CI matrix adds independent MySQL acceptance races on PHP 8.1 and 8.3 and
the actual Chromium call-interface runner, alongside retained PA1, PA2 and PA3
coverage. Provider HTTP and browser media are controlled fixtures; no live
provider call is used to establish these results.

The merged PA3C release is commit
`5429f484ee639e7ce44bcef759c8a31bf53a8cd3`. All 16 PR workflows and all
15 post-merge workflows passed. MySQL on PHP 8.1 and 8.3 passed 21 independent
acceptance/replay concurrency checks per version, with observed row-lock waits.
Chromium passed 30 calling checks and 42 owner visitor-card checks using the real
Chat and voice scripts in production order. Retained PA1, PA2, native handoff and
explicit Agent/live-choice browser suites passed 58, 31, 11 and 21 checks.
The flat artifact from run `41708765620` was verified against all 1,752 packaged
application source files; SHA-256
`83d3d24184ad4f1749c85ef155b705ef02bbea01b93cdae871d6f91edd65e139`.

The recovery cases include existing account blocks before admission and during
a call, an account switch during a slow Join, delayed microphone/camera
permissions after hang-up, lost responses, and a CreateRoom response that
arrives after cleanup. A late possible room creation invalidates the old proof
and retains an exact-room cleanup obligation. A completed cleanup gets a new
canonical generation; a pending or manually paused cleanup retains its existing
job controls. Repair never ends or releases an already accepted successor call.

Integration with PA3D keeps ordinary messages owner-directed during a call and
after a simple hang-up. A fresh explicit Agent choice remains available after
media ends. Live-text expiry and a hidden Chat tab cannot delegate an ongoing
media call; explicit Offline, disabled user chat, permissions and account blocks
still govern media authority. Accepting a call does not relabel an earlier
declined or expired text request as accepted.

Owner visitor and call cards use Chat's canonical identity and canvas controller
when the voice runtime installs its separate controller. The runtime and browser
fixtures load the actual Chat and voice scripts in production order, verify that
the voice controller is active, and retain the exact nonzero Agent and private
conversation when mounting saved cards. Blank Chat initialization also waits for
a pending voice submission.

Existing Twilio telephone and SIP adapters provide a future route to actual
phone numbers. That follow-on needs its own exact-number confirmation,
availability and caller-identity rules, cost disclosure, and carrier setup
verification. The browser-call section does not provision numbers or place
telephone calls. Existing LiveKit code also does not prove deployment readiness
or installed microphone/camera performance; automated and live-provider/device
evidence must be reported separately.

Provider contracts for the call adapter:

- [LiveKit access tokens and room/source grants](https://docs.livekit.io/frontends/reference/tokens-grants/)
- [LiveKit participant management and removal](https://docs.livekit.io/intro/basics/rooms-participants-tracks/participants/)
- [LiveKit camera and microphone publication](https://docs.livekit.io/transport/media/publish/)

Deployment steps for PA3C:

1. Upload and extract the flat Cloud ZIP, run `/upgrade.php` once, and reload
   Chat and the public Profile page. The canonical upgrade installs the four
   call tables and checks the existing social-block schema.
2. Open **Admin → Profile voice & video**. Save dedicated LiveKit Cloud
   credentials, or explicitly choose the existing supported meeting project.
   Confirm its human-only configuration and run **Check metadata API**. This
   does not create a room or access a microphone.
3. Keep the Profile recovery worker configured, for example a hosting cron
   invoking `php agent-worker-v1910.php --executor=profile`. Active call views
   can drive eligible work; the worker handles obligations after both browsers
   close. Cleanup timing then depends on that worker schedule and provider
   availability.
4. With the owner Available in Chat, use two consenting browsers on HTTPS to
   verify an actual audio call, optional video, hang-up, denied permissions and
   an interrupted connection. Automated transport and media fixtures do not
   establish the deployed account's media connectivity or iPhone microphone,
   speaker and camera behavior.

Call IDs and public/private conversation markers persist across reloads. Media
tokens stay in memory and restoring a card never reconnects automatically.
Network reconnection in the same open tab keeps its participant binding; a new
tab cannot replace that participant. A reload can require ending the previous
call and making a new offer after cleanup. An old ended call cannot be resumed;
a new offer creates a new room. A cleanup-pending card
retains the owner's busy slot until closure is proved. A stored closure proof
lets a failed completion-receipt write retry without pretending that a later
missing-room response proves participant revocation.

### PA3C assistance: disclosed voice calls

The owner opts in under Chat Settings. The voice call control displays the
named Agent and explains that it can listen and transcribe before the visitor
places or accepts the call. The ordinary Call and Accept controls carry the
current notice automatically. Each offer binds its disclosed assistance to
that specific call.

The canonical call engine owns invitations, participant authority, reconnect
and cleanup. An assisted voice call can admit one hidden Agent identity bound
to the call and the verified LiveKit Cloud project. The worker selectively
subscribes to the two expected microphone tracks. Ordinary calls and video
calls retain the two human participants. The Agent drawer shows capture and
research activity to the authorized owner. Pausing stops new transcript writes
immediately, releases microphone subscriptions on the worker's next lease
check and leaves the human call active. Ending or revoking the call removes
the Agent's access.

Deployment requires the normal PA3C setup and an explicitly named Profile
worker registered on that same project. Follow the Profile worker configuration
in [the worker README](../workers/vp3-meeting-agent/README.md). Before certifying
live operation, verify on two devices that audio flows in both directions, the
two speakers are labeled separately, pause and resume preserve the conversation,
hang-up stops capture, and cleanup removes the room.

### PA4: speech, notifications and Brain

PA4A adds visitor voice assignment, distinct owner/visitor speaker cues, one
arrival announcement per session, and safe playback interruption/queue behavior.
Reuse existing ElevenLabs credentials and provider settings. A voice selection
must not grant access to another account's saved voice clone.

PA4A reuses the existing provider configuration in `api/agent-voice-v117.php`,
`api/admin-elevenlabs-v102.php` and `includes/ai-settings.php`. It keeps visitor
voice assignments separate from Studio voice profiles and offers a voluntary
voice preference. It does not derive that preference from a name, photo or
recording.

The following acceptance gates define this section's release score. All 16 PR
workflows passed on commit `526916f4d0b9c58ced51f00fc5477a779762167d`, including
45 actual Chromium assertions and the MySQL gates on PHP 8.1 and 8.3. Live calls continue to use actual
participant audio; synthetic announcements and typed message playback yield to
an accepted human call.

The implementation uses separate durable visitor voice assignments, immutable
speech source items, and provider attempts. Arrival identity comes from the
actual native visitor principal and an explicit arrival generation, independently
of mutable CRM recognition. Reloads, polls and duplicate notifications cannot
create another automatic announcement. An Agent-only chat may receive a factual
arrival announcement when the owner's current settings permit it, without
requesting owner pickup or changing the public responder. Message speech is
resolved from the exact saved public visitor message; browser requests contain
selectors and delivery proof, never arbitrary speech text or provider voice IDs.

Reuse the existing ElevenLabs key and current provider settings. Voice choices
come from the current default catalog or a bounded list of explicitly
Admin-approved public-library presets with validated provider origin and access.
Personal and workspace clones cannot become visitor presets. This second path
also serves newer provider accounts: ElevenLabs documents that its older Default
voices are limited to accounts created before March 2026 and retire on December
31, 2026. Do not infer a replacement voice or its style from a previous voice ID.
See [Default voice migration](https://elevenlabs.io/docs/help-center/product/voices/my-voices/what-are-default-voices)
and [voice metadata](https://elevenlabs.io/docs/api-reference/voices/get).

A provider attempt is durably marked `sending` before network I/O. Abandoned or
uncertain sends become `outcome_unverified`; neither read polling nor the worker
starts another synthesis. Explicit retries are linked new attempts and disclose
possible additional quota use. Completed audio is private, bounded and temporary,
and serving it requires fresh source and owner authority. Provider completion,
client-reported playback and notification handling remain distinct facts.
Record provider-reported character cost and request/trace IDs when supplied;
never fabricate a token count or dollar cost. See
[generation metadata](https://elevenlabs.io/docs/api-reference/introduction) and
[the speech endpoint](https://elevenlabs.io/docs/api-reference/text-to-speech/stream).

Visitor reply dictation explicitly arms one current visible card and stages its
transcript for review through the existing public reply boundary. It must not
inherit an unsent private composer draft. An occupied private composer, ordinary
typing during capture, changed Chat/visitor authority or an accepted human call
cancels that visitor capture route while preserving private text. The existing
Stonefellow voice/playback source remains unchanged.

Playback starts with a 30-second authorization lease. Increasing client report
sequences can renew it within the saved audio expiry and an absolute ten-minute
limit; duplicate reports cannot extend it. The client measures the deadline from
request-send time and stops on a missed renewal, changed source or accepted call.
Only an explicit subsequent Play can reuse completed audio. An uncertain request
keeps its original identity, and an automatic announcement cannot replay after a
lost playback acknowledgement.

When a browser rejects playback because it needs a fresh gesture, the dedicated
player retains the prepared audio and current permit and offers **Tap to play**.
That button calls the same element's `play()` directly inside the click, before
any request or asynchronous wait. Pending playback does not emit speech-start
events or renew an unplayed permit. The existing shorter client authority limit,
source checks and call/context cleanup still apply. An expired server permit is
projected as interrupted even before scheduled maintenance, without changing its
saved receipt; a new explicit claim may reuse a valid cache, while expired audio
still requires a separately confirmed generation. See WebKit's
[gesture-triggered media guidance](https://webkit.org/blog/6784/new-video-policies-for-ios/)
and [transient user activation](https://webkit.org/blog/13862/the-user-activation-api/).

The exact saved arrival also owns narration in the older notification presenter.
That suppresses a second spoken announcement without marking its notification
read, handling its attention item, creating another Chat turn or acknowledging
the doorbell. Historical notifications without a linked speech item keep their
existing presentation path.

Local evidence: core SQL 123, provider/storage 76,
actual speech API HTTP 83, actual Admin HTTP 23, UI runtime 94 and notification
presentation 11 checks passed. Fresh-install normalization passed 8 checks and
Upgrade readiness passed 35 cases; the retained
full Recovery Baseline and production Cloud worker/API suite also passed. The
independent-process MySQL runner passed 38 checks with observed row-lock
contention for competing claims, request replay and both call/playback orderings.
Both PHP versions passed the installation, core, notification, provider and
production API suites on MySQL without skipping PA4A gates. Browser tests
use actual production Chat and voice scripts with simulated external boundaries;
no live provider request, physical microphone or device connectivity is claimed.

PR #701 merged as `25ae238f3a8d38d8735156223311f0cfc604acbe` after all 16 PR
workflows passed. All 15 post-merge workflows also passed. Packaging run
`41725126831` produced the verified flat ZIP with 1,758 source files, all compared
byte for byte to that merge. Existing installations run Upgrade once, then reload
Chat. Admin's Profile speech page uses the existing encrypted ElevenLabs
settings and validates any approved visitor presets. Live provider and physical
iPhone behavior remain deployment checks rather than automated test claims.

| PA4A gate | Required behavior |
| --- | --- |
| 1 | Announce a currently authorized Profile visitor/chat arrival once per durable visitor session, without treating a page refresh as another arrival |
| 2 | Route arrival and message announcements to the exact owner's current Chat and preserve the current private conversation |
| 3 | Respect Available/Away/Do not disturb, audio preferences, hidden tabs, browser autoplay restrictions and current human calls |
| 4 | Give each visitor a stable permitted ElevenLabs voice distinct from the contacted owner/Agent voice |
| 5 | Match a supplied voice category or explicit visitor profile preference; anonymous/unknown visitors use a selectable preset with no sex/gender inference |
| 6 | Read only authorized public visitor content with correct speaker attribution; never send private Chat, Brain context or unrelated visitor text to speech |
| 7 | Bind queued playback to current owner, visitor conversation, message and voice; stale or revoked items cannot play after a context change |
| 8 | An explicit spoken owner reply resolves to one current visitor and uses the same targeted public reply boundary as the text card |
| 9 | Preserve visible text and useful retry/error states when voice is unavailable; account for provider usage without claiming exactly-once provider billing |
| 10 | Verify replay, interruption, capture exclusion, simultaneous visitors, account changes and mobile browser behavior without live provider calls |

### PA4B: notification lifecycle and Brain recovery

PA4B makes presented/read/snoozed/handled/ignored states consistent. Snoozed
attention must wake after expiry even if it was previously presented. A stale
poll result cannot present an item already handled or ignored. Replay cannot
silently switch the owner's private Chat conversation. Failed reads cannot be
reported as “caught up.” Repair a missed Brain projection from durable event
evidence, with visitor, Agent and owner statements and actions attributed
separately.

The focused audit reproduced four failures across the existing query and
presentation paths: handled items could create a new Chat turn, expired snoozes
could remain invisible after their previous presentation, replay could reopen an
older private conversation, and an unavailable notification table could appear
to be an empty successful inbox. Brain archival also happened after Chat commit
without an exact retry obligation, so a lost archival write could stay missing.

The PA4B implementation separates canonical attention disposition, read
generation and visible-card acknowledgement. State revisions reject stale
operations; each due snooze receives one new wake generation. The current
generation is unread until an explicit read action, independently of whether its
card has been displayed. Handled and ignored attention cannot return to pending
through a delayed acknowledgement or stale replay. Read endpoints inspect saved
state; bounded maintenance or an explicit presentation request materializes a
due wake.

Each display is bound to one exact owner, notification, attention item, source
event, public conversation and generation. A neutral private Chat marker refers
to that source; public visitor instructions are not copied into trusted assistant
history. Current source resolution retains visitor, owner and Agent attribution,
checks the original native authority, and respects current access and identity
disclosure. Aggregate contact recognition never substitutes for that evidence.
Historical sources whose binding cannot be proved remain unverified.

The owner keeps the selected private Chat and any unsent draft. A delivery saved
in another conversation is reported as elsewhere, rather than automatically
opening that conversation. The drawer and Profile portal share the same strict
API contract and lifecycle controls. Bounded Previous/Next pages keep older
attention and handled history reachable; navigating the list does not present a
new Chat card or acknowledge a visitor. Partial and failed reads preserve the last
known display with a visible retry state; “caught up” requires complete current
counts showing no unread or outstanding attention. PA3 doorbell pickup and PA4A speech keep their existing ownership of those
interactions; generic notification replay grants no new speech permission.

Local projection receipts distinguish a source event recorded, a private Chat
marker saved, and an exact Brain archive row saved. The repair worker reads
current source authority, writes the intended local destination and verifies its
receipt in one transaction. It does not execute a public reply, provider request,
workflow action or payment. Brain uses the exact private Chat message ID and
neutral marker, while typed public-source references retain speaker attribution.
Conflicting destination evidence is reported for review instead of overwritten.

Fresh setup and Upgrade install the lifecycle fields, operation/delivery tables
and local projection receipts. The existing Cloud/Profile worker performs bounded
snooze and projection maintenance. An incomplete schema is reported as
unavailable, and worker execution never installs tables. Production release still
requires the following ten gates and all exact-commit CI workflows to pass.

| PA4B gate | Required behavior |
| --- | --- |
| 1 | Durable source events bind the exact owner, visitor, public thread and action; aggregate contact recognition never grants conversation access |
| 2 | Presented, read, snoozed, handled and ignored are separate durable states with explicit transitions |
| 3 | Snooze expiry makes an unhandled item eligible again without duplicating the underlying event |
| 4 | A stale poll or delayed acknowledgement cannot revive handled, ignored or revoked attention |
| 5 | Multiple tabs and retries produce one authoritative notification outcome and avoid duplicate audible announcements |
| 6 | Public visitor, human owner and Agent statements retain accurate attribution in Chat and Brain |
| 7 | Repair a missed Chat/Brain projection from saved event evidence, without replaying the public action or payment |
| 8 | Notification replay and backlog paging preserve private Chat selection, unsent drafts and exact public reply targets; all owned items remain reachable |
| 9 | Failed/partial reads remain visibly unsuccessful rather than reporting that all attention is handled |
| 10 | SQL, HTTP and browser fixtures cover expiry, concurrent state changes, projection failure, account revocation and retained doorbell/call behavior |

The focused local suite passes **332 checks**: 79 source/lifecycle SQL checks,
93 local projection/recovery checks, 59 production HTTP and notification-page
checks, 87 interface runtime assertions, and 14 actual installer/recovery checks.
The Upgrade fixture passes 41 cases. Retained historical owner HTTP checks pass
82 assertions and retained Profile notification integration passes 35. The
65-source backlog cases cover every page, current active priority, handled/read
history, and navigation without a presentation or acknowledgement.

Release CI executes the SQL and HTTP gates on MySQL 8 with PHP 8.1 and 8.3,
including 40 independent-process concurrency checks, and the actual Chromium
Chat/voice/drawer/portal integration. Transport and media boundaries remain
controlled; these gates do not place live provider, payment, voice or video calls.

### PA5: checkout in ordinary Profile conversation

Checkout already exists in Profile Commerce pages and native WebMCP tools. The
ordinary public Profile chat currently supplies approved product information and
checkout links; it does not yet perform the whole canonical checkout flow in a
conversation card.

The visitor can browse products and open a purchase review in the public chat.
The review shows the seller terms, provider, quantity and total. Confirming
that review opens the provider checkout. Payment status, the receipt and
delivery links appear in the same visitor conversation after Commerce verifies
the payment and fulfillment state. Appointments use the Scheduling payment
flow.

A verified order adds a seller notification and an announcement in the
seller's private Agent Chat. The announcement links to the Profile orders
workspace. It preserves the notification's read state. Late payments are
flagged for fulfillment review.

Run `/upgrade.php` on existing installations before enabling conversation
checkout. The migration adds storage for visitor-bound reviews and their
canonical order links. Buyers can reopen the original conversation in the
same browser to recover a checkout or check payment status.

PA5 acceptance gates

| Gate | Required behavior |
| --- | --- |
| 1 | Resolve only current published products belonging to the contacted owner and a currently enabled canonical merchant integration |
| 2 | Display the canonical product, quantity, price, currency, applicable charges, fulfillment method and seller terms in the visitor's conversation |
| 3 | Bind each review to the exact visitor, public thread, merchant, product/version, quantity and expiry; changed terms require another review |
| 4 | Require the buyer's explicit confirmation of that review; Agent prose, an owner reply, a call acceptance or an unrelated yes cannot authorize checkout |
| 5 | Reuse the same canonical prepared intent and provider request identity after an uncertain result rather than creating a second checkout |
| 6 | Let the configured provider collect payment details and show the returned checkout destination; do not collect card data through ordinary Agent text |
| 7 | Use PA2's verified payment evidence and durable recovery for status; browser return parameters never establish payment success |
| 8 | Scope receipts and private delivery to the exact authorized buyer/order, and show awaiting payment, paid, review required and fulfilled accurately |
| 9 | Preserve human/Agent handoff, call state and the conversation when payment is cancelled, unavailable or delayed; use the Scheduling path for appointments |
| 10 | Test current authority, changed terms, duplicate confirmations, lost responses, late payment, fulfillment retry and mobile cards without live financial effects |

## Validation and deployment contract

Each section is released only after its gates and exact-head PR checks are
green. GitHub records the merge and postmerge checks; the flat production ZIP's
`VP3_RELEASE.json` identifies the exact commit and packaging run. Update the
existing deployment download only after verifying the artifact digest, archive
integrity and every packaged source file against that exact merged source tree.
