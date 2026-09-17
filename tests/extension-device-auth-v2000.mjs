import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(path, 'utf8');
const auth = read('includes/extension-device-auth-v2000.php');
const bootstrap = read('includes/bootstrap.php');
const upgrade = read('upgrade.php');
const requestApi = read('api/extension-connect-request.php');
const statusApi = read('api/extension-connect-status.php');
const sessionApi = read('api/extension-session.php');
const approval = read('extension-connect.php');
const devices = read('connected-browsers.php');

const must = (condition, message) => assert.equal(Boolean(condition), true, message);

// Schema and lifecycle contract.
for (const table of ['extension_devices_v2000','extension_connection_requests_v2000','extension_sessions_v2000']) {
  must(auth.includes(table), `missing ${table}`);
}
must(auth.includes('credential_hash CHAR(64)'), 'persistent device secret must be hashed');
must(auth.includes('poll_token_hash CHAR(64)'), 'poll secret must be hashed');
must(auth.includes('approval_token_hash CHAR(64)'), 'approval secret must be independently hashed');
must(auth.includes('token_hash CHAR(64)'), 'session token must be hashed');
must(!auth.includes('device_credential VARCHAR'), 'plaintext device credentials must never be stored');
must(!auth.includes('poll_token VARCHAR'), 'plaintext poll tokens must never be stored');
must(!auth.includes('approval_token VARCHAR'), 'plaintext approval tokens must never be stored');
must(auth.includes("random_bytes(32)"), 'secrets must use cryptographic randomness');
must(auth.includes("hash('sha256'"), 'secret hashes must use SHA-256');

// Pairing must keep polling authority separate from approval authority.
must(auth.includes('$pollToken = vp3_extension_secret_v2000();'), 'poll token must be generated separately');
must(auth.includes('$approvalToken = vp3_extension_secret_v2000();'), 'approval token must be generated separately');
must(auth.includes('poll_token_hash,approval_token_hash'), 'both pairing hashes must be persisted');
must(auth.includes('credential_delivered_at'), 'device credential must have one-time delivery state');

// Sessions must be short-lived and revocation must immediately reach active sessions.
must(auth.includes('VP3_EXTENSION_SESSION_TTL_V2000 = 3600'), 'session TTL must remain one hour');
must(auth.includes("DATE_ADD(NOW(),INTERVAL 60 MINUTE)"), 'database session expiry must remain one hour');
must(auth.includes("UPDATE extension_sessions_v2000 SET revoked_at=COALESCE(revoked_at,NOW())"), 'device revoke must revoke sessions');
must(auth.includes("d.device_status"), 'session auth must check live device state');
must(auth.includes("u.is_active"), 'session auth must check active user state');

// Extension capabilities may only come from the explicit allowlist.
for (const capability of ['team.destinations.read','team.share.create','team.chat.read','agent.message','knowledge.write','task.propose','notifications.read','browser.asset.upload']) {
  must(auth.includes(`'${capability}'`), `missing capability ${capability}`);
}

// Bootstrap/upgrade integration is mandatory; APIs must not perform DDL.
must(bootstrap.includes("require_once __DIR__.'/extension-device-auth-v2000.php';"), 'bootstrap must load extension auth service');
must(upgrade.includes('vp3_extension_schema_ready_v2000()'), 'upgrade completeness must include extension auth schema');
must(upgrade.includes('vp3_extension_ensure_schema_v2000();'), 'upgrade must install extension auth schema');
must(upgrade.includes('vp3_agent_memory_scope_schema_ready_v410();'), 'existing Agent memory upgrade call must remain intact');
for (const api of [requestApi,statusApi,sessionApi]) {
  must(!api.includes('vp3_extension_ensure_schema_v2000'), 'public extension APIs must not run DDL');
  must(api.includes("header('Cache-Control: no-store')"), 'extension APIs must disable caching');
  must(api.includes('vp3_extension_apply_cors_v2000()'), 'extension APIs must use extension CORS policy');
}

// Request API remains anonymous but bounded and contract-versioned.
must(requestApi.includes("strlen($raw)>8192"), 'connection request body must be bounded');
must(requestApi.includes("contract_version"), 'connection request must enforce contract version');
must(requestApi.includes("429"), 'connection request must expose rate limiting');

// Approval is a normal authenticated VP3 page with CSRF and explicit decisions.
must(approval.includes('require_login();'), 'approval page must require VP3 login');
must(approval.includes('verify_csrf()'), 'approval decision must require CSRF protection');
must(approval.includes('value="approve"'), 'approval page must expose explicit approve action');
must(approval.includes('value="deny"'), 'approval page must expose explicit deny action');
must(!approval.includes('device_credential'), 'approval page must never expose the device credential');

// Device management must be owner scoped and CSRF protected.
must(devices.includes('require_login();'), 'connected browsers page must require login');
must(devices.includes('verify_csrf()'), 'revocation must require CSRF protection');
must(devices.includes("vp3_extension_device_revoke_v2000($pdo,(int)$user['id'],$deviceId)"), 'revoke must be scoped to current user');

// Status is the only one-time credential-delivery surface; session endpoint exchanges it.
must(statusApi.includes("$payload['device_credential']"), 'status endpoint must deliver approved credential');
must(sessionApi.includes("$input['device_credential']"), 'session endpoint must exchange device credential');
must(sessionApi.includes('authentication_required'), 'session endpoint must fail closed on bad credential');

console.log('VP3 Browser Companion extension-device-auth v20.00 contract passed.');
