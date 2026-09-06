import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const selector = read('admin/team-workspaces.php');
const manager = read('admin/team-workspace.php');
const producer = read('admin/producer-tracks.php');
const users = read('admin/users.php');
const team = read('admin/team.php');
const domain = read('includes/artist-workspaces-v104.php');
const gates = read('includes/subscription-request-gates.php');
const teamChatApi = read('api/team-chat-v109.php');
const teamChatWidget = read('includes/team-chat-widget-v81.php');

assert.ok(selector.includes('artist_workspace_v104_memberships_for_user'), 'Team selector must derive workspaces from relationships');
assert.ok(selector.includes("$role==='manager'"), 'Manager memberships need a scoped Manager destination');
assert.ok(selector.includes("$role==='producer'"), 'Producer memberships need a scoped production destination');

assert.ok(manager.includes('artist_workspace_v104_membership'), 'Manager workspace must verify the selected Artist relationship');
assert.ok(manager.includes("$teamRole!=='manager'"), 'non-Manager Team members must not enter Manager workspace');
assert.ok(manager.includes('artist_workspace_v104_can_manage'), 'section authority must be relationship-scoped');
assert.ok(manager.includes("WHERE id=? AND workspace_id=?"), 'record mutations must include workspace_id ownership checks');
assert.ok(manager.includes("WHERE workspace_id=?"), 'workspace reads must be workspace-scoped');
assert.ok(!manager.includes('DELETE FROM tracks WHERE'), 'Manager workspace must never fall back to the global Tracks table');
assert.ok(!manager.includes('UPDATE tracks SET'), 'Manager workspace must never mutate the global Tracks table');
for (const table of [
  'artist_catalog_tracks_v181',
  'artist_catalog_albums_v181',
  'artist_catalog_shows_v181',
  'artist_catalog_photos_v181',
  'artist_catalog_merch_v181',
  'artist_posts_v181',
  'artist_workspaces_v181',
]) {
  assert.ok(manager.includes(table), `Manager workspace should operate on private ${table}`);
}
assert.ok(manager.includes('artist_media_v182_store_photo'), 'Manager photo uploads must reuse the workspace-owned media service');
assert.ok(manager.includes('artist_media_v182_delete_owned_photo'), 'Manager photo deletion must validate workspace ownership');

assert.ok(!producer.includes("require_permission('producer.access')"), 'Producer workspace entry must be relationship/direct-assignment driven');
assert.ok(producer.includes('artist_workspace_v104_memberships_for_user'), 'Producer access must recognize Team relationships');
assert.ok(producer.includes('WHERE t.producer_user_id=?'), 'Producer track reads must remain explicitly assigned to the current user');
assert.ok(producer.includes('stem_editor.access'), 'Stem Editor commercial entitlement must remain separate from Producer relationship authority');

assert.ok(users.includes('workspace_artist'), 'Admin Users must expose Artist workspace identity separately from package');
assert.ok(users.includes("['admin','artist','manager','producer']"), 'Admin user saves must remove obsolete manually assigned Team roles before rebuilding identity');
assert.ok(users.includes("if($workspaceArtist)$roles[]='artist'"), 'Artist identity must be explicitly assigned by Admin');
assert.ok(users.includes('Package controls commercial feature access and capacity'), 'Admin UI must distinguish package from identity');
assert.ok(users.includes('Manager/Producer never appear here'), 'Manager/Producer assignment must stay in Team');

assert.ok(team.includes('artist_workspace_v104_attach_member'), 'Team must create relationship-scoped roles');
assert.ok(team.includes('artist_workspace_v104_detach_member'), 'Team removal must detach the relationship');
assert.ok(!team.includes('DELETE FROM users'), 'Team removal must preserve the account');
assert.ok(domain.includes('PRIMARY KEY (artist_user_id,member_user_id)'), 'membership identity must be Artist + member, supporting multi-Artist collaboration');
assert.ok(domain.includes('artist_workspace_v104_sync_context_role_permissions'), 'legacy role compatibility must be reduced to an explicit minimal permission set');
assert.ok(domain.includes('artist_workspace_v104_revoke_producer_assignments'), 'Producer membership removal or downgrade must revoke direct track assignments');
assert.ok(domain.includes('UPDATE tracks SET producer_user_id=NULL WHERE owner_user_id=? AND producer_user_id=?'), 'producer revocation must be scoped to the owning Artist and member');
assert.ok(gates.includes("$managerSafe=['/admin/team-workspaces.php','/admin/team-workspace.php']"), 'Manager compatibility marker must only pass relationship-scoped Admin routes');
assert.ok(gates.includes("$producerSafe=['/admin/producer-tracks.php','/admin/stems.php','/admin/stems-legacy-v108.php']"), 'Producer compatibility marker must only pass direct production Admin routes');

assert.ok(teamChatApi.includes('team_chat_v109_contextual_member'), 'Team Chat API must recognize contextual Artist Team membership');
assert.ok(teamChatApi.includes('FROM artist_team_members WHERE member_user_id=?'), 'Team Chat API eligibility must come from the Team relationship table');
assert.ok(teamChatApi.includes('WHERE atm.member_user_id=u.id'), 'Team Chat directory must include relationship-scoped Team members after base-role migration');
assert.ok(teamChatWidget.includes('artist_workspace_v104_memberships_for_user'), 'Team Chat widget must remain visible to contextual Managers and Producers');

console.log('TEAM_WORKSPACE_SCOPE_CONTRACT=PASS');
