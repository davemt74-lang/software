#!/usr/bin/env python3
"""Run one VP3 development claim on a dedicated staging host."""

import argparse
import hashlib
import ipaddress
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
from urllib import error, parse, request
from zipfile import BadZipFile, ZIP_DEFLATED, ZipFile


REPOSITORY = "davemt74-lang/software"
SHA = re.compile(r"[a-f0-9]{40}")
TOKEN = re.compile(r"[A-Za-z0-9_-]{43,128}")
REVISION = re.compile(r"[a-f0-9]{32}")
LEASE = re.compile(r"[a-f0-9]{64}")
DNS_HOST = re.compile(r"[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+")
MAX_JSON = 65536
MAX_ARCHIVE = 100 * 1024 * 1024
MAX_EXPANDED = 250 * 1024 * 1024
FAILURE = "The isolated coding or staging run did not verify."
EXCLUDED_DIRS = {".git", ".github", "private", "uploads", "tests", "tools", "_deploy"}
EXCLUDED_FILES = {"config.php", ".env", ".gitignore"}


class StageError(RuntimeError):
    """An operator-safe error without request, credentials, or provider output."""


class NoRedirect(request.HTTPRedirectHandler):
    def redirect_request(self, *_args, **_kwargs):
        return None


def matches(pattern, value):
    return isinstance(value, str) and pattern.fullmatch(value) is not None


def origin_of(url):
    try:
        target = parse.urlsplit(url)
        if (target.scheme == "https" and matches(DNS_HOST, target.hostname)
                and target.username is None and target.password is None and not target.query and not target.fragment
                and not re.search(r"[\s\x00-\x1f\x7f\\]", url)):
            try:
                ipaddress.ip_address(target.hostname)
            except ValueError:
                port = "" if target.port is None else f":{target.port}"
                origin = f"https://{target.hostname}{port}"
                if url.startswith(origin + "/") or url == origin:
                    return origin
    except (AttributeError, TypeError, ValueError):
        pass
    raise StageError("Use a canonical HTTPS URL without credentials, query or fragment.")


def configuration(env):
    api = env.get("VP3_AGENT_DEVELOPMENT_RUNNER_URL", "")
    preview = env.get("VP3_AGENT_DEVELOPMENT_PREVIEW_ORIGIN", "").rstrip("/")
    token = env.get("VP3_AGENT_DEVELOPMENT_RUNNER_TOKEN", "")
    preview_token = env.get("VP3_AGENT_DEVELOPMENT_PREVIEW_TOKEN", "")
    production_host = parse.urlsplit(origin_of(api)).hostname
    preview_host = parse.urlsplit(origin_of(preview)).hostname
    if (not api.endswith("/api/agent-development-runner-v1.php") or origin_of(preview) != preview
            or production_host == preview_host or production_host.endswith("." + preview_host)
            or preview_host.endswith("." + production_host)):
        raise StageError("Configure the runner endpoint and a separate HTTPS preview host.")
    if not matches(TOKEN, token) or not matches(TOKEN, preview_token) or token == preview_token:
        raise StageError("Configure distinct runner and preview tokens with at least 43 URL-safe characters.")
    source = Path(env.get("VP3_AGENT_DEVELOPMENT_SOURCE_REPO", ""))
    adapter = Path(env.get("VP3_AGENT_DEVELOPMENT_ADAPTER", ""))
    git = shutil.which("git", path=env.get("PATH"))
    if not source.is_absolute() or not source.is_dir() or not git:
        raise StageError("Configure a local VP3 Git checkout and install Git on this host.")
    if not adapter.is_absolute() or not adapter.is_file() or not os.access(adapter, os.X_OK):
        raise StageError("Configure an executable development adapter with an absolute path.")
    allowed = {"PATH", "LANG", "LC_ALL", "SYSTEMROOT", "WINDIR", "PATHEXT", "TMPDIR", "TEMP", "TMP"}
    adapter_env = {key: value for key, value in env.items()
                   if key.upper() in allowed or key.startswith("VP3_AGENT_DEVELOPMENT_ADAPTER_")}
    if any(token in value or preview_token in value for value in adapter_env.values()):
        raise StageError("The development adapter must use credentials separate from the worker tokens.")
    return dict(api=api, preview=preview, token=token, preview_token=preview_token,
                source=source.resolve(), adapter=adapter.resolve(), git=git, adapter_env=adapter_env)


