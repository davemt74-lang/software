import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const service = read('includes/homeserver-cloud-pairing-v1200.php');
const actions = read('includes/homeserver-cloud-pairing-actions-v1200.php');
const accountPairing = read('includes/homeserver-account-pairing-v1210.php');
const relayLifecycle = read('includes/homeserver-relay-lifecycle-v1210.php');
const api = read('api/homeserver-connection-v1200.php');
const redeemApi = read('api/homeserver-pair-v1210.php');
const legacyApi = read('api/homeserver-status.php');
const page = read('settings-homeserver.php');
const js = read('homeserver-settings-v1210.js');
const lifecycle = read('homeserver-settings-lifecycle-v1200.js');
const nav = read('includes/member-navigation.php');
const base = read('includes/homeserver-vp3.php');

// The HomeServer app-pairing protocol remains the claim-token bearer protocol;
// account-token-v1 now owns the user-facing bootstrap flow.
assert.match(service, /VP3_HOMESERVER_PAIRING_PROTOCOL\s*=\s*'claim-v1'/);
assert.match(service, /'pair\.request'/);
assert.match(service, /homeserver_vp3_check_pairing/);
assert.match(service, /homeserver_vp3_encrypt\(\$relayToken\)/);
assert.match(service, /homeserver_vp3_encrypt\(\(string\)\$pairing\['claim_token'\]\)/);
assert.match(service, /FILTER_FLAG_NO_PRIV_RANGE\s*\|\s*FILTER_FLAG_NO_RES_RANGE/);
assert.match(service, /VP3_HOMESERVER_RELAY_ALLOWED_HOSTS/);
assert.match(service, /VP3_HOMESERVER_ALLOW_PRIVATE_RELAY/);

assert.match(accountPairing, /VP3_HOMESERVER_ACCOUNT_PAIRING_TTL_SECONDS\s*=\s*900/);
assert.match(accountPairing, /CREATE TABLE IF NOT EXISTS homeserver_pairing_tokens/);
assert.match(accountPairing, /token_hash CHAR\(64\)/);
assert.match(accountPairing, /bin2hex\(random_bytes\(32\)\)/);
assert.match(accountPairing, /VP3-(?:[^\n]*)/);
assert.match(accountPairing, /status='redeeming'/);
assert.match(accountPairing, /FOR UPDATE/);
assert.match(accountPairing, /homeserver_vp3_relay_request\('POST', '\/v1\/claim'/);
assert.match(accountPairing, /homeserver_cloud_v1200_pair_request/);
assert.match(accountPairing, /homeserver_cloud_v1200_store_pending/);
assert.match(accountPairing, /homeserver_relay_v1210_release/);
assert.match(accountPairing, /status='awaiting_approval'/);
assert.match(accountPairing, /status='paired'/);
assert.doesNotMatch(accountPairing, /localStorage|sessionStorage|document\.cookie/);

assert.match(relayLifecycle, /\/v1\/session\/release/);
assert.match(relayLifecycle, /Authorization: Bearer/);
assert.match(relayLifecycle, /released/);
assert.doesNotMatch(relayLifecycle, /claim_code/);

assert.match(actions, /retained_previous_pairing/);
assert.match(actions, /\/v1\/session\/rotate/);
assert.match(actions, /homeserver_relay_v1210_release/);
assert.match(actions, /DELETE FROM homeserver_connections WHERE user_id=\?/);
const removeOffset = actions.indexOf('function homeserver_cloud_v1200_remove_pairing');
assert.ok(removeOffset >= 0);
const removeBody = actions.slice(removeOffset);
assert.ok(removeBody.indexOf('homeserver_relay_v1210_release') < removeBody.indexOf('DELETE FROM homeserver_connections'));

assert.match(api, /require_login\(\)/);
assert.match(api, /verify_csrf\(\)/);
assert.match(api, /generate_pairing_token/);
assert.match(api, /homeserver_account_v1210_generate_token/);
assert.match(api, /account_pairing/);
assert.match(api, /account-token-v1/);
assert.match(api, /start_pairing/); // compatibility for older HomeServer builds
assert.match(api, /pairing_status/);
assert.match(api, /homeserver_account_v1210_mark_paired/);
assert.match(api, /disconnect/);
assert.match(api, /remove/);
assert.doesNotMatch(api, /\brelay_token\b|\bclaim_token\b|\bhomeserver_token\b/);

assert.match(redeemApi, /REQUEST_METHOD/);
assert.match(redeemApi, /homeserver_account_v1210_redeem/);
assert.match(redeemApi, /pairing_token/);
assert.match(redeemApi, /relay_claim/);
assert.doesNotMatch(redeemApi, /require_login\(\)|verify_csrf\(\)/);
assert.doesNotMatch(redeemApi, /relay_token|claim_token|homeserver_token/);

assert.match(legacyApi, /homeserver-cloud-pairing-actions-v1200\.php/);
assert.doesNotMatch(legacyApi, /homeserver_vp3_disconnect\(\$userId\)/);

assert.match(page, /Generate Pairing Token/);
assert.match(page, /Enter token in HomeServer/);
assert.match(page, /Approve locally/);
assert.match(page, /No second code is required/);
assert.match(page, /account-token-v1/);
assert.match(page, /homeserver-settings-v1210\.js/);
assert.doesNotMatch(page, /HomeServer connection code/);
assert.doesNotMatch(page, /Approval code/);
assert.doesNotMatch(page, /Paste connection code from HomeServer/);
assert.match(nav, /'homeserver','HomeServer',url\('\/settings-homeserver\.php'\)/);

for (const state of ['not_connected','connecting','waiting_for_approval','connected','connection_error','disconnected']) {
  assert.match(js, new RegExp(state));
}
assert.match(js, /generate_pairing_token/);
assert.match(js, /Generate Pairing Token/);
assert.match(js, /navigator\.clipboard\.writeText/);
assert.match(js, /pairing_status/);
assert.match(js, /setTimeout\(tick,3000\)/);
assert.doesNotMatch(js, /claim_code|HomeServer connection code|Approval code/);
assert.match(lifecycle, /disconnected/);
for (const browserSource of [js, lifecycle]) {
  assert.doesNotMatch(browserSource, /localStorage|sessionStorage|document\.cookie/);
  assert.doesNotMatch(browserSource, /\brelay_token\b|\bclaim_token\b|\bhomeserver_token\b/);
}

assert.match(base, /aes-256-gcm/);
assert.match(base, /homeserver-vp3\.key/);

console.log('homeserver-cloud-pairing-v1200-contract: account-token-v1 ok');
