"""Lease-gated streaming transcription for a two-party VP3 Profile call."""

from __future__ import annotations

import asyncio
import json
import logging
import re
import secrets
import time
import urllib.error
import urllib.parse
import urllib.request
from collections.abc import Awaitable, Callable
from dataclasses import dataclass
from typing import Any

from livekit import rtc
from livekit.agents import AutoSubscribe, JobContext

logger = logging.getLogger("vp3-meeting-agent")
HEARTBEAT_SECONDS = 2.0
HTTP_TIMEOUT_SECONDS = 3.0
MAX_LEASE_SECONDS = 8
LISTENING_WINDOW_SECONDS = 3.0


class LeaseRejected(Exception):
    """The authoritative call endpoint rejected or revoked this worker."""


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args: Any, **kwargs: Any) -> None:
        return None


@dataclass
class Microphone:
    publication: rtc.RemoteTrackPublication
    subscribed_requested: bool = False
    track: rtc.Track | None = None
    task: asyncio.Task[None] | None = None
    last_frame: float = 0.0


def _post(endpoint: str, secret: str, payload: dict[str, Any]) -> dict[str, Any]:
    request = urllib.request.Request(
        endpoint,
        data=json.dumps(payload, separators=(",", ":"), ensure_ascii=False, allow_nan=False).encode("utf-8"),
        method="POST",
        headers={
            "Authorization": f"Bearer {secret}",
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": "VP3-LiveKit-Profile-Call/1",
        },
    )
    try:
        with urllib.request.build_opener(NoRedirect()).open(request, timeout=HTTP_TIMEOUT_SECONDS) as response:
            result = json.loads(response.read(32769))
    except urllib.error.HTTPError as exc:
        if exc.code in {401, 403, 404, 409, 410}:
            raise LeaseRejected from None
        raise
    if not isinstance(result, dict) or result.get("ok") is not True:
        raise LeaseRejected
    return result


