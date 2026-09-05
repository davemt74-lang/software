import fs from 'node:fs';
import assert from 'node:assert/strict';

const header = fs.readFileSync('includes/member-header.php', 'utf8');
const menu = fs.readFileSync('includes/member-user-menu.php', 'utf8');
const contacts = fs.readFileSync('contacts.php', 'utf8');
const knowledge = fs.readFileSync('knowledge.php', 'utf8');

assert.match(header, /data-member-header/, 'canonical member header must expose one shared header marker');
assert.match(header, /chatNotificationMenu/, 'canonical member header must own notifications');
assert.match(header, /member-user-menu\.php/, 'canonical member header must own the shared user menu');
assert.match(menu, /user_avatar_url\(\$memberMenuUser\)/, 'shared user menu must render the profile image');
assert.match(menu, /id="chatProfileButton"/, 'shared user menu must expose the profile dropdown button');

for (const [name, source] of [['Contacts', contacts], ['My Knowledge', knowledge]]) {
  assert.match(source, /includes\/member-header\.php/, `${name} must use the canonical member header`);
  assert.doesNotMatch(source, /<header class="contacts-topbar"|<header class="chat-topbar personal-knowledge-topbar"/, `${name} must not recreate its own top header`);
}

console.log('member-header-contract: ok');
