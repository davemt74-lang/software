import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const reliability=read('includes/tracky-reliability-v274.php');
const join=read('includes/tracky-join-v273.php');
const routing=read('includes/homeserver-execution-routing-v220.php');
const page=read('tracky.php');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const recovery=read('tools/run_recovery_baseline.py');

assert.match(reliability,/VP3_TRACKY_RELIABILITY_V274='vp3-tracky-reliability-v274-20260926'/);
assert.match(reliability,/function tracky_v274_validate_active_response/);
assert.match(reliability,/function tracky_v274_projection_max_sequence/);
assert.match(reliability,/function tracky_v274_ingest_active_projection/);
assert.match(reliability,/function tracky_v274_reliability_state/);
assert.match(reliability,/request identity does not match/);
assert.match(reliability,/correlation identity does not match/);
assert.match(reliability,/physical projection for the wrong site/);
assert.match(reliability,/verified_sequence/);
assert.match(reliability,/homeserver_tracky_backlog_critical/);
assert.match(reliability,/cloud_projection_stale/);
assert.doesNotMatch(reliability,/CREATE TABLE|ALTER TABLE/,'Cloud V2.74 must not create another authority or reliability store');
assert.doesNotMatch(reliability,/homeserver_https_v1300_queue|INSERT INTO homeserver_https_requests/,'V2.74 must not bypass canonical HomeServer routing');

assert.match(join,/tracky_v274_validate_active_response/);
assert.match(join,/tracky_v274_ingest_active_projection/);
assert.match(join,/invalid_homeserver_response/);
assert.match(join,/cloud_projection_verification_failed/);
assert.match(join,/tracky-reliability-v274\.php/);

assert.match(routing,/'physical_context\.active_perception'/);
assert.doesNotMatch(join,/homeserver_https_v1300_remote_operation\(/,'V2.74 must preserve v2.3/v2.4 execution authority');

assert.match(page,/End-to-end reliability/);
assert.match(page,/V2\.74/);

assert.match(packageWorkflow,/test -f _deploy\/includes\/tracky-reliability-v274\.php/);
assert.match(recovery,/tests\/tracky-v274-reliability-contract\.mjs/);
assert.match(recovery,/tests\/tracky-v274-reliability-unit\.php/);

const vectors=JSON.parse(read('tests/fixtures/tracky_v274_resilience_vectors.json'));
assert.equal(vectors.version,'tracky-v2.74');
assert.deepEqual(
  vectors.scenarios.find(s=>s.id==='cloud_outage_backoff').expected_backoff_seconds,
  [5,10,20,40,80,160,300]
);

console.log('TRACKY_V274_RELIABILITY_CONTRACT=PASS');
