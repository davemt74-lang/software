import fs from 'node:fs';
import assert from 'node:assert/strict';

const chatCss = fs.readFileSync('chat.css','utf8');
const chatPhp = fs.readFileSync('chat.php','utf8');
const legacy = fs.readFileSync('chat-legacy-v108.php','utf8');
const overlay = fs.readFileSync('chat-media-overlays.css','utf8');
const profileActivity = fs.readFileSync('profile-activity-chat.js','utf8');
const teamChatCss = fs.readFileSync('team-chat-v109.css','utf8');
const teamChatAdmin = fs.readFileSync('team-chat-admin-v109.js','utf8');
const teamChatWidget = fs.readFileSync('includes/team-chat-widget-v81.php','utf8');

assert.match(chatCss, /:root\{--bg:#ffffff;--side:#f8fafc;--panel:#ffffff;--panel2:#f3f4f6;--cream:#111827;/, 'Chat base palette must be light at source');
for (const token of ['#0b0a09','#11100e','#171411','#1d1915','#ddc4a4','rgba(190,155,111']) {
  assert.equal(chatCss.includes(token), false, `legacy brown Chat token must be removed: ${token}`);
}
assert.equal(chatPhp.includes('agent-theme-v242.css'), false, 'Chat must not load theme override CSS');
assert.equal(chatPhp.includes('agent-theme-v242.js'), false, 'Chat must not load the theme toggle runtime');
assert.match(legacy, /meta name="theme-color" content="#ffffff"/, 'browser theme color must be light');
assert.match(legacy, /chat\.css\?v=206-source-light-20260905/, 'Chat base CSS must be cache-busted');
assert.match(legacy, /data-chat-view-target="playlists"/, 'Playlists must stay in the sidebar');
for (const removed of ['shows','photos','merch']) {
  assert.equal(legacy.includes(`data-chat-view-target="${removed}"`), false, `${removed} must be removed from the main sidebar`);
}
assert.match(chatPhp, /\['account'=>true, 'profile_agent'=>true\]/, 'Chat dropdown must retain View Profile while excluding duplicate account/Profile Agent shortcuts');
assert.equal(chatPhp.includes("['profile'=>true, 'account'=>true, 'profile_agent'=>true]"), false, 'Chat dropdown must never suppress the canonical View Profile item');
assert.equal(overlay.includes('body[data-agent-theme="light"]'), false, 'media overlay presentation must not depend on theme state');
assert.match(profileActivity, /actions\.insertBefore\(button,avatar\|\|null\)/, 'Profile Activity badge must live in the upper-right action cluster');

assert.match(teamChatCss, /\.sf-online-rail-v109\{[^}]*background:#f8fafc/, 'Team Chat rail must be light at source');
assert.match(teamChatCss, /\.sf-team-chat-windows-v109/, 'Team Chat popup window owner must remain in the canonical stylesheet');
assert.match(teamChatAdmin, /const ASSET_BUILD = 'team-chat-light-v117-20260905'/, 'Team Chat bootstrap must own the current light asset build');
assert.match(teamChatAdmin, /team-chat-v109\.css\?v=' \+ ASSET_BUILD/, 'runtime-injected Team Chat CSS must use the light asset build rather than the bootstrap build');
assert.match(teamChatAdmin, /team-chat-v109\.js\?v=' \+ ASSET_BUILD/, 'runtime-injected Team Chat JS must use the same canonical asset build');
assert.match(teamChatAdmin, /configSource = 'existing-chat-widget'/, 'Agent Chat must reuse its server-rendered Team Chat widget instead of creating a second poller');
assert.match(teamChatWidget, /\$teamChatAssetBuild = 'team-chat-light-v117-20260905'/, 'server-rendered Team Chat widget must use the same canonical asset build');
assert.match(chatPhp, /\$teamChatAdminBuild = 'team-chat-bootstrap-v236-20260905'/, 'Agent Chat must cache-bust the Team Chat bootstrap owner');

console.log('chat-light-source-contract=PASS');