# Tracky V2.74 — End-to-End Integration & Reliability Hardening

## Purpose

V2.74 hardens the V2.73 OTRO/Cloud join without adding a new authority, queue, broker, pairing path, or physical-action permission.

HomeServer v2.4 continuity/native authority/reconciliation remains foundational and authoritative.

## OTRO reliability

- exponential semantic-sync retry backoff: 5s → 10s → 20s → 40s → 80s → 160s → 300s cap
- pending-event backlog health thresholds
- retry state survives process restarts in the existing Tracky local database
- stale requested/accepted/observing active-perception requests recover as failed after interruption
- a newer request with the same correlation supersedes an unfinished predecessor
- provider execution has a bounded deadline; timeout fails closed instead of hanging the relay
- the normal HTTPS worker honors Tracky sync backoff
- replay remains idempotent; events are marked synced only after Cloud acknowledgement

## Cloud reliability

Before Cloud accepts a fresh active-perception result, it verifies:

- request ID
- correlation ID
- HomeServer/site identity
- `physical_context.v1` protocol
- projection site identity
- returned Cloud sequence reaches the HomeServer projection sequence

A failed identity/sequence check is rejected before the result can be described as freshly verified.

## Reliability states

Cloud projects one joined state: `healthy`, `stale`, `recovering`, `degraded`, `critical`, `reconciling`, `incompatible`, or `offline`.

The state is derived from the canonical HomeServer capability, v2.4 reconciliation gate, local Tracky reliability metrics, and Cloud projection freshness.

## Golden vectors

Both repos retain the same V2.74 resilience fixture covering replay, sequence conflict, Cloud outage/backoff, stale request recovery, correlation supersession, provider timeout, privacy denial, reconciliation pending, protocol mismatch, wrong-site routing, backlog thresholds, and fresh-result identity mismatch.

## Authority boundary

V2.74 remains read + verify only. It does not execute device commands or approve actions. Active perception still routes through `homeserver_execution_v220_execute()` and the existing v2.4 transport/authority fabric.
