# Tracky V2.71 — Agent Brain Physical Context Integration

## Goal

V2.71 makes Tracky a first-class physical-context source for the existing VP3 Agent Brain. It does not create a second Brain and it does not give the Agent a new physical action authority.

OTRO/HomeServer remains responsible for sensing and local perception. VP3 Cloud continues to receive only the governed `physical_context.v1` projection introduced in V2.70.

## Agent capabilities

When Tracky is enabled and a site has synchronized, Agent Chat can deterministically answer:

- Where am I?
- Where are my keys?
- Who is in the office?
- When did you last see my keys?
- What changed in the room?
- How confident are you?
- Why do you think that?
- Is Tracky healthy?

The answers come from canonical Tracky Cloud context, world state and governed event history.

## Cognitive Runtime

Tracky registers the `physical_context` cognitive domain with:

- `physical_site`
- `physical_entity`
- `physical_room`

Read-only cognitive tools are registered for current context, location, presence, last-seen history, recent changes, confidence, evidence explanation and health.

The Unified Context Engine receives a bounded `physical_context` section only when a physical query is relevant or when a fresh urgent/system-health Tracky event needs attention.

## Events

Newly accepted Tracky Cloud events can be projected into the canonical VP3 event inbox through `vp3_cognitive_domain_ingest_v2600()`.

Only compact governed summaries are bridged. Informational event noise is not promoted indiscriminately.

## Privacy and authority

V2.71 preserves the V2.70 boundary:

- no raw camera frames in Agent Brain
- no continuous video or audio
- no face/identity embeddings
- no local camera URIs or filesystem paths
- no raw perception evidence copied into Cloud
- no second event ledger
- no second Agent Brain
- no autonomous device-control authority

Tracky tools in this section are reads only. Physical actions remain governed by the existing HomeServer/action systems and are outside this Tracky Agent integration.
