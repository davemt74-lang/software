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
visitor card and durable public conversation. The next slice adds an in-browser
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
| PA3C | Direct browser voice and optional video between the exact visitor and owner; consent, call controls, reconnect, and Agent handoff | Implemented in PR #699 with the ten acceptance gates below; PR checks and the release artifact provide final deployment evidence |
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

The focused local suite passes **408 checks**: 153 production call-state and
recovery checks, 79 provider-contract checks, 85 actual HTTP API checks,
16 actual Admin HTTP checks, and 75 interface runtime assertions. Supporting
credential recovery passes 98 checks and the upgrade page passes 28 cases.
The CI matrix adds independent MySQL acceptance races on PHP 8.1 and 8.3 and
the actual Chromium call-interface runner, alongside retained PA1, PA2 and PA3
coverage. Provider HTTP and browser media are controlled fixtures; no live
provider call is used to establish these results.

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

### PA4: speech, notifications and Brain

PA4A adds visitor voice assignment, distinct owner/visitor speaker cues, one
arrival announcement per session, and safe playback interruption/queue behavior.
Reuse existing ElevenLabs credentials and provider settings. A voice selection
must not grant access to another account's saved voice clone.

The existing implementation points for the next focused audit are
`api/agent-voice-v117.php`, `api/admin-elevenlabs-v102.php`,
`includes/ai-settings.php`, and the Studio voice-profile services. Their current
general speech/provider configuration is not a visitor voice-assignment record.
The Profile and Studio schema surfaces reviewed so far do not provide an explicit
visitor sex/gender or preferred speech-voice field; PA4A must offer a voluntary
voice preference instead of deriving it from a name, photo or recording.

The following are planned acceptance gates, not completed results. Live calls
continue to use actual participant audio; synthetic announcements and typed
message playback must yield to an accepted human call.

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

PA4B makes presented/read/snoozed/handled/ignored states consistent. Snoozed
attention must wake after expiry even if it was previously presented. A stale
poll result cannot present an item already handled or ignored. Replay cannot
silently switch the owner's private Chat conversation. Failed reads cannot be
reported as “caught up.” Repair a missed Brain projection from durable event
evidence, with visitor, Agent and owner statements and actions attributed
separately.

The notification query already contains an expired-snooze wake condition in
`notification_profile_attention_after_v1341()`. Verify it through the later
presentation/replay path before claiming wake-up works end to end. That function
currently returns an empty successful result when the connection or notification
table is unavailable; gate 9 must distinguish that case from an empty inbox.

| PA4B gate | Required behavior |
| --- | --- |
| 1 | Durable source events bind the exact owner, visitor, public thread and action; aggregate contact recognition never grants conversation access |
| 2 | Presented, read, snoozed, handled and ignored are separate durable states with explicit transitions |
| 3 | Snooze expiry makes an unhandled item eligible again without duplicating the underlying event |
| 4 | A stale poll or delayed acknowledgement cannot revive handled, ignored or revoked attention |
| 5 | Multiple tabs and retries produce one authoritative notification outcome and avoid duplicate audible announcements |
| 6 | Public visitor, human owner and Agent statements retain accurate attribution in Chat and Brain |
| 7 | Repair a missed Chat/Brain projection from saved event evidence, without replaying the public action or payment |
| 8 | Notification replay preserves private Chat selection, unsent drafts and exact public reply targets |
| 9 | Failed/partial reads remain visibly unsuccessful rather than reporting that all attention is handled |
| 10 | SQL, HTTP and browser fixtures cover expiry, concurrent state changes, projection failure, account revocation and retained doorbell/call behavior |

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

PA5 remains an outline until its own section is built and verified. Its planned
acceptance gates are:

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
