import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(path, 'utf8');
const hardening = read('includes/extension-device-auth-v2001.php');
const requestApi = read('api/extension-connect-request.php');
const statusApi = read('api/extension-connect-status.php');
const sessionApi = read('api/extension-session.php');
const must = (condition, message) => assert.equal(Boolean(condition), true, message);

for (const [capability, permission] of [
  ['team.destinations.read','chat.access'],
  ['team.share.create','chat.access'],
  ['team.chat.read','chat.access'],
  ['agent.message','chat.access'],
  ['knowledge.write','knowledge.manage'],
  ['task.propose','chat.access'],
  ['notifications.read','account.access'],
  ['browser.asset.upload','account.access'],
]) {
  must(hardening.includes(`'${capability}' => '${permission}'`), `missing live permission mapping for ${capability}`);
}
must(hardening.includes('has_permission($permission, $user)'), 'effective extension capabilities must use the canonical live VP3 permission matrix');
must(hardening.includes('vp3_extension_live_capabilities_v2001('), 'live capability intersection helper missing');
must(hardening.includes('vp3_extension_session_authenticate_v2000'), 'v20.01 must preserve the reviewed v20.00 bearer primitive');
must(hardening.includes("$session['capabilities'] = vp3_extension_live_capabilities_v2001"), 'every authenticated request must recalculate current user authority');
must(hardening.includes('VP3_EXTENSION_SESSION_ISSUE_LIMIT_V2001 = 60'), 'session issue rate limit missing');
must(hardening.includes('VP3_EXTENSION_ACTIVE_SESSION_LIMIT_V2001 = 8'), 'active session bound missing');
must(hardening.includes("SET revoked_at=NOW()"), 'old active sessions must be revocable when the bound is reached');
must(hardening.includes("extension_allowed_origins"), 'explicit extension origin allow-list missing');
must(hardening.includes("extension_allow_unlisted_chrome_origins"), 'development-only unlisted Chrome origin escape hatch missing');
must(!hardening.includes("$allowed = (bool)preg_match('#^chrome-extension://"), 'production CORS must not trust every Chrome extension origin by default');
must(hardening.includes("$result['reconnect_required'] = true"), 'lost one-time credential must produce explicit reconnect state');

for (const api of [requestApi,statusApi,sessionApi]) {
  must(api.includes("extension-device-auth-v2001.php"), 'all Phase 1 APIs must load the v20.01 authorization layer');
  must(api.includes('vp3_extension_apply_cors_v2001()'), 'all Phase 1 APIs must use fail-closed v20.01 CORS');
}
must(requestApi.includes("request_ip=?") && requestApi.includes('>=50'), 'anonymous pairing must have independent IP throttling');
must(statusApi.includes('vp3_extension_connection_poll_v2001'), 'pairing poll must use v20.01 recovery/live-capability wrapper');
must(statusApi.includes('reconnect_required'), 'pairing status API must return reconnect_required');
must(sessionApi.includes('vp3_extension_session_issue_v2001'), 'session issuance must use bounded v20.01 lifecycle policy');
must(sessionApi.includes('VP3ExtensionSecurityExceptionV2001'), 'session API must preserve v20.01 rate/auth error codes');

console.log('VP3 Browser Companion Phase 1 v20.01 security audit contract passed.');
