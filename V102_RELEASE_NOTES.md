# Stonefellow v102

## Agent voice

- Adds a server-side ElevenLabs text-to-speech proxy for Agent responses.
- Keeps the ElevenLabs API key encrypted in Stonefellow settings and out of browser configuration/runtime payloads.
- Adds an ElevenLabs card to **Admin → AI / API Settings** with API key, Voice ID, and model controls.
- Defaults to ElevenLabs George (`JBFqnCBsd6RMkjVDRZzb`) with `eleven_v3`; both are configurable.
- Main Agent Chat, Video Editor Agent, and Stem Studio Agent use premium ElevenLabs speech when configured.
- Existing browser speech synthesis remains the automatic fallback if premium speech is unavailable.
- Existing barge-in behavior can interrupt premium or browser speech.

## Persistent LISTEN mode

- Closing the Agent Chat drawer in Video Editor or Stem Studio no longer disables an active voice conversation.
- Speech recognition continues while the drawer is hidden and automatically resumes after a response.
- The editor header AI control remains the visible conversation-state indicator:
  - blue = listening
  - green = thinking / preparing response
  - red = responding
- Voice mode is still explicitly stopped when the user turns voice conversation off or leaves the page.

## Metronome

- Adds a dedicated metronome volume slider to the existing metronome settings.
- Default volume is 75%.
- The volume is remembered with the existing per-user/per-track metronome state.
- Volume scales the metronome click gain without changing the Studio master/output volume.
- Volume edits participate in the existing manual Studio edit ledger.

## Security / failure behavior

- ElevenLabs credentials are accepted only by the authenticated AI settings API and encrypted with Stonefellow's existing secret-storage helpers.
- The voice proxy requires an authenticated user with `chat.access` and a valid CSRF token.
- Voice ID/model values are allowlisted/validated before use.
- ElevenLabs requests are server-to-server over HTTPS with redirect following disabled and TLS verification enabled.
- Provider failures return a safe error and fall back to browser speech in the Agent UI.

## Deployment check

1. Open **Admin → AI / API Settings**, enter the ElevenLabs API key, confirm Voice ID/model, and save.
2. Open Agent Chat in voice mode and verify a spoken response uses premium voice.
3. In Video Editor and Stem Studio, enable voice mode, close the Agent drawer, and confirm the AI control remains blue while listening.
4. Speak a command with the drawer closed; confirm green while processing and red while the spoken response plays.
5. Interrupt the response and confirm listening resumes.
6. Open Stem Studio metronome settings, change volume, audition the click, reload, and confirm the saved level is restored.
