# VP3 Meeting Agent — Phase 18.0

This worker is the realtime media-side companion to VP3 Video Meetings. VP3 remains the system of record for meetings, invitations, calendars, CRM associations, transcript sessions and intelligence. LiveKit provides the realtime room and dispatch lifecycle.

The worker is explicitly dispatched by VP3 when the organizer joins a meeting with Agent mode enabled. It joins as the organizer's existing VP3 Agent display identity, subscribes only to remote audio, runs one streaming STT pipeline per participant, and sends final speaker-separated transcript segments back to VP3. It does not store API secrets in the room metadata and does not create a second analysis store.

## Required environment

- `LIVEKIT_URL`, `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET` — standard LiveKit Agent worker credentials.
- `VP3_LIVEKIT_AGENT_NAME` — must match the dispatch name configured by the VP3 web app. Default: `vp3-meeting-agent`.
- `VP3_MEETING_API_BASE` — public HTTPS origin of the VP3 deployment, for example `https://vp3.example.com`.
- `VP3_MEETING_WORKER_SECRET` — long random shared secret used only for the server-to-server transcript ingest endpoint.
- `VP3_MEETING_STT_MODEL` — optional LiveKit Inference STT model. Default: `deepgram/nova-3`.
- `VP3_MEETING_STT_LANGUAGE` — optional STT language. Default: `en`.

Do not reuse the LiveKit API secret as the VP3 meeting worker secret.

## Run locally

```bash
python -m venv .venv
. .venv/bin/activate
pip install -r requirements.txt
python agent.py dev
```

For production, deploy this directory using the normal LiveKit Agents deployment flow and set the environment values in the deployment secret store. VP3 uses explicit Agent Dispatch, so the worker name must match exactly.

## Current Phase 18.0 behavior

The worker is intentionally notes-first. It transcribes all subscribed human audio tracks and appears in the room as the user's existing VP3 Agent identity. Final transcript segments are mirrored into the canonical `artist_transcript_sessions_v172` / `artist_transcript_segments_v172` pipeline so the existing VP3 transcription intelligence, AI Summary, Knowledge, CRM and Agent Brain workflows can operate on meeting output. Recording remains off unless a later phase adds explicit recording controls and consent.

Generative spoken participation is not enabled by this worker yet; `notes` is the production-safe Phase 18.0 behavior. The server-side meeting model retains additional Agent modes for later policy-controlled voice participation without changing the meeting or transcript architecture.

## Profile call transcription

Run the Profile call Agent as a separate service. Install the supported SDK in
that service's Python environment before starting it:

```bash
python -m pip install --upgrade 'livekit-agents>=1.8.10,<2'
python profile_agent.py start
```

The Profile worker verifies its active grant using the current RTC permission API
and registers its own hidden, subscribe-only participant permissions. The ordinary Profile call notice
appears before a visitor uses the normal call controls. Assistance is available for
audio calls. The worker subscribes to the two authorized microphone tracks and
does not publish media or data. A short renewable VP3 lease governs capture, and
the owner can pause capture from the activity drawer while the call continues.

Configure these environment variables for the dedicated process:

- `LIVEKIT_URL`, `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET` for the same LiveKit Cloud project used by VP3 Profile calls.
- `VP3_PROFILE_CALL_AGENT_ENABLED=1` to enable the worker. Keep the same opt-in on the VP3 web server.
- `VP3_PROFILE_CALL_AGENT_URL` with the same `wss://<project>.livekit.cloud` origin, configured on both the worker and the VP3 web server.
- `VP3_PROFILE_CALL_AGENT_NAME` with a dedicated dispatch name, such as `vp3-profile-call-agent`. Use the same name on both sides and a different name for meeting dispatches.
- `VP3_PROFILE_CALL_WORKER_API_BASE` with the HTTPS base URL of the VP3 web app, including any subdirectory.
- `VP3_PROFILE_CALL_WORKER_SECRET` with the same separate random secret on both sides, at least 32 printable non-space ASCII characters.
- Optional `VP3_PROFILE_CALL_STT_MODEL` and `VP3_PROFILE_CALL_STT_LANGUAGE`, defaulting to `deepgram/nova-3` and `en`.

Run the isolated worker tests with `python -B tests/profile-call-worker-pa3c.py` from the repository root. A live staging call also needs two Profile participants, a verified Profile calling project, and transcription enabled for the dedicated worker.
