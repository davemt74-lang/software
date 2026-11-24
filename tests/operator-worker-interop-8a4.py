"""Real Python/PHP worker interop over isolated loopback TLS (no carrier calls).

Only the fixture's certificate is trusted in its test connection factory.
The production client is imported from a pinned, independently checked-out
HomeServer tree. PHP uses its actual credential/registration dispatch code.
No production config, credentials, external server or database are accessed.
"""
from __future__ import annotations

import base64
import http.client
import http.server
import json
import os
from pathlib import Path
import socket
import ssl
import subprocess
import sys
import tempfile
import threading
import unittest

ROOT = Path(__file__).resolve().parents[1]
HOME = Path(os.environ["VP3_INTEROP_HOMESERVER"]).resolve()
sys.path.insert(0, str(HOME))
from telephony.operator_worker_pairing import (  # noqa: E402
    CREDENTIAL_PATH, PAIRING_POLL_PATH, PairingSnapshot, PairedOperatorWorker, PairedWorkerError,
)
from telephony.operator_worker_https import REGISTRATION_PATH, RegistrationTransportError  # noqa: E402
from telephony.operator_worker_supervisor import (  # noqa: E402
    OperatorWorkerSupervisor, CAPABILITIES, RESTART_FENCE_SECONDS,
)

ORIGIN = "https://cloud.example"
DEVICE = "hs-" + "a" * 24
BASE_TIME = 1_800_000_000
TOKEN = "A" * 64


