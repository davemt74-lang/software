from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


helper = read("includes/homeserver-approvals-v028.php")
api = read("api/homeserver-approvals-v028.php")
page = read("approvals.php")
js = read("approvals-v028.js")
sidebar = read("includes/main-sidebar.php")

assert "approvals.federation.v1" in helper
assert "'approvals.review'" in helper
assert "'action.list'" in helper
assert "'action.approve'" in helper
assert "'action.deny'" in helper
assert "homeserver_vp3_check_pairing" in helper
assert "pending_request_id" in helper and "pending_claim_token_enc" in helper
assert "app_key'=>'vp3'" in helper

assert "require_login();" in api
assert "verify_csrf()" in api
assert "homeserver_approvals_v028_review" in api
assert "upgrade_permission" in api and "check_permission" in api

assert "data-endpoint" in page
assert "HomeServer remains the execution authority" in page
assert "approvals.review" in page
assert "approvals.php" in sidebar and "<strong>Approvals</strong>" in sidebar

# Server-controlled values must be rendered as text, never interpolated HTML.
assert ".innerHTML" not in js
assert "textContent" in js
assert "window.confirm" in js
assert "action arguments stay on HomeServer" in js
assert "request_id" in js

print("VP3 v0.28 HomeServer approval federation contract passed")