def http_json(opener, url, token, data=None):
    encoded = None if data is None else json.dumps(data, ensure_ascii=False).encode("utf-8")
    headers = {"Authorization": f"Bearer {token}", "Accept": "application/json", "Cache-Control": "no-store"}
    if encoded is not None:
        headers["Content-Type"] = "application/json"
    try:
        with opener.open(request.Request(url, encoded, headers), timeout=20) as response:
            if response.status != 200 or response.geturl() != url:
                raise StageError("The service response did not verify.")
            raw = response.read(MAX_JSON + 1)
        if len(raw) > MAX_JSON:
            raise StageError("The service response exceeded the size limit.")
        result = json.loads(raw.decode("utf-8"))
        if not isinstance(result, dict):
            raise StageError("The service returned an invalid response.")
        return result
    except (error.URLError, OSError, ValueError) as exc:
        raise StageError("The service request did not complete successfully.") from exc


def verify_preview_page(opener, preview, token, head):
    headers = {"Authorization": f"Bearer {token}", "Accept": "text/html", "Cache-Control": "no-store"}
    try:
        with opener.open(request.Request(preview, headers=headers), timeout=20) as response:
            if (response.status != 200 or response.geturl() != preview
                    or response.headers.get("X-VP3-Stage-Commit") != head
                    or not response.headers.get("Content-Type", "").lower().startswith("text/html")
                    or not response.read(1)):
                raise StageError("The staged preview page did not verify at this candidate.")
    except (error.URLError, OSError, ValueError) as exc:
        raise StageError("The staged preview page could not be opened.") from exc


def git_run(cfg, path, *args):
    env = dict(cfg["adapter_env"], GIT_CONFIG_GLOBAL=os.devnull, GIT_CONFIG_NOSYSTEM="1",
               GIT_TERMINAL_PROMPT="0", GIT_OPTIONAL_LOCKS="0")
    try:
        return subprocess.run([cfg["git"], "-c", "core.hooksPath=" + os.devnull, "-C", str(path), *args],
                              env=env, check=True, capture_output=True, timeout=60).stdout
    except (OSError, subprocess.SubprocessError) as exc:
        raise StageError("The candidate Git checkout did not verify.") from exc


def checkout(cfg, workspace, base_sha):
    expected = {f"https://github.com/{REPOSITORY}", f"git@github.com:{REPOSITORY}",
                f"ssh://git@github.com/{REPOSITORY}"}
    remote = git_run(cfg, cfg["source"], "remote", "get-url", "origin").decode("utf-8").strip()
    if remote.removesuffix(".git") not in expected:
        raise StageError("The configured Git checkout is for a different repository.")
    tree = workspace / "checkout"
    tree.mkdir()
    git_run(cfg, tree, "init", "--quiet", "--template=")
    git_run(cfg, tree, "-c", "protocol.file.allow=always", "fetch", "--quiet", "--no-tags",
            "--no-recurse-submodules", "--depth=1", "--", str(cfg["source"]), base_sha)
    git_run(cfg, tree, "checkout", "--quiet", "--detach", "FETCH_HEAD")
    if git_run(cfg, tree, "rev-parse", "HEAD").decode("ascii").strip() != base_sha:
        raise StageError("The isolated checkout did not match the requested base.")
    return tree


def validate_job(job, cfg):
    if (not isinstance(job, dict) or not matches(REVISION, job.get("id"))
            or not matches(LEASE, job.get("lease")) or not matches(SHA, job.get("base_sha"))
            or not isinstance(job.get("request"), str) or not 1 <= len(job["request"]) <= 8000):
        raise StageError("The development claim is invalid.")
    if any(secret in job["request"] for secret in (cfg["token"], cfg["preview_token"], job["lease"])):
        raise StageError("The development request contains a worker credential.")


def invoke_adapter(cfg, workspace, tree, job):
    adapter_job = {key: job[key] for key in ("id", "base_sha", "request")}
    adapter_job.update(checkout=str(tree), artifact_path=str(workspace / "candidate.zip"), preview_origin=cfg["preview"])
    try:
        with tempfile.TemporaryFile() as output:
            with subprocess.Popen([str(cfg["adapter"])], cwd=tree, env=cfg["adapter_env"],
                                  stdin=subprocess.PIPE, stdout=output, stderr=subprocess.DEVNULL,
                                  start_new_session=os.name == "posix") as proc:
                try:
                    proc.communicate(json.dumps(adapter_job, ensure_ascii=False).encode("utf-8"), timeout=1440)
                except subprocess.TimeoutExpired as exc:
                    if os.name == "posix":
                        try:
                            os.killpg(proc.pid, signal.SIGKILL)
                        except ProcessLookupError:
                            pass
                    else:
                        proc.kill()
                    proc.wait()
                    raise StageError("The isolated development adapter timed out.") from exc
                if proc.returncode != 0 or output.tell() > MAX_JSON:
                    raise StageError("The isolated development adapter did not return a candidate.")
            output.seek(0)
            result = json.load(output)
    except (OSError, ValueError) as exc:
        raise StageError("The isolated development adapter did not return a valid result.") from exc
    return result


