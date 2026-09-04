# Stonefellow PHP Site

Stonefellow is now a PHP/MySQL site with public artist pages, a database-driven player, account types, role permissions, protected track/media delivery and an administration dashboard.

## Public / account pages

- `index.php` — simple landing page with image hero and link cards. The hero no longer contains the Stonefellow title/tagline/buttons.
- `about.php` — hero image + editable artist bio
- `player.php` — music player; only shows tracks the current visitor/account can view
- `shows.php` — upcoming live dates
- `contact.php` — contact form saved to the database
- `login.php` / `logout.php` — user authentication
- `account.php` — signed-in user dashboard
- `investor.php` — permission-protected investor area
- `media.php` — protected audio/cover streaming endpoint

The Stonefellow logo/brand on every public, player and admin layout links to `index.php`.

## User account types

- Fan
- Manager
- Supervisor
- Investor
- Admin

Default access:

- **Fan** — account dashboard and role-restricted fan media
- **Manager** — account + admin dashboard + tracks + shows + artist/profile management
- **Supervisor** — Manager access plus contact-message management
- **Investor** — account + private investor area + investor-only media
- **Admin** — full access, including Users and Permissions

Admins can change the non-admin role permissions in `admin/permissions.php`.

## Backend / Admin

- `admin/index.php` — dashboard
- `admin/tracks.php` — add/edit/delete/publish tracks, upload audio and cover images, set **Who can view?**
- `admin/shows.php` — manage live dates
- `admin/messages.php` — contact inbox
- `admin/profile.php` — edit artist bio, tagline, email and external links
- `admin/users.php` — create/edit/deactivate/delete users and assign account types
- `admin/permissions.php` — role-permission matrix

Admin permissions are always enabled and cannot be removed.

## Track / media visibility

Every track has a **Who can view?** dropdown:

- Public — Everyone
- All Signed-In Users
- Fans Only
- Managers Only
- Supervisors Only
- Investors Only
- Admins Only

Admin accounts can view all track/media entries. Role-only media is limited to the selected account type plus Admin.

Uploaded audio and covers are delivered through `media.php`, which verifies the visitor's account before streaming the file. Apache `.htaccess` rules deny direct access to `/uploads` so restricted files cannot be bypassed by copying the upload URL.

## Database tables

- `users`
- `settings`
- `permissions`
- `role_permissions`
- `tracks`
- `shows`
- `contact_messages`

The complete fresh-install schema is in `schema.sql`.

## Fresh install

1. Upload the package to the site root.
2. Copy `config-example.php` to `config.php`.
3. Enter the MySQL/MariaDB credentials in `config.php`.
4. Visit `/setup.php`.
5. Create the first Admin account.
6. Setup automatically locks once an administrator exists.
7. **Delete or rename `setup.php` after setup.**
8. Log in at `/login.php`.


## Upgrading an existing Stonefellow installation

If the original Stonefellow backend is already installed:

1. Upload the new files.
2. Log in with the existing Admin account.
3. Visit `/upgrade.php`.
4. Click **Run Upgrade** once.
5. You will be sent to **Admin → Users** when complete.

`upgrade.php` safely checks for existing columns/tables before changing the database.

A one-time manual SQL version is also included as `upgrade-user-access.sql`, but `/upgrade.php` is preferred because it is safer to rerun.

## Contact form

`contact.php` stores messages in `contact_messages`.

PHP mail is disabled by default. Set:

`'send_contact_email' => true`

in `config.php` only if your host has working outbound PHP mail. Database storage does not require mail delivery.

## Music uploads

Admin → Tracks supports:

- MP3
- M4A
- WAV
- OGG

Cover images:

- JPG/JPEG
- PNG
- WEBP

Uploads are stored in:

- `/uploads/audio/`
- `/uploads/covers/`

They are served to permitted users through `media.php`.

## Existing Stonefellow links

- Spotify: https://open.spotify.com/artist/4cngj2wPSfLjyibLMUpQFI
- Apple Music: https://music.apple.com/us/artist/stonefellow/1588143974
- TIDAL: https://tidal.com/artist/28653042
- YouTube: https://www.youtube.com/@stonefellow
- Instagram: https://www.instagram.com/stonefellow
- Facebook: https://www.facebook.com/stonefellow
- Contact: stonefellow74@gmail.com

## PHP

Target: PHP 8.1+ with PDO MySQL.


## Stonefellow Chat + Knowledge Base

Fan and Supervisor accounts now land in `/chat.php` after login when `chat.access`
is enabled for their role.

The chat UI includes:

- new chat + conversation history
- role-scoped Stonefellow database retrieval
- knowledge-base retrieval
- source links for knowledge files
- responsive mobile chat navigation
- optional OpenAI-compatible model endpoint in `config.php`

Without an external model configured, chat still works as a database/knowledge
retrieval assistant and returns the most relevant available records.

### Knowledge Base

Administrators/authorized managers can use:

`/admin/knowledge.php`

Supported uploads:

- TXT / Markdown
- CSV / JSON
- HTML / XML
- DOC / DOCX
- PDF
- MP3 / M4A / WAV / OGG

Text files and DOCX are indexed directly. PDF/DOC extraction is used when the
hosting server has a compatible extraction utility. For music/audio or files
that cannot be parsed, add a transcript, lyrics, credits, notes or other
searchable text in the Knowledge Text field.

Existing sites can run `/upgrade.php`, or manually run:

`upgrade-stonefellow-chat-kb.sql`


## v7 — Listening Analytics + Song Workspace

New features:

- detailed play-start and listening-duration tracking
- qualified plays (10+ seconds)
- unique listener estimates without storing IP addresses
- completion percentages and completed sessions
- device-type breakdown
- per-track listening statistics
- recent listening sessions
- `/admin/listening.php`
- `/admin/track.php?id=TRACK_ID`
- private Supervisor song notes
- lyrics for every track
- song-specific Knowledge Base items
- public/member song detail page at `/track.php?id=TRACK_ID`
- Player `•••` opens lyrics/song information
- fixed profile-image display using `/avatar.php`

### Existing database

Run:

`upgrade-stonefellow-v7.sql`

or upload the files and run `/upgrade.php` while signed in as Admin.

No listener IP addresses are stored. Anonymous unique-listener estimates use a
random first-party listener cookie that is hashed before database storage.


## v9 — OpenAI / Claude API Settings

Admin now includes:

`Admin > AI / API`

Features:

- OpenAI enable / disable
- Claude / Anthropic enable / disable
- active chat provider selector
- OpenAI model selector
- Claude model selector
- encrypted API-key storage
- connection-test buttons
- safe fallback to local Stonefellow retrieval when an API provider is unavailable

Default cost-efficient chat models:

- OpenAI: `gpt-5.6-luna`
- Claude: `claude-haiku-4-5`

The API credentials are encrypted in the database. Stonefellow creates a local
encryption key at:

`/private/ai-key.php`

Back up that file with `config.php`. Do not commit, publish or share it.

### Tracks Admin

`Admin > Tracks` now opens directly to the track library. The add/edit form is
hidden by default. Use `+ Add Track` to open the new-track form.


## v12 — Knowledge Base is Backend + Chat Only

The previous member-facing `/knowledge.php` library UI has been removed.

Knowledge now exists only as:

- `Admin > Knowledge` for management
- song-specific backend knowledge
- Agent Chat retrieval/context
- protected source-file delivery through `knowledge-file.php` when Chat/Admin references a file

Visiting `/knowledge.php` redirects signed-in chat users to `/chat.php` and
other visitors to the home page.


## v13 — Agent Listening Canvas, Recommendations, Messages + Notifications

### Agent Chat audio

Agent Chat can now return playable Stonefellow tracks directly inside the chat.

- audio cards use the protected `/media.php` delivery path
- every Agent Chat listen uses the existing listening analytics endpoint
- analytics distinguish `Player` from `Agent Chat`
- playlists automatically continue to the next suggested track
- audio cards are also attached when the assistant names an exact Stonefellow track

### Recommendations and mood playlists

Tracks now have recommendation metadata:

- description
- genre
- mood
- energy
- BPM
- recommendation keywords

Agent Chat uses those fields, lyrics, prior listening history and permitted
track visibility to:

- suggest the next song
- avoid over-repeating recently played tracks
- create mood-based Stonefellow playlists
- attach playable results directly to the listening canvas

### Chat history

Every saved conversation in the Agent Chat sidebar now has its own delete
control. Deleting the currently open conversation clears the chat canvas.

### Contact inbox audit

`Admin > Messages` now supports:

- New / Open / Replied / Archived status
- assignment to users with message-management permission
- private internal notes
- unread/read workflow
- reply-by-email action
- message assignment notifications
- contact-form submissions as notification events

### Notifications

Logged-in headers now include a notification bell and unread badge.

Notifications are available in:

- the public logged-in header
- Admin
- Agent Chat
- `/notifications.php`

New contact-form submissions create notifications for active users who can
manage messages. Existing unread contact submissions are backfilled during
the v13 upgrade.

### Existing database

Run `/upgrade.php` as Admin, or import:

`upgrade-stonefellow-v13.sql`


## v14 — REAPER Project / Stem Studio

`Admin > Tracks > Edit Media` now accepts a REAPER Media ZIP.

The uploader is chunked in the browser (8 MB by default), which allows large
project ZIPs without relying on a single giant PHP upload request.

### Import behavior

Stonefellow:

- reads the included `.rpp` project when present
- imports `*-consolidated.wav` files as synchronized stems
- falls back to ordinary WAV files when no consolidated stems exist
- ignores `.reapeaks`
- ignores redundant raw WAV takes when consolidated stems are available
- parses REAPER tempo, time signature, project sample rate, track names,
  track volume/pan, item positions and named REAPER FX presets
- detects basic stem roles such as Vocal, Drums, Percussion, Bass and Guitar
- stores the `.rpp` file privately
- stores stems under the protected `/uploads/stems/` tree

### Online Stem Studio

After import, use `Song Details > Stem Studio`.

The browser studio supports:

- synchronized playback
- shared timeline / seek
- mute
- solo
- level
- stereo pan
- Full Mix preset
- Vocals preset
- Instrumental preset
- Rhythm preset
- REAPER starting level/pan values
- project/stem sample-rate reporting
- editable stem names and roles

The online studio is for review, collaboration and listening. REAPER remains
the production DAW for plugins, automation, MIDI, rendering and mastering.

### Agent Chat

Accounts with Track Management permission can ask Agent Chat about imported
production stems and REAPER project metadata.

### Existing database

Run `/upgrade.php` as Admin, or import:

`upgrade-stonefellow-v14.sql`


## v16 — Low-space MP3 Stem Workflow

Stonefellow's REAPER importer now supports MP3 stems.

Recommended hosted workflow:

1. Keep the production WAVs and full REAPER project locally.
2. Render every web stem from the same timeline start in REAPER.
3. Use 256 kbps or 320 kbps MP3.
4. Preserve useful stem filenames such as `Lead Vocal.mp3`, `Drums.mp3`,
   `Bass.mp3`, etc.
5. Put the `.rpp` file and MP3 stems into one ZIP.
6. Upload the ZIP in `Admin > Tracks > Edit Media`.

When MP3 files exist in the package, Stonefellow imports those instead of WAVs.

The chunked uploader now deletes temporary upload chunks immediately after the
ZIP is assembled and deletes the assembled ZIP immediately after import. This
substantially reduces peak disk usage on shared hosting.

WAV packages continue to work when enough disk space is available.


## v17 — Shared-hosting Stem Import Repair

The REAPER stem importer no longer performs the entire extraction/import in
one long PHP request.

The browser now uses four phases:

1. upload the ZIP in small chunks
2. assemble and inspect the package
3. import exactly one stem per server request
4. commit the database records and delete temporary files

This avoids common shared-hosting/FastCGI timeouts during the
"Parsing REAPER project and importing stems" stage.

The upload UI also reports the actual HTTP/server response when JSON is not
returned instead of only showing "invalid response."

Server-side importer warnings/fatal errors are suppressed from API JSON and
written to:

`/private/stem-import.log`

Errors returned to the browser include a short request ID that can be matched
to the log.

No database schema changes were added after v14.


## v18 — 500 Error / ZIP Assembly Repair

The REAPER uploader now splits ZIP assembly itself into one server request per
uploaded chunk. Shared hosting no longer has to concatenate the complete ZIP
inside one long PHP request.

Import pipeline:

1. upload one small chunk per request
2. append one uploaded chunk to the ZIP per request
3. inspect the assembled ZIP
4. extract one stem per request
5. commit database records

Each source upload chunk is deleted immediately after it has been safely
appended to the assembled ZIP.

