# Campaigns & Rewards V1.19 — Automation, Triggers & Lifecycle Intelligence

V1.19 activates the durable automation authorities already present in the Campaigns & Rewards V1 schema. It does not create a second scheduler, CRM, Wallet, payment system or Agent Brain.

## Execution authority

Canonical tables:

- `campaign_automation_rules` — merchant/campaign scoped trigger, audience and action configuration.
- `campaign_rule_executions` — durable idempotent execution history.
- `campaign_agent_recommendations` — human-review lifecycle recommendations.
- `reward_issuances` — certificate authority.
- `campaign_activity_events` — Campaign audit/cognitive event stream.

Reward issuance still passes through the existing V1 engine, so published Campaign Version, attached Reward, per-contact limits, Campaign budget, inventory, expiration, idempotency and Merchant lifecycle rules remain authoritative.

## Trigger families

Scheduled through the existing CLI cron pattern:

- Birthday window
- Win-back inactivity

Event-driven:

- Purchase completed
- Referral qualified
- Contest winner selected
- Attendance confirmed
- Social / UGC / Training proof approved
- Loyalty milestone
- Product / offer available
- Community allocation approved

Governed manual:

- Approved Agent action
- Manual trigger

The CLI entry point is `cron/campaigns-rewards-v119.php`. It evaluates due birthday and win-back rules and refreshes lifecycle recommendations. It is CLI-only and does not introduce its own daemon or scheduler.

## Event safety

If an event identifies a CRM contact, the automation evaluates only that event contact. The configured audience or CRM segment becomes an eligibility filter; a purchase event cannot accidentally batch-issue to all customers.

Broad audience evaluation is reserved for triggers that intentionally have no event contact, such as birthday, win-back or a broad product-available event.

## CRM audiences

V1.19 reuses canonical CRM:

- All Merchant contacts
- Prospects
- Customers
- Inactive customers
- VIP / loyalty contacts
- Birthday window
- Saved CRM segment
- Unclaimed Reward holders
- Previous Campaign participants
- Event contact

No Campaign-specific duplicate customer or segment database is created.

## Referral lifecycle

Referral landing pages preserve the referral reference. V1.19 resolves a referral reference to a canonical Merchant CRM contact when the reference is a CRM public ID or email and records `referrer_contact_id` on the enrollment.

A later `referral_qualified` trigger can issue to:

- referred participant
- referrer
- both

All recipients must still satisfy the configured audience rule.

## Commerce and Loyalty bridges

A fully paid canonical VP3 Commerce order emits the `purchase_completed` trigger into V1.19 using the order owner, payer email, order ID, paid amount and currency.

Canonical Loyalty positive adjustments emit `loyalty_milestone` with contact, balance and points delta. Rules may require a minimum balance.

These bridges are additive and do not move Commerce or Loyalty authority into Campaigns.

## Rule configuration

Each automation supports:

- Campaign
- trigger
- CRM audience
- optional saved CRM segment
- attached Reward Product
- referral recipient mode
- cooldown days
- inactivity days
- birthday window
- minimum purchase amount
- minimum loyalty points
- maximum actions per evaluation
- optional marketing-subscribed-only filter
- Draft / Active / Paused lifecycle

Activating a rule requires:

- `campaigns.publish`
- `rewards.issue`
- active Campaign
- attached active Reward

The Agent may propose an automation or lifecycle recommendation but V1.19 does not allow the Agent to activate a rule autonomously.

## Idempotency and retry

Rule execution idempotency is keyed by rule + recipient + trigger event identity.

- completed/running duplicate events do not reissue
- concurrent inserts are protected by the existing unique execution key
- failed executions may retry safely
- cooldown suppresses otherwise valid repeated events

## Funnel intelligence

Each Campaign now exposes:

Viewed → Participated → Qualified → Reward Issued → Reward Viewed → Sent/Regifted → Claimed

The workspace also shows:

- participation rate
- issuance rate
- claim rate
- outstanding unclaimed Reward liability
- tracked Reward inventory remaining

## Lifecycle intelligence

Deterministic Campaign insights identify conditions such as:

- landing traffic with weak participation
- low claim rate after meaningful issuance volume
- low tracked inventory
- outstanding Reward liability
- active Campaign with no automation configured

Due runs persist these as `campaign_agent_recommendations` with `requires_human_decision=true`. Recommendations are informational/actionable evidence, not permission to mutate Campaign configuration.

## Merchant verification queue

V1.18's fulfillment queue remains available for direct manual fulfillment. V1.19 additionally lets authorized Merchant operators verify a lifecycle event such as winner selection, attendance confirmation, proof approval, referral qualification or allocation approval and pass it into matching active automation rules.

This preserves a clear distinction between:

- verifying that the business condition happened
- Campaign automation deciding whether the rule matches
- Reward Issuance enforcing the actual certificate authority
