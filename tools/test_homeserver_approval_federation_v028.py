from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


helper = read("includes/homeserver-approvals-v028.php")
api = read("api/homeserver-approvals-v028.php")
status_api = read("api/homeserver-status.php")
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
assert "permission_upgrade_pending" in helper and "approval_code" in helper
assert "app_key'=>'vp3'" in helper
assert "homeserver_approvals_v028_claim_and_pair" in helper

assert "require_login();" in api
assert "verify_csrf()" in api
assert "homeserver_approvals_v028_review" in api
assert "upgrade_permission" in api and "check_permission" in api

# Fresh pairings request the federation permission immediately; older pairings
# use the explicit permission-upgrade flow instead of receiving silent privilege.
assert "homeserver-approvals-v028.php" in status_api
assert "homeserver_approvals_v028_claim_and_pair" in status_api
assert "homeserver_vp3_claim_and_pair" not in status_api

assert "data-endpoint" in page
assert "HomeServer remains the execution authority" in page
assert "approvals.review" in page
assert "approvals.php" in sidebar and "<strong>Approvals</strong>" in sidebar

# Server-controlled values must be rendered as text, never interpolated HTML.
assert ".innerHTML" not in js
assert "textContent" in js
assert "window.confirm" in js
assert "tool arguments stay on HomeServer" in js
assert "action_key" in js
assert "request_id" in js
assert "Re-pair required" in js

print("VP3 v0.28 HomeServer approval federation contract passed")
