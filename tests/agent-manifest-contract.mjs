import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const bootstrap = read('includes/bootstrap.php');
const manifest = read('includes/agent-manifest.php');
const api = read('api/agent-manifest.php');
const contentApi = read('api/agent-content.php');
const htaccess = read('.htaccess');

assert.ok(bootstrap.includes("require_once __DIR__.'/agent-manifest.php';"), 'bootstrap must load Agent Manifest runtime');
assert.ok(htaccess.includes('^\\.well-known/vp3-agent/'), 'Apache routing must expose the native VP3 well-known Agent Manifest');
assert.ok(htaccess.includes('api/agent-manifest.php?username=$1'), 'well-known route must resolve to the canonical manifest API');

assert.ok(manifest.includes("'schema'=>'vp3-agent-manifest'"), 'manifest must publish a stable schema identifier');
assert.ok(manifest.includes("'structured_content'=>true"), 'native profile manifest must advertise structured public content');
assert.ok(manifest.includes("'agent_messaging_requires_approval'=>$messagingAvailable"), 'manifest must state that Agent Messaging requires approval');
assert.ok(manifest.includes("'agent_access_request'=>$accessRequest"), 'manifest must advertise the access-request endpoint rather than unrestricted messaging');
assert.ok(manifest.includes("vp3_agent_messaging_allowed($ownerUser)"), 'messaging discovery must respect the canonical subscription entitlement');
assert.ok(manifest.includes("personal_capability_has_v242('profile_chat.access',$ownerUser)"), 'messaging discovery must require Profile Agent chat capability');
assert.ok(manifest.includes('Private VP3, CRM, Analytics and HomeServer data are never included'), 'manifest must explicitly maintain the private-data boundary');
assert.ok(manifest.includes("foreach(['tracks'=>'music','shows'=>'shows','posts'=>'posts','merch'=>'merch','photos'=>'photos']"), 'manifest must expose bounded public content collections');

assert.ok(api.includes("header('Content-Type: application/json; charset=UTF-8')"), 'manifest endpoint must be JSON');
assert.ok(api.includes("header('Cache-Control: public, max-age=300')"), 'manifest endpoint must use bounded public caching');
assert.ok(api.includes("header('Access-Control-Allow-Origin: *')"), 'manifest endpoint must be machine-readable cross-origin');
assert.ok(api.includes("empty($profile['is_public'])"), 'manifest endpoint must reject non-public profiles');
assert.ok(api.includes('vp3_radar_record_native_profile_request'), 'structured manifest reads must feed Agent Radar');

assert.ok(contentApi.includes("Content-Type: application/json"), 'structured content endpoint must return JSON');
assert.ok(contentApi.includes('vp3_agent_content_collection'), 'structured content endpoint must use the canonical public-content projection');
assert.ok(!manifest.includes('ai_token_credits'), 'manifest must never expose token ledger internals');
assert.ok(!manifest.includes('vp3_agent_policies'), 'manifest must never expose Gateway policy rows');
assert.ok(!manifest.includes('agent_brain'), 'manifest must never expose private Agent Brain records');

console.log('AGENT_MANIFEST_CONTRACT=PASS');
