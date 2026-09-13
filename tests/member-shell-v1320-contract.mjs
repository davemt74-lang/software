import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const navigation = read('includes/member-navigation.php');
const sidebar = read('includes/main-sidebar.php');
const memberHeader = read('includes/member-header.php');
const memberUserMenu = read('includes/member-user-menu.php');
const memberShell = read('member-shell-v77.js');
const agentVoiceMenu = read('member-agent-voice-menu.js');
const agentVoiceCss = read('member-agent-voice-menu.css');
const voiceProfileApi = read('api/studio-voice-profile.php');
const userAgentApi = read('api/user-agent-system-v236.php');
const products = read('profile-commerce-products.php');
const homeserverPage = read('settings-homeserver.php');
const homeserverCss = read('homeserver-settings-v1200.css');

assert.match(navigation, /\$add\(\$links,'calendar','My Calendar',url\('\/calendar\.php'\),'agent'\)/, 'My Calendar must be an account-level navigation destination');
const calendarIndex = navigation.indexOf("$add($links,'calendar','My Calendar'");
const schedulingGateIndex = navigation.indexOf("agent_scheduling_schema_ready_v430");
assert.ok(calendarIndex > 0 && schedulingGateIndex > calendarIndex, 'My Calendar must not depend on legacy scheduling schema readiness');

assert.match(navigation, /\$add\(\$links,'knowledge','My Knowledge',url\('\/knowledge\.php'\),'identity'\)/, 'My Knowledge must remain a canonical destination');
assert.match(navigation, /\$add\(\$links,'messages','Messages',url\('\/messages\.php'\),'identity'\)/, 'Messages must remain a canonical destination');
assert.match(navigation, /\$add\(\$links,'profile_agent','Profile Agent',url\('\/profile-agent\.php'\),'identity'\)/, 'Profile Agent must remain a canonical destination');
assert.match(navigation, /\$add\(\$links,'memory','My Memory',url\('\/memory\.php'\),'identity'\)/, 'My Memory must remain available from the secondary account menu');

assert.match(sidebar, /'profile_agent'=>true,'messages'=>true,'knowledge'=>true/, 'My Agent, My Messages and My Knowledge must be promoted out of the sidebar footer menu');
assert.match(sidebar, /href="<\?= e\(url\('\/profile-agent\.php'\)\) \?>"[^>]*><span>◉<\/span><strong>My Agent<\/strong>/, 'Primary sidebar must visibly expose My Agent');
assert.match(sidebar, /href="<\?= e\(url\('\/messages\.php'\)\) \?>"[^>]*><span>✉<\/span><strong>My Messages<\/strong>/, 'Primary sidebar must visibly expose My Messages');
assert.match(sidebar, /href="<\?= e\(url\('\/knowledge\.php'\)\) \?>"[^>]*><span>◇<\/span><strong>My Knowledge<\/strong>/, 'Primary sidebar must visibly expose My Knowledge');
assert.match(sidebar, /'calendar'=>true/, 'My Calendar must be promoted out of the footer menu');
assert.match(sidebar, /href="<\?= e\(url\('\/calendar\.php'\)\) \?>"[^>]*><span>▣<\/span><strong>My Calendar<\/strong>/, 'Primary sidebar must visibly expose My Calendar');
assert.match(sidebar, /\$mainSidebarCalendarActive/, 'My Calendar must have a canonical active state');
assert.doesNotMatch(sidebar, /href="<\?= e\(url\('\/approvals\.php'\)\) \?>"/, 'Approvals must not remain in the primary sidebar');
assert.doesNotMatch(sidebar, /<strong>Approvals<\/strong>/, 'Approvals label must be removed from the primary sidebar');

