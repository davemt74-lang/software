# Campaigns & Rewards V1.20 — Campaign Messaging & Journeys

V1.20 turns the V1.19 automation engine into a communication journey layer without creating a second Campaign, CRM, scheduler, human-message store, Wallet, or Agent authority.

## Canonical authority

V1.20 intentionally reuses tables that already existed in the V1 database map:

- `campaign_messages` — versioned message/journey step definitions.
- `campaign_deliveries` — durable scheduled delivery records and delivery state.
- `campaign_idempotency_keys` — enqueue deduplication.
- Core CRM identity, Merchant relationships and CRM contact preferences — recipient identity and consent.
- V1.19 automation — Campaign trigger eligibility and Reward issuance.
- `reward_issuances` — Reward/expiration state.
- `campaign_activity_events` — audit and Cognitive Runtime ingress.

No V1.20 database migration is required after the V1.18/V1.19 schema is installed.

## Journey model

A journey is a set of active `campaign_messages` sharing a `journey_key`. Each latest message version stores its step definition in `template_json`:

- journey key and step key
- step order
- trigger event
- delay in minutes
- delivery channel
- marketing or transactional purpose
- stop-on-claim / stop-on-expiration controls
- reward-expiration lead days

Editing a step creates a new immutable message version and supersedes the older definition. Existing deliveries continue to point at the exact version that was queued.

V1.20 supports newsletter signup, Birthday/VIP, Win Back, post-purchase, referral, winner, attendance, proof, loyalty, product availability, community allocation, approved Agent action, Reward issuance, Reward expiration and manual journey triggers.

## Durable scheduling

All steps are queued into `campaign_deliveries` immediately with a UTC `scheduled_for` value inside delivery metadata. The CLI runner evaluates due V1.19 automation, queues Reward-expiration reminders, and dispatches due deliveries:

`cron/campaigns-rewards-v120.php`

This runner supersedes the V1.19 Campaign cron entry when V1.20 is deployed. It remains CLI-only and does not introduce a daemon or second scheduler.

## Channels

Email is provider-neutral at the journey layer. VP3 includes a conservative PHP `mail()` sender that is disabled unless `site.send_campaign_email` is enabled and a valid `site.campaign_email_from` address is configured.

SMS is adapter-based. V1.20 does not hard-code a vendor or API credential into Campaigns. A provider integration registers a sender with `campaigns_rewards_register_message_sender_v120('sms', ...)`.

The Agent channel delivers a VP3 notification to an existing VP3 user linked to the CRM contact. It is a presentation/delivery channel only; it does not grant an Agent permission to activate Campaigns, journeys, or Reward issuance.

## Consent and suppression

Marketing delivery is checked both when a journey is queued and immediately before it sends. The canonical CRM marketing status and channel preferences are authoritative. SMS marketing requires explicit SMS opt-in. Transactional messages honor `transactional_allowed`.

Due steps are also suppressed when the Campaign or message is no longer active. Reward-linked steps can stop after a Reward is claimed, voided or expired.

## V1.19 bridge

After V1.19 successfully issues a Reward for an automation rule, it may enqueue V1.20 steps matching that rule's trigger. This preserves the authority chain:

verified/scheduled event → V1.19 rule eligibility → published Campaign + attached Reward + inventory/budget/contact limits → canonical Reward issuance → V1.20 communication journey.

Messaging never bypasses Reward issuance authority.

## Expiration reminders

The V1.20 due runner scans live unclaimed Reward issuances inside the configured look-ahead window. Active `reward_expiring` steps calculate their schedule from the Reward's canonical `expires_at` timestamp and their lead-days setting. Enqueue idempotency prevents duplicate reminder records.

## Message performance and attribution

Campaign analytics can report queued, sent, delivered, viewed, failed, suppressed, provider-unconfigured and claim-attributed deliveries by channel. Provider adapters may record delivered/viewed events through the V1.20 delivery event function.

When a Reward Claim completes, V1.20 can attribute the claim to the most recent eligible sent/delivered/viewed Campaign delivery for the same Campaign/contact/Reward and emits `campaign.message_converted`.

## Governance

Journey activation requires Campaign edit + publish authority. The Campaign itself must already be active and published. Agent recommendations may propose messaging changes, but no Agent receives autonomous activation authority in V1.20.
