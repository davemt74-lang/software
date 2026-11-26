# VP3 music listening — master specification

## 1. Status and intended outcome

This is a proposed development specification assembled from David Evans's requirements and source audits of VP3 and Stoneward. It is a planning baseline, not a shipped API or an assertion that a live deployment has passed acceptance. [Development status](DEVELOPMENT-STATUS.md) owns completion claims; [source audit](SOURCE-AUDIT.md) owns evidence about existing code.

Restore music listening as a first-class VP3 capability. An artist should be able to manage recordings, describe their creators and rights, publish authorized music, connect an independently hosted artist site, understand where listening occurs, and eventually reconcile funded music earnings and payments. `/music-workspace.php` becomes the artist's operating screen for this work.

The first complete integration milestone is deliberately bounded: one recording, one artist workspace, one registered Stoneward installation, ordinary and supported Agent playback, stream renewal, one correlated listening record, source attribution and withdrawal. Multiple songs/sites use the same reusable contracts.

## 2. Requirements established by the user

| ID | Requirement |
|---|---|
| U-01 | Audit and revive VP3 audio/music listening, including overall analytics, artist statistics, royalty-rate capability and payment tracking. |
| U-02 | Make `/music-workspace.php` the main artist workspace for these capabilities. |
| U-03 | VP3 is the main catalog and provides a song hosting/streaming API. |
| U-04 | An artist may host their own website while streaming and tracking plays through their VP3 account. |
| U-05 | Each Stoneward script is a standalone artist website. Support self-hosted purchased installations and a VP3 subdomain/managed hosting package. |
| U-06 | Stoneward adds **Import Music from VP3**: connect the artist's account/workspace and choose which music to use. |
| U-07 | Track the URL generating each playback request/listen, with a stable relationship to the artist site. |
| U-08 | Add a metadata form to main Add Song/Add Track and/or Music Workspace, attached to each uploaded song. Include songwriter/publisher, performing-rights, record-company and recording-identifier information. Use the correct identifier name **ISRC**. |
| U-09 | Continue outlining and auditing before application implementation. This package records that planning work. |

Engineering recommendations below make those requirements concrete. They are reviewable design decisions. Numerical royalty rates, funded eligibility, payout arrangements, commercial hosting allowances and launch policies remain explicitly unresolved where listed in the decision registers.

## 3. Product and account model

### 3.1 VP3 catalog and standalone artist sites

VP3 owns canonical records and audio for recordings hosted by this service. Stoneward remains a standalone artist application with its own presentation, native player, Agent capabilities, local accounts, customer entitlements and operational state. The integration adds a trusted music provider and import mapping to those existing capabilities.

Public authorized listening should work without requiring a VP3 visitor account or reliance on third-party session cookies. Artist management credentials remain server-side. A visitor receives only the permitted playback capability for the requested recording; the artist owner's privileged session must never be used as a substitute for visitor authorization.

An artist connection selects a workspace, not merely an email address. One person may administer multiple artist workspaces. A musician, manager, credited contributor, rights holder and payee can be different people or organizations. Changing staff membership must not change the recording's identity or rewrite historical obligations.

The artist publishing connection, the existing VP3 listener-to-Stoneward connector and Stoneward's fan-to-HomeServer purchase access are separate relationships. Their credentials and scopes are not interchangeable. The managed hosting offer must not require an artist to operate a personal HomeServer.

### 3.2 Authority map

| Record or action | Authority for VP3-connected recordings |
|---|---|
| Canonical recording, source metadata, hosted asset/version | VP3 artist workspace and its authorized catalog service |
| Publication and allowed uses | Explicit applicable grants and publication policy in VP3 |
| Registered site, catalog selection and revocation | VP3 connection authority, with Stoneward's local configuration and mapping |
| Local track ID, presentation order, featured placement and editorial introduction | Stoneward |
| Public credits imported from the canonical source | Approved public projection from VP3, mapped into Stoneward |
| Private Stoneward fan history and personalization | Stoneward under the fan's current account/privacy choices |
| Existing Stoneward orders, purchase receipts and owned-music access | Existing Stoneward commerce/entitlement services |
| Provider usage and future streaming accrual | VP3's validated provider evidence and approved financial policy |
| VP3 user's personal listening history/favorites | The individual VP3 account; artist reporting does not change ownership |

