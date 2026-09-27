# Tracky V2.78 Section 3 — Cross-Site Sync, Authority & Conflict Resolution

Protocol: `physical_federation_sync.v1`.

Section 3 uses the existing HomeServer → VP3 Cloud Tracky sync request as a bidirectional federation transport. The HomeServer POSTs its normal governed physical context and Cloud may return newer semantic world envelopes for approved peer sites.

## Authority model

Every envelope preserves the source site's:

- stable site UUID,
- authority device UUID,
- authority epoch,
- world revision,
- source fingerprint,
- semantic world fragment.

Cloud is **relay-only**. It does not assign site authority, mutate semantic world facts, create cross-site identity links, or pick a conflict winner.

Once a HomeServer resolves its local stable site UUID, it uploads only that local site's authoritative federated-world fragment. Remote fragments learned from federation are not re-originated to Cloud as local observations.

## Conflict rules

- Lower world revisions are stale and ignored.
- Exact revision + exact fingerprint is idempotent.
- Exact revision + different fingerprint is quarantined.
- A source authority epoch newer than the receiver knows is held/quarantined until topology catches up.
- An older or mismatched authority epoch/device is quarantined.
- Unapproved site peers are quarantined.
- Wrong-destination envelopes are ignored.
- Cloud re-validates the current origin authority before relaying a stored fragment.

Topology revision counters are diagnostic only because they are local counters. Authority epochs are the cross-site comparable authority signal.

Cross-site identity linking remains deferred to V2.78 Section 5.
