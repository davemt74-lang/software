# Stonefellow v89 — Video Studio shell and shared voice interruption

- Removes the legacy workspace sidebar from Video Editor and gives the editor the full application canvas.
- Adds a Stem Studio-style compact header with Menu, project title, Save, Save As, Inspector, smart Exit Studio, Media Library, and AI.
- Adds safe same-origin smart-return handling so Exit Studio goes back to the page that opened Video Editor.
- Makes the source library and Inspector responsive drawers on tablets/phones; both remain accessible from Menu on mobile.
- Keeps the preview/timeline canvas full-width when drawers are closed.
- Forces the embedded Agent Chat composer to a single non-wrapping row with flexible textarea, microphone, and send controls.
- Adds responsive mobile chat sizing.
- Adds shared echo-cancelled voice barge-in monitoring to both Stem Studio and Video Editor embedded Agent Chat, matching Agent Chat interruption thresholds.

QA: 120 PHP syntax checks, 92 JavaScript syntax checks, 19/19 primary v89 checks, 6/6 mobile/voice hardening checks. No SQL migration required.
