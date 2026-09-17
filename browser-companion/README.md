# VP3 Browser Companion v20.30

Chrome Manifest V3 companion for the VP3 Browser Share workflow.

## First usable loop

1. Load the extension in Chrome.
2. Open the side panel and connect the browser to VP3.
3. Approve the connection in the VP3 tab that opens.
4. Highlight text on any normal HTTP(S) page.
5. Either open the VP3 side panel and refresh the selection, or right-click the selection and choose **Share selection with VP3**.
6. Choose a Team or conversation, optionally add a note, and share.
7. Use **Ask VP3**, **Save to Knowledge**, **Create Task**, **Open source**, or **Open in VP3 Messages**.

## Local installation

Open `chrome://extensions`, enable **Developer mode**, choose **Load unpacked**, and select this `browser-companion` directory.

The default VP3 site is `https://vp3.me`. Another HTTPS VP3 installation can be selected in Extension Settings. Local development may use `http://localhost` or `http://127.0.0.1`; Chrome asks for explicit access to the selected origin.

## Connection security

The extension never receives or stores a VP3 password. Pairing uses the existing Browser Companion approval flow. The server delivers a device credential once; the extension stores it in `chrome.storage.local` and exchanges it for short-lived bearer sessions. Every authenticated request intersects the device's approved scopes with the user's current VP3 permissions.

Production VP3 installations should configure the published extension's exact `chrome-extension://...` origin. For unpacked development builds, the existing `extension_allow_unlisted_chrome_origins` site setting can be enabled temporarily. Production should remain fail-closed.

Disconnecting from the extension calls the VP3 self-revoke endpoint before local credentials are removed, revoking active sessions for that browser.

## Privacy boundaries

- Only a user-selected text capture is sent in v20.30.
- Browser Share accepts only HTTP(S) source URLs.
- Selection payloads are bounded to 32,768 UTF-8 bytes; notes are bounded to 4,096 UTF-8 bytes.
- Browser Share content is stored by the existing immutable Browser Share backend and linked to canonical Human Messaging; the extension adds no parallel content store.
- **Ask VP3** passes only the Browser Share public ID into the web handoff. VP3 re-resolves authorization and current share state before Agent Chat receives the content.
- Knowledge and Task actions re-resolve the Browser Share server-side and use the existing Personal Knowledge and Agent Workflow systems.
