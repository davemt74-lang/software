import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const layer=fs.readFileSync(new URL('includes/system-apps-v240.php',root),'utf8');
const bridge=fs.readFileSync(new URL('includes/system-apps-v110.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');
const apps=fs.readFileSync(new URL('apps.php',root),'utf8');
const redeem=fs.readFileSync(new URL('private-app-share.php',root),'utf8');

assert.match(layer,/vp3\.user-app-private-share\.v1/);
assert.match(layer,/function vp3_user_app_share_create_v240/);
assert.match(layer,/function vp3_user_app_share_redeem_v240/);
assert.match(layer,/function vp3_user_app_share_revoke_v240/);
assert.match(layer,/package_sha256/);
assert.match(layer,/grant_code_hash/);
assert.match(layer,/recipient_user_id/);
assert.match(layer,/cloud_package_repository'\s*=>\s*false/);
assert.match(layer,/cloud_source_repository'\s*=>\s*false/);
assert.match(layer,/ownership_transfer'\s*=>\s*false/);
assert.match(layer,/marketplace'\s*=>\s*false/);
assert.match(bridge,/apps\.user\.distribution\.describe/);
assert.match(bootstrap,/system-apps-v240\.php/);
assert.match(apps,/user\.share\.create/);
assert.match(apps,/user\.share\.revoke/);
assert.match(redeem,/Accept Private App Share/);
assert.match(layer,/#code=/);
assert.match(redeem,/location\.hash/);
assert.match(redeem,/Expected package SHA-256/);

console.log('System Apps Section 11 Cloud private distribution contract: PASS');
