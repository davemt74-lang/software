# ML2A — native listening measurement and receipts

## Scope and evidence boundary

ML2A extends the existing shared native audio tracker in Chat and Player. It preserves the existing player, queues, playlists, account-owned history, listening continuation and accepted Agent media-offer relationship. It does not establish the VP3 external streaming provider, Stoneward import, artist analytics UI, managed hosting or financial accrual.

The active client uses `native_listening_v1` on `/api/playback.php`. Existing clients without this version retain their legacy path, and their measurements remain legacy evidence. No historical row is rewritten into a newly measured listen. Public-profile and artist-admin audio controls that do not use the shared tracker remain uninstrumented. The existing connected-site player protocol is separate and is not silently routed through the new native counter.

Native telemetry is bounded client-reported evidence. It can establish a coherent, acknowledged playback timeline; it does not prove human attention. The recording/asset mapping is the catalog snapshot at session creation, explicitly `catalog_snapshot_unverified_delivery`. Native media URLs do not yet guarantee version-pinned byte delivery. ML2B owns that delivery contract and ML2D owns the combined provider/client pilot.

## Stable playback and event identities

The browser generates one random `play_id` for an intended native play. The server scopes it to the authenticated listener (including anonymous identity where the route permits it), listener cookie and authorized backing track. Starting the same request again returns the saved start result within the session's validity window. Conflicting reuse is rejected. A session starts with zero accepted listening time.

An initial request without a valid listener cookie receives `428 listener_initialization_required`, sets the cookie, and writes no session. The client retries its frozen start after accepting that cookie. Losing this first response cannot allocate multiple sessions under different newly generated cookies. Subsequent events with a missing cookie fail; they do not create a replacement listener session.

Every subsequent event has its own random `event_id`, a strictly increasing `seq`, elapsed session time, cumulative playing time, media position and playback rate. Events are accepted in sequence under a database lock. An identical retry returns the original saved acknowledgement. A sequence gap identifies the next expected sequence; changing a previously accepted event is a conflict. The client serializes its queue and retries the exact request after an uncertain response.

Acceptance updates the event receipt, native measurement and compatible `track_play_sessions` / `track_play_events` rows in one transaction. A failure cannot leave a counted interval without its receipt. Replay does not issue another Agent offer receipt or listening notification. Current account and media authority are checked at the HTTP boundary; possession of a token does not replace them.

Legacy backing track IDs and user ownership remain intact, so favorites, playlists and account history retain their existing relationships. Artist metadata does not grant access to somebody else's individual listening history.

## Time, position and completion

The client samples its actual playing state and content advancement. Pause, buffering, seeking and failed autoplay must not accrue normal playing time. Seek is a position change, not evidence that the skipped content played. Rate changes establish a new measurement boundary. End/stop preserve the final pending interval and close the old playback, even if the next song or a replay has already started.

The server validates numeric ranges, monotonic cumulative values, ordered state transitions, elapsed server time and plausible position advancement. Each playing segment is anchored when the server accepts its resume transition; changing the action name cannot bypass that anchor. Each interval is limited to the part of its reported timeline that has actually elapsed on the server. Previously acknowledged pause time cannot become credit for a later interval or a backdated resume.

Reporting can recover delayed acknowledgements within an already-established playing segment without counting a retried interval again. If a whole segment took place before the server received its resume, some or all of that segment is conservatively clipped and marked partial. Retaining a client observation is not the same as accepting every reported millisecond. Sessions expire after 24 hours and accept at most 10,000 events, with at most 30 seconds credited per event. These are engineering bounds, not a retention or royalty policy.

The client retains at most 64 pending events per play and eight pending plays per attached player. Normal transport retry uses three delays after the first attempt; listener-cookie initialization has its own limit of two retries. Requests older than two minutes stop retrying. A browser reload does not restore another page's queued playback credentials; page departure delivery is best effort and cannot be represented as a server acknowledgement. Clipped or missed observations and muted/loop playback remain explicit diagnostics.

The historical engagement definitions are retained under the explicit metric version `legacy_10s_80pct_v1`: a qualified play requires at least 10 accepted seconds, and accumulated listening of at least 80% of a usable duration supplies the historical completion flag. Natural ending alone does not produce completion. Furthest media position remains distinct from accumulated listening and must not be presented as proof that every segment was heard.

Browser duration and the artist-entered catalog duration are observations. A supported server-side media probe supplies file duration when available; otherwise duration-dependent completion is unavailable. Unsupported codecs, missing files and unresolved identity mappings retain explicit coverage limits. These engagement definitions do not establish royalty eligibility, payable balances or financial finality.

