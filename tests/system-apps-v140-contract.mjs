import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const svc=fs.readFileSync(new URL('includes/system-apps-v140.php',root),'utf8');
const page=fs.readFileSync(new URL('apps.php',root),'utf8');
const css=fs.readFileSync(new URL('system-apps-v100.css',root),'utf8');
const api=fs.readFileSync(new URL('api/system-apps-v100.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');

assert.match(svc,/vp3\.system-app-product-ux\.v1/);
assert.match(svc,/function vp3_system_apps_connection_snapshot_v140/);
assert.match(svc,/function vp3_system_apps_view_item_v140/);
assert.match(svc,/function vp3_system_apps_catalog_v140/);
assert.match(svc,/connection_aware_actions'\s*=>\s*true/);
assert.match(svc,/cleanup_pending_visibility'\s*=>\s*true/);
assert.match(svc,/hosted_filter'\s*=>\s*true/);
assert.match(page,/HomeServer <\?=e\(\(string\)\$catalog\['connection'\]\['label'\]\)\?>/);
assert.match(page,/data-app-filter="hosted"/);
assert.match(page,/data-app-filter-tags/);
assert.match(page,/Cleanup will retry when HomeServer reconnects/);
assert.match(page,/Verify Installation/);
assert.match(page,/Assign Hosting/);
assert.match(page,/Manage Hosting/);
assert.match(page,/Open/);
assert.match(css,/system-apps-connection/);
assert.match(css,/system-app-badge\.warning/);
assert.match(api,/vp3_system_apps_catalog_v140/);
assert.match(api,/vp3_system_apps_capability_v140/);
assert.match(bootstrap,/system-apps-v140\.php/);

console.log('System Apps V1 Section 13 unified product UX contract: PASS');
