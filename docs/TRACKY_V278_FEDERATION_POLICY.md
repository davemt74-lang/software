# Tracky V2.78 Section 7 — Permissions, Consent & Site Boundaries

Section 7 adds the federation-governance layer above Tracky's existing local privacy controls. Local cameras, microphones, room policies, sensitive regions, retention rules, and anonymous observation remain governed locally. Federation is separately deny-by-default.

## Authority model

A site authority owns its own federation policy. Cloud mirrors and enforces that policy but cannot create grants, revoke access, alter recognition consent, or assign physical authority. Another site cannot override the source site's policy.

Policy protocol: `physical_federation_policy.v1`.

## Federation scopes

A source site grants an exact scope to an exact destination site:

- `semantic_world_read`
- `agent_context_read`
- `history_query`
- `identity_continuity_read`
- `identity_linking`
- `person_recognition`
- `voice_matching`
- `remote_observation`

An approved topology relationship alone is not permission to share.

## Recognition consent

Recognition consent is site-scoped and identity-scoped for:

- person recognition
- voice matching
- cross-site identity linking

Consent states are pending, granted, denied, and revoked. Revocations advance a monotonic revocation epoch and create tombstones so stale nodes cannot silently restore an older grant or consent decision.

## World-data boundary

Direct federated world sharing is intentionally non-person. Even when `semantic_world_read` is granted, the relay removes person entities, relations involving those person entities, and free-form context that could contain person-derived text. Cross-site person continuity uses the separate identity-continuity channel and its consent checks.

Raw frames, images, video, recordings, audio, embeddings, and similar perception payloads never become federation data.

## Mobile transitions and Agent context

Mobile transitions are cross-site physical context, so relay requires `agent_context_read` from the source site to the destination. Temporary/mobile context never becomes durable site authority.

The Section 6 federated Agent-context snapshot remains Cloud mirror-only; Section 7 does not turn Cloud into a source of physical truth.

## Cloud role

Cloud stores only a validated mirror of HomeServer-governed policy. It verifies the governing site authority device and epoch against the topology mirror and enforces the latest policy at relay time. This means a newly received revocation immediately suppresses delivery of older stored world or identity state.

Cloud also returns a destination-specific policy mirror with each federation sync. The destination HomeServer stores that mirror read-only and applies it to cached remote world, identity, and mobile-transition views. Revoked cached remote state remains durable for reconciliation and audit, but disappears from Agent context and read APIs.

Cloud capability guarantees:

- `cloud_role = mirror_relay_enforcer`
- `cloud_can_grant = false`
- `cloud_can_revoke = false`
- `cloud_can_change_consent = false`
- `raw_perception = false`

The Cloud policy endpoint is read-only.