`Admin > Stem Diagnostics` was also added. It reports PHP version, ZipArchive,
memory/execution settings, upload limits, writable directories, available
disk space, database tables and the recent private importer log.

No new database schema changes are required.


## v19 — Direct MP3 Stem Upload

Shared-hosting HTTP 500 errors during ZIP inspection can now be bypassed
completely.

`Admin > Tracks > Edit Media` now has a primary direct MP3 stem importer:

- select multiple synchronized MP3 stems
- optionally select the small REAPER `.rpp` project
- each MP3 is uploaded in small chunks
- no ZIP is created
- no ZipArchive inspection is required
- no archive is extracted
- uploaded MP3 temp files are moved into final stem storage instead of copied
- the existing Stem Studio and Agent Chat metadata integration are retained

The older ZIP importer remains under an Advanced disclosure for servers where
ZipArchive imports work reliably.

No new database schema changes are required after the v14 stem tables.


## v20 — RPP-Only Production Metadata

The direct production uploader now accepts:

- MP3 stems only
- REAPER `.rpp` only
- MP3 stems + `.rpp`

Uploading only the `.rpp` attaches the REAPER project metadata to the song
without requiring an audio stem. MP3 stems can be added later.

When an existing RPP project is present and a later MP3 upload does not include
a replacement RPP, Stonefellow preserves the existing project metadata/file.

No database schema changes are required after v14.


## v21 — REAPER Project File Validation Repair

The direct production uploader now validates REAPER project files by their
actual text signature (`<REAPER_PROJECT`) instead of requiring the filename to
end exactly in lowercase `.rpp`.

Accepted examples now include:

- `.rpp`
- `.RPP`
- `.rpp-bak`
- `.RPP-BAK`
- renamed text files that are genuinely REAPER project files

The maximum project-file size was raised from 12 MB to 64 MB.

If a wrong file is selected, the error now includes the filename and size so
the problem is visible immediately.

No database schema changes are required.


## v22 — One-Upload REAPER ZIP Extraction

Track/Edit once again uses a REAPER ZIP as the primary hosted workflow.

The server no longer depends on PHP ZipArchive for the normal import path.
Stonefellow chooses the safest available ZIP backend in this order:

1. command-line `unzip` utility
2. PHP `PharData`
3. PHP `ZipArchive` only as the final fallback

After the browser uploads and assembles the ZIP, Stonefellow:

- lists the package without extracting the entire archive
- automatically finds the `.rpp` / `.rpp-bak`
- prefers MP3 stems when present
- falls back to consolidated WAV stems
- extracts the small RPP once
- extracts exactly one audio stem per short server request
- writes the project/stem database records
- deletes the temporary uploaded ZIP

This avoids the HostGator/shared-hosting failure previously seen while
ZipArchive was inspecting the package in one PHP request.

The direct MP3 + RPP uploader remains available as a fallback.

No database schema changes are required after the v14 stem tables.


## v23 — Native ZIP Reader for Shared Hosting

The repeated HostGator HTTP 500 during ZIP inspection was isolated to the
server-side archive backend. Stonefellow now has its own standard ZIP reader
implemented in PHP.

For normal REAPER ZIP files it:

- reads the ZIP end record and central directory directly
- does not invoke `ZipArchive`
- does not invoke `exec()` / command-line unzip
- does not invoke `PharData`
- supports normal Stored and Deflate ZIP entries
- streams decompression in 64 KB blocks
- validates extracted size and CRC
- extracts only the RPP and chosen stems
- still processes one stem per short request

External ZIP mechanisms remain fallbacks only for unusual environments.

The native reader was tested against the original 244 MB `Media.zip` under a
64 MB PHP memory limit. It listed the package, extracted and parsed the RPP,
and streamed a 24.9 MB 48 kHz / 24-bit WAV stem successfully.

The browser now includes the failing API action in any HTTP error, such as
`prepare`, `import_step`, or `commit`.

No database schema changes are required.


## v24 — Stale Deployment / OPcache Isolation

A repeated generic `Server returned 500 instead of JSON` after v23 indicated
that the browser/server was still executing an older cached stem importer.

v24 therefore uses new physical filenames instead of relying on query-string
cache busting:

- `/api/stem-upload-v24.php`
- `/admin/stem-upload-v24.js`
- `/includes/stems-v24.php`

Track/Edit visibly shows `Stem Importer v24`.

Before uploading any bytes, the browser now calls a `probe` action. The server
must answer with:

- importer build `v24`
- selected ZIP backend
- PHP version
- memory limit
- execution limit

If the browser and server versions do not match, upload stops with an explicit
deployment-mismatch error instead of proceeding into an unknown stale build.

No database schema changes are required.


## v25 — Browser-Side REAPER ZIP Import

The server-side ZIP path has been removed from the primary Track/Edit workflow.

The selected REAPER ZIP stays in the browser. JavaScript now:

1. reads the ZIP end record and central directory locally
2. finds the `.rpp` / `.rpp-bak`
3. prefers MP3 stems, otherwise consolidated WAV stems
4. decompresses one selected file at a time in the browser
5. uploads only the extracted RPP and audio files through the direct uploader

The HostGator server therefore never receives, opens, lists, or extracts the
ZIP itself.

The server endpoint is now `/api/stem-direct-v25.php` and accepts both MP3 and
WAV stems. Each extracted audio file is still uploaded in small chunks.

The browser importer uses the standard `DecompressionStream('deflate-raw')`
API available in current Chrome/Edge. Stored ZIP entries are handled without
decompression.

The browser implementation was tested against the original 244 MB `Media.zip`:
it found all 55 entries and locally extracted the 80,852-byte RPP plus a
24,883,890-byte consolidated WAV stem successfully.

No database schema changes are required.


## v26 — Phased Stem Library Commit

The browser-side ZIP import reached the final `commit` stage on HostGator but
the single commit request still returned HTTP 500.

v26 removes that final long request.

After browser extraction/upload, Stonefellow now commits in three phases:

1. `commit_prepare`
   - parses the small RPP
   - prepares final directories
   - creates/resolves the project record
   - does not touch the current live stem set

2. `commit_step`
   - moves exactly one uploaded stem
   - inserts exactly one hidden (`is_active=0`) stem row
   - returns immediately
   - repeats once per stem

3. `commit_finish`
   - swaps the completed staged stem set live in one short transaction
   - updates project/track metadata
   - removes the previous stem files only after the new set is active

An interrupted import therefore leaves the existing active Stem Studio set
untouched. Abort cleanup removes staged rows and staged files.

The primary endpoint is `/api/stem-direct-v26.php`.

No database schema changes are required.


## v27 — HostGator / ModSecurity Save-Action Repair

The v25 and v26 imports both reached the final save stage, but HostGator
returned a generic HTTP 500 before Stonefellow could return JSON.

The key pattern was:

- v25 failed on POST action `commit`
- v26 failed immediately on POST action `commit_prepare`
- all upload, probe, RPP, browser extraction, and chunk requests worked

`COMMIT` is an SQL keyword and shared-host ModSecurity/WAF rules can block a
POST parameter containing SQL-looking command words before PHP executes. That
also explains why Stonefellow's PHP exception/shutdown handlers never got a
chance to return JSON.

v27 removes the word `commit` from every browser request action. The save
pipeline is now:

1. `stage_start`
2. `stage_item` — one stem per request
3. `stage_finish`

The underlying safe staged-save behavior from v26 is unchanged: the current
live stem set stays active until the replacement set is completely staged.

The endpoint and browser scripts also use new physical filenames:

- `/api/stem-direct-v27.php`
- `/admin/stem-browser-zip-v27.js`
- `/admin/direct-stem-upload-v27.js`

No database schema changes are required.


## v28 — Browser RPP Parsing + Micro Save Phases

v27 confirmed the failure occurs after all browser ZIP extraction and audio
uploads have already succeeded.

v28 removes the remaining server-side REAPER parse from the save path. The
browser parses the small `.rpp` text and sends only compact project metadata
and the per-file placement map.

The final save is split into four independently visible requests:

1. `save_open` — validates uploads and creates final folders only
2. `save_rpp` — moves only the small RPP file
3. `save_db` — resolves/creates the project database record
4. `save_item` — moves/inserts one stem per request
5. `save_finish` — activates the completed staged set

This makes the next server response identify the exact failing operation while
also removing RPP regex parsing and large parsed project structures from the
HostGator save request.

The server persists only compact file-map metadata, not the full parsed REAPER
track tree.

No database schema changes are required.


## v29 — Minimal Stem Row + Separate File Placement

v28 proved the project DB setup itself succeeds. The HTTP 500 occurs on the
first `save_item`, whose two remaining responsibilities were:

- inserting a `track_stems` row
- moving the uploaded audio file

v29 separates those operations.

For each stem:

1. `row_add`
   - performs a deliberately minimal `track_stems` INSERT
   - stores only essential playback fields
   - lets optional REAPER fields use database defaults
   - leaves the uploaded audio file untouched

2. `file_place`
   - moves only the already-uploaded audio file
   - advances the saved-stem counter

This also makes the save retry-safe: if the database request fails, the
uploaded audio file is still in temporary storage and can be retried without
re-uploading or leaving a half-moved stem.

The minimal INSERT uses:
`track_id`, `project_id`, `stem_name`, `stem_role`, `file_name`, `file_path`,
`duration_seconds`, `start_offset_seconds`, `sort_order`, and `is_active`.

No database schema changes are required.


## v30 — Actual row_add HTTP 500 Root Cause

The v29 `row_add` failure exposed a deterministic PHP fatal in the shared stem
helper. `stem_role_from_metadata()` calls `stem_lower()`, and the helper had:

```php
return function_exists('mb_strtolower')
    ? stem_lower($value)
    : strtolower($value);
```

On a normal PHP host with `mbstring` installed, that recursively called
`stem_lower()` forever. The first `row_add` is the first point in the new save
pipeline that calls role inference, so the failure location matches exactly.

v30 changes the implementation to call `mb_strtolower()` correctly and fixes
the same bug in every retained copy of the stem helper.

Additional v30 validation performed before packaging:

- PHP token-based audit of every PHP function for accidental direct recursion
- runtime test of `stem_lower()`
- runtime test of `stem_match_rpp_file()`
- runtime tests of Vocal/Drums/Guitar/Bass/Keys/Synth role inference
- critical SQL statement placeholder audit
- browser/API save-action parity audit
- full PHP syntax lint
- full primary JavaScript syntax check

New physical paths prevent stale OPcache/browser code:

- `/includes/stems-v30.php`
- `/api/stem-direct-v30.php`
- `/admin/stem-browser-zip-v30.js`
- `/admin/direct-stem-upload-v30.js`

No database schema changes are required.


## v31 — Full-Canvas DAW Stem Studio

Stem Studio is now a dedicated full-canvas interface. The normal admin sidebar,
mobile admin bar, and admin page header are removed only while inside the
studio.

Layout:
- compact studio header with song identity and exit controls
- traditional arrange/timeline canvas across the main work area
- stem/track list in the right column
- full-width mixer across the bottom
- master channel at the left of the mixer
- master bus/plugin rack beside the master
- one vertical channel strip per stem
- per-stem pan knob, vertical volume fader, mute and solo
- synchronized controls between right track list and mixer
- track selection links the right list, arrange lane, and mixer channel
- master EQ/compressor/reverb review-chain toggles
- existing synchronized playback, seeking, presets and reset behavior retained

No database schema changes are required.


## v32 — DAW Workflow, Audio Repair, Drag Ordering, Supervisor Mix Saves

Stem Studio refinements:

- fixes the v31 no-audio regression with a permanent master bus input
- moves the stem/track list to the left of the arrange canvas
- aligns the left stem rows with the arrange lanes
- hides browser scrollbars while preserving wheel/trackpad scrolling
- lets left stem rows and bottom mixer channels drag/reorder together
- replaces glitchy native pan dragging with pointer-driven pan knobs
- moves Play, timeline, project metrics, presets and reset to the top of the bottom mixer
- moves Master Bus / Plugins into a popup
- adds Stem Studio buttons to Admin Tracks
- shows Stem Studio buttons to supervisors/admins in Chat results and Player
- supervisors/admins can save, load, update and delete private custom mixes
- saved mixes include stem order, volume, pan, mute, solo, master volume and plugin toggles

Database:
- adds `stem_mix_saves`
- run `/upgrade.php` after deployment, or import `upgrade-stonefellow-v32.sql`


## v33 — Main Timeline, Loop Regions, Spectrum Output

Stem Studio timeline/mixer refinements:

- removes the duplicate bottom play scrub/range control
- keeps Play and current/total time at the top of the bottom mixer
- makes the main arrange/wave canvas independently horizontally scrollable
- two-finger left/right trackpad gestures scroll the arrange canvas without
  changing playback position
