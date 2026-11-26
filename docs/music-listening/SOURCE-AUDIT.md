# Music Listening Source Audit

**Status: ML0, documentation only.** This records source observations and their planning consequences. It does not certify a deployment, migrated database, payment provider, hosting package, or production traffic. Proposed behavior belongs in the [master specification](MASTER-SPECIFICATION.md), [streaming contract](STREAMING-CONTRACT.md), and [rights and earnings specification](RIGHTS-AND-EARNINGS.md).

## 1. Audit baselines and interpretation

| Repository | Inspected revision | Scope |
| --- | --- | --- |
| `davemt74-lang/software` / VP3 | `2b69d47b04bb2bcdd74402189b820eef22ed1235` | Music workspace, catalog, Player, telemetry, permissions, connected sites, commerce and hosting interfaces. |
| `davemt74-lang/stoneward` | `253d1267c95ffefbcf3a79173429abbbba0c33fc` | Actual standalone artist application: catalog, rights, imports, persistent player, Agent audio, listening, commerce and recovery. |
| Planning worktree | Based on software `884e88342dd59d6c630e0c1abe097ff5739f6a56` | Documentation consolidation after intervening main-branch work; source claims below remain pinned to the audited revisions. |

Links identify the inspected code, including the original `chat.php` caller. A README title or historical version label is not evidence of the current implementation. “Not found” means absent from the audited paths/searches, not proof that an inaccessible branch, deployment, or another repository never implemented it.

## 2. Answers established by the VP3 source

| Question | Verified current capability | Boundary / planning consequence |
| --- | --- | --- |
| Is there overall listening analytics? | `/admin/listening.php` queries starts, 10-second plays, listeners, time, completion, devices, sources and the latest 100 sessions; ranges are 7/30/90 days or all. | Queries are global, without a workspace predicate. This is source-backed analytics, not an artist-scoped workspace report. [V1] |
| Is there engagement reporting? | `/admin/analytics.php` adds favorites, playlist additions, repeat listener hashes, short listens, Studio saves and album totals. | Mostly all-time queries over legacy Player tables; catalog/backing-track identity and favorite reconciliation need explicit treatment. [V2] |
| Is `/music-workspace.php` the dashboard? | It resolves an authorized workspace and shows counts for tracks, albums, releases and active teammates. | It is currently a launcher for catalog, production, releases, Player, transcriptions and team workflows; it contains no listening or earnings dashboard. Preserve these existing capabilities in ML3. [V3] |
| Does “artist listening” mean audience analytics? | The workspace labels `/artist-listening.php` as “Transcription & Notes.” | Do not rename transcription history into artist-performance data. [V3] |
| Is there a royalty rate or artist payout system? | The audited playback path records sessions/events. Generic commerce and package billing exist separately. | No music rate schedule, per-listen accrual, royalty statement or artist payout workflow was found in the audited VP3 source. ML4 and ML5 remain new work. [V4] [V13] |

### Permissions and personal ownership

Both global analytics pages require `listening.view`. `has_permission()` checks the user's role grants; it does not call the separate Music Workspace owner-permission helper. **Do not infer that enabling or owning a workspace automatically grants these reports.** Persisted grants and migration state were not inspected. The proven issue is that whoever receives this global permission reaches queries with no workspace filter; the recent-session query also selects listener display name, email and role. [V1] [V2] [V5]

Personal Player history has a different boundary. `includes/player-v76.php` filters by the current user and currently authorized tracks, applies the playback-history data-policy guard, and supplies owner-scoped Brain context. Personal statistics use `user_id`; workspace ownership does not transfer a listener's history, favorites or playlists to the artist. The workspace explicitly documents this separation. Artist analytics must therefore use scoped aggregates, not expose personal history as a team resource. [V6] [V3]

## 3. Native listening measurements: useful foundations, limited authority

`api/playback.php` checks session CSRF, resolves an authorized track, and binds subsequent reports to session token, track, user and listener hash under a row lock. Its anonymous listener cookie is persistent and `SameSite=Lax`. Reported deltas are capped at 20 seconds and then by elapsed server time. Closed sessions reject further accrual. These are existing controls to preserve. [V4]

The active Chat client also contains real playback lifecycle handling: playing-time accumulation, coalesced start requests, generation invalidation, waiting/stalled handling, final deltas and hidden-page flushing. Do not generalize a defect in an old, unconfirmed asset into a claim that the active player has no telemetry. [V7]

