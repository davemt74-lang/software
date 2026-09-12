import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const service = read('includes/homeserver-cloud-pairing-v1200.php');
const actions = read('includes/homeserver-cloud-pairing-actions-v1200.php');
const api = read('api/homeserver-connection-v1200.php');
const page = read('settings-homeserver.php');
const js = read('homeserver-settings-v1200.js');
const nav = read('includes/member-navigation.php');
const base = read('includes/homeserver-vp3.php');
const hsRemote = read('docs/homeserver-remote-contract-v1200.txt');

assert.match(service, /VP3_HOMESERVER_PAIRING_PROTOCOL\s*=\s*'claim-v1'/);
assert.match(service, /'pair\.request'/);
assert.match(service, /homeserver_vp3_check_pairing/);
assert.match(service, /\/v1\/claim/);
assert.match(service, /\/v1\/session\/rotate/);
assert.match(service, /homeserver_vp3_encrypt\(\$relayToken\)/);
assert.match(service, /homeserver_vp3_encrypt\(\(string\)\$pairing\['claim_token'\]\)/);
assert.match(service, /relay_token_enc=NULL,homeserver_token_enc=NULL/);
assert.match(service, /status='revoked'/);
assert.match(service, /FILTER_FLAG_NO_PRIV_RANGE\s*\|\s*FILTER_FLAG_NO_RES_RANGE/);
assert.match(service, /VP3_HOMESERVER_RELAY_ALLOWED_HOSTS/);
assert.match(service, /VP3_HOMESERVER_ALLOW_PRIVATE_RELAY/);
assert.match(service, /scheme[^\n]*!== 'https'/);
assert.doesNotMatch(service, /localStorage|sessionStorage|document\.cookie/);

assert.match(actions, /retained_previous_pairing/);
assert.match(actions, /homeserver_token_enc IS NOT NULL/);
assert.match(actions, /cancel_pairing/);
assert.match(actions, /pending_claim_token_enc=NULL/);

assert.match(api, /require_login\(\)/);
assert.match(api, /verify_csrf\(\)/);
assert.match(api, /start_pairing/);
assert.match(api, /pairing_status/);
assert.match(api, /reconnect/);
assert.match(api, /repair/);
assert.match(api, /disconnect/);
assert.match(api, /remove/);
assert.match(api, /Retry-After: 1/);
assert.match(api, /homeserver_cloud_v1200_revoke_access[\s\S]*homeserver_commerce_agent_v1000_revoke/);
assert.doesNotMatch(api, /relay_token|claim_token|homeserver_token/);

assert.match(page, /Settings[\s\S]*HomeServer/);
assert.match(page, /Connect HomeServer/);
assert.match(page, /Waiting for HomeServer approval/);
assert.match(page, /Re-pair/);
assert.match(page, /Disconnect/);
assert.match(page, /Remove Cloud pairing/);
assert.match(page, /csrf_token\(\)/);
assert.match(nav, /'homeserver','HomeServer',url\('\/settings-homeserver\.php'\)/);

for (const state of ['not_connected','connecting','waiting_for_approval','connected','connection_error','disconnected']) {
  assert.match(js, new RegExp(state));
}
assert.match(js, /setTimeout\(tick,3000\)/);
assert.match(js, /pollCount>=220/);
assert.doesNotMatch(js, /localStorage|sessionStorage|document\.cookie/);
assert.doesNotMatch(js, /relay_token|claim_token|homeserver_token/);

assert.match(base, /aes-256-gcm/);
assert.match(base, /homeserver-vp3\.key/);
assert.match(hsRemote, /HomeServer main: 2ec7c46521857d19d90395437dd56c8cc8108c6f/);
assert.match(hsRemote, /pair\.request/);
assert.match(hsRemote, /pair\.status/);
assert.match(hsRemote, /claim-v1/);
assert.match(hsRemote, /\/v1\/claim/);
assert.match(hsRemote, /\/v1\/session\/rotate/);

console.log('homeserver-cloud-pairing-v1200-contract: ok');
