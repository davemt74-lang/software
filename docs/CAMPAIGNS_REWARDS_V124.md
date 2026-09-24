# Campaigns & Rewards V1.24 — Journey Operations, Live Monitoring & Recovery

V1.24 promotes each pinned Journey execution into a first-class operational record. Journey releases remain governed by V1.23; deliveries remain the node-execution ledger. V1.24 adds one canonical runtime object, `campaign_journey_instances`, so operators can monitor and recover a customer's Journey without reconstructing state from unrelated delivery rows.

This release also includes the transcription Analyze fix requested alongside V1.24: manual transcription intelligence can now use the saved raw transcript as primary evidence when a page-analysis cache is missing.

## Database migration

V1.24 requires the idempotent `upgrade-campaigns-rewards-v124.sql` migration. Normal deployment uses `upgrade.php`; fresh installs use the same V1.24 schema installer.

One canonical table is added:

- `campaign_journey_instances`

The instance row stores the Campaign, logical Journey, pinned Journey Version, CRM contact, deterministic instance key, trigger, current operational status and current logical step.

No second delivery queue, event ledger, CRM, Reward ledger or scheduler is introduced.

## Existing V1.23 instance adoption

The V1.24 schema installer scans existing V1.23 delivery metadata and creates one instance row per existing `journey_instance_key`. Older V1.23 deliveries do not need to be rewritten. Operations membership accepts either the new `journey_instance_id` metadata or the pre-V1.24 deterministic `journey_instance_key`.

New V1.23-compatible deliveries automatically receive `journey_instance_id` after the V1.24 schema is ready.

## Instance lifecycle

Canonical statuses include:

- `active`
- `paused`
- `needs_attention`
- `completed`
- `cancelled`

A dead-letter delivery marks the instance `needs_attention`. A normal terminating Exit or policy-based Journey exit marks it completed. Operator cancellation marks it cancelled and suppresses only remaining pending/retry work; already-sent delivery history is immutable.

## Human operations

A user with Campaign publish authority may:

- pause an instance
- resume a paused instance
- cancel remaining work
- retry a failed/dead-letter delivery
- skip a pending node only when it has an unambiguous next step
- explicitly move an active/paused/attention-required instance to a compatible logical step in its already-pinned Journey Version

These actions write Campaign cognitive/audit events. Agent recommendations cannot execute them.

### Move step

Move Step never changes the pinned Journey Version. Existing pending/retry/dead-letter work for that instance is suppressed as operator-replaced, the requested target is validated against the pinned immutable graph, deterministic variant selection runs for that target, and one new canonical delivery is enqueued.

## Emergency Journey stop

An authorized human can immediately pause new enrollment for an entire Journey and independently choose what happens to in-flight instances:

- continue
- pause
- cancel remaining work

This provides an operational stop without rewriting historical deliveries or releases.

## Contact Journey Timeline

The Operations workspace can open a specific instance timeline. The timeline is reconstructed from canonical `campaign_deliveries` that belong to the instance and shows:

- step
- node type
- channel
- scheduled time
- sent/delivered/viewed/failure state
- branch outcome
- retry/failure error
- operator skip marker

The timeline is read-only.

## Controlled rollout of new Journey releases

V1.24 extends V1.23 publication metadata with `rollout_percent`.

At publish or scheduled publish time, an authorized human may send 1–100% of **new entrants** to the new release. The remainder deterministically enter the immediately previous release. Selection is based on Journey, contact and triggering event so it is stable and auditable.

Important boundaries:

- existing instances remain pinned
- rollout never migrates an in-flight instance
- the separate V1.23 in-flight policy remains the only mechanism for explicit in-flight migration
- first publication is always 100% because there is no previous release
- rollback defaults to 100%
- the rollout value is recorded on the publication audit row

## Operational incidents and SLAs

V1.24 derives incidents from canonical state instead of storing a parallel incident ledger. Current signals include:

- instance in `needs_attention`
- active instance with no activity for 24 hours
- paused instance older than 72 hours
- due pending delivery older than 2 hours
- retry wait unresolved for more than 6 hours
- five or more dead-letter Campaign deliveries within 24 hours

The Agent may create a deduplicated `campaign_agent_recommendations` proposal containing the incident evidence. The recommendation has `auto_apply=false` and cannot pause, cancel, retry, skip or move an instance.

## Release operations comparison

The Journey Operations workspace displays descriptive counts by pinned Journey Version, including total, active, paused, completed, cancelled and attention-required instances.

This is an operational comparison only. V1.24 does not automatically declare a winning release or alter rollout percentages.

## Scheduler

Replace the V1.23 Campaign cron entry with:

`cron/campaigns-rewards-v124.php`

The V1.24 runner keeps one Campaign scheduler and runs:

1. V1.23 due scheduled publication
2. V1.19 Campaign automation
3. Reward-expiration Journey entry
4. V1.24 instance-aware delivery dispatch
5. V1.22 optimization recommendations
6. V1.24 operations incident/recommendation refresh

## Transcription Analyze fix

Previously, manual Analyze in the transcription workspace could fail with:

> There is not enough saved transcript analysis to run the selected plugins yet.

The failure was caused by an intermediate page-analysis cache gate. Even when a long saved transcript was visible and available, selected plugins were not allowed to run unless at least one current page-analysis row had already been persisted.

V1.24 changes manual analysis input behavior:

1. Fresh saved page-analysis results remain preferred.
2. If a page-analysis cache is unavailable, the saved transcript page itself is supplied as `saved_transcript` evidence.
3. The plugin prompt treats cached analysis and saved raw transcript as authoritative transcript evidence.
4. A truly empty transcription still fails explicitly.
5. If page preparation itself failed, the actual preparation error is surfaced instead of the misleading “not enough saved analysis” message.

The fix does not make Agent Brain, Knowledge, CRM, or web research authoritative for what was said in the transcript. The saved transcript remains the primary source.

## Authority boundaries

- Journey publication/rollback authority remains V1.23.
- Journey instance controls require human Campaign publish authority.
- Agents may surface incidents and recommendations only.
- Provider callbacks cannot operate Journey Instances.
- Already-sent delivery history is immutable.
- Reward issuance authority is unchanged.
- CRM contact authority is unchanged.
- No second scheduler, delivery queue, analytics ledger or event ledger is added.
