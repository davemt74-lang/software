import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

const bootstrap = read('includes/bootstrap.php');
const gateway = read('includes/agent-radar-gateway.php');
const policyApi = read('api/agent-radar-policy.php');
const serverApi = read('api/radar-server-collect.php');
const sitesUi = read('profile-agent-radar-sites.js');
const gatewayUi = read('profile-agent-radar-gateway.js');
const gatewayCss = read('profile-agent-radar-gateway.css');
const portal = read('profile-agent.php');

assert.ok(bootstrap.includes("require_once __DIR__.'/agent-radar-gateway.php';"), 'bootstrap must load Agent Gateway after Radar server runtime');
assert.ok(gateway.includes("const VP3_RADAR_GATEWAY_ACTIONS = ['allow','monitor','limit','block']"), 'Gateway must expose the simple four-action model');
assert.ok(gateway.includes('vp3_agent_policies'), 'Gateway must use the canonical policy table');
assert.ok(gateway.includes('(property_id IS NULL OR property_id=?)'), 'Gateway policies must support global or property-specific scope');
assert.ok(gateway.includes('(agent_contact_id IS NULL OR agent_contact_id=?)'), 'Gateway policies must support specific Agent CRM contacts');
assert.ok(gateway.includes("operator_name='' OR LOWER(operator_name)=LOWER(?)"), 'Gateway policies must support operator matching');
assert.ok(gateway.includes("visitor_class='' OR visitor_class=?"), 'Gateway policies must support agent-class matching');
assert.ok(gateway.includes('vp3_radar_gateway_path_matches'), 'Gateway must support path-level policy matching');
assert.ok(gateway.includes('vp3_radar_gateway_policy_specificity'), 'Gateway must deterministically prefer more specific policies');
assert.ok(gateway.includes("'action'=>'monitor'"), 'default Gateway behavior must be monitor, not implicit block');
assert.ok(gateway.includes("'status_code'=>$action==='block'?403:200"), 'block policy must produce 403 enforcement');
assert.ok(gateway.includes("$decision['status_code']=429"), 'limit policy must produce 429 when its budget is exhausted');
assert.ok(gateway.includes("'retry_after'=>1800") || gateway.includes("$decision['retry_after']=1800"), 'rate-limited agents must receive an exact bounded 1800-second retry window');
assert.ok(gateway.includes("'agent_policy_blocked'"), 'blocked/rate-limited attempts must be recorded in the canonical Radar event stream');
assert.ok(gateway.includes("'radar_security_action'"), 'blocked requests must surface through the existing high-attention notification channel');
assert.ok(gateway.includes('vp3_radar_gateway_set_contact_policy'), 'contact-level policy write service must exist');
assert.ok(gateway.includes('WHERE id=? AND owner_user_id=?'), 'contact policy writes must remain owner scoped');
assert.ok(gateway.includes("property_id IS NULL") && gateway.includes("path_pattern='*'"), 'simple contact controls must create a global all-sites/all-paths policy');
assert.ok(gateway.includes('vp3_radar_gateway_server_collect'), 'server collector must route through Gateway before recording allowed traffic');
assert.ok(gateway.indexOf('vp3_radar_gateway_decision') < gateway.indexOf("$decision['recorded']=vp3_radar_server_collect"), 'Gateway decision must occur before allowed request recording');
assert.ok(gateway.includes('if(empty($decision[\'allowed\']))'), 'denied requests must stop before normal server collection');

assert.ok(policyApi.includes("has_permission('account.access',$user)"), 'Gateway policy API must require account access');
assert.ok(policyApi.includes("personal_capability_has_v242('profile_agent.access',$user)"), 'Gateway policy API must require Profile Agent/Radar access');
assert.ok(policyApi.includes('hash_equals(csrf_token(),$csrf)'), 'Gateway policy writes must require CSRF');
assert.ok(policyApi.includes('strlen($raw)>8192'), 'Gateway policy API must cap request bodies');
assert.ok(!policyApi.includes("$_GET['owner"), 'Gateway policy API must never accept arbitrary owner ids');
assert.ok(policyApi.includes("$action==='set_contact_policy'"), 'Gateway API must expose the per-contact policy action');

assert.ok(serverApi.includes('vp3_radar_gateway_server_collect'), 'authenticated server collector must return Gateway decisions');
assert.ok(serverApi.includes("'decision'=>$decision"), 'server collector response must expose the enforceable decision');
assert.ok(serverApi.includes("'reason'=>'collector_error'"), 'collector errors must explicitly fail open');
assert.ok(serverApi.includes("'allowed'=>true"), 'collector error fallback must keep the remote site available');

assert.ok(sitesUi.includes('Only likely automation asks'), 'remote gate must keep normal human requests local');
assert.ok(sitesUi.includes("if (!preg_match('/(?:chatgpt-user"), 'remote gate must locally classify likely automation before contacting VP3');
assert.ok(sitesUi.includes("$decision['allowed'] !== false"), 'remote gate must only deny on an explicit VP3 denial');
assert.ok(sitesUi.includes("http_response_code($status)"), 'remote gate must enforce returned HTTP status before page output');
assert.ok(sitesUi.includes("header('Retry-After: '.$retry)"), 'remote gate must apply retry guidance for 429 limits');
assert.ok(sitesUi.includes('Fail open if VP3 is unreachable'), 'remote gate must document fail-open availability behavior');
assert.ok(sitesUi.includes('CURLOPT_CONNECTTIMEOUT_MS => 250') && sitesUi.includes('CURLOPT_TIMEOUT_MS => 500'), 'remote Gateway call must be tightly bounded');
assert.ok(!sitesUi.includes("$_SERVER['REMOTE_ADDR']"), 'remote Gateway must not transmit visitor IP addresses');

assert.ok(portal.includes("'radarPolicyEndpoint'=>url('/api/agent-radar-policy.php')"), 'Profile Agent must expose the Gateway policy endpoint to its Radar UI');
assert.ok(portal.includes('/profile-agent-radar-gateway.css'), 'Profile Agent must load isolated Gateway styles');
assert.ok(portal.includes('/profile-agent-radar-gateway.js'), 'Profile Agent must load per-contact Gateway controls');
assert.ok(gatewayUi.includes("['allow','monitor','limit','block']") || (gatewayUi.includes("'allow'")&&gatewayUi.includes("'monitor'")&&gatewayUi.includes("'limit'")&&gatewayUi.includes("'block'")), 'Gateway UI must expose all four simple contact actions');
assert.ok(gatewayUi.includes("action:'set_contact_policy'"), 'Gateway UI must save policies through the canonical API');
assert.ok(gatewayUi.includes('data-gateway-limit'), 'Gateway UI must expose a simple requests-per-30-minutes limit');
assert.ok(gatewayUi.includes('blocked everywhere'), 'Gateway UI must describe global contact blocking clearly');
assert.ok(gatewayUi.includes('server-side PHP gate'), 'Gateway UI must state that enforcement requires server-side installation');
assert.ok(gatewayCss.includes('.profile-agent-radar-gateway-contact.high-risk'), 'high-risk contacts must be visually distinct in Gateway controls');
assert.ok(gatewayCss.includes('@media(max-width:700px)'), 'Gateway controls must remain usable on small screens');

console.log('AGENT_RADAR_GATEWAY_CONTRACT=PASS');
