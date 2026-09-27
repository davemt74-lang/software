import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const query=read('includes/tracky-federated-query-v278.php');
const world=read('includes/tracky-federated-world-v278.php');
const policy=read('includes/tracky-federation-policy-v278.php');
const cloud=read('includes/tracky-cloud-v270.php');
const api=read('api/tracky-federated-query-v278.php');
const docs=read('docs/TRACKY_V278_FEDERATED_QUERY_HISTORY.md');

assert.match(query,/VP3_TRACKY_FEDERATED_QUERY_PROTOCOL_V278='physical_federated_query\.v1'/);
assert.match(query,/CREATE TABLE IF NOT EXISTS tracky_cloud_federated_query_audit/);
assert.match(world,/CREATE TABLE IF NOT EXISTS tracky_cloud_federated_world_history/);
assert.match(world,/INSERT IGNORE INTO tracky_cloud_federated_world_history/);
assert.match(query,/tracky_v278_policy_decision\([^;]+semantic_world_read/s);
assert.match(query,/tracky_v278_policy_decision\([^;]+history_query/s);
assert.match(query,/tracky_v278_policy_filter_world_fragment/);
assert.match(query,/person_query_requires_identity_continuity/);
assert.match(query,/destination_site_id/);
assert.match(query,/no_location_invention/);
assert.match(query,/'authority_mutation'=>false/);
assert.match(query,/'cloud_role'=>'mirror_query_only'/);
assert.doesNotMatch(query,/function\s+tracky_v278_query_(?:grant|revoke|assign_authority|mutate_world)\s*\(/i);

assert.match(policy,/history_query/);
assert.match(cloud,/tracky-federated-query-v278\.php/);
assert.match(cloud,/tracky_v278_query_ensure_schema/);
assert.match(cloud,/federated_query_protocol/);
assert.match(cloud,/federated_query/);

assert.match(api,/\$_SERVER\['REQUEST_METHOD'\].*GET/);
assert.match(api,/destination_site/);
assert.match(api,/tracky_v278_query_execute/);
assert.match(api,/tracky_v278_query_audit/);
assert.doesNotMatch(api,/verify_csrf|\$_POST|REQUEST_METHOD.*POST/i);

assert.match(docs,/history_query/);
assert.match(docs,/destination site/i);
assert.match(docs,/person.*identity continuity/i);
assert.match(docs,/deny-by-default/i);
assert.match(docs,/immutable semantic snapshots/i);
assert.match(docs,/Cloud.*mirror/i);
assert.match(docs,/raw perception/i);

console.log('TRACKY_V278_FEDERATED_QUERY_CONTRACT=PASS');
