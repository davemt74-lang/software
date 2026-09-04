# Stonefellow v84 — Agent Brain Tools

- Agent Chat can open authorized Stem Studio projects and return playable production-stem search results with parent-song metadata, full-song playback and Studio links.
- Stem Studio includes an auditable AI session panel for transport, tempo, track selection, mute/solo/level/pan, inspector, automation, native plugin insertion, library, arm/monitor/record and save actions. Recording remains confirmation-gated.
- Voice conversation supports barge-in: an echo-cancelled microphone VAD stops speech output when the user interrupts, then hands control back to browser speech recognition.
- Booking Agent is built into Agent Chat: it ranks 90-day listener-density markets, tracks upcoming user shows, searches current public-web venue opportunities, stores research history and maintains a per-user opportunity pipeline without a separate Booking Agent page.
- Every executable Agent Brain tool call is written to `agent_tool_history`; Studio sessions use `agent_studio_sessions` + `agent_studio_history`.
- v84 requires `upgrade-stonefellow-v84.sql`.
