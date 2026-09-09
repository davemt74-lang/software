import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const mainSidebar = readFileSync('includes/main-sidebar.php', 'utf8');
const legacyWrapper = readFileSync('includes/workspace-sidebar-v82.php', 'utf8');
const memberHeader = readFileSync('includes/member-header.php', 'utf8');
const memberMenu = readFileSync('includes/member-user-menu.php', 'utf8');
const memberNavigation = readFileSync('includes/member-navigation.php', 'utf8');
const contacts = readFileSync('contacts.php', 'utf8');
const knowledge = readFileSync('knowledge.php', 'utf8');
const memory = readFileSync('memory.php', 'utf8');

assert.match(legacyWrapper, /require __DIR__ \. '\/main-sidebar\.php';/, 'legacy workspace sidebar must route to one canonical member sidebar');
assert.doesNotMatch(legacyWrapper, /Stem Studio|Video Editor|workspace-main-sidebar/, 'legacy wrapper must not own duplicate navigation markup');

const primaryStart = mainSidebar.indexOf('data-agent-primary-nav');
const primaryEnd = primaryStart < 0 ? -1 : mainSidebar.indexOf('</nav>', primaryStart);
assert.ok(primaryStart >= 0 && primaryEnd > primaryStart, 'canonical Agent sidebar must expose one primary navigation block');
const primaryNav = mainSidebar.slice(primaryStart, primaryEnd);
for (const label of ['New Chat', 'Approvals', 'Knowledge', 'Memory', 'Contacts']) {
  assert.ok(primaryNav.includes(`<strong>${label}</strong>`), `canonical Agent sidebar must include primary ${label}`);
}
for (const secondary of ['Profile Agent', 'My Transcriptions', 'My Team', 'Plan &amp; Usage', 'Buy AI Tokens']) {
  assert.ok(!primaryNav.includes(`<strong>${secondary}</strong>`), `secondary ${secondary} must not compete with Agent tools in primary navigation`);
}
for (const removed of ['Player', 'Saved Songs', 'My Playlists', 'Stem Studio', 'Video Editor']) {
  assert.ok(!primaryNav.includes(`<strong>${removed}</strong>`), `canonical Agent navigation must not include ${removed}`);
}
assert.match(mainSidebar, /data-agent-user-footer/, 'secondary account and product navigation must live in the bottom user menu');
assert.match(mainSidebar, /member_navigation_menu_links\(\$mainSidebarUser\)/, 'bottom user menu must reuse canonical member navigation');
assert.match(mainSidebar, /personal_knowledge\.access/, 'Knowledge must remain permission-aware');
assert.match(mainSidebar, /mainSidebarActive === 'knowledge'/, 'Knowledge must support the active-page state');
assert.match(mainSidebar, /mainSidebarActive === 'memory'/, 'Memory must support the active-page state');
assert.match(mainSidebar, /id="vp3AgentRuntimeStrip"/, 'sidebar must promote Agent runtime state');
assert.match(mainSidebar, /data-rename-conversation/, 'chat history must expose rename alongside delete');

for (const label of ['Profile Agent', 'My Transcriptions', 'Plan & Usage']) {
  assert.ok(memberNavigation.includes(`'${label}'`), `canonical member user menu must retain ${label}`);
}

assert.match(contacts, /workspaceSidebarActive = 'contacts'/, 'Contacts must identify its active canonical sidebar item');
assert.match(contacts, /includes\/workspace-sidebar-v82\.php/, 'Contacts must use the canonical sidebar wrapper');
assert.match(contacts, /includes\/member-header\.php/, 'Contacts must use the shared member header');
assert.match(contacts, /member-shell-v77\.js/, 'Contacts must load the shared member menu controller');
assert.doesNotMatch(contacts, /<span class="contacts-avatar"/, 'Contacts must not render the old oversized inert avatar');
assert.doesNotMatch(contacts, /contacts-hero|Personal relationship CRM|A living list of people and guest browsers|Visitor activity|Open Agent Chat/, 'Contacts must start with working CRM content instead of the removed duplicate hero');
assert.match(contacts, /<section class="contacts-metrics"/, 'Contacts metrics must be the first page content after the shared header');

assert.match(knowledge, /workspaceSidebarActive='knowledge'/, 'Knowledge must identify its active canonical sidebar item');
assert.match(knowledge, /includes\/workspace-sidebar-v82\.php/, 'Knowledge must use the canonical sidebar wrapper');
assert.match(knowledge, /includes\/member-header\.php/, 'Knowledge must use the shared member header');

assert.match(memory, /workspaceSidebarActive='memory'/, 'Memory must identify its active canonical sidebar item');
assert.match(memory, /includes\/workspace-sidebar-v82\.php/, 'Memory must use the canonical sidebar wrapper');
assert.match(memory, /SET is_active=0 WHERE id=\? AND user_id=\?/, 'Memory Forget must remain owner scoped');

assert.match(memberHeader, /member-user-menu\.php/, 'shared member header must own the shared top user dropdown');
assert.match(memberMenu, /id="chatProfileButton"/, 'shared member menu must expose the canonical profile trigger');
assert.match(memberMenu, /id="chatProfileDropdown"/, 'shared member menu must expose the canonical dropdown');
assert.match(memberMenu, /member_navigation_menu_links\(\$memberMenuUser\)/, 'shared member menu must use canonical member navigation links');

console.log('member-sidebar-shell-contract: ok');