Locally hosted Stoneward recordings continue to use their existing authority until an explicit migration changes hosting. A connection does not automatically upload their files, transfer their private rights records, or transfer customer identities.

### 3.3 Three commercial entitlements

Represent the Stoneward software license, managed website hosting and VP3 audio service separately even when sold as a bundle. Define cancellation, plan changes, exports, grace periods, limits and restoration behavior before commercial launch. A hosting invoice, a customer music purchase, accrued streaming earnings and a payout are distinct transactions. Any permitted offset must be explicit and traceable.

## 4. Music records and identity

These are logical entities; final physical schema names and compatibility migrations are selected in ML1 after checking current database conventions.

| Entity | Responsibility |
|---|---|
| Party | Stable person/company identity; registered name, public credit name, identifiers and relevant affiliations. May exist without a login. |
| Composition/work | Written work and its creator/publisher relationships. Supports multiple recordings; allow future more complex work relationships without identifying a work by an MP3 path. |
| Recording | A particular recorded performance/version, canonical VP3 identity, linked works, performers and recording identifiers. |
| Release and release-track placement | Album/single/EP presentation, release identifiers, artwork, dates and ordered recording references. A recording may appear on more than one release. |
| Audio asset/version | Immutable delivered bytes, checksum, technical properties, processing status and original/streaming rendition relationship. |
| Rights declaration/use grant | Asserted authority and permitted use, source evidence, scope, restrictions and effective period. |
| Earnings agreement/payee allocation | Versioned economic arrangement. Attribution credits are not an agreement. |
| Site installation and connection | Stable installation identity, registered origins, hosting mode, selected workspace/catalog, credentials and connection revision. |
| Imported track mapping | Stoneward local ID linked to VP3 recording and approved public metadata; private synchronization state stored outside the public catalog. |
| Playback evidence and attribution | Correlated session/event identities, asset version, measured behavior, source context and evidence quality. |
| Aggregate/report | Reproducible authorized projection of evidence with metric version, period, freshness and coverage. |

Preserve existing real Player track IDs and their history references. The current workspace catalog's backing-track mechanism must be reused or explicitly migrated. Do not expose implementation aliases as the external API's only permanent identity. Do not match ownership solely through display name, title or first use of an ISRC.

ISRC identifies a recording; ISWC identifies a work. Identifier syntax, registry matching and ownership confirmation are different states. A filename, signed URL, album placement or title change does not automatically create a new recording. File correction versus a distinct recording/version requires an explicit user-visible choice and history preservation.

An active playback is pinned to its asset version. Replacement affects new sessions according to publication policy; active sessions must not splice bytes from different files. Retain old bytes for the documented active-session/rollback window and preserve historical references thereafter. Retention durations remain an operational decision, not an implicit promise to store every file forever.

## 5. Shared upload and metadata experience

### 5.1 Entry points

Use one canonical save service and one shared editor contract for main Player/Chat Add Track, Music Workspace add/edit, and the artist administration flow. Existing song editing must support the same metadata. A generic audio media upload remains a reusable media asset until an explicit **Add to Music Catalog** operation creates a recording.

The source audit identifies the current main Add Track direct insert as a separate write path that must be reconciled. ML1 must establish the selected workspace, current role and revision before saving; adding fields only to `music-library.php` would leave the main creation flow inconsistent.

Save the recording, related metadata and required backing identities atomically, with stale-revision handling. Retry a successfully completed operation without creating another track. Keep files private until the appropriate publication flow enables them.

### 5.2 Form sections

The proposed artist-facing sections are **Song Details**, **Writers & Publishers**, **Recording & Label**, and **Rights & Documents**. See [rights and earnings](RIGHTS-AND-EARNINGS.md) for field meanings and share bases.

Support repeatable writers, publishers, performers, rights owners and credits; optional identifiers and affiliations; explicit unknown/not assigned/self-published values; reusable party profiles and templates; and public-credit preview. Templates can carry recurring defaults but must not silently reuse a recording's unique ISRC, invent a registration or assert that every song has identical splits.