| Existing field | Actual meaning | Interpretation constraint |
| --- | --- | --- |
| `qualified_play` | Accumulated listening time reaches 10 seconds. | A product analytics threshold, not a royalty-bearing stream definition. |
| `completed` | Accumulated listening time reaches 80% of effective duration. | Natural-end notification alone is not the implemented qualification. |
| `completion_percent` | Furthest reported position divided by effective duration. | Seeking can increase this independently of listened time. |
| `source_context` | `player`, `agent_chat` or `agent_player`. | Not an external installation identifier. |
| `referrer_host` | Host extracted from the HTTP referrer. | Not the initiating page URL requested for the new product. |

These definitions come from the writer, not labels; the existing report contains stale completion explanatory text. Native events have no per-event idempotency key/sequence contract comparable to Stoneward's newer protocol. Client-reported positions/duration remain inputs, and media telemetry is not proof that a human heard audio. ML2A must establish consistent measurements and retry semantics before these records support monetary calculations. [V4] [V1]

Stoneward already offers reusable patterns: protocol-2 CSRF/session/privacy-epoch checks; play/event identities; payload-hash replay receipts; cumulative time bounded by wall time and position; and privacy changes that retire private playback state. Its compatibility path explicitly retains legacy/unknown evidence. Preserve provenance when synchronizing so a local event and its VP3 copy cannot become two plays. [S9]

## 4. Catalog entry points and reusable metadata mapping

| Entry point | Current write path | Required reconciliation |
| --- | --- | --- |
| `music-library.php` | `music_catalog_write()` with workspace capabilities and revision checks. | Preferred shared catalog/metadata integration. |
| `admin/artist-music.php` | Same writer, behind legacy role gates. | Reuse the shared editor/service rather than add another schema. |
| Main Chat/Player Add Track | `chat.php` loads `chat-legacy-v108.php`; `#chatCreateModal` contains `data-chat-create-form="track"`; `chat.js` submits to `/api/chat-create-v76.php`. | The endpoint directly inserts `tracks`, bypassing the shared writer and omitting `workspace_id` from that INSERT. ML1 must reconcile this active creation path. |
| `media-library.php` | Private generic media assets; API accepts audio, while current page upload is image-oriented. | An Add Song action should select an asset and target workspace, then use the shared catalog workflow. Generic audio storage is not a song record. |

Evidence: [V8] [V9] [V10] [V15] [V16]. The workspace writer currently stores basic catalog fields and eagerly creates/synchronizes a backing Player track. The workspace catalog ID, backing `tracks.id`, audio asset/version and composition identity must remain distinguishable. Its current delete removes the catalog row and unpublishes the backing track; replacing audio can delete the prior owned file. Historical listening/financial references therefore require deliberate archive/version behavior. Legacy listening tables also have cascading track/session foreign keys, which must be considered before any hard deletion of records underlying future statements. [V8] [V18]

VP3 has free-text `tracks.credits` and structured `track_credits` attribution, but no structured writer/publisher/PRO/ISRC/ISWC or ownership-allocation support was found in the audited catalog writers. Credits include manually declared contributors and inferred production participants; neither category establishes a payee or economic share. [V9] [V11]

### Field map for the shared Song Metadata & Rights form

The final column is a proposed integration rule, not an assertion that the VP3 schema exists. Stoneward provides source material for field names and validation; it is not a complete royalty-accounting schema. [S1] [S2]

| Information | VP3 audited representation | Stoneward existing representation | Shared-model / export rule |
| --- | --- | --- | --- |
| Song/recording identity | Workspace catalog ID plus backing `source_track_id`. | String catalog `id`; prepared songs use `sf-` plus a request-key prefix. | Store explicit cross-references; do not match by title or overwrite local IDs. |
| Title, album, duration, audio, cover | Structured catalog fields. | `title`, `release`, `duration`, `audio`, `artwork`. | Map fields and release membership explicitly; keep asset versions separate. |
| Recording identifier | No dedicated field in audited writer. | `metadata.isrc`, format and catalog uniqueness checks. | Store supplied ISRC; no automatic registration claim. |
| Composition identity | No structured work relation found. | Archive `canonical_work_id`; `rights_works.iswc` and `pro_work_id`. | Model shared works separately from recordings; existing Stoneward rights works are unique per track. |
| Writers/composers | Free-text or contribution roles. | `words_by`, `music_by`, template `co_writers`. | Repeatable parties/roles; preserve public credit text separately from ownership. |
| Performing-rights affiliations | No structured catalog field found. | `pro_affiliation`, `ipi_cae`; party-level equivalents. | Map supplied affiliations/identifiers with provenance. |
| Publisher | No structured catalog field found. | `publisher`, `publisher_pro`, `publisher_ipi`; rights work `publisher_name`. | Repeatable relationships, not one field inferred from a writer's name. |
| Label / record company | No structured catalog field found. | `label`, `catalog_number`. | Public metadata; not automatically a master owner or payee. |
| Copyright / ownership | Attribution is not ownership. | `composition_copyright`, `master_copyright`; party/work/split records. | Keep recording and composition claims separate. |
| Splits | No music allocation engine found. | `rights_splits.percent_bp`, `split_type` composition/master. | Versioned explicit agreements belong in ML4; template percentages are not settlement instructions. |
| Production / performers | `track_credits` roles/details. | Producer/engineer/studio fields, performers, archive personnel. | Reuse display credits without granting financial rights. |
| Release / content flags | Visibility, publication and album release date. | Lifecycle state, release date/year, language, explicit flag. | Translate publication eligibility; retain drafts and local presentation choices. |

