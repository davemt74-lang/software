import fs from 'node:fs';
import assert from 'node:assert/strict';

const navigation = fs.readFileSync('includes/member-navigation.php', 'utf8');
const sidebar = fs.readFileSync('includes/main-sidebar.php', 'utf8');
const workspaceWrapper = fs.readFileSync('includes/workspace-sidebar-v82.php', 'utf8');
const header = fs.readFileSync('includes/member-header.php', 'utf8');
const shell = fs.readFileSync('member-shell-v77.js', 'utf8');
const shellCss = fs.readFileSync('agent-ui-v034.css', 'utf8');
const knowledge = fs.readFileSync('knowledge.php', 'utf8');
const calendar = fs.readFileSync('calendar.php', 'utf8');
const account = fs.readFileSync('account.php', 'utf8');

assert.match(navigation, /function member_navigation_active_key\(/, 'navigation must own canonical active-key resolution');
for (const [script, key] of [
  ['chat.php', 'chat'],
  ['knowledge.php', 'knowledge'],
  ['calendar.php', 'calendar'],
  ['scheduling.php', 'scheduling'],
  ['profile-commerce-products.php', 'profile_commerce'],
  ['team.php', 'team'],
  ['account.php', 'account'],
]) {
  assert.ok(navigation.includes(`'${script}'=>'${key}'`), `${script} must resolve to ${key}`);
}
assert.match(navigation, /function member_navigation_section_label\(/, 'navigation must expose shared shell sections');
assert.match(navigation, /function member_navigation_group_label\(/, 'navigation must expose user-menu group labels');

assert.match(workspaceWrapper, /require __DIR__ \. '\/main-sidebar\.php'/, 'legacy workspace wrapper must continue delegating to the canonical sidebar');
assert.match(sidebar, /member_navigation_active_key\(\)/, 'sidebar must fall back to canonical active-key resolution');
assert.match(sidebar, /\['chat','profile_agent','messages','contacts','knowledge','transcriptions','calendar','scheduling','profile_commerce','team'\]/, 'primary shell must cover core Agent, workspace, planning, commerce and team destinations');
assert.match(sidebar, /data-vp3-nav-key=/, 'sidebar links must expose canonical navigation keys');
assert.match(sidebar, /aria-current=\\"page\\"/, 'active sidebar links must expose aria-current');
assert.match(sidebar, /agent-nav-group-label/, 'primary navigation must render product-area grouping');
assert.match(sidebar, /agent-menu-group-label/, 'secondary user menu must render group labels');
assert.match(sidebar, /data-shell-active=/, 'sidebar must expose active shell state to the runtime');
assert.match(sidebar, /authenticated-shell-phase2-20260914/, 'sidebar must bust the consolidated shell CSS cache');
assert.doesNotMatch(sidebar, /\$mainSidebarCalendarActive|\$mainSidebarProductsActive|\$mainSidebarTranscriptionsActive/, 'sidebar must not keep page-specific active-state booleans');

assert.match(header, /member_navigation_active_key\(\)/, 'member header must share canonical active-key resolution');
assert.match(header, /member_navigation_section_label\(\$memberHeaderActiveKey\)/, 'member header must derive the same product section');
assert.match(header, /member-header-context">VP3 ·/, 'member header must show consistent VP3 section context');
assert.match(header, /aria-controls="chatSidebar"/, 'mobile header toggle must identify the canonical sidebar');
assert.match(header, /VP3_MEMBER_HEADER_RUNTIME_RENDERED/, 'member header must use a canonical VP3 runtime render guard');
assert.match(header, /STONEFELLOW_MEMBER_HEADER_RUNTIME_RENDERED/, 'member header must retain the legacy runtime guard for compatibility');

assert.match(shell, /document\.body\.dataset\.vp3ShellActive/, 'shell runtime must publish the active product key');
assert.match(shell, /document\.body\.dataset\.vp3ShellSection/, 'shell runtime must publish the active product section');
assert.match(shell, /closeSidebar\?\.focus\(\)/, 'opening mobile navigation must move focus into the drawer');
assert.match(shell, /closeNav\(navWasOpen\)/, 'Escape must close mobile navigation and restore focus');
assert.match(shell, /matchMedia\('\(max-width: 760px\)'\)/, 'mobile navigation must close after selecting a destination');
assert.match(shell, /window\.VP3_PROFILE_AGENT \|\| window\.STONEFELLOW_PROFILE_AGENT/, 'Profile Agent compatibility bridge must remain intact');

assert.match(shellCss, /\.agent-nav-group-label/, 'shell CSS must style primary navigation groups');
assert.match(shellCss, /\[aria-current="page"\]/, 'shell CSS must visibly distinguish the current destination');
assert.match(shellCss, /\.member-header-context/, 'shell CSS must style shared VP3 page context');

for (const [name, source] of [['Knowledge', knowledge], ['Calendar', calendar], ['Account', account]]) {
  assert.match(source, /includes\/workspace-sidebar-v82\.php/, `${name} must continue consuming the canonical sidebar wrapper`);
}
assert.match(knowledge, /includes\/member-header\.php/, 'Knowledge must consume the shared member header');
assert.match(calendar, /includes\/member-header\.php/, 'Calendar must consume the shared member header');

console.log('Authenticated shell consolidation contract passed.');
