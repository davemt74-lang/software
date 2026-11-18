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

Status: implemented and locally reviewed; awaiting final-source hosted gates before scoring and merge.

- A single Profile source/report adapter reads currently authorized conversations, attention, calls, reviewed follow-ups, and verified activity.
- Findings retain stable references, revisions, source links, coverage, and current disposition.
- Profile participates in the existing source registry, routing, specialist catalog, status, and Top of Mind; it does not introduce another scheduler or public responder.
- Named-Agent access is explicitly bound to the current owner, Agent, and private Chat.
- Reports use Key findings; Actions & follow-ups; Open questions; Next step; Questions for you.

## Phase 3 — Owner Profile workspace

Status: not started.

- The existing specialist interface provides Overview, Conversations, Calls, Analytics, and Reports without duplicate active inboxes or nested page scrolling.
- Exact visitor threads and current responder mode are visible; takeover/reply/delegate/resolve reuse existing domain actions.
- Live visitor/call cards remain in main Chat and preserve visible-delivery timing, message identity, drafts, and reload recovery.
- Owner-assisted text replies are reviewed drafts bound to the exact current visitor thread; they do not compete with the active responder.
- Mobile, keyboard, focus, loading, empty, failure, and stale-context behavior are verified.

## Phase 4 — Contextual engagement and call history

Status: not started.

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

The Activity Hub HTTP suite runs the production endpoint and checks exact scoped reports, unavailable/limited/unconfigured states, method and session authority, and unchanged storage. Renderer tests exercise all five sections, safe source links including subdirectory installs, hostile visitor text, and asynchronous Chat/Agent switches. The hosted browser suite checks the actual shared specialist interface on desktop and compact screens. These checks join the existing gates; Phase 2 remains unscored until all final-source workflows pass.
