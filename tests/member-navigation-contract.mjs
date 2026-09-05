import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const nav = read('includes/member-navigation.php');
const chat = read('chat.php');
const listening = read('artist-listening.php');
const account = read('account.php');
const admin = read('admin/_header.php');
const header = read('includes/header.php');
const knowledge = read('knowledge.php');
const voiceProfile = read('voice-profile.php');
const bootstrap = read('includes/bootstrap.php');
const profileAgent = read('includes/profile-agent.php');
const profileRuntime = read('includes/profile-agent-runtime.php');
const profileDashboard = read('profile-dashboard.js');
const accountAgentCss = read('account-agent-settings-v236.css');
const profileDashboardCss = read('profile-dashboard.css');
const accountCss = read('account.css');
const htaccess = read('.htaccess');

for (const [label, route] of [
  ['View Profile', 'profile_public_url'],
  ['My Account', '/account.php'],
  ['My Knowledge', '/knowledge.php'],
  ['My Transcriptions', '/artist-listening.php'],
  ['Agent Chat', '/chat.php'],
  ['Voice Profile', '/voice-profile.php'],
  ['Artist Workspace', '/admin/artist.php'],
  ['Stem Studio', '/admin/stems.php'],
  ['Video Editor', '/video-editor.php'],
  ['Admin Dashboard', '/admin/index.php'],
  ['Log Out', '/logout.php'],
]) {
  assert.ok(nav.includes(label), `canonical navigation should contain ${label}`);
  assert.ok(nav.includes(route), `canonical navigation should resolve ${route}`);
}

assert.ok(nav.includes("has_permission('knowledge.manage'"), 'My Knowledge must be permission gated');
assert.ok(nav.includes("has_permission('artist_listening.access'"), 'My Transcriptions must be permission gated');
assert.ok(nav.includes("user_has_role('artist'"), 'Artist Workspace must require artist identity');
assert.ok(nav.includes("has_permission('producer.access'"), 'Stem Studio must retain producer access');
assert.ok(nav.includes("empty($profile['is_public'])") && nav.includes("'preview=1'"), 'unpublished owners should receive a usable profile preview URL');
assert.ok(!nav.includes('My Library'), 'My Library is not a canonical user-dropdown destination');
assert.ok(!nav.includes("'agent_settings'"), 'Agent Settings belongs inside My Account');
assert.ok(!nav.includes("'profile_agent'"), 'Profile Agent belongs inside My Account');

for (const [name, source] of [['account', account], ['admin', admin], ['site header', header]]) {
  assert.ok(source.includes('member_navigation_menu_links'), `${name} should use canonical member navigation`);
}
assert.ok(chat.includes('member_navigation_menu_links($user)'), 'Chat should replace its legacy dropdown from the canonical map');
assert.ok(!chat.includes('<span>Agent Settings</span>'), 'Chat must not inject Agent Settings into the dropdown');
assert.ok(!chat.includes('<span>Profile Agent</span>'), 'Chat must not inject Profile Agent into the dropdown');
assert.ok(chat.includes('My Transcriptions'), 'Chat sidebar should use the My Transcriptions product name');
assert.ok(listening.includes("'userMenuLinks'=>member_navigation_menu_links($user)"), 'Artist Listening should receive canonical menu JSON');
assert.ok(!listening.includes('my-library.php'), 'Artist Listening menu must not hardcode My Library');
assert.ok(!listening.includes('artist-profile.php?user_id='), 'Artist Listening menu must not use the legacy artist profile route');
assert.ok(voiceProfile.includes('member_navigation_profile_url($user)'), 'Voice Profile should resolve the canonical View Profile destination');
assert.ok(voiceProfile.includes('chat-topbar-actions voice-profile-top-actions'), 'Voice Profile header actions should use the shared right-aligned topbar layout');
assert.ok(voiceProfile.includes('>View Profile</a>'), 'Voice Profile header should expose View Profile');
assert.ok(knowledge.includes("has_permission('knowledge.manage'"), '/knowledge.php should forward managers to the real knowledge workspace');
assert.ok(knowledge.includes("/admin/knowledge.php"), '/knowledge.php should resolve to the actual knowledge manager');
assert.ok(bootstrap.includes("/member-navigation.php"), 'bootstrap should load the canonical member navigation helper');
assert.ok(profileAgent.includes("return url('/' . rawurlencode($username));"), 'profile URLs should resolve at the domain root');
assert.ok(!profileAgent.includes('STONEFELLOW_PROFILE_NAMESPACE'), 'profile URL generation must not retain a Stonefellow namespace');
assert.ok(profileRuntime.includes("'profile_url_example'=>url('/username')"), 'profile owner state should expose a root URL example');
assert.ok(htaccess.includes('RewriteRule ^stonefellow/([A-Za-z0-9._-]+)/?$ /$1 [R=301,L,NE]'), 'legacy namespaced profile URLs should redirect to root usernames');
assert.ok(htaccess.includes('profile.php?username=$1 [L,QSA,NC]'), 'root usernames should rewrite to profile.php');
assert.ok(account.includes('/account.css?v=account-light-20260904'), 'My Account should load the canonical light workspace theme');
assert.ok(account.includes('system_agent_name()'), 'My Account should use the configured system name');
assert.ok(profileDashboard.includes('state.system_agent_name'), 'Profile Agent dashboard should use the configured system name');
assert.ok(!profileDashboard.includes('stonefellow.com/stonefellow/username'), 'Profile dashboard must not show the legacy namespaced URL');
for (const css of [accountAgentCss, profileDashboardCss, accountCss]) { assert.ok(!css.includes('background:#11100f'), 'account surfaces must not contain legacy black card backgrounds'); }

console.log('MEMBER_NAVIGATION_CONTRACT=PASS');
