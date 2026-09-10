import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const resources = read('includes/music-workspace-resources-v330.php');
const runtime = read('includes/music-workspace-resources-v331.php');
const releaseSchema = read('includes/music-workspace-release-schema-v330.php');
const releaseMigration = read('upgrade-stonefellow-v105.sql');
const permissions = read('includes/permissions.php');
const artistMusic = read('includes/artist-music-v185.php');
const memberNav = read('includes/member-navigation.php');
const workspacePage = read('music-workspace.php');
const libraryPage = read('music-library.php');
const studioPage = read('music-studio.php');
const releasesPage = read('music-releases.php');
const setup = read('setup.php');
const upgrade = read('upgrade.php');

// Professional resources must have an explicit workspace boundary.
for (const table of ['tracks','albums','track_projects','track_stems','shows','release_plans','release_items','track_credits']) {
  assert.match(resources, new RegExp(`['\"]${table}['\"]\\s*=>\\s*['\"]workspace_id['\"]`), `${table} must be workspace-scoped`);
}
assert.match(resources, /artist_workspace_id BIGINT UNSIGNED NULL/);
assert.match(resources, /personal playlists are core user data/i);
assert.doesNotMatch(resources, /ALTER TABLE (?:track_favorites|album_favorites|track_play_sessions) ADD COLUMN workspace_id/i, 'personal listening data must stay user-owned');

// Legacy ownership is backfilled, while v181 source links win on disagreement.
assert.match(resources, /UPDATE tracks t INNER JOIN artist_workspaces_v181 w ON w\.artist_user_id=t\.owner_user_id SET t\.workspace_id=w\.id/);
assert.match(resources, /INNER JOIN artist_catalog_tracks_v181 c ON c\.source_track_id=t\.id SET t\.workspace_id=c\.workspace_id/);
assert.match(resources, /UPDATE track_projects p INNER JOIN tracks t ON t\.id=p\.track_id SET p\.workspace_id=t\.workspace_id/);
assert.match(resources, /UPDATE track_stems s INNER JOIN tracks t ON t\.id=s\.track_id SET s\.workspace_id=t\.workspace_id/);
assert.match(runtime, /music_workspace_resources_v331_sync_track_graph/);
assert.match(runtime, /register_shutdown_function/);

// A disabled owner plugin pauses workspace access without deleting the workspace.
assert.match(resources, /function music_workspace_resources_v330_workspace_enabled/);
assert.match(resources, /music_workspace_enabled_v320\(\$owner\)/);
const canAccess = resources.match(/function music_workspace_resources_v330_can_access[\s\S]*?\n}/)?.[0] || '';
assert.match(canAccess, /music_workspace_resources_v330_workspace_enabled/);
assert.match(canAccess, /music_workspace_resources_v330_member_role/);
assert.doesNotMatch(canAccess, /DELETE\s+FROM/i);
const accessible = resources.match(/function music_workspace_resources_v330_accessible_workspaces[\s\S]*?\n}/)?.[0] || '';
assert.match(accessible, /array_filter/);
assert.match(accessible, /music_workspace_resources_v330_workspace_enabled/);

// Owner, Manager and Producer authority is contextual; Producer access is track-assignment scoped.
const canManage = resources.match(/function music_workspace_resources_v330_can_manage[\s\S]*?\n}/)?.[0] || '';
assert.match(canManage, /\$role==='owner'/);
assert.match(canManage, /\$role==='manager'/);
assert.match(canManage, /\$role==='producer'/);
assert.match(canManage, /music_workspace_resources_v330_can_access/);
const canManageTrack = resources.match(/function music_workspace_resources_v330_can_manage_track[\s\S]*?\n}/)?.[0] || '';
assert.match(canManageTrack, /\$role==='producer'/);
assert.match(canManageTrack, /producer_user_id/);
assert.match(canManageTrack, /music_workspace_resources_v330_can_access/);
assert.doesNotMatch(canManageTrack, /has_permission\('tracks\.manage'/, 'workspace tracks must not fall back to a global track permission');

// The mature production helper delegates workspace-owned tracks to the workspace guard first.
const productionGuard = permissions.match(/function can_manage_track_production[\s\S]*?\n}/)?.[0] || '';
assert.match(productionGuard, /music_workspace_resources_v330_track_workspace_id/);
assert.match(productionGuard, /music_workspace_resources_v330_can_manage_track/);
assert.ok(productionGuard.indexOf('music_workspace_resources_v330_can_manage_track') < productionGuard.indexOf("has_permission('tracks.manage'"), 'workspace authorization must run before legacy global permissions');
assert.match(permissions, /workspaceSelect/);

