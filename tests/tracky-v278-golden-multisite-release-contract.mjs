import fs from 'node:fs';
import assert from 'node:assert/strict';

const fixture=JSON.parse(fs.readFileSync('tests/fixtures/tracky_v278_golden_multisite_scenarios.json','utf8'));
const reconciliation=fs.readFileSync('includes/tracky-federation-reconciliation-v278.php','utf8');
const sync=fs.readFileSync('includes/tracky-federation-sync-v278.php','utf8');
const packageWorkflow=fs.readFileSync('.github/workflows/production-deploy-package.yml','utf8');
const releaseWorkflow=fs.readFileSync('.github/workflows/cognitive-loop-release-v2360.yml','utf8');

assert.equal(fixture.format,'tracky_v278_golden_multisite_scenarios.v1');
assert.equal(fixture.version,'2.78');
assert.equal(fixture.scenarios.length,10);
const ids=new Set(fixture.scenarios.map(x=>x.id));
for(const id of [
  'transport-partition-stale-query',
  'reconnect-revision-gap-full-snapshot',
  'authority-epoch-change-revalidation',
  'permission-revoked-during-partition',
  'restart-during-reconciliation',
  'three-site-cloud-relay-no-authority'
]) assert.ok(ids.has(id),'missing '+id);

assert.match(reconciliation,/cloud_can_assign_authority'=>false/);
assert.match(reconciliation,/cloud_can_mark_destination_current'=>false/);
assert.match(sync,/tracky_v278_reconciliation_payload/);
assert.match(sync,/destination_decides_freshness/);
assert.match(packageWorkflow,/tracky-federation-reconciliation-v278\.php/);
assert.match(releaseWorkflow,/tracky-v278-golden-multisite-release-unit\.php/);
assert.match(releaseWorkflow,/tracky-v278-golden-multisite-release-contract\.mjs/);

console.log('TRACKY_V278_GOLDEN_MULTISITE_RELEASE_CONTRACT=PASS');
