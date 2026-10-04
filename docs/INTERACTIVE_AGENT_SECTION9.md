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

## 9C — camera/voice fusion boundary

9C extends the shared attribution contract so an opaque participant reference
may be carried by visual corroboration for comparison with trusted voice
evidence, while visual evidence remains incapable of establishing identity by
itself. Strong camera/voice disagreement now fails closed: participant identity
is cleared and identity confidence becomes zero rather than allowing a
conflicted speaker assertion to survive.

Diarization provenance is derived from the complete evidence set, so a
verified local voice match can remain the winning identity evidence while the
turn still records that its speaker separation came from provider diarization
or a separate LiveKit track.

HomeServer performs the actual local participant matching. Its Tracky voice
profiles and visual descriptors remain local. When the owner explicitly shares
a completed HomeServer transcript, the paired relay strips local Tracky
participant references, verified-identity flags and camera corroboration before
Cloud import. Cloud receives only generic speaker separation/timing/overlap and
continues to reject raw speaker references or identity escalation.

Cloud does not treat HomeServer browser-local voice/camera matching as account
authentication or Cloud participant verification. Any future Cloud identity
binding still requires the existing Cloud participant/consent rules.

## 9C acceptance

Canonical PHP and Python tests require visual-only non-identity, opaque
voice/visual corroboration, strong visual-conflict fail-closed behavior and
retention of diarization provenance through identity fusion. HomeServer focused
tests additionally prove local-only enrollment/matching and paired-relay
identity stripping. Installed device accuracy remains Section 10 certification.

## Remaining Section 9 work

### 9C review repairs

Canonical fusion clears a verified-voice identity when any evidence row marks
overlap, including mixed provider/voice evidence. It preserves diarization
provenance and leaves independently isolated LiveKit track attribution intact.
PHP and Python parity tests cover both overlap sources. HomeServer additionally
repairs camera startup cleanup, enrollment cancellation/clear transactions,
ambiguous visual evidence and participant picker interaction with behavioral
and real Chromium IndexedDB tests.

9D completes recording and meeting integration, including overlap-aware meeting
intelligence and final cross-system correction/review. Installed
microphone/camera accuracy remains Section 10 acceptance rather than a
software-only claim.
