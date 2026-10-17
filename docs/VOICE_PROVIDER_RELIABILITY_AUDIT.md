# Voice and provider reliability audit — 17 October 2026

Scope: Agent Chat in Cloud and HomeServer. Keep the minimal conversation canvas; do not add permanent diagnostics or automatically resend uncertain turns.

## Findings and corrections

1. Cloud's voice fetch wrapper replaced the canonical chat abort signal. Stop, navigation or a chat deadline could leave voice transport running. The voice owner now observes that signal, including during context preparation, aborts transport/output and rejects late completion.
2. Cloud treated NDJSON EOF without a successful `done` event as success. Incomplete streams now fail, keep the draft through canonical recovery and never trigger a second inference request. Incremental speech already heard before a broken stream cannot be recalled.
3. Cloud's fast transport converted HTTP authentication/permission failures to 502. It now preserves HTTP status; the streaming endpoint explicitly returns 401 for an absent/expired session and 403 for missing chat permission. Canonical 401 cleanup remains authoritative.
4. ElevenLabs readiness and ticket requests could wait indefinitely. They now have 12-second deadlines and cancellation; readiness calls share one pending request. Late results cannot change readiness. Audio unlock has an 8-second deadline and audible chunks have an end watchdog. These bounds affect speech transport, not automatic inference retries.
5. HomeServer used a text-length watchdog during Piper preparation, silently dropping short replies after 15 seconds despite the server's 60-second synthesis limit. Preparation now has a separate 65-second deadline. An allowed browser fallback starts once under a new output generation; Strict Local stops without browser speech. Late synthesis/decode results cannot replace fallback.
6. Stalled or failed audible playback now pauses conversation mode with a short retry message. It cannot silently resume listening over a missing reply. Successfully completed playback still resumes listening normally.

## Acceptance evidence

| Acceptance | Automated evidence |
| --- | --- |
| Explicit microphone ownership; no late capture after Stop | Capture ownership behavior suites, Cloud and HomeServer |
| Correct transcript identity and one canonical send | Listening suites and complete Chat browser fixtures |
| Stop while preparing context or waiting for a provider | Cloud listening suite and fast-stream browser case |
| No successful completion from a truncated stream | Cloud listening suite and fast-stream browser case |
| Provider errors, timeout, offline and retained draft | Canonical Chat recovery browser fixtures |
| Owner-session expiry cannot retain private recovery | Canonical and fast-stream Cloud browser cases; native owner-session fixture |
| Bounded readiness, ticket and audible output | Real premium module under a deterministic clock |
| Local synthesis deadline and Strict Local policy | HomeServer listening behavior suite |
| Selected Agent, conversation and output ownership | HomeServer routing tests and complete browser fixture |
| Successful speech resumes once; cancelled output stays cancelled | Listening, premium runtime/unlock and full browser fixtures |

Primary commands: `node tests/listening-section2.mjs`, `node tests/capture-ownership-section1.mjs`, `node tests/chat-canvas-voice-browser.mjs`, `node tests/premium-voice-runtime-v146.mjs`, `node tests/premium-audio-unlock-v147.mjs`, `node tests/chat-fast-voice-v144.mjs`, `node tests/chat-proactive-voice-hardening-v244.mjs`.

## Installed-machine acceptance remains required

Automated evidence uses controlled provider/speech responses. It does not certify the owner's configured credentials, a live provider endpoint, microphone drivers, Windows speech services, speaker routing or room acoustics. Do not mark real-device acceptance complete from these fixtures.

After installing both packages, verify on Cloud and HomeServer: allow/deny microphone permission; ask a question through the configured model and hear its selected voice; interrupt during thinking and speaking; confirm the draft/history before an explicit resend; finish a normal reply and confirm one listening restart. On HomeServer also test installed Whisper/Piper with Strict Local enabled, then disable Strict Local and verify the disclosed permitted fallback. Record OS/browser, provider/model, selected Agent/voice, outcome and any failure. Never record API keys.

This section introduces no schema change, automatic message replay or new permanent canvas panel. A transport abort cannot undo a server-side action that has already completed; check canonical history before sending again.
