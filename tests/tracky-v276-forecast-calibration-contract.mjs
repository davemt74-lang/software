import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const moduleSource=read('includes/tracky-calibration-v276.php');
const cloud=read('includes/tracky-cloud-v270.php');
const actions=read('includes/tracky-actions-v275.php');
const agent=read('includes/tracky-agent-v271.php');
const registry=read('includes/cognitive-domain-registry-v2600.php');
const routing=read('includes/homeserver-execution-routing-v220.php');
const page=read('tracky.php');
const api=read('api/tracky-calibration-v276.php');
const workflow=read('.github/workflows/cognitive-loop-release-v2360.yml');
const recovery=read('tools/run_recovery_baseline.py');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');

assert.match(moduleSource,/VP3_TRACKY_CALIBRATION_PROTOCOL_V276='forecast_calibration\.v1'/);
assert.match(moduleSource,/CREATE TABLE IF NOT EXISTS tracky_cloud_calibration/);
assert.doesNotMatch(moduleSource,/CREATE TABLE IF NOT EXISTS\s+tracky_(?:cloud_)?(?:forecast_)?predictions/i);
assert.doesNotMatch(moduleSource,/CREATE TABLE IF NOT EXISTS\s+tracky_(?:cloud_)?(?:forecast_)?settlements/i);
assert.match(moduleSource,/'summary_only'=>true/);
assert.match(moduleSource,/'prediction_records_exposed'=>false/);
assert.match(moduleSource,/'settlement_records_exposed'=>false/);
assert.match(moduleSource,/confidence buckets/i);
assert.match(moduleSource,/'module'=>'physical_model_accuracy'/);
assert.match(moduleSource,/'tracky\.model_accuracy'/);
assert.match(moduleSource,/'kind'=>'read','risk'=>'low','requires_approval'=>false/);
assert.match(moduleSource,/'training_authority'=>false/);
assert.match(moduleSource,/'settlement_authority'=>false/);
assert.match(moduleSource,/'prediction_authority'=>false/);
assert.doesNotMatch(moduleSource,/function\s+tracky_v276_(?:train|settle|record_prediction|record_forecast|calibrate_model)/i);

assert.match(actions,/require_once __DIR__\.'\/tracky-calibration-v276\.php'/);
assert.match(registry,/tracky_v276_register_cognitive/);
assert.doesNotMatch(agent,/'kind'=>'write'/,'retained V2.71 physical context must stay read-only');

assert.match(cloud,/tracky_v276_ensure_schema/);
assert.match(cloud,/tracky_v276_normalize/);
assert.match(cloud,/tracky_v276_ingest/);
assert.match(cloud,/'forecast_calibration'=>\[/);
assert.match(cloud,/forecast_calibration_protocol/);
assert.match(cloud,/\$allowed=\['runtime','camera','world_state','inference','model','database','event_backlog','sync_backlog','resource_pressure','storage_pressure','forecast_calibration'\]/);

assert.match(routing,/'physical_context\.calibration'/);
assert.match(page,/Model accuracy · V2\.76/);
assert.match(page,/Awaiting evidence/);
assert.match(page,/Cloud is read-only/);

assert.match(api,/\$_SERVER\['REQUEST_METHOD'\]!=='GET'/);
assert.doesNotMatch(api,/verify_csrf|tracky_v276_ingest|INSERT|UPDATE|DELETE/,'read-only calibration API contains write behavior');

assert.match(workflow,/Tracky V2\.76 forecast calibration/);
assert.match(recovery,/tests\/tracky-v276-forecast-calibration-contract\.mjs/);
assert.match(recovery,/tests\/tracky-v276-forecast-calibration-unit\.php/);
assert.match(packageWorkflow,/test -f _deploy\/includes\/tracky-calibration-v276\.php/);
assert.match(packageWorkflow,/test -f _deploy\/api\/tracky-calibration-v276\.php/);

const vectors=JSON.parse(read('tests/fixtures/tracky_v276_calibration_vectors.json'));
assert.equal(vectors.protocol,'forecast_calibration.v1');
assert.equal(vectors.valid.summary_only,true);
assert.equal(vectors.valid.prediction_records_exposed,false);
assert.equal(vectors.valid.settlement_records_exposed,false);

console.log('TRACKY_V276_FORECAST_CALIBRATION_CONTRACT=PASS');
