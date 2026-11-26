# Music listening development status

## Current state

**ML0 and ML1 are merged; ML2A native listening is implemented in PR [#936](https://github.com/davemt74-lang/software/pull/936).** The user approved the planning merge and first implementation, then requested continued scoring and building on 2026-11-26. Planning PR [#925](https://github.com/davemt74-lang/software/pull/925) merged as `3ec69d03031e859f9cf155d1ad64cd9f16d5b631`. Catalog/metadata PR [#930](https://github.com/davemt74-lang/software/pull/930) merged as `b554101b6ad9d66f497416244e18f745c7489510`. Implementation PRs record their exact candidate commits, applicable checks and release evidence; a source merge does not mean production was migrated or deployed.

Integration was subsequently refreshed against main `5dc2767bcf1edc6be3704182b87da1c39b740fd9` (Operator conversation mode, PR #928). Its new phone participation test exposed a fixture clock crossing a second boundary between call creation and the signed answered receipt. The fixture now uses the persisted receipt clock and includes an explicit earlier-clock case; production freshness checks are unchanged.

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
| ML2A — Native listening measurement and receipts | Implemented in #936; real service/API/client, native MySQL and browser acceptance evidence is available. The PR records the exact release candidate, remaining shared integration gates and merge/package state. |
| ML2B — VP3 catalog/streaming provider | Not started |
| ML2C — Stoneward import/source adapter | Not started |
| ML2D — Combined one-recording pilot | Not started |
| ML3 — Music Workspace analytics | Not started |
| ML4 — Funded earnings and statements | Not started; policy decisions required before real accrual |
| ML5 — Payouts and reconciliation | Not started; settlement/provider decisions required |
| ML6 — Artist Agent, support and release acceptance | Not started |
| MH1 — VP3-managed Stoneward hosting | Not started; actual operated runtime/provisioning must be established |

A phase score applies only to evidence actually collected. Local database/browser fixtures do not certify deployed media headers, a restored production backup or physical-device playback.

## Release procedure and next build

ML1 is merged. For ML2A, use PR #936's actual candidate and completed applicable checks for the release decision; do not count an earlier passing snapshot or an unrun shared browser case as the final gate. Verified releases include a Cloud deployment ZIP and the explicit upgrade procedure; production migration and host smoke checks require the actual deployment. The next product build is ML2B–ML2D: VP3 streaming provider, Stoneward import/source adapter, and the combined one-recording pilot. ML3 consumes that measurement for artist dashboards; financial and managed-hosting phases retain their separate requirements.

Update this file after each implementation PR with its exact PR/commit and new evidence. Keep commercial decisions in [rights and earnings](RIGHTS-AND-EARNINGS.md) and streaming semantics in [streaming contract](STREAMING-CONTRACT.md).
