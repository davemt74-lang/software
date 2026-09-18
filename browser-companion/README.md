# VP3 Browser Companion v20.70

Chrome Manifest V3 companion for VP3 Browser Share and the Source Feed layer: This Page, Following, source/user follows, comments, read state, Private/Team/Public publishing, plus highlighted text, screenshot regions, source-media moments, and voice commentary.

## Capture and share

1. Load the extension in Chrome.
2. Open the side panel and connect the browser to VP3.
3. Approve the connection in the VP3 tab that opens.
4. Open any normal HTTP(S) page and choose one or more capture types:
   - highlight text;
   - **Screenshot region** and drag over the visible area to keep;
   - **Media moment** on a detected YouTube, audio, or video page and set a source timestamp window up to 90 seconds;
   - **Voice commentary** to record an audio note up to 90 seconds.
5. Choose a Team or conversation, optionally add a note, and share.
6. Use **Ask VP3**, **Save to Knowledge**, **Create Task**, **Open source**, or **Open in VP3 Messages**.

The existing right-click **Share selection with VP3** path remains available for text selections.

## What rich capture stores

Browser Share remains the canonical share/message object. Rich capture does not place screenshots or audio blobs into Browser Share text fields.

- Screenshot bytes and voice commentary are private media attachments linked to the Browser Share.
- YouTube/audio/video moments are source references with bounded start/end timestamps. v20.40 does not download or rip third-party media streams.
- If a share contains no highlighted text, the canonical Browser Share receives only a short readable fallback such as “Screenshot captured from …” while the actual media remains in the private media layer.
- Media reads always re-resolve the current Browser Share authorization. Deleting the share or losing conversation access also removes access to the attachment.
- Binary attachment retries are idempotent per Browser Share, media kind, and content hash.

## Private media storage

The default private media directory is outside the application web root:

`../vp3-private/browser-share-media`

A production installation can override it in `config.php`:

```php
'uploads' => [
    // existing upload settings...
    'browser_share_private_root' => '/absolute/private/path/browser-share-media',
],
```

The PHP/web worker user needs read/write access to this directory. Files are created with private permissions and are served only through the live-authorized VP3 media endpoint.

After deploying v20.40, run the normal `/upgrade.php` flow once to install `browser_share_media_v2040` and `browser_share_media_jobs_v2040`.

## Media inspection worker

Binary screenshot/commentary uploads enter `processing` state and are inspected by the CLI worker before becoming `ready`:

```bash
php jobs/browser-share-media-worker-v2040.php 20
```

The optional numeric argument controls the maximum number of jobs processed in that run, capped at 50. A normal production scheduler can invoke this command repeatedly. Failed inspections retry up to three times with a delay and then move to `failed`.

## Local Chrome installation

Open `chrome://extensions`, enable **Developer mode**, choose **Load unpacked**, and select this `browser-companion` directory. The CI package `vp3-browser-companion-v20.70.0.zip` is directly loadable/extractable and contains `manifest.json` at the ZIP root.

The default VP3 site is `https://vp3.me`. Another HTTPS VP3 installation can be selected in Extension Settings. Local development may use `http://localhost` or `http://127.0.0.1`; Chrome asks for explicit access to the selected origin.

## Connection security

The extension never receives or stores a VP3 password. Pairing uses the existing Browser Companion approval flow. The server delivers a device credential once; the extension stores it in `chrome.storage.local` and exchanges it for short-lived bearer sessions. Every authenticated request intersects the device's approved scopes with the user's current VP3 permissions.

Production VP3 installations should configure the published extension's exact `chrome-extension://...` origin. For unpacked development builds, the existing `extension_allow_unlisted_chrome_origins` site setting can be enabled temporarily. Production should remain fail-closed.

Disconnecting from the extension calls the VP3 self-revoke endpoint before local credentials are removed, revoking active sessions for that browser.

## Privacy and safety boundaries

