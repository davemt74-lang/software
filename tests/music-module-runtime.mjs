import fs from 'node:fs';
import assert from 'node:assert/strict';
import {runMusicListeningClientTests} from './music-listening-client-v1.mjs';
// Run the actual production emitter: pending starts, final pause deltas,
// stalled playback and late stopped-generation receipts remain runtime gates.
await runMusicListeningClientTests({silent: true});
const read=p=>fs.readFileSync(p,'utf8');
for(const route of ['music-library.php','admin/artist-music.php'])assert.match(read(route),/music_catalog_write\(\$pdo,\$workspaceId,\$user/,'Both editors must use the same writer');
assert.match(read('includes/music-catalog.php'),/music_catalog_transaction/);assert.match(read('includes/music-catalog.php'),/music_catalog_revision/);
assert.match(read('api/playback.php'),/COALESCE\(user_id,0\)=\? AND listener_hash=\?/);assert.match(read('api/playback.php'),/FOR UPDATE/);assert.match(read('api/playback.php'),/music_catalog_playback_track_id/);assert.match(read('api/playback.php'),/\$delta=min\(\$delta,\(float\)\$elapsed\)/);
assert.doesNotMatch(read('api/playback.php'),/\$action === 'ended' \|\|/,'Seeking to the end must not manufacture a completed listen');
assert.match(read('api/player-library-v76.php'),/music_catalog_playlist_tracks/);assert.match(read('api/player-library-v76.php'),/MAX\(sort_order\).*artist_workspace_playlist_tracks_v181/);
assert.match(read('admin/artist.php'),/no changes were applied/);assert.match(read('admin/artist-media.php'),/music_catalog_delete_photo/);
assert.match(read('content-image.php'),/music_workspace_resources_v330_can_manage/);
assert.match(read('music-library.php'),/music_song_catalog_editor_v1/);
assert.match(read('includes/music-song-metadata-form-v1.php'),/name="cover_photo_id"/);
for(const route of ['media.php','artist-track-audio.php','artist-music-image.php'])assert.match(read(route),/music_media_send/);
console.log('MUSIC_MODULE_RUNTIME=PASS');
