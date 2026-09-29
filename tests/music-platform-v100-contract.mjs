import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=path=>fs.readFileSync(new URL('../'+path,import.meta.url),'utf8');
const artist=read('includes/music-artist-v100.php');
const bootstrap=read('includes/bootstrap.php');
const setup=read('setup.php');
const upgrade=read('upgrade.php');
const workspace=read('includes/artist-workspace-v181.php');
const resources=read('includes/music-workspace-resources-v330.php');
const workflow=read('.github/workflows/team-workspaces-v350.yml');

for(const table of ['music_artists_v100','music_artist_memberships_v100','music_artist_authority_events_v100']){
  assert.match(artist,new RegExp('CREATE TABLE IF NOT EXISTS '+table));
}
assert.match(artist,/workspace_id BIGINT UNSIGNED NOT NULL/);
assert.match(artist,/FOREIGN KEY \(workspace_id\) REFERENCES artist_workspaces_v181\(id\)/);
assert.match(artist,/FOREIGN KEY \(user_id\) REFERENCES users\(id\)/);
assert.match(artist,/UNIQUE KEY uq_music_artist_slug_v100 \(slug\)/);
assert.match(artist,/is_primary TINYINT\(1\) NOT NULL DEFAULT 0/);
assert.match(artist,/verification_status VARCHAR\(30\) NOT NULL DEFAULT 'unverified'/);

// Artist identity must not be a second login identity.
assert.doesNotMatch(artist,/password_hash|CREATE TABLE IF NOT EXISTS music_artist_users/i);
assert.match(artist,/Artist identity is intentionally independent from login identity/);

// The mature workspace remains the outer authority boundary.
const canAccess=artist.match(/function music_artist_v100_can_access[\s\S]*?\n}/)?.[0]||'';
assert.match(canAccess,/music_workspace_resources_v330_can_access/);
assert.match(canAccess,/music_artist_v100_effective_role/);
const canManage=artist.match(/function music_artist_v100_can_manage[\s\S]*?\n}/)?.[0]||'';
assert.match(canManage,/music_workspace_resources_v330_can_access/);
assert.match(canManage,/music_artist_v100_role_capabilities/);

// Existing one-owner workspace contract is preserved, not destructively rewritten.
assert.match(workspace,/UNIQUE KEY uq_artist_workspace_user \(artist_user_id\)/);
assert.doesNotMatch(artist,/DROP (?:INDEX|KEY) uq_artist_workspace_user/i);
assert.match(artist,/music_artist_v100_migrate_existing_workspaces/);
assert.match(artist,/music_artist_v100_seed_primary_for_workspace/);
assert.match(artist,/source.*artist_workspaces_v181/s);

// Primary artist preserves existing Manager/Producer access. Secondary artists
// require explicit artist membership.
const effective=artist.match(/function music_artist_v100_effective_role[\s\S]*?\n}/)?.[0]||'';
assert.match(effective,/is_primary/);
assert.match(effective,/music_workspace_resources_v330_member_role/);
assert.match(effective,/music_artist_v100_membership/);
assert.match(artist,/\['owner','manager','editor','producer','viewer'\]/);
assert.match(artist,/The Music Workspace owner is the canonical artist owner/);

// Artist creation is serialized under the existing workspace row and never
// bypasses the workspace capability system.
const create=artist.match(/function music_artist_v100_create[\s\S]*?\n}/)?.[0]||'';
assert.match(create,/music_workspace_resources_v330_can_manage\(\$pdo,\$workspaceId,'profile',\$user\)/);
assert.match(create,/FOR UPDATE/);
assert.match(create,/music_artist_v100_unique_slug/);
assert.match(create,/music_artist_v100_upsert_membership/);

// Setup/upgrade load the new schema only after mature workspace ownership exists.
assert.match(bootstrap,/music-artist-v100\.php/);
for(const file of [setup,upgrade]){
  const workspaceIndex=file.indexOf('music_workspace_resources_v330_ensure_schema($pdo)');
  const artistIndex=file.indexOf('music_artist_v100_ensure_schema($pdo)');
  assert.ok(workspaceIndex>=0&&artistIndex>workspaceIndex,'Artist identity must install after Music Workspace ownership');
}

// Existing workspace authorization stays in place.
assert.match(resources,/function music_workspace_resources_v330_can_access/);

// CI remains consolidated in the existing Team/Workspace authority workflow.
assert.match(workflow,/music-platform-v100-contract\.mjs/);
assert.match(workflow,/music-platform-v100-mysql\.php/);

console.log('MUSIC_PLATFORM_V100_SECTION1_CONTRACT=PASS');
