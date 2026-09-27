import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const actions=read('includes/tracky-actions-v275.php');
const proactive=read('includes/tracky-proactive-v272.php');
const routing=read('includes/homeserver-execution-routing-v220.php');
const agent=read('includes/tracky-agent-v271.php');
const page=read('tracky.php');
const api=read('api/tracky-actions-v275.php');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(actions,/VP3_TRACKY_ACTION_PROTOCOL_V275='physical_action\.v1'/);
assert.match(actions,/homeserver_execution_v220_execute\(\$uid,'physical_context\.action\.propose'/);
assert.match(actions,/homeserver_execution_v220_execute\(\$uid,'physical_context\.action\.status'/);
assert.match(actions,/'remote_approval_allowed'=>false/);
assert.match(actions,/'direct_execution'=>false/);
assert.match(actions,/permission_upgrade_required/);
assert.match(actions,/governed_action_proposals_not_enabled/);
assert.doesNotMatch(actions,/action\.approve|action\.deny/,'Tracky Cloud must not review physical approvals');
assert.doesNotMatch(actions,/homeserver_https_v1300_remote_operation\(/,'Tracky V2.75 must use canonical execution routing');
assert.doesNotMatch(actions,/devices\.command.*execute|execute_device|driver/,'Tracky Cloud must not directly execute devices');
assert.doesNotMatch(actions,/CREATE TABLE|ALTER TABLE/,'Tracky V2.75 Cloud must not create an action authority store');

assert.match(proactive,/'governed_action_proposals_enabled'=>false/);
assert.match(routing,/'physical_context\.action\.propose'/);
assert.match(routing,/'physical_context\.action\.status'/);

assert.match(agent,/'tracky\.propose_device_action'/);
assert.match(agent,/'kind'=>'write','risk'=>'high','requires_approval'=>true/);
assert.match(agent,/'remote_approval_allowed'=>false/);
assert.match(agent,/'direct_execution'=>false/);

assert.match(page,/governed_action_proposals_enabled/);
assert.match(page,/Governed physical actions · V2\.75/);
assert.match(page,/remote approval never allowed/);
assert.match(page,/Read \+ verify \+ propose/);

assert.match(api,/tracky_v275_propose/);
assert.match(api,/tracky_v275_status/);
assert.doesNotMatch(api,/approve|deny/,'Tracky action API must not expose approval endpoints');

assert.match(packageWorkflow,/test -f _deploy\/includes\/tracky-actions-v275\.php/);
assert.match(packageWorkflow,/test -f _deploy\/api\/tracky-actions-v275\.php/);
assert.match(recovery,/tests\/tracky-v275-governed-actions-contract\.mjs/);
assert.match(recovery,/tests\/tracky-v275-governed-actions-unit\.php/);

const vectors=JSON.parse(read('tests/fixtures/tracky_v275_action_vectors.json'));
assert.equal(vectors.version,'tracky-v2.75');
assert.equal(vectors.authority,'existing_homeserver_action_requests');

console.log('TRACKY_V275_GOVERNED_ACTIONS_CONTRACT=PASS');