## 5. Stoneward import and player integration

### Existing code worth preserving

Stoneward reads `data/catalog.json`. Its shared catalog writer locks and replaces that file, optionally checks a revision hash, then generates `assets/js/catalog.js`. Current local-folder intake creates private drafts; Media Library intake and prepared-song publication require a separate reviewed publication action. Replay fingerprints and ISRC collision checks already exist. Catalog lifecycle supports published, hidden, archived and recoverable deleted states while retaining IDs and history. [S1] [S3] [S14] [S15] [S18]

The persistent player resolves local string IDs for queues/playlists and assigns `track.audio` to the persistent audio element. Recovery retries that same URL while restoring position. Canvas validators accept only letters, digits, underscore and hyphen in track IDs; an unplanned colon-delimited remote ID would fail. Preserve these interfaces and add a provider adapter. [S4] [S5]

### Three concrete integration gaps

1. **Remote sources across all listening surfaces.** The main player can attempt a supplied URL, but `sf_canvas_audio_track_source()` explicitly rejects scheme/host-bearing references. Agent catalog selection filters unavailable sources. Lyric transcription requires a managed local `api/media.php?id=M...` reference and verified local bytes. ML2C must adapt ordinary playback, Agent/canvas selection, authorization refresh and supported excerpts together; URL import alone does not preserve those capabilities. [S4] [S5] [S6]
2. **Authority and public serialization.** Stoneward already has locally editable metadata, archive records and a rights registry. Imported VP3 fields need an authority/revision map and local editorial overrides. The public catalog serializer filters hidden tracks and strips lyrics, then retains other fields. Credentials, private rights details and synchronization state must stay outside that public payload. [S1] [S2] [S7]
3. **Streaming versus production entitlements.** Stoneward separates a public listening copy from a private master and gates paid downloads/vinyl accordingly. A VP3 streaming grant must not automatically activate digital ownership or manufacturing rights. Preserve the existing commerce flags and grants. [S3] [S8]

### Existing connection direction is different

VP3 already implements a Stoneward consumer connector: owner-bound encrypted credentials, origin/instance binding, `listening.history.read`, play/queue capabilities and expected remote `/api/stoneward-vp3.php` and `/api/stoneward-vp3-audio.php` endpoints. This consumes an external artist site; it is not the proposed artist publishing grant from VP3 to Stoneward. [V12]

The corresponding direct connector endpoints and a VP3 catalog-import implementation were not found in the inspected Stoneward checkout. HomeServer account/device pairing does exist and is a separate integration. Therefore, “integration has started” is supported on the VP3 side; end-to-end compatibility with this Stoneward revision is unverified. ML2B/ML2C must establish the matched provider/adapter contract before ML2D's joint pilot. [S17]

## 6. Financial, hosting and recovery boundaries

**VP3 commerce is reusable infrastructure, not music earnings.** Generic orders, receipts, refunds and audit/deduplication helpers exist; package billing exists separately. A commerce platform-fee default of zero basis points is not a royalty rate. No verified amount should appear in an artist earnings dashboard merely because a playback qualified analytically. [V13] [V19] [V20]

The earlier source lead was `stonefellow74-debug/vp3-working`, branch `claude/home-stereo-phase-9c-royalty-backoff`. Retrieval was unavailable through current access: Git required unavailable authentication, connected repository lookup returned 404, and branch search did not resolve the repository. No code or rate was recovered. This does not establish that the branch was deleted.

**Stoneward has real customer commerce, but not the proposed stream royalty ledger.** Verified Stripe payment handling checks session, environment, amount/currency and PaymentIntent before recording payment and following through to Library/fulfillment. Existing rights records are editable declarations/readiness data, not historical accrual agreements. Their checks also do not evaluate every stored restriction, including start dates/territory. Older general analytics include simulated/test paid states; the newer verified conversion report separates live, test/simulated and unverified revenue. Neither establishes causal listening-to-order attribution. [S2] [S10] [S11] [S16]

