# Tracky V2.78 Section 2 — Federated Physical World Model

Section 2 creates the semantic world model that can contain multiple authoritative physical sites without collapsing their identities.

## Contract

Protocol: `physical_federated_world.v1`.

Each site world fragment carries:

- stable site UUID,
- local authority device UUID and authority epoch,
- topology revision,
- monotonic site-world revision,
- semantic entities,
- semantic relations,
- compact semantic context.

Entity references are qualified by site. The same local entity key at two sites is intentionally represented as two different federated refs in Section 2.

Cross-site person/object identity linking is **not** performed here. That work is reserved for V2.78 Section 5 — Cross-Site Identity Continuity.

Raw frames, audio, video, embeddings and local file paths remain local and are rejected by the federation contract.

## Authority boundary

HomeServer validates every fragment against its local Section 1 topology authority. VP3 Cloud validates the same authority device + epoch against the mirrored topology before accepting the fragment.

Cloud is read-only. It cannot mutate world facts, assign authority, or create identity links.

`GET /api/tracky-federated-world-v278.php` exposes the current governed federated semantic world.
