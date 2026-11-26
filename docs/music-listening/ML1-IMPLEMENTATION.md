# ML1 — song catalog and metadata foundation

## Implemented scope

Main **Add Track**, `/music-library.php` and `/admin/artist-music.php` now use the same workspace-authorized catalog writer and song metadata form. The main flow selects an explicit Music Workspace and uses that workspace's catalog album IDs. Workspace ownership/management is the authority; a global Artist or `tracks.manage` grant does not replace it.

Songs can be saved as unpublished drafts without audio, an ISRC or complete credits. Publishing requires a supported uploaded or already-owned audio file that is readable and nonempty. This is local publication readiness, not a rights-clearance or external streaming grant decision.

The shared form includes recording/version, language, explicit-content status, ISRC, composition identity/title/ISWC, repeatable writers/publishers/performers, IPI/CAE and PRO/CMO details, label, copyright notices, private rights notes and private contact email. Identifiers may be unknown, unassigned or provided. Provided values receive syntax validation only. No identifier registration or external ownership verification occurs.

Four saved readiness states remain separate: catalog, playback, earnings and payout. Earnings and payout are explicitly `not_configured`; no numerical rate, zero balance, share agreement or payment destination is inferred from metadata.

The Music Workspace landing page links to **Add song** and **Songs & Metadata**. Active song counts exclude archived recordings. Listening analytics, site/page attribution, Stoneward import and the hosted streaming API belong to the subsequent phases in [delivery plan](DELIVERY-PLAN.md).

## Identity and privacy contract

| Entity | Stored relationship |
|---|---|
| Recording | UUID in `music_recordings_v1`; preserves `artist_catalog_tracks_v181.id` and existing `tracks.id` |
| Composition | UUID in `music_works_v1`; multiple recordings can reference one work |
| Release placement | Existing workspace catalog album/order; separate from recording identity |
| Audio file version | UUID in `music_assets_v1`; records path and available hash/size without exposing paths publicly |
| Contributor/company | Workspace-scoped UUID in `music_parties_v1`; independent of login/payee identity |
| Metadata history | Append-only snapshots in `music_metadata_revisions_v1` and `music_work_revisions_v1` |
| Save request | Workspace/user/request-key receipt in `music_catalog_requests_v1` |
| Mapping review | `music_metadata_migration_issues_v1`; ambiguity is retained for explicit review |

Selecting an existing party reuses its name and identity details. It does not copy a recording identifier or economic shares. Party identity facts are immutable in this phase; a different party/details assertion gets a new record. Selecting an existing composition reuses its canonical details. Changes to a composition already used by multiple recordings are rejected with an explicit conflict instead of silently altering other songs; a dedicated shared-work editing/review operation remains future work.

The private getter requires current track-management authority. Public projection is an explicit allowlist: approved identifiers, credit names/roles, label and copyright notices. It omits IPI/PRO detail, contact email, private rights notes, internal party IDs, asset paths and revision snapshots. A draft's authorized editor can preview this projection; an actual public read additionally checks publication, audience, archive state and backing identity. An explicit anonymous viewer never inherits the signed-in owner's session.

Existing lyrics and additional production fields survive when a workspace editor omits them. Main Add Track's additional production credits retain their existing public meaning. Private notes belong in the private rights section.

## Save and conflict behavior

`music_catalog_write()` owns catalog, production backing, metadata/work/party/asset revisions and request receipt in one transaction. Membership is checked before the operation and again under the workspace write lock. A linked recording cannot silently switch to a different established Player ID or workspace. Cross-workspace album, artwork, work and contributor IDs are rejected.

Existing song edits, archives and restores require both the raw catalog row `revision` and integer `metadata_revision`. A stale or missing version returns a conflict; forms retain submitted text and the submitted versions. The user can inspect the latest saved version before reconciling changes. The form never substitutes a newer version automatically after a failed save.

New interactive/API creates carry `request_key`, a random key for one intended save. Repeating the same key and payload returns the prior catalog ID; changed content under a completed key returns `409`. Receipts are scoped to the current workspace and actor, and current authority is checked even for a replay. A retry cannot bypass revocation. The shared writer still accepts metadata-omitting legacy internal creates, while all updated interactive create routes require request keys.

