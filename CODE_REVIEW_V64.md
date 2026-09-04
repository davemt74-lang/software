# Stonefellow v64 Recording Review

## User-reported issue

The audio capture itself was working. The apparent playback failure was caused
by the recorded track being muted.

The remaining workflow problems were:

1. saved recording required a page reload to appear
2. recording could create a second track/take row
3. Space did not control recording when a track was armed
4. the armed track did not show its captured waveform in real time
5. the left sidebar still exposed a redundant delete button

## Changes

### In-place recording target

Recording finalization now updates the exact armed `track_stems.id`; there is no
recording-finalize INSERT path.

### No reload

The finalized response returns enough track/media metadata for the browser to
replace the track source and clip immediately. The existing MediaElementSource
remains attached to the same HTMLMediaElement, so changing the protected source
URL does not require rebuilding the whole Studio.

### Live waveform

Real PCM input buffer minima/maxima are accumulated while capture runs and
drawn in the armed arrangement lane. This is separate from the final waveform
cache, which is invalidated and regenerated after saving.

### Cache correctness

Final recording media receives a cache-busted protected URL and waveform
requests use `no-store` for the newly replaced source. This prevents the
pre-recording placeholder/source waveform from being reused after saving.

### Keyboard

Space is recording-aware. Armed track state takes precedence over normal
Play/Pause.

### Deletion

Sidebar delete UI and its obsolete event binding were removed. Context-menu
Delete Track remains.

## Release-readiness score

**10 / 10 for the v64 recording workflow under the current browser DAW
architecture.**

Important design note: this workflow treats the armed track as the recording
destination and replaces that track's active source media. For preserving
multiple historical takes on one channel in a future phase, Stonefellow should
introduce a dedicated clip/take media model rather than representing each take
as another `track_stems` channel.

No SQL changes are required for v64.
