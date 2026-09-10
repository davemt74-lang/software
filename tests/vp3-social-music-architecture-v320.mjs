import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const social = read('includes/social-network-v320.php');
const socialRefined = read('includes/social-network-v321.php');
const human = read('includes/human-messaging-v370.php');
const plugins = read('includes/plugin-registry-v320.php');
const music = read('includes/music-workspace-plugin-v320.php');
const resources = read('includes/music-workspace-resources-v330.php');
const subscriptions = read('includes/subscription-schema.php');
const teamSubscription = read('includes/team-subscription.php');
const setup = read('setup.php');
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

// Music enablement is atomic and all schema DDL is kept out of caller-owned transactions.
assert.match(plugins, /if\(!vp3_plugin_schema_ready_v320\(\$pdo\)\)vp3_plugin_ensure_schema_v320\(\$pdo\)/);
const musicToggle = music.match(/function music_workspace_set_enabled_v320[\s\S]*?\n}/)?.[0] || '';
const musicOwner = music.match(/function music_workspace_ensure_owner_v320[\s\S]*?\n}/)?.[0] || '';
assert.match(musicToggle, /\$ownsTransaction=!\$pdo->inTransaction\(\)/);
assert.match(musicToggle, /artist_workspace_v181_schema_ready/);
assert.match(musicToggle, /artist_workspace_v181_ensure_schema\(\$pdo\)/);
assert.ok(musicToggle.indexOf('artist_workspace_v181_ensure_schema($pdo)') < musicToggle.indexOf('$pdo->beginTransaction()'), 'workspace schema DDL must run before Music state transaction');
assert.match(musicOwner, /artist_workspace_v181_schema_ready/);
assert.match(musicToggle, /\$pdo->beginTransaction\(\)/);
assert.match(musicToggle, /music_workspace_ensure_owner_v320\(\$pdo,\$user\)/);
assert.match(musicToggle, /\$pdo->commit\(\)/);
assert.match(musicToggle, /\$pdo->rollBack\(\)/);

// Social graph and human messaging remain separate from Agent/Profile-Agent persistence.
for (const table of ['user_follows','user_friendships','user_blocks','human_conversations','human_conversation_members','human_messages','human_message_requests']) {
  assert.match(social, new RegExp(table));
}
assert.doesNotMatch(social, /profile_agent_messages|chat_messages/, 'Human messages must not be stored as Agent/Profile-Agent chat');
assert.match(human, /human_conversation_reads_v370/);
assert.match(messagesApi, /vp3_human_inbox_v370/);
assert.match(messagesApi, /request_accept/);
assert.match(messagesApi, /friend_request/);
assert.match(messagesApi, /block/);
assert.match(socialRefined, /r\.status='pending'/);

// v3.70 is now the canonical direct-message mutation path.
assert.match(human, /function vp3_human_start_direct_v370/);
assert.match(human, /function vp3_human_send_message_v370/);
assert.match(human, /vp3_human_dm_route_v370/);
assert.match(human, /This member is not accepting messages from you/);
assert.match(human, /status='accepted'/);
assert.match(messagesApi, /vp3_human_start_direct_v370/);
assert.match(messagesApi, /vp3_human_send_message_v370/);

// Team General is authorized from current workspace membership, never copied participant state.
assert.match(human, /conversation_type='team_general'/);
assert.match(human, /artist_team_members/);
const teamAccessStart = human.indexOf('function vp3_human_can_access_v370');
const teamAccessEnd = human.indexOf('function vp3_human_insert_message_v370', teamAccessStart);
const teamAccess = human.slice(teamAccessStart, teamAccessEnd);
assert.match(teamAccess, /team_general/);
assert.match(teamAccess, /vp3_human_team_authorized_v370/);
const teamGeneralStart = human.indexOf('function vp3_human_team_general_v370');
const teamGeneralEnd = human.indexOf('function vp3_human_messages_v370', teamGeneralStart);
assert.doesNotMatch(human.slice(teamGeneralStart, teamGeneralEnd), /INSERT INTO human_conversation_members/);

