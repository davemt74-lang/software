# ML3A — all-time listening totals

## Scope and evidence

The user asked whether total listens were already tracked and authorized this focused follow-up. It adds a consistent all-time measured total per canonical recording and artist workspace, plus a platform total and workspace drilldown for administrators with `listening.view`. The starting VP3 main is `2c721f7b92a01f3a83c3123b2afd7dfa9908bfa6` after ML3 merged in [PR #948](https://github.com/davemt74-lang/software/pull/948).

This phase is reporting over the existing VP3 native and connected-site ledgers. It does not change playback writers, the 10-second engagement definition, site imports, financial eligibility or the Stoneward protocol. The previously verified Stoneward v1.3.333 release remains compatible. No new schema or counter migration is required.

The implementation PR and release handoff own exact candidate/merge commits, executed checks and the source-verified full Cloud archive. This document defines the acceptance contract; a result is complete only when the corresponding evidence has passed. Actual-host deployment and physical-device acceptance remain ML2D.

## What “all-time” means

The reporting basis is `all_retained_measured_history_v1`. It uses all retained versioned native/provider sessions up to the report snapshot, with no lower date boundary. A song with no qualifying sessions remains visible with zero measured listens. Archive and site revocation do not erase historical measurements while the canonical song, workspace and site associations remain intact.

This is not a guarantee that every historical listen still exists. The earlier native schema permits backing-track/history deletion to cascade into native sessions. Deleted records cannot be reconstructed by a read-only report, and listening before instrumentation was introduced cannot be invented. The response always carries `history.complete_lifetime: false`; the UI describes all retained measured history and its observed date range. Permanently deleted evidence is unknowable, while still-retained unassociated evidence is reported in coverage.

The default audience view excludes known owner/manager previews, authorized private listening and staging tests. Production anonymous provider visitors remain unclassified people; neither the session key nor a 10-second listen establishes a unique human. The explicit All traffic view includes measured test classes and separately labeled legacy-only evidence. Known tests can also be inspected alone.

| Figure | Meaning |
|---|---|
| All-time listens (10s+) | Count of retained measured sessions with at least 10,000 accepted playing milliseconds. Replayed event receipts do not create another session. |
| Starts | Native/provider sessions with an acknowledged playback transition. A request for a stream is not sufficient. |
| Listening time | Sum of accepted cumulative playing milliseconds for the selected measured sources and traffic classes. |
| Completion | Accepted time reaches 80% of a usable duration; the percentage denominator includes only started sessions with known duration. |
| Native / connected-site contribution | Independently reported contributions to the same measured total. |
| Legacy-only sessions / time | Older, safely attributable rows without a versioned native session; never relabeled as new measured listens or added to measured time. |
| Requests / delivery bytes | Existing session and delivery accounting, separate from listens, people and money. |

## Authority and safe output

Workspace scope requires a freshly loaded active account, an enabled selected workspace and existing `tracks` management authority for that workspace. Producer membership alone is insufficient. Global `listening.view` does not grant artist management.

Platform scope requires a freshly loaded active account and the existing production `listening.view` permission. Its authority helper uses the same database connection as the reporting snapshot. Current primary roles, explicitly assigned secondary roles and current permission grants remain authoritative. A copied session role does not survive demotion, removal or account deactivation. The administrator can narrow platform scope to one workspace without switching into artist management scope.

The service checks authority before and inside its read-only consistent snapshot, then rechecks current authority after completing the snapshot. It refuses to take ownership of a caller's existing transaction. Reads create no schema, events, counters, site grants or financial records.

Responses contain aggregate song/workspace labels and counts. They omit listener accounts, names, email addresses, hashes, session IDs, playback keys, media tickets, source query strings, private song metadata and site credentials. The HTTP route is authenticated JSON-only POST with CSRF, bounded request size, private/no-store caching and safe public error codes.

## Aggregation and coverage

SQL aggregates the retained source ledgers directly. The all-time service does not reuse the dated report's 25,000-session materialization limit or 366-day limit, and it does not introduce a second mutable counter that can drift from the underlying evidence. Large histories are tested beyond both dated limits. Group results remain bounded to at most 100 rows per page, with validated exact-integer offsets; paging never changes the summary.

Canonical recording, catalog track and workspace identities must agree. Provider records also retain their matching registered-site association. Current publication/selection status does not discard a valid historical session. Retained records whose associations no longer agree are excluded from attributed totals and surfaced as unassociated coverage rather than assigned to a guessed owner. Legacy-only attribution additionally requires an unambiguous catalog/backing-track mapping; versioned native compatibility rows are excluded.

The response reports source availability and coverage for missing sources, unresolved associations, legacy ambiguity, unknown classes, partial intervals, previews/private/staging activity, unknown duration and ongoing sessions. Missing or unselected source metrics are not presented as measured zero. A combined report can show its available contributions with a visible incomplete-source warning; requesting an unavailable source directly fails. Unsupported selected metric versions fail explicitly. Future-created sessions are outside the snapshot.

Integer parsing and sums must remain exact within JSON's safe integer range. Out-of-range totals fail visibly rather than silently rounding. MySQL runs with native prepared statements and a read-only repeatable snapshot; SQLite acceptance exercises the same metric and ownership rules. This removes application-level row materialization, but it is not a benchmark against an unmeasured production history or a promise of constant-time database execution. The current native ledger has no dedicated workspace reporting index, so its aggregation can scan retained history; this read-only phase adds no index migration. Production query plans and timings should guide any subsequent index or rollup work.

Legacy timestamps must be usable canonical UTC values and cannot start after the snapshot. Malformed mapped timestamps fail visibly when legacy history is selected instead of disappearing into a believable zero. The association/classification gap counters describe all retained evidence in the chosen workspace and sources independently of the traffic filter; measured summary counts still obey that filter.

## Interface and existing behavior

`/music-workspace.php` gains a prominent All-time listening panel above the existing date-range analytics. Its source and traffic filters are independent of date controls. It shows a workspace total and paged per-song totals, including zero-listen canonical songs. Existing metadata, library, player, release, stem, team and site-management links remain available.

`/admin/listening.php` gains the combined platform panel, paged artist-workspace totals and a platform-authorized song drilldown. Its existing native/legacy historical report remains below with an explicit separate heading and range scope. The two reports retain their different evidence definitions and must not be summed together.

The shared renderer uses safe text DOM insertion, visible loading/changed/error/unavailable/empty states and request generation guards. Superseded responses cannot replace current results. Desktop and phone widths use ordinary document scrolling; tables reflow without introducing nested workflow scrollers. Financial panels remain Not configured.

## Required acceptance score

Each row is worth one point. This score applies only to ML3A; it is not a score for live deployment, funded royalties, payouts or the complete music product.

| ID | Required evidence |
|---|---|
| LT-01 | Workspace and platform authority remain distinct; real permission helpers reject copied roles, removed grants and disabled accounts. |
| LT-02 | Native/provider totals, retries, starts, listening time and completion reconcile exactly; native compatibility rows are never counted twice. |
| LT-03 | Histories beyond 366 days and 25,000 sessions aggregate without a partial total or materialization cap. |
| LT-04 | Song/workspace paging includes zero-listen groups, has stable ordering and never changes summary totals. |
| LT-05 | Archive/revocation, deleted-history limits, orphan/ambiguous mappings, unknown duration and missing-source coverage retain their real meaning. |
| LT-06 | SQL snapshot, fresh post-read authority and numeric overflow behavior are verified, with no report writes. |
| LT-07 | Actual HTTP endpoints enforce authentication, CSRF, strict input and safe aggregate/error projection. |
| LT-08 | Actual Chromium/WebKit desktop and phone flows preserve dated reporting/navigation and cover admin drilldown, stale responses and responsive scrolling. |
| LT-09 | Native MySQL and SQLite tests plus relevant existing music regressions pass on the reviewed source. |
| LT-10 | All applicable CI passes, the merged tree matches the reviewed tested tree, and every file in the complete Cloud archive matches its exact source/manifest contract. |

## Rollout

Follow the packaged [deployment note](../MUSIC-LISTENING-TOTALS-DEPLOY.txt). The change requires only the existing catalog/listening/provider prerequisites. Preserve production configuration, private keys, uploads and database. No Stoneward update is required for these totals. Report verification after a deployment should compare one known native session and one known connected-site session without confusing a live-host observation with automated fixture evidence.