- the playhead moves independently while the user browses elsewhere in the
  song; the arrange window is not forced to follow playback
- clicking the main arrange timeline seeks playback to that exact song point
- dragging across the timeline ruler highlights a loop region
- highlighted regions can be toggled Loop On/Off or cleared
- active loop regions repeat continuously during synchronized stem playback
- saved supervisor mixes also preserve loop start/end/active state
- each mixer track now has a live 8-band spectrum/frequency output driven by a
  Web Audio AnalyserNode
- stem volume faders cannot start channel drag/reorder
- drag/reorder now starts only from explicit drag handles on the left track row
  or mixer channel header

No database schema changes are required beyond the v32 `stem_mix_saves` table.
If v32 `/upgrade.php` already completed successfully, no additional SQL action
is needed for v33.


## v34 — Timeline Seek Static / Range Playback Repair

v33 exposed a seek-specific playback failure: playback from the beginning was
clean, but clicking the main timeline could turn the synchronized stem output
into white-noise/static.

Two related seek paths were hardened.

### Binary-safe stem range endpoint

Stem Studio now uses `/stem-media-v34.php`.

The endpoint:

- disables PHP output compression and output buffering for media bytes
- closes the PHP session before streaming
- sends `Content-Encoding: identity`
- sends `Cache-Control: private, no-transform`
- validates a single HTTP byte-range request strictly
- returns correct 206 `Content-Range` / `Content-Length`
- supports suffix and open-ended byte ranges
- supports HEAD requests
- streams the exact underlying MP3/WAV bytes without transformation
- uses a new physical endpoint path so browsers cannot reuse an older cached
  range response

### Coordinated decoder seeks

The browser no longer writes every stem's `audio.currentTime` during normal
animation frames.

Explicit timeline/loop seeks now:

1. pause the stem media elements
2. wait for metadata where needed
3. set each active stem to its one target local time
4. wait for `seeked` / `canplay`
5. resume synchronized playback only after the decoders have repositioned

This prevents repeated decoder/range resets after a timeline click.

No SQL changes are required for v34. The v32 saved-mix table remains current.


## v35 — Automatic Local Stem Studio Persistence

Stem Studio now automatically preserves the current browser session settings
for each signed-in user and track using localStorage.

Persisted automatically:

- per-stem volume
- per-stem pan
- mute / solo state
- stem/channel order
- master volume
- master EQ / compressor / reverb toggle state
- loop start / end / active state
- selected stem
- arrange-canvas horizontal and vertical scroll position
- mixer horizontal scroll position

The local state key is scoped by both user ID and track ID, so different users
and songs do not overwrite one another on the same browser.

Changes are debounced during normal editing and also written synchronously on
`pagehide`, so refreshing or leaving the page does not normally lose the latest
settings.

Server-side Saved Mixes remain separate. Loading a Saved Mix also makes that
mix the current locally autosaved browser state.

No SQL changes are required for v35.


## v36 — Pull-Up Track Plugin Rack

Stem Studio now has a DAW-style per-track insert rack above the bottom mixer.

### Rack interaction

- a horizontal `TRACK PLUGINS` handle sits at the top edge of the bottom mixer
- drag the handle upward to expand the mixer and expose plugin inserts for
  every stem
- drag downward to collapse it
- clicking the handle also toggles the rack
- rack open/closed state and mixer height are saved to the existing per-user,
  per-track localStorage state

### Plugin directory

Each stem has an `+ Plugin` insert button when the rack is open. Clicking it
opens the Plugin Directory for that exact stem.

Initial built-in plugins:

1. `5-Band EQ`
   - 80 Hz lowshelf
   - 250 Hz peaking band
   - 1 kHz peaking band
   - 4 kHz peaking band
   - 12 kHz highshelf
   - each band supports -18 dB to +18 dB

2. `Delay`
   - delay time 20 ms to 1.5 sec
   - feedback 0% to 92%
   - wet/dry mix 0% to 100%

Assigned plugin chips appear directly in each stem's insert rack. Clicking an
assigned plugin opens its parameter editor. Plugins can be bypassed, enabled,
edited, or removed.

### Audio routing

Per-stem routing is now:

`media → volume → pan → track plugins → spectrum analyser → master bus`

Track plugin graphs can be rebuilt while the project is open without replacing
the media source node. The v34 coordinated timeline seek/range-stream repair is
preserved.

### Persistence

Per-track plugin chains, enabled/bypass state and plugin parameter values are:

- automatically saved/restored through v35 localStorage persistence
- included in supervisor Saved Mixes through the existing `mix_json`

No new database table or SQL migration is required for v36.


## v37 — Interactive Graph Plugin Editors

The built-in track plugins now use DAW-style graph editors instead of rows of
sliders.

### 5-Band EQ graph

The EQ editor draws a logarithmic frequency graph from 20 Hz to 20 kHz with a
live response curve and five draggable nodes.

Each node can be dragged:

- horizontally to change that band's center/corner frequency
- vertically to change gain from -18 dB to +18 dB

The five bands start at:

- 80 Hz
- 250 Hz
- 1 kHz
- 4 kHz
- 12 kHz

Node readouts show the current frequency and gain. Nodes are keyboard
adjustable with arrow keys and a Flat button resets the graph.

The actual Web Audio BiquadFilter frequency and gain AudioParams update live
while the node moves, so the audio chain does not need to be disconnected and
rebuilt for every drag frame.

### Delay graph

Delay now has a visual echo graph.

- drag the ECHO node horizontally to change delay time
- drag the ECHO node vertically to change feedback
- drag the WET node vertically to change wet/dry mix
- the echo-tail visualization updates with the controls
- keyboard adjustment remains available
- Reset restores the default delay

Delay AudioParams also update live without rebuilding the stem signal chain.

### Persistence

Movable EQ frequencies, EQ gains, delay settings and bypass state continue to
persist in:

- per-user/per-track localStorage autosave
- supervisor Saved Mixes

The existing v32 `stem_mix_saves` table stores these additions inside
`mix_json`; no new SQL migration is required.

The v34 coordinated seek/range playback repair remains intact.


## v38 — Mixer Strip Cleanup

Bottom mixer refinements:

- removes the old decorative/static level strip to the right of each stem fader
- moves the live 8-band spectrum display to the left of each stem volume fader
- changes the spectrum to a compact vertical presentation
- shortens the stem volume fader so it no longer extends into the mute/solo row
- separates Mute/Solo into their own dedicated row
- keeps faders independently draggable without moving the whole channel
- rebuilds the Master strip with a cleaner dedicated control body
- adds a live dual master output meter using a master Web Audio AnalyserNode
- updates the Master fader styling and value display
- preserves v37 graph-based EQ/Delay plugins, v35 local persistence, and the v34 seek/range playback repair

No SQL changes are required for v38.


## v39 — Live Track EQ Meter Repair

Fixes the per-track vertical EQ/spectrum meters introduced in v38.

Changes:

- increases each stem analyser from FFT 256 to FFT 1024 for usable low-frequency resolution
- sets explicit analyser dB range and smoothing
- maps each displayed band to its real center frequency (63 Hz through 8 kHz)
- computes live RMS/peak energy per band instead of using fixed FFT bin ranges
- changes rendering from variable element width to a full-width bar with a GPU-friendly `scaleX()` fill
- keeps a faint background rail visible even when a band is quiet, so the meter never appears missing
- orders high frequencies at the top and low frequencies at the bottom
- preserves v38 mixer cleanup, v37 graph plugins, localStorage, Saved Mixes and the v34 seek/range playback repair

No SQL changes are required for v39.


## v40 — Automation Lanes, Aux Sends/Returns, Compressor + Reverb

This begins the next full DAW expansion.

### Track automation

Every stem now has an `A` automation button. Opening it expands that stem and
its matching arrange lane together.

Available automation lanes:

- Volume
- Pan
- Send A · Room
- Send B · Delay

Automation interaction:

- click an empty lane to add a point
- drag a point horizontally to change time
- drag vertically to change value
- double-click a point, or press Delete/Backspace while focused, to remove it
- points are linearly interpolated during playback
- volume, pan and send automation are applied live by the Web Audio graph

Automation point data and the open-lane UI state are included in localStorage.
Automation data is also included in supervisor Saved Mixes.

### Aux sends / return buses

The open Track Plugins rack now contains two sends on every stem:

- Send A → shared Room/Reverb return
- Send B → shared Feedback Delay return

Two dedicated return channels now sit beside Master:

- AUX A · Room Return
- AUX B · Delay Return

Each return has its own output fader. The signal path is post-fader /
post-plugin:

`Stem → Volume → Pan → Track Plugins → Main + Aux Send taps`

The shared effect returns feed back into the master bus.

### Compressor plugin

The Plugin Directory now includes a functional dynamics compressor.

The graph editor supports:

- draggable Threshold / Ratio node
- draggable Makeup Gain node
- Knee
- Attack
- Release

Web Audio DynamicsCompressor parameters update live while editing.

### Reverb plugin

The Plugin Directory now includes a functional per-track convolution reverb.

The graph editor supports:

- TAIL node: decay + wet mix
- ROOM node: room size
- DAMP node: high-frequency damping
- live visual reverb-tail curve

Wet/dry and damping parameters update live. Convolution impulse regeneration is
debounced while decay/room size are dragged to avoid rebuilding the impulse on
every pointer event.

### Persistence

No new SQL table is required. The existing `stem_mix_saves.mix_json` now
accepts:

- per-track plugin chains including EQ, Delay, Compressor and Reverb
- per-track Aux A / Aux B send levels
- Aux return levels
- automation points for Volume / Pan / Send A / Send B

Older v32-v39 saved mixes remain valid because all new fields have defaults.

No SQL changes are required for v40.


## v41 — Plugin Ordering, Channel Strips, Group Buses, Zoom + Regions

This phase continues the full browser-DAW build on top of v40.

### Drag/reorder plugin chains

Assigned track plugins are now draggable inside each stem insert rack.

Changing the order rebuilds only that track's insert graph, so chains such as:

`EQ → Compressor → Delay`

can be changed to:

`Compressor → EQ → Delay`

without replacing the media source node.

Plugin order persists in localStorage and supervisor Saved Mixes.

### Full track channel strip controls

The expanded Track Plugins rack now includes:

- Trim: -12 dB to +12 dB, pre-fader
- Polarity invert
- Mono sum
- Group-bus assignment
- Send A / Send B
- ordered plugin inserts

The track input path is now:

`Media → Trim → Polarity → Mono/Stereo → Fader → Pan → Plugins → Spectrum`

The processed signal then routes to Direct or a selected Group Bus, while the
post-plugin Aux sends continue to feed the shared Room and Delay returns.

### Group buses

Three dedicated subgroup channels are now available:

- VOCALS
- RHYTHM
- MUSIC

Each has:

- group fader
- mute
- live output meter

Tracks can be routed Direct or assigned to any group from the channel strip.
Group settings and assignments persist in localStorage and Saved Mixes.

Default routing for a new browser state is:

- Vocal → VOCALS
- Drums / Percussion / Bass → RHYTHM
- Guitar / Keys / Synth / Other → MUSIC

### Timeline / arrange zoom

The arrange canvas now supports:

- Zoom - / Zoom + controls
- 50% to 800% zoom
- Ctrl/Cmd + trackpad or mouse-wheel zoom
- independent horizontal browsing remains intact
- zoom level is remembered per user / track in localStorage

### Markers and regions

The timeline now has a dedicated marker/region lane.

Markers:

- add at the current transport position
- click to seek
- drag horizontally to move
- double-click to rename
- delete individually

Regions:

- create from the active loop selection, or from the current position
- click to seek and make the region the active loop
- drag the region to move it
- drag either edge to resize it
- double-click to rename
- delete individually

Markers and regions persist in both localStorage and supervisor Saved Mixes.

### Persistence compatibility

No new SQL table is required. v41 extends the existing `stem_mix_saves.mix_json`
payload with:

- trim / polarity / mono / group assignments
- group-bus fader + mute states
- ordered plugin chains
- timeline markers
- timeline regions

The Saved Mix JSON limit was raised to 512 KB to leave room for automation,
regions and future DAW state.

Older saved mixes remain valid because all added state has defaults.

The v34 coordinated seek/range repair, v35 browser persistence, v39 live stem
spectrum meters and v40 automation/Aux/Compressor/Reverb systems remain active.

No SQL changes are required for v41.


## v42 — Live Stem Spectrum Repair + Compact Routing Control

The individual per-stem spectrum display has been rebuilt again rather than
patching the v39 CSS-bar implementation.

### Canvas spectrum meters

Each mixer stem now owns a dedicated canvas meter. The display is driven from
that stem's actual Web Audio `AnalyserNode` and renders eight frequency bands:

