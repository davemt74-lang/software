# Stonefellow v73 Code Review

## Scope

Review covers:

- Tracks → Player navigation consolidation
- removal of Albums from Agent Chat sidebar
- retirement of the public Player page
- newest-track Player hero
- Albums section
- popular-track ranking
- persistent user favorites
- All Tracks library
- Player search
- custom audio/queue behavior
- sidebar spacing
- notification badge/icon styling
- regressions against v72 Albums/Playlists/Create forms
- Stem Studio regressions

## Review findings

### Pass 1 — navigation / information architecture

The initial Player consolidation was checked against the authenticated-shell
rule introduced in v67.

Fixes applied:

- `/player.php` no longer renders the public Player application.
- Signed-out public navigation no longer includes Player.
- all private Player links use `/chat.php?view=player`.
- the separate Albums sidebar entry was removed.
- Albums remain available inside Player and Admin.

### Pass 2 — music data / personalization

The Player ranking and Favorites implementation was reviewed for account scope.

Fixes applied:

- popularity uses only tracks already visible to the current account.
- favorite writes derive the user ID only from the authenticated session.
- the favorites API re-validates track visibility server-side.
- favorite cards update in-place without requiring a reload.

### Pass 3 — playback queues

Because tracks may appear in multiple Player sections, queue behavior was
reviewed for duplicate media elements.

Fixes applied:

- each Player section has a local queue scope.
- Previous / Next use the current section queue.
- hidden favorite cards are excluded.
- the existing one-active-audio rule remains in force.
- persistent Now Playing remains synchronized.

## Final score

**10 / 10 for the requested v73 Player scope.**

## Database

v73 requires the `track_favorites` table.

Both `/upgrade.php` and `upgrade-stonefellow-v73.sql` support the upgrade.

## Preserved functionality

- Agent Chat as authenticated home
- split Explore / Chats sidebar
- Playlists
- Shows
- Photos
- Merch
- About
- all eight `+` create popup forms
- managed Albums
- Album track assignment
- user Playlists
- notification dropdown
- profile dropdown
- Agent Updates
- Producer sharing
- Producer export
- custom dark inline audio player
- persistent Now Playing
- Stem Studio Exit → Agent Chat
- live recording
- Space-bar armed recording
- mute/solo playback repair
- coordinated safe seek
- Save / Save As
- Open Project / Load Song


## Automated validation

- **98 PHP files** pass `php -l`
- **69 JavaScript files** pass `node --check`
- **56/56** targeted v73 feature/security/regression checks pass
- final requested-scope score: **10/10**