def included(path):
    parts = PurePosixPath(path).parts
    name = parts[-1]
    return (not EXCLUDED_DIRS.intersection(parts[:-1]) and name not in EXCLUDED_FILES
            and not name.startswith(".env.") and not name.endswith((".md", ".zip")))


def safe_archive_path(path):
    parts = path.split("/")
    return bool(path and not set(parts).intersection(("", ".", ".."))
                and not re.search(r"[\\:\x00-\x1f\x7f]", path))


def staged_files(cfg, tree, head):
    files = {}
    for entry in git_run(cfg, tree, "ls-tree", "-r", "-z", head).split(b"\0"):
        if not entry:
            continue
        metadata, raw_name = entry.split(b"\t", 1)
        path = raw_name.decode("utf-8")
        if not safe_archive_path(path):
            raise StageError("The candidate contains an unsafe package path.")
        if not included(path):
            continue
        mode, kind, oid = metadata.decode("ascii").split()
        if mode not in ("100644", "100755") or kind != "blob" or not matches(SHA, oid):
            raise StageError("The candidate contains a non-regular package entry.")
        files[path] = oid
    if not files or "VP3_RELEASE.json" in files:
        raise StageError("The candidate contains an invalid release file map.")
    return files


def stage_manifest(job, head):
    return dict(release="VP3 Cloud Staging", repository=REPOSITORY, revision=job["id"],
                base_commit=job["base_sha"], commit=head)


def verify_archive(cfg, tree, artifact, job, head):
    try:
        metadata = artifact.lstat()
        if not stat.S_ISREG(metadata.st_mode) or not 1 <= metadata.st_size <= MAX_ARCHIVE:
            raise StageError("The development adapter did not create a regular staged ZIP.")
        files = staged_files(cfg, tree, head)
        with artifact.open("rb") as bundle:
            digest = hashlib.sha256()
            for block in iter(lambda: bundle.read(1024 * 1024), b""):
                digest.update(block)
            bundle.seek(0)
            with ZipFile(bundle) as archive:
                entries = archive.infolist()
                names = [item.filename for item in entries]
                expected = set(files) | {"VP3_RELEASE.json"}
                if (len(names) != len(expected) or set(names) != expected
                        or sum(item.file_size for item in entries) > MAX_EXPANDED):
                    raise StageError("The staged ZIP does not match the candidate release file map.")
                for item in entries:
                    mode = stat.S_IFMT(item.external_attr >> 16)
                    if (item.is_dir() or item.orig_filename != item.filename
                            or mode not in (0, stat.S_IFREG) or item.flag_bits & 1):
                        raise StageError("The staged ZIP contains an unsafe entry.")
                    with archive.open(item) as stream:
                        if item.filename == "VP3_RELEASE.json":
                            if item.file_size > MAX_JSON:
                                raise StageError("The staged manifest exceeded the size limit.")
                            manifest = json.load(stream)
                            if manifest != stage_manifest(job, head):
                                raise StageError("The staged manifest does not match this candidate.")
                        else:
                            blob = hashlib.sha1(f"blob {item.file_size}\0".encode("ascii"), usedforsecurity=False)
                            for block in iter(lambda: stream.read(1024 * 1024), b""):
                                blob.update(block)
                            if blob.hexdigest() != files[item.filename]:
                                raise StageError("A staged file does not match the candidate Git revision.")
        return digest.hexdigest()
    except (OSError, ValueError, BadZipFile, RuntimeError) as exc:
        if isinstance(exc, StageError):
            raise
        raise StageError("The staged ZIP could not be verified.") from exc


def package_candidate(env, job):
    """Adapter helper. It builds the exact ZIP without either transport token."""
    git = shutil.which("git", path=env.get("PATH"))
    if (not git or not isinstance(job, dict) or not matches(REVISION, job.get("id"))
            or not matches(SHA, job.get("base_sha")) or not isinstance(job.get("artifact_path"), str)):
        raise StageError("Supply the adapter job JSON to package a candidate.")
    tree = Path.cwd().resolve()
    artifact = Path(job["artifact_path"])
    if not artifact.is_absolute() or artifact != tree.parent / "candidate.zip" or artifact.exists():
        raise StageError("Use the unoccupied candidate ZIP path supplied with this job.")
    cfg = dict(git=git, adapter_env={key: env[key] for key in ("PATH", "SYSTEMROOT", "WINDIR") if key in env})
    head = git_run(cfg, tree, "rev-parse", "HEAD").decode("ascii").strip()
    if not matches(SHA, head) or head == job["base_sha"]:
        raise StageError("Commit the staged change before building its ZIP.")
    git_run(cfg, tree, "merge-base", "--is-ancestor", job["base_sha"], head)
    files = staged_files(cfg, tree, head)
    with tempfile.TemporaryDirectory(prefix="vp3-stage-package-", dir=tree.parent) as temp:
        source = Path(temp) / "tree.zip"
        verified = Path(temp) / "candidate.zip"
        git_run(cfg, tree, "archive", "--format=zip", "--output=" + str(source), head)
        with ZipFile(source) as original, ZipFile(verified, "x", compression=ZIP_DEFLATED) as archive:
            for path in files:
                info = original.getinfo(path)
                with original.open(info) as source_stream, archive.open(info, "w") as target_stream:
                    shutil.copyfileobj(source_stream, target_stream)
            archive.writestr("VP3_RELEASE.json", json.dumps(stage_manifest(job, head)) + "\n")
        digest = verify_archive(cfg, tree, verified, job, head)
        os.replace(verified, artifact)
    return dict(head_sha=head, artifact_sha=digest)