- 8 kHz
- 4 kHz
- 2 kHz
- 1 kHz
- 500 Hz
- 250 Hz
- 125 Hz
- 63 Hz

The renderer combines:

- FFT band energy
- RMS/peak weighting
- time-domain RMS fallback when a browser returns an unusually flat frequency
  frame
- fast attack / slower release
- per-band peak hold/decay

This removes the previous dependency on CSS custom-property widths for the
actual animation. The browser now draws the meter frame-by-frame on canvas.

### Track routing control

The visible `BUS` label was removed from the per-track routing control. The
compact native dropdown/chevron remains clickable and accessible; opening it
still provides Direct / Vocals / Rhythm / Music routing options.

v41 channel strips, group buses, zoom, markers/regions, v40 automation/Aux
routing/plugins, localStorage persistence, Saved Mixes and the v34
seek/range-playback repair remain intact.

No SQL changes are required for v42.


## v43 — Universal Channel Plugins + Add Bus Routing

Stem Studio's insert rack now applies to every mixer channel rather than source
stems only.

### Plugin controls on every mixer channel

The pull-up `CHANNEL PLUGINS` rack now exposes ordered plugin inserts on:

- every source stem
- MASTER
- AUX A
- AUX B
- VOCALS group
- RHYTHM group
- MUSIC group
- every user-created custom bus

All channel types support the same current plugin directory:

- 5-Band EQ
- Delay
- Compressor
- Reverb

Plugin chains can be reordered by drag/drop, bypassed, edited, removed and
persisted. The maximum insert count is now six per channel.

AUX A and AUX B now expose their default effects as real editable inserts:

- AUX A starts with Reverb
- AUX B starts with Delay

Those default effects use the same graph editors as track inserts.

### Stable mixer geometry while pulling the rack

Dragging the top plugin-rack handle no longer changes the sizes of the pan,
mute/solo, meter, fader or channel-number sections.

The lower mixer controls use fixed row heights. Only the insert/plugin row uses
the extra vertical space created by dragging the rack upward.

The rack can now expand to 820 px for larger plugin chains.

### Add Bus

The mixer toolbar now includes `+ Add Bus`.

Creating a bus opens an in-app dialog. A custom bus receives:

- its own mixer channel
- volume fader
- mute
- live output meter
- full plugin insert rack
- persistence in localStorage
- persistence in supervisor Saved Mixes

Up to 12 custom buses can be stored in a mix.

As soon as a bus is created, it is added to every source track's compact routing
dropdown. Tracks can therefore route to:

- Direct
- Vocals
- Rhythm
- Music
- any custom bus created in the current mix

Deleting a custom bus automatically routes affected tracks back to Direct.

### Audio routing

Custom and built-in group insert chains are real Web Audio routing stages.

Examples:

`Stem → Track Plugins → VOCALS Plugins → Master Plugins → Master`

`Stem → Track Plugins → Custom Bus Plugins → Master Plugins → Master`

`Stem Send A → AUX A Reverb/Plugins → Master`

`Stem Send B → AUX B Delay/Plugins → Master`

No new SQL table is required. Universal channel plugins and custom buses are
stored inside the existing `stem_mix_saves.mix_json` and the per-user/per-track
localStorage state.

Older mixes remain valid. Mixes created before universal channel plugins retain
the default AUX A Reverb and AUX B Delay when loaded.

No SQL changes are required for v43.


## v44 — Tempo-Synced Stem Library + Loop Clips

This phase adds a global stem library, tempo-aware playback and stronger
automation editing.

### Session tempo

Stem Studio now exposes a session BPM control in the bottom toolbar.

- the REAPER/project BPM is treated as the source tempo
- session tempo can be changed from 40–300 BPM
- all source stems change playback rate together
- the transport clock advances at the same source/session tempo ratio
- browser pitch preservation is enabled on HTML media elements where supported
- Reset Mix / `SRC` restores the source/project tempo
- tempo is persisted in per-user localStorage and supervisor Saved Mixes
- legacy Saved Mixes without a tempo continue at their source/project BPM

Automation, markers, regions and loop positions remain aligned because they are
stored in project/source timeline coordinates while the transport changes rate.

### Right-side Track Library

A `Track Library` button now appears immediately to the right of `Exit Studio`
in the Stem Studio header.

It opens a slide-out panel from the right containing active stems imported from
all uploaded Stonefellow tracks.

Library cards include:

- stem name
- source song
- stem category/role
- source BPM
- time signature
- duration
- mono/stereo status when known
- inline audio preview
- `Add Track`

The library supports text search and category filtering.

Only one inline library preview is allowed to play at a time, and closing the
drawer stops hidden previews.

### Four-bar library clips

Pressing `Add Track` creates a four-bar clip in the current arrangement.

If the inline sample was scrubbed before pressing Add Track, the clip begins
from that sample position where possible.

The new clip:

- appears as a new lane in the track list and arrangement
- is four destination bars long by default
- uses the source stem's BPM when mapping source audio
- follows the current session tempo
- can be dragged horizontally
- snaps movement to musical beat increments
- can be extended from the right edge
- extension snaps to full bars
- repeats the original four-bar source section when extended
- can be removed independently without touching the source stem

The clip engine uses pitch-preserving HTML media playback where supported and
routes added clips into the existing Web Audio master graph.

Library clips are persisted in localStorage and Saved Mix state without
duplicating the source audio file.

### Inward-opening dropdown menus

The compact track-routing control no longer depends on the browser's native
select popup. It now uses a custom route popover positioned toward the inside
of the viewport so Direct / group / custom-bus routing remains readable even
on mixer channels at the far-right edge.

The Track Library category selector uses the same inward-opening behavior.

### Automation cleanup

Every expanded automation lane now has explicit:

- `Delete Point`
- `Clear Lane`
- `Clear All`

Clicking an automation point selects/highlights it and enables Delete Point.
The existing double-click and keyboard Delete/Backspace behavior remains
available.

Clear Lane removes only the currently selected automation parameter. Clear All
removes Volume, Pan, Send A and Send B automation for that source track.

### Compatibility

v43 universal channel plugins and custom buses remain intact, including plugin
chains on Master, AUX A, AUX B, built-in group buses and custom buses.

v42 canvas stem spectrum meters remain intact.

The v34 coordinated seek / byte-range playback repair remains intact; normal
source-stem playback still does not repeatedly rewrite media `currentTime`.

No SQL changes are required for v44. New state is stored in the existing
`stem_mix_saves.mix_json`.


## v45 — Alt-Click Delete + Undo

Stem Studio now supports fast destructive editing with immediate undo.

### Alt-click delete

Automation:
- Alt-click / Option-click an automation node to delete it immediately.
- Existing Delete Point, Clear Lane, Clear All, double-click delete and
  Delete/Backspace keyboard behavior remain available.

Plugins:
- Alt-click / Option-click any assigned plugin chip to remove that plugin from
  its channel immediately.
- This works on source stems, Master, AUX A, AUX B, built-in groups and custom
  buses because all channels use the universal plugin-chain renderer.
- Normal click still opens the plugin editor.
- Drag still reorders the plugin chain.

### Ctrl+Z / Cmd+Z undo

Stem Studio now keeps an in-memory mix undo history.

- Ctrl+Z on Windows/Linux restores the previous mix state.
- Cmd+Z on macOS performs the same action.
- Text/search fields keep their normal browser text-edit undo behavior.
- Rapid slider/drag updates are grouped into one undo step after a short idle
  interval rather than generating a history entry for every pointer frame.
- Up to 80 prior mix states are retained for the current Studio session.
- Undo restores the complete mix state represented by `collectMixState()`,
  including plugins, automation, routing, buses, tempo, markers/regions,
  library clips, loop state and mixer values.
- Undo also immediately refreshes localStorage so a restored state survives a
  browser reload.

No SQL changes are required for v45.


## v46 — Unified Clip Editing, Split/Delete + Snap/Free

The source stems imported from REAPER/WAV and stems inserted from Track Library
now use the same arrangement-editing language.

### Editable source-stem clips

Each main imported stem is now represented by one or more editable clip
sections rather than one fixed decorative block.

A source clip can now be:

- selected
- dragged horizontally
- trimmed from the left edge
- extended back into available source audio from the left edge
- trimmed from the right edge
- extended back into available source audio from the right edge
- split at the transport/playhead
- deleted without deleting the underlying source stem
- restored with Ctrl/Cmd+Z

The original source audio file is never rewritten. Clip edits are references
containing source start/end and timeline start/length.

### Track Library inserts use the same clip editor

Track Library inserts now use the same selected-clip appearance, draggable body
and left/right trim handles as main imported stems.

Library clips still default to a four-bar section. Extending a Library clip
continues to repeat its selected source section, while main source-stem clips
extend into available source audio.

### Ctrl/Cmd+S Split

With a source or Library clip selected:

- Ctrl+S on Windows/Linux splits at the playhead
- Cmd+S on macOS performs the same action
- SNAP mode snaps the split to the beat guide
- FREE EDIT splits at the exact playhead position
- the browser Save Page shortcut is suppressed inside Stem Studio

A split source stem stays on the same stem lane and creates independently
movable/trim-able left and right sections.

### Ctrl/Cmd+X Delete Section

Ctrl+X / Cmd+X removes the selected clip section.

For source stems this removes only the selected arrangement section, not the
source track, mixer strip, plugins or uploaded WAV/MP3.

If no clip is explicitly selected, Stem Studio can use the currently selected
source track's clip under the playhead.

### SNAP: GRID / FREE EDIT

The thin mixer toolbar now contains a mode toggle:

- `SNAP: GRID` sticks moves, splits and edge edits to the musical beat guide
- `FREE EDIT` allows continuous placement

The arrange canvas displays beat-guide lines derived from the REAPER/source BPM
and follows timeline zoom.

### Simplified thin toolbar

Removed from the thin row:

- Full
- Vox
- Inst
- Rhythm
- Master Plugins

Master channel plugin editing is still available directly on the Master mixer
strip, so functionality was not removed from the Master itself.

### Safe media seeking

Source-stem clip boundaries do not reintroduce per-frame `currentTime`
rewrites. A moved/split clip performs one explicit metadata/seek operation when
the playhead enters that section, using the existing safe seek helper.

Library clips use the same boundary-seek discipline.

### Persistence

Source-stem clip sections are stored in:

- per-user/per-track localStorage
- Saved Mix `mix_json`
- undo snapshots

Legacy mixes without clip-arrangement state restore the original full stem
rather than becoming silent.

The Saved Mix payload allowance is now 2 MB to leave room for larger split
arrangements.

No new SQL table is required for v46.


## v47 — Studio Projects, Media Import + Account Ownership

Stem Studio now has a project/file workflow directly inside the full-canvas DAW.

### Compact left header menu

The old always-visible song-information block was removed from the header.

The left side now contains:

- `Menu`
- compact current project title
- `Song Info` dropdown

The right side keeps:

- `Exit Studio`
- `Track Library`

`Song Info` now opens as a dropdown containing project/song metadata, source
tempo, time signature, imported-track count, account ownership, Song Details
and Catalog Media links.

### Stem Studio project menu

The new left menu includes:

- New Project
- Import Media
- Import Multiple Media
- Save to My Account
- My Projects
- Delete Project

User-owned projects are listed directly in the menu for quick switching.

### New Project

A New Project dialog creates:

- a private/draft `tracks` row
- matching `track_projects` metadata
- source BPM
- time signature
- ownership by the signed-in user

The new project opens directly in Stem Studio.

The Tracks admin also includes a `+ New Studio Project` button.

Older catalog tracks opened in Stem Studio automatically receive lightweight
`track_projects` metadata if they did not already have it.

### Import Media

`Import Media` selects one WAV/MP3 file.

`Import Multiple Media` selects many WAV/MP3 files. Every selected file becomes
a separate source track / mixer strip.

Imports use the existing Stem Studio chunk-size configuration instead of relying
on one large PHP multipart upload. This is designed for production WAV files
that exceed normal shared-host upload limits.

The importer:

1. reads browser media duration metadata
2. initializes one server-side import request
3. uploads each file in configured chunks
4. validates the completed file
5. creates one `track_stems` row per media file
6. reloads Stem Studio with the new mixer tracks

### WAV metadata

Imported WAV files are parsed server-side using the existing RIFF/WAVE reader.

The stored stem metadata includes:

- channels
- sample rate
- bit depth
- duration
- inferred role/category from the source filename

MP3 files receive browser-derived duration and validated MP3-header handling.

### User account ownership

`tracks.owner_user_id` was added.

A project can be attached to the signed-in user with `Save to My Account`.
New Studio projects are owned by their creator automatically.

The Tracks admin now displays the track/project owner. Existing shared catalog
tracks may remain ownerless until explicitly saved to an account.

