import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(p,'utf8');
const helper=read('includes/artist-media-v182.php');
const admin=read('admin/artist-media.php');
const artist=read('admin/artist.php');
const bootstrap=read('includes/bootstrap.php');
const image=read('content-image.php');
const upgrade=read('upgrade.php');
const deny=read('uploads/artist-media/.htaccess');
const workflow=read('.github/workflows/pr82-listening-recovery.yml');

for(const column of ['caption','alt_text','sort_order']){
  assert.match(helper,new RegExp(`column_exists\\('artist_catalog_photos_v181','${column}'\\)`),`checks ${column}`);
  assert.match(helper,new RegExp(`ADD COLUMN ${column}`),`installs ${column}`);
}
assert.match(helper,/is_uploaded_file/);
assert.match(helper,/getimagesize/);
for(const mime of ['image\/jpeg','image\/png','image\/webp'])assert.match(helper,new RegExp(mime));
assert.match(helper,/uploads\/artist-media\/.*workspaceId.*photos/);
assert.match(helper,/realpath/);
assert.match(helper,/WHERE id=\? AND workspace_id=\? LIMIT 1/);
assert.match(helper,/artist_media_v182_delete_owned_photo/);
assert.match(helper,/artist_media_v182_copy_photo_to_profile/);
assert.match(helper,/uploads\/artist-profiles/);
assert.match(deny,/denied|Deny from all/i);

assert.match(admin,/user_has_role\('artist'/);
assert.match(admin,/has_permission\('photos.manage'/);
assert.match(admin,/name="photo_file"/);
assert.match(admin,/multipart\/form-data/);
assert.match(admin,/Drop a photo here/);
assert.match(admin,/artist-upload-preview/);
assert.match(admin,/data-lightbox/);
assert.match(admin,/name="alt_text"/);
assert.match(admin,/name="caption"/);
assert.match(admin,/name="sort_order"/);
assert.match(admin,/name="visibility"/);
assert.match(admin,/name="is_published"/);
assert.match(admin,/value="use_profile"/);
assert.match(admin,/value="use_cover"/);
assert.match(admin,/DELETE FROM artist_catalog_photos_v181 WHERE id=\? AND workspace_id=\?/);
assert.match(admin,/UPDATE artist_catalog_photos_v181 SET .* WHERE id=\? AND workspace_id=\?/s);
assert.doesNotMatch(admin,/name="image_path"/);
assert.doesNotMatch(admin,/existing_image_path/);

assert.match(artist,/artist_media_v182_ensure_schema/);
assert.match(artist,/artist_media_v182_copy_photo_to_profile/);
assert.match(artist,/artist-media\.php/);
assert.match(artist,/Use the Artist Media Library/);
assert.match(image,/artist_media_v182_resolve_stored_photo/);
assert.match(image,/artist_workspace_v181_scope_id\(\$user\) === \(int\)\(\$item\['workspace_id'\]/);
assert.match(image,/X-Content-Type-Options: nosniff/);
assert.match(bootstrap,/artist-media-v182\.php/);
assert.match(upgrade,/artist_media_v182_ensure_schema/);
assert.match(upgrade,/artist_media_v182_schema_ready/);
assert.match(workflow,/find tests -maxdepth 1 -type f/,'recovery workflow must execute the complete Node contract suite');
assert.match(workflow,/node "\$test"/,'recovery workflow must execute each discovered Node contract test, including artist media');
console.log('ARTIST_MEDIA_V182=PASS');
