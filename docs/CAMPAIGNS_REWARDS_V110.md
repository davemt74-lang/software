# Campaigns & Rewards V1.10 — Rewards Workspace + Reward Tray

V1.10 separates merchant Campaign operations from merchant Reward operations and moves the personal Reward Wallet experience into Agent Chat.

## Navigation

Plan & Sell now contains separate **Campaigns** and **Rewards** destinations. Both are also available in the bottom user menu. **Reward Wallet is not a sidebar destination.** The legacy `/rewards-wallet.php` route redirects to Agent Chat with the Reward Tray Inbox open.

## Rewards workspace

`/rewards.php` is the merchant-facing Reward workspace. It manages reusable Reward Products, tracked inventory, Campaign attachment, direct issuance to Core CRM Contacts, Make Good issuance, Merchant Claim Codes, recent Claims, Reward metrics and reconciliation.

Campaigns remain in `/campaigns.php`, which now focuses on Merchant identity, Locations, Campaign lifecycle, landing pages and Merchant/Team access.

## Agent Chat Reward Tray

Agent Chat adds three tabs in the right-column header:

- **INBOX** — active certificates currently held by the signed-in VP3 user.
- **SENT** — durable certificate transfers initiated by the signed-in user.
- **CLAIMED** — certificates claimed by the signed-in holder.

Each tab shows its current count.

An Inbox certificate exposes **SEND** and **CLAIM**. SEND selects a target from the user's owner-scoped Core CRM. V1.10 resolves that identity into the issuing Merchant owner's Core CRM, changes the canonical Reward Issuance holder transactionally, invalidates any previously revealed holder credential, and writes a durable `reward_transfers` audit record. The certificate is not cloned.

CLAIM rotates a fresh one-time Reward Credential and renders a QR locally in the browser. No credential is sent to a QR service. The QR points at the canonical Claim Terminal with the credential in the URL fragment, so the credential is not part of the HTTP request. The Claim Terminal consumes the fragment, fills the credential input and immediately clears the fragment from the address bar.

If the current certificate holder is also an authorized Merchant operator, the modal accepts the Merchant Claim Code and uses the existing V1 three-factor claim engine. Otherwise the holder presents/scans the QR at an authorized Merchant terminal. V1.10 does not weaken the required factors: Reward Credential + Merchant Claim Code + authenticated operator with `claims.process`.

## Authority

Reward Issuance remains the certificate authority. `reward_transfers` is an append-only transfer/audit history, not a second certificate ledger. Core CRM remains contact identity authority. Claim state remains `reward_claims`. Campaign versions remain immutable snapshots. Production Claim inventory/liability and cognitive outcome flows remain the existing V1 authorities.
