import fs from 'node:fs';
import assert from 'node:assert/strict';
const read = path => fs.readFileSync(path, 'utf8');
const permissions = read('includes/permissions.php');
const permissionExt = read('includes/permissions-v105.php');
const workspace = read('includes/artist-workspace-v181.php');
const chat = read('api/chat.php');
const stream = read('api/chat-stream-v121.php');
const continuity = read('includes/agent-chat-continuity-v101.php');
const favorites = read('api/favorites-v73.php');
const create = read('api/chat-create-v76.php');
const header = read('includes/header.php');
const sidebar = read('includes/workspace-sidebar-v82.php');
const account = read('account.php');
const accountAgentLoader = read('account-agent-settings-loader-v236.js');
const accountShellCss = read('account-shell.css');
const accountAgentCss = read('account-agent-settings-v236.css');
const chatLegacy = read('chat-legacy-v108.php');
const bootstrap = read('includes/bootstrap.php');
const uiPermissions = read('api/ui-permissions-v187.php');
const uiRuntime = read('chat-agent-updates-autodismiss-v173.js');
const upgrade = read('upgrade.php');

assert.doesNotMatch(permissions, /\$permission === 'chat\.access'[\s\S]{0,180}array_key_exists/);
assert.match(chat, /has_permission\('chat\.access', \$user\)/);
assert.match(workspace, /chat_conversations ADD COLUMN artist_workspace_id/);
assert.match(workspace, /playlists ADD COLUMN artist_workspace_id/);
assert.match(workspace, /UPDATE chat_conversations SET artist_workspace_id=\?/);
assert.match(workspace, /UPDATE playlists SET artist_workspace_id=\?/);
for (const source of [chat, stream, continuity]) assert.match(source, /artist_workspace_v181_scope_id/);
assert.match(favorites, /FROM track_favorites[\s\S]{0,80}WHERE user_id=\? AND track_id=\?/);
assert.match(create, /owner_user_id,\s*artist_workspace_id/);
assert.match(create, /artist_workspace_v181_scope_id\(\$user\)/);
assert.match(header, /has_permission\('chat\.access', \$headerUser\)/);
assert.match(header, /has_permission\('account\.access', \$headerUser\)/);
assert.match(sidebar, /has_permission\('chat\.access',\s*\$workspaceSidebarUser\)/);
assert.match(sidebar, /has_permission\('account\.access',\s*\$workspaceSidebarUser\)/);
assert.match(account, /has_permission\('chat\.access', \$user\)/);
assert.match(chatLegacy, /\$chatCanAccessAccount = has_permission\('account\.access', \$user\)/);
assert.match(chatLegacy, /<\?php if \(\$chatCanAccessAccount\): \?>[\s\S]{0,120}<div class="chat-top-menu" id="chatNotificationMenu"/);

// Account Agents & Data must keep both runtime assets mounted. Using one shared
// marker makes the script lookup remove the stylesheet that was just inserted.
assert.match(accountAgentLoader, /data-account-agent-v236-css/);
assert.match(accountAgentLoader, /data-account-agent-v236-js/);
assert.match(accountAgentLoader, /querySelector\(`\$\{kind\}\[\$\{attr\}\]`\)/);
assert.doesNotMatch(accountAgentLoader, /\['link','data-account-agent-v236'/);
assert.doesNotMatch(accountAgentLoader, /\['script','data-account-agent-v236'/);
assert.match(accountAgentLoader, /account-shell\.css\?v=\$\{build\}/);
assert.match(accountAgentLoader, /account-light-shell-20260905/);
assert.match(sidebar, /account-agent-settings-loader-v236\.js\?v=account-light-shell-20260905/);

// One late-loaded Account stylesheet owns the final light-theme cascade and
// cache-busts both the base account layout and the injected Agents & Data UI.
assert.match(accountShellCss, /@import url\('\.\/account\.css\?v=account-light-shell-20260905'\)/);
assert.match(accountShellCss, /@import url\('\.\/account-agent-settings-v236\.css\?v=account-light-shell-20260905'\)/);
assert.match(accountShellCss, /\.workspace-main-sidebar/);
assert.match(accountShellCss, /\.account-panel/);
assert.match(accountShellCss, /\.account-agent-v236/);
assert.match(accountShellCss, /background:#fff!important/);
assert.match(accountShellCss, /color:#111318!important/);
assert.match(accountAgentCss, /\.account-agent-v236/);
assert.match(accountAgentCss, /background:#fff/);
assert.match(accountAgentCss, /color:#111318/);

assert.match(permissionExt, /'playlists\.manage'=>\[/);
assert.match(permissionExt, /'playlists\.manage'=>\['fan','artist','manager','producer','supervisor','investor','admin'\]/);
assert.match(permissionExt, /playlists_manage_permission_seed_v187/);
assert.match(permissionExt, /permission_v105_seed_playlist_permission/);
assert.match(permissionExt, /chat-create-v76\.php/);
assert.match(permissionExt, /player-library-v76\.php/);
for (const action of ['playlist_update','playlist_add_track','playlist_delete','playlist_duplicate']) {
  assert.match(permissionExt, new RegExp(action));
}
assert.match(bootstrap, /permission_v105_enforce_request_gates\(\)/);
assert.match(upgrade, /permission_v105_seed_playlist_permission\(\)/);
assert.match(upgrade, /permission_v105_playlist_permission_ready\(\)/);
assert.match(uiPermissions, /'playlist'=>permission_v105_has\('playlists\.manage',\$user\)/);
assert.match(uiPermissions, /'artist_listening_access'=>has_permission\('artist_listening\.access',\$user\)/);
assert.match(uiRuntime, /api\/ui-permissions-v187\.php/);
assert.match(uiRuntime, /\[data-chat-create-type\]/);
assert.match(uiRuntime, /\[data-chat-create-form\]/);
assert.match(uiRuntime, /data\.playlists_manage !== true/);
assert.match(uiRuntime, /chat-sidebar-recordings-link,#chatRecordingsCanvas/);

const fanDefaults = permissions.match(/'fan'\s*=>\s*\[([\s\S]*?)\],\s*'artist'/)?.[1] || '';
assert.match(fanDefaults, /'chat\.access'/);
assert.doesNotMatch(fanDefaults, /'artist_listening\.access'/);
assert.doesNotMatch(fanDefaults, /'tracks\.manage'|'albums\.manage'|'shows\.manage'|'photos\.manage'|'merch\.manage'|'posts\.manage'/);

console.log('ACCOUNT_SCOPE_V181=PASS');