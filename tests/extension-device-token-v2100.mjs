import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path,'utf8');
const must = (condition,message) => assert.equal(Boolean(condition),true,message);

const manifest = JSON.parse(read('browser-companion/manifest.json'));
const background = read('browser-companion/background.js');
const panelHtml = read('browser-companion/sidepanel.html');
const panelJs = read('browser-companion/sidepanel.js');
const optionsJs = read('browser-companion/options.js');
const auth = read('includes/extension-device-token-v2100.php');
const security = read('includes/extension-device-auth-v2001.php');
const approval = read('extension-connect.php');
const tokenApi = read('api/extension-token.php');
const meApi = read('api/extension-me.php');
const disconnectApi = read('api/extension-device-disconnect-v2030.php');
const bootstrap = read('includes/bootstrap.php');
const upgrade = read('upgrade.php');

// Chrome side: one web-auth flow and one durable local token.
must(manifest.manifest_version===3,'Manifest V3 required');
must(Number(String(manifest.version).split('.')[0])>=21,'Browser Companion must be v21+');
must(manifest.permissions?.includes('identity'),'Chrome identity permission required');
must(background.includes("chrome.identity.launchWebAuthFlow"),'web auth flow missing');
must(background.includes("chrome.identity.getRedirectURL('vp3-connect')"),'private Chrome callback missing');
must(background.includes("'/api/extension-token.php'"),'one-time code exchange missing');
must(background.includes("'/api/extension-me.php'"),'live account sync endpoint missing');
must(background.includes("await storage.set({ device_token:"),'durable device token must be stored locally');
must(background.includes("Authorization: `Bearer ${token}`"),'device token must be sent as Bearer auth');
must(background.includes("chrome.storage.local"),'device token must remain browser-local');
must(!background.includes("chrome.storage.sync"),'device token must never sync through Chrome');
must(!background.includes("'/api/extension-connect-request.php'"),'v21 must not create polling pairing requests');
must(!background.includes("'/api/extension-connect-status.php'"),'v21 must not poll connection status');
must(!background.includes("'/api/extension-session.php'"),'v21 must not mint/refresh short-lived extension sessions');
must(!background.includes("await storage.set({ pending_connection:"),'pending connection state must not be persisted');
must(!background.includes("await storage.set({ session:"),'renewable extension session state must not be persisted');
must(background.includes("async function currentAccount()"),'live account helper missing');
must(background.includes("return authorizedFetch('/api/extension-me.php'"),'account state must come from VP3');
must(background.includes("await storage.get(['device_token'])"),'connection state must use one durable token');
must(background.includes("case 'poll_connect': return pollConnect();"),'legacy panel message compatibility may remain');
must(background.includes("return publicState();"),'legacy poll message must be a no-op state read');
must(!panelHtml.includes('pendingControls'),'pending approval UI must be removed');
must(!panelHtml.includes('checkConnectionBtn'),'manual poll button must be removed');
must(!panelJs.includes('startPoll'),'panel polling loop must be removed');
must(!panelJs.includes('pollTimer'),'panel polling timer must be removed');
must(!optionsJs.includes('pending_connection'),'settings must not expose pending pairing state');

// Server side: one one-time code table, reused durable device registry, no public DDL.
must(auth.includes('extension_device_codes_v2100'),'one-time device code table missing');
must(auth.includes('code_hash CHAR(64)'),'authorization code must be stored hashed');
must(auth.includes('consumed_at DATETIME NULL'),'authorization code must be one-time');
must(auth.includes('DATE_ADD(NOW(),INTERVAL 5 MINUTE)'),'authorization code must expire quickly');
must(auth.includes("extension_devices_v2000"),'existing device registry must be reused');
must(auth.includes("credential_hash"),'durable device token must be stored hashed');
must(auth.includes("vp3_extension_secret_v2000()"),'device token must use cryptographic randomness');
must(auth.includes("vp3_extension_hash_v2000($deviceToken)"),'device token plaintext must never be stored');
must(auth.includes("^[a-p]{32}\\.chromiumapp\\.org$"),'Chrome callback host must be strictly validated');
must(auth.includes("$path!=='/vp3-connect'"),'Chrome callback path must be fixed');
must(auth.includes("vp3_extension_device_token_require_schema_v2100"),'runtime must fail closed when upgrade is missing');
must(!tokenApi.includes('ensure_schema'),'public token exchange must not run DDL');
must(!meApi.includes('ensure_schema'),'account sync endpoint must not run DDL');

// Approval uses normal VP3 login and explicit approval.
must(approval.includes('VP3_EXTENSION_DEVICE_APPROVAL_SESSION_V2100'),'v21 approval session missing');
must(approval.includes("login.php?return_to="),'normal VP3 login must be reused');
must(approval.includes("chrome.identity.launchWebAuthFlow") || approval.includes("chromiumapp"),'approval copy/code must identify Chrome callback flow');
must(approval.includes('verify_csrf()'),'browser approval must require CSRF protection');
must(approval.includes('value="approve"'),'Connect Browser approval action missing');
must(approval.includes('value="deny"'),'Cancel action missing');
must(approval.includes('vp3_extension_device_code_issue_v2100'),'approval must issue one-time code, not a device token');
must(!approval.includes("'device_token'=>") && !approval.includes('name="device_token"'),'approval page must never expose the durable device token');

// Exchange returns the token once; account sync and every protected API re-resolve live permissions.
must(tokenApi.includes('vp3_extension_device_code_exchange_v2100'),'token API must exchange one-time code');
must(tokenApi.includes('vp3_extension_live_capabilities_v2001'),'initial capability response must use live permission matrix');
must(meApi.includes('vp3_extension_session_authenticate_v2001($pdo)'),'account sync must use the shared live auth wrapper');
must(meApi.includes('vp3_extension_user_for_permission_v2001'),'account identity must come from live VP3 user');
must(security.includes('vp3_extension_device_token_authenticate_v2100'),'shared auth wrapper must accept durable device token');
must(security.includes('vp3_extension_session_authenticate_v2000'),'legacy short-lived session fallback must remain during migration');
must(security.includes('vp3_extension_live_capabilities_v2001'),'every authenticated request must recalculate effective capabilities');
must(disconnectApi.includes('vp3_extension_session_authenticate_v2001($pdo)'),'disconnect must accept the same durable bearer token');
must(disconnectApi.includes('vp3_extension_device_revoke_v2000'),'disconnect must revoke the server-side device');

// Upgrade/bootstrap integration.
must(bootstrap.includes("extension-device-token-v2100.php"),'bootstrap must load v21 device-token service');
must(upgrade.includes('vp3_extension_device_token_schema_ready_v2100()'),'upgrade completeness must include v21 code table');
must(upgrade.includes('vp3_extension_device_token_ensure_schema_v2100();'),'upgrade must install v21 code table');

console.log('VP3 Browser Companion durable device-token auth v21.00 contract passed.');
