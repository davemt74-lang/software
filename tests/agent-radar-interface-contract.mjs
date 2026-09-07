import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const portal = read('profile-agent.php');
const js = read('profile-agent-portal.js');
const css = read('profile-agent-radar.css');
const endpoint = read('api/agent-radar.php');
const readModel = read('includes/agent-radar-portal.php');
const bootstrap = read('includes/bootstrap.php');

assert.ok(bootstrap.includes("require_once __DIR__.'/agent-radar-portal.php';"), 'bootstrap must load the Radar portal read model');
assert.ok(portal.includes('data-pa-tab="radar"'), 'Profile Agent sidebar must expose Agent Radar as a first-class tab');
assert.ok(portal.includes('data-pa-view="radar"'), 'Profile Agent portal must contain the Radar view');
assert.ok(portal.includes('id="profileAgentRadar"'), 'Radar view must have a dedicated render host');
assert.ok(portal.includes("'radarEndpoint'=>url('/api/agent-radar.php')"), 'portal config must expose the owner-only Radar endpoint');
assert.ok(portal.includes('/profile-agent-radar.css'), 'Radar styles must be isolated from the existing portal stylesheet');

assert.ok(endpoint.includes("has_permission('account.access',$user)"), 'Radar endpoint must require signed-in account access');
assert.ok(endpoint.includes("personal_capability_has_v242('profile_agent.access',$user)"), 'Radar endpoint must remain scoped to Profile Agent owners');
assert.ok(endpoint.includes('vp3_radar_portal_state($pdo,(int)$user[\'id\'])'), 'Radar endpoint must use the canonical owner-scoped read model');
assert.ok(!endpoint.includes('$_GET[\'owner'), 'Radar endpoint must not accept arbitrary owner ids');

for (const table of ['vp3_agent_contacts','vp3_radar_events','vp3_radar_sessions','vp3_radar_properties','vp3_agent_registry']) {
  assert.ok(readModel.includes(table), `Radar read model must project canonical ${table} data`);
}
assert.ok(readModel.includes('WHERE c.owner_user_id=?'), 'Agent contacts must be owner scoped');
assert.ok(readModel.includes('WHERE e.owner_user_id=?'), 'Radar events must be owner scoped');
assert.ok(readModel.includes('WHERE s.owner_user_id=?'), 'Radar sessions must be owner scoped');
assert.ok(!readModel.includes('REMOTE_ADDR'), 'Radar UI read model must never expose raw IP addresses');
assert.ok(!readModel.includes('user_agent_pattern'), 'Radar UI read model must not expose stored detection patterns');

for (const filter of ["['all','All']","['human','Human']","['ai_user_agent','AI Agent']","['ai_search','Search']","['ai_crawler','Crawler']","['automated_unknown','Unknown']"]) {
  assert.ok(js.includes(filter), `Radar UI must include filter ${filter}`);
}
assert.ok(js.includes('(state?.visits||[])'), 'Radar must reuse the existing human visitor stream');
assert.ok(js.includes('(radarState?.contacts||[])'), 'Radar must render Agent CRM contacts separately');
assert.ok(js.includes('(radarState?.events||[])'), 'Radar must render the canonical automated activity timeline');
assert.ok(js.includes('(radarState?.sessions||[])'), 'Radar must render recent automated sessions and paths');
assert.ok(js.includes('trust_score'), 'Agent contact cards must expose trust score');
assert.ok(js.includes('risk_score'), 'Agent contact cards must expose risk score');
assert.ok(js.includes('confidence_score'), 'Agent contact cards must expose signature confidence');
assert.ok(js.includes('relationship_status'), 'Agent contact cards must expose relationship status');
assert.ok(js.includes('Known signature'), 'UI must distinguish known signature recognition from verification');
assert.ok(js.includes('does not mean cryptographic identity verification'), 'UI must explain the verification boundary');
assert.ok(js.includes('does not display or persist raw IP addresses or raw User-Agent strings'), 'UI must explain Radar privacy boundaries');
assert.ok(js.includes("setInterval(()=>{if(document.visibilityState==='visible')refresh(true);},15000)"), 'Radar must refresh with the existing 15-second portal cadence');

assert.ok(css.includes('.profile-agent-radar-layout'), 'Radar must have a dedicated responsive layout');
assert.ok(css.includes('@media(max-width:760px)'), 'Radar interface must include small-screen behavior');
assert.ok(css.includes('.profile-agent-radar-session'), 'Radar must style recent session rows');
assert.ok(css.includes('.profile-agent-radar-event'), 'Radar must style the activity timeline');

console.log('AGENT_RADAR_INTERFACE_CONTRACT=PASS');
