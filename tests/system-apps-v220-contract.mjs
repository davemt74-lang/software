import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const v110=fs.readFileSync(new URL('includes/system-apps-v110.php',root),'utf8');
const v220=fs.readFileSync(new URL('includes/system-apps-v220.php',root),'utf8');
const auth=fs.readFileSync(new URL('includes/agent-tool-authorization-v400.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');

assert.match(v110,/apps\.user\.list/);
assert.match(v110,/apps\.user\.status/);
assert.match(v220,/vp3\.user-app-cloud-projection\.v1/);
assert.match(v220,/function vp3_user_apps_snapshot_v220/);
assert.match(v220,/function vp3_user_apps_agent_query_v220/);
assert.match(v220,/'registry'=>'homeserver'/);
assert.match(v220,/'cloud_registry'=>false/);
assert.match(v220,/'user_app_sdk_version'=>'1\.1'/);
assert.match(v220,/'cloud_user_app_projection'=>true/);
assert.match(v220,/'starter_release_integration'=>true/);
assert.match(v220,/'starter_permission_integration'=>true/);
assert.match(v220,/'starter_data_recovery_integration'=>true/);
assert.match(auth,/vp3_user_apps_agent_query_v220/);
assert.match(bootstrap,/system-apps-v220\.php/);

console.log('System Apps Section 9 Cloud user app SDK integration: PASS');