Uploaded audio and cover files are removed if validation or related database work fails. Duplicate successful requests clean up the unused retry upload. Replaced audio remains attached to its earlier asset version. Upload-bearing calls must start outside another caller's open transaction, so a later outer rollback cannot strand physical files; they fail with `409` before moving bytes. Metadata-only nested calls use savepoints and preserve the caller's transaction after a failed nested operation.

The existing audio repair service preserves its original catalog/Player behavior, including unlinked legacy production records. For linked catalog recordings it now invokes the canonical metadata service inside its file-update transaction, recording the replacement asset and revision without changing private metadata or publication. It also rejects uploads inside an outer transaction.

**Archive song** unpublishes both catalog and Player records while preserving IDs, playlists, favorites, history, production references, metadata and prior files. **Restore draft** restores the same recording as unpublished. Restoring an already-active song is a no-op and cannot unexpectedly unpublish it. Album deletion retains its existing behavior: detach songs, preserve songs and production history.

## Private HTTP contract

`/api/music-song-metadata-v1.php` uses the existing signed-in session. Responses are private/no-store. POST requires the existing CSRF token. It is an internal workspace-management API; it is not the later Stoneward grant/streaming API.

| Request | Result |
|---|---|
| `GET ?workspace_id=W` | Authorized reusable `parties` and `works` |
| `GET ?workspace_id=W&id=C` | `catalog`, raw catalog `revision`, `recording` envelope, parties and works |
| `POST` with `workspace_id`, `id`, `action=save`, catalog fields and metadata | Same shared save as the forms; returns catalog/Player IDs, new catalog revision and recording envelope |
| `POST action=delete` | Archive existing song, with required revisions |
| `POST action=restore` | Restore an archived song as a draft, with required revisions |

POST accepts multipart/form data or a JSON object. Multipart carries `audio_file` and optional `cover_file`; the latter enters the workspace's Media Library atomically with the song. Existing artwork can be selected by `cover_photo_id`. JSON requests are bounded to 192 KiB.

The metadata object is `music_metadata`; `metadata_revision` and `request_key` are top-level fields. The nested work uses `work_id` and `work_revision`. Contributor arrays contain `party_id`, `name`, `ipi`, `pro`, `role`; label contains `party_id`, `name`. Unknown keys, including economic-rate/share instructions, are rejected. Save preserves existing private metadata if a legacy field-only caller omits `music_metadata`.

The `recording` response contains `recording_id`, `work_id`, `asset_id`, `revision`, `work_revision`, `archived`, `metadata`, `readiness`, and the approved `public` preview. Both GET and POST return the raw catalog revision for the next edit. `id` on this API is the **catalog** ID; main `/api/chat-create-v76.php` retains its historical **Player** `id` and additionally returns `catalog_track_id`, `recording_id`, `metadata_revision` and the workspace editor URL.

Expected errors use `401` sign-in, `403` authority, `404` unavailable scoped record, `405` method, `409` conflict, `419` CSRF, `422` validation and `503` upgrade/storage availability. Database/internal failures return a generic `500`, with details logged server-side. An API success is a completed catalog operation, not proof of external playback or payment.

## Additive migration and recovery

The web upgrader and fresh installer install the new metadata schema after existing workspace/music prerequisites. Normal reads and writes do not execute metadata DDL. The ML1 prerequisite upgrade runs the older workspace schema helper in additive-only mode when necessary, avoiding its historical uploader-based ownership backfill.

The installer rereads each catalog mapping under the same workspace lock as the writer. Existing catalog/Player rows, user favorites/history, playlists and Studio relationships remain in place. Safe mappings get new metadata identities and a `legacy_import` revision. Missing workspaces/backings, duplicate catalog links, conflicting workspace assignments, established identity drift and unlinked legacy Player records are reported for review. The migration never resolves these by guessing ownership from the uploader. Rerunning it does not duplicate previously imported metadata.

Before changing an actual deployment:

1. Back up the complete database and music/art uploads together; preserve the currently deployed code/version and verify the backup can be read. Pause catalog writes during the operational upgrade.
2. Run the read-only inventory on the existing deployment configuration:

   ```sh
   php scripts/music-song-metadata-v1.php --report
   ```

