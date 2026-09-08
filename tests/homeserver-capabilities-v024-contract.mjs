import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const helper = read('includes/homeserver-capabilities-v024.php');
const delegation = read('includes/homeserver-agent-v025.php');
const bootstrap = read('includes/bootstrap.php');
const api = read('api/user-agent-system-v236.php');
const chat = read('api/chat-v236.php');
const loader = read('account-agent-settings-loader-v236.js');
const ui = read('account-homeserver-capabilities-v024.js');
const css = read('homeserver-capabilities-v024.css');
const runner = read('tools/run_recovery_baseline.py');

for (const key of ['agent_brain','inference','memory','knowledge','contacts','awareness','tools','skills','plugins','events','tasks','notifications']) {
  assert.match(helper, new RegExp(`'${key}'\\s*=>`), `catalog must include ${key}`);
}
for (const operation of ['memory.read','knowledge.search','contacts.search','awareness.list','tools.list','skills.list','plugins.list','events.list']) {
  assert.ok(helper.includes(`'${operation}'`), `read gateway must allow ${operation}`);
}
assert.doesNotMatch(helper, /CREATE\s+TABLE/i, 'v0.24 must reuse canonical HomeServer capability storage');
assert.match(helper, /homeserver_vp3_connection\(\$userId\)/, 'registry must read canonical HomeServer connection');
assert.match(helper, /capabilities_json/, 'registry must derive from canonical capabilities cache');
assert.match(helper, /legacy_assumed/, 'legacy Agent Brain compatibility must be explicit');
assert.match(helper, /unsupported_capability/, 'read gateway must be allowlisted');
assert.doesNotMatch(helper, /shell\.execute|filesystem\.read|http\.proxy/, 'unsafe HomeServer capabilities must not be routed');

assert.match(bootstrap, /homeserver-capabilities-v024\.php/, 'bootstrap must load v0.24 capability resolver');
assert.match(api, /homeserver_capability_v024_attach_state/, 'Agent settings state must expose capability registry');
assert.match(api, /refresh_homeserver_capabilities/, 'Agent settings must support explicit capability refresh');
assert.match(api, /homeserver_capability_v024_registry\(\(int\)\$user\['id'\], true\)/, 'refresh must force canonical HomeServer status discovery');

