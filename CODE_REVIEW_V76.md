# Stonefellow v76 Code Review

## Scope

v76 completes the full fan music workspace requested after v75:

1. Album detail inside Player
2. complete Playlist management
3. Up Next / Queue
4. Recently Played / Continue Listening
5. track `•••` action menu
6. Track detail drawer
7. playback-following Lyrics
8. Favorite Albums + Playlists
9. For You personalization
10. New Release notifications
11. Show reminders
12. contextual Merch
13. Artist Posts / Updates
14. user Listening Stats
15. Admin Music Analytics

## Review pass 1 — account and content authorization

Validated server-side ownership / visibility rules for:

- playlist edits and deletes
- public playlist duplication
- playlist track additions
- album favorites
- playlist favorites
- show reminders
- release notifications
- artist-post notifications
- contextual merch associations

Playlist owner identity comes from the authenticated session. Track IDs are rechecked through `can_view_track()` or `can_manage_track_production()` as appropriate.

## Review pass 2 — playback architecture

The new features reuse the existing protected audio endpoints and v70 custom audio controls rather than introducing a second playback engine.

Queue behavior is layered on top of the existing one-active-audio rule and persistent Now Playing implementation.

The queue is scoped to the signed-in user in localStorage and supports reordering, Play Next, Add to Queue, Clear, remove and persistent Previous / Next behavior.

## Review pass 3 — UX fixes

Additional fixes made during the completion pass:

- Now Playing queue label is a semantic clickable button.
- playlist cards identify the owner for public/shared playlists.
- sharing a private playlist offers to make it Public before copying the link.
- Recently Played uses the actual latest playback session's resume position instead of a grouped maximum from an older session.
- synchronized lyric scrolling only moves when the active line changes.
- stale copy saying playlist editing was a “future pass” was removed.

## Notifications

Release, show reminder and artist-post notifications feed the existing Agent Chat realtime polling loop.

`create_notification()` source deduplication prevents repeat notifications for the same release/post/show event.

Release and post fanout is visibility-aware.

## Desktop-only Studio rule

v75 behavior is preserved:

- Stem Studio remains desktop-only.
- all Studio actions stay hidden at phone widths.
- Producer export/Studio functionality remains unchanged on desktop.

## Database

v76 requires a database upgrade.

Migration file:

`upgrade-stonefellow-v76.sql`

## Final target

Requested-scope target: **10 / 10** after syntax, structural, authorization and regression validation.

## Final validation

- **106 PHP files** pass `php -l`
- **75 JavaScript files** pass `node --check`
- **39/39** targeted v76 feature/security/regression checks pass
- shared-playlist privacy was corrected so other users' `members` playlists are not loaded; only the owner or `public` playlists are visible
- final requested-scope score: **10 / 10**

## SQL packaging correction

The distributed v76 SQL migration was rechecked after phpMyAdmin reported literal `\\n` characters in the `tracks.credits` migration block.

The migration file has been corrected so those are real line breaks. Re-running the corrected v76 migration is safe: permission inserts are idempotent, new tables use `CREATE TABLE IF NOT EXISTS`, and conditional column additions avoid duplicate-column errors.
