# commerce-payment-v1

`commerce-payment-v1` is the frozen VP3 Cloud ↔ HomeServer payment-authority contract.

## Ownership

VP3 Cloud is canonical for Product/Offer → Order → Order Item → Payment/Refund ledger → Fulfillment linkage. HomeServer may execute an explicitly selected local payment authority, but it does not price products, calculate deposits/tax/discounts, own scheduling, or perform fulfillment.

## Wire rules

- IDs crossing the bridge are opaque strings.
- Money crossing the bridge is an integer in the currency's minor unit and uses `*_minor` field names.
- Currency is lowercase ISO-4217 alpha-3.
- Unknown request fields are rejected.
- Money-moving mutations require a stable idempotency key.
- Payment authority is pinned to the attempt; no silent fallback from HomeServer to Cloud or vice versa.
- Provider credentials and raw card data never cross the pairing boundary.

The Cloud database may retain legacy `*_cents` column names internally. Those names are storage details and are not part of this wire contract.

## Operations

- `vp3.commerce.payments.status` → `payments.read`
- `vp3.commerce.checkout.create` → `payments.write`
- `vp3.commerce.checkout.retrieve` → `payments.read`
- `vp3.commerce.webhook.verify` → `payments.write`
- `vp3.commerce.refund` → `payments.refund`

HomeServer verifies provider webhooks at the selected authority and returns normalized payment state plus provider diagnostics. Cloud remains responsible for applying the result idempotently to the canonical ledger.

## Compatibility

The exact canonical payload is `contract.json`; its digest is pinned in `SHA256`. Breaking semantic or field changes require `commerce-payment-v2`. v1 does not silently gain optional wire fields.
