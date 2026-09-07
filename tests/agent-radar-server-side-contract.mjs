import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const bootstrap = read('includes/bootstrap.php');
const server = read('includes/agent-radar-server.php');
const gateway = read('includes/agent-radar-gateway.php');
const sitesApi = read('api/agent-radar-sites.php');
const collectApi = read('api/radar-server-collect.php');
const sitesUi = read('profile-agent-radar-sites.js');
const sitesCss = read('profile-agent-radar-sites.css');

assert.ok(bootstrap.includes("require_once __DIR__.'/agent-radar-server.php';"), 'bootstrap must load server-side Radar runtime');
assert.ok(bootstrap.includes("require_once __DIR__.'/agent-radar-gateway.php';"), 'bootstrap must load Gateway after server-side Radar');

assert.ok(server.includes('bin2hex(random_bytes(VP3_RADAR_SERVER_TOKEN_BYTES))'), 'server token rotation must use cryptographic randomness');
assert.ok(server.includes("hash('sha256',$token)"), 'VP3 must persist only a token hash');
assert.ok(server.includes('secret_hash=?'), 'server token hash must use the canonical Radar property secret_hash field');
assert.ok(server.includes('secret_hash=NULL'), 'server token must be revocable');
assert.ok(server.includes('hash_equals($stored,hash('), 'server token verification must use constant-time hash comparison');
assert.ok(!server.includes('server_token VARCHAR'), 'server runtime must not introduce plaintext token storage');

assert.ok(sitesApi.includes("$action==='rotate_server_token'"), 'Connected Sites API must support server token creation/rotation');
assert.ok(sitesApi.includes("$action==='revoke_server_token'"), 'Connected Sites API must support server token revocation');
assert.ok(sitesApi.includes('hash_equals(csrf_token(),$csrf)'), 'server token mutations must remain CSRF protected');
assert.ok(sitesApi.includes('vp3_radar_server_enrich_site_state'), 'site state must expose only token configured status, not the stored hash');

assert.ok(collectApi.includes("REQUEST_METHOD']??'GET'))!=='POST'"), 'server collector must be POST only');
assert.ok(collectApi.includes('vp3_radar_external_property_by_key'), 'server collector must resolve an active connected property');
assert.ok(collectApi.includes('vp3_radar_server_request_token'), 'server collector must require the private server token');
assert.ok(collectApi.includes('vp3_radar_server_token_valid'), 'server collector must verify the private token hash');
assert.ok(collectApi.includes('strlen($raw)>16384'), 'server collector must bound request bodies');
assert.ok(collectApi.includes('vp3_radar_gateway_server_collect'), 'server collector must pass authenticated automation through Agent Gateway');
assert.ok(collectApi.includes("'decision'=>$decision"), 'server collector must return the Gateway decision to the remote site');
assert.ok(!collectApi.includes('REMOTE_ADDR'), 'server collector must not read or persist raw IP addresses');

assert.ok(server.includes('vp3_radar_match_agent($userAgent,$pdo)'), 'server Radar must match maintained agent signatures first');
assert.ok(server.includes('vp3_radar_native_identity($pdo'), 'known server-side agents must reuse canonical Agent CRM identities');
assert.ok(server.includes('vp3_radar_looks_automated($userAgent)'), 'server Radar must detect unknown automation without requiring JavaScript');
assert.ok(server.includes("$ownerUserId.'|server-side-unknown-automation'"), 'unknown server automation must aggregate into one bounded owner-level identity');
assert.ok(server.includes("'automated_unknown','unknown',35,20,15"), 'unknown server automation must retain explicit low-confidence trust/risk defaults');
assert.ok(server.includes('vp3_radar_external_session_at_cap'), 'server collector must reuse the bounded coarse-session cap');
assert.ok(server.includes("'external_agent_request'"), 'server-side requests must be distinguishable from browser page-view events');
assert.ok(server.includes("'collector'=>'server'"), 'server events must identify their collector source');
assert.ok(server.includes('vp3_radar_external_signals($session,$path)'), 'server Radar must reuse canonical risk signals');
assert.ok(server.includes('vp3_radar_external_notify'), 'server Radar must flow through canonical Brain/Main Feed notification behavior');
assert.ok(!server.includes('metadata_json') || !server.includes('user_agent'), 'raw user-agent must not be copied into Radar event metadata');
assert.ok(gateway.includes("$decision['recorded']=vp3_radar_server_collect"), 'allowed Gateway traffic must continue through canonical server Radar collection');

assert.ok(sitesUi.includes('const serverTokens=new Map()'), 'plaintext server tokens must exist only ephemerally in the current browser session');
assert.ok(sitesUi.includes("action:'rotate_server_token'"), 'Connected Sites UI must create/rotate server tokens');
assert.ok(sitesUi.includes("action:'revoke_server_token'"), 'Connected Sites UI must revoke server tokens');
assert.ok(sitesUi.includes('VP3 stores only the token hash'), 'UI must explain one-time plaintext token handling');
assert.ok(sitesUi.includes('Never place the token in HTML or client JavaScript'), 'UI must warn against exposing the server token');
assert.ok(sitesUi.includes("'user_agent' => $ua"), 'PHP integration must send the incoming user-agent transiently for server classification');
assert.ok(sitesUi.includes("'path' => $path"), 'PHP integration must send pathname only');
assert.ok(sitesUi.includes("'method' =>"), 'PHP integration must send request method');
assert.ok(!sitesUi.includes("'status_code' => http_response_code()"), 'pre-render Gateway must not depend on a final response status that does not exist yet');
assert.ok(sitesUi.includes("X-VP3-Radar-Token"), 'PHP integration must authenticate with the private server token header');
assert.ok(sitesUi.includes('CURLOPT_TIMEOUT_MS => 500'), 'PHP integration must use a short bounded network timeout');
assert.ok(sitesUi.includes("parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)"), 'PHP integration must exclude query strings');
assert.ok(!sitesUi.includes("$_SERVER['REMOTE_ADDR']"), 'PHP integration must not transmit visitor IP addresses');
assert.ok(sitesUi.includes('raw User-Agent values are not stored'), 'Connected Sites UI must disclose transient server classification behavior');
assert.ok(sitesUi.includes('before page output'), 'Connected Sites UI must explain that the server integration is an enforcement gate');
assert.ok(sitesCss.includes('.profile-agent-radar-server-secret'), 'server token reveal must have a dedicated protected-looking UI surface');
assert.ok(sitesCss.includes('@media(max-width:620px)'), 'server-side setup must remain responsive');

console.log('AGENT_RADAR_SERVER_SIDE_CONTRACT=PASS');
