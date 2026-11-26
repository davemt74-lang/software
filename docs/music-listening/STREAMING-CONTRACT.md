# VP3 Music Provider and Stoneward Streaming Contract

**Status: product integration contract, partially implemented.** ML1 implements metadata and [ML2A](ML2A-IMPLEMENTATION.md) implements native measurement. [ML2B](ML2B-IMPLEMENTATION.md) now owns the exact implemented `vp3_music_provider_v1` routes, selected public catalog scope, manual site verification, visitor sessions, ranges, receipts and revocation. The broader consumer/private-entitlement/hosting behaviors below remain proposed until their phase ships. A provider implementation does not establish deployed Stoneward integration, royalty obligations or completed joint acceptance. No application change is authorized by this specification alone.

Read with [MASTER-SPECIFICATION.md](MASTER-SPECIFICATION.md), [SOURCE-AUDIT.md](SOURCE-AUDIT.md), [DELIVERY-PLAN.md](DELIVERY-PLAN.md), [DEVELOPMENT-STATUS.md](DEVELOPMENT-STATUS.md), and the project [README.md](README.md). Evidence snapshots are VP3 `2b69d47b04bb2bcdd74402189b820eef22ed1235` and Stoneward `253d1267c95ffefbcf3a79173429abbbba0c33fc`; later implementation must recheck current source.

## 1. Product boundary

VP3 is the canonical artist catalog, metadata, audio-storage, hosted-play measurement, and future earnings authority. Each artist can run a standalone Stoneward installation on their own hosting or choose a managed installation on a VP3 subdomain. The artist does not need HomeServer for either arrangement. Hosting ownership does not change the music protocol.

**Import Music from VP3** connects an artist's account, selects an authorized Music Workspace, displays its importable tracks/albums, and imports selected references and metadata into Stoneward. Audio remains hosted by VP3. Stoneward retains its own catalog IDs, public pages, presentation, player, playlists, and permitted listener features. The same provider interface should support custom artist sites.

Use a provider source adapter within Stoneward's existing main, Agent, footer, and host-owned canvas players. Do not introduce a competing player or a second independent audience counter. Raw byte retrieval for decoding, production, or custom processing is not evidence that a song was heard.

## 2. Distinct authorities and identities

| Authority | Intended access | Boundary |
| --- | --- | --- |
| Artist connection | Connect an approved site to a selected VP3 workspace; read its allowed catalog and maintain selected imports | Management credential stays on the site backend; it is never the visitor's identity |
| Selected-catalog grant | Explicit tracks/albums and supported playback uses for that connection | Grants no general catalog-editing or VP3 publication authority; additional selection requires the authorized management flow |
| Visitor playback grant | One eligible recording/asset and logical playback session, with bounded lifetime and source context | Cannot enumerate private music, manage catalog, read artist finance, or inherit artist privileges |
| Listener-history pairing | Separately approved access to a particular listener's history | Does not grant artist import, publishing, payment, or unrelated listener access |
| Existing HomeServer fan grant | Stoneward customer's purchased-library/private-audio access | Separate purchase-based integration; neither required for this feature nor equivalent to artist authority |

Publication, connected-site management, and listening-history access require separate checks. Current VP3 helpers can permit owners to hear drafts; external visitor delivery must explicitly evaluate publication and visitor entitlement instead of borrowing the owner's permission. Current `members` visibility is not proof of an artist-specific subscription or purchase.

Stable identity fields must distinguish provider installation, workspace, registered site, canonical recording, catalog track/release, audio asset version, metadata revision, and consumer-local item ID. Public IDs must not expose storage paths. References are verified server-side; an identifier supplied by a browser grants no authority.

## 3. Connection and domain lifecycle

A registered site has an immutable site ID, responsible artist/workspace relationship, deployment profile, allowed HTTPS origins, approved grants, and a connection revision. Normalize scheme, host, and port. Treat production, staging, previews, and managed subdomains as explicit registrations or environments; avoid broad wildcard trust.

Connecting requires the appropriate workspace authority and an exact validated callback. Credential rotation, expiry, revocation, and reconnect are supported lifecycle operations. Store long-lived secrets outside public responses and logs. A reconnect must not duplicate imported items or silently expand prior selection.

Domain changes require verification and an explicit transition. Bind new grants to the updated connection revision; revoke old-origin issuance and renewals. Existing media access has a documented maximum validity window. Public-page ownership, an Origin header, or a claimed source URL alone is insufficient publisher authorization.

Account suspension, workspace authority removal, site deletion, unpublishing, and rights restrictions must stop inappropriate new grants. Consumer catalog records can retain safe metadata and availability explanations. Neither a cached import nor a managed hosting subscription overrides a revoked music grant.

## 4. Catalog selection and import

