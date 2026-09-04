import fs from 'node:fs';
import assert from 'node:assert/strict';
const read = path => fs.readFileSync(path, 'utf8');
const helper = read('includes/artist-workspace-v181.php');
const upgrade = read('upgrade.php');
const header = read('includes/header.php');
const page = read('admin/artist.php');
const functions = read('includes/functions.php');
const player = read('includes/player-v76.php');
const chatEngine = read('includes/chat-engine.php');
const favoritesApi = read('api/favorites-v73.php');
const libraryApi = read('api/player-library-v76.php');
const chatLegacy = read('chat-legacy-v108.php');
const contentImage = read('content-image.php');
const legacyArtistProfile = read('artist-profile.php');
const canonicalProfile = read('profile.php');
const profileDomain = read('includes/profile-agent.php');
const sql = read('upgrade-stonefellow-v181-artist-workspace.sql');
for (const table of ['artist_workspaces_v181','artist_catalog_tracks_v181','artist_catalog_photos_v181','artist_catalog_merch_v181','artist_catalog_shows_v181','artist_catalog_albums_v181','artist_posts_v181','artist_release_plans_v181']) {
  assert.match(helper, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`));
  assert.match(sql, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`));
}
for (const table of ['artist_workspace_track_favorites_v181','artist_workspace_playlist_tracks_v181']) {
  assert.match(helper, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`));
  assert.match(sql, new RegExp(`CREATE TABLE IF NOT EXISTS ${table}`));
}
assert.match(helper, /artist_workspace_v181_migrate_legacy/);
assert.match(helper, /INSERT IGNORE INTO artist_catalog_tracks_v181/);
assert.match(helper, /INSERT IGNORE INTO artist_catalog_shows_v181/);
assert.match(helper, /INSERT IGNORE INTO artist_catalog_albums_v181/);
assert.match(helper, /INSERT IGNORE INTO artist_posts_v181/);
assert.match(helper, /INSERT IGNORE INTO artist_release_plans_v181/);
assert.match(helper, /artist_workspace_v181_scope_id/);
assert.match(helper, /artist_workspace_v181_public_records/);
assert.match(helper, /workspace_id=\?/);
assert.match(helper, /is_published=1/);
assert.match(helper, /can_view_visibility/);
assert.match(helper, /artist_workspace_v181_guard_legacy_admin/);
assert.match(helper, /chat_conversations ADD COLUMN artist_workspace_id/);
assert.match(upgrade, /artist_listening_v172_ensure_schema/);
assert.match(upgrade, /crm_v180_ensure_schema/);
assert.match(upgrade, /artist_workspace_v181_ensure_schema/);
assert.match(header, /Artist Admin/);
assert.match(page, /artist_catalog_tracks_v181/);
assert.match(page, /artist_catalog_shows_v181/);
assert.match(page, /artist_catalog_albums_v181/);
assert.match(page, /artist_posts_v181/);
assert.match(page, /artist_release_plans_v181/);
assert.match(page, /INSERT INTO \{\$table\}/);
assert.match(page, /UPDATE \{\$table\}/);
assert.match(page, /DELETE FROM \{\$table\}/);
assert.match(page, /workspace_id=\?/);
assert.doesNotMatch(page, /INSERT INTO tracks\b|INSERT INTO photos\b|INSERT INTO merch_items\b/);
assert.match(functions, /artist_workspace_v181_public_records\('tracks'/);
assert.match(functions, /artist_workspace_v181_public_records\('shows'/);
assert.doesNotMatch(functions, /if \(\$rows\) return array_map/);
assert.match(player, /artist_workspace_v181_public_records\('posts'/);
assert.match(chatEngine, /artist_workspace_v181_schema_ready/);
assert.match(chatEngine, /artist-profile:/);
assert.match(favoritesApi, /artist_workspace_track_favorites_v181/);
assert.match(favoritesApi, /1000000000/);
assert.match(libraryApi, /artist_workspace_playlist_tracks_v181/);
assert.match(libraryApi, /DELETE FROM artist_workspace_playlist_tracks_v181/);
assert.match(chatLegacy, /artist_workspace_track_favorites_v181/);
assert.match(chatLegacy, /artist_workspace_playlist_tracks_v181/);
assert.match(player, /artist_workspace_track_favorites_v181/);
assert.match(chatLegacy, /artist_workspace_v181_public_records\('albums'/);
assert.match(chatLegacy, /artist_workspace_v181_public_records\('merch'/);
assert.match(chatLegacy, /artist_workspace_v181_public_records\('photos'/);
assert.match(contentImage, /artist_catalog_photos_v181/);

// Public v181 catalog rendering moved into the canonical /stonefellow/{username} profile.
assert.match(legacyArtistProfile,/artist_workspace_v181_lookup_public/);
assert.match(legacyArtistProfile,/profile_public_url/);
assert.match(legacyArtistProfile,/301/);
assert.doesNotMatch(legacyArtistProfile,/artist_workspace_v181_public_records\('tracks'/);
assert.match(profileDomain,/function profile_public_artist_workspace/);
assert.match(profileDomain,/artist_workspace_v181_lookup_public/);
assert.match(profileDomain,/function profile_public_catalog/);
assert.match(profileDomain,/artist_workspace_v181_public_records\(\$kind,\$viewer,\$limit,\$wid\)/);
for (const kind of ['tracks','albums','shows','photos','merch','posts']) assert.match(profileDomain,new RegExp(`'${kind}'`));
assert.match(canonicalProfile,/artist-track-audio\.php\?track=/);
assert.match(canonicalProfile,/artist-music-image\.php\?type=album&id=/);
assert.match(canonicalProfile,/content-image\.php\?type=artist_photo&id=/);
assert.match(canonicalProfile,/artist-post-image\.php\?post=/);
assert.match(canonicalProfile,/profile-show-actions/);
assert.match(helper, /WHERE artist_user_id=\? LIMIT 1/);

for (const [path, collection] of [['admin/tracks.php','tracks'],['admin/albums.php','albums'],['admin/shows.php','shows'],['admin/photos.php','photos'],['admin/merch.php','merch'],['admin/posts.php','posts'],['admin/releases.php','releases']]) {
  assert.match(read(path), new RegExp(`artist_workspace_v181_guard_legacy_admin\\('${collection}'\\)`));
}
console.log('ARTIST_WORKSPACE_V181=PASS');