class ProfileCall:
    def __init__(
        self,
        ctx: JobContext,
        call_id: str,
        room_name: str,
        worker_key: str,
        endpoint: str,
        secret: str,
        transcribe: Callable[..., Awaitable[None]],
    ) -> None:
        self.ctx = ctx
        self.room = ctx.room
        self.endpoint = endpoint
        self.secret = secret
        self.call_id = call_id
        self.room_name = room_name
        self.transcribe = transcribe
        self.credentials = {
            "call_id": call_id,
            "room_name": room_name,
            "worker_key": worker_key,
            "worker_id": secrets.token_hex(16),
        }
        self.identities = {f"vp3-profile-{side}-{call_id}" for side in ("owner", "visitor")}
        self.present: set[str] = set()
        self.mics: dict[str, Microphone] = {}
        self.unsubscribing: set[str] = set()
        self.tasks: set[asyncio.Task[None]] = set()
        self.stopped = asyncio.Event()
        self.connected = False
        self.capture = False
        self.capture_revision = 0
        self.deadline = 0.0
        self.call_started = 0.0

    def capturing(self) -> bool:
        return (
            self.connected and self.capture and self.present == self.identities
            and not self.stopped.is_set() and time.monotonic() < self.deadline
        )

    def listening(self) -> bool:
        if not self.capturing():
            return False
        now = time.monotonic()
        return any(
            mic.task is not None and not mic.task.done() and not mic.publication.muted
            and 0 < mic.last_frame and now - mic.last_frame < LISTENING_WINDOW_SECONDS
            for mic in self.mics.values()
        )

    async def post(self, action: str, **fields: Any) -> dict[str, Any]:
        payload = {**fields, **self.credentials, "action": action}
        timeout = HTTP_TIMEOUT_SECONDS
        if self.deadline:
            timeout = min(timeout, self.deadline - time.monotonic())
        if timeout <= 0:
            raise LeaseRejected
        return await asyncio.wait_for(asyncio.to_thread(_post, self.endpoint, self.secret, payload), timeout=timeout)

    async def renew(self) -> None:
        requested_at = time.monotonic()
        result = await self.post(
            "lease", connected=self.connected, ready=self.listening(), capture_revision=self.capture_revision,
        )
        participants = result.get("participants")
        revision = result.get("capture_revision")
        capture = result.get("capture")
        elapsed = result.get("elapsed_ms")
        seconds = result.get("lease_seconds")
        if (
            self.stopped.is_set() or result.get("active") is not True
            or not isinstance(participants, list)
            or not all(isinstance(identity, str) for identity in participants)
            or len(participants) != 2
            or set(participants) != self.identities
            or type(revision) is not int or revision < 1 or revision < self.capture_revision
            or type(capture) is not bool
            or type(elapsed) is not int or elapsed < 0
            or type(seconds) is not int or not 1 <= seconds <= MAX_LEASE_SECONDS
        ):
            raise LeaseRejected
        now = time.monotonic()
        if requested_at + seconds <= now or (self.deadline and now >= self.deadline):
            raise LeaseRejected
        if not self.deadline:
            self.call_started = (requested_at + now) / 2 - elapsed / 1000
        if self.capture != capture or self.capture_revision != revision:
            self.capture = False
            self.clear_microphones()
        self.capture_revision = revision
        self.deadline = requested_at + seconds
        self.capture = capture
        self.scan_microphones()

    def clear_microphones(self) -> None:
        for identity in list(self.mics):
            self.remove_microphone(identity)

    def remove_microphone(self, identity: str) -> None:
        mic = self.mics.pop(identity, None)
        if mic is None:
            return
        if mic.task is not None:
            mic.task.cancel()
        try:
            if mic.publication.subscribed:
                self.unsubscribing.add(mic.publication.sid)
            mic.publication.set_subscribed(False)
        except Exception:
            logger.debug("Profile microphone subscription could not be cleared")

    def eligible(self, publication: rtc.RemoteTrackPublication, participant: rtc.RemoteParticipant) -> bool:
        return (
            participant.identity in self.identities
            and publication.kind == rtc.TrackKind.KIND_AUDIO
            and publication.source == rtc.TrackSource.SOURCE_MICROPHONE
        )

    def consider(self, publication: rtc.RemoteTrackPublication, participant: rtc.RemoteParticipant) -> None:
        if not self.capturing() or not self.eligible(publication, participant):
            return
        if publication.sid in self.unsubscribing:
            if publication.subscribed:
                return
            self.unsubscribing.discard(publication.sid)
        mic = self.mics.setdefault(participant.identity, Microphone(publication))
        if mic.publication.sid != publication.sid:
            return
        if publication.subscribed and publication.track is not None:
            self.track_subscribed(publication.track, publication, participant)
        elif not mic.subscribed_requested:
            publication.set_subscribed(True)
            mic.subscribed_requested = True

    def scan_microphones(self) -> None:
        if not self.capturing():
            return
        for participant in self.room.remote_participants.values():
            if participant.identity not in self.identities:
                continue
            for publication in participant.track_publications.values():
                self.consider(publication, participant)

    def track_subscribed(
        self, track: rtc.Track, publication: rtc.RemoteTrackPublication, participant: rtc.RemoteParticipant,
    ) -> None:
        identity = participant.identity
        mic = self.mics.get(identity)
        if (
            not self.capturing() or not self.eligible(publication, participant)
            or mic is None or mic.publication.sid != publication.sid
            or publication.sid in self.unsubscribing or track.kind != rtc.TrackKind.KIND_AUDIO
        ):
            publication.set_subscribed(False)
            return
        if mic.task is not None and not mic.task.done():
            return

        revision = self.capture_revision
        mic.track = track
        mic.last_frame = 0.0

        def allowed() -> bool:
            return (
                self.capturing() and self.capture_revision == revision
                and self.mics.get(identity) is mic and mic.track is track
            )

        def accept_frame() -> bool:
            if not allowed() or publication.muted:
                return False
            mic.last_frame = time.monotonic()
            return True

        async def send(segment: dict[str, Any]) -> None:
            for attempt in range(2):
                if not allowed():
                    return
                try:
                    await self.post("segment", **segment, capture_revision=revision)
                    return
                except LeaseRejected:
                    # The heartbeat re-reads capture state. A revoked segment
                    # can never be replayed under a newer capture revision.
                    return
                except (OSError, ValueError, asyncio.TimeoutError):
                    if attempt == 1:
                        logger.warning("Profile call transcript segment could not be delivered")

        mic.task = asyncio.create_task(
            self.transcribe(
                track, identity, self.call_id, self.room_name, self.call_started, send,
                allow_stream=allowed, accept_frame=accept_frame,
            )
        )
        self.tasks.add(mic.task)
        mic.task.add_done_callback(self.track_finished)

    def track_finished(self, task: asyncio.Task[None]) -> None:
        self.tasks.discard(task)
        if not task.cancelled() and task.exception() is not None:
            logger.warning("Profile microphone transcription stream ended unexpectedly")

    def track_unsubscribed(
        self, track: rtc.Track, publication: rtc.RemoteTrackPublication, participant: rtc.RemoteParticipant,
    ) -> None:
        self.unsubscribing.discard(publication.sid)
        mic = self.mics.get(participant.identity)
        if mic is None or mic.publication.sid != publication.sid or mic.track is not track:
            return
        mic.track = None
        mic.last_frame = 0.0
        mic.subscribed_requested = False
        if mic.task is not None:
            mic.task.cancel()

    def track_unpublished(self, publication: rtc.RemoteTrackPublication, participant: rtc.RemoteParticipant) -> None:
        mic = self.mics.get(participant.identity)
        if mic is not None and mic.publication.sid == publication.sid:
            self.remove_microphone(participant.identity)
        self.unsubscribing.discard(publication.sid)

    def subscription_failed(self, participant: rtc.RemoteParticipant, track_sid: str, _error: str) -> None:
        mic = self.mics.get(participant.identity)
        if mic is not None and mic.publication.sid == track_sid:
            mic.subscribed_requested = False

    def participant_joined(self, participant: rtc.RemoteParticipant) -> None:
        if participant.identity in self.identities:
            self.present.add(participant.identity)
            self.scan_microphones()

    def participant_left(self, participant: rtc.RemoteParticipant) -> None:
        if participant.identity in self.present:
            self.present.discard(participant.identity)
            self.clear_microphones()

    def reconnecting(self) -> None:
        self.connected = False
        self.present.clear()
        self.clear_microphones()

    def reconnected(self) -> None:
        self.connected = not self.stopped.is_set()
        self.present = self.identities.intersection(p.identity for p in self.room.remote_participants.values())
        self.scan_microphones()

    def stop(self, *_: Any) -> None:
        self.connected = False
        self.capture = False
        self.present.clear()
        self.stopped.set()
        self.clear_microphones()

    async def run(self, agent_name: str) -> None:
        self.room.on("track_published", self.consider)
        self.room.on("track_subscribed", self.track_subscribed)
        self.room.on("track_unsubscribed", self.track_unsubscribed)
        self.room.on("track_unpublished", self.track_unpublished)
        self.room.on("track_subscription_failed", self.subscription_failed)
        self.room.on("participant_connected", self.participant_joined)
        self.room.on("participant_disconnected", self.participant_left)
        self.room.on("reconnecting", self.reconnecting)
        self.room.on("reconnected", self.reconnected)
        self.room.on("disconnected", self.stop)

        try:
            await self.renew()
            await asyncio.wait_for(
                self.ctx.connect(auto_subscribe=AutoSubscribe.SUBSCRIBE_NONE),
                timeout=max(0.0, self.deadline - time.monotonic()),
            )
            self.reconnected()
            if agent_name and self.connected:
                try:
                    await asyncio.wait_for(self.room.local_participant.set_name(agent_name), timeout=1.0)
                except Exception:
                    logger.debug("Profile Agent display name could not be updated")
            while not self.stopped.is_set() and time.monotonic() < self.deadline:
                try:
                    await self.renew()
                except (OSError, ValueError, asyncio.TimeoutError):
                    # Keep at most the last verified lease through a transient
                    # transport error. Each frame is also checked against it.
                    pass
                delay = min(HEARTBEAT_SECONDS, self.deadline - time.monotonic())
                if delay <= 0:
                    break
                try:
                    await asyncio.wait_for(self.stopped.wait(), timeout=delay)
                except asyncio.TimeoutError:
                    pass
        except (LeaseRejected, OSError, ValueError, asyncio.TimeoutError):
            logger.info("Profile call worker ended without a current capture lease")
        finally:
            self.stop()
            if self.tasks:
                await asyncio.wait(self.tasks, timeout=1.0)
            try:
                await asyncio.wait_for(self.room.disconnect(), timeout=HTTP_TIMEOUT_SECONDS)
            except Exception:
                logger.debug("Profile Agent room disconnect could not be confirmed")


