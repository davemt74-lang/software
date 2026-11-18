# Profile specialist delivery plan

Audit baseline: `088b3283ac396e15915d34808ad311f0d0388816`.

The owner authorized sequential implementation, review, pull requests, green required checks, and merge for the six phases below. Each phase is evaluated against its stated acceptance criteria before the next phase begins. Existing `/images` and `/marketing` commands retain Enter/Send activation.

## Acceptance scoring

Each phase has five scored areas, worth two points each: requested behavior, current source/owner authority, recovery and failure handling, presentation and integration, and regression/release evidence. Two points requires verified completion; one means partially verified; zero means incomplete. A phase reaches 10/10 only when every area is complete and required PR checks pass on the final source commit. Scores describe the stated phase acceptance scope.

## Phase 1 — Profile reliability

Status: complete, 10/10 for this acceptance scope. PR #788 merged as `a781a75dfce3dd172988a236568b075529f52463` after all 15 workflows passed on final source `d141c41197351b0e20c8826675c6e483262ad531` (38 successful checks; 4 expected path-filtered skips).

- A lost owner-reply acknowledgement cannot duplicate a visitor-visible message through the live visitor card or either older inbox. Retries retain the exact operation identity and original source version. Both an accepted handoff and a historical conversation without a current turn are covered.
- Shared activity context respects canonical handled/ignored/snoozed disposition. Visible presentation, Read, and resolution remain separate. A routine read visit does not become a new interruption, and an unresolved visitor request remains unresolved.
- Analytics totals aggregate all matching events; the bounded recent feed is not used as a period total.
- Native Profile period activity and traffic-spike comparisons use timestamped visit events instead of lifetime browser-contact view counters. Metrics use accurate names and comparable windows.
- Revenue read failure has explicit unavailable/error coverage and cannot be rendered as a verified zero.
- Focused regression tests reproduce the original failure modes on the production paths, and existing relevant SQLite, MySQL, HTTP, and browser gates remain enabled.

## Phase 2 — Shared Profile specialist

Status: complete, 10/10 for this acceptance scope. PR #789 merged as `ec31558b4e44e3e746c0e5169f415adec73222e4` after all 13 workflows passed on final source `dc161379e94d9ec95eff8d3ffbb9192efc06c845` (33 successful checks; 4 expected path-filtered skips).

- A single Profile source/report adapter reads currently authorized conversations, attention, calls, reviewed follow-ups, and verified activity.
- Findings retain stable references, revisions, source links, coverage, and current disposition.
- Profile participates in the existing source registry, routing, specialist catalog, status, and Top of Mind; it does not introduce another scheduler or public responder.
- Named-Agent access is explicitly bound to the current owner, Agent, and private Chat.
- Reports use Key findings; Actions & follow-ups; Open questions; Next step; Questions for you.

## Phase 3 — Owner Profile workspace

Status: complete, 10/10 for this acceptance scope. PR #790 merged as `d5d51cca6b6008e4792e06b187b0dea85ae14651` after all 15 workflows passed on final source `ed2118632fa9494af99b58416a6f12ffa6498179` (38 successful checks; 3 expected path-filtered skips).

- The existing specialist interface provides Overview, Conversations, Calls, Analytics, and Reports without duplicate active inboxes or nested page scrolling.
- Exact visitor threads and current responder mode are visible; takeover/reply/delegate/resolve reuse existing domain actions.
- Live visitor/call cards remain in main Chat and preserve visible-delivery timing, message identity, drafts, and reload recovery.
- Owner-assisted text replies are reviewed drafts bound to the exact current visitor thread; they do not compete with the active responder.
- Mobile, keyboard, focus, loading, empty, failure, and stale-context behavior are verified.

## Phase 4 — Contextual engagement and call history

Status: implementation in progress; final scoring follows focused verification, hosted browser review, and all final-source PR gates.

- Meaningful first-time and returning visits use observed context; routine traffic can be grouped. No importance rule equates raw visit count with meaningful intent.
- Existing presentation ownership prevents duplicate doorbell, speech, attention, and specialist interruptions.
- Completed assisted calls have durable outcome entries with authorized transcript, research, and reviewed follow-up access after Chat reload.
- Existing direct browser calling remains LiveKit-based; optional assistance and separate PSTN capabilities retain their own readiness.
- Repeated reads/reloads do not produce new announcements or reports without a verified source change.

