"""Exercise the production PHP endpoint's body budget over real HTTP.

Only downstream pairing/storage is stubbed; method, input stream, JSON parsing,
size enforcement, field forwarding, and HTTP responses use the shipped endpoint.
Run with Python 3 and PHP CLI available (no application database required).
"""
from __future__ import annotations

import http.client
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
LIMIT = 1024 * 1024
TOKEN = "VP3-" + "-".join(["A1B2C3D4"] * 8)


with tempfile.TemporaryDirectory(prefix="vp3-pairing-body-") as directory:
    site = Path(directory)
    (site / "api").mkdir()
    (site / "includes").mkdir()
    shutil.copyfile(ROOT / "api/homeserver-https-pair-v1300.php",
                    site / "api/homeserver-https-pair-v1300.php")
    (site / "includes/bootstrap.php").write_text("<?php\n")
    (site / "includes/homeserver-account-pairing-v1210.php").write_text("<?php\n")
    (site / "includes/homeserver-https-relay-v1300.php").write_text("""<?php
const VP3_HOMESERVER_RELEASE_VERSION='2.4';
function homeserver_https_v1300_pair($token,$device,$local,$version,$caps,$recovery) {
    file_put_contents(__DIR__.'/received.json',json_encode(func_get_args()));
    if($token!=='VP3-'.implode('-',array_fill(0,8,'A1B2C3D4')))
        throw new RuntimeException('The VP3 pairing token is invalid or expired.');
    return ['device_id'=>$device,'transport'=>'vp3_https','protocol'=>'https-relay-v1',
      'session_token'=>$recovery,'poll_url'=>'https://vp3.me/api/homeserver-https-poll-v1300.php',
      'poll_after_ms'=>900];
}
""")
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    with (site / "server.log").open("wb") as log:
        process = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", str(site)],
                                   stdout=log, stderr=log)
        try:
            for _ in range(100):
                if process.poll() is not None:
                    raise RuntimeError((site / "server.log").read_text())
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                        break
                except OSError:
                    time.sleep(0.05)
            else:
                raise RuntimeError("PHP endpoint did not start")

            def request(raw: bytes, method="POST", chunked=False):
                client = http.client.HTTPConnection("127.0.0.1", port, timeout=10)
                try:
                    client.request(method, "/api/homeserver-https-pair-v1300.php",
                                   body=[raw] if chunked else raw,
                                   headers={"Content-Type": "application/json"},
                                   encode_chunked=chunked)
                    response = client.getresponse()
                    return response.status, json.loads(response.read())
                finally:
                    client.close()

            body = {"pairing_token": TOKEN, "device_id": "hs-0123456789abcdef01234567",
                    "homeserver_token": "L" * 64, "version": "2.4",
                    "recovery_session_token": "S" * 64,
                    "capabilities": {"features": [f"feature-{n:04d}" for n in range(3000)]}}
            raw = json.dumps(body, separators=(",", ":")).encode()
            assert 16384 < len(raw) < LIMIT
            status, reply = request(raw)
            assert status == 200 and reply["ok"] is True, (len(raw), status, reply)
            forwarded = json.loads((site / "includes/received.json").read_text())
            assert forwarded == [TOKEN, body["device_id"], body["homeserver_token"],
                                 "2.4", body["capabilities"], body["recovery_session_token"]]
            print(f"PASS existing HomeServer manifest above 16 KB accepted ({len(raw)} bytes)")

            body["capabilities"] = {"padding": ""}
            empty = json.dumps(body, separators=(",", ":")).encode()
            body["capabilities"]["padding"] = "x" * (LIMIT - len(empty))
            boundary = json.dumps(body, separators=(",", ":")).encode()
            assert len(boundary) == LIMIT
            assert request(boundary)[0] == 200
            print("PASS exact 1 MB boundary accepted")

            receipt = (site / "includes/received.json").read_bytes()
            for chunked in (False, True):
                status, reply = request(boundary + b" ", chunked=chunked)
                assert status == 413 and reply["ok"] is False, (chunked, status, reply)
                assert (site / "includes/received.json").read_bytes() == receipt
            print("PASS oversized declared and chunked bodies rejected before pairing")

            assert request(b"{broken-json")[0] == 400
            assert request(b"null")[0] == 400
            assert request(b"{}", method="GET")[0] == 405
            assert request(b'{"pairing_token":"invalid"}')[0] == 422
            print("PASS malformed body, wrong method, and invalid pairing token still rejected")
        finally:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)
