# Stonefellow Agent Architecture — v126

Production baseline: `production-hardening-v126-20260826`

This document is the post-review architecture contract for Stonefellow after Phases 1–6. Internal component version names may remain older when they are the proven owner of that responsibility; v126 is the shared production envelope and operations layer.

## Phase 1 — Conversation engine

1. One shared LISTEN/conversation controller across Agent Chat, Stem Studio, and Video Editor.
2. Explicit voice output API; no global `speechSynthesis` interception for Agent Chat.
3. Live AI text deltas stream into the ElevenLabs sentence queue.
4. Small first voice chunk plus later-ticket prefetch for low time-to-first-audio.
5. Interruption preserves adaptive barge-in and cancels the active AI stream without duplicating turns.

## Phase 2 — Conversation reliability and Agent Brain retrieval

6. Per-device acoustic calibration remembers learned echo floors.
7. Multi-tab microphone arbitration uses a shared expiring lease.
8. Agent Brain stores explicit current conversation state.
9. Recent conversation turns roll into durable summaries.
10. Retrieval combines lexical, semantic, confidence/context, and recency signals.

## Phase 3 — Memory and proactive intelligence

11. Memories use confidence-aware ranking with reinforcement and type-specific aging.
12. Memory reconciliation supersedes duplicates and ages stale non-core memories.
13. Tasks/commitments have `open`, `in_progress`, `waiting`, `completed`, and `cancelled` lifecycle states.
14. Proactive actions are dynamically scored rather than ordered by fixed legacy priorities.
15. Observed events are separate objects from recommended actions.

## Phase 4 — Action system

16. Recommendations have per-action shown/acted/dismissed cooldowns.
17. Proactive ecosystem scans use persisted incremental cursors with overlap protection.
18. Recommended actions carry approval-aware inspect/prepare/execute/verify plans.
19. Open tasks and proposed actions form a dependency/blocker graph.
20. Acted/dismissed outcomes influence future source ranking without suppressing unrelated actions.

## Phase 5 — Runtime reliability

21. Agent Brain maintenance uses a bounded deduplicated background queue with retry/quarantine handling.
22. Browser voice turns, PHP requests, spans, and AI telemetry share trace IDs.
23. Voice-health packets persist recognition/interruption/recovery metrics and session quality.
24. AI streaming uses bounded transient retries and circuit breakers; partial output is never retried.
25. Streamed Agent Chat turns are idempotent before any conversation/message side effect is created.

## Phase 6 — Production hardening

26. Admin runtime health reports current queues, breakers, voice quality, storage, file markers, and Team Chat readiness.
27. Runtime data has bounded retention with automatic locked housekeeping.
28. Runtime observability redacts sensitive keys, tokens, email/phone patterns, and arbitrary non-scalar context.
29. Resilience primitives have deterministic isolated self-tests for breaker behavior, idempotency, queue dedupe, retention, and redaction.
30. A shared `X-Stonefellow-Production` v126 envelope and final CI contract verify Chat, Stem Studio, Video Editor, Team Chat, Agent Brain, actions, and runtime reliability together.

## Non-regression invariants

- `conversation-voice-v122.js` remains the shared conversation/LISTEN controller. Do not add another SpeechRecognition owner.
- SpeechRecognition must not be blocked on a direct `getUserMedia()` diagnostic probe.
- ElevenLabs remains explicit output/streaming; user barge-in must be able to stop actual playback and generation.
- Agent Chat, Stem Studio, and Video Editor continue the same conversation identity rather than creating isolated assistant histories.
- Team Chat remains a 48px desktop directory rail under the header; eligible offline users remain visible; mobile hides the rail.
- Executable Studio commands remain sanitized; external/destructive action plans require approval.
- Runtime logs must not store prompts, message bodies, API credentials, authorization headers, cookies, CSRF values, or arbitrary request/context objects.
- Partial streamed AI output is never automatically retried.
- Idempotency claims occur before streamed Chat side effects.
- Quarantined background failures remain visible to operators until retained cleanup removes old records.

## Operator verification

Admin runtime verification is available at `/admin/runtime-status.php`. Structured health is available at `/api/runtime-health-v126.php` for authorized admins. The runtime page can run the safe resilience self-test, bounded cleanup, and PHP OPcache reset.
