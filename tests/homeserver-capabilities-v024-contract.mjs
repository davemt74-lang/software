import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const helper = read('includes/homeserver-capabilities-v024.php');
const runtime = read('includes/agent-runtime-routing-v420.php');
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
assert.match(bootstrap, /agent-runtime-routing-v420\.php/, 'bootstrap must load the canonical runtime after compute compatibility layers');
assert.match(api, /homeserver_capability_v024_attach_state/, 'Agent settings state must expose capability registry');
assert.match(api, /refresh_homeserver_capabilities/, 'Agent settings must support explicit capability refresh');
assert.match(api, /homeserver_capability_v024_registry\(\(int\)\$user\['id'\], true\)/, 'refresh must force canonical HomeServer status discovery');

// Section 10 centralizes live capability-aware routing. Direct VP3 Cloud must
// never enter HomeServer scope/capability discovery, while Automatic and
// HomeServer-only may resolve the cached/refreshable Agent Brain capability.
assert.match(runtime, /if\(\$requested!==\'vp3_cloud\'\)[\s\S]*homeserver_capability_v024_registry\(\$userId,false\)/, 'direct VP3 Cloud must not refresh HomeServer capability status');
assert.match(runtime, /homeserver_capability_v024_resolve\(\$registry,'agent_brain','vp3_cloud'\)/, 'runtime must resolve Agent Brain capability');
assert.match(runtime, /\$home\['supported'\]=!empty\(\$brainCapability\['supported'\]\)/, 'runtime must distinguish HomeServer capability support');
assert.match(runtime, /\$home\['ready'\]=!empty\(\$brainCapability\['ready'\]\)/, 'runtime must use current HomeServer capability readiness');
assert.match(runtime, /ai_gateway_v031_plan/, 'v4.20 must delegate pure route choice to the canonical AI Gateway');
assert.match(runtime, /'try_homeserver'=>!empty\(\$gateway\['try_homeserver'\]\)/, 'runtime plan must expose whether HomeServer may be attempted');
assert.match(runtime, /'homeserver_cloud_allowed'=>\$homeCloudAllowed/, 'runtime must carry bounded HomeServer cloud permission');
assert.match(runtime, /does not advertise the Agent Brain capability/, 'HomeServer-only mode must fail closed for unsupported Agent Brain');
assert.match(runtime, /function vp3_agent_runtime_capability_route_v420/, 'capability provenance must be normalized by Section 10');
assert.match(runtime, /route_reason/, 'canonical route reason must remain visible in runtime provenance');
assert.match(runtime, /fallback_reason/, 'canonical fallback reason must remain visible in runtime provenance');

// Deterministic tools run before model routing, and Chat consumes one v4.20
// plan instead of independently resolving v0.24 capability state.
const toolIndex = chat.indexOf('vp3_agent_tool_execute_query_v400');
const runtimePlanIndex = chat.indexOf('vp3_agent_runtime_plan_v420');
const homeAttemptIndex = chat.indexOf('homeserver_agent_v025_chat');
assert.ok(toolIndex >= 0 && runtimePlanIndex > toolIndex, 'existing VP3 tools must remain first in the canonical chat path');
assert.ok(homeAttemptIndex > runtimePlanIndex, 'HomeServer execution must follow the canonical v4.20 plan');
assert.doesNotMatch(chat, /homeserver_capability_v024_registry\(\$userId,false\)/, 'Chat must not create a second capability-routing path');
assert.doesNotMatch(chat, /homeserver_capability_v024_resolve\(/, 'Chat must consume v4.20 capability decisions instead of resolving them again');
assert.match(chat, /homeserver_agent_v025_chat\(\$user,\$query,\$conversationId,\$history,\$principal,\$activeAgent,\$agentContext,!empty\(\$runtimePlan\['homeserver_cloud_allowed'\]\)\)/, 'HomeServer execution must use the v4.20 cloud boundary');
assert.match(chat, /vp3_agent_runtime_capability_route_v420\(\$runtimePlan,\$execution,\$homeAttempted\)/, 'capability provenance must persist with execution metadata');
assert.match(chat, /vp3_agent_runtime_tool_plan_v420/, 'VP3 tool execution must receive local capability/runtime provenance');
assert.match(chat, /vp3_agent_runtime_finalize_v420/, 'all model execution must receive canonical runtime provenance');
assert.match(chat, /vp3_agent_runtime_block_message_v420/, 'blocked routes must use the canonical runtime failure contract');

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

console.log('VP3 v0.24 capability routing + v0.25 delegation compatibility contract passed through canonical v4.20 runtime');