The authorized selection view supports tracks and albums, search/pagination, release ordering, duration, artwork, metadata completeness, and publication/streaming eligibility. A selected album resolves to a reviewable track list and revision. Newly added album tracks must not silently expand the grant; a refresh presents additions for acceptance under the agreed selection policy.

An import manifest contains canonical IDs, selected membership, approved metadata/artwork references, metadata revision, audio version, publication state, and stable availability status. Never return private file paths, management secrets, owner email, or production notes in the visitor projection. Importing draft metadata for staging, if supported, requires separate authority and does not make its audio public.

Stoneward preserves a unique mapping of provider installation, workspace, canonical item, and local site. Import operations carry an idempotency key and retain a result receipt. Retrying, reconnecting, or importing an album containing an already imported single updates the mapping rather than producing duplicate local songs. Do not match identity by title alone.

Keep canonical metadata and local presentation overrides separate. The shared song/rights form follows ML1 in [MASTER-SPECIFICATION.md](MASTER-SPECIFICATION.md). Refresh must report changed metadata, changed audio, removed selections, unpublished tracks, conflicts, and partial failures. A missing import response is an uncertain operation to reconcile using its receipt, not permission to create another item.

A metadata edit preserves recording identity. A technical re-encode creates a new asset version; a different performance/remaster requires the defined recording-version decision. Active playback remains bound to its original asset. Preserve album sequence and historic mappings when a track moves between releases.

## 5. Proposed interface surface