## Phase 5 — Profile analytics in briefings

Status: not started.

- Existing morning/catch-up/report mechanisms consume current Profile findings, with owner-local reporting boundaries and source availability.
- Visits, recognized browser contacts, conversations, unanswered turns, call states, and outcome measures use explicit definitions and comparable windows.
- Collected conversion events remain distinct from verified paid orders; unknown data remains unknown.
- No unique-person, actual-talk-time, or impression-based rate claim is made without supporting measurements.
- Briefing acknowledgement and source changes respect prior handling/snooze/delivery while permitting a concise summary of still-unresolved work.

## Phase 6 — Product marketing follow-through

Status: not started.

- Profile conversation findings and attributable promotion outcomes link to exact existing product/plan/publication identifiers.
- Draft, active, and paused product marketing work retain their existing lifecycle and original Chat/Agent binding.
- Promotion clicks, checkout starts, verified paid/refunded orders, and relevant product questions can enter specialist reports and briefings with honest windows and availability.
- The shared planner can choose a supported next action without automatically publishing, sending, or creating duplicate plans from observation alone.
- Profile and Marketing share evidence and source revisions while each keeps its domain authority.

## Release evidence

For each phase, record the reviewed commit, scored evidence, pull request, required check results, merge commit, and any material validation limits. Production configuration/worker/provider health is separate from fixture-backed code verification.

## Phase 1 verification scope

Focused regressions cover historical and accepted-turn reply replay through the real HTTP endpoints, stable request identities in both older inbox clients, current canonical attention disposition through presentation and mutation, uncapped analytics aggregates, exact event windows, unavailable reads, and reporting-selection changes while requests are pending. Native MySQL variants exercise real aggregate SQL and transaction locks in the Section 14 matrix. Existing browser and runtime journeys remain enabled.

