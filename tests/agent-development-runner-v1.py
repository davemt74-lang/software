#!/usr/bin/env python3
"""Exercise the isolated development bridge using local Git and HTTPS fixtures."""

import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from urllib.error import URLError


sys.dont_write_bytecode = True
WORKER = Path(__file__).resolve().parents[1] / "workers" / "agent-development-runner-v1.py"
SPEC = importlib.util.spec_from_file_location("vp3_development_worker", WORKER)
worker = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(worker)


class Response(io.BytesIO):
    def __init__(self, url, body, headers=None):
        super().__init__(body if isinstance(body, bytes) else json.dumps(body).encode())
        self.url = url
        self.headers = headers or {}
        self.status = 200

    def geturl(self):
        return self.url


class Transport:
    def __init__(self, case, issue=""):
        self.case = case
        self.issue = issue
        self.requests = []

    def open(self, request, timeout):
        url = request.full_url
        if url == self.case.cfg["api"]:
            self.case.assertEqual(request.get_header("Authorization"), "Bearer " + self.case.cfg["token"])
            data = json.loads(request.data)
            self.requests.append(data)
            if data["action"] == "claim":
                return Response(url, {"ok": True, "job": None if self.issue == "idle" else self.case.job})
            if self.issue == "uncertain_finish" and data["action"] == "finish":
                raise URLError("private fixture detail")
            return Response(url, {"ok": True})
        self.case.assertEqual(request.get_header("Authorization"), "Bearer " + self.case.cfg["preview_token"])
        proof = json.loads(self.case.receipt.read_text())
        preview = f"{self.case.cfg['preview']}/revisions/{proof['head_sha']}/"
        if url == preview + "vp3-stage-receipt.json":
            if self.issue == "wrong_receipt":
                proof["artifact_sha"] = "0" * 64
            if self.issue == "redirected_receipt":
                return Response("https://outside.example.test/vp3-stage-receipt.json", proof)
            return Response(url, proof)
        self.case.assertEqual(url, preview)
        return Response(url, b"<main>Preview</main>", {"Content-Type": "text/html; charset=utf-8",
                        "X-VP3-Stage-Commit": "0" * 40 if self.issue == "stale_page" else proof["head_sha"]})


class DevelopmentRunnerTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="vp3-dev-test-")
        self.addCleanup(self.temp.cleanup)
        home = Path(self.temp.name).resolve()
        source = home / "source"
        source.mkdir()
        self.git(source, "init", "--quiet", "--template=")
        self.git(source, "remote", "add", "origin", "https://github.com/davemt74-lang/software.git")
        self.git(source, "commit", "--quiet", "--allow-empty", "-m", "Fixture history")
        (source / "index.php").write_text("<?php echo 'Original';\n")
        (source / "config.php").write_text("Fixture excluded from artifact")
        self.git(source, "add", ".")
        self.git(source, "commit", "--quiet", "-m", "Fixture base")
        base = self.git(source, "rev-parse", "HEAD").strip()
        self.source = source
        self.receipt = home / "receipt.json"
        self.job = dict(id="a" * 32, lease="b" * 64, base_sha=base,
                        request="Add a safe staging fixture. $(touch should-never-be-executed)")
        adapter = home / "fixture-adapter"
        adapter.write_text(f"#!{sys.executable}\n" + r'''
import hashlib, json, os, pathlib, subprocess, sys, zipfile
assert 'VP3_AGENT_DEVELOPMENT_RUNNER_TOKEN' not in os.environ
assert 'VP3_AGENT_DEVELOPMENT_PREVIEW_TOKEN' not in os.environ
job = json.load(sys.stdin)
assert set(job) == {'id', 'base_sha', 'request', 'checkout', 'artifact_path', 'preview_origin'}
assert '$(touch should-never-be-executed)' in job['request']
root = pathlib.Path.cwd().resolve()
assert root == pathlib.Path(job['checkout'])
assert subprocess.check_output(['git','rev-parse','HEAD'],text=True).strip() == job['base_sha']
assert subprocess.check_output(['git','rev-list','--count','HEAD'],text=True).strip() == '1'
(root/'index.php').write_text("<?php echo 'Updated';\n")
subprocess.run(['git','add','index.php'],check=True)
subprocess.run(['git','-c','user.name=Fixture','-c','user.email=fixture@example.test','-c','commit.gpgSign=false',
                'commit','--quiet','-m','Fixture candidate'],check=True)
helper = os.environ['VP3_AGENT_DEVELOPMENT_ADAPTER_FIXTURE_HELPER']
result = json.loads(subprocess.check_output([sys.executable, helper, '--package'],input=json.dumps(job).encode()))
archive = pathlib.Path(job['artifact_path'])
with zipfile.ZipFile(archive) as package:
    assert set(package.namelist()) == {'index.php','VP3_RELEASE.json'}
    assert package.read('index.php') == b"<?php echo 'Updated';\n"
    assert hashlib.sha256(archive.read_bytes()).hexdigest() == result['artifact_sha']
    manifest = package.read('VP3_RELEASE.json')
issue = os.environ.get('VP3_AGENT_DEVELOPMENT_ADAPTER_FIXTURE_ISSUE', '')
if issue == 'tampered_zip':
    with zipfile.ZipFile(archive, 'w') as tampered:
        tampered.writestr('index.php', 'not the candidate')
        tampered.writestr('VP3_RELEASE.json', manifest)
proof = dict(id=job['id'],base_sha=job['base_sha'],head_sha=result['head_sha'],artifact_sha=result['artifact_sha'],smoke_passed=True)
pathlib.Path(os.environ['VP3_AGENT_DEVELOPMENT_ADAPTER_FIXTURE_RECEIPT']).write_text(json.dumps(proof))
url = job['preview_origin']+'/revisions/'+result['head_sha']+'/'
if issue == 'foreign_url':
    url = url.replace(job['preview_origin'], 'https://outside.example.test')
print(json.dumps(dict(head_sha=result['head_sha'],preview_url=url,summary='Fixture improvement',checks=['Fixture browser smoke'])))
''')
        adapter.chmod(0o700)
        self.env = dict(PATH=os.environ["PATH"],
                        VP3_AGENT_DEVELOPMENT_SOURCE_REPO=str(source), VP3_AGENT_DEVELOPMENT_ADAPTER=str(adapter),
                        VP3_AGENT_DEVELOPMENT_RUNNER_URL="https://vp3.example.test/api/agent-development-runner-v1.php",
                        VP3_AGENT_DEVELOPMENT_PREVIEW_ORIGIN="https://stage.example-preview.test",
                        VP3_AGENT_DEVELOPMENT_RUNNER_TOKEN="R" * 48, VP3_AGENT_DEVELOPMENT_PREVIEW_TOKEN="P" * 48,
                        VP3_AGENT_DEVELOPMENT_ADAPTER_FIXTURE_HELPER=str(WORKER),
                        VP3_AGENT_DEVELOPMENT_ADAPTER_FIXTURE_RECEIPT=str(self.receipt))
        self.cfg = worker.configuration(self.env)

    def git(self, repo, *args):
        return subprocess.check_output(["git", "-C", str(repo), "-c", "user.name=Fixture", "-c",
                                        "user.email=fixture@example.test", "-c", "commit.gpgSign=false", *args], text=True)

    def actions(self, transport):
        return [data["action"] for data in transport.requests]

    def test_staged_candidate_is_from_exact_private_checkout(self):
        transport = Transport(self)
        self.assertIn("verified staging", worker.run_once(self.cfg, transport))
        self.assertEqual(self.actions(transport), ["claim", "finish"])
        finish = transport.requests[-1]
        self.assertEqual(finish["id"], self.job["id"])
        self.assertEqual(finish["lease"], self.job["lease"])
        proof = json.loads(self.receipt.read_text())
        self.assertEqual(finish["result"]["artifact_sha"], proof["artifact_sha"])
        self.assertEqual(finish["result"]["head_sha"], proof["head_sha"])
        self.assertEqual(self.git(self.source, "rev-parse", "HEAD").strip(), self.job["base_sha"])
        self.assertFalse((self.source / "should-never-be-executed").exists())
        self.assertEqual((self.source / "index.php").read_text(), "<?php echo 'Original';\n")

    def test_candidate_with_foreign_preview_or_wrong_zip_never_finishes(self):
        for issue in ("foreign_url", "tampered_zip"):
            with self.subTest(issue=issue):
                cfg = worker.configuration(dict(self.env, VP3_AGENT_DEVELOPMENT_ADAPTER_FIXTURE_ISSUE=issue))
                transport = Transport(self)
                with self.assertRaises(worker.StageError):
                    worker.run_once(cfg, transport)
                self.assertEqual(self.actions(transport), ["claim", "fail"])
                self.assertEqual(transport.requests[-1]["reason"], worker.FAILURE)

    def test_stale_preview_receipt_or_live_page_never_finishes(self):
        for issue in ("wrong_receipt", "redirected_receipt", "stale_page"):
            with self.subTest(issue=issue):
                transport = Transport(self, issue)
                with self.assertRaises(worker.StageError):
                    worker.run_once(self.cfg, transport)
                self.assertEqual(self.actions(transport), ["claim", "fail"])

    def test_unavailable_base_never_runs_the_adapter(self):
        self.job["base_sha"] = "0" * 40
        transport = Transport(self)
        with self.assertRaises(worker.StageError):
            worker.run_once(self.cfg, transport)
        self.assertEqual(self.actions(transport), ["claim", "fail"])
        self.assertFalse(self.receipt.exists())

    def test_uncertain_finish_never_replays_or_marks_the_result_failed(self):
        transport = Transport(self, "uncertain_finish")
        with self.assertRaises(worker.StageError) as caught:
            worker.run_once(self.cfg, transport)
        self.assertNotIn("fixture detail", str(caught.exception))
        self.assertEqual(self.actions(transport), ["claim", "finish"])

    def test_idle_claim_and_unsafe_configuration(self):
        transport = Transport(self, "idle")
        self.assertIn("No development revisions", worker.run_once(self.cfg, transport))
        self.assertEqual(self.actions(transport), ["claim"])
        for settings in ({"VP3_AGENT_DEVELOPMENT_PREVIEW_ORIGIN": "https://vp3.example.test"},
                         {"VP3_AGENT_DEVELOPMENT_PREVIEW_ORIGIN": "https://child.vp3.example.test"},
                         {"VP3_AGENT_DEVELOPMENT_PREVIEW_ORIGIN": "https://127.0.0.1"},
                         {"VP3_AGENT_DEVELOPMENT_PREVIEW_ORIGIN": "https://stage.example.test?secret=1"},
                         {"VP3_AGENT_DEVELOPMENT_PREVIEW_TOKEN": self.cfg["token"]}):
            with self.subTest(settings=settings), self.assertRaises(worker.StageError):
                worker.configuration(dict(self.env, **settings))


if __name__ == "__main__":
    unittest.main(verbosity=2)
