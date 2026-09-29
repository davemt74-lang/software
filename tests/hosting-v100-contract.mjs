import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const hosting=readFileSync('includes/hosting-v100.php','utf8');
const subscriptions=readFileSync('includes/subscription-schema.php','utf8');
const plugins=readFileSync('includes/plugin-registry-v320.php','utf8');
const bootstrap=readFileSync('includes/bootstrap.php','utf8');
const brain=readFileSync('includes/agent-brain-context-v142.php','utf8');
const pluginPage=readFileSync('plugins.php','utf8');
const upgrade=readFileSync('upgrade.php','utf8');

for(const key of ['hosting.access','hosting.subdomains','hosting.homeserver_sites','hosting.storage_mb','hosting.sqlite_mb','hosting.bandwidth_mb_monthly']){
  assert.ok(subscriptions.includes(key),key+' must be in the canonical package catalog');
}
assert.match(plugins,/'hosting'\s*=>[\s\S]*?'entitlement'=>'hosting\.access'/);
assert.match(hosting,/hosting_usage_ledger/);
assert.match(hosting,/INSERT IGNORE INTO package_entitlements/,'Basic defaults must be seed-only and admin-overridable');
assert.match(hosting,/LOWER\(name\)='basic user'/,'Basic User receives the initial hosting seed');
assert.match(hosting,/\['hosting\.subdomains',1,1\]/,'Basic User must seed one subdomain');
assert.match(hosting,/vp3_plugin_effective_state_v360\(\$pdo,\$user,'hosting'\)/,'Brain context must respect effective plugin state');
assert.match(bootstrap,/hosting-v100\.php/);
assert.match(brain,/vp3_hosting_agent_context_v100/);
assert.match(pluginPage,/hosting_enable/);
assert.match(pluginPage,/VP3 Hosting/);
assert.match(upgrade,/vp3_hosting_schema_ready_v100/);
assert.match(upgrade,/vp3_hosting_ensure_schema_v100/);
assert.doesNotMatch(hosting,/CPANEL_API|cpanel.*token|api[_-]?key/i,'Section 1 must not store cPanel credentials');
console.log('HOSTING_V100_CONTRACT=PASS');
