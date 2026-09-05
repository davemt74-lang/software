import fs from 'node:fs';
import assert from 'node:assert/strict';

const template = fs.readFileSync('chat-legacy-v108.php', 'utf8');
const chat = fs.readFileSync('chat.js', 'utf8');
const notifications = fs.readFileSync('chat-notifications-drawer-v240.js', 'utf8');
const memberNav = fs.readFileSync('includes/member-navigation.php', 'utf8');
const profileAgent = fs.readFileSync('profile-agent.php', 'utf8');
const mainSidebar = fs.readFileSync('includes/main-sidebar.php', 'utf8');
const activity = fs.readFileSync('agent-activity-v94.js', 'utf8');

assert.match(template, /href="<\?= e\(url\('\/contacts\.php'\)\) \?>"[\s\S]*?<strong>My Contacts<\/strong>/, 'Main Feed sidebar must expose My Contacts');
assert.match(template, /href="<\?= e\(url\('\/profile-agent\.php'\)\) \?>"[\s\S]*?<strong>Profile Agent<\/strong>/, 'Main Feed sidebar must expose Profile Agent');
assert.match(template, /<strong>My Playlists<\/strong>/, 'Main Feed sidebar must label playlists as My Playlists');
assert.match(mainSidebar, /href="<\?= e\(url\('\/knowledge\.php'\)\) \?>"[\s\S]*?<strong>My Knowledge<\/strong>/, 'Canonical member sidebar must expose My Knowledge');
assert.match(activity, /<strong>My Knowledge<\/strong>/, 'Main Feed runtime must expose My Knowledge in its visible sidebar');
assert.match(activity, /contacts\.insertAdjacentElement\('afterend',link\)/, 'Main Feed must place My Knowledge directly after My Contacts');
assert.doesNotMatch(template, /id="chatLiveUpdates"/, 'Canonical template must not contain the parallel Agent Updates panel');
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
