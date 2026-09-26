# Tracky V2.7 — VP3 Cloud Foundation

## Purpose

Tracky becomes a first-class VP3 plugin while OTRO remains the HomeServer/runtime authority. This Cloud foundation is deliberately contract-first so HomeServer development can continue independently.

## Contract

Protocol: `physical_context.v1`

The Cloud accepts only governed physical meaning from an already paired OTRO HomeServer. It does not accept continuous camera feeds or raw perception evidence.

### Cloud-eligible classes

- `cloud_derived`
- `system_health`
- `user_approved`

### Rejected local-only material

- raw frames or images
- video or audio
- recordings/transcripts
- face/identity embeddings
- local filesystem paths
- camera/source URIs

## Synchronization

`POST /api/tracky-sync-v270.php`

Authentication reuses the canonical HomeServer HTTPS session:

- `Authorization: Bearer <homeserver-session-token>`
- `X-HomeServer-Device: hs-...`

Payloads include:

- protocol
- site identity
- status
- capabilities
- health
- monotonically increasing sequence numbers
- idempotent event IDs
- governed events
- current world-state relations
- compact Agent context
- sync cursor

Cloud ingestion is idempotent by `user + site + event_id`. Older sequence data may be retained as history but cannot overwrite a newer world-state projection.

## Authority boundaries

Tracky Cloud does not create:

- a second Agent Brain
- a second HomeServer pairing mechanism
- a second room/device controller
- an autonomous action runtime
- raw camera storage
- a second identity authority

The existing VP3 plugin lifecycle, OTRO pairing/session transport, subscription entitlements and Agent authorization remain canonical.

## Development simulator

`tracky_cloud_v270_simulator_payload()` produces deterministic `physical_context.v1` data so Cloud work can be validated before the OTRO Tracky endpoint is merged.
