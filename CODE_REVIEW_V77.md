# Stonefellow v77 Code Review

## Requested changes

- fix the All Tracks audio-row layout
- keep artwork aligned to the full row height
- left-align the song title above the timeline
- move the `•••` action control to the far right
- remove per-track volume controls
- move volume to the persistent Player
- show only a volume icon until clicked
- show a vertical volume slider from that icon

## Implementation review

### Track-row layout

All Tracks now uses an explicit two-row CSS grid. The artwork and track number
span both rows, title/album occupy the upper content row, and the custom audio
transport occupies the lower content row. The `•••` menu spans both rows on the
far-right edge.

### Audio controls

The per-track `VOL` button and horizontal volume range were removed from the
dynamic inline-player markup and JavaScript control state. Inline playback still
includes Previous, Play/Pause, activity indicator, scrubber, elapsed/total time
and Next.

### Global volume

The persistent Now Playing footer owns volume. The speaker icon toggles a
vertical range popover. Volume applies to all loaded audio elements and any
later audio element decorated by Agent Chat. The setting is persisted per user.

### Queue markup cleanup

v76 had two elements using `id="chatNowPlayingQueue"`. v77 keeps a single queue
label/button and removes the duplicate ID.

### Mobile

The compact All Tracks layout remains responsive. Stem Studio remains hidden at
phone widths as required by v75.

### Database

No SQL changes are required for v77. The corrected v76 migration remains
included unchanged.

## Validation

- **107 PHP files** pass `php -l`
- **77 JavaScript files** pass `node --check`
- **26/26** targeted v77 layout/audio/regression checks pass
- requested-scope score: **10/10**