class Lab:
    def __init__(self, directory: Path):
        self.now = BASE_TIME
        self.elapsed = 0.0
        self.snapshot = PairingSnapshot(ORIGIN + PAIRING_POLL_PATH, DEVICE, TOKEN)
        self.frames = []
        self.lose_next_registration = False
        self.redirect_next = False
        self.lock = threading.Lock()
        self.process = None
        self.server = None
        self.thread = None
        self.cert = directory / "cert.pem"
        key = directory / "key.pem"
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes",
                        "-days", "1", "-subj", "/CN=cloud.example",
                        "-addext", "subjectAltName=DNS:cloud.example",
                        "-keyout", str(key), "-out", str(self.cert)],
                       check=True, capture_output=True, timeout=20)
        self.process = subprocess.Popen(
            ["php", str(ROOT / "tests/fixtures/operator-worker-interop-8a4.php"), "--fixture-only"],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            text=True, bufsize=1)
        lab = self

        class Handler(http.server.BaseHTTPRequestHandler):
            protocol_version = "HTTP/1.1"

            def log_message(self, *_):
                pass

            def do_POST(self):
                if self.path not in (CREDENTIAL_PATH, REGISTRATION_PATH):
                    self.send_error(404)
                    return
                if lab.redirect_next:
                    lab.redirect_next = False
                    self.send_response(302)
                    self.send_header("Location", "https://forbidden.example/")
                    self.send_header("Content-Length", "0")
                    self.send_header("Connection", "close")
                    self.end_headers()
                    self.close_connection = True
                    return
                length = self.headers.get("Content-Length", "")
                if not length.isdecimal() or not 2 <= int(length) <= 4096:
                    self.send_error(400)
                    return
                raw = self.rfile.read(int(length))
                server = {"HTTPS": "on", "REQUEST_METHOD": "POST",
                          "CONTENT_TYPE": self.headers.get("Content-Type", ""),
                          "CONTENT_LENGTH": length}
                for key, header in (("HTTP_AUTHORIZATION", "Authorization"),
                                    ("HTTP_X_HOMESERVER_DEVICE", "X-HomeServer-Device"),
                                    ("HTTP_X_VP3_OPERATOR_SIGNATURE", "X-VP3-Operator-Signature")):
                    if header in self.headers:
                        server[key] = self.headers[header]
                credential = self.path == CREDENTIAL_PATH
                if not credential:
                    lab.frames.append((raw, server.get("HTTP_X_VP3_OPERATOR_SIGNATURE")))
                result = lab.rpc("http", server=server, body=base64.b64encode(raw).decode(),
                                 credential=credential)
                if not credential and lab.lose_next_registration:
                    # Commit the real PHP receipt, then intentionally lose the response.
                    lab.lose_next_registration = False
                    self.close_connection = True
                    try:
                        self.connection.shutdown(socket.SHUT_RDWR)
                    except OSError:
                        pass
                    return
                body = base64.b64decode(result["body"])
                self.send_response(result["status"])
                self.send_header("Content-Type", "application/json; charset=utf-8")
                self.send_header("Cache-Control", "no-store")
                self.send_header("Content-Length", str(len(body)))
                self.send_header("Connection", "close")
                if result["signature"] is not None:
                    self.send_header("X-VP3-Operator-Signature", result["signature"])
                self.end_headers()
                self.wfile.write(body)
                self.close_connection = True

        self.server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self.server.daemon_threads = True
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.minimum_version = ssl.TLSVersion.TLSv1_2
        ctx.load_cert_chain(self.cert, key)
        self.server.socket = ctx.wrap_socket(self.server.socket, server_side=True)
        self.port = self.server.server_address[1]
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def rpc(self, op, **args):
        with self.lock:
            if self.process.poll() is not None:
                raise RuntimeError("PHP fixture exited")
            line = json.dumps({"op": op, "now": self.now, **args})
            self.process.stdin.write(line + "\n")
            self.process.stdin.flush()
            response = self.process.stdout.readline()
            result = json.loads(response)
            if result.get("ok") is not True:
                raise RuntimeError("PHP fixture rejected command")
            return result["result"]

    def factory(self, host, *, port, timeout, context):
        if host != "cloud.example" or port != 443:
            raise AssertionError("Client escaped pinned origin")
        if not context.check_hostname or context.verify_mode != ssl.CERT_REQUIRED:
            raise AssertionError("Client weakened TLS verification")
        if context.minimum_version < ssl.TLSVersion.TLSv1_2:
            raise AssertionError("Client weakened TLS version")
        context.load_verify_locations(cafile=str(self.cert))
        lab = self

        class LoopbackConnection(http.client.HTTPSConnection):
            def connect(self):
                # Resolve the synthetic configured host to the isolated TLS fixture.
                raw = socket.create_connection(("127.0.0.1", lab.port), timeout)
                try:
                    self.sock = context.wrap_socket(raw, server_hostname=self.host)
                except BaseException:
                    raw.close()
                    raise

        return LoopbackConnection(host, port=port, timeout=timeout, context=context)

    def worker(self):
        return PairedOperatorWorker(ORIGIN, lambda: self.snapshot, clock=lambda: self.now,
                                    connection_factory=self.factory)

    def supervisor(self):
        return OperatorWorkerSupervisor(self.worker, monotonic=lambda: self.elapsed,
                                        wall=lambda: self.now)

    def advance(self, seconds):
        self.now += seconds
        self.elapsed += seconds

    def close(self):
        if self.server is not None:
            if self.thread is not None:
                self.server.shutdown()
                self.thread.join(timeout=2)
            self.server.server_close()
        if self.process is not None:
            if self.process.stdin is not None:
                self.process.stdin.close()
            try:
                self.process.wait(timeout=3)
            except subprocess.TimeoutExpired:
                self.process.kill()
                self.process.wait(timeout=3)
            for stream in (self.process.stdout, self.process.stderr):
                if stream is not None:
                    stream.close()


