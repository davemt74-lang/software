import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const registry=read('includes/plugin-registry-v320.php');
const lifecycle=read('includes/plugin-lifecycle-v360.php');
const cloud=read('includes/tracky-cloud-v270.php');
const api=read('api/tracky-sync-v270.php');
const plugins=read('plugins.php');
const setup=read('setup.php');
const upgrade=read('upgrade.php');
const schema=read('includes/subscription-schema.php');
const docs=read('docs/TRACKY_V270_CLOUD_FOUNDATION.md');

assert.match(registry,/'tracky'\s*=>\s*\[/);
assert.match(registry,/'entitlement'=>'tracky\.access'/);
assert.match(schema,/'tracky\.access'\s*=>\s*\['label'=>'Tracky Physical Awareness'/);
assert.match(schema,/\$legacyEntitlements[\s\S]*?'tracky\.access'=>\[1,null\]/);
assert.match(lifecycle,/vp3_plugin_effective_state_v360/);

assert.match(cloud,/VP3_TRACKY_PROTOCOL_V270='physical_context\.v1'/);
assert.match(cloud,/CREATE TABLE IF NOT EXISTS tracky_cloud_sites/);
assert.match(cloud,/CREATE TABLE IF NOT EXISTS tracky_cloud_events/);
assert.match(cloud,/CREATE TABLE IF NOT EXISTS tracky_cloud_world_state/);
assert.match(cloud,/CREATE TABLE IF NOT EXISTS tracky_cloud_context/);
assert.match(cloud,/UNIQUE KEY uq_tracky_event \(user_id,site_id,event_id\)/);
assert.match(cloud,/tracky_cloud_v270_forbidden_key/);
assert.match(cloud,/raw\|frame\|frames\|image\|images\|video\|videos\|audio/);
assert.match(cloud,/cloud_derived','system_health','user_approved/);
assert.match(cloud,/tracky_cloud_v270_simulator_payload/);

assert.match(api,/homeserver_https_v1300_authenticate/);
assert.match(api,/tracky_cloud_v270_plugin_enabled/);
assert.match(api,/tracky_cloud_v270_ingest/);
assert.doesNotMatch(api,/pair\.request|pair\.status/);

assert.match(plugins,/name="action" value="tracky_enable"/);
assert.match(plugins,/name="action" value="tracky_disable"/);
assert.match(plugins,/physical_context\.v1/);
assert.match(setup,/tracky_cloud_v270_ensure_schema\(\$pdo\)/);
assert.match(upgrade,/tracky_cloud_v270_schema_ready\(\)/);
assert.match(upgrade,/tracky_cloud_v270_ensure_schema\(\$pdo\)/);
assert.match(docs,/idempotent/i);
assert.match(docs,/second Agent Brain/i);

console.log('TRACKY_V270_CLOUD_CONTRACT=PASS');
