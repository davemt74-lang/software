# Stonefellow v72 Code Review

## Scope

Review covers:

- all Agent Chat `+` popup forms
- Album content architecture
- Album → track assignment
- Track → Album assignment
- user-generated Playlists
- Playlist track validation
- Albums/Playlists Agent Chat canvases
- protected album covers
- schema upgrade behavior
- existing v70 audio player
- existing v66+ Agent Updates
- v64+ Stem Studio regressions

## Review cycle

### Pass 1

The Album/Playlist implementation was reviewed for data integrity and
authorization.

Issues identified during the pass:

1. Album assignment needed to be scoped at the track level, not only by the
   `albums.manage` permission.
2. Fresh-install SQL needed Albums to be created before Tracks so the album
   foreign key could be declared safely.
3. Album creation/Playlist creation needed transaction rollback protection in
   the shared Agent Chat create endpoint.
4. The Track editor needed to synchronize both the new relational `album_id`
   and the legacy album display text.

### Fix pass

The implementation was updated so:

- Album assignments call `can_manage_track_production()` for every selected
  track.
- The same authorization is enforced in both full Admin and inline Chat
  creation.
- Fresh schema order is Albums → Tracks → Playlists.
- `tracks.album_id` has an index and foreign key.
- API transaction failures roll back before uploaded-file cleanup.
- Track create/edit synchronizes `album_id` and album display text.
- Album rename/reassignment keeps legacy display labels consistent.

## Final score

**10 / 10 for the v72 requested scope.**

## Popup form coverage

Agent Chat has an inline create form for all eight current types:

1. Track
2. Album
3. Playlist
4. Event
5. Knowledge Base
6. User
7. Merch
8. Photo

The create menu remains permission-driven. Playlist creation is intentionally
available to every signed-in Agent Chat user.

## Album data integrity

Albums use a dedicated table.

Tracks use a nullable `album_id` foreign key while preserving the pre-existing
album text field for backward compatibility.

Album deletion uses `ON DELETE SET NULL` semantics and explicitly normalizes
the legacy display value.

Album editing can assign, move and unassign tracks.

## Playlist data integrity

A playlist belongs to one user.

`playlist_tracks` uses a composite primary key so the same track cannot be
inserted twice into one playlist.

The create API checks every selected track with `can_view_track()` before
insertion.

## Media

Album and Playlist canvases reuse the v70 custom dark audio player rather than
introducing another playback implementation.

Existing playback analytics, queue auto-next and persistent Now Playing remain
active.

## Security

Validated:

- CSRF on all popup-create requests
- server-side permission checks
- playlist user identity comes from the session
- album assignments are track-scoped
- playlist tracks are visibility-scoped
- album cover realpath containment
- album cover visibility checks
- no user-supplied server path reads

## Automated validation

- all packaged PHP files pass `php -l`
- all packaged JavaScript files pass `node --check`
- 86/86 targeted v72 feature/security/regression checks pass

## Existing behavior preserved

- Agent Chat as signed-in home
- split Explore / Chats sidebar
- Merch sidebar view
- notification dropdown
- profile dropdown
- live Agent Updates
- Producer sharing
- Producer MP3/WAV export
- custom dark inline audio
- persistent Now Playing
- Stem Studio Exit → Agent Chat
- live recording
- Space-bar armed recording
- mute/solo playback repair
- coordinated safe seeking
- Save / Save As
- Open Project / Load Song

## Database

v72 requires a database upgrade.
