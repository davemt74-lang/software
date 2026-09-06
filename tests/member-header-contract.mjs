import fs from 'node:fs';
import assert from 'node:assert/strict';

const header = fs.readFileSync('includes/member-header.php', 'utf8');
const menu = fs.readFileSync('includes/member-user-menu.php', 'utf8');
const contacts = fs.readFileSync('contacts.php', 'utf8');
const knowledge = fs.readFileSync('knowledge.php', 'utf8');
const profile = fs.readFileSync('profile.php', 'utf8');

assert.match(header, /data-member-header/, 'canonical member header must expose one shared header marker');
assert.match(header, /chatNotificationMenu/, 'canonical member header must own notifications');
assert.match(header, /member-user-menu\.php/, 'canonical member header must own the shared user menu');
assert.match(header, /memberHeaderShowSidebarToggle/, 'canonical member header must support pages without a sidebar toggle');
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