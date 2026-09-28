import fs from 'node:fs';
import assert from 'node:assert/strict';

const include=fs.readFileSync('includes/tracky-federation-sync-visibility-v280.php','utf8');
const cloud=fs.readFileSync('includes/tracky-cloud-v270.php','utf8');
const dashboard=fs.readFileSync('includes/tracky-physical-world-dashboard-v280.php','utf8');
const api=fs.readFileSync('api/tracky-federation-sync-visibility-v280.php','utf8');
const page=fs.readFileSync('tracky.php','utf8');
const script=fs.readFileSync('tracky-federation-sync-visibility-v280.js','utf8');
const worldScript=fs.readFileSync('tracky-physical-world-dashboard-v280.js','utf8');
const release=fs.readFileSync('.github/workflows/cognitive-loop-release-v2360.yml','utf8');
const packageWorkflow=fs.readFileSync('.github/workflows/production-deploy-package.yml','utf8');
const recovery=fs.readFileSync('tools/run_recovery_baseline.py','utf8');

assert.match(include,/physical_federation_sync_visibility\.v1/);
assert.match(include,/cloud_can_mark_destination_current'=>false/);
assert.match(include,/remote_authority_promotion'=>false/);
assert.match(include,/tracky_cloud_federation_sync_visibility/);
assert.match(cloud,/federation_sync_visibility/);
assert.match(cloud,/tracky_v280_syncv_ingest/);
assert.match(cloud,/sync visibility local site must match the uploader federation site/i);
assert.match(dashboard,/tracky_v280_syncv_annotate_dashboard/);
assert.match(api,/tracky_v280_syncv_report/);
assert.match(page,/id="federationSyncVisibilityV280"/);
assert.match(page,/data-pw280-sync-warning/);
assert.match(page,/tracky-federation-sync-visibility-v280\.css/);
assert.match(page,/tracky-federation-sync-visibility-v280\.js/);
assert.match(script,/Origin-reported mirror/);
assert.match(worldScript,/origin-HomeServer reported/);
assert.doesNotMatch(script,/method\s*:\s*['"](?:POST|PUT|PATCH|DELETE)['"]/);
assert.match(release,/tracky-v280-sync-visibility-unit\.php/);
assert.match(release,/tracky-v280-sync-visibility-contract\.mjs/);
assert.match(packageWorkflow,/tracky-federation-sync-visibility-v280\.php/);
assert.match(packageWorkflow,/tracky-federation-sync-visibility-v280\.js/);
assert.match(packageWorkflow,/tracky-federation-sync-visibility-v280\.css/);
assert.match(recovery,/tracky-v280-sync-visibility-unit\.php/);
assert.match(recovery,/tracky-v280-sync-visibility-contract\.mjs/);

console.log('TRACKY_V280_SYNC_VISIBILITY_CONTRACT=PASS');
