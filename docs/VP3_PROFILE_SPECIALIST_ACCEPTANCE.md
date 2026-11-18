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

Status: complete, 10/10 for this acceptance scope. PR #791 merged as `d31eb5aa0a8e0f79a9ac7924d3c2df16395bfb5d` after all 12 workflows passed on final source `12adb7e6697d1712e12754ca50b5d8c34ebe930f` (31 successful checks; 4 expected path-filtered skips).

- Meaningful first-time and returning visits use observed context; routine traffic can be grouped. No importance rule equates raw visit count with meaningful intent.
- Existing presentation ownership prevents duplicate doorbell, speech, attention, and specialist interruptions.
- Completed assisted calls have durable outcome entries with authorized transcript, research, and reviewed follow-up access after Chat reload.
- Existing direct browser calling remains LiveKit-based; optional assistance and separate PSTN capabilities retain their own readiness.
- Repeated reads/reloads do not produce new announcements or reports without a verified source change.

## Phase 5 — Profile analytics in briefings

Status: complete, 10/10 for this acceptance scope. PR #793 merged as `5b5ceb2a6a8a8090dffca6ab28187fff34c7109d` after all 14 workflows passed on final source `65b8695bd0ed0faaa7dea766e2592b44a586c200` (34 successful checks; 4 expected path-filtered skips). The merged tree `2538c9da67988af0562284bbdb144b8bbe5d775a` exactly matches the tested source. The follow-up preserves PR #792 and adds current-authority concurrency evidence, canonical native admission/receipt validation and compact period presentation.

- Existing morning/catch-up/report mechanisms consume current Profile findings, with owner-local reporting boundaries and source availability.
- Visits, recognized browser contacts, conversations, unanswered turns, call states, and outcome measures use explicit definitions and comparable windows.
- Collected conversion events remain distinct from verified paid orders; unknown data remains unknown.
- No unique-person, actual-talk-time, or impression-based rate claim is made without supporting measurements.
- Briefing acknowledgement and source changes respect prior handling/snooze/delivery while permitting a concise summary of still-unresolved work.
- Completed day, 7-day and 30-day periods use explicit owner-local calendar boundaries, equal previous calendar periods, and fixed references carrying the original timezone context. Current backlog has a separate checked time and revision.
- Native Profile events receive an application UTC clock at their original insertion. Explicit Setup/Database Upgrade marks only actual pre-existing untimed rows; old timestamps are never guessed into UTC, and incomplete deployments remain visibly partial.
- First-reply delay uses the new-conversation cohort and exact canonical reply receipts before the cutoff. Call acceptance delay is not talk duration. Money preserves the Commerce ledger's explicit minor-unit scale and separates currencies, payments and refunds.
- Saved source links restore the original private Agent/Chat before opening the completed period. Changed or malformed scope never falls back to another Chat. Existing Traffic details and Enter/Send slash-command behavior remain available.

## Phase 6 — Product marketing follow-through

The implementation covers the acceptance scope below. The phase pull request records the release score, exact final source, hosted PHP/MySQL/browser results, screenshot verification and merge evidence. A 10/10 release decision requires all of those gates on the final source.

- Profile conversation findings and attributable promotion outcomes link to exact existing product/plan/publication identifiers.
- Draft, active, and paused product marketing work retain their existing lifecycle and original Chat/Agent binding.
- Promotion clicks, verified checkout-link availability, paid/refunded orders, and relevant product questions can enter specialist reports and briefings with honest windows and availability. A ready checkout link is not proof that a buyer opened the payment provider.
- The shared planner can choose a supported next action without automatically publishing, sending, or creating duplicate plans from observation alone.
- Profile and Marketing share evidence and source revisions while each keeps its domain authority.

### Phase 6 implementation boundaries

The existing `/marketing` dialog gains publication choices and a read-only follow-through view. Enter/Send remains the activation gesture for both `/marketing` and `/images`. Each report identifies one exact publication, historical draft, original image, private Agent and Chat. An earlier publication remains inspectable after its creative is paused or replaced. Opening a fixed source link restores that exact private Chat before reading; malformed, unavailable or changed destinations do not fall back to another plan.

Reports compare completed 1-, 7- and 30-day owner-local calendar periods with equal previous periods. The reference freezes the publication, cutoff and timezone. The seven measures distinguish recorded CTA clicks, clicks with verified native conversation association, promotion-linked Agent exchanges, attributable orders, locally usable checkout links, verified paid orders and verified refund-affected orders. Selected and previous periods retain their evidence coverage. Partial observations and unavailable evidence cannot become verified zero. Money stays in exact decimal strings at the Commerce ledger scale, with currencies and payments/refunds separate.

