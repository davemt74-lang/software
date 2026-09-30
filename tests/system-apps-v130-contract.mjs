import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const v100=fs.readFileSync(new URL('includes/system-apps-v100.php',root),'utf8');
const v110=fs.readFileSync(new URL('includes/system-apps-v110.php',root),'utf8');
const v130=fs.readFileSync(new URL('includes/system-apps-v130.php',root),'utf8');
const api=fs.readFileSync(new URL('api/system-apps-v100.php',root),'utf8');
const page=fs.readFileSync(new URL('apps.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');

assert.match(v100,/vp3_system_apps_before_ownership_revoke_v130/);
assert.match(v100,/'cleanup'=>\$cleanup/);
assert.match(v110,/apps\.system\.deactivate/);
assert.match(v110,/function vp3_system_apps_deactivate_v110/);
assert.match(v130,/vp3\.system-app-revocation\.v1/);
assert.match(v130,/vp3_system_apps_hosting_unbind_v120/);
assert.match(v130,/revocation_deactivate_pending/);
assert.match(v130,/function vp3_system_apps_reconcile_revocations_v130/);
assert.match(v130,/function vp3_system_apps_reconcile_all_v130/);
assert.match(v130,/ownership_revocation_unbinds_hosting'\s*=>\s*true/);
assert.match(v130,/ownership_revocation_deactivates_runtime'\s*=>\s*true/);
assert.match(v130,/offline_revocation_cleanup_persists'\s*=>\s*true/);
assert.match(v130,/revocation_cleanup_retries_on_reconcile'\s*=>\s*true/);
assert.match(v130,/revocation_preserves_app_data'\s*=>\s*true/);
assert.match(api,/vp3_system_apps_reconcile_all_v130/);
assert.match(api,/vp3_system_apps_capability_v130/);
assert.match(page,/vp3_system_apps_reconcile_all_v130/);
assert.match(bootstrap,/system-apps-v130\.php/);

console.log('System Apps audit revocation lifecycle contract: PASS');
