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
| PA3 | Availability, exact visible-card acknowledgement, 15-second pickup, durable automatic takeover, and functional main Chat visitor cards with explicit text reply/claim/delegate/resolve | Building PA3A and PA3B together as one usable text handoff release |
| PA3C | Direct browser voice and optional video between the exact visitor and owner; consent, call controls, reconnect, and Agent handoff | Next after PA3; added at the owner's request |
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

Native Profile requests enter the durable handoff path. Existing direct service
and external WebMCP callers retain their synchronous PA1 contract in this slice,
including current public authority and source rechecks. They do not acquire the
native browser's conversation credentials or an implicit owner-forwarding path.

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

The local PA3 focused suite passes 95 core handoff checks, 46 canonical binding
and provenance checks, 24 recovery checks, 57 production HTTP checks, 49 owner
UI runtime assertions, and 28 native visitor UI assertions (299 total). The
expanded Upgrade fixture passes 24 cases, and the retained Recovery Baseline
passes. The PR additionally runs the SQL and HTTP fixtures with MySQL 8 on PHP
8.1 and 8.3, including independent-process takeover races, plus both actual
browser interfaces in Chromium with mobile viewport checks. These controlled
tests do not call a live model, voice, payment or media provider.

### PA3C: direct voice and video

Repository audit found an existing LiveKit media configuration, server-side
participant token signer, room/participant services, and browser audio/video
controls in `includes/video-meetings-v1800.php`,
`api/video-meeting-token.php`, and `video-meetings-v1800.js`. Reuse the narrow
media infrastructure with a new Profile call authority adapter. An existing
meeting invitation is not proof of permission to join a visitor conversation;
the new adapter must verify the exact Profile visitor binding and owner on every
call mutation and token issue. Do not create calendar appointments, paid
bookings, CRM identities, recordings, or Agent transcription merely to connect
two people.

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
