import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const nav = read('includes/member-navigation.php');
const chat = read('chat.php');
const chatTemplate = read('chat-legacy-v108.php');
const listening = read('artist-listening.php');
const account = read('account.php');
const admin = read('admin/_header.php');
const header = read('includes/header.php');
const knowledge = read('knowledge.php');
const systemKnowledge = read('admin/knowledge.php');
const voiceProfile = read('voice-profile.php');
const bootstrap = read('includes/bootstrap.php');
const profileAgent = read('includes/profile-agent.php');
const profileRuntime = read('includes/profile-agent-runtime.php');
const profileDashboard = read('profile-dashboard.js');
const accountAgentCss = read('account-agent-settings-v236.css');
const profileDashboardCss = read('profile-dashboard.css');
const accountCss = read('account.css');
const htaccess = read('.htaccess');
const voiceProfileJs = read('voice-profile.js');
const profileAgentPortal = read('profile-agent.php');
const accountAgentLoader = read('account-agent-settings-loader-v236.js');

for (const [label, route] of [
  ['Main Feed', '/chat.php'],
  ['View Profile', 'profile_public_url'],
  ['My Account', '/account.php'],
  ['Profile Agent', '/profile-agent.php'],
  ['My Knowledge', '/knowledge.php'],
  ['My Transcriptions', '/artist-listening.php'],
  ['Voice Profile', '/voice-profile.php'],
  ['Artist Workspace', '/admin/artist.php'],
  ['Admin Dashboard', '/admin/index.php'],
  ['Log Out', '/logout.php'],
]) {
  assert.ok(nav.includes(label), `canonical navigation should contain ${label}`);
  assert.ok(nav.includes(route), `canonical navigation should resolve ${route}`);
}

assert.ok(nav.includes("personal_capability_has_v242('personal_knowledge.access'"), 'My Knowledge must use the personal Knowledge access permission');
assert.ok(nav.includes("personal_capability_has_v242('profile_agent.access'"), 'Profile Agent navigation must use its personal capability permission');
assert.ok(nav.includes("personal_capability_has_v242('voice_profile.access'"), 'Voice Profile navigation must use its personal capability permission');
assert.ok(nav.includes("has_permission('artist_listening.access'"), 'My Transcriptions must remain permission gated');
assert.ok(nav.includes("user_has_role('artist'"), 'Artist Workspace must require artist identity');
assert.ok(nav.includes("empty($profile['is_public'])") && nav.includes("'preview=1'"), 'unpublished owners should receive a usable profile preview URL');
assert.ok(!nav.includes('My Library'), 'My Library is not a canonical user-dropdown destination');
assert.ok(!nav.includes("'agent_settings'"), 'Agent Settings belongs inside My Account');
assert.ok(nav.includes("'profile_agent','Profile Agent',url('/profile-agent.php')"), 'Profile Agent is a first-class customer-service destination');
assert.ok(!nav.includes("url('/account.php#profile-agent')"), 'canonical navigation must not route Profile Agent back into My Account');
assert.ok(!nav.includes('Stem Studio') && !nav.includes('/admin/stems.php'), 'Stem Studio must not appear in the user dropdown');
assert.ok(!nav.includes('Video Editor') && !nav.includes('/video-editor.php'), 'Video Editor must not appear in the user dropdown');
assert.ok(nav.indexOf("$add($links,'chat','Main Feed'") < nav.indexOf('$profileUrl = member_navigation_profile_url'), 'Main Feed must be the first canonical user-menu destination');

for (const [name, source] of [['account', account], ['admin', admin], ['site header', header]]) {
  assert.ok(source.includes('member_navigation_menu_links'), `${name} should use canonical member navigation`);
}
assert.ok(chat.includes('member_navigation_menu_links($user)'), 'Chat should replace its legacy dropdown from the canonical map');
assert.ok(!chat.includes('<span>Agent Settings</span>'), 'Chat must not inject Agent Settings into the dropdown');
assert.ok(!chat.includes('<span>Profile Agent</span>'), 'Chat must not maintain a second hardcoded Profile Agent menu item');
assert.ok(!chat.includes('$recordingsNavLink'), 'Chat wrapper must not inject My Transcriptions into the sidebar');
assert.ok(chatTemplate.includes('My Transcriptions'), 'Canonical Main Feed template should use the My Transcriptions product name');
assert.ok(chatTemplate.includes('Profile Agent'), 'Canonical Main Feed sidebar should expose Profile Agent');
assert.ok(chatTemplate.includes('My Contacts'), 'Canonical Main Feed sidebar should expose My Contacts');
assert.ok(listening.includes("'userMenuLinks'=>member_navigation_menu_links($user)"), 'Artist Listening should receive canonical menu JSON');
assert.ok(!listening.includes('my-library.php'), 'Artist Listening menu must not hardcode My Library');
assert.ok(!listening.includes('artist-profile.php?user_id='), 'Artist Listening menu must not use the legacy artist profile route');
assert.ok(voiceProfile.includes('member_navigation_profile_url($user)'), 'Voice Profile should resolve the canonical View Profile destination');
assert.ok(voiceProfile.includes('chat-topbar-actions voice-profile-top-actions'), 'Voice Profile header actions should use the shared right-aligned topbar layout');
assert.ok(voiceProfile.includes('>View Profile</a>'), 'Voice Profile header should expose View Profile');