The click writer adds immutable native conversation proof only to an accepted Profile Agent click with valid existing cookies and the exact native conversation. Admission holds the current positive owner grants and rechecks them before committing. Later visitor logout, password rotation or public Agent retirement does not rewrite accepted historical attribution. Legacy clicks stay unassociated. It creates no replacement visitor session or conversation. One closed native proof is stored in `native_context_json_v1`; one `opened_at_utc_ms` clock records checkout availability. The checkout writer records that UTC clock once, in the existing committed lease-fenced update that makes a provider link locally usable. That clock does not measure whether a buyer opened the provider. Explicit Database Upgrade adds the nullable capture columns; readers perform no migration and no historical backfill.

Public exchange snippets require a real admitted turn, saved Agent answer containing the exact publication key, and canonical message/session/job receipt lineage. The exact displayed proof is checked again after the aggregate snapshot, so a late ownership or source change withholds stale excerpts. Snippets are bounded while totals use all matching evidence. The displayed visitor statement is source content, not inferred buying intent. Historical facts and current actions have separate revisions: the report can link to the original saved creative, the exact visitor thread, or the current product plan, with each destination reauthorizing its own action.

The Marketing source adapter retains the existing Campaign source and adds original-Chat product plans and fixed publication periods. Missing optional Campaign storage cannot hide otherwise authorized product work. Private plan and publication inventory requires current account/Chat authority; public conversation and sales reports additionally require current Profile grants. Current role, permission, Agent and Chat rows are rechecked even inside an older transaction snapshot. The plan adapter also compares and holds the exact creative, draft, image, generation, receipt and destination dependencies used by the native plan view, then verifies them again before returning. Native delivery and scan bookkeeping does not create a new semantic source revision. Browser requests recheck the original session after source reads. A pure-tools-only load makes the private publication picker available without mounting or enabling the protected Profile workspace.

Profile and Marketing expose the same verified promotion evidence with one canonical revision. The Marketing Hub adds current plan and publication summaries alongside the retained Campaign report, and its links open the same detailed `/marketing` view. Explicit private Chat catch-up uses that same source adapter. Native Profile conversation findings can link to a bounded set of exact associated plans or completed publication periods; these links create no separate attention obligation. Count and money changes retain the coverage and exact arithmetic of both compared periods. Morning/catch-up mechanisms deduplicate the shared result; a Marketing Watch rule suppresses its Profile alias. Native plan presentation and the existing briefing mechanism own delivery, so generic coordination does not queue another automatic report. A saved delivery claim must match the real ordinary Chat message, not a bookkeeping flag. Reading a report does not publish, send, claim a visitor thread, create a plan, generate an image, run inference or wake provider work.

### Phase 6 concurrent implementation reconciliation

PR #794 and PR #795 developed complementary parts of this phase. The combined candidate retains one canonical collector, period reference, set of metric definitions, capture schema and detailed report renderer. It keeps the publication picker, explicit latest-period control, exact original private Chat, compact period metadata, current-session API fence, bounded current-plan proof, actual native message delivery evidence and exact older-draft lookup. It also incorporates the Product Marketing Hub, explicit catch-up, related Profile findings, period changes, current click-admission grant fence and final displayed-exchange proof check.

PR #794 merged as `07023be6884fed83f5f96ab224b4caccb7b0e2cb`, tree `7f4270e0c0ed4f9d3fd0b6f2afadfc01307792c1`. The follow-up preserves its test entry points and verifies the combined final tree. Its five-parameter publication URLs and exact predecessor period references translate to the same publication, original private Chat, cutoff and timezone. The old URL's product selector must match that publication. Ambiguous, malformed or mismatched routes do not fall through to another plan. Saved historical text must still satisfy the current canonical revision; translation does not certify an older report's changed metric definitions. Released `product_outcome` routing preferences project to `promotion_period` without rewriting settings, and an existing Watch preference continues to suppress automatic Marketing and Profile-alias briefings.

The seven canonical metrics keep their original units: a conversation-associated click remains a click event rather than a distinct conversation count, and checkout availability is a verified committed attempt rather than inferred buyer activity. Mutable current plan status and product names remain outside fixed historical revisions. Payment evidence cannot precede the verified order creation clock. A future exchange outside both periods cannot make an older complete period incomplete. The report still requires its original private Agent and Chat; it never silently rewrites a caller's scope.

Focused acceptance covers actual click and checkout writers, rollback and lost-acknowledgement recovery, source correction, current authority, exact older draft retrieval, native worker context, independent source failure, shared registry/rules, ordinary briefing publication and saved-history revalidation, original Chat restoration, compact layout and unchanged slash-command submission. Both PHP versions run SQLite and native MySQL gates. The hosted browser produces desktop and compact screenshots for review before scoring and merge.

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

