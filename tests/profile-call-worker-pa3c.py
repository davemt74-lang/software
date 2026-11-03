"""Deterministic Profile call worker tests using controlled media and HTTP."""

import asyncio
import importlib.util
import json
import os
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import AsyncMock, patch

livekit = SimpleNamespace(
    rtc=SimpleNamespace(
        TrackKind=SimpleNamespace(KIND_AUDIO="audio"),
        TrackSource=SimpleNamespace(SOURCE_MICROPHONE="mic"),
        ParticipantKind=SimpleNamespace(PARTICIPANT_KIND_STANDARD="human", PARTICIPANT_KIND_AGENT="agent"),
        LocalParticipant=SimpleNamespace(permissions=None),
    ),
)
agents = SimpleNamespace(
    AutoSubscribe=SimpleNamespace(SUBSCRIBE_NONE="none"), JobContext=object, JobRequest=object,
    WorkerPermissions=SimpleNamespace, AgentServer=SimpleNamespace, cli=None, inference=None, stt=None,
)
WORKERS = Path(__file__).resolve().parents[1] / "workers/vp3-meeting-agent"


def load_worker(name):
    spec = importlib.util.spec_from_file_location(name, WORKERS / f"{name}.py")
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


with patch.dict(sys.modules, {"livekit": livekit, "livekit.agents": agents}):
    worker = load_worker("profile_call")
    profile_agent = load_worker("profile_agent")

CALL_ID = "a" * 32
ROOM_NAME = f"vp3-profile-{CALL_ID}"
AGENT_ID = f"agent-{CALL_ID}"
IDENTITIES = [f"{side}-{CALL_ID}" for side in ("owner", "visitor")]
METADATA = {"vp3_profile_call_id": CALL_ID, "room_name": ROOM_NAME, "worker_key": "f" * 64,
            "agent_display_name": "Profile Agent"}
SETTINGS = {
    "VP3_PROFILE_CALL_AGENT_ENABLED": "1", "VP3_PROFILE_CALL_AGENT_NAME": "vp3-profile-test",
    "VP3_PROFILE_CALL_AGENT_URL": "wss://project.livekit.cloud", "LIVEKIT_URL": "wss://PROJECT.livekit.cloud:443/",
    "VP3_PROFILE_CALL_WORKER_API_BASE": "https://example.com/vp3/", "VP3_PROFILE_CALL_WORKER_SECRET": "s" * 32,
}


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
    return SimpleNamespace(identity=identity, kind="human", track_publications={p.sid: p for p in publications})


def local_agent():
    return SimpleNamespace(identity=AGENT_ID, kind="agent", permissions=SimpleNamespace(
        hidden=True, can_subscribe=True, can_publish=False, can_publish_data=False,
        can_update_metadata=False, can_publish_sources=[],
    ))


