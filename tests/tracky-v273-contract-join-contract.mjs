import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const join=read('includes/tracky-join-v273.php');
const routing=read('includes/homeserver-execution-routing-v220.php');
const proactive=read('includes/tracky-proactive-v272.php');
const agent=read('includes/tracky-agent-v271.php');
const page=read('tracky.php');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(join,/VP3_TRACKY_ACTIVE_PROTOCOL_V273='active_perception\.v1'/);
assert.match(join,/homeserver_reconciliation_v246_state/);
assert.match(join,/needs_reconciliation/);
assert.match(join,/homeserver_execution_v220_registry/);
assert.match(join,/homeserver_execution_v220_can_route/);
assert.match(join,/homeserver_execution_v220_execute\(\$uid,'physical_context\.active_perception'/);
assert.match(join,/tracky_cloud_v270_ingest\(\$pdo,\$uid,\$deviceId,\$projection\)/);
assert.match(join,/'cloud_projection_ingested'/);
assert.doesNotMatch(join,/homeserver_https_v1300_remote_operation\(/,'V2.73 must route through the canonical execution router');
assert.doesNotMatch(join,/INSERT INTO homeserver_https_requests|homeserver_https_v1300_queue\(/,'V2.73 must not bypass canonical execution routing');
assert.doesNotMatch(join,/CREATE TABLE|ALTER TABLE/,'Cloud V2.73 must not create another physical or reconciliation authority store');

assert.match(routing,/'physical_context\.capabilities'/);
assert.match(routing,/'physical_context\.current'/);
assert.match(routing,/'physical_context\.active_perception'/);
assert.match(routing,/'physical_context\.request_status'/);
assert.match(routing,/'physical_context\.sync'/);
assert.match(routing,/'physical_context'=>!empty\(\$caps\['tracky_physical_context'\]\)\?'homeserver':'unavailable'/);

assert.match(proactive,/'active_perception_enabled'=>false/);
assert.match(proactive,/'auto_refresh_stale'=>false/);
assert.match(proactive,/tracky-join-v273\.php/);

assert.match(agent,/tracky_v273_maybe_refresh/);
assert.match(agent,/tracky_v273_refresh_note/);
assert.match(agent,/'active_perception'=>\$refresh/);
assert.match(agent,/'raw_perception_exposed'=>false/);

assert.match(page,/active_perception_enabled/);
assert.match(page,/auto_refresh_stale/);
assert.match(page,/Off by default/);
assert.match(page,/existing v2\.4 HomeServer relay/);
assert.match(page,/V2\.4 reconciliation/);
assert.match(page,/Read \+ verify/);

assert.match(join,/provider_unavailable/);
assert.match(join,/protocol_incompatible/);
assert.match(join,/homeserver_offline/);
assert.match(join,/raw_perception_exposed'=>false/);
assert.doesNotMatch(join,/devices\.control|tool\.execute|action\.approve|action\.list/,'active perception must not gain physical action authority');

for(const name of [
  'homeserver_federated_records','homeserver_federated_cursors',
  'homeserver_reconciliation_state','homeserver_reconciliation_runs'
]){
  assert.ok(!join.includes('CREATE TABLE '+name),'V2.73 duplicated v2.4 authority table '+name);
}

assert.match(packageWorkflow,/test -f _deploy\/includes\/tracky-join-v273\.php/);
assert.match(recovery,/tests\/tracky-v273-contract-join-contract\.mjs/);
assert.match(recovery,/tests\/tracky-v273-contract-join-unit\.php/);

console.log('TRACKY_V273_CONTRACT_JOIN_CONTRACT=PASS');
