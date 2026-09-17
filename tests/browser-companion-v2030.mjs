import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(path, 'utf8');
const must = (condition, message) => assert.equal(Boolean(condition), true, message);
const manifest = JSON.parse(read('browser-companion/manifest.json'));
const background = read('browser-companion/background.js');
const sidepanelHtml = read('browser-companion/sidepanel.html');
const sidepanelJs = read('browser-companion/sidepanel.js');
const optionsHtml = read('browser-companion/options.html');
const optionsJs = read('browser-companion/options.js');
const actionsApi = read('api/extension-browser-share-actions-v2030.php');
const disconnectApi = read('api/extension-device-disconnect-v2030.php');
const handoff = read('browser-share-agent-handoff.php');
const shareApi = read('api/browser-share.php');
const destinationsApi = read('api/extension-share-destinations.php');
const sessionApi = read('api/extension-session.php');
const security = read('includes/extension-device-auth-v2001.php');
const phase3 = read('includes/browser-share-chat-feed-v2020.php');

must(manifest.manifest_version === 3, 'Browser Companion must use Manifest V3');
must(manifest.background?.service_worker === 'background.js', 'MV3 service worker is required');
must(manifest.side_panel?.default_path === 'sidepanel.html', 'side panel entry point is required');
for (const permission of ['activeTab','contextMenus','scripting','sidePanel','storage']) {
  must(manifest.permissions?.includes(permission), `missing Chrome permission ${permission}`);
}
must(!manifest.permissions?.includes('<all_urls>'), 'all URLs must never be a normal permission');
must(!manifest.host_permissions?.includes('<all_urls>'), 'all URLs must never be a required host permission');
must(manifest.host_permissions?.includes('https://vp3.me/*'), 'default VP3 host permission missing');
must(manifest.content_security_policy?.extension_pages?.includes("script-src 'self'"), 'extension CSP must prohibit remote script execution');

