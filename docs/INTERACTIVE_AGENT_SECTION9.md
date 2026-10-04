# Interactive Agent — Section 9: missing conversation, transcription and meeting capabilities

## 9A — canonical speaker attribution evidence

9A is merged. Speaker attribution is now typed evidence rather than a display
label. Canonical sources distinguish unknown/acoustic heuristics, provider
diarization, verified voice, LiveKit tracks, manual corrections, visual
corroboration and account identity. Conflicting strong identity evidence fails
closed. Visual evidence may corroborate but never establish speaker identity or
authentication.

## 9B — shared-microphone diarization

HomeServer may optionally send a completed VAD audio chunk to ElevenLabs Scribe
v2 for batch diarization. The owner must explicitly enable Enhanced speaker
separation. HomeServer requests provider history/logging off, does not retain
the chunk in the transcription workspace, and falls back to local Whisper for
text if diarization fails.

Cloud receives only an explicitly shared completed HomeServer transcript. Import
preserves bounded speaker labels, timing and overlap metadata. It does not import
raw audio, provider speaker IDs, verified voice identity, account identity or
authentication authority. Any identity-capable evidence must be independently
re-verified on Cloud under the participant/consent rules.

The 9B source hash includes canonical speaker metadata. A narrow compatibility
path recognizes pre-9B Section 7 hashes only when the source is semantically the
same unidentified single-channel transcript.

## Acceptance

- provider word timelines become bounded speaker turns;
- overlapping turns retain independent start/end timing;
- provider speaker references never cross the canonical attribution boundary;
- speaker-library matches remain diarization labels, not person identity;
- Strict Local disables the cloud-assisted speaker-separation path;
- provider/network failure degrades to local Whisper rather than losing text;
- retries cannot mutate text or attribution for an existing segment key;
- deletion cascades speaker metadata;
- Cloud share/revoke remains text-only and owner controlled;
- Cloud rejects identity escalation, raw speaker references and label mismatch;
- old Section 7 imports remain retry-compatible.

## Remaining Section 9 work

9C adds continuous camera/voice corroboration and trusted local participant
mapping without allowing visual-only speaker identity. 9D completes recording
and meeting integration, including overlap-aware meeting intelligence and final
cross-system correction/review. Installed microphone/camera accuracy remains
Section 10 acceptance rather than a software-only claim.