// Music Library validates every professional catalog mutation against workspace_id.
assert.match(libraryPage, /music_workspace_resources_v330_resolve_active/);
assert.match(libraryPage, /artist_music_v185_track\(\$pdo,\$workspaceId/);
assert.match(libraryPage, /DELETE FROM artist_catalog_tracks_v181 WHERE id=\? AND workspace_id=\?/);
assert.match(libraryPage, /UPDATE artist_catalog_tracks_v181[\s\S]*WHERE id=\? AND workspace_id=\?/);
assert.match(libraryPage, /UPDATE artist_catalog_albums_v181[\s\S]*WHERE id=\? AND workspace_id=\?/);
assert.match(libraryPage, /producer_user_id=\?/);
assert.match(libraryPage, /music-studio\.php\?workspace=/);

// Studio entry resolves a workspace track and uses the strict preparation guard.
// A Producer may open only an existing backing track explicitly assigned to them;
// they cannot self-assign a catalog track by guessing a route id.
const studioPrep = runtime.match(/function music_workspace_resources_v331_prepare_studio_track[\s\S]*?\n}/)?.[0] || '';
assert.match(studioPrep, /music_workspace_resources_v330_can_access/);
assert.match(studioPrep, /\$role==='producer'/);
assert.match(studioPrep, /\$sourceId<1/);
assert.match(studioPrep, /producer_user_id=\?/);
assert.match(studioPrep, /music_workspace_resources_v330_can_manage_track/);
assert.match(studioPrep, /music_workspace_resources_v330_ensure_production_track/);
assert.match(studioPage, /music_workspace_resources_v330_resolve_active/);
assert.match(studioPage, /music_workspace_resources_v331_prepare_studio_track/);
assert.doesNotMatch(studioPage, /music_workspace_resources_v330_ensure_production_track/);
assert.match(studioPage, /admin\/stems\.php\?track=/);
assert.doesNotMatch(studioPage, /owner_user_id\s*===|user_has_role\('artist'/);

// Release plans and tasks are scoped on every read/write/delete path. The PHP installer
// deliberately executes the canonical v105 SQL instead of duplicating that schema.
assert.match(releaseSchema, /upgrade-stonefellow-v105\.sql/);
assert.match(releaseSchema, /music_workspace_release_schema_v330_ready/);
assert.match(releaseMigration, /CREATE TABLE IF NOT EXISTS release_plans/);
assert.match(releaseMigration, /CREATE TABLE IF NOT EXISTS release_items/);
assert.match(releaseMigration, /CREATE TABLE IF NOT EXISTS track_credits/);
assert.match(releaseSchema, /music_workspace_release_schema_v330_cleanup_context_roles/);
assert.match(releaseSchema, /artist_workspace_v104_sync_context_role_permissions/);
assert.match(releaseSchema, /role IN \('manager','producer'\)/);
assert.ok(releaseSchema.lastIndexOf('music_workspace_release_schema_v330_cleanup_context_roles($pdo)') > releaseSchema.indexOf('$pdo->exec($statement)'), 'legacy Manager/Producer global grants must be removed after v105 SQL executes');
assert.match(runtime, /INSERT INTO release_plans \(workspace_id,owner_user_id/);
assert.match(runtime, /UPDATE release_plans[\s\S]*WHERE id=\? AND workspace_id=\?/);
assert.match(runtime, /SELECT \* FROM release_items WHERE release_id=\? AND workspace_id=\?/);
assert.match(runtime, /INSERT INTO release_items \(workspace_id,release_id/);
assert.match(runtime, /DELETE FROM release_plans WHERE id=\? AND workspace_id=\?/);
assert.match(releasesPage, /music_workspace_resources_v330_resolve_active/);
assert.match(releasesPage, /workspace_id/);

// Protected draft/private catalog media recognizes current workspace membership, not a global Artist role.
assert.match(artistMusic, /function artist_music_v185_workspace_viewer_can_access/);
assert.match(artistMusic, /music_workspace_resources_v330_can_access/);
assert.match(artistMusic, /function artist_music_v185_audio_is_referenced/);
assert.match(artistMusic, /register_shutdown_function/);

// Collaborators can discover the workspace even when they do not own the plugin entitlement themselves.
assert.match(memberNav, /music_workspace_resources_v330_accessible_workspaces/);
assert.match(memberNav, /\$musicEnabled\|\|\$musicWorkspaces/);
assert.match(workspacePage, /Enable Music Workspace or join a Music Workspace Team/);
assert.match(workspacePage, /music_workspace_resources_v330_accessible_workspaces/);

// Fresh install and upgrade must install release prerequisites before adding workspace ownership columns.
for (const file of [setup, upgrade]) {
  const releaseIndex = file.indexOf('music_workspace_release_schema_v330_ensure($pdo)');
  const ownershipIndex = file.indexOf('music_workspace_resources_v330_ensure_schema($pdo)');
  assert.ok(releaseIndex >= 0, 'release schema installer missing');
  assert.ok(ownershipIndex > releaseIndex, 'release schema must exist before workspace ownership migration');
}

console.log('MUSIC_WORKSPACE_RESOURCE_OWNERSHIP_V330=PASS');
