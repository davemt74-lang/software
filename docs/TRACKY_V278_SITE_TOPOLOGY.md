# Tracky V2.78 Section 1 — Multi-Site / Multi-Node Site Topology

V2.78 Section 1 establishes durable site and device identity before federation begins.

## Physical topology contract

Protocol: `physical_site_topology.v1`.

Built-in OTRO hardware profiles are **Node, Desk, Studio, Team Node, Pocket, and Custom**. Product names do not grant authority. Site authority is capability-driven and remains local to Tracky/HomeServer.

A durable site-authority device must:
- belong to the site,
- be trusted,
- hold the `site_authority` role,
- advertise `site_authority_eligible`,
- be non-mobile.

There is one active authority device per site. Authority epochs are monotonic and same-device claim retries are idempotent.

Pocket/mobile devices can participate in presence, identity continuity and transition sensing, but cannot hold durable site authority.

## Cloud boundary

VP3 Cloud stores only the governed topology summary. It cannot register devices, mutate roles/trust, claim authority, replace authority, or modify topology relationships.

Cloud enforces monotonic topology revisions and rejects conflicting summaries at the same revision.

`GET /api/tracky-site-topology-v278.php` is read-only.