**Managed hosting remains a separate milestone, MH1.** VP3 has package entitlements, stable site keys, hostname/DNS/TLS state and deployment records. Current Cloud Hosting source binds execution to HomeServer; it does not demonstrate a finished managed Stoneward installer/package. Reuse these records where appropriate while retaining installation identity across approved domain/hosting moves. Restores and independent clones need different identity/grant behavior. [V14] [V17]

**Shared-host compatibility needs source-specific validation.** Stoneward can attempt FFmpeg conversion and fall back to bounded MP3 remuxing when process execution is unavailable. That is not proof arbitrary WAV conversion works on every shared host. VP3-hosted imports should not require downloading local production masters merely to satisfy the old intake path. [S12]

**Recovery is coordinated file-and-database recovery.** Stoneward upgrade code checks drift, preflight, database backup and integrity. Authoritative orders are JSON files; catalog/releases are also files, while grants/rights/listening use the database. A database-only restore cannot establish a consistent recovery point for all those records, media references and connection credentials. No full restoration was exercised in this audit. [S1] [S10] [S13]

## 7. Validation record and limits

Exactly these two Node checks previously passed at the VP3 audit revision:

- `node tests/music-module-runtime.mjs` — `MUSIC_MODULE_RUNTIME=PASS`.
- `node tests/music-workspace-resource-ownership-v330.mjs` — source/structural ownership contract passed; this is not live database isolation proof.

PHP was unavailable. `php tests/music-module-integrity.php` could not execute, and `node tests/player-history-api.mjs` could not launch PHP (`ENOENT`); these are execution limitations, not failed application assertions. No Stoneward test suite was rerun, and no payment-provider, production listener, managed-host installer or restore verification was performed. The documentation package does not upgrade these observations into runtime certification.

The bounded implementation sequence is ML1 catalog/identity/permissions; ML2A native telemetry; ML2B VP3 provider; ML2C Stoneward adapter; ML2D joint pilot; ML3 workspace analytics; ML4 earnings; ML5 payouts; ML6 Agent/operations release. MH1 covers managed hosting separately. This audit closes ML0's source inventory; acceptance gates and unresolved product choices belong in the accompanying delivery documents.

[V1]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/admin/listening.php
[V2]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/admin/analytics.php
[V3]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/music-workspace.php
[V4]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/api/playback.php
[V5]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/includes/permissions.php#L1378-L1421
[V6]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/includes/player-v76.php#L19-L202
[V7]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/chat.js#L4081-L4415
[V8]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/includes/music-catalog.php
[V9]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/api/chat-create-v76.php#L161-L385
[V10]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/api/media-library-v86.php#L121-L219
[V11]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/includes/release-agent-v105.php#L98-L124
[V12]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/includes/connected-stoneward-v1.php
[V13]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/includes/agent-commerce-v800-part1.php#L144-L348
[V14]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/includes/cloud-hosting-v100.php#L5-L89
[V15]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/chat.php#L95
[V16]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/chat-legacy-v108.php#L2082-L2259
[V17]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/includes/cloud-hosting-domains-v200.php#L26-L80
[V18]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/schema.sql#L401-L441
[V19]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/includes/billing-schema.php#L31-L132
[V20]: https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/includes/agent-commerce-v800-part4.php#L31-L89
[S1]: https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/admin/api/bootstrap.php#L73-L154
[S2]: https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/api/rights-core.php#L11-L84
[S3]: https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/admin/api/catalog.php
[S4]: https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/assets/js/app.js#L5119-L5190
[S5]: https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/api/canvas-audio-core.php#L66-L156
[S6]: https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/api/agent-song-catalog-core.php#L30-L78
[S7]: https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/api/songbook-core.php#L14-L28
[S8]: https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/api/media-catalog-intake.php#L61-L108
[S9]: https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/api/listening-playback-core.php#L11-L181
[S10]: https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/api/store-payments.php#L154-L215
[S11]: https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/api/fan-conversion-verified.php#L4-L56
[S12]: https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/api/media-catalog-intake.php#L113-L232
[S13]: https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/upgrade.php#L73-L85
[S14]: https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/admin/api/scan-private.php#L3-L105
[S15]: https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/api/agent-song-catalog.php#L9-L105
[S16]: https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/api/analytics-core.php#L7-L9
[S17]: https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/homeserver-connect.php
[S18]: https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/admin/api/media-catalog.php#L49-L110
