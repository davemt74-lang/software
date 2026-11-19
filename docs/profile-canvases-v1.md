# Public Profile Canvases

The public Profile has a **Canvases** gallery tab. Selecting a card navigates to a full-page Canvas at `/<username>/canvas/<published-slug>`. The Canvas fills the viewport and owns its content scroll. The existing Profile Agent widget and a small return-to-Profile control sit outside the isolated Canvas runtime.

## Owner flow

1. Open **Canvases** and choose a saved Canvas, or create one in Chat.
2. Open **Edit app → Public publishing**. Activate and explicitly publish the version you want visitors to see.
3. Check **Show on my Profile**, then select **Save Profile visibility**.
4. Use **Open full-page Profile Canvas** to review the public page. It also appears in the Profile's **Canvases** tab.

The Profile needs a public username and the account must be active. A newly published app is not listed automatically. The owner can remove a listing through the same control. Draft changes stay private until the owner publishes an update through the existing publishing flow.

Unpublishing the Canvas or making the Profile private makes the Profile Canvas unavailable. The saved selection remains visible to the owner, and the page returns if both are made public again. Explicitly removing **Show on my Profile** keeps it unlisted after a later publication.

## Shared chat

`profile-canvas.php` selects a full-page mode in the canonical `profile.php` renderer. Both surfaces use the same Profile Agent markup, active owner Agent, session token, visitor namespace and scripts. The visitor's conversation can continue across the owner's Profile and all its canvases. Image collections, photo editing, attachments, live text, calls and checkout retain their existing availability rules. Owner views retain the existing preview behavior.

The Canvas never receives chat credentials or conversation state. It uses the existing opaque sandbox with an empty app-data object, disabled state saves and the same restricted worker runtime. Only the verified published artifact and documented public source projections enter the Canvas payload.

## Publication and visibility

The owner API uses the existing current-user, Agent and conversation checks, plus CSRF. A save must match both the reviewed publication version and current Profile listing version. Public gallery cards use the published title, and full-page reads verify current listing, publication, owner, account status and public Profile username. Private Canvas titles, thumbnails, source commands and unpublished revisions do not enter the gallery.

The existing public-publication restrictions still apply. For example, a private Media Library image cannot become public by adding a Canvas to the Profile.

## Deployment

Run **Database Upgrade** after installing the code. It creates `vp3_profile_canvases_v1` after the existing Canvas tables. The upgrade does not opt in existing publications or modify their contents. Before upgrade, ordinary Profiles still render, public Canvas attachments are unavailable, and owner controls explain the upgrade requirement.

## Verification

The dedicated checks are:

- `tests/profile-canvases-v1.php`: storage, ownership, publication proofs, visibility revocation and deletion; SQLite and the existing native MySQL gate.
- `tests/profile-canvases-api-v1.mjs`: real HTTP requests through the production API, including CSRF, current identity, exact version proofs and bounded errors.
- `tests/profile-canvases-owner-v1.mjs`: the complete owner module, explicit saves, safe links, recovery and stale-context handling.
- `tests/profile-canvases-browser-v1.mjs`: production template, gallery navigation, full-page isolated runtime, desktop/mobile layout, chat continuity and owner preview. `--render-only` runs the PHP template assertions without a browser.
- `tests/upgrade-database-connection.php`: existing upgrade cases plus missing Profile Canvas storage.

These run through the existing Section 14 workflow; browser screenshots are saved as `profile-canvases-browser-evidence`.
