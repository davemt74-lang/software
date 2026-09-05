import fs from 'node:fs';
import assert from 'node:assert/strict';

const chatCss = fs.readFileSync('chat.css','utf8');
const chatPhp = fs.readFileSync('chat.php','utf8');
const legacy = fs.readFileSync('chat-legacy-v108.php','utf8');
const overlay = fs.readFileSync('chat-media-overlays.css','utf8');
const profileActivity = fs.readFileSync('profile-activity-chat.js','utf8');

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
assert.match(chatPhp, /\['profile'=>true, 'account'=>true, 'profile_agent'=>true\]/, 'Chat dropdown must exclude profile/account/Profile Agent shortcuts');
assert.equal(overlay.includes('body[data-agent-theme="light"]'), false, 'media overlay presentation must not depend on theme state');
assert.match(profileActivity, /actions\.insertBefore\(button,avatar\|\|null\)/, 'Profile Activity badge must live in the upper-right action cluster');
console.log('chat-light-source-contract=PASS');
