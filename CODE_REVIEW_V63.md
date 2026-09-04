# Stonefellow Code Review — v63

## Scope

Review covers the complete packaged PHP/JavaScript source for syntax and the
active Stem Studio playback, recording, editing, persistence, media-security,
and shared-host paths for functional/regression structure.

The final score is a release-readiness score, not a claim that browser/USB
hardware behavior can be physically tested without the target Focusrite and
browser.

## Pass 1 — v61 baseline

**Score: 7.8 / 10**

Findings:

1. Recording ARM existed in mixer channels but not the left track sidebar.
2. Record could be invoked without a valid armed recording target.
3. Server recording start did not require an armed target stem.
4. Stop Recording immediately finalized/reloaded instead of asking Save/Discard.
5. Capture had no client/server byte-count verification before finalization.
6. No explicit indication whether actual input signal was captured.
7. A track muted/soloed before first playback could leave
   `HTMLMediaElement.muted` set after Web Audio took over.
8. Mute/Solo while playing recalculated clip gain using stored `position`
   rather than the live transport position.
9. Repeated recordings could leave old incoming ScriptProcessor connections.
10. Track deletion/re-arm was not locked while a take was recording or awaiting
    final save.

### Build fixes

- added synchronized sidebar ARM controls
- required ARM in client and API
- added Stop → Save Recording / Discard workflow
- added recording status API
- added exact PCM byte verification and signal peak reporting
- repaired native media mute/volume carryover
- repaired live-position mute/solo gain calculation
- synchronized Inspector/sidebar/mixer states
- removed stale recording processor input connections
- protected recording targets during capture/finalization

## Pass 2 — v62 repair review

**Score: 9.6 / 10**

Remaining findings:

1. Timeline seek/edit controls could still be used during capture.
2. An active loop could rewind playback while PCM capture continued linearly.
3. The Play transport could be changed independently during an active take.
4. Leaving the page with an active or stopped-unsaved take needed explicit
   navigation protection.
5. Rapid mute/solo transitions could leave prior gain ramps scheduled briefly.

### Build fixes

- added recording transport lock
- blocked seeks during count-in/capture/pending save
- disabled arrangement editing during recording
- disabled loop rewinds during active capture
- protected unsaved captures with `beforeunload`
- cancelled stale output-gain schedules before mute/solo transitions

## Pass 3 — v63 final review

**Score: 10 / 10 under the defined release-readiness rubric**

### 1. Playback / transport — 10 / 10

- coordinated seek remains intact
- no direct `stem.audio.currentTime = ...`
- no direct crossfade decoder currentTime writes
- live playback position used for mix state
- native mute/volume state is cleared when Web Audio owns routing
- secondary crossfade decoder is normalized
- recording blocks user seek
- recording cannot loop-rewind
- recording transport lock is explicit
- standard render/sync path remains intact

### 2. Mute / solo / mix — 10 / 10

- any-solo gating is deterministic
- track mute is respected
- current automation volume is used when toggling state
- stale gain schedules are cancelled before state changes
- pre-Web-Audio mute state cannot poison later playback
- all Mute controls synchronize
- all Solo controls synchronize
- Track Inspector state synchronizes
- mute persists
- solo persists

### 3. Recording integrity — 10 / 10

- client requires ARM
- server requires ARM
- input MediaStreamTrack must be live
- PCM16 conversion remains deterministic
- chunks remain sequential
- server capture status is verified
- client/server PCM bytes must match exactly
- input peak is tracked
- USB/interface disconnect is detected
- retired processor input links are disconnected

### 4. Recording UX — 10 / 10

- sidebar ARM
- mixer ARM
- synchronized ARM state
- Record disabled until ARM
- count-in
- metronome
- punch range
- Stop opens Save Recording
- Discard removes capture
- saved recording is selected after reload

### 5. Editing / fades / crossfades — 10 / 10

- clip gain
- clip mute
- fade-in handles
- fade-out handles
- auto crossfade
- dual protected media decoders
- real clip envelopes
- split
- delete section
- undo

### 6. Save / project persistence — 10 / 10

- Save
- Save As
- Saved Versions
- Open Project
- account-scoped projects
- fade state persistence
- recording setting persistence
- track input persistence
- stable localStorage key preserved
- active/unsaved recording navigation protection

### 7. UI / alignment — 10 / 10

- sample-rate warning removed
- sidebar 54px pre-track alignment offset
- 48px matching rows
- matching automation expanded rows
- sidebar ARM styling
- Save Recording modal styling
- Track Inspector
- per-track input
- Open Project grid
- full Load Song workflow retained

### 8. Security / permissions — 10 / 10

- Stem Studio remains `tracks.manage`
- project API remains CSRF protected
- CSRF field remains `csrf_token`
- recording state is user scoped
- protected stem media retained
- management-only preview retained
- microphone Permissions-Policy retained
- Apache microphone policy retained
- recording token validation retained
- recording target must belong to current project track

### 9. Compatibility / resilience — 10 / 10

- shared-host mbstring fallback retained
- real `getUserMedia()` remains permission authority
- HTTPS requirement retained
- Focusrite-family detection retained
- explicit channel mode has browser fallback
- pitch preservation retained
- browser waveform decode fallback retained
- protected Range media playback retained
- empty projects initialize
- no new schema dependency

### 10. Build / regression — 10 / 10

- active v63 Studio JS
- active v63 project API
- polarity control remains removed
- mono control remains removed
- BUS remains
- New Track remains empty live-recording track
- Import Media remains separate
- Load Song remains
- real waveform endpoint remains
- `syncAll(true)` remains absent

## Final validation

- PHP: all packaged PHP files pass `php -l`
- JavaScript: all packaged JavaScript files pass `node --check`
- Release-readiness structural/regression rubric: **100 / 100 checks**
- SQL: **no new SQL required**
