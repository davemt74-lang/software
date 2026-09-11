import fs from 'node:fs';
import assert from 'node:assert/strict';

const header = fs.readFileSync('includes/member-header.php', 'utf8');
const headerUi = fs.readFileSync('chat-header-ui.css', 'utf8');
const scrollUi = fs.readFileSync('member-page-scroll.css', 'utf8');
const agentUi = fs.readFileSync('agent-ui-v034.css', 'utf8');
const menu = fs.readFileSync('includes/member-user-menu.php', 'utf8');
const contacts = fs.readFileSync('contacts.php', 'utf8');
const knowledge = fs.readFileSync('knowledge.php', 'utf8');
const profile = fs.readFileSync('profile.php', 'utf8');
const plugins = fs.readFileSync('plugins.php', 'utf8');
const team = fs.readFileSync('team.php', 'utf8');
const musicWorkspace = fs.readFileSync('music-workspace.php', 'utf8');
const musicLibrary = fs.readFileSync('music-library.php', 'utf8');
const musicReleases = fs.readFileSync('music-releases.php', 'utf8');
const messages = fs.readFileSync('messages.php', 'utf8');
const notifications = fs.readFileSync('notifications.php', 'utf8');

assert.match(header, /data-member-header/, 'canonical member header must expose one shared header marker');
assert.match(header, /chatNotificationMenu/, 'canonical member header must own notifications');
assert.match(header, /member-user-menu\.php/, 'canonical member header must own the shared user menu');
assert.match(header, /memberHeaderShowSidebarToggle/, 'canonical member header must support pages without a sidebar toggle');
assert.match(header, /\$memberHeaderUiBuild\s*=\s*'universal-member-header-layout-20260906'/, 'canonical member header must cache-bust the portable layout build');
assert.match(header, /chat-header-ui\.css\?v='\s*\.\s*\$memberHeaderUiBuild/, 'canonical member header must load its own layout stylesheet');
assert.match(header, /member-page-scroll\.css\?v='\s*\.\s*\$memberHeaderScrollBuild/, 'canonical member header must load member page scroll ownership fixes');
assert.match(headerUi, /\.member-header\s*\{[\s\S]*?height:58px;[\s\S]*?display:flex;[\s\S]*?align-items:center;/, 'shared member header CSS must own its structural height and flex layout');
assert.match(headerUi, /\.member-header \.chat-topbar-actions\s*\{[\s\S]*?margin-left:auto;[\s\S]*?display:flex;[\s\S]*?align-items:center;/, 'shared member header CSS must keep actions right-aligned without depending on chat.css');
assert.match(headerUi, /\.member-header \.chat-notification-link\s*\{[\s\S]*?width:34px;[\s\S]*?display:grid;[\s\S]*?border-radius:50%/, 'shared member header CSS must own notification bell sizing and layout');
assert.match(headerUi, /\.chat-top-dropdown\s*\{[\s\S]*?position:absolute;[\s\S]*?top:calc\(100% \+ 10px\);[\s\S]*?right:0;/, 'shared member header CSS must own desktop dropdown positioning');
assert.match(headerUi, /\.chat-profile-summary\s*\{[\s\S]*?display:grid;[\s\S]*?grid-template-columns:42px minmax\(0,1fr\) auto!important;/, 'shared member header CSS must own profile summary layout');
assert.match(menu, /user_avatar_url\(\$memberMenuUser\)/, 'shared user menu must render the profile image');
assert.match(menu, /id="chatProfileButton"/, 'shared user menu must expose the profile dropdown button');

assert.match(scrollUi, /\.plugins-wrap,[\s\S]*\.team-canvas,[\s\S]*\.music-wrap,[\s\S]*\.ml-wrap,[\s\S]*\.mr-wrap[\s\S]*overflow-y:auto/, 'long fixed-shell member pages must own vertical scrolling');
assert.match(scrollUi, /\.messages-shell[\s\S]*height:100%[\s\S]*overflow:hidden/, 'desktop Messages must stay constrained to the member content row');
assert.match(scrollUi, /@media\(max-width:800px\)[\s\S]*\.messages-shell[\s\S]*overflow-y:auto/, 'mobile Messages must expose a scrollable stacked shell');

assert.match(agentUi, /\.workspace-main-sidebar \.chat-sidebar-sections\{order:1;/, 'main sidebar content must stay above runtime telemetry');
assert.match(agentUi, /\.workspace-main-sidebar \.agent-runtime-strip\{order:2;/, 'runtime telemetry must live at the bottom of the sidebar');
assert.match(agentUi, /\.workspace-main-sidebar \.agent-sidebar-footer\{order:3;/, 'account footer must remain below runtime telemetry');
assert.match(agentUi, /body:has\(\.chat-composer\) \.chat-settings-launcher\{[\s\S]*z-index:12050!important;[\s\S]*right:max\(72px,calc\(\(100vw - 1062px\)\/2 \+ 60px\)\)!important;/, 'Agent Chat settings control must sit above and inside the composer rail');
assert.match(agentUi, /body:has\(\.chat-composer\) \.chat-settings-button\{[\s\S]*width:30px!important;[\s\S]*height:30px!important;/, 'Agent Chat settings control must use the compact rail size');

for (const [name, source, contentClass] of [
  ['Plugins', plugins, 'plugins-wrap'],
  ['My Team', team, 'team-canvas'],
  ['Music Workspace', musicWorkspace, 'music-wrap'],
  ['Music Library', musicLibrary, 'ml-wrap'],
  ['Release Workspace', musicReleases, 'mr-wrap'],
  ['Messages', messages, 'messages-shell'],
]) {
  assert.match(source, /includes\/member-header\.php/, `${name} must use the canonical member header`);
  assert.match(source, new RegExp(`class="${contentClass}`), `${name} must expose the canonical scroll target`);
}

for (const [name, source] of [['Contacts', contacts], ['My Knowledge', knowledge]]) {
  assert.match(source, /includes\/member-header\.php/, `${name} must use the canonical member header`);
  assert.doesNotMatch(source, /<header class="contacts-topbar"|<header class="chat-topbar personal-knowledge-topbar"/, `${name} must not recreate its own top header`);
}

assert.match(notifications, /includes\/workspace-sidebar-v82\.php/, 'Notifications must use the canonical member sidebar');
assert.match(notifications, /includes\/member-header\.php/, 'Notifications must use the canonical member header');
assert.match(notifications, /class="notification-member-canvas"/, 'Notifications must expose its fixed-shell scroll owner');
assert.match(notifications, /workspace-shell-v82\.js/, 'Notifications must use the canonical workspace shell runtime');
assert.doesNotMatch(notifications, /includes\/header\.php|includes\/footer\.php/, 'Notifications must not fall back to the public-site header/footer shell');

assert.match(profile, /\$memberHeaderUser=\$viewer/, 'logged-in public profile viewers must use the authenticated member header');
assert.match(profile, /includes\/member-header\.php/, 'public profile must reuse the canonical member header for authenticated viewers');
assert.match(profile, /\$memberHeaderShowSidebarToggle=false/, 'public profile must not render a dead mobile sidebar control');
assert.match(profile, /member-shell-v77\.js/, 'public profile must load the shared header interaction runtime for authenticated viewers');
assert.doesNotMatch(profile, /\?\>\s*<header class="profile-topbar">[\s\S]*Agent Chat[\s\S]*Profile Agent/, 'public profile must not retain the legacy authenticated Agent Chat/Profile Agent topbar');
assert.match(profile, /profile-guest-topbar/, 'signed-out public profiles must retain a lightweight guest header with sign-in access');

console.log('member-header-contract: ok');