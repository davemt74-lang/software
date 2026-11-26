# Music listening — delivery plan and engineering handoff

## Delivery approach

This package closes the planning work with a bounded implementation sequence. It does not claim that a provider API, import feature, royalty rate or operated hosting package already exists. See [status](DEVELOPMENT-STATUS.md), [master specification](MASTER-SPECIFICATION.md) and [source audit](SOURCE-AUDIT.md).

First establish the shared catalog and metadata foundation, then prove a single recording through real VP3-to-Stoneward playback. Build the full artist dashboard over the resulting evidence. Add earnings only when their funding and allocation policy is explicit; add payouts after settlement decisions and reconciliation behavior are defined.

The phase IDs below are the canonical sequence for implementation. ML2A/B/C preserve the native/provider/client subdivision discussed during planning; ML2D is the combined acceptance milestone. MH1 is an independent managed-hosting workstream using the same music contract.

## Phase map

| Phase | Primary repository | Deliverable | Entry dependency | Exit evidence |
|---|---|---|---|---|
| **ML0 — Audit and specification** | software; Stoneward inspected | Reviewed product/source/contracts/decision package | User planning requirements | Source-backed scope, recorded unknowns and explicit implementation handoff |
| **ML1 — Catalog and metadata foundation** | software | Shared metadata save/editor, stable identity, private/public projection, readiness and workspace authority | Current source reconciliation | Main Add Track and workspace create/edit the same authorized recording with related metadata and preserved legacy history |
| **ML2A — Native listening** | software | Replay-safe native event handling, clear metric/evidence definitions, coverage classification | ML1 identity mapping | Duplicate/out-of-order/recovery events do not inflate accepted listening; personal history ownership preserved |
| **ML2B — VP3 provider** | software | Authorized catalog reads, selected-site grants, visitor sessions, asset-pinned streams, telemetry and revocation | ML1; agreed ML2A event identity | Provider runtime/API tests with public/private and cross-workspace negative cases, renewal/range/cache behavior |
| **ML2C — Stoneward client** | stoneward | Connect/select/import, private source mapping and adapter for existing music consumers | ML2B contract; Stoneward source reconciliation | Reimport stable; public projection safe; native and supported Agent paths handle provider sources and renewal |
| **ML2D — Combined pilot** | both | One complete recording flow | ML2A–ML2C | Same identified VP3/Stoneward builds pass the joint scenario and actual-host/device checks assigned to this milestone |
| **ML3 — Artist workspace** | software | Overview, Listening, Catalog and Sites & API; honest Earnings/Payments setup states | Stable authorized query/aggregate contract | Workspace-isolated, period-correct reporting including source URLs, coverage and service usage |
| **ML4 — Funded earnings** | software | Versioned eligibility/agreements, precise accrual, adjustments and statements | Explicit decisions F-01 onward relevant to accrual | Reproducible balanced calculations, replay safety, historical version preservation, holds and finalized statement behavior |
| **ML5 — Settlement** | software; scoped site commerce adapter where needed | Payout attempts, receipts, reconciliation and financial reporting | ML4; chosen payout mechanism and policies | Timeout reconciliation, duplicate prevention, refunds/adjustments and provider-evidenced outcomes |
| **ML6 — Agent, operations and release** | both as needed | Artist insights, actionable support, recovery, release compatibility and verified packages | Actual implemented capabilities | Authorized evidence-based Agent actions, recovery rehearsal, full agreed runtime/live release evidence |
| **MH1 — Managed Stoneward hosting** | software plus actual hosting runtime/deployment integration | Provision/update/backup/restore a VP3-operated artist site and subdomain/custom-domain service | Runtime/entitlement/domain decisions; stable provider/client contract | Real managed install and self-hosted equivalent complete the same music journey; migration and cancellation behavior demonstrated |

ML2B and ML2C may proceed in coordinated branches once the contract is stable. Contract fixtures are useful development evidence, but the complete integration requires the actual consumer and provider. ML3 interface work can proceed against approved query shapes; simulated values must never be labeled real audience or money. MH1 preparation can run in parallel after its architecture is selected; the present HomeServer-oriented hosting code is not proof of an operated managed service.

## ML1 engineering handoff

### Objective

An artist can create and edit a recording with structured metadata from the normal Add Track flow and Music Workspace, using the intended workspace's authority and one canonical save service. The record has stable identity, an explicit metadata revision, a safe public projection and understandable readiness. Preserve existing catalog/playback references and personal listening history.

### Read before changing code

