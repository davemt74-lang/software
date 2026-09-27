# Tracky V2.78 Section 6 — Federated Agent Context

Protocol: `physical_federated_agent_context.v1`.

Section 6 gives the shared Agent Brain a compact, explainable view of federated physical context. It is a **derived snapshot**, not a new authority source.

The snapshot can describe:

- current, reconciling, stale, or failed Agent physical-context state,
- current site when evidence supports one,
- explicit uncertainty when multiple sites conflict,
- which site/HomeServer currently holds relevant physical authority,
- an active explicitly bound mobile transition,
- current canonical person identity when it can be resolved conservatively,
- which remote sites changed since the previous snapshot,
- freshness and sync state for each federated site.

## No invented location

If current evidence conflicts, is stale, or does not resolve to one site, the Agent snapshot does not invent a location.

A mobile transition is bound to a person only when the transition uses that canonical identity as an explicit continuity subject. A generic Pocket/mobile-device transition is not silently attributed to a person.

Temporary contexts remain non-authoritative.

## Authority

The context reports physical authority; it does not create or transfer it. Site authority continues to come from the V2.78 topology and V2.4 continuity/reconciliation system.

The Cloud projection is read-only. Cloud validates:

- the uploader has a resolved federation site,
- the snapshot `local_site_id` matches that uploader site,
- any reported authority device + epoch matches the uploader's topology mirror,
- snapshot revisions are monotonic.

Cloud does not relay Agent context back to HomeServer as physical truth and cannot mutate the context or site authority.

Read-only Cloud surface: `GET /api/tracky-federated-agent-context-v278.php`.
