import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const topology=read('includes/tracky-topology-v278.php');
const cloud=read('includes/tracky-cloud-v270.php');
const api=read('api/tracky-site-topology-v278.php');
const docs=read('docs/TRACKY_V278_SITE_TOPOLOGY.md');

assert.match(topology,/VP3_TRACKY_TOPOLOGY_PROTOCOL_V278='physical_site_topology\.v1'/);
assert.match(topology,/CREATE TABLE IF NOT EXISTS tracky_cloud_site_topology/);
assert.match(topology,/'cloud_read_only'=>true/);
assert.match(topology,/'authority_assignment'=>'local_only'/);
assert.match(topology,/'topology_mutation_authority'=>false/);
assert.match(topology,/revision conflicts with an existing summary/);
assert.match(topology,/mobile device cannot advertise site authority/);
assert.doesNotMatch(topology,/function\s+tracky_v278_(?:claim|assign|replace|register_device|set_trust|set_roles)/i);
assert.match(cloud,/tracky-topology-v278\.php/);
assert.match(cloud,/tracky_v278_ensure_schema/);
assert.match(cloud,/tracky_v278_normalize/);
assert.match(cloud,/tracky_v278_ingest/);
assert.match(cloud,/'site_topology'=>\[/);
assert.match(cloud,/site_topology_protocol/);
assert.match(api,/\$_SERVER\['REQUEST_METHOD'\]!=='GET'/);
assert.doesNotMatch(api,/verify_csrf|tracky_v278_ingest|\b(?:INSERT|UPDATE|DELETE)\b/i);
assert.match(docs,/Node, Desk, Studio, Team Node, Pocket, and Custom/);
assert.match(docs,/capability-driven/);
assert.match(docs,/one active authority device per site/i);
console.log('TRACKY_V278_SITE_TOPOLOGY_CONTRACT=PASS');