For batch entry, stage changes with a preview, row-level errors and conflict handling. Album defaults should remain understandable and overridable. Extracted audio tags and Agent suggestions retain their provenance and need confirmation where relevant; model output must not establish ownership or payment instructions.

Keep private contacts, agreements, business notes, disputed shares, payee destinations and connection credentials separate from public catalog output. Public serialization uses an explicit allowlist. Merely placing a private field beside public track metadata is unsafe because Stoneward's current public serializer retains most track fields.

### 5.3 Independent readiness

| State | Derived meaning |
|---|---|
| Catalog ready | Identity and organizational information meet catalog requirements. |
| Playback ready | A valid rendition exists and the requested audience/use/site may access it. |
| Earnings ready | Funding, eligibility and applicable economic arrangements are configured. |
| Payout ready | The payee and payment destination satisfy the selected settlement requirements. |

These are computed results with reasons, not switches that bypass requirements. Incomplete business information can remain a draft. Identify exactly which missing information blocks a specific operation. Money setup is not a prerequisite for organizing a catalog, and authorized promotional playback can have its own configured policy.

## 6. Publication and lifecycle

Separate draft/private intake, processing, readiness, published availability, hiding/withdrawal, archiving and permanent deletion. Reuse Stoneward's recoverable lifecycle semantics where appropriate; reconcile VP3's existing delete behavior before a migration can remove historical identity or active media.

Per-use controls distinguish public streaming, approved-site streaming, private review, previews and any later supported download, paid-access or manufacturing capability. A public stream grant does not supply a private master or activate Stoneward's download/vinyl offers.

Define the maximum validity of existing grants after an artist unpublishes, withdraws a selected track or revokes an installation. Stop new grants promptly. Return an understandable unavailable state to connected sites and queues. Preserve historical listening, rights/financial snapshots and the original source-page meaning.

A later scheduling feature must define timezone and exact availability time. Until implemented, release dates used for description must not imply that scheduled access enforcement exists. Territory/date restrictions can only be advertised as enforced when the relevant request path actually evaluates them.

## 7. Import Music from VP3

The Stoneward experience should:

1. Connect an authorized VP3 account and explicitly select the intended artist workspace.
2. Browse authorized recordings/releases and preview the approved public metadata.
3. Show selected, already imported, draft/unavailable and changed states clearly.
4. Select a whole album or individual recordings. Default to the selected snapshot; importing future additions requires an explicit supported setting.
5. Import canonical references and public metadata/artwork while leaving hosted audio in VP3.
6. Retain stable Stoneward local IDs and map them privately to VP3 identity.
7. Refresh repeat imports without duplication or overwriting local presentation choices.
8. Surface unavailable/revoked sources and synchronization conflicts with a direct recovery action.

One-time migration of existing local Stoneward music to VP3 is a separate reviewed workflow. Preserve track pages, playlists, favorites, purchases and historical references. Stage candidate matches and rights/file transfer choices; do not silently turn import into bidirectional synchronization.

A trusted source adapter supports the existing player and authorized Agent/canvas capabilities. The current remote-source rejection and literal-URL playback identity checks require explicit adaptation. Define each consumer's supported capability; operations such as raw-byte analysis, transcription or private-master download require their own authorization and implementation.

## 8. Listening and source reporting

[Streaming contract](STREAMING-CONTRACT.md) owns event semantics. Keep playback attempts, actual starts, measured listening, qualified engagement, accepted financial eligibility and delivered bandwidth as distinct metrics.

Classify public visitor playback, private/editor review, artist testing, staging installs, promotional excerpts and any future venue/jukebox mode. A queue request, approved phone, preload, audio fetch, byte-range request, buffering period or token renewal is not itself another listen. Backgrounding is not proof that audio stopped.

Record the verified installation/origin separately from the player-reported safe initiating page URL, acquisition/referrer context, placement and delivery URL. Preserve the initiating page for one session; navigation can optionally add event context. Unknown page context stays unknown. Remove unapproved query values/fragments and sensitive path data. A source label is not independent proof of human listening or financial eligibility.

