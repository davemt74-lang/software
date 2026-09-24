import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const relay=read('includes/homeserver-https-relay-v1300.php');
const pair=read('api/homeserver-https-pair-v1300.php');
const poll=read('api/homeserver-https-poll-v1300.php');
const vp3=read('includes/homeserver-vp3.php');
const agent=read('includes/homeserver-agent-v018.php');
const actions=read('includes/homeserver-cloud-pairing-actions-v1200.php');
const cloudPairing=read('includes/homeserver-cloud-pairing-v1200.php');
const modal=read('homeserver-vp3.js');
const settingsUi=read('homeserver-settings-v1210.js');
const setup=read('setup.php');
const upgrade=read('upgrade.php');
const pollFunction=relay.slice(
  relay.indexOf('function homeserver_https_v1300_poll'),
  relay.indexOf('function homeserver_https_v1300_queue')
);

const checks=[
 ['shared Cloud/HomeServer product release version is v2.3',
  vp3.includes("const VP3_HOMESERVER_RELEASE_VERSION = '2.3';")&&
  relay.includes("'cloud_version'=>VP3_HOMESERVER_RELEASE_VERSION")&&
  pair.includes("'cloud_version'=>$result['cloud_version']??VP3_HOMESERVER_RELEASE_VERSION")&&
  cloudPairing.includes("$raw['release_version'] = VP3_HOMESERVER_RELEASE_VERSION")],
 ['HTTPS relay adds session and request queue authorities',
  relay.includes('CREATE TABLE IF NOT EXISTS homeserver_https_sessions')&&relay.includes('CREATE TABLE IF NOT EXISTS homeserver_https_requests')],
 ['normal pairing consumes the existing VP3 account token without a WebSocket relay claim',
  relay.includes('homeserver_account_v1210_begin_redeem($pairingToken)')&&
  pair.includes('homeserver_https_v1300_pair')&&!pair.includes('relay_claim')],
 ['pairing stores only the session hash in Cloud and encrypts the HomeServer local bearer token',
  relay.includes("hash('sha256',$sessionToken)")&&relay.includes('homeserver_vp3_encrypt($homeServerToken)')&&
  relay.includes("'session_token'=>$sessionToken")],
 ['poll endpoint authenticates a HomeServer-only outbound HTTPS session',
  poll.includes('HTTP_AUTHORIZATION')&&poll.includes('Bearer\\s+')&&
  poll.includes('HTTP_X_VP3_HOMESERVER_SESSION')&&poll.includes('HTTP_X_HOMESERVER_DEVICE')&&
  poll.includes('homeserver_https_v1300_authenticate')],
 ['HomeServer to Cloud heartbeat updates canonical connection truth',
  relay.includes("last_seen_at=UTC_TIMESTAMP()")&&relay.includes("status='paired'")&&relay.includes('capabilities_json')],
 ['Cloud to HomeServer requests are queued and delivered over the same outbound HTTPS session',
  relay.includes("status='queued'")&&relay.includes("status='delivered'")&&relay.includes("'requests'=>$requests")],
 ['pairing returns a canonical absolute HTTPS poll URL',
  relay.includes("VP3_HOMESERVER_HTTPS_POLL_URL='https://vp3.me/api/homeserver-https-poll-v1300.php'")&&
  relay.includes("'poll_url'=>VP3_HOMESERVER_HTTPS_POLL_URL")],
 ['HomeServer results travel back to Cloud and complete the queued request',
  relay.includes("status=?,response_status=?,response_json=?,completed_at=UTC_TIMESTAMP()")&&
  relay.includes("$ok?'completed':'failed'")],
 ['only explicitly revoked HTTPS sessions return 410 while ordinary auth failures remain retryable',
  relay.includes("HomeServer HTTPS session was revoked.")&&
  poll.includes("http_response_code($revoked?410:401)")],
 ['poll transaction performs schema DDL before beginTransaction and never calls a DDL-capable connection helper inside the transaction',
  pollFunction.indexOf('homeserver_https_v1300_ensure_schema($pdo);') < pollFunction.indexOf('$pdo->beginTransaction();')&&
  !pollFunction.includes('homeserver_vp3_connection($userId)')&&
  pollFunction.includes('SELECT homeserver_token_enc FROM homeserver_connections WHERE user_id=? LIMIT 1')],
 ['poll endpoint distinguishes authentication rejection from internal relay processing failure',
  poll.includes("error_log('HomeServer HTTPS poll failed: '")&&
  poll.includes('http_response_code(500)')&&
  poll.includes("'HomeServer HTTPS relay could not process the exchange.'")],
 ['Cloud can synchronously await a bidirectional HTTPS result without a WebSocket broker',
  relay.includes('homeserver_https_v1300_remote_operation')&&relay.includes('homeserver_https_v1300_wait')&&
  !relay.includes('VP3_HOMESERVER_RELAY_URL')],
 ['canonical HomeServer status prefers the official HTTPS session when present',
  vp3.includes("homeserver_https_v1300_status($userId)")&&vp3.includes("'transport'=>'vp3_https'")],
 ['modern HTTPS rows cannot silently fall through to legacy custom WebSocket status',
  vp3.includes("if (empty($row['relay_token_enc']))")&&
  vp3.includes("keep it in the HTTPS lifecycle")],
 ['Cloud status wrapper derives standard connection truth once from the canonical VP3 status authority',
  cloudPairing.includes("$raw = homeserver_vp3_status($userId, $forceRefresh)")&&
  cloudPairing.includes("if ($transport === 'vp3_https')")&&
  cloudPairing.includes("$raw['reconnect_status'] = $connected ? 'not_needed' : ($paired ? 'automatic' : 'not_available')")],
 ['HomeServer Cloud surfaces continuously refresh the same live status and interpret SQL timestamps as UTC',
  modal.includes("+'Z'")&&modal.includes("},10000);")&&settingsUi.includes("},10000);")],
 ['Cloud settings expose no second approval or legacy relay pairing path',
  !settingsUi.includes('pairing_status')&&!settingsUi.includes('cancel_pairing')&&
  !settingsUi.includes("post('repair')")&&!settingsUi.includes('waiting_for_approval')],
 ['Agent runtime routes chat and usage through the transport-neutral per-user operation helper',
  vp3.includes('homeserver_vp3_remote_operation_for_user')&&
  agent.includes("homeserver_vp3_remote_operation_for_user($userId,'agent.chat'")&&
  agent.includes("homeserver_vp3_remote_operation_for_user($userId,'usage.write'")],
 ['disconnect and remove revoke HTTPS sessions fail-closed',
  actions.includes('homeserver_https_v1300_revoke($userId,false)')&&actions.includes('homeserver_https_v1300_revoke($userId,true)')],
 ['fresh setup and upgrade install HTTPS relay schema',
  setup.includes('homeserver_https_v1300_ensure_schema($pdo)')&&upgrade.includes('homeserver_https_v1300_ensure_schema($pdo)')],
 ['upgrade completeness requires HTTPS relay schema',
  upgrade.includes('homeserver_https_v1300_schema_ready()')],
 ['official HTTPS transport needs no broker environment variable',
  relay.includes("'transport'=>'vp3_https'")&&!pair.includes('VP3_HOMESERVER_RELAY_URL')&&!poll.includes('VP3_HOMESERVER_RELAY_URL')]
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log('VP3 Cloud / HomeServer v2.3 HTTPS relay contract: '+checks.length+'/'+checks.length+' passed');