- Browser Share accepts only HTTP(S) source URLs.
- Selection payloads remain bounded to 32,768 UTF-8 bytes; notes remain bounded to 4,096 UTF-8 bytes.
- Screenshot uploads are bounded to 8 MB and PNG/JPEG/WebP.
- Voice commentary uploads are bounded to 16 MB and an explicit audio MIME allowlist.
- Region screenshots are cropped locally from Chrome's visible-tab capture.
- Source-media windows are clamped server-side to 90 seconds and store timestamps rather than copied third-party media.
- The extension does not request `tabCapture` and does not call media-element `captureStream()`.
- **Ask VP3** passes only the Browser Share public ID into the web handoff. VP3 re-resolves authorization and current share state before Agent Chat receives the content.
- Knowledge and Task actions continue to reuse the existing Personal Knowledge and Agent Workflow systems.

## Phase 6 — This Page + Following

- **This Page** resolves the canonical source without storing passive browsing history. A Source row is persisted only when a user explicitly publishes or follows.
- **Following** is generated from followed Sources and VP3 users using the same Browser Share + publication model used by the website.
- Delivery destination and feed visibility are independent. Visibility is **Private**, **Team**, or **Public** and is always enforced server-side.
- Source versions use SHA-256 page-text fingerprints when Browser Companion can inspect the page; VP3 stores the fingerprint, not the page body.
- Comments/replies, Save, Add to Research, Follow/Unfollow, read state, Share with Team, Ask VP3, and Save to Knowledge re-resolve current authorization on every action.
- Public/source views use `/source.php`; annotation context uses `/annotation.php`.

After deploying v20.50, run `/upgrade.php` once to install the Browser Source Feed tables and backfill canonical Source links for existing Phase 4/5 Browser Shares.


## Phase 7 — Research Projects + Publishing

- **Add to Research** now opens a project chooser instead of only toggling a generic saved state.
- Send an annotation to the **Research Inbox**, assign it directly to an existing project, or **Create project + add** without leaving the sidebar.
- Project assignment references the canonical Browser Share, Source, and pinned Source Version; Research does not copy captured text into a second extension data store.
- Project roles are **Owner**, **Admin**, **Researcher**, and **Viewer**. Team projects also revalidate live Team membership.
- Assigning an annotation automatically adds its canonical Source to the project evidence base.
- Findings move from **Draft → Confirmed → Published** and can link supporting, conflicting, and contextual evidence with pinned Source Versions.
- Reports are composed from selected Findings, Sources, and annotations. Every publish creates a new immutable JSON snapshot with a SHA-256 integrity hash.
- Report visibility is **Private**, **Team**, or **Public**. Incompatible annotation text is redacted back to the pinned Source instead of leaking broader content.
- Unpublishing removes current public/team availability but preserves historical report versions and hashes.
- Canonical Source pages surface authorized published Research reports that cite that Source.

After deploying v20.60, run `/upgrade.php` once to install the Research Project, Finding, evidence, report, immutable report-version, and report-source provenance tables.


## Phase 8 — Live Rooms + Cloak Mode

- The sidebar now includes a **Live** tab for the current canonical Source.
- Start or join **Public** and **Team** rooms without introducing a separate source/capture store.
- Live messages use a lightweight cursor poll; presence is refreshed separately by heartbeat and expires when the participant disappears.
- **Cloak Mode** uses a room-specific pseudonym. Participant payloads never expose the cloaked user's VP3 user ID or real display name.
- Every message snapshots its cloaked/visible sender identity at send time, so disabling Cloak later does not retroactively deanonymize earlier cloaked messages.
- Public rooms can be read on the web without signing in; joining, presence, Cloak Mode, and posting require an authenticated VP3 account.
- Team rooms revalidate live Team authorization server-side.
- Sharing an annotation into Live never republishes it. Public rooms accept only already-Public annotations; Team rooms accept Public or same-Team annotations.
- Canonical Source pages surface active authorized Live Rooms.
- Website surfaces are `/live.php` and `/live-room.php`.

After deploying v20.70, run `/upgrade.php` once to install the Live Room, participant/presence, and message tables.
