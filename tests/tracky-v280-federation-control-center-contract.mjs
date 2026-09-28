import fs from 'node:fs';
import assert from 'node:assert/strict';

const page=fs.readFileSync('tracky.php','utf8');
const script=fs.readFileSync('tracky-federation-control-center-v280.js','utf8');
const workflow=fs.readFileSync('.github/workflows/cognitive-loop-release-v2360.yml','utf8');
const packageWorkflow=fs.readFileSync('.github/workflows/production-deploy-package.yml','utf8');
const recovery=fs.readFileSync('tools/run_recovery_baseline.py','utf8');

assert.match(page,/id="federationControlCenter"/);
assert.match(page,/tracky-federation-control-center-v280\.css/);
assert.match(page,/tracky-federation-control-center-v280\.js/);
assert.match(page,/tracky-federation-operations-v280\.php/);
assert.match(script,/Live Cloud mirror/);
assert.match(script,/local freshness is never inferred here/);
assert.doesNotMatch(script,/method\s*:\s*['"](?:POST|PUT|PATCH|DELETE)['"]/);
assert.match(workflow,/tracky-v280-federation-control-center-unit\.php/);
assert.match(workflow,/tracky-v280-federation-control-center-contract\.mjs/);
assert.match(packageWorkflow,/tracky-federation-control-center-v280\.js/);
assert.match(packageWorkflow,/tracky-federation-control-center-v280\.css/);
assert.match(recovery,/tracky-v280-federation-control-center-unit\.php/);
assert.match(recovery,/tracky-v280-federation-control-center-contract\.mjs/);

console.log('TRACKY_V280_FEDERATION_CONTROL_CENTER_CONTRACT=PASS');
