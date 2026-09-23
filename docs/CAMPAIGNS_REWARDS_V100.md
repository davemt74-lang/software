# Campaigns & Rewards V1.00

Campaigns & Rewards is a native VP3 plugin built against the Cognitive Domain Registry introduced in v26.00.

A VP3 user account is an identity. A merchant account is a separate business entity. The plugin host user owns the plugin installation, while each merchant account supports multiple active owner, admin, and member relationships. Merchant records never replace a user's Profile, CRM, Team membership, subscription, or Agent identity.

V1 owns merchant accounts and merchant members, merchant locations, campaigns and campaign landing pages, rewards, campaign customers, claim codes and claim lifecycle, campaign activity and derived reporting, and plugin-specific Team scope metadata.

CRM remains authoritative for contacts. A campaign customer stores an optional crm_contact_id reference after the existing CRM contact upsert runs. Canonical Team membership remains workspace_memberships_v350. Campaigns & Rewards adds Basic Team, Merchant Team, and Both as plugin scope metadata. It does not create a second Team identity or lifecycle table. Direct merchant owner/admin roles and Merchant Team scope are independent access sources; removing or suspending Team scope never revives a previously removed direct admin role.

Campaigns may be draft, active, paused, or ended. Only active campaigns inside their configured time window are public and appear on the owner's Profile Campaigns tab. Rewards have independent active windows, inventory limits, and per-customer limits.

A visitor claim upserts the existing CRM contact when available, upserts a merchant-scoped campaign customer, locks the reward while checking inventory and per-customer limits, creates a random unique claim code, exposes a public verification URL, and lets an authorized merchant owner or admin validate and redeem the code. Redemption emits reward.claimed, claim.completed, and campaign.conversion.

Campaign activity enters the existing v19.20 canonical event inbox through the v26.00 campaigns_rewards domain contract. It does not create another event ledger, Brain, memory system, attention engine, or presentation system. User-facing state continues through the v25.90 Presentation Firewall, and customer email, phone, raw claim form input, and internal activity metadata are not copied into cognitive event payloads.

The plugin uses the existing v3.20/v3.60 plugin registry and lifecycle. Disabling Campaigns & Rewards pauses working surfaces; merchant, campaign, reward, customer, claim, Team scope, CRM linkage, and reporting history remain durable.
