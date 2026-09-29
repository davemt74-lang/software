import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const health=read('includes/cloud-hosting-health-v240.php');
const ui=read('includes/cloud-hosting-ui-v140.php');
const agent=read('includes/cloud-hosting-agent-v130.php');
const page=read('hosting.php');
const css=read('cloud-hosting-v140.css');
const bootstrap=read('includes/bootstrap.php');
const workflow=read('.github/workflows/cloud-hosting-v100.yml');

assert.match(health,/VP3_CLOUD_HOSTING_HEALTH_V240/);
assert.match(health,/hosting\.health\.status/);
assert.match(health,/hosting\.health\.check/);
assert.match(health,/hosting\.health\.policy\.update/);
assert.match(health,/runtime_recovery_authority'=>'homeserver'/);
assert.match(health,/desired_state_authority'=>'cloud'/);
assert.match(health,/automatic_restore_default'=>false/);
assert.match(ui,/health\.check/);
assert.match(ui,/health\.policy/);
assert.match(ui,/'automated_health_recovery'=>true/);
assert.match(ui,/'health_policy_controls'=>true/);
assert.match(agent,/vp3_cloud_hosting_health_v240_summary/);
assert.match(agent,/Last automatic recovery action/);
assert.match(page,/Health & Recovery/);
assert.match(page,/Run Health Check/);
assert.match(page,/Save Recovery Policy/);
assert.match(page,/Auto rollback/);
assert.match(css,/hosting-health-recovery/);
assert.match(css,/hosting-health-state/);
assert.match(css,/hosting-health-history/);
assert.match(bootstrap,/cloud-hosting-health-v240\.php/);
assert.match(workflow,/cloud-hosting-health-v240\.php/);
assert.match(workflow,/cloud-hosting-v240-contract\.mjs/);
assert.match(workflow,/cloud-hosting-v240-mysql\.php/);

console.log('Cloud Hosting V2 Section 4 health recovery contract: PASS');
