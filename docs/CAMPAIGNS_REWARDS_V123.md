# Campaigns & Rewards V1.23 — Journey Publishing, Versioning & Release Management

V1.23 turns the V1.21/V1.22 journey graph into a governed release artifact. The central rule is simple: **editing a journey can no longer mutate the live graph**. Draft node changes are collected into a draft Journey Version and become executable only through an atomic Journey Publish.

## Database migration

V1.23 requires the new idempotent migration in `upgrade-campaigns-rewards-v123.sql`. The normal VP3 upgrade path is still `upgrade.php`, which now calls the same V1.23 schema installer and performs the legacy graph adoption/backfill.

Three canonical tables are added:

- `campaign_journeys` — one logical journey within one Campaign.
- `campaign_journey_versions` — draft, scheduled, published, superseded and rollback release snapshots.
- `campaign_journey_publications` — append-only publish / schedule / rollback audit history.

Node content stays in `campaign_messages`. Execution stays in `campaign_deliveries`. Reward authority stays in `reward_issuances`.

Fresh setup also invokes the V1.23 schema installer after the Campaign V1 platform schema is created.

## Draft and Live separation

Every logical Journey has separate pointers to:

- `current_draft_version_id`
- `current_published_version_id`

Editing a node writes a **new draft campaign_message version** and updates only the current draft graph snapshot. V1.23 deliberately does not supersede or deactivate the message row used by the live release.

Publishing validates the entire graph, then atomically:

1. marks the previous Journey Version superseded,
2. marks the draft Journey Version published,
3. activates the exact message versions referenced by that snapshot,
4. moves the Journey's live pointer,
5. applies the chosen in-flight policy,
6. appends a publication audit record.

Published Journey Versions are never edited in place.

## In-flight version pinning

Every V1.23 journey instance writes these values into canonical delivery metadata:

- `journey_id`
- `journey_version_id`
- `journey_version_no`
- `journey_instance_key`

All next-step resolution uses that pinned immutable Journey Version. Publishing V4 therefore does not silently change a contact that entered V3.

New entrants always resolve the Journey's current published version.

## In-flight publication policy

At publish / rollback time the authorized human chooses one of three policies:

- **Continue** — existing instances remain pinned to their current release.
- **Migrate pending** — only unsent pending/retry nodes are remapped to the same logical step in the new release. Publishing fails before mutation if a pending step cannot be mapped safely.
- **Exit remaining** — pending/retry nodes from the previous release are suppressed and marked as publisher-exited.

Already sent historical deliveries are never rewritten.

## Graph validation

A draft cannot publish while structural errors remain. Validation checks:

- exactly one logical entry step per trigger,
- missing target steps,
- decision nodes with both branches,
- wait nodes with a next target,
- duplicate logical variants,
- mixed node types inside one A/B step,
- A/B variant weights totaling 100,
- A/B variants converging on the same route,
- unreachable nodes,
- graph cycles,
- exit-path reachability.

Provider readiness is checked during release preflight. Missing SendGrid/Twilio/PHP-mail configuration is surfaced as a release warning rather than silently changing provider behavior.

## Pre-publish simulation suite

The Release Console can run a structural/provider suite and optionally dry-run the draft against a Merchant CRM contact. Sample simulation evaluates deterministic variant selection, branch conditions, consent, frequency controls and scheduling without:

- inserting Campaign deliveries,
- sending a provider message,
- issuing a Reward,
- changing Campaign or Journey state.

## Scheduled publishing

A human with `campaigns.publish` authority may schedule a validated draft. The scheduled version is frozen and removed from the editable draft pointer. Later edits create a new independent draft from the currently published release.

`cron/campaigns-rewards-v123.php` checks due scheduled versions first, then runs Campaign automation, Reward expiration enqueue, delivery dispatch and V1.22 optimization recommendation refresh.

A scheduled publish executes with the human authorization captured when it was scheduled. It does not grant an Agent or provider callback publish authority.

## Rollback

Rollback never rewinds mutable state. Choosing a historical published/superseded version creates a **new Journey Version** containing that historical graph and publishes the new version as a rollback release. The audit trail therefore remains monotonic and explicit.

## Enrollment and archive

New enrollment can be paused independently from in-flight execution. Pausing enrollment prevents new contacts from entering the Journey but does not alter existing pinned instances.

Archiving preserves all versions, publications, delivery history and analytics. The publisher may allow existing instances to finish or explicitly exit remaining pending nodes.

## Clone Journey

Any release snapshot can be cloned into a new logical Journey. Node rows are copied as new draft `campaign_messages` with a new Journey key, so future edits never share mutable node identity with the source.

## Visual Journey Builder

The Campaign workspace renders the selected draft (or live release when no draft exists) as a node-and-connector graph. Message, wait, decision and exit cards are placed by step order, and connectors render normal, true and false paths. Clicking a node opens its draft editor.

The visual graph is a presentation of the canonical release snapshot; it is not a second graph store.

## Draft / Live comparison and Release Health

The Release Console shows:

- live version,
- draft version,
- node additions / changes / removals,
- validation errors and warnings,
- provider readiness,
- version history and release notes,
- scheduled versions,
- rollback controls,
- enrollment state,
- pinned delivery counts by live version,
- conversion / retry / dead-letter health.

Health is derived from canonical `campaign_deliveries`; no parallel analytics ledger is added.

## Backward compatibility

During upgrade, existing V1.21/V1.22 `journey_node` graphs are adopted into V1.23 Journey records. Public V1.18 and Automation V1.19 bridges call V1.23 only when the V1.23 schema is ready. Otherwise they continue through the V1.21/V1.20 compatibility path.

The V1.23 runtime falls back to V1.22 dispatch for legacy deliveries that do not carry a V1.23 release pin.

## Authority boundaries

- Agents cannot publish, schedule, rollback, archive or migrate Journey releases.
- Provider webhooks still update delivery state only.
- Reward issuance remains governed by the Campaign/Reward V1.19 authority path.
- V1.22 recommendations remain advisory and human-reviewed.
- No second scheduler or delivery queue is added.
