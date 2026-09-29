import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const route=read('includes/cloud-hosting-v110.php');
const bootstrap=read('includes/bootstrap.php');
const admin=read('admin/ai.php');
const setup=read('setup.php');
const upgrade=read('upgrade.php');
const workflow=read('.github/workflows/cloud-hosting-v100.yml');

for(const table of ['cloud_hosting_routes','cloud_hosting_provider_operations']){
  assert.ok(route.includes('CREATE TABLE IF NOT EXISTS '+table),`missing ${table}`);
}
assert.match(route,/ZoneEdit.*add_zone_record/s);
assert.match(route,/type'\s*=>\s*'CNAME'/);
assert.match(route,/\$recordName=substr\(\$hostname,0,-strlen\('\.'\.\$zone\)\)/);
assert.match(route,/\['provisioned','pending'\]/);
assert.match(route,/Authorization: cpanel /);
assert.match(route,/api2/);
assert.match(route,/dns_get_record\(\$hostname,DNS_CNAME\)/);
assert.match(route,/hash_equals\(\$target,\$candidate\)/);
assert.match(route,/tls_authority'\s*=>\s*'cloud_edge'/);
assert.match(route,/cpanel_tls_authority'\s*=>\s*false/);
assert.match(route,/homeserver_public_listener'\s*=>\s*false/);
assert.match(route,/uq_cloud_hosting_provider_request/);
assert.match(route,/SELECT id FROM cloud_hosting_sites WHERE id=\? FOR UPDATE/);
assert.match(route,/dns_state='provisioning'/);
assert.match(route,/DNS provisioning is already in progress for this hosted site/);
assert.match(route,/reused_existing_route/);
assert.match(route,/vp3_cloud_hosting_v110_assert_provision_entitled/);
assert.match(route,/hosting\.subdomains/);
assert.match(route,/Idempotency key was already used for a different Hosting operation/);
assert.match(route,/cPanel API2 response did not include an explicit result status/);
assert.doesNotMatch(route,/CURLOPT_SSL_VERIFYPEER\s*=>\s*false/);
assert.doesNotMatch(route,/CURLOPT_SSL_VERIFYHOST\s*=>\s*0/);

assert.match(bootstrap,/cloud-hosting-v110\.php/);
assert.match(setup,/vp3_cloud_hosting_v110_ensure_schema\(\$pdo\)/);
assert.match(upgrade,/vp3_cloud_hosting_v110_schema_ready\(\)/);
assert.match(upgrade,/vp3_cloud_hosting_v110_ensure_schema\(\$pdo\)/);

assert.match(admin,/name="cpanel_zone_domain"/);
assert.match(admin,/name="hosting_public_ingress_hostname"/);
assert.match(admin,/value="test_cpanel"/);
assert.match(admin,/DomainInfo', 'list_domains'/);
assert.match(admin,/TLS remains Cloud-edge authority/);

assert.match(workflow,/cloud-hosting-v110\.php/);
assert.match(workflow,/cloud-hosting-v110-mysql\.php/);
assert.match(workflow,/cloud-hosting-v110-contract\.mjs/);

console.log('Cloud Hosting V1 Section 2 architecture contract: PASS');
