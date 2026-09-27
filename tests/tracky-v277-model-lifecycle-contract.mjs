import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const lifecycle=read('includes/tracky-lifecycle-v277.php');
const calibration=read('includes/tracky-calibration-v276.php');
const cloud=read('includes/tracky-cloud-v270.php');
const agent=read('includes/tracky-agent-v271.php');
const registry=read('includes/cognitive-domain-registry-v2600.php');
const routing=read('includes/homeserver-execution-routing-v220.php');
const page=read('tracky.php');
const api=read('api/tracky-lifecycle-v277.php');
const workflow=read('.github/workflows/cognitive-loop-release-v2360.yml');
const recovery=read('tools/run_recovery_baseline.py');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');

assert.match(lifecycle,/VP3_TRACKY_LIFECYCLE_PROTOCOL_V277='physical_model_lifecycle\.v1'/);
assert.match(lifecycle,/CREATE TABLE IF NOT EXISTS tracky_cloud_model_lifecycle/);
assert.doesNotMatch(lifecycle,/CREATE TABLE IF NOT EXISTS\s+tracky_(?:cloud_)?model_(?:decisions|rollouts|activations|rollbacks)/i);
assert.match(lifecycle,/'summary_only'=>true/);
assert.match(lifecycle,/'activation_authority'=>'local_tracky'/);
assert.match(lifecycle,/'promotion_authority'=>false/);
assert.match(lifecycle,/'rollback_authority'=>false/);
assert.match(lifecycle,/'environment_profiles_exposed'=>false/);
assert.match(lifecycle,/'decision_history_exposed'=>false/);
assert.match(lifecycle,/'scenario_details_exposed'=>false/);
assert.match(lifecycle,/'module'=>'physical_model_lifecycle'/);
assert.match(lifecycle,/'tracky\.model_lifecycle'/);
assert.match(lifecycle,/'kind'=>'read','risk'=>'low','requires_approval'=>false/);
assert.doesNotMatch(lifecycle,/function\s+tracky_v277_(?:promote|activate|rollback|install|train)/i);

assert.match(calibration,/require_once __DIR__\.'\/tracky-lifecycle-v277\.php'/);
assert.match(registry,/tracky_v277_register_cognitive/);
assert.doesNotMatch(agent,/'kind'=>'write'/,'retained V2.71 physical context must remain read-only');

assert.match(cloud,/tracky_v277_ensure_schema/);
assert.match(cloud,/tracky_v277_normalize/);
assert.match(cloud,/tracky_v277_ingest/);
assert.match(cloud,/'model_lifecycle'=>\[/);
assert.match(cloud,/model_lifecycle_protocol/);
assert.match(cloud,/'model_lifecycle'\]/);

assert.match(routing,/'physical_context\.model_lifecycle'/);
assert.match(page,/Model lifecycle · V2\.77/);
assert.match(page,/Cloud is read-only/);
assert.match(page,/shadow/);
assert.match(page,/canary/);

assert.match(api,/\$_SERVER\['REQUEST_METHOD'\]!=='GET'/);
assert.doesNotMatch(api,/verify_csrf|tracky_v277_ingest|promote|activate|rollback|INSERT|UPDATE|DELETE/,'read-only lifecycle API contains write behavior');

assert.match(workflow,/Tracky V2\.77 model lifecycle/);
assert.match(recovery,/tests\/tracky-v277-model-lifecycle-contract\.mjs/);
assert.match(recovery,/tests\/tracky-v277-model-lifecycle-unit\.php/);
assert.match(packageWorkflow,/test -f _deploy\/includes\/tracky-lifecycle-v277\.php/);
assert.match(packageWorkflow,/test -f _deploy\/api\/tracky-lifecycle-v277\.php/);

const vectors=JSON.parse(read('tests/fixtures/tracky_v277_lifecycle_vectors.json'));
assert.equal(vectors.protocol,'physical_model_lifecycle.v1');
assert.equal(vectors.valid.activation_authority,'local_tracky');
assert.equal(vectors.valid.summary_only,true);

console.log('TRACKY_V277_MODEL_LIFECYCLE_CONTRACT=PASS');
