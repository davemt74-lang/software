# Canonical Video Editor Runtime

`video-editor.js` is the single active Video Editor core runtime.

## Ownership

- Browser bridge: `window.StonefellowVideoEditor`
- Active page: `video-editor.php`
- Active consumers: `video-header-v92.js` and the current Editor Agent runtime
- Historical source: `video-editor-v90.js` is not loaded by the active Video Editor page

## Preserved core contract

The canonical bridge exposes editor state, selected clip identity, command execution, save, preview, project/playhead access, edit-ledger recording, and before/after state diffs.

Voice, shared conversation continuity, Agent Context, and ElevenLabs are outside this core ownership move and are intentionally unchanged.

The next Editor Agent phase will consume this canonical bridge through a universal surface-adapter/capability registry rather than making the Agent Editor Video-specific.