assert.ok(knowledge.includes("personal_capability_has_v242('personal_knowledge.access'"), 'Personal Knowledge workspace must require personal Knowledge access');
assert.ok(knowledge.includes("$canManage=personal_capability_has_v242('personal_knowledge.manage'"), 'Personal Knowledge workspace must separate view and manage permissions');
assert.ok(knowledge.includes("if(!$canManage){http_response_code(403)"), 'Personal Knowledge writes must fail closed without manage permission');
assert.ok(knowledge.includes("created_by_user_id=? AND i.knowledge_scope='personal'"), 'Personal Knowledge list must be owner-scoped and personal-only');
assert.ok(knowledge.includes("created_by_user_id=? AND knowledge_scope='personal'"), 'Personal Knowledge item operations must be owner-scoped and personal-only');
assert.ok(!knowledge.includes("redirect(url('/admin/knowledge.php'))"), 'Personal Knowledge must not redirect members into the system Knowledge manager');
assert.ok(systemKnowledge.includes("knowledge_scope='system'"), 'Admin Knowledge must remain explicitly system-scoped');
assert.ok(systemKnowledge.includes('Shared / System Knowledge'), 'Admin Knowledge must clearly identify the system/shared library');

assert.ok(bootstrap.includes('/member-navigation.php'), 'bootstrap should load the canonical member navigation helper');
assert.ok(profileAgent.includes("return url('/' . rawurlencode($username));"), 'profile URLs should resolve at the domain root');
assert.ok(!profileAgent.includes('const STONEFELLOW_PROFILE_NAMESPACE'), 'profile URL generation must not retain a Stonefellow namespace declaration');
assert.ok(profileRuntime.includes("'profile_url_example'=>url('/username')"), 'profile owner state should expose a root URL example');
assert.ok(htaccess.includes('RewriteRule ^stonefellow/([A-Za-z0-9._-]+)/?$ /$1 [R=301,L,NE]'), 'legacy namespaced profile URLs should redirect to root usernames');
assert.ok(htaccess.includes('profile.php?username=$1 [L,QSA,NC]'), 'root usernames should rewrite to profile.php');
assert.ok(account.includes('/account.css?v=account-light-20260904'), 'My Account should load the canonical light workspace theme');
assert.ok(account.includes('system_agent_name()'), 'My Account should use the configured system name');
assert.ok(profileDashboard.includes('state.system_agent_name'), 'legacy Profile Agent dashboard source should still use the configured system name while retained for compatibility');
assert.ok(!profileDashboard.includes('stonefellow.com/stonefellow/username'), 'retained profile dashboard source must not show the legacy namespaced URL');
for (const css of [accountAgentCss, profileDashboardCss, accountCss]) { assert.ok(!css.includes('background:#11100f'), 'account surfaces must not contain legacy black card backgrounds'); }
assert.ok(!account.includes('Stonefellow'), 'My Account user-facing copy should use the configured system name');
assert.ok(profileDashboard.includes('profileDisplayUrl=new URL(profileUrl,window.location.origin).href'), 'retained profile dashboard should display the full canonical domain/username URL');
assert.ok(!profileDashboard.includes('Stonefellow-powered'), 'retained Profile Agent copy should use the configured system name');
assert.ok(profileAgentPortal.includes('class="chat-sidebar profile-agent-sidebar"'), 'standalone Profile Agent portal should own a dedicated customer-service sidebar');
assert.ok(profileAgentPortal.includes("personal_capability_has_v242('personal_knowledge.access'"), 'Profile Agent sidebar must honor the Personal Knowledge permission before showing My Knowledge');
assert.ok(profileAgentPortal.includes("<?php if ($personalKnowledgeAllowed): ?><a href=\"<?= e(url('/knowledge.php')) ?>\">My Knowledge</a><?php endif; ?>"), 'Profile Agent My Knowledge link must be permission-gated');
assert.ok(!profileAgentPortal.includes('workspace-sidebar-v82.php'), 'standalone Profile Agent portal should not embed the generic workspace sidebar');
assert.ok(!profileAgentPortal.includes('Customer service workspace'), 'standalone Profile Agent portal should not restore the removed intro hero');
assert.ok(accountAgentLoader.includes('account-agent-settings-v236.js') && !accountAgentLoader.includes('profile-dashboard.js'), 'My Account should load agent settings without injecting the old Profile Agent dashboard');
assert.ok(voiceProfile.includes('systemName:<?= json_encode(system_agent_name()) ?>'), 'Voice Profile should expose the configured system name to its runtime');
assert.ok(!voiceProfileJs.includes('Stonefellow voice clone'), 'Voice Profile runtime must not hardcode the system name');
assert.ok(voiceProfileJs.includes('`Your ${systemName} voice clone was created.`'), 'Voice Profile dynamic system name must use template interpolation');
assert.ok(!voiceProfileJs.includes("'Your ${systemName} voice clone was created.'"), 'Voice Profile must not render a literal ${systemName} token');
assert.ok(accountCss.includes('#agent-brain .agent-brain-tool-grid>a'), 'Agent Brain tool cards should be explicitly covered by the light account theme');

console.log('MEMBER_NAVIGATION_CONTRACT=PASS');