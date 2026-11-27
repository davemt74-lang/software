# Music listening development status

## Current state

**MC1 music Chat and private playlists builds on the released ML3A totals.** [PR #957](https://github.com/davemt74-lang/software/pull/957) is the authoritative implementation and release record for MC1. VP3 [PR #951](https://github.com/davemt74-lang/software/pull/951) merged all-time listening totals as `98cccd1e8a56a182a19e8007601915757840889a`. All 16 applicable final candidate workflows and all 16 post-merge workflows passed, and its full Cloud archive was verified against the merged source and manifest. Stoneward remains compatible at v1.3.333; this follow-up requires no Stoneward code update.

MC1 starts from refreshed VP3 main `bd8a62229164f03d13966a3fef0496ab7395fa40` (including Canvas recovery/editor PR #954). It makes the Media/Entertainment specialist's two audio sources explicit: VP3 and YouTube. One song means one result across both; explicit multiple-song counts and source restrictions are respected. Playlist creation writes a durable owner-private, source-tagged list, while coordinated playback uses the existing native and visible YouTube players and their existing receipts. [The MC1 contract](MC1-MUSIC-CHAT.md) owns behavior, the ten scored criteria and evidence limits. [Its deployment note](../MUSIC-CHAT-DEPLOY.txt) records the additive schema upgrade. Exact candidate, CI, merge and full archive evidence belong to the implementation PR. Implementation in progress is not a passed release gate.

The following releases will address full searchable two-source personal listening history, then stronger preferences, suggestions and shared Agent knowledge. MC1 does not extend history retention or activate financial policy. No live host deployment, production migration, physical-device acceptance or actual Google account test is claimed by isolated fixtures.

## Earlier reporting releases

**ML3 released Music Workspace reporting and page attribution.** VP3 [PR #948](https://github.com/davemt74-lang/software/pull/948) merged as `aae15539187a8226463b41f9a02e0da67b99cc59`, paired with Stoneward [PR #391](https://github.com/davemt74-lang/stoneward/pull/391), v1.3.333 at `a15a0505627a18e0ead2310c9ac3915b044711d6`. All applicable final candidate and post-merge checks passed for both. The full archives were checked against every included source file and generated manifest. The Cloud ZIP SHA-256 was `3ff9a59abca019f77d2dc113515111f0542b58788621f0c5515fca04d7ed0fdd`; the Stoneward deployment ZIP SHA-256 was `d9f419aa758c5aec7523fb8fc85e0ba3f7d41a4cef774245250170c73cbf6aa8`. These are ML3 artifacts, not MC1 or the lifetime-totals release.

ML3A starts from VP3 `2c721f7b92a01f3a83c3123b2afd7dfa9908bfa6`. It adds all retained measured totals per canonical song/workspace and a platform report guarded by current `listening.view`, without date-window/session-materialization limits or new mutable counters. [The implementation contract](ML3A-LIFETIME-TOTALS.md) defines its ten scored requirements, exact history/authority semantics and remaining release evidence. The [packaged deployment note](../MUSIC-LISTENING-TOTALS-DEPLOY.txt) explains prerequisites and rollout. Stoneward's existing measured sessions need no client update. No live host, production migration or financial activation is part of this reporting follow-up.

The first ML3A candidate integrated main `cdb2dbaca42c6aa53b7b3a08c60e5232608b7bca` (#950 paired AGENT/private Canvas delivery), preserving both phone package additions and the new totals manifest/files. Local SQLite service acceptance passed 182 checks, production permission helpers passed 18 checks, and the real PHP/SQLite totals HTTP suite passed 77 checks. Existing dated analytics service/HTTP and metadata checks also passed. These early results are historical; [PR #951](https://github.com/davemt74-lang/software/pull/951) owns the final native MySQL, Chromium/WebKit, complete candidate/post-merge CI and archive qualification.

The following paragraphs preserve the earlier phase history and evidence boundaries.

**ML2C is complete; ML3 reporting and public-page attribution are implemented, with release qualification recorded in their implementation PRs.** Stoneward [PR #390](https://github.com/davemt74-lang/stoneward/pull/390) merged v1.3.332 as `d4844b48cb36bcbbbd3f6c6061c80891d3e28930`. All 24 applicable PR workflows and 18 post-merge workflows passed. The exact merged source and full deployment ZIP were verified; the published ZIP SHA-256 is `34b6d6d94633bb62b0e2abaf545d0fca72bf6a7e8a3189ee758884d98063c640`. Its accepted VP3 counterpart is `41603520b24c3ac6146dca5cb7310d04a8a3de9e` following provider #939 and release-validation #945. The [release](https://github.com/davemt74-lang/stoneward/releases/tag/stonefellow-v1.3.332-d4844b48) contains the source update; no actual artist host was deployed.

The ML3 work starts from VP3 `ea69a419f4878b02170769bc440cb2f26b004799` and that Stoneward release. It supplies a workspace-authorized read-only reporting service and responsive dashboard, with explicit native/provider/legacy coverage, session-start cohort/timezone meaning, safe source breakdowns and separate service usage. It extends only recognized public Stoneward page forms while preserving old-client/provider fallback and in-flight receipts. [ML3 implementation](ML3-IMPLEMENTATION.md) owns those definitions and scored acceptance requirements. Its implementation PRs own the current candidate/test/merge/package evidence. ML2D still requires actual hosts and physical phones.

The paragraphs below retain the earlier phase history and its evidence boundaries.

**ML0, ML1, ML2A and the ML2B provider are merged; ML2B release qualification is tracked in its implementation PR.** The user approved the prior builds and requested continued development on 2026-11-26. Planning PR [#925](https://github.com/davemt74-lang/software/pull/925) merged as `3ec69d03031e859f9cf155d1ad64cd9f16d5b631`. Catalog/metadata PR [#930](https://github.com/davemt74-lang/software/pull/930) merged as `b554101b6ad9d66f497416244e18f745c7489510`. Native listening PR [#936](https://github.com/davemt74-lang/software/pull/936) merged as `55045785c4fb9f4e85926275ff54d31f062b116f`. Its final candidate had 19 successful workflows, 49 successful jobs and four expected conditional skips; its merge tree matched the fully tested candidate. Post-merge Music and exact source/package verification passed. No production migration or live deployment was performed.

ML2B starts from refreshed main `180743e41ad40c249af6d27c64e0a7ba9cc932cf`, including the subsequent Brainstorming/call-review changes. It adds Sites & streaming management, verified site registrations, hash-only scoped credentials, explicit selected public catalog/manifest, cookie-independent visitor sessions, immutable audio snapshots/range delivery, renewal and event receipts, anonymous safe page attribution, current revocation, separate byte usage and additive schema/reporting. [ML2B implementation](ML2B-IMPLEMENTATION.md) owns its actual contract, engineering decisions and release limits. [Implementation PR #939](https://github.com/davemt74-lang/software/pull/939) records the exact release candidate, current CI/browser results, actual merge and source-verified Cloud package; earlier snapshots do not substitute for that final gate.

Before ML2B release, main advanced to `98fda3927659560050b3a7e279ffae6b314c2e0c` with Operator question preparation in PR #940. ML2B integrates that release, retaining its phone behavior and required package file alongside all provider code. The earlier ML2B candidate `553b1e682c379e052dc1351c128be7ded317c8c6` passed 18 workflows, 48 jobs and four expected conditional skips against base `180743e4`; that is historical evidence, not the refreshed integration gate. PR #939 records the combined candidate's checks and actual release.

ML2B merged in #939 as `64658b9c78f17467a0238d5304e5a4fb50dfd53b`, including the intervening Gmail preferences work. Its actual Music workflow passed real SQLite/native MySQL, 256 provider HTTP checks, 68 provider browser checks, the publication race and the existing metadata/native-player regressions. The full release remained held after shared browser fixtures exposed an inconsistent simulated assessment outcome and a calendar event expiring at UTC midnight between desktop and mobile cases. Both failed attempts remain part of the release record; a separate passing exact-commit run does not erase them.

The release-validation follow-up starts from `e89317d13d3e4afa1f1b0d173846ce24db3493ea`, which also contains Operator call follow-through (#943). All provider runtime files are preserved. The fixture corrections retain a completed assessment outcome across health reads, exercise a valid all-day event before/after midnight and at expiry, and keep real-time phone test calls inside a valid saved calling window while still checking actual boundary rejection. Application authority, expiry, scheduling and listening rules are unchanged. The implementation PR owns the corrected candidate's complete CI result and the final source-verified archive; an earlier archive is not the final release gate.

Earlier ML1 integration was refreshed against main `5dc2767bcf1edc6be3704182b87da1c39b740fd9` (Operator conversation mode, PR #928). Its new phone participation test exposed a fixture clock crossing a second boundary between call creation and the signed answered receipt. The fixture now uses the persisted receipt clock and includes an explicit earlier-clock case; production freshness checks are unchanged.

ML1 was then refreshed against main `3a30e377ae60ec01c5bd2df772a3d0a6357fdd53` as candidate `7dc3944b67ad25d46be4168c6d3f1132b0a61cd7`. The combined Music gate passed real SQLite/API, MySQL and 47 desktop/phone-sized editor checks. Its full repository gate and merge state remain in PR #930.

The release candidate was refreshed again as `9935d4cc129907c01be5536b644b0a3e3b04868c` against main `ff85f94c4f12c41d6bee89f488d4b081a5e83754`, incorporating paired-phone recovery and Operator session controls. The new Operator fixture already contains the earlier receipt-clock correction; its newer duration argument and clock assertions are preserved together. ML2A is based on this combined foundation. The exact refreshed candidate checks supersede earlier-base evidence for release decisions.

ML1's final candidate passed all 19 applicable workflows: 49 successful jobs and four expected changed-path skips, with no failed, cancelled or nonterminal jobs. Its Music gate passed SQLite, actual HTTP uploads/repair, native MySQL and 47 desktop/phone browser checks. Main then included Gmail owner phone calls (`b1f6d455`); the clean integration preserved all nonoverlapping files and both additions to the sole shared `upgrade.php`. The actual ML1 merge tree `cf07a839efda7499fd2e6e89d81b97a3a8b4d94f` matches that reviewed integration. The earlier-base CI and subsequent integration review are distinct evidence.

ML1 introduces one catalog/metadata save contract for main Add Track and both library editors, structured song credits and identifiers, private notes, stable recording/work/file identities, reusable parties, revision conflicts, request receipts, safe archive/restore and additive migration. `/music-workspace.php` links directly to song creation and metadata editing. The existing audio repair path also records canonical asset versions.

See [ML1 implementation and rollout](ML1-IMPLEMENTATION.md) for exact behavior, tests and operational requirements. No production database migration or live application deployment has been performed by this development task. There is no new listening dashboard, active royalty rate, payout or completed Stoneward streaming integration in ML1.

ML2A replaces the shared native player's unacknowledged deltas with stable play/event identities, frozen retries, strict event sequences, atomic receipts, account/cookie binding and bounded timing. It preserves existing personal history and accepted Agent media offers. Safe native source routes, explicit catalog/asset snapshot quality, unknown completion denominators and owner-preview/mute/loop/partial labels are recorded. [ML2A implementation](ML2A-IMPLEMENTATION.md) owns the exact contract and limits; provider byte pinning and external Stoneward imports remain later phases.

## Source snapshots

| Repository | Inspected snapshot | Meaning |
|---|---|---|
| `davemt74-lang/software` | `2b69d47b04bb2bcdd74402189b820eef22ed1235` | Detailed original VP3 music/permissions/finance/hosting audit |
| `davemt74-lang/stoneward` | `253d1267c95ffefbcf3a79173429abbbba0c33fc` | Standalone catalog/rights/player/telemetry/commerce and recovery inspection |
| `davemt74-lang/software` documentation base | `884e88342dd59d6c630e0c1abe097ff5739f6a56` | Refreshed main before preparing this package; intervening changes concern Gmail/Operator and shared integration files. Relevant source must be rechecked at implementation time. |

Pinned source references appear in [source audit](SOURCE-AUDIT.md). Source inspection does not identify the live deployment version or prove deployed runtime behavior. The missing direct-connector counterpart finding applies to these inspected checkouts; it does not rule out another branch or separately installed endpoint.

## Evidence available

| Check | Result and limit |
|---|---|
| `php tests/music-song-metadata-v1.php` | Passed with real SQLite schema/transactions: migrations, ambiguity review, stable identities, workspace authority, shared works, revision/retry conflicts, public/private separation, rollback and archive/restore. |
| `node tests/music-song-metadata-api-v1.mjs` | Passed against real PHP endpoints with multipart audio/art uploads and JSON edits. Covers Add Track to workspace identity, upload cleanup, replacement versions, authority, CSRF, missing-file publication denial and outer-transaction upload rejection. |
| CLI migration/report | Tested by the HTTP suite. Default report preserves exact database rows and SQLite file bytes; explicit migrate imports valid legacy mappings and is rerunnable. |
| `node tests/music-file-repair-api.mjs` | Passed with real multipart replacement and canonical recording/asset/revision assertions. |
| Existing music and Player checks | Music module PHP/JS, Player history API, ownership, artist media/profile/music and catalog checks passed locally. Source assertions were updated where hard deletion/global Artist permission was intentionally replaced by archive/workspace authority. |
| ML2A native service/client/HTTP | Real SQLite service, real PHP HTTP/media/history/CLI and 49 client behavior checks pass locally. Includes first-cookie lost-response protection, duplicate/conflicting receipt handling, sequence gaps, actual transaction failure, account changes, safe URL handling and pause/backdated-resume timing regressions. |
| ML2A migration/report | Default CLI report preserves exact database rows and SQLite file bytes. Explicit install is additive and rerunnable; no legacy history is converted into new events. |
| ML2A native MySQL and browser | [Music run 45882564023](https://github.com/davemt74-lang/software/actions/runs/45882564023) passed all 17 steps on `a322540b`, including both native MySQL suites, 47 metadata editor browser checks and 25 native player checks. Its synthetic checkout has the exact candidate tree. Local MySQL/Chromium remain unavailable, not local passes. A separate shared offer test captured the expected cookie-initialization response before the real client's successful retry; its observer/assertions now follow the full handshake and prove one saved play/offer event. PR #936 owns the corrected candidate's complete integration result. |
| MySQL runtime | Dedicated native-PDO MySQL acceptance test and CI service added; native MySQL passed in the first implementation CI run. A local absent-DSN `SKIP` is not evidence of a MySQL pass; use [PR #930 checks](https://github.com/davemt74-lang/software/pull/930/checks) for the current candidate result. |
| Desktop/phone browser form | Dedicated browser acceptance covers the shared renderer, real library navigation/styles and Chat controller. Current candidate completion and screenshot review are recorded with [PR #930](https://github.com/davemt74-lang/software/pull/930). A narrow viewport is not a physical-phone playback test. |
| Live deployment/restore/media headers and physical iPhone/Android playback | Not performed. Deployment and later streaming milestones retain these gates. |

The development runtime is PHP 8.4.27 with SQLite, mbstring and fileinfo. CI uses the repository's PHP 8.3 convention and a MySQL 8 service for the new database acceptance test. Authentication/session adapters in isolated fixtures are explicit; tests exercise the real catalog, metadata, authority, uploads and HTTP controllers, not a live signed-in deployment.

The original documentation checks resolved 40 relative links and 49 pinned source targets across 42 files. Those checks validated the planning artifact; they did not certify application behavior.

## Phase status

| Phase | State |
|---|---|
| ML0 — Audit and specification | Merged in #925. Product/financial open decisions remain identified. |
| ML1 — Shared catalog, metadata, identity and authority | Merged in #930 as `b554101b`; all 19 candidate workflows passed and the actual merge tree was verified. Production migration/deployment not performed. |
| ML2A — Native listening measurement and receipts | Merged in #936 as `55045785`; complete candidate checks and post-merge Music/package verification passed. Live host/device acceptance remains separate. |
| ML2B — VP3 catalog/streaming provider | Merged in #939 as `64658b9c`; actual Music acceptance passed. Shared-fixture release corrections and final candidate/merge/package qualification are recorded in the implementation PR. |
| ML2C — Stoneward import/source adapter | Merged in Stoneward #390 as `d4844b48`, v1.3.332. PR/post-merge CI and full deployment ZIP verified. Live installation remains ML2D. |
| ML2D — Combined one-recording pilot | Not started |
| ML3 — Music Workspace analytics | Released in VP3 #948 (`aae15539`) and Stoneward #391 (`a15a0505`, v1.3.333). SQLite/native MySQL (135 each), HTTP (47), Chromium/WebKit (76 each), applicable CI, merged source and complete archives verified. [Reporting/attribution contract](ML3-IMPLEMENTATION.md). |
| ML3A — All-time listening totals | Released in #951 as `98cccd1e`. All 16 candidate and 16 post-merge workflows passed; full archive verified. [Lifetime contract](ML3A-LIFETIME-TOTALS.md). |
| MC1 — Two-source music Chat and private playlists | Implementation and exact release qualification: [PR #957](https://github.com/davemt74-lang/software/pull/957). [Contract and acceptance score](MC1-MUSIC-CHAT.md). The PR records final candidate checks, merged source and full archive verification. |
| MC2 — Personal listening history and Agent retrieval | Planned follow-up; full filtered/paged two-source history and explicit coverage/retention. |
| MC3 — Preferences, suggestions and Agent learning | Planned follow-up; explicit taste, actual listening, deliberate skips and failures remain distinct evidence. |
| ML4 — Funded earnings and statements | Not started; policy decisions required before real accrual |
| ML5 — Payouts and reconciliation | Not started; settlement/provider decisions required |
| ML6 — Artist Agent, support and release acceptance | Not started |
| MH1 — VP3-managed Stoneward hosting | Not started; actual operated runtime/provisioning must be established |

A phase score applies only to evidence actually collected. Local database/browser fixtures do not certify deployed media headers, a restored production backup or physical-device playback.

## Release procedure and next build

ML1 through ML3A are merged. MC1 adds precise two-source music requests, private saved playlists and coordinated playback to the existing measurements and reports; [PR #957](https://github.com/davemt74-lang/software/pull/957) records its exact release state. Its complete Cloud ZIP must match its exact tested/merged source. Stoneward remains the verified compatible v1.3.333 release. The [music Chat deployment note](../MUSIC-CHAT-DEPLOY.txt), [analytics guide](../MUSIC-ANALYTICS-DEPLOY.txt) and [lifetime-totals note](../MUSIC-LISTENING-TOTALS-DEPLOY.txt) describe distinct features and prerequisites. ML2D's actual-host/physical-device pilot, production migrations and backup recovery remain separately observed rollout work. Financial and managed-hosting phases retain their own requirements.

Update this file after each implementation PR with its exact PR/commit and new evidence. Keep commercial decisions in [rights and earnings](RIGHTS-AND-EARNINGS.md) and streaming semantics in [streaming contract](STREAMING-CONTRACT.md).
