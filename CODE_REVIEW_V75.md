# Stonefellow v75 Code Review

## Scope

- hide Stem Studio actions on mobile phone layouts
- preserve desktop Studio behavior
- fix Agent Chat header dropdown stacking over the Player canvas

## Stem Studio mobile behavior

At `max-width: 820px`, both Chat and Admin CSS hide Studio-routed controls.

The implementation uses both explicit `.desktop-studio-only` classes on the
main rendered Studio buttons and a defensive `href*="/admin/stems.php"`
selector so newly rendered Studio links cannot accidentally remain visible on
phone layouts.

Desktop layouts are unaffected.

## Header stacking

The authenticated top bar now uses a higher stacking context than the scrolling
canvas. Create, Notifications and Profile dropdowns use an even higher z-index,
so positioned Player artwork/cards cannot paint over them.

## Regression protection

Preserved:

- Agent Chat Player
- newest/popular/favorite/all-track sections
- custom dark audio player
- persistent Now Playing
- transparent overlay Chat footer
- Albums management
- Playlists
- all eight inline Create forms
- Agent Updates
- Producer sharing
- Producer export on desktop
- Stem Studio live recording on desktop
- mute/solo playback repair
- safe coordinated seeking

## Database

No SQL changes are required.
