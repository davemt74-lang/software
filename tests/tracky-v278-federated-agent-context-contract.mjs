import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const context=read('includes/tracky-federated-agent-context-v278.php');
const cloud=read('includes/tracky-cloud-v270.php');
const api=read('api/tracky-federated-agent-context-v278.php');
const docs=read('docs/TRACKY_V278_FEDERATED_AGENT_CONTEXT.md');

assert.match(context,/VP3_TRACKY_FEDERATED_AGENT_CONTEXT_PROTOCOL_V278='physical_federated_agent_context\.v1'/);
assert.match(context,/CREATE TABLE IF NOT EXISTS tracky_cloud_federated_agent_context/);
assert.match(context,/CREATE TABLE IF NOT EXISTS tracky_cloud_federated_agent_context_history/);
assert.match(context,/tracky_v278_agent_context_authority/);
assert.match(context,/authority does not match the current topology mirror/);
assert.match(context,/revision conflicts with the existing Cloud mirror/);
assert.match(context,/'cloud_role'=>'mirror_only'/);
assert.match(context,/'context_mutation_authority'=>false/);
assert.match(context,/'site_authority_mutation'=>false/);
assert.match(context,/'no_location_invention'=>true/);
assert.doesNotMatch(context,/function\s+tracky_v278_agent_context_(?:mutate|assign|transfer|relay)_/i);

assert.match(cloud,/tracky-federated-agent-context-v278\.php/);
assert.match(cloud,/tracky_v278_agent_context_normalize/);
assert.match(cloud,/tracky_v278_agent_context_ingest/);
assert.match(cloud,/requires a resolved local federation site/);
assert.match(cloud,/local site must match the uploader federation site/);
assert.match(cloud,/federated_agent_context_protocol/);
assert.doesNotMatch(cloud,/'federated_agent_context'=>\$[A-Za-z]+Relay/);

assert.match(api,/\$_SERVER\['REQUEST_METHOD'\].*GET/);
assert.doesNotMatch(api,/verify_csrf|tracky_v278_agent_context_ingest|\b(?:INSERT|UPDATE|DELETE)\b/i);

assert.match(docs,/derived snapshot/i);
assert.match(docs,/does not invent a location/i);
assert.match(docs,/generic Pocket\/mobile-device transition is not silently attributed/i);
assert.match(docs,/does not relay Agent context back/i);

console.log('TRACKY_V278_FEDERATED_AGENT_CONTEXT_CONTRACT=PASS');
