import fs from 'node:fs';
import assert from 'node:assert/strict';

const template = fs.readFileSync('chat-legacy-v108.php', 'utf8');
const wrapper = fs.readFileSync('chat.php', 'utf8');
const chat = fs.readFileSync('chat.js', 'utf8');
const notifications = fs.readFileSync('chat-notifications-drawer-v240.js', 'utf8');
const learning = fs.readFileSync('chat-brain-learning-history-v317.js', 'utf8');
const memberNav = fs.readFileSync('includes/member-navigation.php', 'utf8');
const profileAgent = fs.readFileSync('profile-agent.php', 'utf8');
const mainSidebar = fs.readFileSync('includes/main-sidebar.php', 'utf8');
const activity = fs.readFileSync('agent-activity-v94.js', 'utf8');
const settingsUi = fs.readFileSync('chat-settings-v237.js', 'utf8');
const settingsCss = fs.readFileSync('chat-settings-v237.css', 'utf8');
const htaccess = fs.readFileSync('.htaccess', 'utf8');

// The shared sidebar is the only visible navigation authority. Chat contributes
// behavior/history data to it rather than owning a second navigation tree.
assert.match(wrapper, /require __DIR__ \. '\/includes\/main-sidebar\.php'/, 'Main Feed must render the canonical shared sidebar');
assert.match(wrapper, /\$mainSidebarUseNewChatButton = true;/, 'Chat must ask the canonical sidebar for the New Chat button behavior');
assert.match(wrapper, /\$mainSidebarHistoryRows = isset\(\$recent\)/, 'Chat must pass recent conversation rows into the canonical sidebar');
assert.match(wrapper, /<aside class=\"chat-sidebar\" id=\"chatSidebar\">\.\*\?<\/aside>/, 'Main Feed must replace the complete legacy sidebar as one unit');
assert.doesNotMatch(wrapper, /data-chat-view-target=\"\(\?:player\|saved\|playlists\)\"|chatMyTeamSidebarLink|data-chat-my-team/, 'Main Feed must not strip/inject individual sidebar items after render');

assert.match(mainSidebar, /aria-label="VP3 Home">VP3<\/a>/, 'Canonical sidebar logo must identify VP3 Home');
assert.match(mainSidebar, /\$mainSidebarPrimaryOrder = \['home','chat','profile_agent','messages','contacts','knowledge','transcriptions','calendar','scheduling','profile_commerce','team'\]/, 'Canonical sidebar must retain the consolidated primary Agent navigation order');
for (const [key, label] of [
  ['home', 'Home'],
  ['chat', 'Agent Chat'],
  ['profile_agent', 'Profile Agent'],
  ['messages', 'Messages'],
  ['contacts', 'Contacts'],
  ['knowledge', 'Knowledge'],
  ['transcriptions', 'Transcriptions'],
  ['calendar', 'Calendar'],
  ['scheduling', 'Scheduling'],
  ['profile_commerce', 'Products'],
  ['team', 'Team'],
]) {
  assert.ok(mainSidebar.includes(`'${key}'=>'${label}'`), `Canonical Agent sidebar must retain ${label}`);
}
assert.match(mainSidebar, /member_navigation_menu_links\(\$mainSidebarUser\)/, 'Canonical sidebar must consume permission-aware member navigation');
assert.match(mainSidebar, /foreach \(\$mainSidebarPrimaryOrder as \$key\)/, 'Canonical sidebar must derive primary links by canonical key order');
assert.match(mainSidebar, /data-agent-primary-nav/, 'Canonical sidebar must expose an explicit primary Agent navigation block');
assert.match(mainSidebar, /data-vp3-nav-key=/, 'Canonical primary navigation must expose keyed destinations');
assert.match(mainSidebar, /aria-current="page"/, 'Canonical primary navigation must expose accessible active state');
assert.doesNotMatch(mainSidebar, /\$mainSidebarCalendarActive|\$mainSidebarProductsActive|\$mainSidebarTranscriptionsActive/, 'Legacy page-specific active-state booleans must not return');
assert.ok(!mainSidebar.includes("'memory'=>'Memory'") && !mainSidebar.includes("'approvals'=>'Approvals'"), 'Memory and Approvals must not become primary Agent destinations');
assert.match(mainSidebar, /\$mainSidebarPrimaryKeys = array_fill_keys\(\$mainSidebarPrimaryOrder, true\)/, 'Primary destinations must be represented by the canonical key set');
assert.match(mainSidebar, /!isset\(\$mainSidebarPrimaryKeys\[\(string\)\(\$link\['key'\] \?\? ''\)\]\)/, 'Primary destinations must be removed from duplicate footer navigation');
assert.match(mainSidebar, /class="chat-history-heading"[\s\S]*id="newChatButton"[\s\S]*data-chat-view-target="chat"/, 'Canonical sidebar must preserve Main Feed New Chat behavior in the Chats heading');
assert.match(mainSidebar, /class="chat-history-new" id="newChatButton"/, 'Canonical Main Feed New Chat action must use the compact Chats-heading plus control');
assert.match(mainSidebar, /id="chatHistory"[\s\S]*data-conversation-id/, 'Canonical sidebar must own recent Chat history rendering when supplied');
assert.match(mainSidebar, /data-rename-conversation/, 'Canonical sidebar must expose chat rename controls');
assert.match(mainSidebar, /data-delete-conversation/, 'Canonical sidebar must preserve chat delete controls');
assert.match(mainSidebar, /data-agent-user-footer/, 'Canonical sidebar must move secondary navigation into the bottom user menu');
const footerStart = mainSidebar.indexOf('<footer class="agent-sidebar-footer"');
const footerEnd = footerStart < 0 ? -1 : mainSidebar.indexOf('</footer>', footerStart);
assert.ok(footerStart >= 0 && footerEnd > footerStart, 'Canonical sidebar must expose the combined bottom footer');
const footer = mainSidebar.slice(footerStart, footerEnd);
assert.match(footer, /id="vp3AgentRuntimeStrip"/, 'Canonical sidebar must integrate live Agent runtime state into the bottom footer');
assert.doesNotMatch(footer, /agent-sidebar-avatar/, 'Canonical bottom footer must not render the removed user avatar');
assert.doesNotMatch(mainSidebar, /'player'=>'Player'|'saved'=>'Saved Songs'|'playlists'=>'My Playlists'/, 'Canonical Agent navigation must not contain retired music navigation');

assert.ok(wrapper.includes("$html = str_replace('agent-activity-v94.js?v=101', 'agent-activity-v94.js?v=' . $activityBuild, $html);"), 'Main Feed must use an explicit current Agent Activity asset URL');
assert.match(wrapper, /agent-activity-v94-canonical-runtime-20260907/, 'Main Feed must cache-bust the simplified Agent Activity runtime');
assert.match(wrapper, /id=\"chatCreateMenu\"', 'id=\"chatCreateMenu\" hidden/, 'Create + menu must be hidden server-side while retained for later re-enable');
assert.match(wrapper, /data-brain-learning-history-v317 src=/, 'inert Brain Learning asset may remain loaded without exposing a tab');
assert.match(learning, /enabled:false/, 'Brain Learning drawer tab must remain hidden');

assert.doesNotMatch(activity, /<strong>My Knowledge<\/strong>|chat-sidebar-nav|insertAdjacentElement|chatCreateMenu|chat-device-registry-v94|Audio input status|videoinput|getUserMedia|chat-brain-learning-history-v317\.js/, 'Agent Activity must not own sidebar/header/device/Brain Learning UI');
assert.doesNotMatch(learning, /cleanupMainSidebar|data-chat-view-target="player"|data-chat-view-target="saved"|data-chat-view-target="playlists"|data-chat-profile-link="my_team"|chatMyTeam|notificationTab\s*=\s*['"]learning['"]/, 'Brain Learning must not mutate navigation or expose its tab');
assert.doesNotMatch(memberNav, /'my_team','My Team'/, 'profile/dropdown navigation must not duplicate legacy My Team');
assert.match(memberNav, /'profile_agent','Profile Agent'/, 'Profile Agent must remain available from canonical member navigation');
assert.match(memberNav, /'knowledge','My Knowledge',url\('\/knowledge\.php'\)/, 'My Knowledge must remain available from canonical member navigation');
assert.match(memberNav, /'memory','My Memory',url\('\/memory\.php'\)/, 'My Memory must remain available from the canonical user menu');
assert.match(memberNav, /'transcriptions','My Transcriptions'/, 'Transcriptions must remain available from canonical member navigation');
assert.match(memberNav, /'subscription','Plan & Usage'/, 'Plan & Usage must remain available from the canonical user menu');
assert.match(memberNav, /'calendar','My Calendar',url\('\/calendar\.php'\)/, 'My Calendar must remain available from canonical member navigation');
assert.match(settingsUi, /document\.body\.appendChild\(host\)/, 'Chat Settings launcher must live outside the left sidebar');
assert.match(settingsUi, /chat-settings-presence-dot/, 'Chat Settings launcher must expose the compact status dot');
assert.doesNotMatch(settingsUi, /sidebar\.appendChild\(host\)|const sidebar = document\.getElementById\('chatSidebar'\)/, 'Chat Settings must not append to the left sidebar');
assert.match(settingsCss, /position:fixed;[\s\S]*right:10px;[\s\S]*bottom:12px;/, 'Chat Settings must occupy the bottom-right rail position on Agent Chat');
assert.match(mainSidebar, /\$mainSidebarIsChat/, 'Shared sidebar must scope Chat rail controls to Agent Chat');
assert.match(mainSidebar, /if \(\$mainSidebarIsChat\): \?><script data-chat-rail-controls-v132/, 'Chat rail controls must not load on non-Chat workspaces');
assert.match(htaccess, /RewriteRule \^chat-legacy-v108\\\.php\$ \/chat\.php \[R=302,L,NE\]/, 'legacy Chat template must redirect to the one canonical public Chat path');

// The retained source template may still contain legacy media canvas markup for
// compatibility, but it must never be independently addressable or authoritative.
assert.doesNotMatch(template, /id="chatLiveUpdates"/, 'Canonical template source must not contain the parallel Agent Updates panel');
assert.doesNotMatch(chat, /renderActivityUpdates|chatLiveUpdateList|chatLiveStatus/, 'Canonical chat runtime must not render parallel activity cards');
assert.match(chat, /openConversation:async id =>/, 'Canonical chat continuity API must expose conversation opening');
assert.match(chat, /syncConversation:async id =>/, 'Canonical chat continuity API must expose message synchronization');
assert.doesNotMatch(notifications, /ensureHistoryButton|Attention required<\/span>/, 'Attention flow must not fabricate chat-history UI');
assert.match(notifications, /await chat\.openConversation\(id\)/, 'Attention flow must open the persisted conversation through canonical Chat');
assert.match(notifications, /#chatThread \.message\.assistant \.message-text/, 'Attention flow must verify the assistant turn is visible in the canonical canvas');
assert.match(notifications, /queueSpeech\(String\(data\.message \|\| ''\)\)/, 'Speech must happen only after canonical canvas presentation succeeds');
assert.match(memberNav, /'chat','Main Feed',url\('\/chat\.php'\)/, 'User menu must label Agent Chat as Main Feed');
assert.doesNotMatch(memberNav, /'stem_studio'|'video_editor'/, 'Editor workspaces must not appear in the user menu');
assert.match(profileAgent, /profile-agent-sidebar-brand" href="<\?= e\(url\('\/'\)\) \?>"/, 'Profile Agent logo must return to authenticated home');

console.log('main-feed-canonical-ui-contract: ok');
