import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const service = read('includes/homeserver-cloud-pairing-v1200.php');
const actions = read('includes/homeserver-cloud-pairing-actions-v1200.php');
const api = read('api/homeserver-connection-v1200.php');
const legacyApi = read('api/homeserver-status.php');
const page = read('settings-homeserver.php');
const js = read('homeserver-settings-v1200.js');
const lifecycle = read('homeserver-settings-lifecycle-v1200.js');
const nav = read('includes/member-navigation.php');
const base = read('includes/homeserver-vp3.php');
const hsRemote = read('docs/homeserver-remote-contract-v1200.txt');

assert.match(service, /VP3_HOMESERVER_PAIRING_PROTOCOL\s*=\s*'claim-v1'/);
assert.match(service, /'pair\.request'/);
assert.match(service, /homeserver_vp3_check_pairing/);
assert.match(service, /\/v1\/claim/);
assert.match(service, /homeserver_vp3_encrypt\(\$relayToken\)/);
assert.match(service, /homeserver_vp3_encrypt\(\(string\)\$pairing\['claim_token'\]\)/);
assert.match(service, /FILTER_FLAG_NO_PRIV_RANGE\s*\|\s*FILTER_FLAG_NO_RES_RANGE/);
assert.match(service, /VP3_HOMESERVER_RELAY_ALLOWED_HOSTS/);
assert.match(service, /VP3_HOMESERVER_ALLOW_PRIVATE_RELAY/);
assert.match(service, /scheme[^\n]*!== 'https'/);
assert.doesNotMatch(service, /localStorage|sessionStorage|document\.cookie/);

assert.match(actions, /retained_previous_pairing/);
assert.match(actions, /homeserver_token_enc IS NOT NULL/);
assert.match(actions, /cancel_pairing/);
assert.match(actions, /pending_claim_token_enc=NULL/);
assert.match(actions, /function homeserver_cloud_v1200_disconnect/);
assert.match(actions, /\/v1\/session\/rotate/);
assert.match(actions, /\$replacement\s*=\s*trim/);
assert.match(actions, /homeserver_vp3_encrypt\(\$replacement\)/);
assert.match(actions, /homeserver_token_enc=NULL/);
assert.match(actions, /status='disconnected'/);
assert.match(actions, /function homeserver_cloud_v1200_remove_pairing/);
assert.match(actions, /Disconnect HomeServer before removing the Cloud pairing/);
assert.match(actions, /\/v1\/session\/release/);
assert.match(actions, /HomeServer relay did not release the device pairing/);
assert.match(actions, /DELETE FROM homeserver_connections WHERE user_id=\?/);
const disconnectOffset = actions.indexOf('function homeserver_cloud_v1200_disconnect');
const removeOffset = actions.indexOf('function homeserver_cloud_v1200_remove_pairing');
assert.ok(disconnectOffset >= 0 && removeOffset > disconnectOffset);
const disconnectBody = actions.slice(disconnectOffset, removeOffset);
assert.ok(disconnectBody.indexOf('/v1/session/rotate') < disconnectBody.indexOf("status='disconnected'"));
const removeBody = actions.slice(removeOffset);
assert.ok(removeBody.indexOf('/v1/session/release') >= 0);
assert.ok(removeBody.indexOf('/v1/session/release') < removeBody.indexOf('DELETE FROM homeserver_connections'));
assert.ok(removeBody.indexOf("released['device_id']") < removeBody.indexOf('DELETE FROM homeserver_connections'));

assert.match(api, /require_login\(\)/);
assert.match(api, /verify_csrf\(\)/);
assert.match(api, /start_pairing/);
assert.match(api, /pairing_status/);
assert.match(api, /reconnect/);
assert.match(api, /repair/);
assert.match(api, /disconnect/);
assert.match(api, /remove/);
assert.match(api, /Retry-After: 1/);
assert.match(api, /homeserver_commerce_agent_v1000_revoke[\s\S]*homeserver_scheduling_v620_revoke[\s\S]*homeserver_cloud_v1200_disconnect/);
assert.match(api, /homeserver_commerce_agent_v1000_revoke[\s\S]*homeserver_scheduling_v620_revoke[\s\S]*homeserver_cloud_v1200_remove_pairing/);
assert.doesNotMatch(api, /homeserver_cloud_v1200_revoke_access/);
assert.doesNotMatch(api, /\brelay_token\b|\bclaim_token\b|\bhomeserver_token\b/);

assert.match(legacyApi, /homeserver-cloud-pairing-actions-v1200\.php/);
assert.match(legacyApi, /homeserver_commerce_agent_v1000_revoke[\s\S]*homeserver_scheduling_v620_revoke[\s\S]*homeserver_cloud_v1200_disconnect/);
assert.doesNotMatch(legacyApi, /homeserver_vp3_disconnect\(\$userId\)/);

assert.match(page, /Settings[\s\S]*HomeServer/);
assert.match(page, /Start on HomeServer/);
assert.match(page, /Start Pairing/);
assert.match(page, /HomeServer connection code/);
assert.match(page, /Approval code/);
assert.match(page, /Connected Apps/);
assert.match(page, /Connect HomeServer/);
assert.match(page, /Re-pair/);
assert.match(page, /Disconnect/);
assert.match(page, /Remove Cloud pairing/);
assert.match(page, /csrf_token\(\)/);
assert.match(page, /homeserver-settings-lifecycle-v1200\.js/);
assert.match(nav, /'homeserver','HomeServer',url\('\/settings-homeserver\.php'\)/);

for (const state of ['not_connected','connecting','waiting_for_approval','connected','connection_error','disconnected']) {
  assert.match(js, new RegExp(state));
}
assert.match(js, /setTimeout\(tick,3000\)/);
assert.match(js, /pollCount>=220/);
assert.match(lifecycle, /disconnected/);
assert.match(lifecycle, /repair\.hidden=false/);
for (const browserSource of [js, lifecycle]) {
  assert.doesNotMatch(browserSource, /localStorage|sessionStorage|document\.cookie/);
  assert.doesNotMatch(browserSource, /\brelay_token\b|\bclaim_token\b|\bhomeserver_token\b/);
}

assert.match(base, /aes-256-gcm/);
assert.match(base, /homeserver-vp3\.key/);
assert.match(hsRemote, /HomeServer pairing head: 994ad4a0e9c6306a0d73dd3bbb9b9822e498ea3e/);
assert.match(hsRemote, /pair\.request/);
assert.match(hsRemote, /pair\.status/);
assert.match(hsRemote, /claim-v1/);
assert.match(hsRemote, /HomeServer connection code/);
assert.match(hsRemote, /Approval code/);
assert.match(hsRemote, /\/v1\/claim/);
assert.match(hsRemote, /\/v1\/session\/rotate/);
assert.match(hsRemote, /\/v1\/session\/release/);
assert.match(hsRemote, /release HTTP response never returns the fresh HomeServer connection code/);

console.log('homeserver-cloud-pairing-v1200-contract: ok');
