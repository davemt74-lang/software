from __future__ import annotations

import asyncio
import hashlib
import json
import logging
import os
import time
import urllib.error
import urllib.request
from typing import Any

from livekit import agents, rtc
from livekit.agents import AgentServer, AutoSubscribe, JobContext, cli, inference, stt

logger = logging.getLogger("vp3-meeting-agent")

AGENT_NAME = os.getenv("VP3_LIVEKIT_AGENT_NAME", "vp3-meeting-agent").strip() or "vp3-meeting-agent"
STT_MODEL = os.getenv("VP3_MEETING_STT_MODEL", "deepgram/nova-3").strip() or "deepgram/nova-3"
STT_LANGUAGE = os.getenv("VP3_MEETING_STT_LANGUAGE", "en").strip() or "en"
API_BASE = os.getenv("VP3_MEETING_API_BASE", "").strip().rstrip("/")
WORKER_SECRET = os.getenv("VP3_MEETING_WORKER_SECRET", "").strip()

server = AgentServer()


def _post_segment(payload: dict[str, Any]) -> dict[str, Any]:
    if not API_BASE or not WORKER_SECRET:
        raise RuntimeError("VP3_MEETING_API_BASE and VP3_MEETING_WORKER_SECRET are required")
    body = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    request = urllib.request.Request(
        f"{API_BASE}/api/video-meeting-worker.php",
        data=body,
        method="POST",
        headers={
            "Authorization": f"Bearer {WORKER_SECRET}",
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": "VP3-LiveKit-Meeting-Agent/18.0",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=12) as response:
            raw = response.read().decode("utf-8", errors="replace")
            data = json.loads(raw) if raw else {}
            if not isinstance(data, dict) or not data.get("ok"):
                raise RuntimeError(str(data.get("error", "VP3 transcript ingest was rejected")))
            return data
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", errors="replace")
        try:
            data = json.loads(raw)
            message = data.get("error") if isinstance(data, dict) else raw
        except json.JSONDecodeError:
            message = raw
        raise RuntimeError(f"VP3 transcript ingest HTTP {exc.code}: {message or exc.reason}") from exc


async def _send_segment(payload: dict[str, Any]) -> None:
    try:
        await asyncio.to_thread(_post_segment, payload)
    except Exception:
        logger.exception("Could not persist final meeting transcript segment")


async def _transcribe_track(
    track: rtc.Track,
    participant: rtc.RemoteParticipant,
    meeting_public_id: str,
    job_started: float,
) -> None:
    track_started = time.monotonic()
    engine = inference.STT(model=STT_MODEL, language=STT_LANGUAGE)
    speech_stream = engine.stream()
    audio_stream = rtc.AudioStream(track)

    async def consume_results() -> None:
        try:
            async for event in speech_stream:
                if event.type != stt.SpeechEventType.FINAL_TRANSCRIPT or not event.alternatives:
                    continue
                alternative = event.alternatives[0]
                text = str(alternative.text or "").strip()
                if not text:
                    continue
                base_ms = max(0, int((track_started - job_started) * 1000))
                start_seconds = max(0.0, float(getattr(alternative, "start_time", 0.0) or 0.0))
                end_seconds = max(start_seconds, float(getattr(alternative, "end_time", start_seconds) or start_seconds))
                start_ms = base_ms + int(start_seconds * 1000)
                end_ms = base_ms + int(end_seconds * 1000)
                request_id = str(getattr(event, "request_id", "") or "")
                track_sid = str(getattr(track, "sid", "") or getattr(track, "name", "") or "audio")
                source_key = hashlib.sha256(
                    f"{meeting_public_id}|{participant.identity}|{track_sid}|{request_id}|{start_ms}|{end_ms}|{text}".encode("utf-8")
                ).hexdigest()
                confidence_raw = getattr(alternative, "confidence", None)
                confidence = float(confidence_raw) if confidence_raw is not None else None
                await _send_segment(
                    {
                        "meeting": meeting_public_id,
                        "participant_identity": participant.identity,
                        "speaker_name": participant.name or participant.identity or "Participant",
                        "start_ms": start_ms,
                        "end_ms": end_ms,
                        "text": text,
                        "confidence": confidence,
                        "source": "livekit-agent",
                        "source_key": source_key,
                        "is_final": True,
                    }
                )
        finally:
            await speech_stream.aclose()

    try:
        async with asyncio.TaskGroup() as group:
            result_task = group.create_task(consume_results())
            async for audio_event in audio_stream:
                speech_stream.push_frame(audio_event.frame)
            speech_stream.end_input()
            await result_task
    except asyncio.CancelledError:
        speech_stream.end_input()
        raise
    except Exception:
        logger.exception("Meeting audio transcription failed for participant %s", participant.identity)
    finally:
        await audio_stream.aclose()


@server.rtc_session(agent_name=AGENT_NAME)
async def vp3_meeting_agent(ctx: JobContext) -> None:
    try:
        metadata = json.loads(ctx.job.metadata or "{}")
    except json.JSONDecodeError:
        metadata = {}
    if not isinstance(metadata, dict):
        metadata = {}
    meeting_public_id = str(metadata.get("vp3_meeting_public_id", "")).strip().lower()
    if not meeting_public_id:
        raise RuntimeError("Explicit VP3 meeting dispatch metadata is required")
    if not API_BASE or not WORKER_SECRET:
        raise RuntimeError("VP3 meeting worker callback configuration is incomplete")

    ctx.log_context_fields = {
        "room": ctx.room.name,
        "vp3_meeting": meeting_public_id,
        "agent_mode": str(metadata.get("mode", "notes")),
    }
    job_started = time.monotonic()
    active_tasks: set[asyncio.Task[Any]] = set()
    disconnected = asyncio.Event()

    @ctx.room.on("track_subscribed")
    def on_track_subscribed(track: rtc.Track, _publication: Any, participant: rtc.RemoteParticipant) -> None:
        if track.kind != rtc.TrackKind.KIND_AUDIO:
            return
        task = asyncio.create_task(_transcribe_track(track, participant, meeting_public_id, job_started))
        active_tasks.add(task)
        task.add_done_callback(active_tasks.discard)

    @ctx.room.on("disconnected")
    def on_disconnected(*_: Any) -> None:
        disconnected.set()

    await ctx.connect(auto_subscribe=AutoSubscribe.AUDIO_ONLY)
    display_name = str(metadata.get("agent_display_name", "")).strip()
    if display_name:
        try:
            await ctx.room.local_participant.set_name(display_name)
        except Exception:
            logger.debug("Agent display name could not be updated", exc_info=True)

    logger.info("VP3 meeting transcription worker connected")
    await disconnected.wait()
    if active_tasks:
        for task in list(active_tasks):
            task.cancel()
        await asyncio.gather(*active_tasks, return_exceptions=True)


if __name__ == "__main__":
    cli.run_app(server)
