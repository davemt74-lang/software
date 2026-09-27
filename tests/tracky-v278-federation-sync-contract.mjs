import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const relay=read('includes/tracky-federation-sync-v278.php');
const cloud=read('includes/tracky-cloud-v270.php');
const world=read('includes/tracky-federated-world-v278.php');
const api=read('api/tracky-federation-sync-v278.php');
const docs=read('docs/TRACKY_V278_FEDERATION_SYNC.md');

assert.match(relay,/VP3_TRACKY_FEDERATION_SYNC_PROTOCOL_V278='physical_federation_sync\.v1'/);
assert.match(relay,/CREATE TABLE IF NOT EXISTS tracky_cloud_federation_relay_state/);
assert.match(relay,/tracky_v278_sync_build_relay/);
assert.match(relay,/tracky_v278_sync_authority_matches/);
assert.match(relay,/tracky_v278_report\(\$pdo,\$userId,\$reportingSite\)/);
assert.match(relay,/last_delivered_revision=GREATEST/);
assert.match(relay,/'cloud_role'=>'relay_only'/);
assert.match(relay,/'world_mutation_authority'=>false/);
assert.doesNotMatch(relay,/function\s+tracky_v278_sync_(?:assign_authority|resolve_conflict|mutate_world|link_identity)/i);

assert.match(cloud,/tracky-federation-sync-v278\.php/);
assert.match(cloud,/tracky_v278_sync_normalize/);
assert.match(cloud,/tracky_v278_sync_build_relay/);
assert.match(cloud,/may upload only its local authoritative site world/);
assert.match(cloud,/federation_sync_protocol/);
assert.match(world,/origin_scope/);

assert.match(api,/\$_SERVER\['REQUEST_METHOD'\].*GET/);
assert.doesNotMatch(api,/verify_csrf|tracky_v278_sync_build_relay|\b(?:INSERT|UPDATE|DELETE)\b/i);

assert.match(docs,/Cloud is \*\*relay-only\*\*/);
assert.match(docs,/Authority epochs are the cross-site comparable authority signal/);
assert.match(docs,/different fingerprint is quarantined/);
assert.match(docs,/Section 5/);
console.log('TRACKY_V278_FEDERATION_SYNC_CONTRACT=PASS');
