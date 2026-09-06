import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const selector = read('admin/team-workspaces.php');
const manager = read('admin/team-workspace.php');
const producer = read('admin/producer-tracks.php');
const users = read('admin/users.php');
const team = read('admin/team.php');
const domain = read('includes/artist-workspaces-v104.php');

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

assert.ok(!producer.includes("require_permission('producer.access')"), 'Producer workspace must no longer depend on a global Producer account type');
assert.ok(producer.includes('artist_workspace_v104_memberships_for_user'), 'Producer access must recognize Team relationships');
assert.ok(producer.includes('WHERE t.producer_user_id=?'), 'Producer track reads must remain explicitly assigned to the current user');
assert.ok(producer.includes('stem_editor.access'), 'Stem Editor commercial entitlement must remain separate from Producer relationship authority');

assert.ok(users.includes('workspace_artist'), 'Admin Users must expose Artist workspace identity separately from package');
assert.ok(users.includes("['admin','artist','manager','producer']"), 'Admin user saves must remove obsolete global Team roles before rebuilding identity');
assert.ok(users.includes("if($workspaceArtist)$roles[]='artist'"), 'Artist identity must be explicitly assigned by Admin');
assert.ok(users.includes('Package controls commercial feature access and capacity'), 'Admin UI must distinguish package from identity');
assert.ok(users.includes('Manager/Producer never appear here'), 'Manager/Producer assignment must stay in Team');

assert.ok(team.includes('artist_workspace_v104_attach_member'), 'Team must create relationship-scoped roles');
assert.ok(team.includes('artist_workspace_v104_detach_member'), 'Team removal must detach the relationship');
assert.ok(!team.includes('DELETE FROM users'), 'Team removal must preserve the account');
assert.ok(domain.includes('PRIMARY KEY (artist_user_id,member_user_id)'), 'membership identity must be Artist + member, supporting multi-Artist collaboration');

console.log('TEAM_WORKSPACE_SCOPE_CONTRACT=PASS');
