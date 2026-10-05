# Cloud stem editor feature audit

The stem editor is a Cloud feature. This audit is inserted before Section 12 of the broader review.

## Review coverage

| Area | Current owner | Review and acceptance |
| --- | --- | --- |
| Project creation, library, ZIP/REAPER metadata and imports | studio-project-v77, direct-v79, upload-v24, stems-v30 | Current workspace authority; per-upload account/project binding; isolated inactive rows; stale import cannot replace newer active audio; project/library contracts. |
| Media authorization and loading | stem-media-v34, project-loader, canonical editor | Fan page/export and Agent access require current track visibility; session locks released before read-only media work; authenticated conditional media revalidation; decoded cache sharing and bounded preparation. |
| Waveforms | stem-waveform-v49 and editor waveform queue | Common WAV buckets use one bounded read rather than 192 seeks, preserving sampled peaks; browser fallback waits for media phase and reuses playback buffers; source revision invalidates waveform cache. |
| Playback, tempo, seeking and loops | master-clock-v201, transport-v200, scheduler-v202, time-stretch-v203, loop-planner-v204 | Play cancellation/generation checks; repeated Play cannot duplicate startup; Stop/seek cannot be undone by late decode; partial scheduling failures clear created sources; memory budget survives concurrent decode completion; seeking into a fade-out preserves its current gain. |
| Editing and undo/redo | canonical stem-editor, editing-v209, professional-editing-v210 | Clip split/trim/move, selections, fades, ripple and undo contracts exercise the active editor rather than the retired v108 copy. |
| Mixer, buses, automation, plugin delay and render state | editor, automation-v211, audio-engine-v215 | Runtime contracts retained; extension saves merge current stored JSON under the owned mix row lock; core saves preserve engine/MIDI/session fields. |
| Recording, punch, loop takes and recovery | studio-project-v77, recording-v212/v213 | Recording contracts retained; exact current archive marker protects finalized takes from late cleanup; files removed only when conditional deletion succeeds; recording replacement cannot overwrite a concurrently replaced target. |
| MIDI, instruments, composition and keyboard | midi-v217, composition-v218, keyboard-v219 | Permission, normalization, note/transport/composition runtime contracts retained; snapshot saves cannot erase current engine/session data. |
| Export, normalization and optional MP3 encoding | render-v214 and hardening | Fan visibility required; capability/failure and render contracts retained; authorized FFmpeg work releases browser session lock. LUFS and true peak retain estimate labels. |
| Session safety and private mixes | stem-mix, session-v216, storage helper | Owned row transaction; missing, foreign, damaged, oversized or failed mutation rolls back; attachments preserve other components. |
| Agent Chat, Agent Brain and command results | stem-agent-v105/v131, tools-v127/v128, command bus-v159 | Current track visibility plus tool authority; supported/unsupported command and verified result reporting tests; shared conversation/Brain contracts retained. |

## Runtime boundaries

Default Studio enables core editing and professional editing. Automation, advanced recording, export/audio-engine and session-safety modules use the existing advanced_runtime=1 opt-in; this audit does not change their rollout. MIDI uses its existing feature/permission configuration.

Projects over the existing 384 MiB decoded-audio budget remain on streaming transport. Browser waveform fallback above 128 MiB estimated per-stem PCM fails explicitly and offers the existing continue-without-waveform decision. WAV bucket reads are bounded to 256 KiB, with sampled-read fallback for larger buckets.

## Validation

New runtime tests execute the production scheduler and actual Play/Pause methods with controlled asynchronous decoders, and production storage/WAV/import SQL with real SQLite and fixtures. Existing feature regressions remain merge gates. CI validates PHP syntax and the full recovered baseline. Source-family cache tokens are regenerated from published bytes.

Software acceptance requires every applicable exact-head and merged-main check to pass, a merged PR, and a production ZIP matching the final Git source blobs. Browser audio quality and load latency on the owner's real songs remain acceptance exercises; automated fixtures do not measure subjective listening quality or HostGator throughput.

## Owner acceptance

1. Open WAV and MP3 projects; confirm correct waveforms, retry choices and no indefinitely pending loader.
2. Play, Stop during startup, seek repeatedly, switch songs and loop across edit boundaries. Confirm no late restart, doubled audio, glitches or unexpectedly silent stems.
3. Save/reload edited clips and a private mix; check undo/redo, mute/solo, buses and plugin settings.
4. In enabled advanced mode, save MIDI/engine/session state together, reload, record punch/loop takes, export WAV and test the MP3 capability result.
5. Check another account cannot open, import into, export or drive the Agent for an unshared workspace project.
6. Start two imports of the same project; stale completion must reject instead of combining uploads or erasing newer audio.
