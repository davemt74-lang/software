import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(path, 'utf8');
const page = read('admin/stems-legacy-v108.php');
const mixes = read('api/stem-mix.php');
const project = read('api/studio-project-v77.php');
const media = read('stem-media-v34.php');
const waveform = read('api/stem-waveform-v49.php');
const exportApi = read('admin-audio-export-v65.php');

assert.match(page, /\$fanPrivateMix = user_has_role\('fan'/);
assert.match(page, /Private fan mix/);
assert.match(page, /'fanPrivateMix'=>\$fanPrivateMix/);
assert.match(page, /if \(!\$project && !\$fanPrivateMix\)/);
assert.match(mixes, /user_has_role\('fan', \$user\) && can_view_track\(\$track, \$user\)/);
assert.match(mixes, /WHERE user_id=\? AND track_id=\?/);
assert.match(project, /require_permission\('chat\.access'\)/);
assert.match(project, /can_manage_track_production\(\$track\)/);
assert.match(media, /user_has_role\('fan'\) && can_view_track\(\$track\)/);
assert.match(waveform, /user_has_role\('fan'\) && can_view_track\(\$track\)/);
assert.match(exportApi, /can_manage_track_production/);

console.log('FAN_PRIVATE_STEM_MIXES=PASS');
