import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const helper = read('includes/artist-workspace-v181.php');
const media = read('includes/artist-media-v182.php');
const sql = read('upgrade-stonefellow-v181-artist-workspace.sql');
const upgrade = read('upgrade.php');
const admin = read('admin/artist.php');
const legacyProfile = read('artist-profile.php');
const canonicalProfile = read('profile.php');
const profileRuntime = read('includes/profile-agent.php');
const image = read('artist-profile-image.php');
const contentImage = read('content-image.php');
const header = read('includes/header.php');
const chat = read('chat-legacy-v108.php');
const account = read('account.php');
const adminHeader = read('admin/_header.php');
const workflow = read('.github/workflows/pr82-listening-recovery.yml');
const deny = read('uploads/artist-profiles/.htaccess');

const profileFields = ['profile_slug','bio','profile_image_path','cover_image_path','website_url','instagram_url','tiktok_url','youtube_url','spotify_url','apple_music_url'];
for (const field of profileFields) {
  assert.match(helper, new RegExp(`column_exists\\('artist_workspaces_v181', '${field}'\\)`));
  assert.match(sql, new RegExp(field));
}
assert.match(helper, /UNIQUE KEY uq_artist_workspace_profile_slug \(profile_slug\)/);
assert.match(sql, /UNIQUE (?:KEY|INDEX).*uq_artist_workspace_profile_slug \(profile_slug\)/);
assert.match(helper, /artist_workspace_v181_lookup_public/);
assert.match(helper, /WHERE profile_slug=\? LIMIT 1/);
assert.match(helper, /WHERE artist_user_id=\? LIMIT 1/);
assert.match(helper, /profile_slug=\? AND id<>\?/);
assert.match(upgrade, /artist_workspace_v181_ensure_schema\(\)/);
assert.doesNotMatch(upgrade, /artist_workspace_v181_migrate_legacy\(/);

// Legacy artist URLs are compatibility-only. The canonical public renderer is profile.php.
assert.match(legacyProfile,/artist_workspace_v181_lookup_public/);
assert.match(legacyProfile,/profile_migrate_artist_identity/);
assert.match(legacyProfile,/profile_public_url/);
assert.match(legacyProfile,/header\('Location: '\.profile_public_url\(\(string\)\$profile\['username'\]\),true,301\)/);
assert.doesNotMatch(legacyProfile,/artist_workspace_v181_public_records/);

assert.match(canonicalProfile,/profile_by_username/);
assert.match(canonicalProfile,/profile_public_catalog/);
assert.match(canonicalProfile,/profile_active_agent/);
assert.match(canonicalProfile,/artist-profile-image\.php/);
assert.match(canonicalProfile,/cover_image_path/);
assert.match(canonicalProfile,/profile_image_path/);
assert.match(canonicalProfile,/FILTER_VALIDATE_URL/);
assert.match(canonicalProfile,/noopener noreferrer nofollow/);
for (const tab of ['music','shows','posts','photos','merch']) {
  assert.match(canonicalProfile,new RegExp(`'${tab}'`));
}
for (const kind of ['tracks','albums','shows','photos','posts','merch']) {
  assert.match(profileRuntime,new RegExp(`'${kind}'`));
}
assert.match(profileRuntime,/artist_workspace_v181_public_records/);
assert.match(profileRuntime,/function profile_public_artist_workspace/);
assert.match(profileRuntime,/function profile_public_catalog/);

assert.doesNotMatch(admin, /artist_workspace_v181_migrate_legacy\(/);
assert.match(admin, /name="profile_slug"/);
assert.match(admin, /name="bio"/);
assert.match(admin, /\['profile'=>'Profile image','cover'=>'Cover image'\]/);
assert.match(admin, /name="<\?= \$kind \?>_image"/);
assert.match(admin, /name="<\?= \$kind \?>_photo_id"/);
for (const field of ['website_url','instagram_url','tiktok_url','youtube_url','spotify_url','apple_music_url']) assert.match(admin, new RegExp(`'${field}'`));
assert.match(admin, /enctype="multipart\/form-data"/);
assert.match(admin, /View Artist Profile/);
assert.match(admin, /new URL\(i\.value,window\.location\.origin\)\.href/);
assert.match(admin, /WHERE id=\? AND artist_user_id=\?/);
assert.doesNotMatch(admin, /name="workspace_id"/);
assert.match(admin, /profile_slug=\? AND id<>\?/);
assert.match(admin, /if\(\$newProfile!==''\)artist_v181_remove_owned_image\(\$workspaceId,\$newProfile\)/);
assert.match(admin, /if\(\$newCover!==''\)artist_v181_remove_owned_image\(\$workspaceId,\$newCover\)/);
assert.match(admin, /function artist_v181_copy_library_photo/);
assert.match(admin, /artist_media_v182_copy_photo_to_profile/);
assert.match(admin, /artist_media_v182_picker/);
assert.match(admin, /Choose from My Photos/);
assert.match(admin, /\$newProfile==='' && \(int\)\(\$_POST\['profile_photo_id'\]/);
assert.match(admin, /\$newCover==='' && \(int\)\(\$_POST\['cover_photo_id'\]/);
assert.match(admin, /content-image\.php\?type=artist_photo&id=/);
assert.match(media, /WHERE id=\? AND workspace_id=\? LIMIT 1/);
assert.match(media, /uploads\/artist-media/);
assert.match(media, /uploads\/artist-profiles/);

assert.match(helper, /FILTER_VALIDATE_URL/);
assert.match(helper, /\['https','http'\]/);
assert.match(helper, /is_uploaded_file/);
assert.match(helper, /getimagesize/);
assert.match(helper, /image\/jpeg/);
assert.match(helper, /image\/png/);
assert.match(helper, /image\/webp/);
assert.match(helper, /uploads\/artist-profiles\/'\.\$workspaceId/);
assert.match(helper, /realpath/);
assert.match(deny, /denied|Deny from all/i);
assert.match(image, /artist_workspace_v181_owned_image_path/);
assert.match(image, /X-Content-Type-Options: nosniff/);
assert.match(contentImage, /\$isArtistWorkspaceAsset = true/);
assert.match(contentImage, /workspace_id/);
assert.match(contentImage, /artist_workspace_v181_scope_id\(\$user\) === \(int\)\(\$item\['workspace_id'\]/);

// Existing navigation remains discoverable and is allowed to pass through the compatibility redirect.
assert.match(header, /Artist Admin/);
assert.match(header, /View Artist Profile/);
assert.match(helper, /artist-profile\.php\?user_id=' \. \(int\)\$user\['id'\]/);
assert.match(helper, /artist_workspace_v181_profile_url\(\$workspace\)/);
assert.match(helper, /function artist_workspace_v181_profile_url_for_user\(/);
assert.match(helper, /user_has_role\('artist', \$user\)/);
assert.match(header, /\$headerArtistProfileUrl = artist_workspace_v181_profile_url_for_user\(\$headerUser\)/);
assert.match(chat, /\$chatArtistProfileUrl = artist_workspace_v181_profile_url_for_user\(\$user\)/);
assert.match(account, /\$accountArtistProfileUrl = artist_workspace_v181_profile_url_for_user\(\$user\)/);
assert.match(adminHeader, /\$adminArtistProfileUrl = artist_workspace_v181_profile_url_for_user\(\$user\)/);

assert.match(workflow,/Canonical Agent Chat voice architecture/,'workflow validates canonical Agent Chat voice architecture');
assert.match(workflow,/test -f chat-voice\.js/,'workflow requires canonical chat-voice.js');
assert.match(workflow,/test ! -e chat-voice-v142\.js/,'workflow rejects the superseded versioned Agent Chat controller');
assert.match(workflow,/test -f conversation-voice-v122\.js/,'workflow retains the active shared editor conversation controller during Section 1');
console.log('ARTIST_PROFILE_V181=PASS');