Phase 1 final-source evidence: [Section 14 Authority and Security](https://github.com/davemt74-lang/software/actions/runs/44272160301), [Recovery Baseline](https://github.com/davemt74-lang/software/actions/runs/44272159933), and [HomeServer Runtime Journey](https://github.com/davemt74-lang/software/actions/runs/44272160010). The merged tree `c10cd6f19ecebac34f6d34e75064809f4b7e1a9c` exactly matches the tested source. All five scoring areas earned 2/2.

## Phase 2 verification scope

The source suite exercises discovery and exact rereads through current private owner/Agent/Chat authority, independent public responder identity, canonical source provenance and disposition, semantic revision stability, missing source coverage, and no-write report reads. The pipeline suite uses actual SQLite/MySQL workflow records and receipts to verify that a saved five-section Profile report can follow an explicitly selected report rule while native visitor/call obligations and Office-owned follow-ups retain their presentation owner.

The Activity Hub HTTP suite runs the production endpoint and checks exact scoped reports, unavailable/limited/unconfigured states, method and session authority, and unchanged storage. Renderer tests exercise all five sections, safe source links including subdirectory installs, hostile visitor text, and asynchronous Chat/Agent switches. The hosted browser suite checks the actual shared specialist interface on desktop and compact screens. All 309 focused checks pass on both hosted PHP versions. Native MySQL additionally passes the 113 source checks and 94 pipeline checks per version, with zero source-suite skips. Final desktop and compact screenshots were inspected. All five scoring areas earned 2/2.

Phase 2 final-source evidence: [Section 14 Authority and Security](https://github.com/davemt74-lang/software/actions/runs/44283207631), [Recovery Baseline](https://github.com/davemt74-lang/software/actions/runs/44283207796), and [HomeServer Runtime Journey](https://github.com/davemt74-lang/software/actions/runs/44283207688). The merged tree `540f873956766322ae67cf659c2e4018f5115db2` exactly matches the tested source.

## Phase 3 verification scope

The existing Activity Hub hosts one Profile workspace with Overview, Conversations, Calls, Analytics, and Reports. The specialist rail, Top of Mind, and existing Profile activity launcher select that workspace. Conversation inventory includes resolved and historical threads independently of attention suppression, with bounded cursor pagination and older-message reads. Native visitor and call cards remain in main Chat; focusing an existing call does not deliver a new card, reconnect, or replace media tracks.

All workspace requests carry the exact owner, private Agent, saved Chat, and current page binding. Read operations do not acknowledge activity, install schema, start inference, or acquire a public responder lease. Public Profile visibility remains separate from private workspace access. Current capabilities and the actual browser session are checked again before returning results. Analytics uses the existing complete native aggregates and conversion-event definitions, with explicit unavailable coverage, current property ownership, and no initialization on read. Owner-local completed calendar windows remain Phase 5 work.

Private reply preparation requires an exact current visitor turn already claimed by the owner. Its durable request, source revision, dispatch token, and send receipt preserve recovery across an uncertain response without a second provider attempt or public message. An edited draft is sent only by an explicit owner action against its reviewed source. The normal public responder retains its original admission rules. Cloud preparation uses governed foreground usage and one physical attempt; optional HomeServer preparation reserves the attempt in the same transaction as its transient queue insertion. Preparing a draft neither sends it nor starts another worker.

Focused SQL, production HTTP, and renderer tests cover authority changes, pagination, request replay, source changes during preparation, late completion, explicit regeneration, unavailable storage, switching tabs, and preserved input. Native MySQL gates exercise authority and transactional recovery, including competing HomeServer queue reservations. Hosted browser gates cover the shared workspace at desktop and compact sizes, keyboard focus, saved reply recovery, and native call/media continuity. Existing regression invocations remain enabled.

Final source passes 198 new workspace/draft/analytics/call SQL checks per PHP version on native MySQL, with no skips. The workspace browser passes 53 checks, the shared report browser passes 42, and all retained visitor/call/speech/attention browser suites pass. Desktop and compact screenshots were inspected. A deterministic regression also verifies read-only recovery when a visible attention acknowledgement overlaps a newer observation, including revocation during recovery. All five scoring areas earned 2/2.

### Phase 4 implementation and verification scope

Arrival context uses the saved native visitor principal, browser credential, and application timestamps. A first recorded visit is explicitly scoped to that browser history. Returning context may quote a strictly earlier, currently authorized visitor statement and a currently disclosed accepted relationship. Reused CRM recognition, raw visit counts, and legacy database wall-clock dates do not establish identity, intent, or historical ordering.

Automatic visual notice eligibility is independent of voice configuration. An eligible silent notice remains available when speech is muted or unconfigured, while expired, suppressed, unavailable-owner, hidden-tab, and competing native presentation states cannot create a new automatic Chat row. Existing verified markers can recover after expiry. Speech for a claimed visitor message reuses the exact existing native handoff card, with its own speech attempt and playback receipts; it does not insert another card, acknowledge pickup, or replace the live card's controls. Manual playback requires the exact selected visitor message.

The Calls workspace separates current calls from paginated history and keeps a selected call independent of the list page. Calls still ending remain current. Completed calls expose bounded saved transcript, analysis, research, and reviewed task sections under current source and private Agent authority. Complete source hashes protect continuation pages, including edits that preserve timestamps. Limited evidence has explicit coverage and an exact source link. Generated summaries distinguish accepted source findings from owner review of the generated result; rejected findings do not reappear as ordinary recommendations. History reads do not start a provider, capture, scheduler, or live media session.

Routine visit prose is grouped within the checked source view. Each original source reference, revision, disposition, and native presentation owner remains available. Unresolved conversations, calls, and reviewed work retain priority over routine visits, and no count is presented as unique people or purchase intent.

Focused SQL, real endpoint, renderer, and retained native call/speech suites cover these contracts. The release gates add both new SQL suites on SQLite and native MySQL for PHP 8.1 and PHP 8.3, retaining every existing workflow invocation. Final scoring remains pending final-source hosted checks and desktop/compact browser review.

Phase 3 final-source evidence: [Section 14 Authority and Security](https://github.com/davemt74-lang/software/actions/runs/44295726514), [Recovery Baseline](https://github.com/davemt74-lang/software/actions/runs/44295726100), and [HomeServer Runtime Journey](https://github.com/davemt74-lang/software/actions/runs/44295726252). The merged tree `3cc735aeb5d49383950e00a94a9e9831b960306f` exactly matches the tested source. Browser artifact `14422559545` has verified SHA-256 `520878ace9d37cc1b5d4e1fc42e139e24785bf9446e335b490da158546a5c460`.