class CrossRuntimeAcceptance(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory(prefix="vp3-interop-")
        self.addCleanup(directory.cleanup)
        self.lab = Lab(Path(directory.name))
        self.addCleanup(self.lab.close)

    def test_browser_independent_registration_heartbeat_retire(self):
        lab = self.lab
        supervisor = lab.supervisor()
        self.addCleanup(supervisor.shutdown)
        self.assertEqual(lab.rpc("status")["state"], "awaiting_registration")
        self.assertTrue(supervisor.step())
        self.assertTrue(supervisor.report()["current"])
        first = lab.rpc("status")
        self.assertEqual(first["state"], "reporting")
        self.assertEqual(first["fresh_until"], BASE_TIME + 25)
        self.assertFalse(first["execution_enabled"])
        self.assertFalse(first["live_certified"])
        self.assertEqual(lab.rpc("status", owner=2)["state"], "not_granted")
        lab.advance(20)
        self.assertTrue(supervisor.step())
        self.assertEqual(lab.rpc("status")["last_verified_epoch"], BASE_TIME + 20)
        lab.advance(25)
        self.assertEqual(lab.rpc("status")["state"], "stale")
        self.assertFalse(supervisor.report()["current"])
        supervisor.shutdown()
        self.assertEqual(lab.rpc("status")["state"], "retired")
        self.assertEqual(lab.rpc("counts"), {"registry": 1, "claims": 0})

    def test_lost_ack_replays_exact_bytes_and_does_not_extend_lease(self):
        lab = self.lab
        supervisor = lab.supervisor()
        self.addCleanup(supervisor.shutdown)
        lab.lose_next_registration = True
        self.assertTrue(supervisor.step())
        self.assertEqual(supervisor.report()["state"], "awaiting_ack")
        self.assertFalse(supervisor.report()["current"])
        original = lab.rpc("status")
        self.assertEqual(original["state"], "reporting")
        lab.advance(2)
        self.assertTrue(supervisor.step())
        self.assertTrue(supervisor.report()["current"])
        self.assertEqual(lab.frames[0], lab.frames[1])
        after = lab.rpc("status")
        self.assertEqual(after["last_verified_epoch"], original["last_verified_epoch"])
        self.assertEqual(after["fresh_until"], original["fresh_until"])
        self.assertEqual(lab.rpc("counts"), {"registry": 1, "claims": 0})

    def test_restart_fences_old_boot_and_recovers_after_expiry(self):
        lab = self.lab
        first = lab.worker()
        first.send("register", CAPABILITIES)
        first.close()  # Simulate abrupt process death without retire.
        second = lab.supervisor()
        self.addCleanup(second.shutdown)
        second.step()
        self.assertFalse(second.report()["current"])
        for seconds in (2, 4, 8):
            lab.advance(seconds)
            second.step()
        self.assertEqual(second.report()["state"], "cooldown")
        lab.advance(RESTART_FENCE_SECONDS - 1)
        self.assertFalse(second.step())
        lab.advance(1)
        self.assertTrue(second.step())
        self.assertTrue(second.report()["current"])
        self.assertEqual(lab.rpc("status")["state"], "reporting")
        self.assertEqual(lab.rpc("counts"), {"registry": 1, "claims": 0})

    def test_remote_revoke_rejects_cached_and_rotated_pairing(self):
        lab = self.lab
        old = lab.worker()
        self.addCleanup(old.close)
        old.send("register", CAPABILITIES)
        lab.rpc("disconnect")
        self.assertEqual(lab.rpc("status")["state"], "pairing_unavailable")
        lab.advance(1)
        with self.assertRaises(RegistrationTransportError):
            old.send("heartbeat", CAPABILITIES)
        self.assertFalse(old.status()["current"])
        with self.assertRaises(PairedWorkerError):
            lab.worker()
        lab.rpc("rotate")
        self.assertEqual(lab.rpc("status")["state"], "awaiting_registration")
        with self.assertRaises(RegistrationTransportError):
            old.retry_pending()
        lab.snapshot = PairingSnapshot(ORIGIN + PAIRING_POLL_PATH, DEVICE, "C" * 64)
        new = lab.worker()
        self.addCleanup(new.close)
        self.assertTrue(new.send("register", CAPABILITIES)["current"])
        self.assertEqual(lab.rpc("status")["state"], "reporting")
        self.assertEqual(lab.rpc("counts"), {"registry": 2, "claims": 0})

    def test_credential_redirect_is_not_followed(self):
        self.lab.redirect_next = True
        with self.assertRaises(PairedWorkerError):
            self.lab.worker()
        self.assertEqual(self.lab.rpc("counts"), {"registry": 0, "claims": 0})

    def test_wrong_pairing_bearer_never_registers(self):
        self.lab.snapshot = PairingSnapshot(ORIGIN + PAIRING_POLL_PATH, DEVICE, "B" * 64)
        with self.assertRaises(PairedWorkerError):
            self.lab.worker()
        self.assertEqual(self.lab.rpc("counts"), {"registry": 0, "claims": 0})


if __name__ == "__main__":
    unittest.main(verbosity=2)
