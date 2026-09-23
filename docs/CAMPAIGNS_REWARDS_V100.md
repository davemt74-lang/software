# Campaigns & Rewards V1.00

Campaigns & Rewards is a native VP3 plugin built on the v26.00 Cognitive Domain Registry. V1 uses one canonical business model: Merchant → Campaign / immutable Campaign Version → reusable Reward Product → Reward Issuance → Claim. The earlier prototype `campaign_*_v100` persistence model is not installed and is not an authority.

## Authority boundaries

A VP3 user is an identity; a Merchant is a separate business entity. Merchant ownership, lifecycle, Locations, roles and capabilities live in the Campaigns & Rewards domain. A Merchant must retain at least one active Owner.

Core CRM remains authoritative for contact identity. Campaign acquisition resolves one owner-scoped `crm_contacts` record and attaches Merchant-specific customer state through `crm_merchant_relationships`. Campaigns does not create a parallel customer identity table.

Canonical VP3 Team membership remains `workspace_memberships_v350`. Campaigns stores independent Merchant access-source grants. Direct Merchant roles and Merchant Team scope can coexist. Removing a direct Administrator does not restore Administrator authority through Team scope; the remaining Team source projects only the bounded Merchant Team role.

## Campaigns and Rewards

Campaign Types are system-seeded and extensible. V1 includes Signup, Make Good, Loyalty, Promotional Goods, Discount Voucher, Post Purchase, Referral and Win Back. Publishing/activation freezes a Campaign Version snapshot so later edits cannot rewrite the terms governing existing Reward Issuances.

Reward Products are reusable Merchant objects. Campaign Reward Sets attach those products to Campaigns. A Reward Issuance is the customer-facing entitlement and the Reward Wallet is a projection over Reward Issuance + Claim state, not a second mutable wallet ledger.

Public Signup Campaigns resolve Core CRM identity, create/update a Merchant Relationship, create a version-bound Enrollment, and issue an attached Reward. Public issuance is allowed only for active production Campaign Types that explicitly support public signup. Campaign, contact, budget, per-contact and inventory limits are enforced server-side.

## Claim security

Redemption is online-only in V1 and requires all three factors:

1. the customer Reward Credential,
2. a Merchant Claim Code,
3. an authenticated VP3 operator with `claims.process` for that Merchant.

Reward Credentials and Merchant Claim Codes are stored only as SHA-256 hashes plus display-safe last-four metadata. The Reward Wallet can rotate a credential and reveal the new plaintext once. The Claim Terminal binds redemption to the selected Merchant before mutation, locks the Reward Issuance and Claim Code, enforces location/campaign/value/use restrictions, consumes tracked inventory transactionally, writes the Claim, settles production liability evidence and emits conversion attribution.

## Operations

Make Good is a first-class Campaign Case flow that resolves the customer through Core CRM, opens a Make Good case, enrolls the contact and issues selected reusable Reward Products.

Inventory and Loyalty use append-only ledgers with current balance projections. Production Reward issuance and claiming write liability evidence. Idempotency keys protect issuance/enrollment retries. Reconciliation is diagnostic: it records findings and never silently replays business side effects.

Sandbox activity remains domain-auditable but is prevented from becoming production cognitive/outcome evidence.

## Cognitive integration

Campaigns & Rewards uses the existing v19.20 canonical event inbox through the v26.00 `campaigns_rewards` domain contract. Canonical events include Merchant lifecycle, Campaign lifecycle, Enrollments/Cases, Reward Issuances, accepted/rejected Claims, Loyalty activity, reconciliation, and `campaign.conversion_attributed`.

The plugin does not create another event bus, Brain, memory store, attention engine, learner, scheduler, worker, approval system or execution authority. User-facing cognitive state continues through the v25.90 Presentation Firewall. CRM PII, plaintext credentials, raw payloads and internal traces are not exposed through cognitive object context.

## Plugin lifecycle

The plugin uses the existing VP3 plugin registry/lifecycle. Disabling Campaigns & Rewards pauses its surfaces and contextual capability; it does not delete Merchant, CRM Relationship, Campaign, Version, Reward, Issuance, Claim, Loyalty, audit, reconciliation or Team-source history.
