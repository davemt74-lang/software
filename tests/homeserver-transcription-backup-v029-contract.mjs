import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const service = read('includes/homeserver-knowledge-backup-v029.php');
const api = read('api/artist-listening-homeserver-v029.php');
const existingApi = read('api/artist-listening-v172.php');
const ui = read('artist-listening-homeserver-v029.js');
const loader = read('artist-listening-naming.js');

assert.match(service, /knowledge\.external_backup\.v1/, 'HomeServer capability discovery must gate backup');
assert.match(service, /vp3-transcript:session-/, 'one stable source key must converge direct and cloud saves');
assert.match(service, /98304/, 'recording chunks must stay at 96 KiB');
assert.match(service, /hash_file\('sha256'/, 'VP3 must hash retained recordings before backup');
for (const operation of ['knowledge.upsert','knowledge.asset.begin','knowledge.asset.chunk','knowledge.asset.commit']) {
  assert.ok(service.includes(operation), `missing HomeServer operation ${operation}`);
}
assert.match(service, /artist_listening_v197_private_dir/, 'recording backup must read the existing private retained recording');
assert.doesNotMatch(ui, /relay_token|homeserver_token|_path|data_base64/, 'browser integration must not receive private transfer material');

assert.match(api, /\['knowledge\.write'\]/, 'permission upgrade must explicitly request knowledge.write');
assert.match(api, /save_direct/, 'direct HomeServer Knowledge save must be exposed');
assert.match(api, /save_cloud/, 'cloud Knowledge mirror must be exposed');
assert.match(api, /artist_listening_hs_v029_pump/, 'recording backup must use bounded resumable pumping');
assert.match(api, /recording_synced.*recording_total/s, 'pump completion must validate current recording counts, not only stale state');
assert.match(api, /recover_stale_upload/, 'expired resumable uploads must be recoverable');
assert.match(api, /homeserver_vp3_check_pairing/, 'permission upgrade approval must complete through canonical pairing');

assert.match(existingApi, /artist_listening_v029_homeserver_after_cloud_save/, 'canonical cloud Knowledge save must initiate HomeServer mirror server-side');
assert.match(existingApi, /artist_listening_v029_homeserver_after_recording/, 'new retained recordings must nudge an existing HomeServer backup server-side');
assert.match(existingApi, /\$result\['homeserver_backup'\]/, 'cloud Knowledge save should return additive HomeServer status without replacing canonical result');
assert.match(existingApi, /return null;/, 'HomeServer mirror failure must remain best-effort and not fail the cloud Knowledge save');

assert.match(ui, /Save to HomeServer Knowledge/, 'workspace must expose the new HomeServer Knowledge action');
assert.match(ui, /save_direct/, 'new action must save the full transcription directly');
assert.match(ui, /save_cloud/, 'existing cloud Knowledge action must trigger HomeServer mirror');
assert.match(ui, /data-listening-workspace-knowledge/, 'cloud Knowledge button must remain the canonical cloud save trigger');
assert.match(ui, /recording_synced/, 'UI must surface recording backup progress');
assert.match(ui, /permission_required/, 'UI must surface permission upgrade state');
assert.match(ui, /upgrade_permission/, 'UI must start the one-time HomeServer permission upgrade');
assert.match(ui, /check_permission/, 'UI must re-check HomeServer permission approval');
assert.match(ui, /window\.setInterval/, 'pending backups must be re-evaluated for newly retained recordings');
assert.match(loader, /artist-listening-homeserver-v029\.js/, 'Artist Listening must load the HomeServer companion integration');

console.log('VP3 v0.29 HomeServer transcription and recording backup contract passed');
