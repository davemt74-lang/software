# Campaigns & Rewards V1.25 — Journey Personalization & Offer Decisioning

V1.25 adds deterministic customer-level decisions without introducing a parallel Campaign, CRM, Reward, or Journey engine.

The central architecture is:

**versioned Journey rule → canonical CRM/loyalty/reward context → append-only Decision → existing Journey/Reward execution**

Personalization, holdout, conflict, and offer settings are stored inside each Journey node's existing `template_json`. Because Journey graphs are published through V1.23, V1.25 rules are immutable for the life of a published Journey Version.

## Database migration

V1.25 requires the idempotent `upgrade-campaigns-rewards-v125.sql` migration.

It adds exactly one canonical table:

- `campaign_decisions`

Normal deployment uses `upgrade.php`; fresh setup invokes the same V1.25 schema installer.

## Campaign Decision ledger

`campaign_decisions` is an append-only explanation ledger. It records:

- Campaign / Journey / Journey Version
- Journey Instance / delivery when available
- CRM contact ID
- logical step
- decision type
- deterministic idempotency key
- a hash of the evaluated context
- minimized decision context
- the frozen rules used
- the outcome
- holdout status
- timestamp

The ledger intentionally does **not** duplicate CRM names, email addresses, or arbitrary CRM metadata. CRM remains the customer authority.

Decision rows are never rewritten after creation. Reward issuance is linked through canonical `reward_issuances` and delivery metadata rather than mutating the original decision evidence.

## Decision context

V1.25 can evaluate governed facts from existing authorities, including:

- CRM lifecycle stage
- Merchant customer / loyalty / marketing status
- preferred channel
- days since last purchase
- CRM tags
- CRM segments
- loyalty tier
- loyalty points
- active and claimed Reward counts
- prior Campaign participation
- prior completed Journey instances
- trigger purchase amount / balance / location / referrer
- Journey conversion state
- Campaign state

A contact must already be reachable through that Merchant's canonical CRM relationship before decision context can be built.

## Richer Journey branching

Decision nodes may use V1.25 fields and operators:

- equals / not equals
- greater / greater-or-equal
- less / less-or-equal
- contains / not contains
- in / not in
- truthy / falsy

Branch outcomes are recorded in `campaign_decisions` with the field, operator, expected value, observed value, match result, and selected next step.

Legacy V1.21 condition fields remain supported as a fallback.

## Holdout / control groups

An entry node may define a 0–90% holdout.

Assignment is deterministic for:

- Campaign
- Journey Version
- contact

A contact therefore remains consistently in or out of the control group for that release.

A held-out contact does not create a Journey Instance or delivery. The holdout decision is still recorded so later incrementality analysis can compare intervention vs control.

All A/B variants at one entry step must use identical holdout and conflict settings because entry eligibility is decided before variant selection.

## Campaign conflicts

Entry nodes may declare:

- `conflict_group`
- `conflict_window_hours`
- `decision_priority`

If another Campaign for the same Merchant and contact already has an admitted entry decision in the same conflict group during the configured window, the later candidate is suppressed.

V1.25 deliberately does **not** automatically preempt or cancel an already-admitted Journey. Priority is recorded as decision evidence for review and future orchestration, while the already-admitted conflicting Journey remains authoritative.

## Dynamic Reward / offer selection

Message nodes may select:

- no dynamic offer
- best eligible attached Reward
- a specific attached Reward

Offer selection reads the **current published Campaign Version's frozen Reward snapshot**, not mutable Reward-set rows.

Candidate eligibility then applies:

- active current Reward state
- frozen Campaign Reward membership
- item priority
- item `conditions_json`
- per-contact Reward claim limit
- tracked inventory availability

Equal-priority eligible candidates are selected deterministically from Journey Instance + step + contact.

The Decision records Campaign Version ID/number and the selected Reward evidence.

## Dynamic Reward issuance