assert.doesNotMatch(memberUserMenu, /foreach\s*\(\s*\$memberMenuLinks/, 'Upper-right member menu must not render the general navigation list');
assert.match(memberUserMenu, /data-vp3-agent-voice-dashboard/, 'Upper-right member menu must render the Agent Voice dashboard');
assert.match(memberUserMenu, /data-vp3-agent-name/, 'Agent Voice dashboard must expose the editable agent name field');
assert.match(memberUserMenu, /My ElevenLabs voice clone/, 'Agent Voice dashboard must expose the existing ElevenLabs clone as a real voice source');
assert.match(memberUserMenu, /Manage clone/, 'Agent Voice dashboard must link to the canonical Voice Profile clone manager');
assert.match(memberUserMenu, /data-vp3-global-agent-voice/, 'Agent Voice dashboard must expose the existing persistent Agent Voice preference');

assert.match(agentVoiceMenu, /api\/user-agent-system-v236\.php/, 'Agent Voice runtime must reuse the canonical user-agent settings API');
assert.match(agentVoiceMenu, /api\/studio-voice-profile\.php/, 'Agent Voice runtime must reuse the canonical Voice Profile API');
assert.match(agentVoiceMenu, /api\/chat-settings-v237\.php/, 'Agent Voice runtime must reuse the existing Agent Voice preference API');
assert.match(agentVoiceMenu, /update_agent/, 'Agent name and per-agent clone selection must save through the existing agent update action');
assert.match(agentVoiceMenu, /voice_enabled/, 'Voice source selection must reuse the existing per-agent voice_enabled setting');
assert.match(agentVoiceMenu, /has_clone_binding/, 'ElevenLabs clone selection must be backed by the real Voice Profile binding state');
assert.doesNotMatch(agentVoiceMenu, /ELEVENLABS_API_KEY|xi-api-key|ai_elevenlabs_api_key|ai_decrypt_secret/i, 'Browser Agent Voice runtime must never receive ElevenLabs credentials');
assert.match(voiceProfileApi, /studio_voice_profile_elevenlabs_key/, 'ElevenLabs credential ownership must remain server-side in the canonical Voice Profile API');
assert.match(voiceProfileApi, /https:\/\/api\.elevenlabs\.io\/v1\/voices\/add/, 'Voice clone creation must remain owned by the existing ElevenLabs server integration');
assert.match(userAgentApi, /if \(\$action === 'update_agent'\)/, 'Canonical agent API must retain the update action reused by the compact menu');
assert.match(agentVoiceCss, /\.chat-profile-dropdown\.vp3-agent-voice-menu/, 'Compact Agent Voice dropdown must own its responsive menu sizing');

assert.match(products, /includes\/member-header\.php/, 'Profile Commerce products must render the shared member header');
assert.match(memberHeader, /data-member-shell-runtime[^>]*member-shell-v77\.js/, 'Shared member header must load the runtime that owns its avatar dropdown');
assert.match(memberHeader, /member-agent-voice-menu\.js/, 'Shared member header must load the Agent Voice dashboard runtime when the sidebar has not already loaded it');
assert.match(sidebar, /member-agent-voice-menu\.js/, 'Canonical sidebar must load the Agent Voice runtime for Chat and legacy account-header surfaces');
assert.match(memberShell, /window\.__VP3_MEMBER_SHELL_V77__/, 'Member shell runtime must guard against duplicate execution');
assert.match(memberShell, /profileButton\?\.addEventListener\('click'/, 'Member shell runtime must wire the profile dropdown button');
assert.match(memberShell, /profileDropdown\.hidden = !opening/, 'Member shell runtime must toggle the profile dropdown');

assert.match(homeserverPage, /class="chat-main account-chat-main"/, 'HomeServer settings must use the fixed account shell');
assert.match(homeserverPage, /class="hs-settings"/, 'HomeServer settings must expose its dedicated scroll surface');
assert.match(homeserverCss, /\.account-chat-main\{[^}]*grid-template-rows:58px minmax\(0,1fr\);[^}]*overflow:hidden/s, 'HomeServer main shell must constrain the viewport rows');
assert.match(homeserverCss, /\.account-chat-main>\.hs-settings\{[^}]*min-height:0;[^}]*overflow-x:hidden;[^}]*overflow-y:auto/s, 'HomeServer settings surface must own vertical scrolling');
assert.match(homeserverCss, /-webkit-overflow-scrolling:touch/, 'HomeServer scrolling must retain touch momentum behavior');

console.log('Member Shell v13.20 + Agent Voice menu contract OK');
