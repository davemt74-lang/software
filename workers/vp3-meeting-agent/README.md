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