1. Root `AGENTS.md` and this package's [README](README.md).
2. [Source audit](SOURCE-AUDIT.md), especially active Add Track, backing-track identity, global report scope and Stoneward mapping.
3. [Rights and earnings](RIGHTS-AND-EARNINGS.md) for field meanings and the distinction between metadata and economic agreements.
4. [Streaming contract](STREAMING-CONTRACT.md) for stable recording/asset identifiers and the future public projection.
5. Current source, migrations and open changes. The audit hashes are pinned historical evidence; implementation uses the current relevant source.

### Confirm the active paths

| Concern | Existing VP3 path to inspect |
|---|---|
| Current workspace landing page | `music-workspace.php` |
| Workspace library and artist management | `music-library.php`, `admin/artist-music.php` |
| Main Add Track modal and handler | `chat-legacy-v108.php`, `chat.js`, `api/chat-create-v76.php`; verify the active wrapper in current `chat.php` |
| Shared transaction/catalog writer | `includes/music-catalog.php` |
| Workspace authority and backing track mapping | `includes/music-workspace-resources-v330.php`, `includes/artist-music-v185.php` |
| Credits and release planning | `admin/credits.php`, `includes/release-agent-v105.php` |
| Generic account media intake | `media-library.php`, `api/media-library-v86.php` |
| History/ownership contracts | `includes/player-v76.php`, `api/playback.php`, `tests/music-workspace-resource-ownership-v330.mjs` |

The source audit links Stoneward's existing metadata templates, rights tables and lifecycle. Use that field mapping to avoid incompatible naming and loss of existing values. Its current one-work-per-track relationship, editable rights records and readiness checks are not already the complete proposed VP3 model.

### Work sequence

1. **Reconcile current source.** Record relevant changes since the audit; coordinate ownership of shared Chat/catalog files with active work. Keep unrelated Gmail, Operator and device changes outside the phase.
2. **Specify the migration.** Identify legacy catalog/backing IDs, ambiguous workspace assignments, existing audio versions and related history. Stage unresolved mappings for review; do not guess ownership from an uploader alone. Make schema/data migration repeatable and review row-count expectations.
3. **Introduce the minimum canonical model.** Represent parties, work/recording relationships, metadata revisions, approved public fields, file/version identity and the required compatibility mapping. Choose physical table names and migrations from current repository conventions. Preserve existing Player IDs.
4. **Extend the shared service.** Validate actor/workspace authority, enforce revision handling, save related metadata atomically and return a stable recording reference. Keep incomplete drafts and unknown identifiers representable. Never interpret a credit or generated suggestion as an economic agreement.
5. **Route all active entry points through it.** Reconcile the main Add Track direct insert with the shared writer, then use the same editor/save contract from workspace and artist management. Keep generic media intake separate until explicitly added to the catalog.
6. **Expose readiness and public preview.** Show actionable missing information without requiring every possible field. Keep private rights/contact/connection data out of public serializers and Agent visitor responses.
7. **Verify compatibility and migration.** Use existing tests plus focused database/API cases proving the risks below. Record actual runtime availability and deployment requirements.

No rate, financial entitlement, payout or registration should be fabricated to make the metadata form appear complete. Applicable publication minima can be decided independently from the money policy. A draft-only foundation can be verified while those launch rules remain open.

### ML1 acceptance

| ID | Observable result |
|---|---|
| M1-01 | Main Add Track and Music Workspace save through the same authority/metadata contract and resolve the same recording identity. |
| M1-02 | An actor cannot create, edit, read private metadata or export another workspace's recording by changing an ID; a role change is rechecked at mutation time. |
| M1-03 | A stale edit returns an understandable conflict; it does not silently overwrite someone else's metadata. A retry does not create a duplicate recording. |
| M1-04 | Related-record failure rolls back the operation; failed uploads/processing do not publish a partial song or expose a private original. |
| M1-05 | Existing catalog/backing track IDs, favorites, playlists, user history, production references and appropriate archived records remain usable after migration. |
| M1-06 | Two recordings may refer to a shared composition without merging their recording identity; release placement/file identity remain separate. |
| M1-07 | Unknown/unassigned identifiers and incomplete metadata save as drafts; syntax validation is visibly different from registry or rights confirmation. |
| M1-08 | Writer/publisher/label defaults are reusable, but unique recording identifiers and economic shares are not silently copied. |
| M1-09 | Public catalog/profile/visitor-Agent projections contain only approved fields; private contacts, agreements, business notes, destinations and credentials do not leak. |
| M1-10 | A metadata change records who/when/provenance/revision, with separate authority for changes that would affect future economic agreements. |
| M1-11 | Main and workspace forms are usable on desktop and phone, with understandable field errors, keyboard access and no nested main-workflow scrolling. |
| M1-12 | The migration is rerunnable, preserves unresolved legacy mappings honestly, and has an evidence-backed recovery procedure. |