// Team ownership and billing are workspace/package driven, not a global Artist role.
assert.match(teamSubscription, /music_workspace_enabled_v320\(\$user\)/);
assert.match(teamSubscription, /artist_workspace_v104_is_artist\(\$user\)/);
assert.doesNotMatch(teamSubscription, /user_has_role\('artist',\$user\)/);
assert.match(teamSubscription, /u\.is_active=1/);
assert.doesNotMatch(memberNav, /user_has_role\('artist'/);

// Legacy Team Chat is a compatibility URL over canonical human messaging only.
assert.match(teamLegacy, /require __DIR__\.'\/team-chat-v320\.php'/);
assert.match(teamScoped, /vp3_human_shared_workspace_v370/);
assert.match(teamScoped, /human_messages/);
assert.match(teamScoped, /human_conversation_reads_v370/);
assert.match(teamScoped, /chat_settings_get_v237/);
assert.match(teamScoped, /social_chat_disabled/);
assert.match(teamScoped, /COALESCE\(p\.presence_mode,'online'\)='online'/);
assert.doesNotMatch(teamScoped, /team_direct_messages/);
assert.doesNotMatch(teamScoped, /WHERE\s+u\.role\s+IN/i);
assert.doesNotMatch(teamScoped, /user_has_role\(/);

// Public member profiles expose social actions without altering Profile Agent persistence.
assert.match(profileSocialApi, /vp3_social_relationship_state_v320/);
assert.match(profileSocialApi, /csrf_token\(\)/);
for (const action of ['Follow','Add Friend','Accept Friend','Message']) assert.match(profileSocialJs, new RegExp(action));
assert.match(memberNav, /'plugins','Plugins'/);
assert.match(memberNav, /'messages','Messages'/);
assert.match(memberNav, /music_workspace_enabled_v320/);
assert.match(memberNav, /music_workspace_resources_v330_accessible_workspaces/);
assert.match(memberNav, /\$musicEnabled\|\|\$musicWorkspaces/);

// Music portal now uses capability/workspace-native member routes; Stem Studio remains track-scoped.
assert.doesNotMatch(musicPage, /stem-studio\.php/);
assert.doesNotMatch(musicPage, /admin\/artist\.php\?collection=/);
assert.match(musicPage, /music-library\.php\?workspace=/);
assert.match(musicPage, /music-releases\.php\?workspace=/);
assert.match(musicPage, /music_workspace_resources_v330_resolve_active/);
assert.match(musicPage, /player\.php/);
assert.match(musicPage, /\$canListening=has_permission\('artist_listening\.access'/);
assert.match(resources, /professional Music resources belong to this workspace|Professional music catalog/);

// Fresh setup and canonical upgrade normalize current architecture without destructive replacement.
assert.match(setup, /artist_workspace_v104_ensure_schema\(\)/);
assert.match(setup, /vp3_plugin_ensure_schema_v320\(\$pdo\)/);
assert.match(setup, /vp3_social_ensure_schema_v320\(\$pdo\)/);
assert.match(setup, /vp3_human_messaging_v370_ensure_schema\(\$pdo\)/);
assert.match(setup, /vp3_human_messaging_v370_migrate_legacy\(\$pdo\)/);
assert.match(setup, /music_workspace_release_schema_v330_ensure\(\$pdo\)/);
assert.match(setup, /music_workspace_resources_v330_ensure_schema\(\$pdo\)/);
assert.match(upgrade, /vp3_plugin_ensure_schema_v320\(\)/);
assert.match(upgrade, /vp3_social_ensure_schema_v320\(\)/);
assert.match(upgrade, /vp3_human_messaging_v370_ready\(\)/);
assert.match(upgrade, /vp3_human_messaging_v370_migrate_legacy\(\$pdo\)/);
assert.match(upgrade, /music_workspace_release_schema_v330_ensure\(\$pdo\)/);
assert.match(upgrade, /music_workspace_resources_v330_ensure_schema\(\$pdo\)/);
assert.match(upgrade, /existing user content/);
assert.match(upgrade, /Existing accounts, package assignments, team memberships, token balances, music content/);

// Repository documentation must continue to state the same product/security model as runtime.
for (const statement of [
  'VP3 is the personal AI, transcription, identity and personal-URL platform',
  'Music is a professional capability layer',
  'Team membership is a resource relationship, not an account type',
  'Each workspace has one canonical General conversation',
  'Human messages must not be stored as Agent Chat messages',
  'Package changes do not mutate identity or Team relationships',
  'Plugin disablement preserves user data',
  'legacy Team Chat endpoint is a compatibility adapter',
]) assert.match(architecture, new RegExp(statement.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i'));

console.log('VP3_SOCIAL_MUSIC_ARCHITECTURE_V320=PASS');
