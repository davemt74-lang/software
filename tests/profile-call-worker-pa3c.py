"""Deterministic Profile call worker tests using controlled media and HTTP."""

import asyncio
import importlib.util
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

livekit = SimpleNamespace(
    rtc=SimpleNamespace(
        TrackKind=SimpleNamespace(KIND_AUDIO="audio"),
        TrackSource=SimpleNamespace(SOURCE_MICROPHONE="mic"),
    ),
)
agents = SimpleNamespace(AutoSubscribe=SimpleNamespace(SUBSCRIBE_NONE="none"), JobContext=object)
spec = importlib.util.spec_from_file_location(
    "vp3_profile_call_worker", Path(__file__).resolve().parents[1] / "workers/vp3-meeting-agent/profile_call.py",
)
worker = importlib.util.module_from_spec(spec)
with patch.dict(sys.modules, {"livekit": livekit, "livekit.agents": agents, spec.name: worker}):
    spec.loader.exec_module(worker)

CALL_ID = "a" * 32
ROOM_NAME = f"vp3-profile-call-{CALL_ID}"
IDENTITIES = [f"vp3-profile-{side}-{CALL_ID}" for side in ("owner", "visitor")]


class Publication:
    def __init__(self, sid, *, kind="audio", source="mic"):
        self.sid = sid
        self.kind = kind
        self.source = source
        self.subscribed = False
        self.muted = False
        self.track = None
        self.requests = []

    def set_subscribed(self, value):
        self.requests.append(value)


def participant(identity, *publications):
    return SimpleNamespace(identity=identity, track_publications={p.sid: p for p in publications})


