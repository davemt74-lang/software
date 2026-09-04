import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = p => fs.readFileSync(p,'utf8');
const helper=read('includes/artist-music-v185.php');
const routing=read('includes/artist-admin-routing-v185.php');
const admin=read('admin/artist-music.php');
const audio=read('artist-track-audio.php');
const image=read('artist-music-image.php');
const profile=read('profile.php');
const profileRuntime=read('includes/profile-agent.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const deny=read('uploads/artist-music/.htaccess');
const workflow=read('.github/workflows/pr82-listening-recovery.yml');

for(const field of ['album_id','description','genre','duration_seconds','track_number','cover_photo_id']){
  assert.match(helper,new RegExp(`column_exists\\('artist_catalog_tracks_v181','${field}'\\)`),`track schema checks ${field}`);
}
for(const field of ['cover_photo_id','sort_order']) assert.match(helper,new RegExp(`column_exists\\('artist_catalog_albums_v181','${field}'\\)`));
assert.match(helper,/idx_artist_track_album_v185/);
assert.match(helper,/is_uploaded_file/);
assert.match(helper,/\['mp3','m4a','wav','ogg'\]/);
assert.match(helper,/finfo_open/);
assert.match(helper,/uploads\/artist-music\/'\.\$workspaceId/);
assert.match(helper,/move_uploaded_file/);
assert.match(helper,/WHERE id=\? AND workspace_id=\? LIMIT 1/);
assert.match(helper,/Choose a cover image from your own Media Library/);
assert.match(helper,/Choose an album from your artist workspace/);
assert.match(helper,/artist_user_id/);
assert.match(deny,/Require all denied|Deny from all/);

assert.match(admin,/user_has_role\('artist',\$user\)/);
assert.match(admin,/has_permission\('tracks\.manage',\$user\)/);
assert.match(admin,/has_permission\('albums\.manage',\$user\)/);
assert.match(admin,/verify_csrf\(\)/);
assert.match(admin,/name="audio_file"/);
assert.match(admin,/name="album_id"/);
assert.match(admin,/name="cover_photo_id"/);
assert.match(admin,/name="track_number"/);
assert.match(admin,/name="duration_seconds"/);
assert.match(admin,/name="genre"/);
assert.match(admin,/Publish on Artist Profile/);
assert.match(admin,/artist_media_v182_picker/);
assert.match(admin,/artist_music_v185_validate_album/);
assert.match(admin,/artist_music_v185_validate_photo/);
assert.match(admin,/DELETE FROM artist_catalog_tracks_v181 WHERE id=\? AND workspace_id=\?/);
assert.match(admin,/UPDATE artist_catalog_tracks_v181 SET .* WHERE id=\? AND workspace_id=\?/s);
assert.match(admin,/UPDATE artist_catalog_albums_v181 SET .* WHERE id=\? AND workspace_id=\?/s);
assert.match(admin,/UPDATE artist_catalog_tracks_v181 SET album_id=NULL,album='' WHERE workspace_id=\? AND album_id=\?/);
assert.doesNotMatch(admin,/name="workspace_id"/);
assert.doesNotMatch(admin,/name="audio_path"/);
assert.doesNotMatch(admin,/name="cover_path"/);
assert.match(admin,/VALUES \(\?,\?,\?,\?,''\,\?,\?,\?,\?\)/);

assert.match(audio,/artist_music_v185_public_track/);
assert.match(audio,/artist_music_v185_owned_path/);
assert.match(audio,/HTTP_RANGE/);
assert.match(audio,/Content-Range: bytes/);
assert.match(audio,/Accept-Ranges: bytes/);
assert.match(audio,/X-Content-Type-Options: nosniff/);
assert.match(image,/artist_media_v182_resolve_photo_file/);
assert.match(image,/artist_music_v185_can_view/);
assert.match(image,/cover_photo_id/);
assert.match(image,/X-Content-Type-Options: nosniff/);

// Public music is rendered by the canonical profile, with the v181 catalog retained behind it.
assert.match(profileRuntime,/artist_workspace_v181_public_records\(\$kind,\$viewer,\$limit,\$wid\)/);
assert.match(profile,/\$tracksByAlbum/);
assert.match(profile,/artist-track-audio\.php\?track=/);
assert.match(profile,/artist-music-image\.php\?type=album&id=/);
assert.match(profile,/<audio controls preload="none"/);
assert.match(profile,/Singles & Tracks/);
assert.match(profile,/track_number/);
assert.match(profile,/duration_seconds/);
assert.match(profile,/genre/);
assert.match(profile,/profile-album-cover/);
assert.match(profile,/profile-track-list/);

assert.match(routing,/admin\/tracks\.php/);
assert.match(routing,/artist-music\.php\?tab=tracks/);
assert.match(routing,/admin\/albums\.php/);
assert.match(routing,/artist-music\.php\?tab=albums/);
assert.match(routing,/admin\/artist\.php/);
assert.match(bootstrap,/artist-music-v185\.php/);
assert.match(bootstrap,/artist-admin-routing-v185\.php/);
assert.match(bootstrap,/artist_admin_routing_v185_apply\(\)/);
assert.match(upgrade,/artist_music_v185_schema_ready\(\)/);
assert.match(upgrade,/artist_music_v185_ensure_schema\(\)/);

assert.match(workflow,/Canonical Agent Chat voice architecture/,'workflow validates canonical Agent Chat voice architecture');
assert.match(workflow,/test -f chat-voice\.js/,'workflow requires canonical chat-voice.js');
assert.match(workflow,/test ! -e chat-voice-v142\.js/,'workflow rejects the superseded versioned Agent Chat controller');
assert.match(workflow,/test -f conversation-voice-v122\.js/,'workflow retains the active shared editor conversation controller during Section 1');
console.log('ARTIST_MUSIC_V185=PASS');