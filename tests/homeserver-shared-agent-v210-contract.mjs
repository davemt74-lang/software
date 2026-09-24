import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const shared=read('includes/homeserver-shared-agent-v210.php');
const bootstrap=read('includes/bootstrap.php');
const setup=read('setup.php');
const upgrade=read('upgrade.php');
const relay=read('includes/homeserver-https-relay-v1300.php');
const vp3=read('includes/homeserver-vp3.php');
const pairing=read('includes/homeserver-cloud-pairing-v1200.php');
const connectionApi=read('api/homeserver-connection-v1200.php');
const notifications=read('includes/notifications.php');
const brain=read('includes/agent-brain-context-v142.php');
const catalog=read('includes/user-agent-system-v236.php');
const profile=read('includes/profile-agent.php');
const page=read('settings-homeserver.php');
const ui=read('homeserver-settings-v1210.js');

const checks=[
 ['shared product release is 2.1',/VP3_HOMESERVER_RELEASE_VERSION = '2\.1'/.test(vp3)&&/VP3_HOMESERVER_SHARED_AGENT_VERSION='2\.1'/.test(shared)],
 ['shared state and event ledgers exist',/homeserver_agent_state/.test(shared)&&/homeserver_agent_events/.test(shared)],
 ['bootstrap loads v2.1 fabric after cognitive HomeServer domain support',bootstrap.indexOf('cognitive-domain-integration-v2390.php')<bootstrap.indexOf('homeserver-shared-agent-v210.php')],
 ['fresh setup installs shared Agent schema',/homeserver_shared_v210_ensure_schema\(\$pdo\)/.test(setup)],
 ['upgrade requires and installs shared Agent schema',/homeserver_shared_v210_schema_ready\(\)/.test(upgrade)&&/homeserver_shared_v210_ensure_schema\(\$pdo\)/.test(upgrade)],
 ['round trip queues authenticated system.ping and waits for result',/homeserver_https_v1300_queue\(\$userId,'system\.ping'/.test(shared)&&/homeserver_https_v1300_wait\(\$requestId,12000\)/.test(shared)&&/hash_equals\(\$nonce/.test(shared)],
 ['shared exchange uses the same paired HTTPS request queue',/shared\.context\.exchange/.test(shared)&&/homeserver_https_v1300_remote_operation/.test(shared)],
 ['Cloud snapshot includes Brain knowledge contacts tasks notifications',["memory","knowledge","contacts","tasks","notifications"].every(x=>shared.includes("'"+x+"'"))],
 ['shared payload is trimmed before relay exchange',/homeserver_shared_v210_fit_datasets/.test(shared)&&/170000/.test(shared)],
 ['material status changes are bridged into canonical cognition',/vp3_cognitive_homeserver_event_v2390/.test(shared)&&/homeserver\.connected/.test(shared)&&/homeserver\.disconnected/.test(shared)],
 ['disconnect and connection error create canonical priority notification',/homeserver_needs_attention/.test(shared)&&/create_notification/.test(shared)&&/connection_error/.test(shared)],
 ['Agent Chat attention poll actively reconciles HomeServer live state',/homeserver_shared_v210_refresh_cognition/.test(notifications)&&/notification_attention_after/.test(notifications)],
 ['Agent Chat attention gate recognizes the HomeServer priority notification',/needs_attention/.test(notifications)&&/notification_requires_attention/.test(notifications)],
 ['offline transition invalidates stale round-trip verification',/last_roundtrip_ok=0/.test(shared)&&/connection_error/.test(shared)&&/disconnected/.test(shared)],
 ['main Agent Brain retrieves HomeServer shared datasets',/homeserver_shared_v210_context_items/.test(brain)&&/shared Agent fabric/.test(brain)],
 ['HomeServer datasets participate in central Agent data policy',/homeserver_memory/.test(catalog)&&/homeserver_knowledge/.test(catalog)&&/homeserver_contacts/.test(catalog)&&/homeserver_tasks/.test(catalog)],
 ['Profile Agent uses same fabric only through existing policy gate',/homeserver_shared_v210_exchange/.test(profile)&&/user_data_policy_can_use_v236/.test(profile)&&/homeserver_memory/.test(profile)&&/homeserver_knowledge/.test(profile)&&/homeserver_contacts/.test(profile)&&/homeserver_tasks/.test(profile)],
 ['Profile Agent does not automatically receive HomeServer notifications',!/notifications'=>'homeserver_notifications/.test(profile)&&!/homeserver_notifications/.test(catalog)],
 ['normal status path reconciles into Agent Brain',/homeserver_shared_v210_reconcile_status/.test(pairing)],
 ['connection API exposes real test_connection action',/test_connection/.test(connectionApi)&&/homeserver_shared_v210_roundtrip/.test(connectionApi)],
 ['settings UI exposes Test Connection and round-trip diagnostics',/hsTestConnection/.test(page)&&/hsRoundTripStatus/.test(page)&&/hsSharedSync/.test(page)&&/hsBrainStatus/.test(page)&&/Cloud → HomeServer → Cloud verified/.test(ui)],
 ['legacy protocol filenames remain compatibility identifiers',/homeserver_https_v1300/.test(relay)&&/homeserver-cloud-pairing-v1200/.test(bootstrap)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`VP3 Cloud / HomeServer v2.1 shared Agent fabric: ${checks.length}/${checks.length} passed`);
