import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const pairing = read('includes/homeserver-account-pairing-v1210.php');
const endpoint = read('api/homeserver-pair-v1210.php');
const accountApi = read('api/homeserver-connection-v1200.php');
const relayBootstrap = read('includes/homeserver-relay-bootstrap-v1210.php');
const relayBootstrapApi = read('api/homeserver-relay-bootstrap-v1210.php');

assert.match(pairing, /VP3_HOMESERVER_ACCOUNT_PAIRING_TTL_SECONDS\s*=\s*900/);
assert.match(pairing, /bin2hex\(random_bytes\(32\)\)/);
assert.match(pairing, /token_hash CHAR\(64\)/);
assert.match(pairing, /SELECT \* FROM homeserver_pairing_tokens WHERE token_hash=\? LIMIT 1 FOR UPDATE/);
assert.match(pairing, /status='redeeming'/);
assert.match(pairing, /function homeserver_account_v1210_redeem\(string \$rawToken, string \$relayClaim, string \$expectedDeviceId\)/);
assert.match(pairing, /\^hs-\[a-f0-9\]\{24\}\$/);
assert.match(pairing, /hash_equals\(\$expectedDeviceId, \$deviceId\)/);
assert.match(pairing, /homeserver_relay_v1210_release\(\$relayToken\)/);
assert.match(pairing, /status='awaiting_approval'/);
assert.match(pairing, /status='paired'/);

assert.match(endpoint, /\$pairingToken/);
assert.match(endpoint, /\$relayClaim/);
assert.match(endpoint, /\$deviceId/);
assert.match(endpoint, /homeserver_account_v1210_redeem\(\$pairingToken, \$relayClaim, \$deviceId\)/);
assert.doesNotMatch(endpoint, /require_login\(\)|verify_csrf\(\)/);
assert.doesNotMatch(endpoint, /['\"]relay_token['\"]|['\"]claim_token['\"]|['\"]homeserver_token['\"]/);

assert.match(accountApi, /generate_pairing_token/);
assert.match(accountApi, /homeserver_account_v1210_generate_token/);
assert.match(accountApi, /account-token-v1/);

assert.match(relayBootstrap, /homeserver_vp3_relay_base_url\(\)/);
assert.match(relayBootstrap, /\$wsScheme\s*=\s*'wss'/);
assert.match(relayBootstrap, /\/bridge'/);
assert.match(relayBootstrapApi, /pairing_protocol'=>'account-token-v1'/);
assert.match(relayBootstrapApi, /relay_websocket_url/);
assert.match(relayBootstrapApi, /homeserver_relay_bootstrap_v1210_websocket_url\(\)/);
assert.doesNotMatch(relayBootstrapApi, /require_login\(\)|verify_csrf\(\)/);

console.log('homeserver-account-pairing-v1210-contract: ok');