const toolIndex = chat.indexOf('agent_tool_execute_query');
const directCloudIndex = chat.indexOf("elseif($computePreference==='vp3_cloud')");
const registryIndex = chat.indexOf('homeserver_capability_v024_registry($userId,false)');
const homeAttemptIndex = chat.indexOf('homeserver_agent_v025_chat');
assert.ok(toolIndex >= 0 && homeAttemptIndex > toolIndex, 'existing VP3 tools must remain first in the canonical chat path');
assert.ok(directCloudIndex > toolIndex && registryIndex > directCloudIndex, 'direct VP3 Cloud must not refresh HomeServer capability status');
assert.match(chat, /homeserver_capability_v024_registry\(\$userId,false\)/, 'HomeServer routes must resolve the current capability registry');
assert.match(chat, /homeserver_capability_v024_resolve\(\$capabilityRegistry,'agent_brain','vp3_cloud'\)/, 'chat must resolve Agent Brain capability');
assert.match(chat, /\$homeReady=!empty\(\$brainCapability\['ready'\]\)/, 'compute route must use actual capability readiness');
assert.match(chat, /\$homeSupported=!empty\(\$brainCapability\['supported'\]\)/, 'chat must distinguish support from temporary readiness');
assert.match(chat, /try_homeserver'\]\)&&\$homeSupported/, 'HomeServer attempts must require advertised or explicit legacy support');
assert.match(chat, /capability_route/, 'capability provenance must persist with execution metadata');
assert.match(chat, /vp3_tool_handled/, 'VP3 tool execution must receive capability provenance');
assert.match(chat, /policy_vp3_cloud/, 'direct cloud policy must be distinguishable from fallback');
assert.match(chat, /homeserver_request_recovered/, 'request-time HomeServer recovery must be visible in provenance');
assert.match(chat, /homeserver_request_failed/, 'request-time HomeServer fallback must be visible in provenance');
assert.match(chat, /does not advertise the Agent Brain capability/, 'HomeServer-only mode must fail closed for unsupported Agent Brain');
assert.match(chat, /retrying supported paired HomeServers on each/, 'offline status must not disable v0.22 request-time recovery');

// v0.25 is a capability-negotiated extension of agent.chat. It must never be
// legacy-assumed and must leave VP3 as the canonical history store.
assert.match(chat, /homeserver-agent-v025\.php/, 'canonical Chat must load the delegation adapter');
assert.match(delegation, /agent\.delegation\.v1/, 'delegation must require explicit HomeServer advertisement');
assert.match(delegation, /homeserver_capability_v024_raw\(\$userId\)/, 'delegation discovery must reuse canonical capability cache');
assert.match(delegation, /homeserver_agent_v018_chat\(\$user,\$query,\$conversationId,\$cloudAllowed\)/, 'older HomeServers must retain v0.18 execution');
assert.match(delegation, /'external_conversation_id'=>'vp3:'\.\$conversationId/, 'VP3 conversation id must be sent as an external canonical reference');
assert.match(delegation, /'delegation'=>homeserver_agent_v025_persona/, 'active VP3 Agent persona must be delegated');
assert.match(delegation, /'history'=>\$boundedHistory/, 'bounded canonical VP3 history must be delegated');
assert.match(delegation, /'surface_context'=>\$surface/, 'sanitized VP3 surface context must be delegated');
assert.match(delegation, /agent_surface_v131_sanitize/, 'delegated surface context must pass through canonical sanitizer');
assert.match(delegation, /array_reverse\(array_slice\(\$history,-12\)\)/, 'history budget must prioritize recent turns');
assert.match(delegation, /'agent\.chat'/, 'delegation must reuse the existing remote operation');
assert.doesNotMatch(delegation, /homeserver_agent_v018_bind/, 'stateless delegation must not create a second HomeServer conversation mapping');
assert.doesNotMatch(delegation, /CREATE\s+TABLE|ALTER\s+TABLE/i, 'v0.25 must not add VP3 schema');
assert.doesNotMatch(delegation, /shell_exec|passthru|filesystem\.read|http\.proxy/i, 'delegation adapter must not add unsafe execution surfaces');
assert.match(chat, /\$execution\['brain_delegation'\]=homeserver_agent_v025_public_state\(\$homeResult\)/, 'safe delegation provenance must persist with existing execution metadata');
assert.match(chat, /INSERT INTO chat_messages \(conversation_id,user_id,role,message,context_json\)/, 'VP3 assistant persistence remains canonical');

assert.match(loader, /agent-compute-v024-20260908/, 'v0.24 assets must be cache-busted');
assert.match(loader, /homeserver-capabilities-v024\.css/, 'capability CSS must load on account pages');
assert.match(loader, /account-homeserver-capabilities-v024\.js/, 'capability UI must load on account pages');
assert.match(ui, /HomeServer Capabilities/, 'Agents & Data must show HomeServer capability routing');
assert.match(ui, /Agent capability routing/, 'capability card heading');
assert.match(ui, /Refresh HomeServer/, 'capability card must support refresh');
assert.match(ui, /MutationObserver/, 'capability UI must mount even when dynamic account scripts load out of order');
assert.match(ui, /Local and user-provider HomeServer execution does not consume VP3 cloud tokens/, 'UI must explain cloud billing boundary');
assert.doesNotMatch(ui, /relay_token|homeserver_token|capabilities_json/, 'browser UI must not expose HomeServer credentials or raw storage');
assert.match(css, /sf-homeserver-capabilities-v024/, 'capability UI must have dedicated responsive styling');

assert.match(runner, /tests\/homeserver-capabilities-v024\.php/, 'pure v0.24 resolver test must run in Recovery Baseline');
assert.match(runner, /tests\/homeserver-capabilities-v024-contract\.mjs/, 'v0.24/v0.25 contract must run in Recovery Baseline');

console.log('VP3 v0.24 capability routing + v0.25 Agent Brain delegation contract passed');