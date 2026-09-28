import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const reconciliation=read('includes/tracky-federation-reconciliation-v278.php');
const sync=read('includes/tracky-federation-sync-v278.php');
const cloud=read('includes/tracky-cloud-v270.php');
const api=read('api/tracky-federation-sync-v278.php');

assert.match(reconciliation,/physical_federation_reconciliation\.v1/);
for(const guard of [
  'origin-site-authority-only',
  'partition-never-promotes-remote-or-cloud-authority',
  'stale-data-must-be-labeled',
  'revision-gap-requires-authoritative-reconciliation',
  'authority-epoch-change-requires-revalidation',
  'same-revision-fingerprint-conflict-fails-closed',
  'cloud-remains-relay-and-mirror-only'
]) assert.ok(reconciliation.includes(guard), 'missing boundary '+guard);

assert.match(reconciliation,/'cloud_can_mark_destination_current'=>false/);
assert.match(reconciliation,/'cloud_can_assign_authority'=>false/);
assert.match(sync,/remoteCursors/);
assert.match(sync,/tracky_v278_reconciliation_payload\(\$remoteCursors\)/);
assert.match(sync,/destination_decides_freshness/);
assert.match(cloud,/tracky-federation-reconciliation-v278\.php/);
assert.match(cloud,/federation_reconciliation_protocol/);
assert.match(api,/tracky_v278_reconciliation_public_capability/);
assert.doesNotMatch(reconciliation,/function\s+tracky_v278_reconciliation_(?:assign_authority|mark_current|resolve_conflict|mutate_world)\s*\(/i);

console.log('TRACKY_V278_FEDERATION_RECONCILIATION_CONTRACT=PASS');
