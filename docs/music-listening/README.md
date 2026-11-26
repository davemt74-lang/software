# VP3 music listening and Stoneward streaming

Canonical planning entry point for the VP3 music revival, `/music-workspace.php`, song metadata and rights, hosted streaming, and the standalone Stoneward integration.

**Status:** ML0 planning, ML1 catalog/metadata and ML2A native listening are merged in PRs #925, #930 and #936. ML2B adds the versioned VP3 provider API and Sites & streaming management; [implementation PR #939](https://github.com/davemt74-lang/software/pull/939) owns exact candidate checks, merge and package evidence. See [ML1 implementation and rollout](ML1-IMPLEMENTATION.md), [ML2A implementation](ML2A-IMPLEMENTATION.md), [ML2B provider implementation](ML2B-IMPLEMENTATION.md) and [development status](DEVELOPMENT-STATUS.md) for scope and release limits. Stoneward import, joint live acceptance, listening dashboards, earnings and payments remain separate phases.

## Product direction

- VP3 is the main catalog and audio host for connected recordings.
- Each artist has a standalone Stoneward installation, either self-hosted or operated through a VP3 managed hosting/subdomain package.
- Stoneward adds **Import Music from VP3**: connect the intended artist workspace, select music, and use it through existing site/player capabilities while VP3 hosts the audio.
- Each listen retains its originating site and safe playback-page context, with explicit limits on what is independently verified.
- Main Add Track and Music Workspace use a shared song metadata/rights workflow.
- Workspace analytics, music earnings and payments have separate, clearly defined sources and authority.

These user requirements are distinct from engineering recommendations and unresolved policy choices. The documents mark those distinctions; an unset financial policy never becomes a production rate.

## Read in this order

| Document | Purpose |
|---|---|
| [Master specification](MASTER-SPECIFICATION.md) | User requirements, ownership, user experience, data relationships, lifecycle and scope |
| [Source audit](SOURCE-AUDIT.md) | What the two audited repositories actually contain, reusable modules, gaps and evidence limits |
| [Streaming contract](STREAMING-CONTRACT.md) | Proposed provider/client contract, permissions, source attribution, playback identity and joint acceptance |
| [Rights and earnings](RIGHTS-AND-EARNINGS.md) | Metadata, readiness, rights/financial distinctions, accounting requirements and financial decision register |
| [Delivery plan](DELIVERY-PLAN.md) | Ordered phases, ML1 engineering handoff, dependencies, release criteria and nonfinancial decisions |
| [ML1 implementation](ML1-IMPLEMENTATION.md) | Implemented metadata/API contract, migration, recovery and acceptance evidence |
| [ML2A implementation](ML2A-IMPLEMENTATION.md) | Native receipt protocol, timing/identity/source boundaries, migration and acceptance scope |
| [ML2B implementation](ML2B-IMPLEMENTATION.md) | Site verification, selected public catalog grants, visitor sessions, pinned streaming, provider API and rollout |
| [Development status](DEVELOPMENT-STATUS.md) | Current completion state, test evidence, pending verification and next action |

## First complete integration milestone

One authorized recording is saved with metadata in VP3, imported into Stoneward without duplication, played through the existing main player and supported Agent path, renewed within the same playback session, reported once with site/page attribution, and made unavailable correctly after revocation. Preserve local playlists, fan privacy, purchased-content authority and personal listening history.

This milestone spans ML1 and ML2A–ML2D. It does not require inventing a royalty rate or automatically activating earnings. ML3 provides the complete workspace reporting interface; ML4 and ML5 add funded earnings and payments after their specific policies are decided.

## Working rules

1. Recheck relevant source and open changes before each implementation phase. Audit snapshots are evidence, not a claim about the current deployment.
2. Reuse existing catalog writers, player controls, permission services, event receipts, commerce and operational tools through stable interfaces. Avoid competing players, ledgers or identity systems.
3. Preserve the distinction between user-owned listening history and workspace-scoped artist reporting.
4. Mark proposed contracts as proposed until shipped. Record actual compatible VP3 and Stoneward commits when joint behavior is verified.
5. Give a phase a completion score only against named criteria with supporting evidence. Keep live-provider, deployed-host and physical-device acceptance explicit.
6. Keep these documents current as implementation changes their assumptions. Record a decision once in its owning document and link to it elsewhere.

This package supplies project context. It does not expand the active task's authorization or supersede user instructions or existing repository guidance.