Focused SQL, real endpoint, renderer, and retained native call/speech suites cover these contracts. Both new SQL suites pass on SQLite and native MySQL for PHP 8.1 and PHP 8.3: arrival context 88 checks and saved call outcomes 105 checks per driver/version. The retained projection suite passes 100 checks, including historical separate markers and native-card-first shared presentation. Production speech HTTP passes 95 checks. The final workspace browser passes 87 checks; the shared report, call and speech browser suites pass 42, 50 and 53 respectively. Desktop and compact screenshots were inspected, and every retained workflow invocation remains enabled. All five scoring areas earned 2/2.

Phase 3 final-source evidence: [Section 14 Authority and Security](https://github.com/davemt74-lang/software/actions/runs/44295726514), [Recovery Baseline](https://github.com/davemt74-lang/software/actions/runs/44295726100), and [HomeServer Runtime Journey](https://github.com/davemt74-lang/software/actions/runs/44295726252). The merged tree `3cc735aeb5d49383950e00a94a9e9831b960306f` exactly matches the tested source. Browser artifact `14422559545` has verified SHA-256 `520878ace9d37cc1b5d4e1fc42e139e24785bf9446e335b490da158546a5c460`.

Phase 4 final-source evidence: [Section 14 Authority and Security](https://github.com/davemt74-lang/software/actions/runs/44307932833), [Recovery Baseline](https://github.com/davemt74-lang/software/actions/runs/44307933020), and [HomeServer Runtime Journey](https://github.com/davemt74-lang/software/actions/runs/44307932835). The merged tree `36f5ec50b765d2a63098bcf0b13b08761a8580be` exactly matches the tested source. Browser artifact `14429390930` has verified SHA-256 `f858d17ade240f8f9f1cf3da7cd0acff1ebe51d2378e56a75270962f847aebc1`.

## Phase 5 implementation and verification scope

The period reader uses bounded current authority reads for the exact owner, private Agent, private Chat, explicit account roles, permission grants and seed state. It evaluates the returned current rows directly rather than following a lock with an ordinary read from an older repeatable-read snapshot. Missing authority storage remains unavailable; a denial withholds the report. Caller transactions remain owned by the caller.

All seventeen measures state their counting unit and evidence requirement. Complete totals, partial observed subsets and unavailable values are distinct. Recognized browser contacts require current native disclosure proof. New-conversation reply cohorts separate replied, unanswered and unverifiable threads. Payment completion is the first verified cumulative payment crossing of frozen order terms, while refunds retain their own recorded time. Legacy booking metadata alone does not prove historical Profile payment attribution.

Historical native conversations retain current ownership and exact admission, message and session lineage. Later changes to a public Agent's name, activity or public Profile visibility do not rewrite those historical counts. Real admission and worker-receipt tests enforce the canonical snapshot format and exact positive receipt identifiers, including the native decimal-string representation and older integer JSON.

The same pure period formatter feeds the shared Profile source, explicit private Chat catch-up and existing morning brief. A briefing presentation owner prevents a competing automatic standalone report without claiming that delivery already occurred. Exact source revisions exclude current backlog and observation time. Saved briefing history rechecks the original fixed reference; source correction, revoked access or a Watch rule withdraws its text and links. Proof slots for period and curiosity sources are reserved before optional related links reach the existing twelve-source history bound.

New regression gates exercise the real event writers and installer, normalized local-day boundaries and daylight-saving transitions, uncapped aggregates, exact receipt lineage, money arithmetic, current authority under stale snapshots, the shared registry and saved morning proof, and production Chat navigation. The shared workspace keeps completed periods separate from retained Traffic details. Final-source PHP 8.1 and 8.3 each passed the native MySQL clock (66), period (170), authority (78, including 20 race cases) and briefing (35) checks. Hosted browser acceptance passed 146 workspace and 23 dedicated period checks. The downloaded final-source workspace and period artifacts passed SHA-256 and ZIP verification, and compact screenshots were reviewed. All five scoring areas earned 2/2.

Phase 5 final-source evidence: [Section 14 Authority and Security](https://github.com/davemt74-lang/software/actions/runs/44326732978), [Recovery Baseline](https://github.com/davemt74-lang/software/actions/runs/44326732905), and [HomeServer Runtime Journey](https://github.com/davemt74-lang/software/actions/runs/44326732617). The final runtime correction requires actual queue audio to exist and be playing before retained Next/Stop assertions; no playback production behavior, assertion or test invocation was removed.

## Phase 5 concurrent-main reconciliation

PR #792 merged fixed-period analytics and preserves independent Traffic details during period read failures. Its retained workflow invocations, separate period HTTP/browser suites, upgrade readiness regressions, exact day-start checks and payment/refund ledger completeness checks remain in the combined version. PR #793 adds strict current-row authority under old transaction snapshots, real admitted-turn and saved-receipt compatibility, compact date headings and accessible exact metadata. Final validation passed against the combined tree before this follow-up merged.