Use one correlated event/session identity across Stoneward and VP3. Their local reports may describe the same event; aggregation must not add them as two separate plays. Preserve privacy preferences and personal account ownership. Do not copy a Stoneward customer's private identity/history into VP3 because the artist connected their catalog.

Metric definitions require versions. Historical native 10-second qualification and accumulated 80-percent completion remain labeled historical behavior until a new definition is implemented and validated. Distinguish elapsed listening, content coverage and furthest position. Define repeats, loops, playback rates, partial sessions, concurrency, period boundaries and late events before their metrics are used financially.

Show period/timezone, data freshness, legacy versus measured coverage, partial failures and zero versus unavailable data. Anonymous uniques are estimates under the documented identity scope; distinct counts cannot be summed across days/sites and presented as global people. Preserve privacy-safe aggregates without exposing fan identities or individual private listening activity to ordinary artist managers.

## 9. Music Workspace interface and Agent integration

Proposed top-level areas: **Overview · Listening · Catalog · Sites & API · Earnings · Payments**. Preserve music production/stems, releases, team and personal Player navigation without counting production review as public audience activity.

Overview should show meaningful period-scoped metrics, readiness and an actionable Needs attention list. Listening should drill into recording, release, site, safe page and placement with clear definitions and coverage. Sites & API should show connected installation/workspace, selected catalog, connection health, service usage and revoke/reconnect actions. Earnings and Payments should show configuration/pending states honestly until their phases are implemented.

Use responsive page layouts with clear sections and no nested scrolling containers for the main workflow. Expose accessible labels, keyboard controls and understandable loading/error states. Test actual phone playback and controls; a desktop viewport emulation does not establish physical-device media behavior.

Reuse existing Agent/Top of Mind services and authorization. High-priority actionable findings should be announced in Agent Chat; ordinary findings belong in the appropriate feed, with reasonable reminder spacing and deduplication. Link each insight to actual authorized data and its reporting period. The Agent may propose metadata corrections or explain unresolved readiness; it must not invent rights, rates, registrations, financial entitlement or paid outcomes.

## 10. Service operation and recovery

Separate management/API processing, visitor authorization, media delivery and telemetry ingestion so they can have appropriate caching, failure behavior and capacity controls. Preserve GET/HEAD/range behavior and scope cross-origin media policies to authorized endpoints. Short-lived tickets need renewal within the same playback identity.

Meter storage, processing and delivered bytes independently of qualified listening. Define budget warnings, concurrency/rate limits, overage or new-session cutoff behavior, and treatment of already authorized sessions. Select supported self-hosted and managed runtime profiles based on actual capabilities; do not assume FFmpeg, a persistent worker or a particular database is available on an artist's shared host.

Provide a support reference linking installation, imported record, canonical recording/asset, authorization attempt, event evidence and financial lineage when applicable. Artist-facing diagnostics supply a safe reason and action; private server logs retain only the necessary protected details.

Backups must capture a recoverable set of database, catalog/release files, order evidence, media/references, configuration and identity mappings. Reconcile current grant revocations and external payment outcomes after restore. Restoring a backup must not reissue a payment or duplicate a listening-derived action. An intentional migration/restore preserves the appropriate identity after revalidation; a new staging or production clone receives a separate identity.

## 11. Scope and dependencies

ML1 and ML2 build the foundational catalog-to-playback flow. ML3 builds artist reporting over the authorized evidence. ML4 requires explicit financial policy before real accrual; ML5 requires settlement decisions and provider reconciliation before payouts. ML6 closes Agent and operational release acceptance. MH1 creates the actual VP3-managed Stoneward offering alongside the shared integration contract.

Advanced scheduling, richer territory administration, adaptive streaming, offline listening, external distributor/collecting-system exchange and fan conversion attribution can follow concrete requirements. Preserve extension points now without presenting those features as part of the first milestone.

Use [delivery plan](DELIVERY-PLAN.md) for exact phase order, dependencies, acceptance and the implementation handoff. Open decisions are owned there or in the financial register in [rights and earnings](RIGHTS-AND-EARNINGS.md).
