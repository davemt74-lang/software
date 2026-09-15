import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const mainSidebar = readFileSync('includes/main-sidebar.php', 'utf8');
const legacyWrapper = readFileSync('includes/workspace-sidebar-v82.php', 'utf8');
const memberHeader = readFileSync('includes/member-header.php', 'utf8');
const memberMenu = readFileSync('includes/member-user-menu.php', 'utf8');
const memberNavigation = readFileSync('includes/member-navigation.php', 'utf8');
const agentUiCss = readFileSync('agent-ui-v034.css', 'utf8');
const contacts = readFileSync('contacts.php', 'utf8');
const knowledge = readFileSync('knowledge.php', 'utf8');
const memory = readFileSync('memory.php', 'utf8');

assert.match(legacyWrapper, /require __DIR__ \. '\/main-sidebar\.php';/, 'legacy workspace sidebar must route to one canonical member sidebar');
assert.doesNotMatch(legacyWrapper, /Stem Studio|Video Editor|workspace-main-sidebar/, 'legacy wrapper must not own duplicate navigation markup');

// Primary navigation is rendered from canonical keyed destinations. Keep the source
// contract aligned with the consolidated shell rather than expecting duplicated anchors.
assert.match(mainSidebar, /\$mainSidebarPrimaryOrder = \['home','chat','profile_agent','messages','contacts','knowledge','transcriptions','calendar','scheduling','profile_commerce','team'\]/, 'canonical Agent sidebar must retain the primary destination order');
for (const [key, label] of [
  ['home', 'Home'],
  ['chat', 'Agent Chat'],
  ['profile_agent', 'Profile Agent'],
  ['messages', 'Messages'],
  ['contacts', 'Contacts'],
  ['knowledge', 'Knowledge'],
  ['transcriptions', 'Transcriptions'],
  ['calendar', 'Calendar'],
]) {
  assert.ok(mainSidebar.includes(`'${key}'=>'${label}'`), `canonical Agent sidebar must retain ${label}`);
}
assert.match(mainSidebar, /foreach \(\$mainSidebarPrimaryOrder as \$key\)/, 'canonical sidebar must derive primary links by keyed order');
assert.match(mainSidebar, /data-agent-primary-nav/, 'canonical Agent sidebar must expose one primary navigation block');
assert.match(mainSidebar, /data-vp3-nav-key=/, 'primary navigation rows must expose canonical destination keys');
assert.match(mainSidebar, /aria-current="page"/, 'active primary destination must expose aria-current');
assert.ok(!mainSidebar.includes("'approvals'=>"), 'canonical Agent navigation must not restore the removed Approvals shortcut');

assert.match(mainSidebar, /class="chat-history-heading"/, 'Chats section must expose a dedicated heading row');
assert.match(mainSidebar, /class="chat-history-new" id="newChatButton"[^>]*aria-label="New chat"[^>]*>\+<\/button>/, 'Agent Chat must move the canonical New Chat action into a compact Chats heading plus button');
assert.match(mainSidebar, /<a class="chat-history-new" href="<\?= e\(url\('\/chat\.php'\)\) \?>" aria-label="New chat" title="New chat">\+<\/a>/, 'non-Chat member surfaces must expose the Chats heading plus as the canonical Chat link');
assert.match(agentUiCss, /\.chat-history-heading\{[^}]*justify-content:space-between/, 'Chats heading must place the plus control at the far right');
assert.match(agentUiCss, /\.chat-history-new\{[^}]*width:24px;[^}]*height:24px;/, 'Chats heading plus must stay compact');

assert.match(mainSidebar, /data-agent-user-footer/, 'secondary account and product navigation must live in the bottom user menu');
assert.match(mainSidebar, /member_navigation_menu_links\(\$mainSidebarUser\)/, 'sidebar and bottom user menu must reuse canonical member navigation');
assert.match(mainSidebar, /\$mainSidebarPrimaryKeys = array_fill_keys\(\$mainSidebarPrimaryOrder, true\)/, 'promoted destinations must be represented by the canonical primary key set');
assert.match(mainSidebar, /!isset\(\$mainSidebarPrimaryKeys\[\(string\)\(\$link\['key'\] \?\? ''\)\]\)/, 'promoted destinations must be filtered out of the bottom menu');
assert.doesNotMatch(mainSidebar, /\$mainSidebarCalendarActive|\$mainSidebarProductsActive|\$mainSidebarTranscriptionsActive/, 'page-specific active-state booleans must not return');
assert.doesNotMatch(mainSidebar, /class="agent-sidebar-avatar"/, 'bottom user section must not render the user picture/avatar');
assert.match(mainSidebar, /class="agent-sidebar-user-copy"><strong>/, 'bottom user section must retain the user name');
const footerStart = mainSidebar.indexOf('<footer class="agent-sidebar-footer"');
const footerEnd = footerStart < 0 ? -1 : mainSidebar.indexOf('</footer>', footerStart);
assert.ok(footerStart >= 0 && footerEnd > footerStart, 'canonical sidebar must expose the compact user footer');
const footer = mainSidebar.slice(footerStart, footerEnd);
assert.match(footer, /id="vp3AgentRuntimeStrip"/, 'runtime stats must be integrated into the bottom user footer');
assert.match(footer, /id="vp3AgentRuntimeSource"/, 'combined footer must retain runtime source status');
assert.match(footer, /id="vp3AgentRuntimeModel"/, 'combined footer must retain Brain/model status');
assert.match(footer, /id="vp3AgentRuntimeUsage"/, 'combined footer must retain monthly usage status');
assert.match(agentUiCss, /\.agent-sidebar-footer \.agent-sidebar-runtime\{[^}]*background:transparent;[^}]*box-shadow:none;/, 'integrated runtime stats must visually belong to the footer instead of a second card');
assert.match(agentUiCss, /\.agent-sidebar-user-button\{[^}]*grid-template-columns:minmax\(0,1fr\) auto;/, 'footer identity layout must no longer reserve an avatar column');
assert.match(mainSidebar, /data-rename-conversation/, 'chat history must expose rename alongside delete');

