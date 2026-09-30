import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const layer=fs.readFileSync(new URL('includes/system-apps-v250.php',root),'utf8');
const apps=fs.readFileSync(new URL('apps.php',root),'utf8');
const redeem=fs.readFileSync(new URL('private-app-share.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');
const router=fs.readFileSync(new URL('includes/agent-tool-authorization-v400.php',root),'utf8');
const snapshot=fs.readFileSync(new URL('includes/system-apps-v220.php',root),'utf8');

assert.match(layer,/vp3\.user-app-trusted-share-lifecycle\.v1/);
assert.match(layer,/function vp3_user_app_share_sync_installs_v250/);
assert.match(layer,/function vp3_user_app_share_reissue_update_v250/);
assert.match(layer,/function vp3_user_app_share_lifecycle_v250/);
assert.match(layer,/function vp3_user_app_share_agent_query_v250/);
assert.match(layer,/automatic_updates'\s*=>\s*false/);
assert.match(layer,/revocation_uninstalls_app'\s*=>\s*false/);
assert.match(layer,/permission_delta_json/);
assert.match(layer,/schema_from/);
assert.match(apps,/user\.share\.update/);
assert.match(apps,/Update available/);
assert.match(redeem,/Update review/);
assert.match(bootstrap,/system-apps-v250\.php/);
assert.match(router,/vp3_user_app_share_agent_query_v250/);
assert.match(snapshot,/'distribution'/);

console.log('System Apps Section 12 trusted share lifecycle contract: PASS');
