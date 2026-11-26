# Music listening development status

## Current state

**ML0 is merged; ML1 implementation is in PR [#930](https://github.com/davemt74-lang/software/pull/930).** The user approved merging the planning package and building the first implementation phase on 2026-11-26. Planning PR [#925](https://github.com/davemt74-lang/software/pull/925) merged as `3ec69d03031e859f9cf155d1ad64cd9f16d5b631`; implementation uses the isolated branch `codex/vp3-music-ml1` based on that commit. The implementation PR records its current candidate commit, checks and review evidence.

ML1 introduces one catalog/metadata save contract for main Add Track and both library editors, structured song credits and identifiers, private notes, stable recording/work/file identities, reusable parties, revision conflicts, request receipts, safe archive/restore and additive migration. `/music-workspace.php` links directly to song creation and metadata editing. The existing audio repair path also records canonical asset versions.

See [ML1 implementation and rollout](ML1-IMPLEMENTATION.md) for exact behavior, tests and operational requirements. No production database migration or live application deployment has been performed by this development task. There is no new listening dashboard, active royalty rate, payout or completed Stoneward streaming integration in ML1.

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
| MySQL runtime | Dedicated native-PDO MySQL acceptance test and CI service added; native MySQL passed in the first implementation CI run. A local absent-DSN `SKIP` is not evidence of a MySQL pass; use [PR #930 checks](https://github.com/davemt74-lang/software/pull/930/checks) for the current candidate result. |
| Desktop/phone browser form | Dedicated browser acceptance covers the shared renderer, real library navigation/styles and Chat controller. Current candidate completion and screenshot review are recorded with [PR #930](https://github.com/davemt74-lang/software/pull/930). A narrow viewport is not a physical-phone playback test. |
| Live deployment/restore/media headers and physical iPhone/Android playback | Not performed. Deployment and later streaming milestones retain these gates. |

The development runtime is PHP 8.4.27 with SQLite, mbstring and fileinfo. CI uses the repository's PHP 8.3 convention and a MySQL 8 service for the new database acceptance test. Authentication/session adapters in isolated fixtures are explicit; tests exercise the real catalog, metadata, authority, uploads and HTTP controllers, not a live signed-in deployment.

The original documentation checks resolved 40 relative links and 49 pinned source targets across 42 files. Those checks validated the planning artifact; they did not certify application behavior.

## Phase status

| Phase | State |
|---|---|
| ML0 — Audit and specification | Merged in #925. Product/financial open decisions remain identified. |
| ML1 — Shared catalog, metadata, identity and authority | Implemented on `codex/vp3-music-ml1`; review and release evidence tracked in the implementation PR. Production migration/deployment not performed. |
| ML2A — Native listening measurement and receipts | Not started |
| ML2B — VP3 catalog/streaming provider | Not started |
| ML2C — Stoneward import/source adapter | Not started |
| ML2D — Combined one-recording pilot | Not started |
| ML3 — Music Workspace analytics | Not started |
| ML4 — Funded earnings and statements | Not started; policy decisions required before real accrual |
| ML5 — Payouts and reconciliation | Not started; settlement/provider decisions required |
| ML6 — Artist Agent, support and release acceptance | Not started |
| MH1 — VP3-managed Stoneward hosting | Not started; actual operated runtime/provisioning must be established |

A phase score applies only to evidence actually collected. Local database/browser fixtures do not certify deployed media headers, a restored production backup or physical-device playback.

## Next action

Finish the ML1 implementation review and applicable checks on its actual candidate commit. Merge/release only within the user's authorization, then perform the documented backed-up migration and host smoke checks. The next product build is ML2A–ML2D: native event receipts, VP3 streaming provider, Stoneward import/source adapter, and the combined one-recording pilot. ML3 consumes that measurement for artist dashboards; financial and managed-hosting phases retain their separate requirements.

Update this file after each implementation PR with its exact PR/commit and new evidence. Keep commercial decisions in [rights and earnings](RIGHTS-AND-EARNINGS.md) and streaming semantics in [streaming contract](STREAMING-CONTRACT.md).
