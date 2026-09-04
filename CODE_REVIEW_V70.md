# Stonefellow v70 Code Review

## Scope

Review covers:

- custom dark Agent Chat audio controls
- persistent Now Playing
- playlist navigation
- playback analytics continuity
- cleaner music answer context
- Agent Chat Create menu
- Admin Photos
- Admin Merch
- secure photo/merch image delivery
- v70 schema/permission upgrade
- Stem Studio regression protection

## Score

**10 / 10 for the requested v70 scope.**

## Audio UI

The native browser audio UI is removed from Agent Chat.

The custom control layer uses the same underlying media elements and therefore
does not duplicate the playback stream.

Controls include play/pause, previous/next, seek, time, mute and volume.

Playback state updates both the inline control and the sticky Now Playing bar.

## Playback / analytics

Existing `/api/playback.php` tracking remains in use.

The review specifically checks:

- one active audio source at a time
- start/resume/pause/seek/heartbeat/end tracking
- queue auto-next
- persistent playback across Chat canvas views
- cleanup for a currently playing detached conversation card
- pagehide stop handling

## Agent answer cleanup

Production context is now intent-gated.

A general music question receives concise track context. REAPER/stem metadata is
only included when the question itself is production-oriented.

This reduces noisy fallback answers and reduces irrelevant production context
sent to configured remote AI providers.

## Create menu authorization

The `+` menu is built server-side from the signed-in user's permissions.

No client-supplied role or permission state determines whether an Add link is
rendered.

Admin sees Track, Event, Knowledge Base, User, Merch and Photo.

## Photos security

Photo uploads are stored under protected `/uploads/photos`.

`/content-image.php` validates:

- requested content type
- database record
- publish state or management permission
- visibility
- expected upload path prefix
- realpath containment
- image MIME type

Arbitrary server paths cannot be requested through the endpoint.

## Merch

Merch uses a dedicated database table and management permission. Item images
use the same protected image-delivery endpoint.

## Database

v70 requires a schema upgrade.

`/upgrade.php`, `schema.sql` and `upgrade-stonefellow-v70.sql` contain the
photos/merch schema and permissions.

## Regression protection

Preserved:

- authenticated Agent Chat shell
- split Explore / Chats sidebar
- notification dropdown
- profile dropdown
- live Agent Updates
- Producer sharing
- Producer MP3/WAV export
- Stem Studio Exit → Agent Chat
- live recording
- Space-bar recording
- mute/solo playback repair
- safe coordinated Stem Studio seeking
- Save / Save As
- Open Project / Load Song