3. Install the matching code and use the authorized web upgrader, or run the explicit metadata migration after the workspace prerequisites exist:

   ```sh
   php scripts/music-song-metadata-v1.php --migrate
   php scripts/music-song-metadata-v1.php --report
   ```

4. Compare checked/migrated/staged counts and preserved catalog/Player IDs. Review staged mappings explicitly before assigning them. Exit `2` means the command completed with mappings requiring review; it is not permission to discard them.
5. Smoke-test an owner and a manager: create a draft, edit metadata, upload audio, preview permitted playback, verify foreign-workspace denial, archive/restore and existing personal history. Check protected upload paths on the actual web server; Apache `.htaccess` files do not independently validate another server's routing rules.

The CLI supports `--config=/absolute/config.php`, is CLI-only, defaults to report, and does not expose credentials in output. Report is read-only; migrate is explicit. The additive schema preserves old records, but reverting code alone after new production writes does not reconstruct the prior application state. If rollback is necessary, pause writes, retain a copy of the failed state for diagnosis, and restore the matching backed-up database/uploads/code together. Do not delete retained audio or drop metadata tables as an improvised rollback.

There is no automatic purge policy for prior audio versions in ML1. Storage retention and later publication/grant lifecycle remain explicit follow-up decisions. A production backup restore and deployed server checks have not been performed during this development task.

## Acceptance and evidence

| Acceptance | Implemented evidence |
|---|---|
| M1-01 shared identity | Actual Add Track HTTP create followed by metadata API edit retains catalog, Player and recording IDs |
| M1-02 authority | Real workspace roles; foreign album/art/work/party denial; revoked manager; private reads and public projection |
| M1-03 conflicts/retries | Required versions; changed-payload conflict; identical retry preserves rows/files |
| M1-04 atomic failure | SQLite trigger failure rolls back catalog/backing/related records and uploaded files; MySQL native trigger case in CI |
| M1-05 legacy preservation | Migration and archive/restore preserve catalog/Player IDs and FK-backed favorites, playlists, history and production references |
| M1-06 distinct entities | Shared composition with separate recordings and versioned assets; existing release placement retained |
| M1-07 incomplete drafts | Audio-free drafts, unknown/unassigned identifiers, syntax-only provided IDs; missing/unreadable file cannot publish |
| M1-08 reusable defaults | Saved party/work choices without copying ISRC or economic shares; immutable party identity checks |
| M1-09 privacy | Explicit projection, anonymous audience regression, private notes/contact excluded |
| M1-10 provenance | Actor/time/server provenance and immutable metadata/work snapshots; economic fields rejected |
| M1-11 form usability | Shared renderer/browser suite for desktop and narrow viewport; physical-device acceptance remains separate |
| M1-12 migration/recovery | Real rerun and ambiguity staging tests; read-only report verified; operational backup/restore procedure above, production restore not yet run |

Primary commands:

```sh
php tests/music-song-metadata-v1.php
node tests/music-song-metadata-api-v1.mjs
node tests/music-song-metadata-browser-v1.mjs
php tests/music-song-metadata-mysql-v1.php
node tests/music-file-repair-api.mjs
php tests/music-module-integrity.php
node tests/music-module-runtime.mjs
node tests/music-workspace-resource-ownership-v330.mjs
node tests/player-history-api.mjs
```

The MySQL test requires an empty disposable database named `vp3_music_test...`, configured through `MUSIC_TEST_MYSQL_DSN`, `MUSIC_TEST_MYSQL_USER`, `MUSIC_TEST_MYSQL_PASSWORD`. Without a DSN it prints `SKIP`; that is not a pass. The dedicated **Music Metadata ML1** workflow supplies MySQL 8 and runs the browser check. The existing **Recovery Baseline** also runs the new SQLite/service and real HTTP tests. Review the exact candidate commit's checks before release.

This phase does not certify the full VP3/Stoneward listening journey. The next complete milestone remains the one-recording provider/import/playback/telemetry scenario in [streaming contract](STREAMING-CONTRACT.md).
