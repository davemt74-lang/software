import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const hosting=fs.readFileSync(new URL('includes/cloud-hosting-v100.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');
const setup=fs.readFileSync(new URL('setup.php',root),'utf8');
const upgrade=fs.readFileSync(new URL('upgrade.php',root),'utf8');
const subs=fs.readFileSync(new URL('includes/subscription-schema.php',root),'utf8');
const ai=fs.readFileSync(new URL('includes/ai-settings.php',root),'utf8');
const admin=fs.readFileSync(new URL('admin/ai.php',root),'utf8');

for(const table of ['cloud_hosting_sites','cloud_hosting_site_events']){
  assert.ok(hosting.includes('CREATE TABLE IF NOT EXISTS '+table));
  assert.ok(upgrade.includes(table)||upgrade.includes('vp3_cloud_hosting_schema_ready_v100'));
}

assert.match(hosting,/fk_cloud_hosting_site_homeserver/);
assert.match(hosting,/REFERENCES homeserver_connections\(user_id\)/);
assert.match(hosting,/desired_revision BIGINT UNSIGNED NOT NULL DEFAULT 1/);
assert.match(hosting,/observed_revision BIGINT UNSIGNED NOT NULL DEFAULT 0/);
assert.match(hosting,/vp3_cloud_hosting_desired_projection_v100/);
assert.match(hosting,/vp3_cloud_hosting_entitlement_snapshot_v100/);
assert.match(hosting,/hosting\.sites/);
assert.match(hosting,/hosting\.subdomains/);
assert.match(hosting,/hosting\.storage_mb_per_site/);
assert.match(hosting,/hosting\.sqlite_mb_per_site/);
assert.match(hosting,/hosting\.php_access/);
assert.match(hosting,/slug IN \('basic','basic-user'\)/);
assert.ok(hosting.includes("$upsert->execute([$id,'hosting.sites',1,1]);"));
assert.ok(hosting.includes("$upsert->execute([$id,'hosting.subdomains',1,1]);"));

for(const key of [
  'hosting.access','hosting.sites','hosting.subdomains',
  'hosting.storage_mb_per_site','hosting.sqlite_mb_per_site','hosting.php_access',
]){
  assert.ok(subs.includes("'"+key+"'"),'Missing package entitlement '+key);
}

assert.match(bootstrap,/cloud-hosting-v100\.php/);
assert.ok(setup.indexOf('homeserver_vp3_ensure_schema($pdo)')<setup.indexOf('vp3_cloud_hosting_ensure_schema_v100($pdo)'),'Fresh setup must create HomeServer identity before Hosting FK schema');
assert.ok(upgrade.indexOf('homeserver_vp3_ensure_schema($pdo)')<upgrade.indexOf('vp3_cloud_hosting_ensure_schema_v100($pdo)'),'Upgrade must create HomeServer identity before Hosting FK schema');
assert.match(upgrade,/vp3_cloud_hosting_schema_ready_v100\(\)/);

assert.ok(ai.includes("'hosting_cpanel_api_token'"),'cPanel token must participate in master-key credential recovery');
assert.match(admin,/name="cpanel_server"/);
assert.match(admin,/name="cpanel_username"/);
assert.match(admin,/name="cpanel_api_token"/);
assert.match(admin,/ai_encrypt_secret\(\$cpanelToken\)/);
assert.match(admin,/remove_cpanel_token/);
assert.ok(!admin.includes("name=\"cpanel_api_token\" type=\"password\" value="),'Encrypted cPanel token must never be rendered into HTML');
assert.match(hosting,/https:\/\//);
assert.match(hosting,/2083/);
assert.match(hosting,/token_suffix/);

console.log('Cloud Hosting V1 Section 1 architecture contract: PASS');
