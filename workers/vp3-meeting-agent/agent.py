from __future__ import annotations

import asyncio
import hashlib
import json
import logging
import math
import os
import time
import urllib.error
import urllib.request
from collections.abc import Awaitable, Callable
from typing import Any

from livekit import rtc
from livekit.agents import AgentServer, AutoSubscribe, JobContext, cli, inference, stt

from profile_call import run_profile_call

logger = logging.getLogger("vp3-meeting-agent")

AGENT_NAME = os.getenv("VP3_LIVEKIT_AGENT_NAME", "vp3-meeting-agent").strip() or "vp3-meeting-agent"
STT_MODEL = os.getenv("VP3_MEETING_STT_MODEL", "deepgram/nova-3").strip() or "deepgram/nova-3"
STT_LANGUAGE = os.getenv("VP3_MEETING_STT_LANGUAGE", "en").strip() or "en"
API_BASE = os.getenv("VP3_MEETING_API_BASE", "").strip().rstrip("/")
WORKER_SECRET = os.getenv("VP3_MEETING_WORKER_SECRET", "").strip()
try:
    FINAL_DRAIN_SECONDS = max(2.0, min(30.0, float(os.getenv("VP3_MEETING_FINAL_DRAIN_SECONDS", "10") or "10")))
except ValueError:
    FINAL_DRAIN_SECONDS = 10.0

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
            "User-Agent": "VP3-LiveKit-Meeting-Agent/18.1",
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


async def _finish_result_task(result_task: asyncio.Task[Any], timeout: float = FINAL_DRAIN_SECONDS) -> None:
    if result_task.done():
        await asyncio.gather(result_task, return_exceptions=True)
        return
    try:
        await asyncio.wait_for(asyncio.shield(result_task), timeout=timeout)
    except asyncio.TimeoutError:
        logger.warning("Timed out draining final STT results after %.1fs", timeout)
        result_task.cancel()
        await asyncio.gather(result_task, return_exceptions=True)


def _seconds(value: float | None) -> float:
    return max(0.0, value) if value is not None and math.isfinite(value) else 0.0


async def _stream_audio_track(
    track: rtc.Track,
    participant_identity: str,
    source_id: str,
    room_name: str,
    job_started: float,
    send: Callable[[dict[str, Any]], Awaitable[None]],
    *,
    allow_stream: Callable[[], bool] | None = None,
    accept_frame: Callable[[], bool] | None = None,
) -> None:
    track_started = time.monotonic()
    engine = inference.STT(model=STT_MODEL, language=STT_LANGUAGE)
    speech_stream = engine.stream()
    audio_stream = rtc.AudioStream(track)
    track_sid = str(getattr(track, "sid", "") or getattr(track, "name", "") or "audio")
    base_ms = max(0, int((track_started - job_started) * 1000))

    async def consume_results() -> None:
        async for event in speech_stream:
            if allow_stream is not None and not allow_stream():
                return
            if event.type != stt.SpeechEventType.FINAL_TRANSCRIPT or not event.alternatives:
                continue
            alternative = event.alternatives[0]
            text = str(alternative.text or "").strip()
            if not text:
                continue
            start_seconds = _seconds(getattr(alternative, "start_time", None))
            end_seconds = max(start_seconds, _seconds(getattr(alternative, "end_time", None)))
            start_ms = base_ms + int(start_seconds * 1000)
            end_ms = base_ms + int(end_seconds * 1000)
            request_id = str(getattr(event, "request_id", "") or "")
            source_key = hashlib.sha256(
                f"{source_id}|{room_name}|{participant_identity}|{track_sid}|{request_id}|{start_ms}|{end_ms}|{text}".encode("utf-8")
            ).hexdigest()
            confidence = getattr(alternative, "confidence", None)
            await send({
                "participant_identity": participant_identity,
                "start_ms": start_ms,
                "end_ms": end_ms,
                "text": text,
                "confidence": confidence if confidence is not None and math.isfinite(confidence) else None,
                "source_key": source_key,
            })

    result_task = asyncio.create_task(consume_results())
    try:
        async for audio_event in audio_stream:
            if allow_stream is not None and not allow_stream():
                return
            if result_task.done():
                await result_task
                return
            if accept_frame is None or accept_frame():
                speech_stream.push_frame(audio_event.frame)
        if allow_stream is None or allow_stream():
            speech_stream.end_input()
            await _finish_result_task(result_task)
    except asyncio.CancelledError:
        if allow_stream is None:
            # Meetings retain their existing bounded final-STT grace period.
            # An authorized Profile stream is cancelled without any drain.
            speech_stream.end_input()
            await _finish_result_task(result_task, min(FINAL_DRAIN_SECONDS, 5.0))
        raise
    except Exception:
        if allow_stream is None:
            logger.exception("Meeting audio transcription failed for participant %s", participant_identity)
            try:
                speech_stream.end_input()
                await _finish_result_task(result_task, min(FINAL_DRAIN_SECONDS, 5.0))
            except Exception:
                pass
        else:
            logger.warning("Profile microphone transcription failed")
    finally:
        if not result_task.done():
            result_task.cancel()
        await asyncio.gather(result_task, return_exceptions=True)
        try:
            await speech_stream.aclose()
        finally:
            await audio_stream.aclose()


async def _transcribe_track(
    track: rtc.Track,
    participant: rtc.RemoteParticipant,
    meeting_public_id: str,
    room_name: str,
    job_started: float,
) -> None:
    async def send(fields: dict[str, Any]) -> None:
        await _send_segment({
            **fields,
            "meeting": meeting_public_id,
            "room_name": room_name,
            "speaker_name": participant.name or participant.identity or "Participant",
            "source": "livekit-agent",
            "is_final": True,
        })

    await _stream_audio_track(track, participant.identity, meeting_public_id, room_name, job_started, send)


@server.rtc_session(agent_name=AGENT_NAME)
async def vp3_meeting_agent(ctx: JobContext) -> None:
    try:
        metadata = json.loads(ctx.job.metadata or "{}")
    except json.JSONDecodeError:
        metadata = {}
    if not isinstance(metadata, dict):
        metadata = {}
    if "vp3_profile_call_id" in metadata:
        await run_profile_call(ctx, metadata, API_BASE, WORKER_SECRET, _stream_audio_track)
        return
    meeting_public_id = str(metadata.get("vp3_meeting_public_id", "")).strip().lower()
    expected_room_name = str(metadata.get("room_name", "")).strip()
    if not meeting_public_id or not expected_room_name:
        raise RuntimeError("Explicit VP3 meeting and room dispatch metadata are required")
    if ctx.room.name != expected_room_name:
        raise RuntimeError("LiveKit room does not match VP3 dispatch metadata")
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
        task = asyncio.create_task(
            _transcribe_track(track, participant, meeting_public_id, expected_room_name, job_started)
        )
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
        # Do not immediately cancel transcription tasks on room disconnect. Give
        # participant audio streams and the STT provider a bounded opportunity
        # to emit their last FINAL_TRANSCRIPT events, then cancel only stragglers.
        _done, pending = await asyncio.wait(list(active_tasks), timeout=FINAL_DRAIN_SECONDS)
        for task in pending:
            task.cancel()
        if pending:
            await asyncio.gather(*pending, return_exceptions=True)


if __name__ == "__main__":
    cli.run_app(server)