class ProfileWorkerTests(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        self.now = 100.0
        clock = patch.object(worker, "time", SimpleNamespace(monotonic=lambda: self.now))
        clock.start()
        self.addCleanup(clock.stop)
        self.requests = []
        self.streams = []
        self.room = SimpleNamespace(name=ROOM_NAME, remote_participants={})
        self.ctx = SimpleNamespace(room=self.room)
        self.call = worker.ProfileCall(
            self.ctx, CALL_ID, ROOM_NAME, "f" * 64, "https://example.com/worker", "secret", self.transcribe,
        )
        self.response = {
            "ok": True, "active": True, "participants": IDENTITIES, "capture": True,
            "capture_revision": 1, "elapsed_ms": 3500, "lease_seconds": 8,
        }

        async def post(action, **fields):
            self.requests.append((action, fields))
            return self.response

        self.call.post = post

    async def asyncTearDown(self):
        self.call.stop()
        if self.call.tasks:
            await asyncio.gather(*self.call.tasks, return_exceptions=True)

    async def transcribe(self, track, identity, call_id, room_name, call_started, send, **gates):
        self.streams.append(SimpleNamespace(
            track=track, identity=identity, call_id=call_id, room_name=room_name,
            call_started=call_started, send=send, **gates,
        ))
        await asyncio.Event().wait()

    def publish(self, p, track_publication):
        track = SimpleNamespace(kind="audio")
        track_publication.track = track
        track_publication.subscribed = True
        self.call.track_subscribed(track, track_publication, p)

    async def test_only_exact_participants_and_one_microphone_each(self):
        owner_mic, duplicate, camera, screen = (
            Publication("owner"), Publication("duplicate"), Publication("camera", kind="video", source="camera"),
            Publication("screen", source="screen_share_audio"),
        )
        visitor_mic, foreign_mic = Publication("visitor"), Publication("other")
        owner = participant(IDENTITIES[0], owner_mic, duplicate, camera, screen)
        visitor = participant(IDENTITIES[1], visitor_mic)
        foreign = participant(f"another-agent-{CALL_ID}", foreign_mic)
        self.room.remote_participants = {p.identity: p for p in (owner, visitor, foreign)}
        self.call.reconnected()
        await self.call.renew()
        self.assertEqual(self.requests[0], ("lease", {"connected": True, "ready": False, "capture_revision": 0}))
        self.assertEqual(owner_mic.requests, [True])
        self.assertEqual(visitor_mic.requests, [True])
        self.assertTrue(all(not pub.requests for pub in (duplicate, camera, screen, foreign_mic)))
        self.publish(owner, owner_mic)
        self.publish(visitor, visitor_mic)
        self.publish(foreign, foreign_mic)
        await asyncio.sleep(0)
        self.assertEqual({stream.identity for stream in self.streams}, set(IDENTITIES))
        self.assertEqual(foreign_mic.requests, [False])
        self.assertEqual(self.streams[0].call_started, 96.5)
        self.assertFalse(self.call.listening())
        self.assertTrue(self.streams[0].accept_frame())
        self.assertTrue(self.call.listening())
        await self.call.renew()
        self.assertEqual(self.requests[-1], ("lease", {"connected": True, "ready": True, "capture_revision": 1}))

    async def test_off_on_discards_old_stream_and_requires_unsubscribe_before_resume(self):
        mic = Publication("owner")
        owner = participant(IDENTITIES[0], mic)
        visitor = participant(IDENTITIES[1])
        self.room.remote_participants = {p.identity: p for p in (owner, visitor)}
        self.call.reconnected()
        await self.call.renew()
        self.publish(owner, mic)
        await asyncio.sleep(0)
        old = self.streams[0]
        self.assertTrue(old.accept_frame())
        await old.send({"text": "from active stream"})
        self.assertEqual(self.requests[-1], ("segment", {"text": "from active stream", "capture_revision": 1}))

        self.response = {**self.response, "capture": False, "capture_revision": 2}
        await self.call.renew()
        self.assertTrue(self.call.connected)
        self.assertFalse(self.call.listening())
        self.assertFalse(old.allow_stream())
        self.assertFalse(old.accept_frame())
        before = len(self.requests)
        await old.send({"text": "stale final"})
        self.assertEqual(len(self.requests), before)
        self.assertEqual(mic.requests, [True, False])

        self.response = {**self.response, "capture": True, "capture_revision": 3}
        await self.call.renew()
        self.assertEqual(mic.requests, [True, False])
        self.assertEqual(len(self.streams), 1)
        mic.subscribed = False
        self.call.track_unsubscribed(old.track, mic, owner)
        self.call.scan_microphones()
        self.assertEqual(mic.requests, [True, False, True])
        self.publish(owner, mic)
        await asyncio.sleep(0)
        current = self.streams[-1]
        self.assertIsNot(current.track, old.track)
        self.assertTrue(current.accept_frame())
        self.assertFalse(old.accept_frame())
        await current.send({"text": "new revision"})
        self.assertEqual(self.requests[-1], ("segment", {"text": "new revision", "capture_revision": 3}))

    async def test_capture_requires_both_connected_humans(self):
        mic = Publication("owner")
        owner = participant(IDENTITIES[0], mic)
        visitor = participant(IDENTITIES[1])
        self.room.remote_participants = {owner.identity: owner}
        self.call.reconnected()
        await self.call.renew()
        self.assertEqual(mic.requests, [])

        self.room.remote_participants[visitor.identity] = visitor
        self.call.participant_joined(visitor)
        self.assertEqual(mic.requests, [True])
        self.publish(owner, mic)
        await asyncio.sleep(0)
        stream = self.streams[0]
        self.assertTrue(stream.accept_frame())

        self.call.participant_left(visitor)
        self.assertEqual(mic.requests, [True, False])
        self.assertFalse(stream.accept_frame())
        self.assertFalse(self.call.listening())
        self.assertTrue(self.call.connected)

    async def test_expired_lease_fences_capture_and_cannot_resurrect(self):
        mic = Publication("owner")
        owner = participant(IDENTITIES[0], mic)
        visitor = participant(IDENTITIES[1])
        self.room.remote_participants = {p.identity: p for p in (owner, visitor)}
        self.call.reconnected()
        await self.call.renew()
        self.publish(owner, mic)
        await asyncio.sleep(0)
        stream = self.streams[0]
        self.assertTrue(stream.accept_frame())
        self.now += 8
        self.assertFalse(stream.accept_frame())
        self.assertFalse(self.call.listening())
        before = len(self.requests)
        await stream.send({"text": "after expiry"})
        self.assertEqual(len(self.requests), before)
        with self.assertRaises(worker.LeaseRejected):
            await self.call.renew()

    async def test_rejects_wrong_participants_and_invalid_dispatch(self):
        self.response = {**self.response, "participants": [IDENTITIES[0], "spoof"]}
        with self.assertRaises(worker.LeaseRejected):
            await self.call.renew()
        self.assertFalse(self.call.capture)
        self.assertEqual(self.call.deadline, 0)

        metadata = {"vp3_profile_call_id": CALL_ID, "room_name": ROOM_NAME, "worker_key": "f" * 64}
        with self.assertRaisesRegex(RuntimeError, "invalid"):
            await worker.run_profile_call(self.ctx, metadata, "http://example.com", "secret", self.transcribe)
        mixed = {**metadata, "vp3_meeting_public_id": "b" * 32}
        with self.assertRaisesRegex(RuntimeError, "invalid"):
            await worker.run_profile_call(self.ctx, mixed, "https://example.com", "secret", self.transcribe)


if __name__ == "__main__":
    unittest.main()
