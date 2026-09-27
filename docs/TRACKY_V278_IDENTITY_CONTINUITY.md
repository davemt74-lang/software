# Tracky V2.78 Section 5 — Cross-Site Identity Continuity

Protocol: `physical_identity_continuity.v1`.

Section 5 adds a governed identity-link layer **above** site-local world entities. The source observations remain site-scoped and immutable; canonical identity is a reversible interpretation of those observations.

## Model

A canonical identity UUID may represent a person, device, object, or animal across multiple physical sites.

Identity continuity uses:

- site-qualified entity refs such as `site:<uuid>::person%3Adave`,
- proposed/confirmed/rejected/revoked/split link states,
- semantic evidence with provenance and confidence,
- explicit consent metadata for automated person linking,
- aliases on the canonical identity,
- a blocked-pair ledger after rejection or split,
- revision history for every link decision.

## Conservative linking

Person identity is never silently merged. Automatic person confirmation requires consent plus multiple independent high-confidence semantic signals. A user confirmation may confirm a proposal directly.

Stable device credentials can confirm device continuity across sites. Objects and animals require independent high-confidence evidence or explicit user confirmation.

Raw frames, audio, face embeddings, voice embeddings, and other local perception payloads are rejected.

## Reversibility

A confirmed link may be split, and a canonical identity may be revoked. The underlying site-local entity refs are never rewritten or deleted as part of identity correction.

One site-local ref cannot belong to two active canonical identities.

## Authority and Cloud

Each decision is governed by one of the linked sites and carries that site's current authority device UUID + authority epoch.

VP3 Cloud validates that governing authority before accepting a link revision. Cloud is a mirror/relay only:

- it cannot confirm a proposal,
- it cannot merge canonical identities,
- it cannot split or revoke identities,
- it relays a link only to HomeServers whose stable site UUID is a member of that link.

The read-only Cloud surface is `GET /api/tracky-identity-continuity-v278.php`.
