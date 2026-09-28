import fs from 'node:fs';
import assert from 'node:assert/strict';

const include=fs.readFileSync('includes/tracky-cross-site-presence-v280.php','utf8');
const api=fs.readFileSync('api/tracky-cross-site-presence-v280.php','utf8');
const loader=fs.readFileSync('includes/tracky-cloud-v270.php','utf8');
const page=fs.readFileSync('tracky.php','utf8');
const script=fs.readFileSync('tracky-cross-site-presence-v280.js','utf8');
const release=fs.readFileSync('.github/workflows/cognitive-loop-release-v2360.yml','utf8');
const packageWorkflow=fs.readFileSync('.github/workflows/production-deploy-package.yml','utf8');
const recovery=fs.readFileSync('tools/run_recovery_baseline.py','utf8');

assert.match(include,/physical_cross_site_presence\.v1/);
assert.match(include,/destination_claim_rule'=>'present_at_destination_only_after_arrived'/);
assert.match(include,/authority_transfer'=>false/);
assert.match(include,/cross_site_merge'=>false/);
assert.match(include,/tracky_cloud_mobile_transition_history/);
assert.match(api,/tracky_v280_presence_report/);
assert.match(loader,/tracky-cross-site-presence-v280\.php/);
assert.match(page,/id="crossSitePresenceV280"/);
assert.match(page,/tracky-cross-site-presence-v280\.css/);
assert.match(page,/tracky-cross-site-presence-v280\.js/);
assert.match(script,/Destination presence is not confirmed yet/);
assert.match(script,/Last confirmed at/);
assert.doesNotMatch(script,/method\s*:\s*['"](?:POST|PUT|PATCH|DELETE)['"]/);
assert.match(release,/tracky-v280-cross-site-presence-unit\.php/);
assert.match(release,/tracky-v280-cross-site-presence-contract\.mjs/);
assert.match(packageWorkflow,/tracky-cross-site-presence-v280\.php/);
assert.match(packageWorkflow,/tracky-cross-site-presence-v280\.js/);
assert.match(packageWorkflow,/tracky-cross-site-presence-v280\.css/);
assert.match(recovery,/tracky-v280-cross-site-presence-unit\.php/);
assert.match(recovery,/tracky-v280-cross-site-presence-contract\.mjs/);

console.log('TRACKY_V280_CROSS_SITE_PRESENCE_CONTRACT=PASS');
