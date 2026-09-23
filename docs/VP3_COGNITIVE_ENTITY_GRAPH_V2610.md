# VP3 Cognitive Runtime v26.10 — Cross-Domain Entity Graph & Relationship Resolution

v26.10 lets VP3 understand verified relationships between objects that remain owned by different domain systems. It does not create a graph database or duplicate Merchant, CRM, Team, Campaign, Meeting, Commerce, Browser, Research, Knowledge, Task or Profile records.

## Graph authority

The graph is an ephemeral projection over the canonical v5.00 Cognitive Runtime object registry, the v26.00 Cognitive Domain Registry, and each registered module's existing permission and relationship providers. Every node and every expansion hop must pass the target domain's own read authorization.

Graph seeds come from two bounded sources: explicit object references already supplied to Working Context, and the `object_refs` array on recent trusted/verified events in the canonical v19.20 event inbox. v26.10 never scans arbitrary event payload fields for identity.

## Resolution rules

Object identity is deterministic: object type + canonical ID + scope (+ workspace when applicable). VP3 does not merge records because names, email addresses, phone numbers or other fuzzy fields look similar.

Only deterministic or user-confirmed relationship edges can expand the graph. Model-inferred links are retained only as unresolved diagnostic evidence and never become identity. Conflicting verified identity links are surfaced as graph-health conflicts rather than silently merged.

## Bounded projection

A graph projection is limited to 10 seeds, 36 authorized nodes, 72 relationships and two hops. The result is read-only and request-scoped.

Health diagnostics include unresolved links, conflicting identity links, orphaned seed objects, cross-domain edge counts, domain counts and truncation state. Diagnostics do not create repair jobs or replay side effects.

## Working Context and presentation

v26.10 contributes a bounded `entity_graph` item to v24.20 Working Context. It summarizes only structural counts and verified relationship availability.

Agent Brain and proactive surfaces receive a separate v25.90 Presentation Firewall object. Raw graph node IDs, internal relationship provenance, event payloads, CRM PII, confidence values and model reasoning are not copied into user-facing presentation.

## Campaigns & Rewards reference implementation

Campaigns & Rewards remains the first reference domain. Its canonical relationship provider now exposes authorized links including:

- Merchant → Owner Profile
- Merchant → canonical VP3 Team members
- Merchant → Core CRM Contacts
- Merchant → Campaigns and Merchant Locations
- Campaign → Merchant and Reward Products
- Enrollment / Make Good Case → Campaign and Core CRM Contact
- Reward Issuance → Campaign, Reward Product, recipient Core CRM Contact and resulting Claim
- Reward Claim → Reward Issuance, Merchant Location and processing VP3 Team member

Cross-domain targets are still filtered by their own domain authorization. A Merchant collaborator therefore cannot use the graph to bypass Core CRM, Profile or Team privacy boundaries.

## Release invariants

v26.10 adds no graph table, entity table, event bus, Brain, memory store, attention pipeline, scheduler, worker, approval path or execution authority. Domain systems remain authoritative and v25.90 remains the only user-facing presentation firewall.
