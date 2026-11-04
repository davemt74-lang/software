"""Dedicated subscribe-only Agent server for VP3 Profile audio calls."""

from __future__ import annotations

import asyncio
import hashlib
import logging
import math
import os
import re
import time
from collections.abc import Awaitable, Callable
from dataclasses import dataclass
from typing import Any
from urllib.parse import urlsplit

from livekit import rtc
from livekit.agents import AgentServer, JobContext, JobRequest, WorkerPermissions, cli, inference, stt

from profile_call import parse_dispatch, run_profile_call

logger = logging.getLogger("vp3-profile-call-agent")


@dataclass(frozen=True)
class WorkerConfig:
    agent_name: str
    livekit_url: str
    api_base: str
    callback_secret: str


def cloud_origin(value: str) -> str:
    url = urlsplit(value.strip())
    if (
        url.scheme != "wss" or url.username is not None or url.password is not None
        or url.query or url.fragment or url.path not in {"", "/"} or url.port not in {None, 443}
        or not re.fullmatch(r"[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.livekit\.cloud", url.hostname or "")
    ):
        raise ValueError("Expected a LiveKit Cloud WSS origin")
    return f"wss://{url.hostname}"


def worker_config() -> WorkerConfig:
    if os.getenv("VP3_PROFILE_CALL_AGENT_ENABLED") != "1":
        raise ValueError("Profile call Agent is disabled")
    agent_name = os.getenv("VP3_PROFILE_CALL_AGENT_NAME", "").strip()
    if not re.fullmatch(r"[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}", agent_name):
        raise ValueError("Set the dedicated Profile call Agent dispatch name")
    if agent_name == (os.getenv("VP3_LIVEKIT_AGENT_NAME", "").strip() or "vp3-meeting-agent"):
        raise ValueError("Profile calls require their own Agent dispatch name")
    livekit_url = cloud_origin(os.getenv("LIVEKIT_URL", ""))
    if livekit_url != cloud_origin(os.getenv("VP3_PROFILE_CALL_AGENT_URL", "")):
        raise ValueError("Profile call Agent must use the configured LiveKit Cloud project")
    api_base = os.getenv("VP3_PROFILE_CALL_WORKER_API_BASE", "").strip().rstrip("/")
    api_url = urlsplit(api_base)
    if (
        api_url.scheme != "https" or not api_url.hostname or api_url.username is not None
        or api_url.password is not None or api_url.query or api_url.fragment
    ):
        raise ValueError("Set the HTTPS VP3 Profile call worker API base")
    callback_secret = os.getenv("VP3_PROFILE_CALL_WORKER_SECRET", "").strip()
    if not re.fullmatch(r"[!-~]{32,512}", callback_secret):
        raise ValueError("Set a dedicated Profile call worker callback secret")
    if callback_secret in {os.getenv("LIVEKIT_API_SECRET", ""), os.getenv("VP3_MEETING_WORKER_SECRET", "")}:
        raise ValueError("Profile call worker must use a separate callback secret")
    return WorkerConfig(agent_name, livekit_url, api_base, callback_secret)


async def request_profile_call(req: JobRequest) -> None:
    try:
        config = worker_config()
        if req.agent_name != config.agent_name:
            raise ValueError("Wrong Profile call Agent registration")
        dispatch = parse_dispatch(req.job.metadata, req.room.name)
    except ValueError:
        logger.warning("Rejected invalid Profile call dispatch")
        await req.reject()
        return
    await req.accept(name=dispatch.display_name, identity=dispatch.identity)


def seconds(value: float | None) -> float:
    return max(0.0, value) if value is not None and math.isfinite(value) else 0.0


async def transcribe_microphone(
    track: rtc.Track,
    identity: str,
    call_id: str,
    room_name: str,
    call_started: float,
    send: Callable[[dict[str, Any]], Awaitable[None]],
    *,
    allow_stream: Callable[[], bool],
    accept_frame: Callable[[], bool],
) -> None:
    if not allow_stream():
        return
    started_ms = max(0, int((time.monotonic() - call_started) * 1000))
    track_sid = track.sid
    model = os.getenv("VP3_PROFILE_CALL_STT_MODEL", "").strip() or "deepgram/nova-3"
    language = os.getenv("VP3_PROFILE_CALL_STT_LANGUAGE", "").strip() or "en"
    audio_stream = rtc.AudioStream(track, capacity=32)
    try:
        speech_stream = inference.STT(model=model, language=language).stream()

        async def consume_results() -> None:
            async for event in speech_stream:
                if not allow_stream():
                    return
                if event.type != stt.SpeechEventType.FINAL_TRANSCRIPT or not event.alternatives:
                    continue
                result = event.alternatives[0]
                text = result.text.strip()
                if not text:
                    continue
                start_ms = started_ms + int(seconds(result.start_time) * 1000)
                end_ms = max(start_ms, started_ms + int(seconds(result.end_time) * 1000))
                source = f"{call_id}|{room_name}|{identity}|{track_sid}|{event.request_id}|{start_ms}|{end_ms}|{text}"
                confidence = result.confidence
                await send({
                    "participant_identity": identity,
                    "start_ms": start_ms,
                    "end_ms": end_ms,
                    "text": text,
                    "confidence": confidence if confidence is not None and math.isfinite(confidence) else None,
                    "source_key": hashlib.sha256(source.encode()).hexdigest(),
                })

        results = asyncio.create_task(consume_results())
        try:
            async for event in audio_stream:
                if not allow_stream() or results.done():
                    break
                if accept_frame():
                    speech_stream.push_frame(event.frame)
        finally:
            # A Profile capture revocation never drains buffered speech.
            results.cancel()
            await asyncio.gather(results, return_exceptions=True)
            await speech_stream.aclose()
    finally:
        await audio_stream.aclose()


async def profile_call_agent(ctx: JobContext) -> None:
    try:
        config = worker_config()
        if ctx.job.agent_name != config.agent_name:
            raise ValueError("Wrong Profile call Agent registration")
        dispatch = parse_dispatch(ctx.job.metadata, ctx.job.room.name)
    except ValueError:
        logger.warning("Invalid Profile call job")
        return
    await run_profile_call(ctx, dispatch, config.api_base, config.callback_secret, transcribe_microphone)


def build_server() -> AgentServer:
    config = worker_config()
    if not hasattr(rtc.LocalParticipant, "permissions"):
        raise RuntimeError("Profile call worker requires livekit-agents>=1.8.10,<2 for live permission checks")
    server = AgentServer(
        ws_url=config.livekit_url,
        permissions=WorkerPermissions(
            can_subscribe=True,
            can_publish=False,
            can_publish_data=False,
            can_update_metadata=False,
            hidden=True,
        ),
    )
    server.rtc_session(agent_name=config.agent_name, on_request=request_profile_call)(profile_call_agent)
    return server


if __name__ == "__main__":
    cli.run_app(build_server())
