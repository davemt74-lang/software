# VP3 Cognitive Runtime v23.90 — Domain Integration II

v23.90 completes the second domain-integration wave on top of the v23.70 live-session/event foundation and v23.80 business-operational adapters.

## Canonical flow

```
Meetings / Browser transactions / Research / Knowledge / Team messaging
Tools / HomeServer / Media / Analytics + Attribution
                              ↓
                    agent_event_inbox
                              ↓
                  live-session projection
                              ↓
                    Cognitive Runtime
```

Existing domain stores remain authoritative. This phase does not create another meeting store, browser-history database, research index, messaging archive, tool history, HomeServer authority, media catalog, analytics warehouse, event ledger, or Brain.

## Meetings

Meetings already implement the target adapter architecture in `cognitive-runtime-meetings-v500.php`. v23.90 preserves that implementation and uses it as the reference rather than replacing or wrapping it unnecessarily.

## Browser transactions and annotations

Durable transaction continuity emits cognitive events only when continuity begins or a meaningful lifecycle state changes. A matched return page with no meaningful change does not create a new cognitive event. Raw URLs, page text, transaction reference hashes, page fingerprints and content hashes remain outside cognitive payloads.

Published Browser annotations emit a reference-only event. Normal browsing remains ephemeral.

## Research and Knowledge

The existing Research event ledger is bridged at its central event function. Project creation/update, sources, findings and published reports become normalized cognitive events without duplicating finding/report text.

Personal Knowledge creation/update emits owner-scoped references after the authoritative item is stored and indexed. Knowledge content remains in the Knowledge subsystem and is resolved only through authorized context retrieval.

## Team messaging and membership

Human/Team message insertion emits message references to active conversation members. Message bodies are explicitly not copied into cognitive events.

Team membership activation, role changes, suspension and removal emit scoped lifecycle events for the workspace owner and affected member.

## Tools

The existing `agent_tool_history` row remains authoritative. Tool outcomes emit only the tool key, status, conversation reference and history object ID. Request text and result JSON stay in the tool-history authority.

## HomeServer

Pairing start, successful connection, denied/expired pairing and disconnect become cognitive events. Relay tokens, HomeServer tokens, claim credentials, approval codes and raw capability payloads are never copied into the event stream. Physical execution remains governed by existing HomeServer approval and capability systems.

## Media Studio

A newly persisted media asset emits a reference-only event with media type and source. File paths and metadata blobs remain in Media Studio. Existing legacy Brain writes are left intact for compatibility and will be evaluated during the v24.00 memory-promotion consolidation.

## Analytics and attribution

Analytics intelligence spikes, attributed referrals and attributed conversions emit normalized signals referencing their authoritative Radar/attribution rows. Full analytics metric blobs are not duplicated.

## Memory and attention

Like v23.80, v23.90 events are record-only. They do not invoke the legacy automatic Brain observation path. Event → episodic candidate → selective durable-memory promotion belongs to v24.00. Unified interruption ranking belongs to v24.10.

## Privacy invariant

Longfellow may know about a domain event while still resolving the source record only when needed and authorized. Cognitive events are references and compact state changes—not copies of raw user content.
