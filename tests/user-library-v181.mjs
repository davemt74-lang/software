import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const helper=readFileSync(new URL('../includes/artist-workspace-v181.php',import.meta.url),'utf8');
const page=readFileSync(new URL('../my-library.php',import.meta.url),'utf8');
const profile=readFileSync(new URL('../profile.php',import.meta.url),'utf8');
const header=readFileSync(new URL('../includes/header.php',import.meta.url),'utf8');
const schema=readFileSync(new URL('../upgrade-stonefellow-v181-artist-workspace.sql',import.meta.url),'utf8');
for(const table of ['artist_workspace_saved_shows_v181','artist_workspace_saved_photos_v181']) { assert.match(helper,new RegExp(table)); assert.match(schema,new RegExp(table)); }
assert.match(helper,/artist_workspace_v181_saved_records/);
assert.match(helper,/artist_workspace_v181_toggle_saved/);
assert.match(helper,/c\.is_published=1/);
assert.match(page,/require_permission\('account\.access'\)/);
assert.match(page,/verify_csrf\(\)/);
assert.match(page,/artist_workspace_v181_saved_records\('shows'/);
assert.match(page,/artist_workspace_v181_saved_records\('photos'/);
assert.match(profile,/my-library\.php/,'canonical profile exposes Save-to-Library navigation/actions');
assert.match(header,/my-library\.php/);
console.log('User library v181 contracts passed.');
