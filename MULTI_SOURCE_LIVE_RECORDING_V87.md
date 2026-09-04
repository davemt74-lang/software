# Stonefellow v87 — Multi-Source Live Recording

- Master LIVE MIX capture prints performed mute/solo/fader/bus/effect changes into a new synchronized WAV take.
- Track Inspector LIVE RECORDING captures selected post-fader track outputs as new WAV takes.
- Existing armed microphone recording can run simultaneously with live output captures.
- All captures share the same Studio timeline start and AudioContext clock.
- Live output captures auto-save as new `track_stems`; microphone capture keeps the existing save/discard flow.
- Live recording start/finish events are audited through Agent Brain tool history.
- Stem Studio and Video Editor have bottom Agent Brain drawers (max 500px) with sticky composers and voice continuity.
- Agent Chat editor links preserve active voice mode and conversation id.

No database migration is required.