def verify_result(cfg, opener, workspace, tree, job, result):
    if not isinstance(result, dict) or set(result) != {"head_sha", "preview_url", "summary", "checks"}:
        raise StageError("The development adapter did not return the expected result fields.")
    head, summary, checks = result["head_sha"], result["summary"], result["checks"]
    if (not matches(SHA, head) or head == job["base_sha"] or not isinstance(summary, str)
            or not 1 <= len(summary.strip()) <= 500 or not isinstance(checks, list) or not 1 <= len(checks) <= 8
            or any(not isinstance(check, str) or not 1 <= len(check.strip()) <= 300 for check in checks)):
        raise StageError("The candidate or check evidence did not verify.")
    if any(secret in value for secret in (cfg["token"], cfg["preview_token"], job["lease"]) for value in [summary, *checks]):
        raise StageError("The candidate evidence contains a worker credential.")
    if (git_run(cfg, tree, "rev-parse", "HEAD").decode("ascii").strip() != head
            or git_run(cfg, tree, "status", "--porcelain", "--untracked-files=no")):
        raise StageError("The candidate does not match a clean committed checkout.")
    git_run(cfg, tree, "merge-base", "--is-ancestor", job["base_sha"], head)
    preview = f"{cfg['preview']}/revisions/{head}/"
    if result["preview_url"] != preview:
        raise StageError("The preview URL does not match the configured origin and candidate.")
    digest = verify_archive(cfg, tree, workspace / "candidate.zip", job, head)
    proof = http_json(opener, preview + "vp3-stage-receipt.json", cfg["preview_token"])
    expected = dict(id=job["id"], base_sha=job["base_sha"], head_sha=head, artifact_sha=digest, smoke_passed=True)
    if proof != expected or proof.get("smoke_passed") is not True:
        raise StageError("The live stage receipt does not match the tested candidate.")
    verify_preview_page(opener, preview, cfg["preview_token"], head)
    return dict(head_sha=head, artifact_sha=digest, preview_url=preview,
                summary=summary.strip(), checks=[check.strip() for check in checks])


def run_once(cfg, opener):
    def call(payload):
        data = http_json(opener, cfg["api"], cfg["token"], payload)
        if data.get("ok") is not True:
            raise StageError("VP3 declined the development worker request.")
        return data

    claim = call({"action": "claim"})
    if "job" not in claim:
        raise StageError("VP3 returned no claim information.")
    job = claim["job"]
    if job is None:
        return "No development revisions are queued."
    validate_job(job, cfg)
    binding = {"id": job["id"], "lease": job["lease"]}
    try:
        with tempfile.TemporaryDirectory(prefix="vp3-development-") as temp:
            workspace = Path(temp)
            tree = checkout(cfg, workspace, job["base_sha"])
            result = verify_result(cfg, opener, workspace, tree, job, invoke_adapter(cfg, workspace, tree, job))
    except (StageError, OSError, ValueError) as exc:
        call({"action": "fail", **binding, "reason": FAILURE})
        if isinstance(exc, StageError):
            raise
        raise StageError(FAILURE) from exc
    call({"action": "finish", **binding, "result": result})
    return "VP3 recorded a verified staging candidate."


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--package", action="store_true", help="Read adapter job JSON and package the committed candidate")
    args = parser.parse_args()
    try:
        if args.package:
            raw = sys.stdin.buffer.read(MAX_JSON + 1)
            if len(raw) > MAX_JSON:
                raise StageError("The adapter job JSON exceeded the size limit.")
            try:
                print(json.dumps(package_candidate(os.environ, json.loads(raw))))
            except (BadZipFile, KeyError, OSError, ValueError) as exc:
                raise StageError("The candidate ZIP could not be created.") from exc
        else:
            print(run_once(configuration(os.environ), request.build_opener(NoRedirect())))
        return 0
    except StageError as exc:
        print(f"VP3 development worker: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
