# Stonefellow v67 Code Review — Authenticated Agent Shell

## Scope

Review covers authenticated routing, public-page lockout, Agent Chat as login
destination, Chat notification/profile dropdowns, removal of Add Knowledge,
and the Account page redesign inside the Chat shell.

## Final score

**10 / 10 for the v67 authenticated-shell scope.**

## Routing

- Agent Chat is checked first by `login_destination()`.
- `chat.access` is a core authenticated capability for every recognized
  account type.
- Home/About/Shows/Contact redirect authenticated users.
- Player remains accessible.
- Login already redirects authenticated users.
- Player's authenticated header hides public logged-out navigation.

## Agent Chat menus

Notification and profile controls are proper buttons with:

- `aria-expanded`
- `aria-controls`
- independent dropdown elements
- outside-click close
- Escape close
- mutual close behavior

The notification dropdown preserves the full Notifications page for history and
uses recent notification data from the existing notification system.

## Account page

The old public-site Account layout was removed.

The new page uses the same Chat shell dimensions and visual language while
keeping all prior profile/password backend logic intact.

No account form endpoints or password security rules were weakened.

## Knowledge

Only the visible Add Knowledge shortcut was removed from Agent Chat.
Admin Knowledge and Chat Knowledge retrieval remain intact.

## Regression constraints

Preserved:

- Agent Updates 3-second polling
- Producer-sharing updates
- Supervisor listening updates
- playable Chat audio
- conversation delete controls
- Producer Workspace
- Producer exports
- Stem Studio live recording
- mute/solo playback repair
- Save / Save As
- Load Song / Open Project

## Database

No database migration is required.