class ProfileWorkerTests(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        self.now = 100.0
        clock = patch.object(worker, "time", SimpleNamespace(monotonic=lambda: self.now))
        clock.start()
        self.addCleanup(clock.stop)
        self.requests = []
        self.streams = []
        self.room = SimpleNamespace(name=ROOM_NAME, local_participant=local_agent(), remote_participants={})
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
        self.room.remote_participants = {p.identity: p for p in (owner, visitor)}
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

    async def test_rejects_wrong_participants(self):
        self.response = {**self.response, "participants": [IDENTITIES[0], "spoof"]}
        with self.assertRaises(worker.LeaseRejected):
            await self.call.renew()
        self.assertFalse(self.call.capture)
        self.assertEqual(self.call.deadline, 0)

    async def test_wrong_room_grant_stops_capture(self):
        self.room.local_participant.permissions.can_publish_data = True
        self.call.reconnected()
        self.assertTrue(self.call.stopped.is_set())
        self.assertFalse(self.call.connected)

    async def test_permission_change_cancels_existing_capture(self):
        owner = participant(IDENTITIES[0], Publication("owner"))
        visitor = participant(IDENTITIES[1])
        self.room.remote_participants = {p.identity: p for p in (owner, visitor)}
        self.call.reconnected()
        await self.call.renew()
        self.assertTrue(self.call.capturing())
        self.room.local_participant.permissions.hidden = False
        self.call.permissions_changed(self.room.local_participant, self.room.local_participant.permissions)
        self.assertFalse(self.call.capturing())
        self.assertTrue(self.call.stopped.is_set())

    async def test_visible_intruder_stops_capture(self):
        owner = participant(IDENTITIES[0], Publication("owner"))
        visitor = participant(IDENTITIES[1])
        self.room.remote_participants = {p.identity: p for p in (owner, visitor)}
        self.call.reconnected()
        await self.call.renew()
        self.assertTrue(self.call.capturing())
        self.call.participant_joined(participant(f"outsider-{CALL_ID}"))
        self.assertTrue(self.call.stopped.is_set())
        self.assertFalse(self.call.capturing())

    async def test_connect_does_not_subscribe_under_the_prejoin_lease(self):
        mic = Publication("owner")
        owner = participant(IDENTITIES[0], mic)
        visitor = participant(IDENTITIES[1])
        self.room.remote_participants = {p.identity: p for p in (owner, visitor)}
        self.room.on = lambda *_: None
        self.room.disconnect = AsyncMock()

        async def connect(*, auto_subscribe):
            self.assertEqual(auto_subscribe, "none")
            self.assertEqual(self.requests[0], ("lease", {"connected": False, "ready": False, "capture_revision": 0}))
            self.call.participant_joined(owner)
            self.call.participant_joined(visitor)
            self.assertFalse(self.call.capturing())
            self.assertEqual(mic.requests, [])
            # A pause may have happened while the Agent joined the room.
            self.response = {**self.response, "active": False}

        self.ctx.connect = connect
        await self.call.run()
        self.assertEqual(len(self.requests), 2)
        self.assertEqual(self.requests[-1], ("lease", {"connected": True, "ready": False, "capture_revision": 1}))
        self.assertEqual(mic.requests, [])
        self.room.disconnect.assert_awaited_once()


class ProfileRegistrationTests(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        env = patch.dict(os.environ, SETTINGS, clear=True)
        env.start()
        self.addCleanup(env.stop)

    def test_separate_opt_in_and_exact_project(self):
        config = profile_agent.worker_config()
        self.assertEqual(config.livekit_url, SETTINGS["VP3_PROFILE_CALL_AGENT_URL"])
        self.assertEqual(config.api_base, "https://example.com/vp3")
        for key, value in (
            ("VP3_PROFILE_CALL_AGENT_ENABLED", "0"), ("LIVEKIT_URL", "wss://other.livekit.cloud"),
            ("VP3_PROFILE_CALL_WORKER_API_BASE", "http://example.com"),
            ("VP3_PROFILE_CALL_WORKER_SECRET", "short"),
            ("VP3_MEETING_WORKER_SECRET", SETTINGS["VP3_PROFILE_CALL_WORKER_SECRET"]),
            ("VP3_PROFILE_CALL_AGENT_NAME", "vp3-meeting-agent"),
        ):
            with self.subTest(key=key), patch.dict(os.environ, {key: value}):
                with self.assertRaises(ValueError):
                    profile_agent.worker_config()

    def test_dedicated_server_grant_and_dispatch_handler(self):
        with patch.object(profile_agent, "AgentServer") as constructor:
            server = profile_agent.build_server()
        self.assertEqual(vars(constructor.call_args.kwargs["permissions"]), {
            "can_subscribe": True, "can_publish": False, "can_publish_data": False,
            "can_update_metadata": False, "hidden": True,
        })
        self.assertEqual(constructor.call_args.kwargs["ws_url"], SETTINGS["VP3_PROFILE_CALL_AGENT_URL"])
        server.rtc_session.assert_called_once_with(
            agent_name=SETTINGS["VP3_PROFILE_CALL_AGENT_NAME"], on_request=profile_agent.request_profile_call,
        )

    def test_older_rtc_stops_before_registering(self):
        with patch.object(profile_agent.rtc, "LocalParticipant", object), patch.object(profile_agent, "AgentServer") as constructor:
            with self.assertRaisesRegex(RuntimeError, "livekit-agents>=1.8.10"):
                profile_agent.build_server()
        constructor.assert_not_called()

    async def test_accepts_only_the_canonical_scope_with_exact_identity(self):
        for name, room, metadata in (
            (SETTINGS["VP3_PROFILE_CALL_AGENT_NAME"], ROOM_NAME, METADATA),
            ("other-worker", ROOM_NAME, METADATA),
            (SETTINGS["VP3_PROFILE_CALL_AGENT_NAME"], f"vp3-profile-call-{CALL_ID}", METADATA),
            (SETTINGS["VP3_PROFILE_CALL_AGENT_NAME"], ROOM_NAME, {**METADATA, "vp3_meeting_public_id": "b" * 32}),
            (SETTINGS["VP3_PROFILE_CALL_AGENT_NAME"], ROOM_NAME, {**METADATA, "worker_key": "bad"}),
        ):
            req = SimpleNamespace(agent_name=name, room=SimpleNamespace(name=room),
                                  job=SimpleNamespace(metadata=json.dumps(metadata)),
                                  accept=AsyncMock(), reject=AsyncMock())
            await profile_agent.request_profile_call(req)
            if name == SETTINGS["VP3_PROFILE_CALL_AGENT_NAME"] and room == ROOM_NAME and metadata == METADATA:
                req.accept.assert_awaited_once_with(identity=AGENT_ID, name="Profile Agent")
                req.reject.assert_not_awaited()
            else:
                req.accept.assert_not_awaited()
                req.reject.assert_awaited_once()

    async def test_entrypoint_uses_job_room_before_the_rtc_connection(self):
        ctx = SimpleNamespace(
            job=SimpleNamespace(agent_name=SETTINGS["VP3_PROFILE_CALL_AGENT_NAME"], metadata=json.dumps(METADATA),
                                room=SimpleNamespace(name=ROOM_NAME)),
            room=SimpleNamespace(name=""),
        )
        with patch.object(profile_agent, "run_profile_call", AsyncMock()) as run:
            await profile_agent.profile_call_agent(ctx)
        args = run.await_args.args
        self.assertIs(args[0], ctx)
        self.assertEqual(args[1].identity, AGENT_ID)
        self.assertEqual(args[1].room_name, ROOM_NAME)
        self.assertEqual(args[2:4], ("https://example.com/vp3", SETTINGS["VP3_PROFILE_CALL_WORKER_SECRET"]))

    async def test_transcribes_only_final_results_from_allowed_frames(self):
        queue = asyncio.Queue()
        final = SimpleNamespace(text=" phrase ", start_time=0.25, end_time=0.5, confidence=0.8)
        flushed = asyncio.Event()
        send = AsyncMock(side_effect=lambda _: flushed.set())

        class Speech:
            aclose = AsyncMock()

            def __aiter__(self):
                return self

            async def __anext__(self):
                return await queue.get()

            def push_frame(self, frame):
                self.frame = frame
                queue.put_nowait(SimpleNamespace(type="interim", alternatives=[final]))
                queue.put_nowait(SimpleNamespace(type="final", alternatives=[final], request_id="result"))

        class Audio:
            aclose = AsyncMock()

            async def __aiter__(self):
                yield SimpleNamespace(frame="allowed")
                await asyncio.wait_for(flushed.wait(), timeout=1)

        speech, audio = Speech(), Audio()
        engine = SimpleNamespace(stream=lambda: speech)
        with (
            patch.object(profile_agent, "inference", SimpleNamespace(STT=lambda **_: engine)),
            patch.object(profile_agent, "stt", SimpleNamespace(SpeechEventType=SimpleNamespace(FINAL_TRANSCRIPT="final"))),
            patch.object(profile_agent, "time", SimpleNamespace(monotonic=lambda: 100.0)),
            patch.object(profile_agent.rtc, "AudioStream", return_value=audio, create=True),
        ):
            await profile_agent.transcribe_microphone(
                SimpleNamespace(sid="track"), IDENTITIES[0], CALL_ID, ROOM_NAME, 99.0, send,
                allow_stream=lambda: True, accept_frame=lambda: True,
            )
        self.assertEqual(speech.frame, "allowed")
        send.assert_awaited_once()
        segment = send.await_args.args[0]
        self.assertEqual((segment["participant_identity"], segment["text"]), (IDENTITIES[0], "phrase"))
        self.assertEqual((segment["start_ms"], segment["end_ms"]), (1250, 1500))
        self.assertEqual(len(segment["source_key"]), 64)
        speech.aclose.assert_awaited_once()
        audio.aclose.assert_awaited_once()


if __name__ == "__main__":
    unittest.main()
