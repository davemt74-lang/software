# Universal Editor Agent Direction

The Editor Agent is not the Chat Agent and is not a Video-only agent.

It shares Agent Brain/context, conversation identity, LISTEN state, and voice infrastructure with the broader agent, but its responsibility is safe, verifiable execution across creative tools.

## Target

One Editor Agent orchestrator consumes registered surface adapters. Current required surfaces are:

- Stem Editor
- Video Editor
- Artist Listening / Transcription

Future creative tools register the same contract rather than introducing another editor-specific agent runtime.

Each adapter must expose complete machine-readable:

- command inventory
- selection inventory/current selection
- state snapshot
- execute(command)
- verify(command, before, after)

Commands are namespaced by surface so similarly named controls remain deterministic, for example `stem.track.mute`, `video.clip.split`, and `transcript.selection.summarize`.

The later cognitive loop will operate on these capabilities using inspect -> plan -> act -> observe -> verify -> replan/complete. It must reason over tool/state contracts rather than DOM selectors.