A message node may either:

- select/personalize only, or
- issue the selected Reward

Issuance never bypasses the existing Reward authority. V1.25 calls `campaigns_rewards_issue_reward_v100()` with `actor_type=decision`.

That canonical path still enforces:

- active production Campaign
- published Campaign Version
- attached Reward snapshot
- Campaign max Reward limit
- per-contact limit
- Reward claim limit
- tracked inventory reservation
- Campaign budget
- liability ledger
- credential generation
- idempotency

The issue path also verifies that the exact append-only offer Decision authorized that exact Campaign/contact/Reward.

## Dynamic message personalization

When personalization is enabled for a message node, V1.25 exposes minimized decision context as tokens such as:

- `{{contact.lifecycle_stage}}`
- `{{contact.customer_status}}`
- `{{contact.tags}}`
- `{{contact.segments}}`
- `{{loyalty.tier}}`
- `{{loyalty.points}}`
- `{{rewards.active_count}}`

Existing canonical tokens like `{{name}}`, `{{email}}`, `{{campaign_name}}`, and Reward wallet URLs remain available from V1.20.

Offer aliases are available whenever an offer is selected:

- `{{offer_name}}`
- `{{offer_value_minor}}`
- `{{offer_currency}}`

Conditional blocks are supported:

- `{{#if loyalty.tier}}...{{/if}}`
- `{{#unless offer.name}}...{{/unless}}`

CRM/loyalty conditional context is only exposed when personalization is enabled. Offer conditionals remain available for selected offers.

## Release validation

V1.23's publish validator now also runs V1.25 checks.

Publishing is blocked for:

- unsupported decision fields
- dynamic offers on non-message nodes
- missing specific Reward
- specific Reward not attached to the Campaign
- issue action with offer selection disabled
- inconsistent holdout/conflict controls across A/B entry variants

Warnings are surfaced for no-op or misplaced settings such as holdout on a non-entry node or personalization on a non-message node.

## Preview / simulation

The V1.25 Decision Preview is read-only.

For a selected Merchant CRM contact it shows:

- deterministic entry eligibility
- holdout result
- conflict result
- observed V1.25 decision values
- branch matches
- eligible offer selection
- simulated Journey path

It creates no:

- Campaign enrollment
- Journey Instance
- delivery
- Reward issuance
- Decision ledger row

The V1.23 pre-publish simulation also understands V1.25 branch fields and dry-run offer eligibility.

## Contact Journey Timeline

The V1.24 Contact Journey Timeline now includes V1.25 Decision evidence for the exact instance:

- entry decision
- branch decision
- offer decision
- holdout/conflict reason where applicable
- selected offer name
- decision timestamp

The existing delivery timeline remains canonical for execution.

## Agent recommendations

V1.25 reviews recent Decision outcomes and may propose human-review recommendations when, for example:

- many offer decisions find no eligible Reward
- many contacts are conflict-suppressed
- the configured holdout produces an unusually large control population

Recommendations are stored in the existing `campaign_agent_recommendations` authority with `auto_apply=false`.

The Agent cannot modify a live Journey rule, change a holdout, attach a Reward, issue a Reward outside the governed Decision path, or publish a Journey.

## Scheduler

After upgrade, use:

`cron/campaigns-rewards-v125.php`

It preserves the single Campaign scheduler and runs the V1.24 lifecycle/operations runtime plus V1.25 Decision recommendation refresh.

## Authority boundaries

- CRM remains canonical customer authority.
- Journey Version remains canonical rule authority.
- `campaign_decisions` is append-only evidence, not a second CRM or execution queue.
- `campaign_deliveries` remains Journey node execution.
- `reward_issuances` remains Reward issuance authority.
- Published Campaign Version Reward snapshots govern dynamic offer selection and issuance.
- V1.24 instance pause/cancel/recovery controls remain enforced.
- Agent recommendations never auto-apply.