async def run_profile_call(
    ctx: JobContext,
    metadata: dict[str, Any],
    base_url: str,
    secret: str,
    transcribe: Callable[..., Awaitable[None]],
) -> None:
    call_id = metadata.get("vp3_profile_call_id")
    room_name = metadata.get("room_name")
    worker_key = metadata.get("worker_key")
    parsed_url = urllib.parse.urlsplit(base_url)
    if (
        "vp3_meeting_public_id" in metadata
        or not isinstance(call_id, str) or not re.fullmatch(r"[0-9a-f]{32}", call_id)
        or room_name != f"vp3-profile-call-{call_id}" or ctx.room.name != room_name
        or not isinstance(worker_key, str) or not re.fullmatch(r"[0-9a-f]{32,128}", worker_key)
        or parsed_url.scheme != "https" or not parsed_url.hostname or parsed_url.username is not None
        or parsed_url.query or parsed_url.fragment or not secret
    ):
        raise RuntimeError("Profile call worker dispatch configuration is invalid")
    ctx.log_context_fields = {"room": room_name, "vp3_profile_call": call_id, "agent_mode": "notes"}
    worker = ProfileCall(ctx, call_id, room_name, worker_key, f"{base_url}/api/profile-call-worker-pa3c.php", secret, transcribe)
    agent_name = metadata.get("agent_display_name")
    await worker.run(agent_name.strip()[:100] if isinstance(agent_name, str) else "")
