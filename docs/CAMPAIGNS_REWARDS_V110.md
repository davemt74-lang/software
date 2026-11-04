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
- **CLAIMED** — successful merchant redemptions for the holder recorded at checkout, with the merchant, location, time and receipt ID.

Each tab shows its current count.

An Inbox certificate exposes **SEND** and **SHOW VOUCHER**. SEND selects an owner-scoped Core CRM contact with a matching active VP3 account. It changes the persisted holder transactionally, invalidates any previously revealed holder credential, and writes a durable `reward_transfers` audit record. The same certificate moves to the recipient. First sends and onward sends use their respective issuance terms.

SHOW VOUCHER rotates a fresh one-time Reward Credential and renders a QR locally in the browser. No credential is sent to a QR service. The QR points at the canonical Claim Terminal with the credential in the URL fragment, so the credential is not part of the initial HTTP request. The Claim Terminal consumes the fragment, fills the credential input and immediately clears the fragment from the address bar.

At checkout, the three-factor check verifies the Reward Credential, Merchant Claim Code and operator’s `claims.process` permission at an eligible active in-person location. If the holder is also an authorized operator, the same controls are available in the modal. Retrying the same request after a lost response recovers the committed send or claim receipt.

## Authority

The account persisted on the Reward Issuance is the sole VP3 holder. Core CRM relinking leaves the holder unchanged. Account-bound recipients reveal credentials from their Inbox. Historical accountless certificates can still redeem with their issued credential at the Merchant terminal. Claim state remains in `reward_claims`. Campaign versions, inventory, liability and conversion attribution retain their existing domain records.