Existing useful regression commands are `node tests/music-module-runtime.mjs` and `node tests/music-workspace-resource-ownership-v330.mjs`; run their current versions, then the applicable PHP/database/API tests. A source-text assertion alone does not prove transaction or authorization behavior. Provision an appropriate PHP/database test environment before claiming runtime completion.

## ML2 integration and ML3 reporting gates

The [streaming contract](STREAMING-CONTRACT.md) contains ST acceptance scenarios and proposed operation semantics. In addition, the combined milestone must identify the exact two repository builds and prove:

- Correct artist workspace selection and scoped catalog reads.
- Stable local mapping, repeat import and preservation of local editorial choices.
- Anonymous authorized playback without transferring the artist's privileged session.
- Main player, queue and supported Agent/canvas behavior through the trusted provider.
- One playback across stream renewal/network retries, pinned audio version and safe byte-range behavior.
- Site/page attribution with verification provenance, safe URLs and explicit unknown context.
- One correlated listening event across local/provider views and current fan privacy controls.
- Cross-workspace denial, source withdrawal and site-grant revocation.
- Scoped media headers, HEAD/Range/CORS behavior and actual device interruptions on the intended host.

For ML3, verify authorization in queries, caches, exports and Agent reads. The existing global admin reports cannot simply be embedded into an artist page. Keep a separate platform-reporting permission. Do not assume workspace ownership grants the old global permission.

Compare daily and period aggregates against source evidence. Distinct listeners need a defined identity/time scope; do not sum daily/site unique counts as global people. Label legacy/partial coverage, provisional measurements and unavailable reads. Preserve a user's own history and favorites as account resources; workspace attribution is a reporting relationship.

## Financial and hosting phase boundaries

[Rights and earnings](RIGHTS-AND-EARNINGS.md) owns F-01 onward. Before ML4 activates real accrual, the relevant funding, eligible use, payee/agreement, precision, finality and adjustment policies must be specified. Calculation comparison mode must be labeled and cannot create payable obligations or mutate issued statements. Before ML5, choose payout authority/provider, schedules, holds and reconciliation rules. A timeout is not permission to send money again.

The unavailable original royalty source remains a documented recovery/comparison item. Recovering it is not an indefinite prerequisite for a newly agreed funded policy: a new policy can proceed with its provenance recorded and historical rates left unknown. Do not invent a legacy rate to close the item.

MH1 must prove an operated runtime, installation, database, deployment, update, domain/TLS, backups and recovery. Reuse applicable VP3 hosting/domain/entitlement concepts, but explicitly resolve their current HomeServer execution dependency. Do not sell a managed product based solely on the existence of generic hosting tables. Keep the same provider/client contract across self-hosted and managed modes.

## Nonfinancial decision register

The user requirements in the master specification are established. The items here are proposed engineering/launch decisions to resolve at the affected stage; they do not create a blanket stop on authorized implementation.

| ID | Decision to record | Recommended starting point | Owner / consequence |
|---|---|---|---|
| D-01 | Minimum supported self-hosted and managed runtime profiles | Test explicit PHP/database/extension/outbound-network/job capabilities, including a representative shared host; processing can be provided by VP3 where supported | Engineering/operations; blocks a claim of supported deployment until proved |
| D-02 | Publication minima and supported initial uses | Separate metadata drafts from authorized public streaming and private review; initially expose only uses that are actually enforced | Product with implementation review; blocks the affected public launch operation, not draft metadata work |
| D-03 | Asset/ticket lifetime and emergency withdrawal behavior | Short scoped grants; existing sessions follow a documented bounded policy; keep asset identity stable across renewal | Engineering/product; required before ML2B access/revocation acceptance |
| D-04 | Reporting timezone, threshold versions and completion meaning | Preserve historical 10-second/80-percent definitions as labeled evidence; explicitly define new metrics and distinguish time/coverage/position | Product/analytics; required before final ML2A/ML3 reporting, with F register owning financial period rules |
| D-05 | Raw event, safe URL and old asset retention | Keep enough evidence for declared reporting/recovery; aggregate/archive under a documented retention policy, preserving required financial lineage | Product/operations; required before public service retention claims |
| D-06 | Supported client versions and catalog update transport | Versioned contract, repeatable explicit reimport and clear capability errors; automatic updates/webhooks only when their retry/revocation behavior is implemented | Engineering; required before supporting independently updated installations |
| D-07 | Existing Stoneward catalog migration | Reviewed mappings preserving local IDs, entitlements and public links; file/rights transfers explicit | Product/engineering; required before migrating an existing artist catalog |
| D-08 | Provider capability coverage | Inventory ordinary/Agent/canvas/excerpt/analysis/download consumers; implement trusted supported capabilities with useful unavailable states | Engineering/product; required to state which existing experiences support VP3 imports |
| D-09 | Managed provisioning, restore and site-move mechanics | Stable installation identity on intentional moves, distinct clone identity, controlled credential/domain revalidation | Operations/engineering; required for MH1 release |
| D-10 | Storage/delivery allowances, overage and cancellation terms | Separate software/hosting/audio entitlements with visible usage and predictable new-session/active-session behavior | David/product; required before selling service packages |

