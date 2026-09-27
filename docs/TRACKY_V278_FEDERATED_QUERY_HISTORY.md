# Tracky V2.78 Section 8 — Federated Query API & History

Section 8 adds a governed query layer over the V2.78 federated physical world without creating a second source of truth.

## Contract

Protocol: \`physical_federated_query.v1\`.

Supported intents:

- \`current_state\`
- \`where_is\`
- \`last_seen\`
- \`history\`
- \`what_changed\`
- \`explain\`

Every Cloud query names an explicit **destination site**. Cloud acts only on behalf of that site and never treats itself as a federation site.

## Permission model

Federated queries are deny-by-default.

- Remote current-state queries require \`semantic_world_read\`.
- Remote historical queries additionally require \`history_query\`.
- Revocation is checked at query time, so previously mirrored data becomes inaccessible immediately when the governing site revokes the relevant grant.
- Same-site queries do not require a cross-site grant.

A target that crosses sites must use a site-qualified reference such as \`site:<uuid>::object%3Akeys\`. Cross-site person queries do not use the world channel; they remain on the Section 5 **identity continuity** path and its consent rules.

## History

HomeServer and Cloud retain immutable semantic snapshots whenever a newer authoritative federated-world revision is accepted. Section 8 does not create a new history transport: revisions already moving through the federation sync are recorded as history at each governed mirror.

History is bounded at query time. Query audit is also bounded and records only semantic query metadata and a result fingerprint.

## Privacy and authority

Raw perception is never queryable or stored in Section 8 history. Frames, images, video, recordings, audio, embeddings, transcripts, camera URIs and filesystem paths remain prohibited.

Remote world results use the Section 7 non-person projection: person entities, person-linked relations and free-form context are removed before a query result is built.

Cloud is a **mirror/query layer only**. It cannot grant or revoke permissions, change recognition consent, mutate physical world state, assign site authority, or invent a location. Results include source revision, authority device/epoch and policy basis so the Agent can explain why an answer exists or why access was denied.

## API

Cloud exposes a GET-only endpoint:

\`/api/tracky-federated-query-v278.php\`

The caller supplies a destination site, source site, intent and optional site-qualified target. \`audit=1\` returns the bounded read-only query audit.

HomeServer exposes paired GET-only endpoints under:

- \`/api/v1/tracky/federated-query\`
- \`/api/v1/tracky/federated-query/audit\`

HomeServer automatically uses its resolved local federation site as the destination.