## Source attribution and privacy

Native playback records its source surface and a sanitized source context. URL handling uses approved route forms. It removes query strings, fragments, credentials and sensitive or unknown paths instead of preserving a hidden raw URL. An unsafe path can contribute an origin or route category with an explanation for the missing page.

An observed same-origin referrer and a browser-reported page are different forms of evidence. Neither is a registered external artist installation. Native source validation uses the existing `site.base_url` canonical origin and `site.base_path` install prefix. If no canonical origin is configured, page context stays unknown; an arbitrary request Host header is not promoted to a trusted site identity. Missing or withheld context stays unknown, and the audio URL is never substituted for the originating page. ML2B adds registered sites and the external source-grant relationship.

The client captures the actual signed-in listener ID and CSRF context, not a profile owner or workspace artist ID. It stops using queued requests when that context changes, and the server independently checks account identity. A missing/revoked backing history row cannot be recreated through receipt replay. No new history-sharing authority or cross-site person identity is introduced.

## Installation, reporting and recovery

The installer and web upgrader add the listening schema after existing music prerequisites. Ordinary playback requests do not execute schema DDL. A versioned client facing a missing schema reports unavailable measurement; it does not downgrade itself into legacy counting.

The command-line report is read-only and defaults to reporting:

```sh
php scripts/music-listening-v1.php --report
```

After backing up the matching database, uploads and code, install explicitly:

```sh
php scripts/music-listening-v1.php --migrate
php scripts/music-listening-v1.php --report
```

`--config=/absolute/config.php` selects an existing application configuration. Reports contain counts and coverage, not listener identities, session tokens or raw page URLs. Missing schema returns exit code 2. Installation is additive and repeatable; it does not synthesize events or reassess historical money.

There is no automatic raw-event deletion policy in ML2A. Operational session/retry bounds are separate from data retention. D-05 remains open for a reviewed retention policy; a future purge must preserve the guarantees against replay and restoration of forgotten history. If rollback is necessary, pause writes and restore the matching database, uploads and code together. Keep the failed state for diagnosis.

The Cloud archive includes [plain-text deployment instructions](../MUSIC-CATALOG-LISTENING-DEPLOY.txt), identifies the protocol in `VP3_RELEASE.json`, and checks that the required service, client, API and migration files exist. Deployed-host checks, physical-phone playback and a production backup restore are operational evidence, not results inferred from CI.

## Acceptance and scoring

The implementation score applies to the named automated scope below. Each row must pass on the reviewed candidate before that scope receives 10/10. The full music product and live deployment retain their separate phase gates.

| ID | Required result | Primary evidence |
|---|---|---|
| M2A-01 | Same start/event returns one saved receipt; conflicting reuse and sequence gaps cannot add listening | PHP service, real HTTP and native MySQL |
| M2A-02 | Pause, stall, seek, rate changes, retries and terminal events preserve an honest cumulative timeline | Client, service and browser |
| M2A-03 | Duration/identity/coverage and versioned engagement meaning stay explicit; ending alone cannot complete a song | Service and HTTP |
| M2A-04 | Session, cookie, track and actual listener authority remain bound across replay/account changes | Real HTTP and service |
| M2A-05 | Safe source routes are retained while private paths, credentials and query secrets are discarded | Service and HTTP |
| M2A-06 | Lost acknowledgements, a pending start, bounded retry and old/new playback generations recover without duplication | Client and real Chat browser |
| M2A-07 | Receipt and legacy measurement changes commit or roll back together under real database behavior | SQLite and native-prepare MySQL |
| M2A-08 | Migration reruns safely, reports are read-only and old history remains unchanged | CLI and database tests |
| M2A-09 | Existing Player history, Agent offer proof, queues and continuation retain their behavior | Existing runtime/API/browser regression gates |
| M2A-10 | Matching client/service files ship, actual shared Chat tracking is exercised, and the current candidate passes applicable gates | Browser, package verification and CI |

Test results, candidate commits and release state belong in [development status](DEVELOPMENT-STATUS.md) and the implementation PR. A configured MySQL test that did not run, or a browser that could not start, remains unverified. Later provider/Stoneward tests must identify both actual compatible builds.

## Next phase

ML2B builds authorized catalog/selection access, registered site grants, visitor playback sessions, version-pinned audio delivery/renewal, service usage and revocation. It consumes the native measurement concepts while keeping artist management credentials separate from visitors. ML2C supplies Stoneward's **Import Music from VP3** adapter; ML2D verifies the complete one-recording journey before ML3 presents artist reporting.