Record the selected value, date, rationale, affected version and evidence when closing an item. If new evidence changes an existing decision, retain the prior decision and migration implications. Never treat an unselected value as a silent operational default.

### ML2A compatibility decisions — 2026-11-26

D-04 is partially resolved for the native implementation: `legacy_10s_80pct_v1` explicitly retains the existing 10-second qualified-play and 80%-of-duration accumulated-listening meanings. Position progress is separate; unavailable server-probed duration leaves completion unknown. This preserves compatibility without assigning a financial qualification rule. Artist reporting timezone, unique-listener scope and later provider reporting remain to be selected for their phases.

ML2A also chooses operational bounds of 24 hours per native session, 10,000 accepted event receipts and 30 accepted seconds per event. Client retry/queue limits and cookie initialization are documented in [ML2A implementation](ML2A-IMPLEMENTATION.md). These bounds do not close D-05: no automatic raw-event deletion or retained-audio purge policy has been selected. Native recording/asset attribution is a catalog snapshot with unverified delivery; D-03's provider asset/ticket lifetime remains ML2B work.

## Review, scoring and release workflow

### ML2B initial provider decisions — 2026-11-26

D-02 is resolved for the initial provider operation: explicit selected public, published, unarchived recordings only. Private/paid visitor entitlement and downloads are not advertised. D-03 uses five-minute delivery tickets within a 24-hour session, current authority checks on each request, no-store responses and immutable per-request snapshots up to 64 MiB. Withdrawal stops new requests; an already admitted response and delivered bytes cannot be recalled. Retained audio is not automatically deleted.

D-06 selects `vp3_music_provider_v1`, explicit paginated refresh, exact retry receipts, and manual origin challenge/bootstrap for custom sites. Stoneward automates that setup in ML2C. D-01 is bounded to the tested PHP/MySQL/SQLite profile and public default-443 HTTPS origins, with actual-host acceptance still pending. D-08 presently covers public player/Agent/embed/canvas session descriptors at the provider boundary; actual Stoneward consumers require their ML2C adapters. D-05 raw-event/asset retention and D-10 commercial allowances remain open. The engineering request/transfer caps in [ML2B implementation](ML2B-IMPLEMENTATION.md) are not purchased plan limits or financial policy.

For each phase, maintain a short checklist covering actual behavior, authority, data lifecycle, replay/recovery, compatibility, interface/accessibility, migration and the required deployment evidence. Mark each item **passed**, **failed**, **not run** or **not applicable with reason**. A 10/10 claim applies only to the named scope with its mandatory checks passed and material blockers resolved. An unavailable runtime or unperformed physical-device test remains a gap in any scope that requires it.

Implementation PRs should explain the concrete problem, resulting behavior, source/contract changes, tests and remaining live evidence. Linked VP3/Stoneward PRs must identify compatible revisions. Avoid changing unrelated shared Agent/Chat systems merely to complete a music feature. Use isolated branches/worktrees and coordinate shared-file edits.

Before merge or release, inspect applicable required checks on the actual candidate commit. After an authorized merge, verify the resulting main commit and produce the deployment ZIP from that exact verified source. Separate Cloud and Stoneward packages when their deployments differ; record compatible versions and required migrations. A documentation-only PR does not need an application deployment ZIP.

## Final release evidence

The complete release record should contain the implemented scope, exact commits, schema versions, compatibility pair, migration/restore results, current runtime tests, deployed header/range checks, actual phone playback results, monitoring/support behavior and any explicitly deferred capabilities. Financial release evidence additionally needs reproducible calculations and provider-confirmed reconciliation; managed hosting needs a real operated installation.

Do not convert an attractive dashboard, a historical test note, a simulated transaction or an accepted API request into a claim that music was heard, money was paid or a live product is fully verified.
