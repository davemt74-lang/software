import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const social = read('includes/social-network-v320.php');
const socialRefined = read('includes/social-network-v321.php');
const plugins = read('includes/plugin-registry-v320.php');
const music = read('includes/music-workspace-plugin-v320.php');
const subscriptions = read('includes/subscription-schema.php');
const upgrade = read('upgrade.php');
const teamLegacy = read('api/team-chat-v109.php');
const teamScoped = read('api/team-chat-v320.php');
const messagesApi = read('api/messages-v320.php');
const profileSocialApi = read('api/profile-social-v320.php');
const profileSocialJs = read('profile-social-v320.js');
const memberNav = read('includes/member-navigation.php');
const musicPage = read('music-workspace.php');
const architecture = read('docs/VP3_PLATFORM_ARCHITECTURE_V320.md');

// VP3 identity, package access, plugins and workspace relationships are separate dimensions.
assert.match(subscriptions, /'music_workspace\.access'\s*=>\s*\['label'=>'Music Workspace'/);
assert.match(plugins, /'music_workspace'[\s\S]*'entitlement'=>'music_workspace\.access'/);
assert.match(plugins, /CREATE TABLE IF NOT EXISTS user_plugin_installations/);
assert.match(music, /function music_workspace_entitled_v320/);
assert.match(music, /function music_workspace_enabled_v320/);
assert.match(music, /vp3_plugin_set_enabled_v320/);
assert.match(music, /artist_workspace_v181_ensure_schema/);
assert.doesNotMatch(music, /INSERT\s+INTO\s+user_account_types/i, 'Music enablement must not create an account type');
assert.doesNotMatch(music, /UPDATE\s+users\s+SET\s+role/i, 'Music enablement must not mutate primary identity role');

// Music ownership grants only music-domain capability; never platform/admin authority.
const musicPermissions = music.match(/function music_workspace_owner_permissions_v320\(\): array[\s\S]*?\n}/)?.[0] || '';
for (const permission of ['tracks.manage','albums.manage','release.manage','producer.access','team.manage']) {
  assert.match(musicPermissions, new RegExp(permission.replace('.', '\\.')));
}
for (const forbidden of ['admin.access','messages.manage','users.manage','ai.manage','permissions.manage']) {
  assert.doesNotMatch(musicPermissions, new RegExp(`['\"]${forbidden.replace('.', '\\.')}['\"]`));
}
assert.match(music, /preserves_data_on_disable'=>true/);
assert.doesNotMatch(plugins, /DELETE\s+FROM\s+(?:tracks|albums|artist_|human_|users)/i, 'Plugin disablement must not delete user/workspace data');

// Social graph and human messaging have their own persistence domain.
for (const table of ['user_follows','user_friendships','user_blocks','human_conversations','human_conversation_members','human_messages','human_message_requests']) {
  assert.match(social, new RegExp(table));
}
assert.doesNotMatch(social, /profile_agent_messages|chat_messages/, 'Human messages must not be stored as Agent/Profile-Agent chat');
assert.match(messagesApi, /vp3_social_inbox_v321/);
assert.match(messagesApi, /request_accept/);
assert.match(messagesApi, /friend_request/);
assert.match(messagesApi, /block/);
assert.match(socialRefined, /r\.status='pending'/);

// Direct sends are re-evaluated against the current social/workspace relationship.
assert.match(socialRefined, /function vp3_social_send_message_v321/);
assert.match(socialRefined, /vp3_social_dm_route_v320\(\$pdo,\$senderId,\$other\)/);
assert.match(socialRefined, /This member is not accepting messages from you/);
assert.match(socialRefined, /status='accepted'/);
assert.match(messagesApi, /vp3_social_start_direct_v321/);
assert.match(messagesApi, /vp3_social_send_message_v321/);

// Team General is authorized from current workspace membership, not a copied participant grant.
assert.match(social, /conversation_type='team_general'/);
assert.match(social, /artist_team_members/);
const teamAccess = social.match(/function vp3_social_can_access_conversation_v320[\s\S]*?\n}/)?.[0] || '';
assert.match(teamAccess, /team_general/);
assert.match(teamAccess, /artist_team_members/);
assert.match(teamAccess, /u\.is_active=1/);

// Legacy Team Chat is a compatibility URL only and its canonical runtime is both
// shared-workspace scoped and governed by the member's existing Chat Settings.
assert.match(teamLegacy, /require __DIR__\.'\/team-chat-v320\.php'/);
assert.match(teamScoped, /vp3_social_shared_workspace_v320/);
assert.match(teamScoped, /artist_team_members/);
assert.match(teamScoped, /chat_settings_get_v237/);
assert.match(teamScoped, /social_chat_disabled/);
assert.match(teamScoped, /COALESCE\(p\.presence_mode,'online'\)='online'/);
assert.doesNotMatch(teamScoped, /WHERE\s+u\.role\s+IN/i);
assert.doesNotMatch(teamScoped, /user_has_role\(/);

// Public member profiles expose social actions without altering Profile Agent persistence.
assert.match(profileSocialApi, /vp3_social_relationship_state_v320/);
assert.match(profileSocialApi, /csrf_token\(\)/);
for (const action of ['Follow','Add Friend','Accept Friend','Message']) assert.match(profileSocialJs, new RegExp(action));
assert.match(memberNav, /'plugins','Plugins'/);
assert.match(memberNav, /'messages','Messages'/);
assert.match(memberNav, /music_workspace_enabled_v320/);
assert.doesNotMatch(memberNav.match(/\$musicEnabled[\s\S]*?\n\s*if\(\$musicEnabled\)/)?.[0] || '', /user_has_role\('artist'/);

// Music portal only links to real, authorized routes. Stem Studio remains track-scoped.
assert.doesNotMatch(musicPage, /stem-studio\.php/);
assert.match(musicPage, /admin\/artist\.php\?collection=tracks/);
assert.match(musicPage, /player\.php/);
assert.match(musicPage, /\$canListening=has_permission\('artist_listening\.access'/);
assert.match(musicPage, /without assigning a global Artist role/);

// One canonical upgrade installs this architecture without destructive replacement.
assert.match(upgrade, /vp3_plugin_ensure_schema_v320\(\)/);
assert.match(upgrade, /vp3_social_ensure_schema_v320\(\)/);
assert.match(upgrade, /existing user content/);
assert.match(upgrade, /Existing accounts, package assignments, team memberships, token balances, music content/);

// Repository documentation must continue to state the same product/security model as the runtime.
for (const statement of [
  'VP3 is the personal AI, transcription, identity and personal-URL platform',
  'Music is a professional capability layer',
  'Team membership is a resource relationship, not an account type',
  'Each workspace has one canonical General conversation',
  'Human messages must not be stored as Agent Chat messages',
  'Package changes do not mutate identity or Team relationships',
  'Plugin disablement preserves user data',
]) assert.match(architecture, new RegExp(statement.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));

console.log('VP3_SOCIAL_MUSIC_ARCHITECTURE_V320=PASS');
