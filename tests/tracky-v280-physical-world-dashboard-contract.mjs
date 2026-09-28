import fs from 'node:fs';
import assert from 'node:assert/strict';

const include=fs.readFileSync('includes/tracky-physical-world-dashboard-v280.php','utf8');
const api=fs.readFileSync('api/tracky-physical-world-dashboard-v280.php','utf8');
const loader=fs.readFileSync('includes/tracky-cloud-v270.php','utf8');
const page=fs.readFileSync('tracky.php','utf8');
const script=fs.readFileSync('tracky-physical-world-dashboard-v280.js','utf8');
const release=fs.readFileSync('.github/workflows/cognitive-loop-release-v2360.yml','utf8');
const packageWorkflow=fs.readFileSync('.github/workflows/production-deploy-package.yml','utf8');
const recovery=fs.readFileSync('tools/run_recovery_baseline.py','utf8');

assert.match(include,/physical_world_dashboard\.v1/);
assert.match(include,/changes_physical_authority'=>false/);
assert.match(include,/changes_physical_location'=>false/);
assert.match(include,/cross_site_identity_merge'=>false/);
assert.match(api,/tracky_v280_dashboard_report/);
assert.match(loader,/tracky-physical-world-dashboard-v280\.php/);
assert.match(page,/id="physicalWorldDashboardV280"/);
assert.match(page,/data-selected-site="<\?= e\(\$selected\) \?>"/);
assert.match(page,/tracky-physical-world-dashboard-v280\.css/);
assert.match(page,/tracky-physical-world-dashboard-v280\.js/);
assert.match(script,/window\.location\.assign/);
assert.match(script,/physical current-site evidence remains unchanged/);
assert.doesNotMatch(script,/method\s*:\s*['"](?:POST|PUT|PATCH|DELETE)['"]/);
assert.match(release,/tracky-v280-physical-world-dashboard-unit\.php/);
assert.match(release,/tracky-v280-physical-world-dashboard-contract\.mjs/);
assert.match(packageWorkflow,/tracky-physical-world-dashboard-v280\.php/);
assert.match(packageWorkflow,/tracky-physical-world-dashboard-v280\.js/);
assert.match(packageWorkflow,/tracky-physical-world-dashboard-v280\.css/);
assert.match(recovery,/tracky-v280-physical-world-dashboard-unit\.php/);
assert.match(recovery,/tracky-v280-physical-world-dashboard-contract\.mjs/);

console.log('TRACKY_V280_PHYSICAL_WORLD_DASHBOARD_CONTRACT=PASS');
