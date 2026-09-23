# Campaigns & Rewards V1.18 — Merchant Activation & Modular Campaign Lifecycle

V1.18 carries the proven Microgifter campaign/reward architecture into VP3's native Campaigns & Rewards plugin without duplicating VP3 Core authorities.

## Campaign Type model

Campaign Types are registry-driven, modular and extensible. Each type carries:

- category
- description
- base handler
- public participation mode
- required public fields
- marketing-consent behavior
- Reward timing
- Reward requirement
- trigger semantics
- default CTA
- case/automation/Agent capabilities

System types include:

- Signup Reward
- Contest / Giveaway
- QR Reward Drop
- Referral Reward
- Birthday / VIP Club
- Agent Offer
- Social Engagement
- Flash / Limited-Time Drop
- Pre-Purchase / Interest
- Win Back
- Local Event / RSVP
- UGC / Story
- Loyalty / Milestone
- Partner / Cross-Merchant
- Post Purchase
- Make Good
- Customer Refund / Recovery
- Promotional Goods
- Discount Voucher
- Training / Education
- Public Donation / Community Reward

## Signup Reward

Signup Reward is specifically a newsletter/email-list acquisition flow.

1. Visitor opens the public Campaign landing page.
2. Name and email are captured into canonical VP3 CRM.
3. Marketing consent is required.
4. Merchant relationship is written as a prospect with `marketing_status=subscribed`.
5. Campaign enrollment is created against the immutable published Campaign Version.
6. The configured attached Reward is immediately issued.
7. The certificate appears in the canonical Reward lifecycle (Inbox / Sent / Claimed).
8. CRM history, Campaign activity and cognitive events are written.

There is no parallel subscriber/customer database.

## Campaign + Reward setup

Campaign creation/editing now includes Reward Product attachment in the same workflow. The Campaign editor may attach any active reusable Reward Product owned by the Merchant.

Campaign Types marked `requires_reward` cannot be activated without at least one attached active Reward.

Changing Reward attachments on an active Campaign creates a new immutable Campaign Version snapshot so existing Reward Issuances remain governed by their original terms.

## Public behavior families

Immediate issuance:
- Signup Reward
- QR Reward Drop
- Flash Drop
- Promotional Goods
- Discount Voucher
- Referral Reward
- Partner Offer

Verification/selection gated:
- Contest / Giveaway winner Reward

Triggered later:
- Birthday / VIP
- Pre-Purchase / Interest
- Public Donation / Community allocation

Verification-gated:
- Local Event / RSVP (attendance confirmation before reward)
- Social Engagement
- UGC / Story
- Training / Education

Existing-contact / governed internal flows:
- Win Back
- Loyalty / Milestone
- Post Purchase
- Agent Offer
- Make Good
- Customer Refund / Recovery

## Authority boundaries

- Core CRM remains customer/contact authority.
- Campaigns owns Campaign, immutable Version, Enrollment, Case, Reward Set and activity state.
- Reward Products remain reusable Merchant objects.
- Reward Issuance remains certificate authority.
- Reward Transfer remains holder-transfer audit.
- Reward Claim remains redemption truth.
- Inbox / Sent / Claimed remain projections, not a second wallet ledger.
- Existing VP3 Agent Brain/cognition receives Campaign activity through the canonical cognitive domain.
- Campaigns adds no payment authority, parallel CRM, scheduler, worker or second Agent Brain.

## Cognitive events

V1.18 adds typed Campaign activity including:

- `campaign.landing_viewed`
- `campaign.newsletter_signup`
- `campaign.contest_entry`
- `campaign.qr_claim`
- `campaign.referral_signup`
- `campaign.birthday_signup`
- `campaign.proof_submit`
- `campaign.instant_claim`
- `campaign.interest_signup`
- `campaign.event_rsvp`
- `campaign.partner_signup`
- `campaign.community_signup`
- `campaign.rewards_updated`

These feed the existing cognitive runtime and Presentation Firewall.
