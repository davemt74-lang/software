import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const layer=fs.readFileSync(new URL('includes/system-apps-v260.php',root),'utf8');
const bridge=fs.readFileSync(new URL('includes/system-apps-v110.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');
const router=fs.readFileSync(new URL('includes/agent-tool-authorization-v400.php',root),'utf8');

assert.match(layer,/vp3\.unified-app-manager\.v1/);
assert.match(layer,/function vp3_app_manager_status_v260/);
assert.match(layer,/function vp3_app_manager_query_v260/);
assert.match(layer,/app_manager_cloud_registry'\s*=>\s*false/);
assert.match(layer,/vp3_optional_app_library'\s*=>\s*true/);
assert.match(layer,/core_homeserver_features_are_apps'\s*=>\s*false/);
assert.match(layer,/app_store'\s*=>\s*false/);
assert.match(bridge,/apps\.manager\.status/);
assert.match(bootstrap,/system-apps-v260\.php/);
assert.match(router,/vp3_app_manager_query_v260/);

console.log('Section 13 Unified VP3 App Manager Cloud contract: PASS');
