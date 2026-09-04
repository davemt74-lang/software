# Stonefellow v80 — Agent Chat Saved Songs

Agent Chat now exposes a **Saved Songs** workspace when the signed-in user has one or more saved tracks.

## Behavior

- The sidebar Saved Songs item is conditional and derives from the existing `track_favorites` records.
- Selecting Saved Songs opens the user's saved song catalog directly in the Agent Chat canvas.
- Catalog cards reuse the existing Stonefellow media endpoint, audio player, global Now Playing controls, and favorite endpoint.
- Saving or unsaving a track updates the Saved Songs catalog, count, and sidebar visibility immediately without a page reload.
- Removing the final saved track hides the Saved Songs nav item and safely returns the canvas to Player.
- The catalog is responsive for desktop, tablet, and mobile.

## Database

No schema migration is required. v80 reuses the existing `track_favorites` table.