for (const source of [background, sidepanelJs, optionsJs]) {
  must(!/\beval\s*\(/.test(source), 'extension code must not use eval');
  must(!/new\s+Function\s*\(/.test(source), 'extension code must not construct executable code');
  must(!/innerHTML\s*=/.test(source), 'extension code must not inject HTML strings');
}
for (const html of [sidepanelHtml, optionsHtml]) {
  must(!/<script[^>]+src=["']https?:\/\//i.test(html), 'extension pages must not load remote scripts');
  must(!/<script(?![^>]+src=)/i.test(html), 'extension pages must not contain inline scripts');
}

must(background.includes("chrome.contextMenus.create({ id: 'vp3-share-selection'"), 'selection context menu missing');
must(background.includes('chrome.sidePanel.open({ tabId: tab.id })'), 'context menu must open the side panel');
must(background.includes('window.getSelection'), 'current-page selection detection missing');
must(background.includes("document.querySelector('link[rel=\"canonical\"]')"), 'canonical page URL detection missing');
must(background.includes('utf8Limit'), 'UTF-8 byte payload bounding missing');
must(background.includes('32768'), 'selection byte limit must match Browser Share contract');
must(background.includes('4096'), 'note byte limit must match Browser Share contract');
must(background.includes("['team_general', 'conversation'].includes(destination.kind)"), 'destination kinds must be allowlisted client-side');
must(background.includes("'X-VP3-Idempotency-Key': idempotencyKey"), 'share idempotency key header missing');
must(background.includes("schema_version: 1"), 'Browser Share schema version missing');
must(background.includes("share_type: 'selection'"), 'v20.30 must remain selection-only');
must(background.includes('Authorization: `Bearer ${token}`'), 'short-lived bearer session header missing');
must(background.includes("'/api/extension-session.php'"), 'session renewal endpoint missing');
must(background.includes("'/api/extension-share-destinations.php'"), 'destination endpoint missing');
must(background.includes("'/api/browser-share.php'"), 'canonical Browser Share endpoint missing');
must(background.includes("'/api/extension-browser-share-actions-v2030.php'"), 'Browser Share action endpoint missing');
must(background.includes("'/api/extension-device-disconnect-v2030.php'"), 'server-side device revoke endpoint missing');
must(background.includes('chrome.storage.local'), 'device state must use extension-local storage');
must(!background.includes('chrome.storage.sync'), 'device credentials must not sync between browsers');
must(background.includes('async function clearRevokedConnection()'), 'revoked credential cleanup helper missing');
must(background.includes("'last_share', 'pending_capture'"), 'revocation/disconnect must clear cached share and temporary capture state');
must(background.includes("revoked.code = 'reconnect_required'"), 'revoked session refresh must return an explicit reconnect state');
must(background.includes("await storage.get(['device_id', 'pending_connection'])"), 'VP3 site changes must inspect connection state');
must(background.includes('Disconnect this browser from the current VP3 site before changing the VP3 site.'), 'device credentials must not be carried across VP3 origins');
must(background.includes("case 'capture': return activeCapture();"), 'normal page capture must remain in memory instead of being persisted');
must(background.includes("case 'clear_pending_capture': await storage.remove('pending_capture')"), 'context-menu capture must be explicitly consumable');
must(background.includes("await storage.remove('pending_capture');"), 'successful shares must clear temporary captured selection storage');
must(background.includes('browser_share: payload.browser_share || null'), 'last-share cache must persist only Browser Share metadata');
must(!background.includes("last_share: { ...payload, source_url"), 'last-share cache must not persist the original source URL or capture payload');
must(sidepanelJs.includes("message('clear_pending_capture')"), 'side panel must consume context-menu selection after opening');
must(sidepanelJs.includes("error.code === 'reconnect_required'"), 'side panel must immediately redraw revoked connections');
must(sidepanelJs.includes("caps.has('agent.message')"), 'Ask VP3 must follow live Agent capability state');
must(sidepanelJs.includes("caps.has('knowledge.write')"), 'Knowledge action must follow live capability state');
must(sidepanelJs.includes("caps.has('task.propose')"), 'Task action must follow live capability state');

for (const capability of ['team.destinations.read','team.share.create','agent.message','knowledge.write','task.propose']) {
  must(background.includes(`'${capability}'`), `requested capability ${capability} missing`);
}

must(sidepanelHtml.includes('Share with VP3'), 'share CTA missing');
for (const action of ['Ask VP3','Save to Knowledge','Create Task','Open source','Open in VP3 Messages']) {
  must(sidepanelHtml.includes(action), `side panel action ${action} missing`);
}
must(sidepanelJs.includes("runShareAction('ask_agent'"), 'Ask VP3 wiring missing');
must(sidepanelJs.includes("runShareAction('save_knowledge'"), 'Knowledge wiring missing');
must(sidepanelJs.includes("runShareAction('create_task'"), 'Task wiring missing');
must(optionsHtml.includes('Disconnect and revoke this browser'), 'revoke control missing');
must(optionsJs.includes("message('set_base_url'"), 'alternate VP3 origin settings missing');
must(optionsJs.includes("message('disconnect'"), 'disconnect settings wiring missing');

for (const api of [actionsApi, disconnectApi]) {
  must(api.includes('vp3_extension_apply_cors_v2001()'), 'extension APIs must use hardened fail-closed CORS');
  must(api.includes('vp3_extension_session_authenticate_v2001($pdo)'), 'extension APIs must authenticate live bearer sessions');
  must(api.includes("HTTP_X_VP3_CONTRACT_VERSION"), 'extension APIs must enforce the contract header');
  must(api.includes("header('Cache-Control: no-store"), 'extension APIs must disable caching');
  must(!api.includes('ensure_schema'), 'public extension APIs must never run DDL');
}

must(actionsApi.includes("vp3_extension_session_has_capability_v2001($session,'agent.message')"), 'Ask Agent capability gate missing');
must(actionsApi.includes("vp3_extension_session_has_capability_v2001($session,'knowledge.write')"), 'Knowledge capability gate missing');
must(actionsApi.includes("vp3_extension_session_has_capability_v2001($session,'task.propose')"), 'Task capability gate missing');
must(actionsApi.includes('vp3_browser_share_resolve_v2020'), 'actions must live-resolve authorized Browser Shares');
must(actionsApi.includes('vp3_browser_share_save_knowledge_v2020'), 'Knowledge action must reuse Phase 3 canonical helper');
must(actionsApi.includes('vp3_browser_share_create_task_v2020'), 'Task action must reuse canonical Agent Workflow helper');
must(actionsApi.includes('vp3_extension_absolute_url_v2000'), 'Agent handoff must return an absolute VP3 URL');
must(disconnectApi.includes('vp3_extension_device_revoke_v2000'), 'disconnect must revoke the server device and its sessions');

must(handoff.includes('current_user()'), 'Agent handoff must require a live VP3 web user');
must(handoff.includes('vp3_browser_share_resolve_v2020'), 'Agent handoff must re-resolve Browser Share authorization');
must(handoff.includes("$_SESSION['vp3_browser_share_agent_context_v2020']"), 'Agent handoff must bind only the authorized public ID into the web session');
must(!handoff.includes('selected_text'), 'Agent handoff must not copy share content into the web session');

// Existing security authorities must remain the sources of truth.
must(security.includes('vp3_extension_live_capabilities_v2001'), 'live extension permission intersection missing');
must(sessionApi.includes('vp3_extension_session_issue_v2001'), 'session endpoint must retain hardened issue path');
must(destinationsApi.includes('vp3_extension_session_authenticate_v2001'), 'destination endpoint must retain hardened auth');
must(shareApi.includes('vp3_browser_share_create_v2011'), 'share endpoint must retain hardened Browser Share creation');
must(phase3.includes('vp3_browser_share_save_knowledge_v2020'), 'Phase 3 Knowledge helper missing');
must(phase3.includes('vp3_browser_share_create_task_v2020'), 'Phase 3 Task helper missing');

console.log('VP3 Browser Companion MV3 v20.30 contract passed.');