### Delete track / delete project

Every imported source track now has a compact delete control in the left track
column.

Deleting a source track:

- removes the `track_stems` row
- removes its protected local stem media
- recalculates the parent track duration
- leaves the rest of the project intact

`Delete Project` removes the current track/project, imported stems and protected
project files.

### Empty projects

Projects with no imported tracks now open the actual Stem Studio canvas rather
than a dead-end upload message. The empty canvas points users to:

`Menu → Import Media`

A temporary three-minute timeline is used until real media establishes the
project duration.

### Database upgrade

v47 adds:

`tracks.owner_user_id`

Run `/upgrade.php` after deploying v47. The cumulative upgrader adds the column,
index and owner foreign key.

A manual `upgrade-stonefellow-v47.sql` file is also included.

All v46 split/move/trim editing, v45 undo/Alt-delete, v44 Track Library and
tempo, v43 universal plugins/custom buses, v42 live spectrum meters and the v34
safe-seek/range playback repair remain intact.


## v48 — Empty Project Repair + Unified Library Tracks

### Empty-project controls repaired

New Studio projects are allowed to contain zero stems.

The v47 browser script still had an old startup guard that returned immediately
when `cfg.stems.length === 0`. That prevented every JavaScript-driven control
from being bound on a newly created empty project.

v48 removes that early return. Empty projects now initialize the full Studio,
including:

- left project menu
- New Project
- Import Media
- Import Multiple Media
- Song Info
- Track Library
- tempo / snap controls
- buses / plugin rack
- project deletion

### Track Library inserts are now normal Studio tracks

`Add Track` in Track Library no longer creates a special temporary library row.

The selected library stem is copied into the current project's protected stem
storage and inserted as a real `track_stems` record. After reload it receives
the exact same:

- left-sidebar track row
- automation controls
- arrange clip editor
- mixer strip
- pan / volume
- routing
- plugin rack
- spectrum meter
- mute / solo
- track settings
- delete behavior

as REAPER/WAV/MP3 imported source stems.

The inline preview position is retained. The new standard track begins with a
four-bar source section starting at the preview location when possible.

### Per-stem source tempo

Library stems can come from songs with a different BPM.

The copied track records its original source BPM. Stem playback rate and clip
time/source mapping use that per-stem source tempo, so the inserted track
follows the current project's session tempo while preserving pitch where the
browser supports it.

Split, trim and extend calculations use the same source/timeline ratio.

### Inward Track Settings

The settings gear in the left track column no longer expands a 230px details
panel inside the narrow sidebar.

Its form is rendered as a fixed popover positioned immediately to the right of
the sidebar and clamped to the browser viewport, preventing the category/track
settings panel from opening off-screen.

### Right-click track menu

Right-click any normal Studio track in:

- the left track row
- the arrangement lane
- the mixer channel

to open a context menu with:

- Track Settings
- Automation
- Mute / Unmute
- Solo / Unsolo
- Delete Track

Delete Track removes the project `track_stems` record and its protected copied
or imported media; it does not delete other tracks in the project.

The existing compact delete button remains available as a second path.

### Toolbar cleanup

The visible:

`Ctrl+S Split · Ctrl+X Delete · Ctrl+Z Undo`

shortcut legend has been removed from the thin mixer toolbar. The shortcuts
themselves remain active.

No SQL changes are required for v48. The v47 ownership upgrade is sufficient.


## v49 — Musical Timeline, Real Waveforms + Track Zoom

### Unified standard tracks

Tracks inserted from Track Library continue to be copied into the current
project as real `track_stems` rows. After reload they use the same left sidebar
row, arrangement lane, mixer channel, plugins, routing, automation, spectrum
meter and delete behavior as WAV/MP3/REAPER imported tracks.

### Musical timeline ruler

The timeline ruler now follows the project BPM and time signature.

It displays:

- measure numbers
- measure boundaries
- beat ticks when zoom provides enough room
- `measure.beat` labels at closer zoom levels
- half-beat/subdivision ticks at high zoom
- smaller time labels beneath measure numbers

The arrangement grid uses the same measure/beat/subdivision spacing. Detail is
progressive: zooming out hides dense subdivisions and zooming in reveals them.

### Wider zoom range + two-finger gestures

Timeline zoom now supports a wider 20%–1200% range.

Trackpad/touch gestures:

- two-finger spread outward → zoom out
- two-finger pinch inward → zoom in
- normal two-finger horizontal movement still scrolls the arrangement
- zoom is anchored around the gesture midpoint

The existing `+` / `−` zoom buttons remain available.

### Actual source waveforms

Source-stem clips now render amplitude data extracted from the real media
instead of a decorative waveform shape.

For WAV stems, `/api/stem-waveform-v49.php` reads RIFF/WAVE PCM or floating
point sample data and builds min/max peak buckets without loading the complete
production file into PHP memory.

For other browser-decodable media such as MP3, the client can fall back to Web
Audio decoding.

Waveforms respect the clip's actual source start/end range, so trimming,
splitting and extending redraw the visible audio section correctly.

Waveform loading is queued one stem at a time to avoid decoding many large files
simultaneously. v49 uses up to 2,400 peak buckets and preserves the strongest
per-channel min/max values, so stereo material does not visually collapse from
left/right phase cancellation.

### Ctrl/Cmd+T New Track

Inside Stem Studio:

- Ctrl+T on Windows/Linux
- Cmd+T on macOS

opens the one-file New Track media picker and suppresses the browser New Tab
shortcut.

### STEM UPDATED notice

Normal admin notices such as `Stem updated.` now dismiss automatically after
approximately 2.6 seconds. Success notices carry the auto-dismiss marker in
server-rendered markup, are removed by the global admin script, and also have a
CSS visibility fallback if JavaScript is delayed.

The v49 admin JS/CSS cache version is bumped so deployed browsers do not keep
the previous persistent-notice behavior.



### Load Song browser

The left Studio `Menu` now includes `Load Song`.

It opens a modal of uploaded songs/projects with:

- cover art
- song title / album
- BPM
- time signature
- imported-track count
- genre when available
- inline audio preview
- a 30-second sample limit per preview session
- `Load Song` action

Songs with a catalog master audio file preview through the tracks.manage-only `/admin-track-media-v49.php` byte-range endpoint. Projects
without a master file can preview their first protected active stem instead.
Only one Load Song preview can play at a time.

Loading a song opens that song's normal Stem Studio project, so its source
tracks appear in the left track column, arrangement and bottom mixer together.

### Track Settings horizontal-scroll repair

The compact Track Settings popup now forces a single-column 230px layout with
`min-width:0`, full-width fields and no horizontal overflow. The Save button no
longer expands the popup or creates a bottom horizontal scrollbar.

No SQL changes are required for v49. The completed v47 ownership upgrade
remains current.


## v50 — Load Song Repair + Header Cleanup

### Load Song reliability

The `Menu → Load Song` action is now bound through both the normal Studio menu
handler and a delegated fallback. The dialog is opened directly and is no
longer dependent on a fragile single menu binding.

The uploaded-song query was also simplified to avoid aggregate/grouping
compatibility issues on shared-host MySQL configurations. Track count and first
preview stem are now resolved with scalar subqueries.

### Header cleanup

The dedicated `Song Info` button was removed from the Studio header.

The project title now sits immediately to the right of the `Menu` button in one
compact horizontal header group.

Song Info remains available inside the Menu dropdown, so the feature is still
available without using extra header width.

No SQL changes are required for v50.


## v51 — Header Label Cleanup

Removed the `STONEFELLOW · STEM STUDIO` label from the Stem Studio header.
The current project title now sits directly beside the Menu button with no
secondary product label above it.

No SQL changes are required for v51.


## v52 — Full-Canvas Load Song Grid

`Menu → Load Song` now opens as a full-page Stem Studio canvas instead of a
small centered modal.

### Grid song browser

Uploaded songs/projects are displayed in a responsive cover-art grid.

Each song card includes:

- full cover art
- song title
- album
- BPM
- time signature
- imported-track count
- genre when available
- custom 30-second preview player
- Load Song / Reload action

The grid automatically increases/decreases its column count with the available
screen width.

### Custom Stonefellow audio player

The browser-native audio chrome has been replaced in the Load Song canvas with
a custom dark Studio player containing:

- Play / Pause
- 30-second sample status
- styled progress/scrub control
- elapsed time
- sample duration
- PLAYING / PAUSED / READY state

Only one song preview can play at a time.

No SQL changes are required for v52.


## v53 — USB Audio Interface Recording + Mixer Strip Cleanup

### Browser audio-interface recording

Stem Studio can now request the computer's audio input devices through the
browser media-device API. A Focusrite USB interface appears in the Studio
input selector when the operating system/browser exposes it as an audio input.

The lower Studio transport now includes:

- audio input selector
- Connect button for microphone/audio-interface permission
- live input meter
- Monitor toggle
- Record button
- per-track Record Arm (`R`) control

Right-clicking a normal Studio track also exposes `Arm Recording`.

Recording starts at the current Studio playhead. If an existing track is armed,
the new recording is named as a take of that track. With nothing armed, Studio
creates a new `Audio Recording N` track.

The capture path is:

`audio input → Web Audio PCM capture → streamed 16-bit chunks → protected
server storage → finalized WAV → track_stems`

The WAV is saved as a normal Studio stem, so after recording it receives the
same:

- left track row
- arrangement clip
- actual waveform rendering
- bottom mixer strip
- plugins
- automation
- BUS routing
- pan / trim / volume
- mute / solo
- split / trim / move / delete behavior

Recording media stays under `/uploads/stems` and is served through the existing
permission-protected range media endpoint.

Input monitoring is OFF by default to reduce feedback risk.

Browser recording requires HTTPS and browser permission. The browser sees the
audio inputs exposed by the operating system; direct ASIO-only channel routing
is outside the standard browser media API.

### Channel-strip cleanup

Removed the visible:

- polarity invert (`Ø`)
- mono mode

The plugin/channel strip now uses one compact `BUS` row. The selected route
name is displayed beside `BUS` instead of using only the dropdown icon.

### TRIM repair

The cramped horizontal TRIM slider was removed.

TRIM is now a dedicated rotary control beside PAN:

- -12 dB to +12 dB
- vertical drag with pointer capture
- keyboard arrows
- Shift for larger steps
- double-click / Home / `0` resets to 0 dB

This avoids the old one-move-then-dead slider behavior and keeps TRIM available
even when the plugin rack changes height.

No SQL changes are required for v53.


## v54 — Focusrite Input Discovery Repair

### Focusrite / USB input discovery

The recording input workflow now performs a permission-first device scan.

Browsers such as Chrome/Edge commonly hide USB audio-device labels until the
site has microphone permission. `CONNECT` now:

1. requests a short temporary audio-input permission stream
2. re-enumerates audio inputs after labels are unlocked
3. detects Focusrite-family device labels including Focusrite, Scarlett,
   Clarett, Vocaster and Saffire
4. automatically prefers the detected Focusrite-family input
5. closes the temporary permission stream
6. opens the selected hardware input directly
7. refreshes the selector using the actual active device ID
8. changes `CONNECT` to `RESCAN`

A previously saved generic/default microphone no longer takes priority over an
available Focusrite input during CONNECT/RESCAN.

Manual selection from the input dropdown still works and reconnects to that
exact device. If a browser lists a selected interface but cannot open it,
Studio reports that device as unavailable rather than silently recording from
a different default microphone.

### Thin-row cleanup

Removed the redundant:

- `N tracks`
- `SRC N BPM`

text from the thin mixer toolbar. Time signature and sample-rate information
remain, while tempo continues to be controlled by the dedicated TEMPO control.

No SQL changes are required for v54.


## v55 — Per-Track Audio Inputs

Audio input assignment now lives on each source mixer track rather than in the
global thin toolbar.

### Track-local INPUT dropdown

Every source track now has an `INPUT` dropdown immediately above its PAN/TRIM
controls.

After `CONNECT` / `RESCAN`:

- browser audio inputs are populated on every track
- Focusrite/Scarlett/Clarett/Vocaster/Saffire inputs are labeled clearly
- an available Focusrite-family input is preferred by default
- each track can choose a different browser-exposed input
- the selected device follows the track when that track is armed
- changing the input on the currently armed track reconnects that track to the
  selected device
- Record always verifies that the armed track's selected input is the active
  capture device before recording begins

Per-track input choices persist in local Studio state and Saved Mix state.

The hidden global input registry remains internal only so device scanning and
the existing Focusrite permission/reconnect logic can be shared by all tracks.

### PAN control

The PAN rotary control is now smaller to make room for the track-local INPUT
selector. TRIM remains beside PAN as the larger dedicated rotary control.

### Thin toolbar

