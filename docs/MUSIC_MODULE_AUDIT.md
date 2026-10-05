# Cloud music module audit

This is a review of the complete music module, discovered through routes, shared catalog models and their dependencies. It follows the completed stem-editor audit; it is Cloud-only.

| Area | Reviewed authority | Result and changes |
| --- | --- | --- |
| Player entry, inline playback, queue and controls | player.php → chat.php, chat.js | Canonical canvas retained. One pending analytics start per audio element; stopped generations close late starts; buffering is excluded and the final playing interval is retained on pause/end. Tracking requests time out and retry without blocking audio. |
| Catalog lists, detail, protected media | functions.php, media.php, artist-track-audio.php | Source precedence agrees across lists/details/media. Unpublished sources cannot reappear through stale catalog shadows. Artist artwork metadata and historical reserved references remain resolvable. Media releases the PHP session lock and supports GET/HEAD and validated single byte ranges. |
| Accounts, entitlement, workspace and Team | music-workspace, plugin v320, resources v330, Team v350, permissions | Existing individual account/entitlement and active Team projection retained. Explicit inaccessible workspaces fail instead of selecting another workspace. Private producer media requires current track assignment. Platform admin editors are restricted to Admin; Artist/workspace owners use their scoped writers. |
| Artist profile and publishing | profile.php, profile-agent, artist workspace, Artist CMS | Canonical profile and visibility retained. Old raw music-path POST routes are blocked. Profile bio and image dimensions are bounded. Both music editors now use the same transactional catalog writer. |
| Song uploads and replacement | artist music v185, music-catalog | Supported MIME/extension, real byte count, workspace-owned private storage. Save/backing synchronization is atomic; failed sync rolls back catalog data and cleans new audio. Stale edit revisions reject. Old audio remains while a Studio track references it. New saves create one real backing identity immediately. |
| Albums, ordering and artwork | music-catalog, v185, source albums v330 | Album rename/deletion propagates to backing tracks; Studio history survives catalog removal. Artwork is selectable in the workspace library and inherits from albums. Platform and artist albums are merged; same-title albums are matched by IDs/workspace. Backing insertion includes the required lyrics column on fresh installs. |
| Media library and shared images | artist-media, content-image, music-media | Current workspace owner/manager authority replaces global draft-photo grants. Used song/album/post artwork cannot be deleted. Photo/profile replacement compares the current path. Actual image size and pixel dimensions are bounded. Protected images require revalidation. |
| Favorites, personal library, recommendations | favorites v73, player v76 | Existing user-owned stores retained. Artist favorites follow a catalog's source identity after Studio materialization. Source-bound removal also clears the historical platform favorite. |
| Playlists | player-library v76, music-catalog, Chat model | Mixed native/platform order is preserved on read and duplicate; drafts are filtered. Add/update lock the owner playlist and compute the tail across both stores. Non-public playlists are owner-only for favorite/duplicate, matching the rendered catalog. Input size is bounded. |
| Listening analytics and completion | playback API, canonical Chat audio tracking | Historical native IDs resolve to a real track FK. Session events bind to account and listener cookie, lock the session row, commit event/session together and cap elapsed credit. Closed sessions cannot resume; seeking to the end does not manufacture completion. |
| Release plans, Agent/proactive and linked artist content | music-releases, v331/v332, Artist posts/shows/merch, public profile | Existing workspace-bound release and owner-only external provider authority reviewed and retained. Music changes keep the shared source graph and current Agent/Studio routes. Release compatibility, Artist CMS and full recovered regressions remain merge gates. |

## Validation and boundaries

The new PHP suite uses actual production writers, ownership guards, catalog readers and range parsing against SQLite fixtures, including an enforced no-default lyrics column and a forced backing failure. New Node tests execute the production Chat tracking functions with controlled asynchronous responses. Retained music, media, profile, CMS, catalog, workspace and release contracts now check their current owners and the active recovery workflow; obsolete exact UI wording is aligned with the canonical profile.

GitHub validates PHP syntax, the new module suites and the full recovered baseline. Software acceptance requires applicable exact-head checks, merge, merged-main checks, and a production ZIP verified against all packaged Git blobs. It does not certify audio quality, browser autoplay behavior or host upload/network throughput without real deployment exercises.

No new database migration, media transcoder, hosting provider, subscription model or advanced Studio rollout is introduced. Existing MP3, M4A, WAV and OGG support remains subject to browser codec support and host upload limits. Existing Studio history remains when a catalog entry is removed.

## Owner acceptance

1. As a Music Workspace owner, upload each supported audio format, create an album, select/reuse cover art and publish. Verify Player, detail and profile agree.
2. Edit album titles, track metadata and artwork; replace audio, then reload Player and Studio. Submit a stale edit and verify it rejects.
3. Play/pause, seek near the end, buffer on a slow network, change queue tracks and leave the page while analytics starts. Listen for playback issues and check history/qualified completion.
4. Mix native artist and platform tracks in a playlist, reorder, duplicate and add more songs. Verify order and favorites survive opening Studio.
5. Test owner, active manager, assigned/unassigned producer, unrelated account, anonymous visitor, suspended/removed teammate and disabled plugin. Verify private drafts/artwork and explicit foreign workspace URLs do not leak or mutate another workspace.
6. Remove a catalog song or album and verify it disappears publicly while Studio history remains. Used artwork must require reassignment before deletion.
7. Test large/unsupported uploads, server upload limits, HEAD requests and seeks. Confirm useful failure/retry behavior on the deployed host.
