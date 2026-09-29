import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const sync=read('includes/cloud-hosting-v120.php');
const bootstrap=read('includes/bootstrap.php');
const setup=read('setup.php');
const upgrade=read('upgrade.php');
const workflow=read('.github/workflows/cloud-hosting-v100.yml');

for(const table of ['cloud_hosting_entitlement_sync','cloud_hosting_site_sync','cloud_hosting_deployments','cloud_hosting_edge_certificates','cloud_hosting_route_credentials']){
  assert.ok(sync.includes('CREATE TABLE IF NOT EXISTS '+table),`missing ${table}`);
}
assert.match(sync,/hosting\.entitlements\.reconcile/);
assert.match(sync,/hosting\.site\.reconcile/);
assert.match(sync,/hosting\.route\.reconcile/);
assert.match(sync,/hosting\.deployment\.begin/);
assert.match(sync,/hosting\.deployment\.chunk/);
assert.match(sync,/hosting\.deployment\.commit/);
assert.match(sync,/hosting\.deployment\.rollback/);
assert.match(sync,/VP3_CLOUD_HOSTING_DEPLOY_CHUNK_BYTES=98304/);
assert.match(sync,/VP3_CLOUD_HOSTING_MAX_PACKAGE_BYTES=67108864/);
assert.match(sync,/base64_encode\(\$chunk\)/);
assert.match(sync,/next_chunk/);
assert.match(sync,/package_sha256/);
assert.match(sync,/Deployment idempotency key/);
assert.match(sync,/Failed deployment requires a new idempotency key/);
assert.match(sync,/Another Hosting deployment operation is already in progress for this site/);
assert.match(sync,/state IN \('pending','transferring','committing','interrupted'\)/);
assert.match(sync,/hosting\.deployment\.status/);
assert.match(sync,/transient_failure_resume_with_same_key'\s*=>\s*true/);
assert.match(sync,/single_inflight_operation_per_site'\s*=>\s*true/);
assert.match(sync,/deployment_status_refresh'\s*=>\s*true/);
assert.match(sync,/deployment_entitlement_revalidation'\s*=>\s*true/);
assert.match(sync,/state\]??''\)==='applied'/);
assert.match(sync,/Hosting deployment package must be a ZIP archive/);
assert.match(sync,/vp3_cloud_hosting_v120_assert_deployment_entitled/);
assert.match(sync,/Rollback idempotency key/);
assert.match(sync,/previous_release_id=active_release_id,active_release_id=/);

assert.match(sync,/fingerprint=hash\('sha256'/);
assert.match(sync,/SELECT revision,fingerprint FROM cloud_hosting_entitlement_sync WHERE user_id=\? FOR UPDATE/);
assert.match(sync,/max_public_routes/);
assert.match(sync,/max_storage_bytes_per_site/);
assert.match(sync,/max_sqlite_bytes_per_site/);
assert.match(sync,/allowed_runtimes/);

assert.match(sync,/homeserver_vp3_encrypt\(\$token\)/);
assert.match(sync,/homeserver_vp3_decrypt\(\$encoded\)/);
assert.match(sync,/cloud_hosting_route_credentials/);
assert.match(sync,/cloud_hosting_edge_certificates/);
assert.match(sync,/certificate_not_after/);
assert.match(sync,/Cloud-edge certificate is expired/);
assert.doesNotMatch(sync,/private_key|BEGIN PRIVATE KEY|certificate_key/);

assert.match(sync,/vp3_cloud_hosting_v120_public_remote/);
assert.match(sync,/raw_package_persisted'\s*=>\s*false/);
assert.match(sync,/cloud_edge_private_key_persisted'\s*=>\s*false/);

assert.match(bootstrap,/cloud-hosting-v120\.php/);
assert.match(setup,/vp3_cloud_hosting_v120_ensure_schema\(\$pdo\)/);
assert.match(upgrade,/vp3_cloud_hosting_v120_schema_ready\(\)/);
assert.match(upgrade,/vp3_cloud_hosting_v120_ensure_schema\(\$pdo\)/);
assert.match(workflow,/cloud-hosting-v120\.php/);
assert.match(workflow,/cloud-hosting-v120-mysql\.php/);
assert.match(workflow,/cloud-hosting-v120-contract\.mjs/);
assert.match(workflow,/extensions: pdo_mysql,sodium,openssl,curl,zip/);

console.log('Cloud Hosting V1 Section 3 architecture contract: PASS');