The thin recording row now contains only:

- Connect / Rescan
- Monitor
- input meter
- recording status

Input-device selection itself is no longer shown globally.

No SQL changes are required for v55.


## v56 — Audio Permission Repair + Header Connect

### Audio connection moved to the header

The audio-device connection UI is no longer in the thin mixer toolbar.

The Studio header now contains:

- Connect Audio / Rescan Audio
- Monitor
- live input meter
- audio-input status

Per-track `INPUT` dropdowns remain in each source mixer strip and are still the
place where an individual track chooses its recording device.

### Permission-denied repair

The connection flow now distinguishes browser permission failures from device
or driver failures.

Before requesting audio, Studio checks:

- secure HTTPS context
- browser microphone permission state when the Permissions API is available

If microphone/audio-input permission is blocked, the header changes to
`Audio Blocked` and a permission-help dialog explains how to allow Microphone
access in the browser site controls and retry.

The error mapper also distinguishes:

- permission blocked / NotAllowedError
- insecure HTTP / SecurityError
- no device / NotFoundError
- device busy or unavailable / NotReadableError
- stale device selection / OverconstrainedError
- interrupted connection / AbortError

`Retry Audio` performs a fresh permission scan after the user changes the
browser permission.

The browser still cannot override a permission that the user or browser has
explicitly blocked; the repair makes that state visible and recoverable from
inside the Studio instead of returning a generic `Permission denied` message.

No SQL changes are required for v56.


## v57 — Microphone Permission False-Negative Repair

### Site says Allow but Studio reports denied

The browser Permissions API is no longer treated as the authority for microphone
access.

Some Chrome/Edge/Windows configurations can report
`navigator.permissions.query({name:"microphone"}) === "denied"` even while the
site-control UI says Microphone is allowed. v57 now treats that API result as
advisory only and always lets the real `getUserMedia()` request determine
whether audio input is available.

Stem Studio now also sends:

`Permissions-Policy: microphone=(self)`

from both the Studio PHP response and the root Apache `.htaccess` when
`mod_headers` is available. This prevents Stonefellow's own same-origin audio
input from being blocked by an omitted microphone policy.

If the actual `getUserMedia()` request still returns `NotAllowedError` while the
site setting says Allow, Studio now points to the next likely blocker: Windows
`Privacy & security → Microphone`, where browser/desktop microphone access must
also be enabled.

Iframe/embedded policy blocks are detected separately.

### Input UI readability

The per-track INPUT labels/dropdowns use larger text.

The header audio controls also use larger text for:

- Connect Audio / Rescan Audio
- Monitor
- input status

### PAN / TRIM sizing

PAN and TRIM now use the same compact 26px rotary-control size.

PAN is slightly larger than its previous compact version while TRIM is reduced
from its prior larger control.

No SQL changes are required for v57.


### Thin-row alignment

The transport/player-time block stays on the left of the thin mixer row.
Everything after it — session metadata and the editing/tool controls — is
pushed to the far right. On narrower windows the row can scroll horizontally
without changing the left/right grouping.


## v58 — Save / Save As Project Versions

### Header Save controls

Stem Studio now exposes `Save` and `Save As` directly in the header beside the
current project title.

`Save` updates the currently active saved Studio version. If the current
project has not been named/saved yet, `Save` opens the Save As dialog first.

`Save As` always creates a new named saved version and makes that new version
the active Save target.

The active saved-version ID and name are remembered per user + project in local
browser state so a normal page reload does not make the next Save create an
accidental duplicate.

### Saved Versions

The old `Saved Mixes` button has been removed from the thin mixer row.

Existing saved-version management is now available through:

`Menu → Saved Versions`

The existing save table continues to store the complete validated Studio state,
including clip layout, track order, plugins, custom buses, automation,
per-track input assignment, markers, regions, loop, tempo and mix values.

### Project owners

Saving is no longer limited to `track_notes.manage`.

A signed-in user may save versions when they:

- own the project track, or
- have `tracks.manage`, or
- have `track_notes.manage`

Saved versions remain scoped by both user ID and track ID.

### Shared-host compatibility

`api/stem-mix.php` now uses a safe string-cut helper that falls back to
`substr()` when `mbstring` is unavailable, matching Stonefellow's proven
HostGator compatibility approach.

No SQL changes are required for v58. The existing `stem_mix_saves` table is
reused.


## v59 — New Track Shortcut Repair

Chrome/Edge reserve `Ctrl+T` / `Cmd+T` for opening a new browser tab before a
normal web page can reliably cancel it. Stem Studio still intercepts
`Ctrl/Cmd+T` in environments where the browser delivers that key event, but it
cannot force Chrome to surrender the browser-level New Tab shortcut.

Stem Studio now provides a reliable in-page shortcut:

`Alt+T` → New Track

`Ctrl/Cmd+Alt+T` also resolves to New Track when delivered.

The New Track menu item remains available and now exposes the `Alt+T` shortcut
in its tooltip.

No SQL changes are required for v59.


## v60 — Track Inspector + Recording v2 + Fades/Crossfades

### Track Inspector

Stem Studio now has a right-side Track Inspector available from the header,
the track-row gear button, and the right-click track menu.

The Inspector follows the selected source track and exposes:

- track name and role editing
- per-track recording INPUT
- BUS routing
- volume
- pan
- trim
- Aux A / Aux B sends
- record arm
- mute / solo
- automation
- inserted plugins
- Add Plugin / Open Rack
- selected-clip gain
- selected-clip mute
- selected-clip fade in / fade out
- automatic crossfade for overlapping clips
- recording-v2 controls

The old floating Track Settings form was removed from track rows. Name/role
editing now belongs in the Inspector.

### Recording workflow v2

The recording workflow now includes:

- 0 / 1 / 2 / 4 bar count-in
- metronome during recording
- input clipping indicator
- recording countdown/status
- growing red recording region in the arrangement
- punch recording using the loop or selected clip range
- automatic punch-out at the end of the range
- per-project recording settings persistence
- recording settings included in Save / Save As
- take numbering
- take lanes immediately below the armed source track
- take renaming through Track Inspector

### New Track for live recording

`Menu → New Track` and `Alt+T` now create a real empty Studio recording track.
They no longer open an import file picker.

The new track:

- is inserted as a standard `track_stems` row
- receives the normal sidebar row, arrangement lane and mixer strip
- is automatically selected and armed
- uses a tiny protected silent WAV only so the existing secure media/DAW
  architecture can render the lane before audio exists
- is filled in-place with the recorded WAV after the first live recording

Recording onto an existing non-empty track creates a new take lane instead of
replacing that source media.

`Menu → Import Media` remains the path for WAV/MP3 imports and supports one or
multiple files.

### Clip fades and real crossfades

Standard source clips now persist:

- clip gain (-24 dB to +12 dB)
- clip mute
- fade-in length
- fade-out length

Fade handles are draggable directly on the clip and also editable from Track
Inspector.

When clips on the same source track overlap, Studio creates equal-power
fade-out/fade-in envelopes for the overlap. A second protected media decoder
and independent clip gain path are used during the overlap, so the crossfade
plays both clip source positions simultaneously rather than merely drawing a
visual transition.

The secondary decoder follows session tempo/pitch preservation and participates
in the same coordinated safe-seek architecture. There are still zero direct
`stem.audio.currentTime = ...` or crossfade-audio currentTime assignments.

### Open Project

`Menu → Open Project` now opens a full-screen saved-project canvas similar to
Load Song.

It shows only projects saved to the signed-in user's account in a responsive
grid with:

- cover art
- project name
- album
- track count
- BPM
- time signature
- updated date
- CURRENT state
- Open Project / Reload Project action
- project search

The old inline `MY PROJECTS` list was removed from the dropdown to keep the
Menu compact.

### Save / Saved Versions

The existing v58 Save / Save As workflow remains in the header and Saved
Versions remains under Menu. v60 extends the saved state to include recording
settings and clip gain/fade/mute data.

No SQL changes are required for v60.


## v61 — Sidebar/Arrangement Alignment Repair

The sample-rate resampling banner has been removed from Stem Studio. A project
may still contain media with a different source sample rate, but the browser's
normal playback resampling no longer generates a persistent Studio warning.

The left track list is now vertically aligned with the arrangement lanes.

The arrangement reserves:

- 24px marker/region lane
- 30px ruler
- 48px per normal track row

The left track panel now reserves the same 54px before its first track row, and
both sidebar and arrangement rows use explicit border-box 48px heights.
Automation-expanded rows remain matched at 150px on both sides.

This removes the previous 24px vertical offset and prevents cumulative drift
between sidebar track names and their corresponding arrangement clips.

No SQL changes are required for v61.


## v63 — Armed Recording + Playback Reliability Review

### Sidebar recording ARM

Every source track now has an `R` arm button in the left track sidebar in
addition to the mixer ARM control.

The sidebar and mixer ARM buttons share one recording target state. Arming a
track highlights the left row and mixer channel and immediately enables the
Record controls.

Record is disabled until a valid track is armed. The recording API also rejects
`recording_start` when no valid active `track_stems` target belongs to the
project, so this rule is enforced on both client and server.

### Stop → Save Recording / Discard

Stopping a recording no longer immediately finalizes the take.

After the final PCM chunk has uploaded, Studio requests server-side capture
status and verifies:

- PCM bytes exist
- the server byte count exactly matches the browser's captured byte count
- sample rate and channel count are known
- the recording target remains associated with the session

A modal then asks whether to save or discard the recording and allows the take
name to be edited.

`Save Recording` finalizes the protected WAV, updates the armed empty recording
track in place or creates a take lane below an existing source track, reloads
the Studio, and selects the saved recording for playback/editing.

`Discard` removes the temporary capture.

The prompt also reports capture duration, bytes, and detected input peak. A
very-low/no-signal warning is shown without preventing the user from saving.

### Recording reliability

The recording path now additionally includes:

- live input-track validation before capture
- explicit/discrete channel configuration when supported
- Focusrite/interface disconnect detection
- exact sequential chunk verification
- stale ScriptProcessor input connections removed between repeated takes
- active/unsaved recording navigation warning
- timeline/clip/transport editing locked during count-in and recording
- loop rewind disabled while recording
- seeking disabled while recording or while a stopped take is awaiting save

### Mute / Solo playback repair

The playback review found two independent causes of mute/solo recovery problems.

1. Before the Web Audio graph existed, the fallback mixer could set
   `HTMLMediaElement.muted` or `volume`. Those native values remained on the
   media element after `MediaElementSource` was created, so a track could stay
   silent even after the Web Audio gain node was unmuted.

2. `updateGains()` used the stored transport start position instead of the live
   playback position. Toggling mute/solo during playback could therefore
   recalculate clip/crossfade gain against the wrong part of the arrangement.

v63 clears native media mute/volume when Web Audio becomes authoritative,
calculates mute/solo state from the live transport time, respects the current
volume automation value, cancels stale output-gain ramps before a mute/solo
transition, and keeps the Inspector/sidebar/mixer state synchronized.

### Code review

The active Studio code was reviewed in three passes:

- v61 baseline: 7.8 / 10
- v62 repair pass: 9.6 / 10
- v63 final review: 10 / 10 under the release-readiness rubric

The final rubric contains 10 categories with 10 structural/regression checks
each. All 100 checks pass. All PHP and JavaScript files also pass syntax checks.

See `CODE_REVIEW_V63.md` for the complete review.

No SQL changes are required for v63.


## v64 — In-Place Live Recording

### Recording no longer creates a second track

A live recording now finalizes into the **armed track itself**.

`recording_finish` requires the original armed stem to still exist and updates
that exact `track_stems.id`. It no longer inserts a second take-lane stem during
recording finalization.

This fixes the previous behavior where recording on one track could produce a
second track after the Studio was reloaded.

### No page reload after Save Recording

`Save Recording` no longer redirects or reloads Stem Studio.

After the server finalizes the WAV, the browser updates the armed track in
place:

- same track/stem ID
- new protected media URL with a cache-busting version
- new duration and start offset
- new source/session tempo metadata
- arrangement clip replaced with the recorded media
- waveform invalidated and rebuilt from the new WAV
- track stays selected and armed
- mixer/Inspector/sidebar remain mounted
- local Studio state is saved immediately

The recording is therefore available for playback, trimming, fades,
automation, plugins and editing as soon as Save Recording completes.

### Live waveform while recording

The armed arrangement lane now shows a red live recording clip while capture is
running.

Each ScriptProcessor buffer contributes real input min/max peak data to the
live canvas. The clip grows from the recording start point and the waveform is
redrawn in real time from the actual captured input.

The timeline expands automatically when a recording runs beyond the previous
project end.

After Save Recording, the temporary live waveform is replaced by the normal
decoded/server waveform for the saved WAV.

