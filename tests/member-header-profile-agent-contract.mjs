import assert from 'node:assert/strict';
import fs from 'node:fs';

const profileAgent = fs.readFileSync('profile-agent.php', 'utf8');
const memberHeader = fs.readFileSync('includes/member-header.php', 'utf8');

assert.match(profileAgent, /includes\/member-header\.php/, 'Profile Agent must use the canonical member header');
assert.doesNotMatch(profileAgent, /<header class="chat-topbar profile-agent-topbar">/, 'Profile Agent must not recreate the universal topbar');
assert.match(memberHeader, /chat-notifications-drawer-v240\.js/, 'member header must provide the tabbed Activity Center runtime');
assert.match(memberHeader, /chat-transcription-canvas\.js/, 'member header must provide the Transcription Activity runtime');
assert.match(memberHeader, /member-user-menu\.php/, 'member header must provide the shared profile dropdown');

console.log('member-header-profile-agent-contract: PASS');