The operation names below describe the integration model. [ML2B's route and request contract](ML2B-IMPLEMENTATION.md#routes-and-request-contract) is authoritative for implemented PHP endpoints and capabilities; discovery does not advertise unimplemented operations. In particular, provider selection retries return stored receipts, while Stoneward's local import/status receipt remains ML2C work.

| Operation | Caller and response |
| --- | --- |
| `catalog.read` / `selection.update` | Artist backend; workspace-scoped eligible items and explicitly selected membership |
| `import.manifest` / `import.status` | Authorized site backend; versioned selected projection and idempotent operation result |
| `playback.begin` | Public or entitled visitor context; attempt/session ID and narrow delivery/reporting grants |
| `playback.renew` | Valid continuation authority; renewed grants for the same session and asset |
| `playback.events` | Session-scoped telemetry; stable receipt, acknowledged sequence/time, and coverage |
| `media.read` | Eligible GET/HEAD request; authorized bytes, version validator, and range response |

Separate management credentials from public catalog reads and visitor bootstrap. Public reads expose only the approved public projection. Public listening must work without a VP3 login or third-party cookies. Protected listening requires a separately established visitor entitlement; a site connection is not that entitlement.

Responses need a protocol version, correlation ID, stable result/error code, retryability, and relevant resource revision. Proposed codes include `connection_revoked`, `selection_changed`, `publication_unavailable`, `ticket_expired`, `event_conflict`, `invalid_range`, and `temporarily_unavailable`. Use a non-disclosing unavailable response where distinguishing private from nonexistent content would leak information. Do not include tokens, file paths, or raw exceptions.

Proposed management availability values are `ready`, `processing`, `restricted`, `unpublished`, `removed`, `temporarily_unavailable`, and `connection_inactive`. Authorized management can receive a specific recovery reason; visitor projections collapse private/nonexistent distinctions to `unavailable`. Availability and telemetry coverage are separate: a playable song can have incomplete measurement.

Capability discovery and supported version ranges govern compatibility. Unknown mandatory fields or unsupported versions produce a clear upgrade/reconnect outcome, not a permissive fallback. Exporting canonical metadata/CSV can be designed later; this contract does not promise DDEX certification or external-distributor compatibility.

## 6. Playback, renewal, and audio lifecycle

Allocate an attempt/session independently of media credentials. Allocation, HEAD, preload, range requests, and downloaded bytes do not themselves count as heard playback. Count the applicable start only after accepted playback evidence. A deliberate replay creates a new logical play according to the metric policy; retry, seek, component rerender, or credential renewal does not.

Bind grants to the registered site/connection revision, selected recording, asset version, playback session, access class, and expiry. Keep browser-visible authority narrow. Renewing preserves session ID, asset version, source attribution, event sequence, cumulative acknowledged time, and any eligibility decision already recorded. Recheck current authority; renewal cannot revive a revoked grant.

Stoneward currently treats a changed literal audio URL as a playback change and can restart telemetry. The adapter must recognize refreshed URLs for the same source/asset/session and continue playback without entering that new-play branch. Its existing network-recovery and verified-seek behavior should remain the normal recovery path. Source resolution must explicitly accept validated provider descriptors; do not relax general arbitrary-remote-URL restrictions.

Keep old assets available for the defined active-session window when replacing audio. Subsequent range requests must not mix different file versions. Use version-aware validators; reject incompatible continuation rather than returning a different recording under the same session. Publication withdrawal and asset retention have separate policies: retaining evidence or a file for an existing obligation does not authorize public playback.

## 7. Listening evidence and counting

The shared model carries logical play ID, stable event ID, sequence, event type, canonical track/asset identity, cumulative elapsed playing time, media position, playback rate, and source context. VP3 supplies authoritative duration from processed media; browser duration is a diagnostic observation. Bound accepted measurements by duration/version policy, elapsed server time, state transitions, plausible media advancement, and previously acknowledged evidence.

Repeated identical event IDs return their original receipt. Conflicting reuse is rejected. Out-of-order data must not move time backward or double-credit a segment. Define bounded buffering/retry behavior, terminal states, stale-session expiry, and cumulative recovery before implementation. Commit event acceptance, aggregate effects, and receipt atomically. Future earnings consumption must be independently idempotent.

Measure elapsed playback separately from content position. Pause, stall, seek, buffering, and failed autoplay must not accrue normal playing time. Explicitly classify short listens, skips, natural end, actual completion, repeat/loop behavior, muted playback, owner previews, rehearsals, test traffic, and concurrent sessions. Audio delivery is stronger evidence of delivery than client assertions, but neither proves human attention.

VP3's legacy engagement thresholds are 10 seconds for a qualified play and accumulated listening of at least 80% of duration for `completed`. Its displayed completion percentage currently uses furthest position, while an older explanation still mentions natural ending. Preserve historical meaning and introduce a metric version when reconciling these definitions. **Neither 10 seconds nor 80% automatically defines royalty eligibility.** Financial qualification belongs to ML4's approved, versioned rules.

Stoneward has different existing start/resume/completion semantics and measured versus legacy evidence. Do not rewrite its history into the VP3 threshold model. Maintain one Stoneward local play correlated with one VP3 provider session; they describe the same performance, not two payable streams. Correlate through a durable mapping, not approximate timestamps or song titles.

Legacy events, imported history, delayed reports, and missing observations retain explicit coverage/quality labels. Reprocessing or backfill must not create new live announcements, duplicate plays, or new financial obligations. No retrospective rate assignment without a separately approved, auditable migration rule.

## 8. Source URL and privacy

Every accepted listen requires a source-attribution object. Its safe source page URL is populated when available; otherwise it is null with a reason such as unavailable, privacy-restricted, invalid, or unverified. Include registered site ID, verified origin, source surface, attribution method, confidence, and any safe canonical page reference. Do not substitute the audio URL, signed ticket URL, iframe URL, or a guessed path.

Distinguish verified site identity from a site-reported page path. For embeds, parent context uses an allowlisted-origin handshake; private credentials never travel in that exchange. Referrer absence must not prevent authorized public playback. Native app/gallery/scene attribution can use existing server-resolved references while respecting their visibility.

Sanitize URLs **including paths**, not just query strings. Remove credentials, fragments, signatures, tokens, account identifiers, and sensitive path segments. Prefer approved public route forms; retain only allowlisted campaign parameters with a defined purpose. When a path cannot be safely retained, store the origin/public route category and a restricted-page reason. Never retain raw URLs as a hidden fallback.

Stoneward's personal listening history belongs to its listener; VP3 artist analytics are scoped aggregates. The artist's connection does not transfer customer identity, private canvas history, CRM records, or individual preferences. Anonymous visitor totals need honest approximate-identity labels; do not silently deduplicate people across unrelated domains.

Preserve history opt-out, clearing, account change/closure, privacy epochs, and stale-event rejection. Opt-out can retain permitted anonymous totals while removing private linkage. Correlation and retry receipts must not restore forgotten history. Specify retention and any separately required financial evidence before ML4; do not reuse private playback history as an unrestricted financial/person-profile store.

A privacy/account change differs from ordinary ticket renewal. Stop identified reporting and invalidate stale queued events. Any permitted anonymous continuation must not reconstruct erased identity links or credit already counted intervals again. Account switching must not attach the previous listener's playback to the new account.

## 9. Delivery, outages, and usage

Preserve GET/HEAD and single-range behavior, including `206`, `416`, content length/range, MIME validation, and session-lock release. Test byte delivery separately from telemetry. Configure CORS, permitted request headers, exposed response headers, preflight, iframe policy where needed, and resource policy only for approved provider surfaces. Existing VP3 same-origin hosting headers require deliberate scoped changes, not a global relaxation.

Public immutable renditions and private/ticketed responses need different cache policies. Authorization, account-specific responses, or private bytes must not leak through shared caches. Define a measured maximum revocation window across tickets, cached delivery, and renewals. A copy already delivered cannot be recalled by revoking its URL.

If public tracking is unavailable but delivery remains authorized, playback may continue with incomplete/delayed coverage and bounded retries. Never fabricate listening or earnings to fill the gap. Protected/paid access requires valid entitlement evidence; an authorization outage must not silently make it public. Document whether an existing valid grant may finish within its remaining lifetime.

Count bytes, requests, range traffic, processing jobs, and concurrent delivery separately from listens. Configure artist/site quotas, cost alerts, rate controls, storage limits, and treatment of active sessions at a limit. A retry storm or repeated large-file download can consume resources without generating qualified plays. Processing states should distinguish uploaded, validating, ready, failed, replaced, and unavailable; incomplete processing must not appear as a playable release.

Initial deployment profiles must be explicit: supported self-hosted Stoneward, managed Stoneward under MH1, and the VP3 hosting profile certified for provider delivery. Cache/CDN, storage offload, transcoding, and adaptive streaming are later capacity decisions unless required by measured pilot demand. Do not claim all shared hosts, proxies, codecs, or mobile devices are certified.

## 10. Joint acceptance and delivery gates

The following cases require retained, privacy-safe evidence tied to exact VP3 and Stoneward commits. Source review, fixtures, actual HTTP/database execution, staging deployment, and physical-device results are separate evidence scopes. Existing test names or planning statements do not establish a passing release or a 10/10 score.

| Case | Required result | Evidence scope |
| --- | --- | --- |
| ST-01 | Workspace A cannot select/import B's private catalog; supplied IDs and artist credentials never elevate a visitor | Negative API/database authorization |
| ST-02 | Import track plus overlapping album, retry, reconnect, and refresh: stable local IDs, one mapping, correct sequence and overrides | Both applications plus persisted receipts |
| ST-03 | Anonymous cookie-independent public playback works; private draft, private artwork, owner identity, secrets, and raw paths are absent | External-origin HTTP/browser |
| ST-04 | Grant/site/domain/workspace revocation blocks new and renewed access within documented delivery limits | Deployed headers/cache and backend state |
| ST-05 | HEAD, suffix/open ranges, invalid ranges, seek, and version validators behave correctly and add no listens by themselves | Actual media bytes and HTTP responses |
| ST-06 | Ticket retry/refresh, URL replacement, reconnect, and network recovery preserve one continuous play and asset | Joint player, provider receipts, counts |
| ST-07 | Same/conflicting/out-of-order events, concurrent requests, terminal retries, and backfill cannot duplicate measurements or future accrual | Transaction/concurrency and replay cases |
| ST-08 | Pause, buffering, speed, seeks, short loops, natural end, preview, and failed autoplay use the declared metric version | Browser observations reconciled to events |
| ST-09 | Opt-out, history clear, account switch, closure, and stale queued events preserve privacy and scoped anonymous totals | Both identity/privacy lifecycles |
| ST-10 | Direct, iframe, custom-player, missing-referrer, and sensitive-path cases retain honest safe source attribution | Browser plus sanitized stored records |
| ST-11 | Tracking outage, entitlement outage, quota breach, and asset replacement produce documented availability/coverage | Joint fault injection and recovery |
| ST-12 | Main/footer/Agent/canvas controls preserve one observed play; independent players stay distinct; raw bytes do not create a listen | Existing Stoneward runtime regression |
| ST-13 | iPhone Safari and Android playback survive supported background, lock-screen, interruption, Bluetooth, and network scenarios; controls remain accessible | Physical devices plus keyboard/screen-reader checks |
| ST-14 | Supported consumer/provider versions interoperate; unsupported versions fail usefully; logs correlate an issue without private leakage | Exact release pair and staging pilot |

ML0 records this proposal. ML1 establishes identity and shared metadata. ML2A hardens native telemetry; ML2B delivers the provider; ML2C integrates Stoneward; ML2D certifies the joint pilot. ML3 builds reporting on accepted aggregates. ML4 adds earnings rules/ledger, ML5 payouts, ML6 Agent operations, and MH1 managed hosting. These are gates, not authorization for all phases at once; see [DELIVERY-PLAN.md](DELIVERY-PLAN.md).

## Source anchors

- [VP3 media reader](https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/includes/music-media.php), [native playback API](https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/api/playback.php), and [hosting headers](https://github.com/davemt74-lang/software/blob/2b69d47b04bb2bcdd74402189b820eef22ed1235/.htaccess).
- [Stoneward native telemetry](https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/assets/js/listening-telemetry.js), [canonical playback/privacy implementation](https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/api/listening-playback-core.php), and [current player URL-change behavior](https://github.com/davemt74-lang/stoneward/blob/253d1267c95ffefbcf3a79173429abbbba0c33fc/assets/js/app.js).

The full evidence and limits, including the unmatched direct-connector routes in the audited snapshots, belong in [SOURCE-AUDIT.md](SOURCE-AUDIT.md).
