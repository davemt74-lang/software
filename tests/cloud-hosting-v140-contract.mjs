import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const ui=read('includes/cloud-hosting-ui-v140.php');
const page=read('hosting.php');
const css=read('cloud-hosting-v140.css');
const js=read('cloud-hosting-v140.js');
const bootstrap=read('includes/bootstrap.php');
const nav=read('includes/member-navigation.php');
const agent=read('includes/cloud-hosting-agent-v130.php');
const workflow=read('.github/workflows/cloud-hosting-v100.yml');

assert.match(ui,/vp3_cloud_hosting_ui_v140_dashboard/);
assert.match(ui,/vp3_cloud_hosting_ui_v140_execute/);
assert.match(ui,/vp3_cloud_hosting_set_desired_state_v100/);
assert.match(ui,/vp3_cloud_hosting_v110_provision_dns/);
assert.match(ui,/vp3_cloud_hosting_v110_verify_dns/);
assert.match(ui,/vp3_cloud_hosting_v120_reconcile_site/);
assert.match(ui,/vp3_cloud_hosting_v120_deploy_package/);
assert.match(ui,/vp3_cloud_hosting_v120_rollback/);
assert.match(ui,/server_enforced_consequential_confirmation'\s*=>\s*true/);
assert.match(ui,/Confirm this consequential Hosting action before execution/);
assert.match(ui,/raw_cpanel_secret_exposed'\s*=>\s*false/);
assert.match(ui,/route_token_exposed'\s*=>\s*false/);
assert.doesNotMatch(ui,/hosting_cpanel_api_token/);
assert.doesNotMatch(ui,/route_token_enc/);

assert.match(page,/require_permission\('account\.access'\)/);
assert.match(page,/vp3_cloud_hosting_ui_v140_execute/);
assert.match(page,/is_uploaded_file\(\$tmp\)/);
assert.match(page,/VP3_CLOUD_HOSTING_MAX_PACKAGE_BYTES/);
assert.match(page,/name="deployment_zip"/);
assert.match(page,/data-hosting-confirmed/);
assert.match(page,/data-hosting-confirm=/);
assert.match(page,/Cloud Desired/);
assert.match(page,/HomeServer Observed/);
assert.match(page,/Provision DNS/);
assert.match(page,/Verify DNS/);
assert.match(page,/Rollback/);
assert.match(page,/Ask Agent/);
assert.doesNotMatch(page,/hosting_cpanel_api_token|route_token_enc|certificate_private/);

assert.match(js,/window\.confirm/);
assert.match(js,/confirmed\.value='1'/);
assert.match(js,/64\*1024\*1024/);
assert.match(css,/hosting-status-grid/);
assert.match(css,/@media\(max-width:720px\)/);

assert.match(bootstrap,/cloud-hosting-ui-v140\.php/);
assert.match(nav,/'hosting\.php'=>'hosting'/);
assert.match(nav,/'hosting','Cloud Hosting'/);
assert.match(agent,/vp3_cloud_hosting_set_desired_state_v100/);

assert.match(workflow,/cloud-hosting-v140-mysql\.php/);
assert.match(workflow,/cloud-hosting-v140-contract\.mjs/);
assert.match(workflow,/hosting\.php/);
assert.match(workflow,/cloud-hosting-v140\.js/);
assert.match(workflow,/cloud-hosting-v140\.css/);

console.log('Cloud Hosting V1 Section 5 UI contract: PASS');
