import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=path=>fs.readFileSync(new URL('../'+path,import.meta.url),'utf8');
const page=read('settings-homeserver.php');
const js=read('homeserver-settings-v1210.js');
const css=read('homeserver-settings-v1200.css');
const api=read('api/homeserver-connection-v1200.php');
const legacyApi=read('api/homeserver-status.php');
const actions=read('includes/homeserver-cloud-pairing-actions-v1200.php');
const cloud=read('includes/homeserver-cloud-pairing-v1200.php');
const account=read('includes/homeserver-account-pairing-v1210.php');
const scheduling=read('includes/homeserver-scheduling-connector-v620.php');
const commerce=read('includes/homeserver-commerce-agent-v1000.php');
const setup=read('setup.php');
const upgrade=read('upgrade.php');
const workflow=read('.github/workflows/homeserver-runtime-journey.yml');
const tokenStatusBody=account.slice(account.indexOf('function homeserver_account_v1210_token_status'),account.indexOf('function homeserver_account_v1210_begin_redeem'));

const checks=[
 ['connector foreign keys use canonical users.id INT UNSIGNED',
  /user_id INT UNSIGNED NOT NULL PRIMARY KEY/.test(scheduling)&&/user_id INT UNSIGNED NOT NULL PRIMARY KEY/.test(commerce)&&
  !/user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY/.test(scheduling)&&!/user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY/.test(commerce)],
 ['pairing and connector schemas expose readiness gates',
  account.includes('homeserver_account_v1210_schema_ready')&&scheduling.includes('homeserver_scheduling_v620_schema_ready')&&commerce.includes('homeserver_commerce_agent_v1000_schema_ready')],
 ['fresh setup installs HomeServer base pairing scheduling and commerce schemas',
  setup.includes('homeserver_vp3_ensure_schema($pdo)')&&setup.includes('homeserver_account_v1210_ensure_schema($pdo)')&&setup.includes('homeserver_scheduling_v620_ensure_schema($pdo)')&&setup.includes('homeserver_commerce_agent_v1000_ensure_schema($pdo)')],
 ['upgrade installs and requires HomeServer pairing/connector schemas',
  upgrade.includes('homeserver_account_v1210_ensure_schema($pdo)')&&upgrade.includes('homeserver_scheduling_v620_ensure_schema($pdo)')&&upgrade.includes('homeserver_commerce_agent_v1000_ensure_schema($pdo)')&&
  upgrade.includes('homeserver_account_v1210_schema_ready()')&&upgrade.includes('homeserver_scheduling_v620_schema_ready()')&&upgrade.includes('homeserver_commerce_agent_v1000_schema_ready()')],
 ['status API isolates optional connector failures from core pairing lifecycle',
  api.includes('homeserver_connection_v1200_optional_connector')&&api.includes("Connector status is unavailable until the database upgrade completes.")],
 ['legacy/global HomeServer status uses the same canonical live connection state',
  legacyApi.includes('homeserver_cloud_v1200_status($userId,$force)')&&legacyApi.includes('homeserver_account_v1210_revoke_user_tokens($userId)')&&!legacyApi.includes('homeserver_vp3_disconnect($userId)')],
 ['disconnect is fail-closed locally when relay rotation is unavailable',
  actions.includes("SET relay_token_enc=NULL,homeserver_token_enc=NULL")&&actions.includes("status='revoked'")&&actions.includes("'local_only'=>!$relayRevoked")],
 ['remove accepts disconnected or locally revoked rows and cannot deadlock on relay release',
  actions.includes("in_array($status, ['disconnected','revoked'], true)")&&actions.includes('catch (Throwable $ignored)')&&actions.includes("DELETE FROM homeserver_connections WHERE user_id=?")],
 ['account pairing tokens are revoked when connection lifecycle is reset',
  account.includes('homeserver_account_v1210_revoke_user_tokens')&&api.includes('homeserver_account_v1210_revoke_user_tokens($userId)')],
 ['GET status no longer performs lazy pairing-token DDL',
  tokenStatusBody.includes("!table_exists('homeserver_pairing_tokens')")&&!tokenStatusBody.includes('homeserver_account_v1210_ensure_schema($pdo)')],
 ['disconnected/revoked rows resolve directly to the canonical disconnected state',
  cloud.includes("in_array($rowStatus, ['disconnected','revoked'], true)")&&cloud.includes("$connectionState = 'disconnected'")],
 ['paired but offline records cannot be reported as connected',
  cloud.includes("elseif ($paired)")&&cloud.includes("$connectionState = 'connection_error'")],
 ['page uses one lifecycle renderer and exposes fresh-pair recovery',
  page.includes('hsRecoveryActions')&&page.includes('hsStartOver')&&page.includes('Start new pairing')&&!page.includes('homeserver-settings-lifecycle-v1200.js')],
 ['component hidden state is enforced against competing display rules',
  css.includes('.hs-settings [hidden]{display:none!important}')],
 ['client renders server error snapshots instead of leaving Checking UI stale',
  js.includes("if(e?.payload?.status)render(e.payload.status,{preserveAlert:true})")&&js.includes("els.title.textContent='HomeServer is not connected'")],
 ['client supports one-click stale pairing reset and fresh token generation',
  js.includes("post('reset_pairing')")&&api.includes("$action === 'reset_pairing'")&&api.includes('homeserver_account_v1210_generate_token($userId)')],
 ['disconnect UX reports local-only fallback instead of pretending relay success',
  js.includes("data.pairing?.local_only")&&js.includes('VP3 discarded its Cloud credentials even though the relay could not be reached.')],
 ['HomeServer CI runs recovery contract and syntax checks',
  workflow.includes('homeserver-settings-recovery-v1220-contract.mjs')&&workflow.includes('node --check homeserver-settings-v1210.js')&&workflow.includes('php -l api/homeserver-connection-v1200.php')]
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log('HomeServer settings recovery v12.20 contract: '+checks.length+'/'+checks.length+' passed');
