import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const must = (value, message) => assert.equal(Boolean(value), true, message);

const manifest = JSON.parse(read('browser-companion/manifest.json'));
const background = read('browser-companion/background.js');
const html = read('browser-companion/sidepanel.html');
const js = read('browser-companion/sidepanel.js');
const css = read('browser-companion/sidepanel.css');
const me = read('api/extension-me.php');

must(manifest.version === '21.1.0', 'v21.10 manifest version missing');
must(background.includes("const VP3_EXTENSION_VERSION = '21.1.0';"), 'v21.10 request version missing');

for (const id of ['connectedAccount','accountAvatar','accountName','accountMeta','accountTeams','openVp3Btn','refreshAccountBtn','accountOptionsBtn','accessNotice']) {
  must(html.includes(`id="${id}"`), `account shell element ${id} missing`);
}
must(html.includes('id="composerCard"'), 'capability-aware composer shell missing');

must(js.includes("function renderAccountTeams()"), 'Team access summary renderer missing');
must(js.includes("function hasReadAccess()"), 'live Browser Companion access guard missing');
must(js.includes("ui.accountAvatar.textContent=initials(name)"), 'live account identity rendering missing');
must(js.includes("ui.accountMeta.textContent=roleLabel(x.user&&x.user.role)"), 'live role rendering missing');
must(js.includes("ui.shareWorkspace.hidden=!readable"), 'protected workspace access gate missing');
must(js.includes("ui.composerCard.hidden=!(state&&state.connected&&canShare)"), 'share composer must follow live capability');
must(js.includes("tab.disabled=!(state&&state.connected&&canRead)"), 'read tabs must follow live capability');
must(js.includes("ui.refreshAccountBtn.onclick"), 'manual live account refresh missing');
must(js.includes("window.addEventListener('focus'"), 'focus-time account refresh missing');
must(js.includes("ui.openVp3Btn.onclick"), 'direct VP3 navigation missing');
must(js.includes("ui.accountOptionsBtn.onclick"), 'extension settings navigation missing');
must(js.includes("renderAccountTeams();renderCaps();"), 'Team summary must refresh with canonical destinations');

must(css.includes('.account-avatar'), 'account shell styling missing');
must(css.includes('.access-notice'), 'permission-loss notice styling missing');

must(me.includes("'display_name'=>(string)$user['display_name']"), 'extension-me display name source missing');
must(me.includes("'role'=>(string)($user['role']??'')"), 'extension-me role source missing');
must(me.includes("'capabilities'=>array_values($session['capabilities']??[])"), 'extension-me live capabilities source missing');

for (const forbidden of ['connected_user','approved_capabilities','account_profile','account_cache','chrome.storage.sync']) {
  if (forbidden === 'connected_user' || forbidden === 'approved_capabilities') {
    must(!js.includes(forbidden), `side panel must not restore legacy persisted ${forbidden}`);
  } else {
    must(!js.includes(forbidden), `side panel must not introduce ${forbidden}`);
  }
}

must(!background.includes("await storage.set({ connected_user:"), 'v21.10 must not persist a Chrome account copy');
must(!background.includes("await storage.set({ approved_capabilities:"), 'v21.10 must not persist Chrome capability copies');
must(!background.includes("'/api/extension-connect-status.php'"), 'v21.10 must not restore connection polling');
must(!background.includes("'/api/extension-session.php'"), 'v21.10 must not restore renewable extension sessions');
must(background.includes("return authorizedFetch('/api/extension-me.php'"), 'VP3 must remain live account authority');

console.log('VP3 Browser Companion Account-Aware Shell v21.10 contract passed.');
