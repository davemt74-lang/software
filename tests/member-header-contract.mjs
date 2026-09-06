import fs from 'node:fs';
import assert from 'node:assert/strict';

const header = fs.readFileSync('includes/member-header.php', 'utf8');
const headerUi = fs.readFileSync('chat-header-ui.css', 'utf8');
const menu = fs.readFileSync('includes/member-user-menu.php', 'utf8');
const contacts = fs.readFileSync('contacts.php', 'utf8');
const knowledge = fs.readFileSync('knowledge.php', 'utf8');
const profile = fs.readFileSync('profile.php', 'utf8');

assert.match(header, /data-member-header/, 'canonical member header must expose one shared header marker');
assert.match(header, /chatNotificationMenu/, 'canonical member header must own notifications');
assert.match(header, /member-user-menu\.php/, 'canonical member header must own the shared user menu');
assert.match(header, /memberHeaderShowSidebarToggle/, 'canonical member header must support pages without a sidebar toggle');
assert.match(header, /\$memberHeaderUiBuild\s*=\s*'universal-member-header-layout-20260906'/, 'canonical member header must cache-bust the portable layout build');
assert.match(header, /chat-header-ui\.css\?v='\s*\.\s*\$memberHeaderUiBuild/, 'canonical member header must load its own layout stylesheet');
assert.match(headerUi, /\.member-header\s*\{[\s\S]*?height:58px;[\s\S]*?display:flex;[\s\S]*?align-items:center;/, 'shared member header CSS must own its structural height and flex layout');
assert.match(headerUi, /\.member-header \.chat-topbar-actions\s*\{[\s\S]*?margin-left:auto;[\s\S]*?display:flex;[\s\S]*?align-items:center;/, 'shared member header CSS must keep actions right-aligned without depending on chat.css');
assert.match(headerUi, /\.member-header \.chat-notification-link\s*\{[\s\S]*?width:34px;[\s\S]*?display:grid;[\s\S]*?border-radius:50%/, 'shared member header CSS must own notification bell sizing and layout');
assert.match(headerUi, /\.chat-top-dropdown\s*\{[\s\S]*?position:absolute;[\s\S]*?top:calc\(100% \+ 10px\);[\s\S]*?right:0;/, 'shared member header CSS must own desktop dropdown positioning');
assert.match(headerUi, /\.chat-profile-summary\s*\{[\s\S]*?display:grid;[\s\S]*?grid-template-columns:42px minmax\(0,1fr\) auto!important;/, 'shared member header CSS must own profile summary layout');
assert.match(menu, /user_avatar_url\(\$memberMenuUser\)/, 'shared user menu must render the profile image');
assert.match(menu, /id="chatProfileButton"/, 'shared user menu must expose the profile dropdown button');

for (const [name, source] of [['Contacts', contacts], ['My Knowledge', knowledge]]) {
  assert.match(source, /includes\/member-header\.php/, `${name} must use the canonical member header`);
  assert.doesNotMatch(source, /<header class="contacts-topbar"|<header class="chat-topbar personal-knowledge-topbar"/, `${name} must not recreate its own top header`);
}

assert.match(profile, /\$memberHeaderUser=\$viewer/, 'logged-in public profile viewers must use the authenticated member header');
assert.match(profile, /includes\/member-header\.php/, 'public profile must reuse the canonical member header for authenticated viewers');
assert.match(profile, /\$memberHeaderShowSidebarToggle=false/, 'public profile must not render a dead mobile sidebar control');
assert.match(profile, /member-shell-v77\.js/, 'public profile must load the shared header interaction runtime for authenticated viewers');
assert.doesNotMatch(profile, /\?\>\s*<header class="profile-topbar">[\s\S]*Agent Chat[\s\S]*Profile Agent/, 'public profile must not retain the legacy authenticated Agent Chat/Profile Agent topbar');
assert.match(profile, /profile-guest-topbar/, 'signed-out public profiles must retain a lightweight guest header with sign-in access');

console.log('member-header-contract: ok');