# Screen sharing follow-through

This release connects source-derived proactive cards, canonical Research/Mission handoffs, result saves, observation corrections and session wrap-ups. No microphone or speech-permission code changes. The source folders remain the existing My Knowledge folders.

## Acceptance target

Each section is held to ten acceptance checks; a passing implementation is ready for deployment testing, not a claim that a live provider/installation has been certified.

| Section | Ten acceptance checks |
|---|---|
| Proactive cards | Account scope; Agent scope; distinct-session recurrence; generic progress exclusion; confidence filtering; bounded recency; source citations; revision-bound actions; durable Later/Dismiss/Never; no automatic execution. |
| Research/Mission follow-through | Canonical Research service; draft source finding; idempotent project creation; canonical Mission prepare; stable request ID; no automatic worker start; native assignment review; honest HomeServer availability; current-source result sync; result storage in original Knowledge folder. |
| Memory review | Owner authorization; exact capture revision; current/incorrect/outdated/irrelevant states; user clarification; original evidence preserved in history; excluded invalid sources; prior Brain promotion removed; guarded re-promotion; stale result note invalidation; immediate suggestion invalidation. |
| Session wrap-up/deletion | Ended-session projection; accepted evidence only; capture timestamps; user notes; clarifications; deterministic Knowledge key; source links; correction invalidates saved summary; deletion removes contribution and generated result notes; unrelated native projects/Missions remain independent records. |

## Behavior

The feed is computed from bounded, account/Agent-owned source rows. It does not copy raw screen content into a separate model memory. Structured literal subjects must appear in different sessions to count as recurrence. Low-confidence observations and generic meters such as playback progress do not become interests. Newness and displayed changes are labelled observations, not verified facts or confirmed user preferences. No action is taken solely because a screen contains an instruction.

Chat shows at most three cards at a time, with source links and a Why this suggestion explanation. Later suppresses the topic for 24 hours. Dismiss suppresses the current semantic evidence, including repeat captures of the same session/topic; genuinely new session evidence can return. Don't suggest again suppresses that topic key until the owner restores dismissed suggestions. The feed refreshes while visible without opening Chat messages, interrupting voice, or starting tools.

Research creates a real personal Research project and draft finding through the existing service. It does not claim that a web search or research analysis has already occurred. Research findings and report summaries can be explicitly saved back into the newest source session's Knowledge folder. Saved outputs remain labelled with their draft/report status and source references.

Specialist Missions still use the current HomeServer Mission API. A Cloud worker/job engine exists separately, but this release does not pretend that the specialist protocol has been connected to it. Mission prepare uses a stable request ID, then opens the existing Agent Teams tools/assignment review. No workers auto-start. When disconnected, the UI reports the HomeServer dependency. Returned Mission output can be explicitly saved into the session folder. An unconfirmed preparation retries with the same request ID; native idempotency remains authoritative.

Cloud Mission start/resume/retry/approval/tool-start/change-review calls for linked Missions revalidate their source revisions. A corrected/deleted source blocks those Cloud calls and result sync until a fresh handoff is prepared. This is not a replacement for direct HomeServer scheduling/execution policy; existing native Missions and schedules remain independent records and must be cancelled through their native controls if desired.

Captured observations can be marked incorrect, outdated, irrelevant, or restored, with a user clarification. Review uses optimistic source revision checks. Original source bytes stay in authorized capture history, while withdrawn source text is removed from its generated searchable Knowledge note. Its prior capture-specific Brain promotion is removed; restoration does not silently promote it again. A prior saved session summary and generated follow-up result note are invalidated when sources change. Source-linked native Research projects and Missions remain separate user records, with stale status shown by the handoff feed.

An ended session offers a deterministic wrap-up of its accepted observations and notes, with timestamps and sources. Save session wrap-up updates the canonical session Knowledge note instead of adding duplicates. Delete session data removes source contributions and session-generated follow-up notes while preserving unrelated Knowledge items and native Research/Mission records. If the user explicitly retains capture-specific Brain memories during deletion, those remain independently retained memories as requested.

## Validation

`tests/agent-screen-insights-v1.php` executes the production insight/review/handoff functions, real canonical Knowledge storage and actual Research service against SQLite-translated Research table definitions. Native Mission transport is injected to verify prepare/get boundaries without a live HomeServer. The session endpoint suite exercises actual authentication/CSRF/account/Agent fences, persistent suppression, exact-revision reviews, stale actions and offline Mission responses. Chromium acceptance uses the actual Chat/review scripts and mocked transport, with mobile layout, native assignment handoff, reminder drafts, result destination links and source withdrawal. Existing screen/display/audio and Chat/voice suites remain required. Live Cloud provider, HomeServer connection and native Mission runtime acceptance remain deployment checks.
