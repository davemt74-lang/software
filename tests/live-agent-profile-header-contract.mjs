import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(path,'utf8');
const chat = read('chat.php');
const identity = read('chat-agent-identity-v236.js');
const header = read('chat-header-ui.css');
const sidebar = read('includes/workspace-sidebar-v82.php');
const shell = read('member-shell-v77.js');
const voice = read('chat-voice.js');

// Normal Chat must resolve the renamed owner agent on the server, rather than
// first rendering Stonefellow and hoping a cached browser script redirects.
assert.match(chat,/\$requestedAgentRaw = trim\(\(string\)\(\$_GET\['agent'\] \?\? ''\)\)/);
assert.match(chat,/\$explicitSystemAgent = strcasecmp\(\$requestedAgentRaw, 'system'\) === 0/);
assert.match(chat,/user_agents_list_v236\(\$pdoForAgent, \(int\)\$user\['id'\], true\)/);
assert.match(chat,/\$activeUserAgent = \$preferred \?: \$ownedAgents\[0\]/,'normal Chat falls back to the first active renamed owner agent');
assert.match(chat,/\$agentDisplayName = trim\(\(string\)\$activeUserAgent\['display_name'\]\)/,'server-rendered Chat uses renamed identity');
assert.match(chat,/preg_replace_callback\([\s\S]*?\/\\bStonefellow\\b\/u[\s\S]*?\$agentDisplayName/,'legacy proactive greeting is corrected to the active renamed identity');
assert.match(chat,/\$agentIdentityBuild = 'live-wiring-20260903-3'/,'identity bundle gets a new cache key');
assert.match(chat,/Public-profile lookup is deliberately isolated from Chat identity/,'profile lookup cannot demote the active renamed agent');
assert.match(chat,/Keep the legacy artist[\s\S]*?until a canonical profile URL really exists/,'partial upgrades retain the legacy profile fallback');

// Header/profile links must exist in server HTML, not only through JS enhancement.
assert.ok(chat.includes('data-chat-profile-link="profile"'),'canonical profile link is server-rendered in Chat');
assert.ok(chat.includes('data-chat-profile-link="agent-settings"'),'agent settings link is server-rendered in Chat');
assert.ok(chat.includes('data-chat-profile-link="profile-agent"'),'Profile Agent link is server-rendered in Chat');
assert.ok(chat.includes('data-chat-header-ui-server'),'header CSS is loaded independently of agent schema readiness');
assert.ok(chat.includes('$runtime = $headerUiRuntime'),'header UI is part of the unconditional Chat runtime');

// JS remains an enhancement/fallback and no longer requires is_default to find
// the only/first active renamed Stonefellow identity.
assert.match(identity,/const active=agents\.filter\(a=>Number\(a\.is_active\)\)/);
assert.match(identity,/active\.find\(a=>Number\(a\.is_default\)\)\|\|active\[0\]/);
assert.match(identity,/My Profile/);
assert.match(identity,/Profile Agent Dashboard/);
assert.match(identity,/Agent Settings/);

// Account/user management exposes the profile and agent destinations directly,
// and loads the canonical white workspace assets from server-rendered markup.
assert.match(sidebar,/profile_public_url\(\$workspaceUsername\)/);
assert.match(sidebar,/>My Profile</);
assert.match(sidebar,/>My Agent</);
assert.match(sidebar,/>Profile Agent</);
assert.match(sidebar,/chat-header-ui\.css\?v=white-tech-20260904/);
assert.match(sidebar,/account-agent-settings-loader-v236\.js\?v=white-tech-20260904/);
assert.match(shell,/const build='white-tech-20260904'/);
assert.match(shell,/chat-header-ui\.css\?v=\$\{build\}/);
assert.doesNotMatch(shell,/account-tech\.css/,'legacy Account-only theme is no longer injected');

// The actual mobile containing-block bug: fixed menus must be viewport-based,
// with backdrop/filter/transform traps removed from the header at mobile size.
const mobile = header.slice(header.indexOf('@media(max-width:760px)'));
assert.ok(mobile.length > 0,'mobile header rules exist');
assert.match(mobile,/\.chat-topbar\{/);
assert.match(mobile,/transform:none!important/);
assert.match(mobile,/filter:none!important/);
assert.match(mobile,/backdrop-filter:none!important/);
assert.match(mobile,/-webkit-backdrop-filter:none!important/);
assert.match(mobile,/contain:none!important/);
assert.match(mobile,/\.chat-top-dropdown\{[\s\S]*?position:fixed!important;[\s\S]*?top:58px!important;[\s\S]*?right:0!important;[\s\S]*?bottom:0!important;[\s\S]*?left:0!important;[\s\S]*?width:100vw!important;[\s\S]*?height:calc\(100dvh - 58px\)!important/s);
assert.match(mobile,/\.chat-create-dropdown,[\s\S]*?\.chat-notification-dropdown,[\s\S]*?\.chat-profile-dropdown/);
assert.match(header,/\.chat-topbar\{[\s\S]*?z-index:20000!important/);
assert.match(header,/\.sf-online-rail-v109\{z-index:9900!important\}/);
assert.match(header,/\.sf-team-chat-windows-v109\{z-index:9950!important\}/);
assert.match(header,/@import url\('\.\/stonefellow-ui\.css\?v=white-tech-20260904'\)/,'header pulls the canonical white authenticated theme');

// This hotfix must not alter the stable voice runtime.
assert.match(voice,/STONEFELLOW_CHAT_VOICE/,'canonical voice runtime remains present');

console.log('LIVE_AGENT_PROFILE_HEADER_CONTRACT=PASS');
