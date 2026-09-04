import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=path=>fs.readFileSync(path,'utf8');
const media=read('admin/artist-media.php'),mediaRuntime=read('includes/artist-media-v182.php'),posts=read('admin/artist-posts.php'),postRuntime=read('includes/artist-posts-v183.php'),shows=read('admin/artist-shows.php'),showRuntime=read('includes/artist-shows-v184.php'),music=read('admin/artist-music.php'),musicRuntime=read('includes/artist-music-v185.php'),routing=read('includes/artist-admin-routing-v185.php'),adminFooter=read('admin/_footer.php'),adminNav=read('admin/artist-cms-nav-v186.js'),legacyProfile=read('artist-profile.php'),canonicalProfile=read('profile.php'),profileDomain=read('includes/profile-agent.php'),header=read('includes/header.php'),bootstrap=read('includes/bootstrap.php'),upgrade=read('upgrade.php'),workflow=read('.github/workflows/pr82-listening-recovery.yml');

// Artist CMS storage and ownership remain canonical.
assert.match(media,/user_has_role\('artist',\$user\)/);assert.match(media,/artist_media_v182_store_photo/);assert.match(media,/WHERE id=\? AND workspace_id=\?/);assert.match(media,/enctype="multipart\/form-data"/);assert.match(mediaRuntime,/is_uploaded_file/);assert.match(mediaRuntime,/getimagesize/);assert.match(mediaRuntime,/image\/jpeg/);assert.match(mediaRuntime,/image\/png/);assert.match(mediaRuntime,/image\/webp/);assert.match(mediaRuntime,/uploads\/artist-media\/.*workspaceId/s);
assert.match(posts,/artist_posts_v183_ensure_schema/);assert.match(posts,/artist_media_v182_picker/);assert.match(posts,/image_photo_id/);assert.match(posts,/WHERE id=\? AND workspace_id=\?/);assert.match(posts,/is_published/);assert.match(postRuntime,/artist_posts_v183_public_image/);assert.match(postRuntime,/INNER JOIN artist_catalog_photos_v181/);assert.match(postRuntime,/workspace_id/);
assert.match(shows,/artist_shows_v184_ensure_schema/);assert.match(shows,/Upcoming/);assert.match(shows,/Past/);assert.match(shows,/Draft/);assert.match(shows,/show_status/);assert.match(shows,/WHERE id=\? AND workspace_id=\?/);assert.match(showRuntime,/scheduled/);assert.match(showRuntime,/postponed/);assert.match(showRuntime,/cancelled/);assert.match(showRuntime,/show_date>=NOW\(\)/);
assert.match(music,/name="audio_file"/);assert.match(music,/artist_music_v185_store_audio/);assert.match(music,/artist_music_v185_validate_album/);assert.match(music,/artist_music_v185_validate_photo/);assert.match(music,/name="track_number"/);assert.match(music,/name="duration_seconds"/);assert.match(music,/name="genre"/);assert.match(music,/WHERE id=\? AND workspace_id=\?/);assert.match(musicRuntime,/\['mp3','m4a','wav','ogg'\]/);assert.match(musicRuntime,/finfo_open/);assert.match(musicRuntime,/uploads\/artist-music/);assert.match(routing,/artist-music\.php\?tab=tracks/);assert.match(routing,/artist-music\.php\?tab=albums/);

// Unified Artist Admin shell stays discoverable.
assert.match(adminFooter,/STONEFELLOW_ARTIST_CMS_V186/);assert.match(adminFooter,/artist_workspace_v181_profile_url/);assert.match(adminFooter,/artist-cms-nav-v186\.js/);assert.match(adminNav,/admin\/tracks\.php/);assert.match(adminNav,/admin\/albums\.php/);assert.match(adminNav,/textContent='Music'/);assert.match(adminNav,/albums\.remove\(\)/);assert.match(adminNav,/View Artist Profile/);

// v181 data remains the source; the legacy renderer is compatibility-only.
assert.match(legacyProfile,/artist_workspace_v181_lookup_public/);assert.match(legacyProfile,/profile_migrate_artist_identity/);assert.match(legacyProfile,/profile_public_url/);assert.match(legacyProfile,/301/);assert.ok(legacyProfile.length<1800);
assert.match(profileDomain,/function profile_public_catalog/);assert.match(profileDomain,/artist_workspace_v181_public_records/);for(const kind of ['tracks','albums','shows','photos','merch','posts'])assert.match(profileDomain,new RegExp(`'${kind}'`));

// Canonical profile retains the complete Artist public surface plus Profile Agent.
assert.match(canonicalProfile,/\['music'=>'Music','shows'=>'Shows','photos'=>'Photos','posts'=>'Posts','merch'=>'Merch','about'=>'About'\]/);
assert.match(canonicalProfile,/data-profile-tab/);assert.match(canonicalProfile,/data-profile-panel/);
assert.match(canonicalProfile,/artist-profile-image\.php/);
assert.match(canonicalProfile,/artist-music-image\.php\?type=album&id=/);
assert.match(canonicalProfile,/artist-track-audio\.php\?track=/);
assert.match(canonicalProfile,/<audio controls preload="none"/);
assert.match(canonicalProfile,/content-image\.php\?type=artist_photo&id=/);
assert.match(canonicalProfile,/artist-post-image\.php\?post=/);
assert.match(canonicalProfile,/No upcoming published shows yet/);
assert.match(canonicalProfile,/Open media ↗/);
assert.match(canonicalProfile,/Singles & Tracks/);
assert.match(canonicalProfile,/Profile Agent/);
assert.match(canonicalProfile,/profile-agent\.js/);
assert.match(canonicalProfile,/\$catalogViewer=\$preview\?null:\$viewer/);
assert.match(canonicalProfile,/Conversation sending is disabled here/);
assert.match(profileDomain,/STONEFELLOW_PROFILE_NAMESPACE\s*=\s*'stonefellow'/);

assert.match(header,/Artist Admin/);assert.match(header,/View Artist Profile/);assert.match(header,/artist_workspace_v181_profile_url/);
for(const runtime of ['artist-media-v182.php','artist-posts-v183.php','artist-shows-v184.php','artist-music-v185.php','profile-agent.php'])assert.match(bootstrap,new RegExp(runtime.replaceAll('.','\\.')));
for(const gate of ['artist_media_v182','artist_posts_v183','artist_shows_v184','artist_music_v185']){assert.match(upgrade,new RegExp(`${gate}_schema_ready\\(\\)`));assert.match(upgrade,new RegExp(`${gate}_ensure_schema\\(\\)`));}
assert.match(upgrade,/profile_agent_schema_ready\(\)/);assert.match(upgrade,/profile_agent_ensure_schema\(\)/);assert.doesNotMatch(upgrade,/artist_workspace_v181_migrate_legacy\(/);
assert.match(workflow,/Canonical Agent Chat voice architecture/);assert.match(workflow,/test -f chat-voice\.js/);assert.match(workflow,/test ! -e chat-voice-v142\.js/);assert.match(workflow,/test -f conversation-voice-v122\.js/);
console.log('ARTIST_CMS_V186=PASS');