### Space bar recording

When a track is armed:

`Space` → start recording  
`Space` again → stop recording

If no track is armed, Space keeps its normal Play/Pause transport behavior.

Space during count-in cancels the count-in. Space does nothing while a stopped
take is waiting for Save/Discard.

### Sidebar cleanup

The per-track delete `×` button has been removed from the left sidebar.
`Right-click track → Delete Track` remains the track deletion workflow.

No SQL changes are required for v64.


## v65 — Producer Accounts + Track Sharing

### Producer account type

Stonefellow now includes a first-class `producer` user/account type.

Admin → Users → Account Type includes:

- Fan
- Manager
- Producer
- Supervisor
- Investor
- Admin

The Producer role receives the scoped defaults:

- Account Dashboard
- Admin shell access
- Producer Workspace

It does **not** receive the global `tracks.manage` permission by default.

### Share a track with a Producer

Each track now has:

`tracks.producer_user_id`

Admin → Tracks → Edit Track includes a **Share with Producer** dropdown that
lists every Producer account in the system. Disabled Producer accounts remain
visible for history but cannot be newly selected.

Saving the assignment:

- validates that the selected user is an active Producer
- stores the Producer on the track
- sends the Producer a Stonefellow notification
- immediately grants scoped production access to that track
- immediately revokes the previous Producer's access when reassigned/unshared

This implementation assigns one Producer per track.

### Producer Workspace

Producer accounts log directly into:

`/admin/producer-tracks.php`

Only tracks where `tracks.producer_user_id` matches the signed-in Producer are
shown.

From the workspace the Producer can:

- open Song Details
- edit production metadata
- open and edit the shared track in Stem Studio
- record/import audio
- add/delete/edit Studio tracks
- use plugins, buses, automation, fades and crossfades
- Save / Save As Studio versions
- export MP3/WAV source files

The Producer cannot:

- create catalog projects
- take ownership of the artist's project
- delete the catalog project
- edit publishing/visibility
- reassign the Producer
- access tracks that were not shared with their account

### Scoped Stem Studio authorization

Stem Studio no longer relies only on the global `tracks.manage` permission.

The authorization rule is:

1. global track managers can manage any track
2. a Producer with `producer.access` can manage only a track whose
   `producer_user_id` equals their own user ID
3. every protected Studio/API/media endpoint independently enforces the same
   track assignment

Scoped authorization covers:

- Stem Studio page
- project API
- Saved Versions API
- protected stem media
- waveform endpoint
- management audio/cover preview
- Track Library source selection
- Load Song / Open Project discovery
- audio exports

Forging another track/stem ID therefore does not expand a Producer's access.

### Producer editing

Song Details exposes a Producer Edit panel for assigned Producer accounts.

A Producer can edit:

- title
- album/collection
- description
- genre
- mood
- energy
- tempo BPM
- recommendation keywords

Publishing, visibility, ownership, media assignment and Producer sharing remain
under track management control.

Audio editing happens in the full Stem Studio.

### MP3/WAV export

Stem Studio → **Export Audio Files** opens an export dialog containing the
actual source files attached to the shared project.

An assigned Producer can download:

- catalog master/source MP3 or WAV, when available
- each active Stem Studio MP3/WAV file
- a ZIP containing all exportable MP3/WAV files when the server has
  `ZipArchive`

The export endpoint validates track assignment again and only reads approved
local MP3/WAV paths.

These are source-file exports. They are not a newly rendered post-plugin
mixdown.

### Database upgrade

v65 adds:

- `producer.access` permission
- `producer` default role permissions
- `tracks.producer_user_id`
- `idx_tracks_producer_updated`
- `fk_tracks_producer`

Run `/upgrade.php` after deployment, or apply:

`upgrade-stonefellow-v65.sql`

No existing track ownership data is changed.


## v66 — Agent Chat Live Updates

### Near-real-time Agent Chat polling

Agent Chat now contains a sticky **Agent Updates** shelf.

While Chat is open, the browser polls the existing authenticated Chat API every
3 seconds. Polling pauses while the tab is hidden and runs immediately when the
tab becomes visible again.

On each Chat page load, the shelf first shows up to 10 matching activity events
from the previous 7 days, then switches to ID-cursor polling so only new events
are fetched.

The polling endpoint is:

`POST /api/chat.php`
`action=activity`

It remains:

- signed-in-user scoped
- CSRF protected
- no-store
- limited to Agent activity event types
- limited to 30 new events per poll

No websocket or external service is required, so this works on the current
shared-host PHP deployment.

### Producer sharing updates

When a track manager changes **Share with Producer**:

- the selected Producer still receives the normal Producer notification
- the acting manager receives an Agent Chat activity update
- the track owner also receives that Agent Chat activity update when different
  from the acting manager
- removing Producer access generates an Agent Chat update as well

Producer accounts also see their existing `producer_track_share` notification
inside Agent Chat, without creating a second duplicate bell notification.

Example:

`Track shared with Producer`
`My Song was shared with Producer Name.`

### Supervisor listening updates

When a signed-in **Supervisor** starts playback, Stonefellow now creates an
Agent Chat activity event for the track owner.

The event reports:

- Supervisor display name
- track title
- whether listening started in Agent Chat or the Music Player
- a direct link to Song Details

Each playback session uses its own notification source ID, so the same playback
session cannot create duplicate events.

For tracks without an owner, the event falls back to active account types with
the default `tracks.manage` permission.

### Agent Updates UI

Agent Updates are rendered independently from conversation messages, so loading
or deleting a conversation does not erase the activity shelf.

The shelf is sticky within Chat and includes:

- live/reconnecting state
- Producer activity
- Supervisor listening activity
- time
- concise event description
- Open link
- current unread notification badge

The existing notification center remains unchanged. Showing an update in Agent
Chat does not automatically mark its bell notification as read.

### Existing functionality preserved

v66 retains:

- Producer account sharing and scoped editing
- Producer MP3/WAV export
- live Stem Studio recording
- Space-bar armed recording
- post-record Save / Discard
- mute/solo playback repair
- safe coordinated seeking
- real waveforms
- fades/crossfades
- Save / Save As
- Open Project
- Load Song

No SQL changes are required for v66. The existing `notifications` table is used
as the durable Agent activity event source.


## v67 — Authenticated Agent Shell

### Agent Chat is the signed-in home

Agent Chat is now the default authenticated landing page.

Every recognized Stonefellow account type can enter Agent Chat, including:

- Fan
- Manager
- Producer
- Supervisor
- Investor
- Admin

This makes Chat the private application shell rather than another optional page.

### Logged-in users cannot browse logged-out screens

When a user is signed in, these public/logged-out pages redirect back to the
authenticated landing page:

- Home
- Artist Bio
- Shows
- Contact

The Music Player remains available while signed in.

The normal public header also changes while authenticated so the Player page
does not advertise Home/About/Shows/Contact links that the user can no longer
open. Signed-in navigation instead exposes Agent Chat, Player, My Account and
authorized private workspaces.

### My Account redesigned into the Agent Chat shell

`/account.php` no longer uses the public website header/footer design.

It now uses the same:

- full-height left sidebar
- dark Agent Chat canvas
- 58px authenticated top bar
- notification button
- user avatar menu
- mobile sidebar behavior

The profile content lives in the main canvas and retains:

- profile photo upload/remove
- display name
- email
- password change
- account-specific access cards

The Account access area no longer links back to logged-out Shows.

### Agent Chat notification dropdown

The bell icon is now a real button/dropdown rather than a direct link.

It shows:

- current unread count
- six recent notifications
- unread state
- title/body
- direct notification open links
- View All Notifications

The live Agent Update polling from v66 continues to refresh the badge count.

### Agent Chat user profile dropdown

The avatar is now a functional profile menu.

Depending on account permissions it can contain:

- My Account
- Music Player
- Producer Workspace
- Investor Area
- Admin Dashboard
- Log Out

The menu closes on outside click or Escape and coordinates with the
notification dropdown.

### Chat cleanup

The **Add Knowledge** button has been removed from the Agent Chat top bar.

Admin Knowledge remains available through the Admin area, and Agent Chat
continues using permitted Knowledge content internally.

### Database

No SQL changes are required for v67. Agent Chat is intentionally treated as a
core authenticated shell for all recognized account types in application
authorization.


## v68 — Chat Canvas Navigation

### Sidebar split

Agent Chat's left sidebar is now divided into two equal working regions.

Top half — **Explore**

- New Chat
- Tracks
- Shows
- Photos
- About

Bottom half — **Chats**

- saved conversation history
- conversation delete controls

The old bottom sidebar links and the user profile card have been removed.

### New Chat

New Chat is now a normal top navigation item.

The old bordered/button treatment and its dedicated `.new-chat-button` CSS were
removed.

### Tracks inside the Chat canvas

Selecting Tracks no longer sends the user to a logged-out/public page.

The main Agent Chat canvas switches to a new account-aware music view with:

- cover artwork
- track title
- album
- available genre/mood/description
- secure playable audio
- playback analytics through the existing Chat playback path
- Full Player link

### Shows inside the Chat canvas

Shows renders upcoming Stonefellow dates directly in the authenticated canvas.

It includes:

- date
- time
- venue
- location
- show notes
- ticket link when configured

### Photos inside the Chat canvas

Photos renders Stonefellow visual content without leaving the authenticated
workspace.

The initial view uses:

- Stonefellow studio imagery
- track artwork available to the signed-in account

This is intentionally designed as a gallery component so dedicated artist-photo
records can be added later without replacing the UI.

### About inside the Chat canvas

About replaces the need to send authenticated users back to the public Artist
Bio page.

It contains:

- Stonefellow artist overview
- current tagline/bio content
- Agent Chat capabilities
- Producer-share update explanation
- Supervisor-listening update explanation
- knowledge/access context

### Chat canvas behavior

Switching to Tracks, Shows, Photos or About:

- keeps the browser on `/chat.php`
- hides the Chat composer
- hides conversation messages
- pauses active Chat audio
- keeps conversation state intact

Opening a saved conversation or New Chat restores the normal Chat canvas and
composer.

### Header cleanup

The visible:

`Stonefellow Chat`
`Database + knowledge`

header copy was removed. The top bar now stays visually quiet and keeps only
the mobile navigation control, notifications and user profile controls.

### Account shell cleanup

The My Account sidebar no longer has bottom navigation links or the user-card
block. The Account canvas and profile/security controls remain unchanged.

### Database

No SQL changes are required for v68.


## v69 — Sidebar Spacing + Persistent Chat Footer

### Chat history spacing

The Chats half of the Agent Chat sidebar no longer stretches a short list of
conversation rows to fill the available half-height.

The history grid now uses:

- `align-content:start`
- `grid-auto-rows:max-content`
- compact 36px conversation rows
- consistent 2px gaps

This keeps every conversation aligned naturally at the top of the Chats area.

### Persistent chat footer

The Agent Chat composer now remains visible and sticky at the bottom of the
authenticated Chat shell for:

- New Chat
- Tracks
- Shows
- Photos
- About

The content canvas remains independently scrollable above it.

### Stem Studio exit

`Exit Studio` now always returns to `/chat.php`, making Agent Chat the single
authenticated home destination for managers, producers and other authorized
users.

No SQL changes are required for v69.


## v70 — Custom Audio Player + Create Menu + Photo Library

### Custom dark Agent Chat audio player

Agent Chat no longer exposes the browser-native white `<audio controls>` UI.

Every playable Chat track and the authenticated Tracks canvas now receives a
Stonefellow custom player with:

- dark Play / Pause
- Previous / Next
- draggable seek bar
- elapsed and total time
- Mute
- volume slider
- animated playing bars
- active-card highlight
- listening analytics preserved
- automatic next-track playback preserved

The underlying HTML audio element remains the actual media engine but is hidden
from the UI.

### Persistent Now Playing

The sticky Chat footer now has a persistent Now Playing strip whenever audio has
been started.

It shows:

- cover artwork
- track title
- album
- queue position such as `2 of 4`
- Previous / Play-Pause / Next
- timeline seek
- elapsed / duration
- Mute

Playback continues when switching between Chat, Tracks, Shows, Photos and About.
It also survives switching the visible conversation while the source player is
still active.

Analytics cleanup includes detached message-player audio so a playing track
cannot escape stop/session cleanup on page exit.

### Cleaner music answers

Normal music/library questions no longer inject raw production metadata into
the answer context.

For example, Agent Chat no longer dumps:

- empty Genre/Mood/Energy fields
- `Channels: 0`
- `Sample rate: 0 Hz`
- bit-depth placeholders
- REAPER project details
- stem rows

unless the user explicitly asks a production question involving stems,
recording, mixing, REAPER, channels, sample rates, plugins, takes or related
production terms.