for (const label of ['Profile Agent', 'My Knowledge', 'My Memory', 'My Transcriptions', 'Plan & Usage']) {
  assert.ok(memberNavigation.includes(`'${label}'`), `canonical member navigation must retain ${label}`);
}
assert.ok(memberNavigation.includes("'messages','Messages'"), 'canonical member navigation must retain Messages');
assert.ok(memberNavigation.includes("'calendar','My Calendar'"), 'canonical member navigation must retain My Calendar');
assert.ok(memberNavigation.includes("'transcriptions','My Transcriptions',url('/artist-listening.php')"), 'canonical member navigation must route Transcriptions to Artist Listening');
assert.ok(memberNavigation.includes("personal_capability_has_v242('personal_knowledge.access'"), 'My Knowledge must remain permission-aware in canonical navigation');
assert.ok(memberNavigation.includes("has_permission('chat.access'"), 'My Memory must remain permission-aware in canonical navigation');

assert.match(contacts, /workspaceSidebarActive = 'contacts'/, 'Contacts must identify its active canonical sidebar item');
assert.match(contacts, /includes\/workspace-sidebar-v82\.php/, 'Contacts must use the canonical sidebar wrapper');
assert.match(contacts, /includes\/member-header\.php/, 'Contacts must use the shared member header');
assert.match(contacts, /member-shell-v77\.js/, 'Contacts must load the shared member menu controller');
assert.doesNotMatch(contacts, /<span class="contacts-avatar"/, 'Contacts must not render the old oversized inert avatar');
assert.doesNotMatch(contacts, /contacts-hero|Personal relationship CRM|A living list of people and guest browsers|Visitor activity|Open Agent Chat/, 'Contacts must start with working CRM content instead of the removed duplicate hero');
assert.match(contacts, /<section class="contacts-metrics"/, 'Contacts metrics must be the first page content after the shared header');

assert.match(knowledge, /workspaceSidebarActive='knowledge'/, 'Knowledge must identify its active canonical route');
assert.match(knowledge, /includes\/workspace-sidebar-v82\.php/, 'Knowledge must use the canonical sidebar wrapper');
assert.match(knowledge, /includes\/member-header\.php/, 'Knowledge must use the shared member header');

assert.match(memory, /workspaceSidebarActive='memory'/, 'Memory must identify its active canonical route');
assert.match(memory, /includes\/workspace-sidebar-v82\.php/, 'Memory must use the canonical sidebar wrapper');
assert.match(memory, /SET is_active=0 WHERE id=\? AND user_id=\?/, 'Memory Forget must remain owner scoped');

assert.match(memberHeader, /member-user-menu\.php/, 'shared member header must own the shared top user dropdown');
assert.match(memberHeader, /member-shell-v77\.js/, 'shared member header must load its menu controller');
assert.match(memberMenu, /id="chatProfileButton"/, 'shared member menu must expose the canonical profile trigger');
assert.match(memberMenu, /id="chatProfileDropdown"/, 'shared member menu must expose the canonical dropdown');
assert.match(memberMenu, /data-vp3-agent-voice-dashboard/, 'upper-right member menu must be the focused Agent + Voice dashboard');
assert.doesNotMatch(memberMenu, /member_navigation_menu_links\(\$memberMenuUser\)/, 'upper-right member menu must not duplicate the general navigation list');
assert.match(memberMenu, /data-vp3-agent-name/, 'Agent + Voice dashboard must expose the Agent name field');
assert.match(memberMenu, /My ElevenLabs voice clone/, 'Agent + Voice dashboard must expose the existing ElevenLabs clone setting');

console.log('member-sidebar-shell-contract: ok');
