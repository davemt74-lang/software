# Stonefellow v86 — Media Studio

- Agent Chat can open browser-visible cameras and microphones, take photos, record video, and make standalone voice recordings. Voice recordings are media files; they are separate from conversational transcription.
- Multiple `videoinput` devices are opened as independent camera feeds. HDMI works when an HDMI capture device is exposed by the OS/browser as a camera input (UVC or equivalent); browsers cannot read a raw HDMI port directly.
- Captures are stored in a private per-user media library and are streamed through an authenticated range-capable endpoint.
- Captures can be pushed directly into the Video Editor with `?asset=<id>`.
- Video Editor supports user photos/videos/audio, direct audio from the Stonefellow song library, visual/audio timeline lanes, clip timing/trim/volume controls, preview playback and persistent projects.
- Agent Brain registers camera, photo/video capture, voice recorder, media library and Video Editor tools. Media capture and project saves are auditable through `agent_tool_history`.
- v86 requires `upgrade-stonefellow-v86.sql`.