Track summaries now include only meaningful populated metadata.

### Agent Chat Create menu

Authorized users now have a `+` button in the Agent Chat header.

The Create dropdown exposes only actions the signed-in account is permitted to
use:

- Track
- Event
- Knowledge Base
- User
- Merch
- Photo

Admin sees all six.

Each item opens the existing/new Admin add form directly.

### Admin Photos

Main Admin now includes a Photos section.

Photos support:

- JPG / PNG / WEBP uploads
- title
- caption
- alt text
- visibility
- sort order
- Published / Draft
- edit
- delete
- protected image delivery

Agent Chat → Photos now reads the managed photo library. If the library is
empty, the prior studio/track-artwork fallback remains.

### Admin Merch

Main Admin also includes a Merch content section so the Agent Chat `+ → Merch`
action has a complete destination.

Merch supports:

- item name
- description
- price
- checkout/product URL
- image
- visibility
- sort order
- Published / Draft
- edit/delete

### Permissions

v70 adds:

- `photos.manage`
- `merch.manage`

Manager and Supervisor receive these defaults. Admin continues to receive every
permission automatically.

### Database upgrade

v70 adds:

- `photos`
- `merch_items`

Run `/upgrade.php` after deployment, or import:

`upgrade-stonefellow-v70.sql`


## v72 — Albums + User Playlists + Complete Inline Create Forms

### Complete `+` popup-create system

The Agent Chat header `+` menu now opens an inline modal form for every
supported create type instead of sending the user to Admin first.

The modal system now includes:

- Track
- Album
- Playlist
- Event
- Knowledge Base
- User
- Merch
- Photo

Each entry is permission-aware except Playlist, which is a signed-in
user-generated content type available to every account that can use Agent Chat.

The full Admin editor remains available as an optional secondary link for
managed content types.

### Albums

v72 introduces a real `albums` content type.

Admin now has:

`Admin → Albums`

Album fields include:

- title
- release date
- description
- album cover
- visibility
- sort order
- Published / Draft

Admin can assign tracks directly from the album editor.

Selecting a track:

- writes `tracks.album_id`
- synchronizes the existing `tracks.album` display label
- moves the track from another album if necessary

Removing a track from an album clears its managed album assignment while
leaving the track itself intact.

Track Edit also has a managed Album dropdown, so assignment works from either
side.

The Agent Chat `+ → Album` form also supports immediate track assignment.

### Albums in Agent Chat

Albums is now an authenticated Agent Chat sidebar/canvas view.

Each album shows:

- cover
- title
- release date
- description
- assigned tracks
- the custom dark inline audio player
- queue-aware Now Playing behavior

Users only see album content permitted by the existing visibility rules.
Album managers can also see draft albums in the authenticated workspace.

### User-generated Playlists

v72 adds persistent user playlists.

Every signed-in Agent Chat user can choose:

`+ → Playlist`

The inline Playlist form supports:

- playlist title
- description
- Public or Signed-In visibility
- multi-track selection from music available to that account

Data is stored in:

- `playlists`
- `playlist_tracks`

Playlist ownership is tied to the signed-in user.

Track IDs submitted to the create endpoint are revalidated against the user's
actual track visibility before insertion.

### Playlists in Agent Chat

A new Playlists sidebar/canvas view shows the signed-in user's saved playlists.

Each playlist displays:

- name
- description
- visibility
- track count
- ordered playable tracks
- custom inline audio controls
- Previous / Next queue behavior
- persistent Now Playing integration

### Track creation

The inline Add Track form now uses the managed Album list rather than relying
only on a free-text album field.

The full Track editor also has the same managed Album dropdown.

### Album security

Album track assignment is limited to tracks the current account can actually
manage through `can_manage_track_production()`.

This prevents a custom account that receives `albums.manage` from using forged
track IDs to reassign unrelated production projects.

Album covers are stored under the protected upload tree and delivered through
the visibility-aware `/content-image.php?type=album&id=...` endpoint.

### Database upgrade

v72 adds:

- `albums.manage`
- `albums`
- `tracks.album_id`
- `idx_tracks_album_sort`
- `fk_tracks_album`
- `playlists`
- `playlist_tracks`

Run `/upgrade.php` after deployment or import:

`upgrade-stonefellow-v72.sql`

The v72 SQL assumes the previous v70 Photos/Merch database upgrade is already
present.


## v73 — Authenticated Player + Favorites

### Agent Chat Player replaces Tracks

The Agent Chat sidebar no longer has separate **Tracks** and **Albums** links.

The music destination is now:

`Player`

The previous Albums sidebar link was removed. Albums still exist as managed
content and are surfaced inside the Player itself.

### Sidebar spacing

Explore navigation rows are 2px tighter than v72.

Saved Chat history rows are also reduced from 36px to 34px while preserving the
top-packed history layout from v69.

### Notification icon

The Agent Chat notification bell is now transparent.

The bell button keeps only its outline, and the unread badge uses a transparent
fill with an outlined count treatment.

### Player inside Agent Chat

The former public Player experience is consolidated into the authenticated
Agent Chat canvas.

`/player.php` is no longer a public music page. It now requires login and
redirects to:

`/chat.php?view=player`

Logged-out Home/About/Shows/Contact navigation no longer advertises a Player
page.

All authenticated Player links now open the Agent Chat Player canvas.

### Newest tracks hero

The top Player area shows the newest available Stonefellow music.

It includes:

- one large newest-track hero
- up to four additional new-release cards
- direct play controls
- favorite controls
- the existing custom dark audio player
- persistent Now Playing integration

### Albums

Managed Albums are shown as a dedicated section inside Player.

Album tiles include:

- cover art
- album title
- assigned track count

Admins/managers with `albums.manage` can still use **+ Add Album** directly from
the Player section.

### Most Popular

Player now ranks popular tracks using the existing listening analytics data.

Ranking priority is:

1. qualified plays
2. total playback sessions
3. listened seconds

If no listening data exists yet, the section gracefully falls back to available
new/recent tracks.

### User Favorites

v73 adds persistent track favorites.

Signed-in users can favorite/unfavorite tracks from Player using the heart
control.

Favorites are stored in:

`track_favorites`

The Player's **Your Favorites** section updates immediately without a page
reload.

Favorite state is synchronized across repeated appearances of the same track,
including newest, popular, favorites and all-track sections.

### All Tracks

Player retains a complete **All Tracks** library so the consolidated Player does
not lose the functionality of the previous Tracks canvas/public Player.

The library includes:

- artwork
- title
- album
- duration
- favorite
- custom player controls
- Stem Studio shortcut when authorized

### Search

Player includes an in-canvas track search filtering newest, popular, favorites
and all-track cards.

### Queue behavior

Previous / Next controls are now scoped to the current Player section.

For example:

- Newest stays within Newest
- Popular stays within Most Popular
- Favorites stays within visible favorites
- All Tracks stays within All Tracks

Hidden non-favorite cards are excluded from the Favorites queue.

### Database

v73 adds:

- `track_favorites`

Run `/upgrade.php` after deployment or import:

`upgrade-stonefellow-v73.sql`

The incremental v73 SQL assumes the v72 database upgrade has already been
installed.


## v75 — Desktop-Only Stem Studio + Header Stacking

### Stem Studio is desktop-only

Stem Studio controls are no longer exposed on phone-sized layouts.

At widths of 820px and below, authenticated Chat and Admin surfaces hide:

- Stem Studio buttons
- Open Stem Studio actions
- Edit in Stem Studio actions
- Studio links in the Player
- Studio-routed export buttons
- dynamically rendered Chat Stem Studio actions

Desktop behavior is unchanged.

### User-menu stacking

The Agent Chat authenticated header now has its own high stacking context.

The following dropdowns always open above Player/Chat canvas content:

- User profile
- Notifications
- `+` Create

This fixes the profile menu appearing behind Player cards/artwork.

### Database

No SQL changes are required for v75.

## v76 — Complete Fan Music Workspace

v76 turns the authenticated Agent Chat Player into a complete fan music workspace.

### Player expansion

The Player now includes:

- Newest Tracks hero
- Continue / Recently Played history with the actual latest-session resume position
- For You personalization based on favorites, qualified listens, completed listens, repeat listening, genre/mood affinity and recent releases
- Albums
- Most Popular
- Your Favorites
- Saved Albums + Saved Playlists
- Your Listening stats
- Artist Updates
- contextual Merch
- All Tracks

### Album detail

Clicking an album opens an in-canvas detail drawer with:

- cover
- release date
- description
- complete album track list
- Play Album
- Favorite Album
- Add Album to Playlist
- track action menus
- associated album/track merch

### Up Next / Queue

The persistent Now Playing strip opens an Up Next queue.

Queue features:

- Play Next
- Add to Queue
- remove
- reorder with controls or drag/drop
- Clear Queue
- Previous / Next integration
- persistence in user-scoped localStorage

### Playlist management

User playlists can now be fully managed from Agent Chat:

- rename
- edit description
- Public / Signed-In visibility
- add tracks
- remove tracks
- drag to reorder
- Play All
- duplicate
- delete
- share public playlists
- favorite playlists
- play public playlists shared by other listeners

If a private playlist is shared, the app asks before converting it to Public so the copied share link actually works for another listener.

### Track actions + detail

Every Player track receives a compact `•••` action menu with:

- Play Next
- Add to Queue
- Add to Playlist
- Favorite
- View Album
- Track Info
- Lyrics
- Stem Studio when desktop + authorized

Track Info opens in the Player canvas and can display:

- cover
- title / album
- genre
- mood
- energy
- BPM
- duration
- description
- credits
- related tracks
- contextual merch

### Lyrics

Lyrics now have an in-canvas view tied to playback.

Timestamped lyrics using `[mm:ss.xx]` follow exact media time. Untimed lyrics follow playback proportionally. The active line only scrolls when it changes, avoiding continuous scroll jitter.

### Favorites

v76 extends favorites beyond tracks:

- `album_favorites`
- `playlist_favorites`

Saved collections appear directly in Player.

### Show reminders

Users can choose **Remind Me** on upcoming shows.

Reminders are stored in `show_reminders`. Agent Chat polling checks due reminders and creates an Agent Chat notification within 48 hours of the show.

### New release notifications

Publishing a new Track or Album generates visibility-aware Agent Chat release notifications for users who can actually access that content.

Draft-to-published transitions notify once. Notification source deduplication prevents repeat alerts from later edits.

### Artist Posts / Updates

v76 adds a complete artist update system:

- `artist_posts`
- Admin → Posts
- `posts.manage`
- Agent Chat `+ → Post`
- update / studio / release / show / photo / video post types
- protected post images
- optional media URL
- visibility
- Draft / Published
- live Player Updates feed
- visibility-aware `artist_post` Agent Chat notifications

### Contextual Merch

Merch can now be associated with:

- an Album
- a Track
- both
- neither (global Stonefellow merch)

The Player still has a global Merch section while Album and Track detail drawers surface the merch relevant to that specific music context.

### User listening stats

The Player's **Your Listening** section includes:

- minutes listened
- qualified 10s+ plays
- completed tracks
- tracks explored
- track favorites
- playlists created
- most-played track
- most-played album

### Admin Music Analytics

Admin now has **Music Analytics** with:

- playback sessions
- qualified plays
- completed plays
- listening hours
- track favorites
- playlist adds
- repeat listeners
- track-level completion rate
- skip count
- favorites
- playlist adds
- Stem Studio save count
- album engagement

### Database upgrade

v76 adds:

- `posts.manage`
- `album_favorites`
- `playlist_favorites`
- `show_reminders`
- `artist_posts`
- `merch_items.album_id`
- `merch_items.track_id`

Run `/upgrade.php` after deployment or import:

`upgrade-stonefellow-v76.sql`

## v77 — Compact Track Player + Global Volume

### All Tracks row layout

The Player's All Tracks rows have been rebuilt so the visual hierarchy is clean
and consistent:

- track number stays on the far left
- cover artwork spans the full row height
- track title and album are left aligned above the scrubber/timeline
- duration, favorite and authorized Studio action remain compact
- the `•••` track-action button is anchored on the far right
- the audio transport stays on the same row instead of dropping into a loose
  second card

### Per-track volume removed

Individual track players no longer show a `VOL` button or horizontal volume
slider. Each inline player is now limited to transport, play state, timeline,
time and previous/next controls.

### Global Player volume

Volume now belongs to the persistent Now Playing Player.

The footer shows only a speaker icon. Clicking it opens a compact vertical
volume slider above the icon. The selected volume is applied to every track,
including newly created audio elements, and is remembered for the signed-in
user in local storage.

The queue label remains the queue control; the duplicate queue-button ID from
v76 has also been removed.

### Database

No SQL changes are required for v77.

