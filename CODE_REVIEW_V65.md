# Stonefellow v65 Code Review — Producer Sharing

## Scope

Review covers the new Producer account type, per-track sharing, scoped editing
authorization, Producer Workspace, shared-track Stem Studio access, secure
source-file export, migration behavior, and regressions against the v64
recording/playback build.

## Review result

**10 / 10 for the v65 Producer-sharing scope.**

This score is based on source review, PHP/JavaScript syntax validation and
82 targeted access/control/regression checks. It is not a substitute for a
live login test against the deployed database.

## Access model

A Producer does not receive global `tracks.manage` access.

An assigned Producer can edit a track only when:

- the user is signed in
- the user has `producer.access`
- `tracks.producer_user_id` equals that user's ID

Global track managers continue to manage all tracks.

The scoped check is repeated at the page/API/media layer rather than trusting
the Producer Workspace list.

## Producer workflow

1. Admin creates a user with Account Type = Producer.
2. Admin opens Edit Track.
3. Admin selects an active Producer from Share with Producer.
4. Producer receives a notification.
5. Producer logs in directly to Producer Workspace.
6. Producer sees only shared tracks.
7. Producer can edit production metadata and open Stem Studio.
8. Producer can record/import/edit/save Studio work.
9. Producer can export the authorized MP3/WAV source files.
10. Reassigning or removing the Producer immediately removes access.

## Producer editing boundaries

Assigned Producers can edit:

- title
- album / collection
- description
- genre
- mood
- energy
- tempo
- keywords
- Stem Studio tracks/clips
- recordings
- plugins
- routing/buses
- automation
- fades/crossfades
- Saved Versions

Assigned Producers cannot change:

- project ownership
- publishing state
- visibility
- Producer assignment
- catalog-level project deletion
- unrelated tracks

## Export security

`admin-audio-export-v65.php`:

- requires an authenticated admin-shell account
- verifies the assigned track on every request
- verifies stem-to-track authorization on stem downloads
- accepts only MP3/WAV files
- rejects external master URLs
- validates local path containment
- streams files as attachments
- supports optional ZIP export when `ZipArchive` exists
- does not expose arbitrary server paths

The export dialog intentionally exports source media. It does not claim to
render the current post-plugin DAW mix.

## Migration

v65 adds:

- `producer.access`
- Producer default role permissions
- `tracks.producer_user_id`
- `idx_tracks_producer_updated`
- `fk_tracks_producer`

Both `/upgrade.php` and `upgrade-stonefellow-v65.sql` cover the schema update.

## Regression validation

Preserved from v64:

- live in-place recording
- Stop → Save / Discard
- Space-bar armed recording
- real-time recording waveform
- mute/solo playback repair
- coordinated safe seeking
- real clip waveforms
- fades/crossfades
- Track Inspector
- Open Project
- Load Song
- Save / Save As
- empty live-recording tracks
- right-click Delete Track
- no sidebar delete button

## Automated validation

- all packaged PHP files pass `php -l`
- all packaged JavaScript files pass `node --check`
- 82/82 targeted Producer/access/export/regression checks pass
- no direct stem-media currentTime regression
- no global Producer `tracks.manage` grant
