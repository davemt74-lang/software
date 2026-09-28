import fs from 'node:fs';
import assert from 'node:assert/strict';

const operations=fs.readFileSync('includes/tracky-federation-operations-v280.php','utf8');
const loader=fs.readFileSync('includes/tracky-cloud-v270.php','utf8');
const release=fs.readFileSync('.github/workflows/cognitive-loop-release-v2360.yml','utf8');
const packageWorkflow=fs.readFileSync('.github/workflows/production-deploy-package.yml','utf8');
const recovery=fs.readFileSync('tools/run_recovery_baseline.py','utf8');

assert.match(operations,/physical_federation_operations\.v1/);
assert.match(operations,/cloud-does-not-decide-destination-freshness/);
assert.match(operations,/origin-site-authority-only/);
assert.match(operations,/authority_mutation'=>false/);
assert.match(operations,/identity_mutation'=>false/);
assert.match(loader,/tracky-federation-operations-v280\.php/);
assert.match(release,/tracky-v280-federation-operations-unit\.php/);
assert.match(release,/tracky-v280-federation-operations-contract\.mjs/);
assert.match(packageWorkflow,/tracky-federation-operations-v280\.php/);
assert.match(packageWorkflow,/tracky-federation-operations-v280\.php/);
assert.match(recovery,/tracky-v280-federation-operations-contract\.mjs/);
assert.match(recovery,/tracky-v280-federation-operations-unit\.php/);

console.log('TRACKY_V280_FEDERATION_OPERATIONS_CONTRACT=PASS');
