import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const mainSidebar = readFileSync('includes/main-sidebar.php', 'utf8');
const legacyWrapper = readFileSync('includes/workspace-sidebar-v82.php', 'utf8');
const memberHeader = readFileSync('includes/member-header.php', 'utf8');
const memberMenu = readFileSync('includes/member-user-menu.php', 'utf8');
const contacts = readFileSync('contacts.php', 'utf8');
const knowledge = readFileSync('knowledge.php', 'utf8');

assert.match(legacyWrapper, /require __DIR__ \. '\/main-sidebar\.php';/, 'legacy workspace sidebar must route to one canonical member sidebar');
assert.doesNotMatch(legacyWrapper, /Stem Studio|Video Editor|workspace-main-sidebar/, 'legacy wrapper must not own duplicate navigation markup');

for (const label of ['New Chat', 'Profile Agent', 'My Contacts', 'My Knowledge', 'Player', 'My Playlists']) {
  assert.ok(mainSidebar.includes(`<strong>${label}</strong>`), `canonical main sidebar must include ${label}`);
}
assert.doesNotMatch(mainSidebar, /Stem Studio|Video Editor/, 'canonical member sidebar must not expose editor/admin workspaces');
assert.match(mainSidebar, /personal_knowledge\.access/, 'My Knowledge must remain permission-aware');
assert.match(mainSidebar, /mainSidebarActive === 'knowledge'/, 'My Knowledge must support the active-page state');

assert.match(contacts, /workspaceSidebarActive = 'contacts'/, 'Contacts must identify its active canonical sidebar item');
assert.match(contacts, /includes\/workspace-sidebar-v82\.php/, 'Contacts must use the canonical sidebar wrapper');
assert.match(contacts, /includes\/member-header\.php/, 'Contacts must use the shared member header');
assert.match(contacts, /member-shell-v77\.js/, 'Contacts must load the shared member menu controller');
assert.doesNotMatch(contacts, /<span class="contacts-avatar"/, 'Contacts must not render the old oversized inert avatar');

assert.match(knowledge, /workspaceSidebarActive='knowledge'/, 'Knowledge must identify its active canonical sidebar item');
assert.match(knowledge, /includes\/workspace-sidebar-v82\.php/, 'Knowledge must use the canonical sidebar wrapper');
assert.match(knowledge, /includes\/member-header\.php/, 'Knowledge must use the shared member header');

assert.match(memberHeader, /member-user-menu\.php/, 'shared member header must own the shared user dropdown');
assert.match(memberMenu, /id="chatProfileButton"/, 'shared member menu must expose the canonical profile trigger');
assert.match(memberMenu, /id="chatProfileDropdown"/, 'shared member menu must expose the canonical dropdown');
assert.match(memberMenu, /member_navigation_menu_links\(\$memberMenuUser\)/, 'shared member menu must use canonical member navigation links');

console.log('member-sidebar-shell-contract: ok');
