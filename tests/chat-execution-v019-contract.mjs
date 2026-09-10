import fs from 'node:fs';
import assert from 'node:assert/strict';

const root = new URL('../', import.meta.url);
const api = fs.readFileSync(new URL('api/chat-v236.php', root), 'utf8');
const runtime = fs.readFileSync(new URL('includes/agent-runtime-routing-v420.php', root), 'utf8');
const delegation = fs.readFileSync(new URL('includes/homeserver-agent-v025.php', root), 'utf8');
const execution = fs.readFileSync(new URL('includes/chat-execution-v019.php', root), 'utf8');
const chat = fs.readFileSync(new URL('chat.js', root), 'utf8');

assert.match(api, /chat-execution-v019\.php/);
// v0.25 remains the HomeServer delegation compatibility path. Section 10 owns
// the live route decision and supplies its cloud permission to that adapter.
assert.match(api, /vp3_agent_runtime_plan_v420\(\$pdo,\$user,\$activeAgentId,'chat'\)/);
assert.match(api, /homeserver_agent_v025_chat\(\$user,\$query,\$conversationId,\$history,\$principal,\$activeAgent,\$agentContext,!empty\(\$runtimePlan\['homeserver_cloud_allowed'\]\)\)/);
assert.match(delegation, /homeserver_agent_v018_chat\(\$user,\$query,\$conversationId,\$cloudAllowed\)/);

// v0.19 execution helpers remain the source/provider/token compatibility layer,
// while v4.20 normalizes actual route and persists the richer route provenance.
assert.match(api, /vp3_agent_runtime_homeserver_execution_v420\(\$homeResult\)/);
assert.match(runtime, /chat_execution_v019_homeserver\(\$result\)/);
assert.match(api, /chat_execution_v019_fallback\(\$user,!empty\(\$runtimePlan\['home'\]\['paired'\]\),true\)/);
assert.match(api, /chat_execution_v019_vp3_direct\(\$user\)/);
assert.match(api, /chat_execution_v019_tool\(\)/);
assert.match(api, /vp3_agent_runtime_finalize_v420/);
assert.match(api, /chat_execution_v019_source\(\$execution\)/);
assert.match(api, /'execution'=>\$execution/);
assert.match(api, /'sources'=>\$publicSources/);
assert.doesNotMatch(api, /homeserver_agent_v018_write_cloud_usage\(/);

assert.match(execution, /'homeserver_local'/);
assert.match(execution, /'user_provider'/);
assert.match(execution, /'vp3_cloud'/);
assert.match(execution, /'vp3_retrieval'/);
assert.match(execution, /'vp3_tool'/);
assert.match(execution, /'homeserver_not_paired'/);
assert.match(execution, /'homeserver_unavailable'/);
assert.match(execution, /'cloud_tokens_debited'/);
assert.match(execution, /'source'=>'compute-routing:v019'/);
assert.match(execution, /'Compute: '/);
assert.doesNotMatch(execution, /relay_token|homeserver_token|bearer_token|provider_response|raw_error/i);

assert.match(runtime, /'runtime_version'\]='v4\.20'/);
assert.match(runtime, /'actual_route'/);
assert.match(runtime, /'attempted_route'/);
assert.match(runtime, /'fallback_reason'/);

// Existing Chat source rendering is deliberately reused: the compute badge is
// visible on the immediate response, a conversation reload, and messages_after.
assert.match(chat, /function sourceHtml\(source\)/);
assert.match(chat, /sources\.map\(sourceHtml\)/);
assert.match(chat, /JSON\.parse\(message\.context_json\)/);
assert.match(chat, /context\.sources/);
assert.match(chat, /action:'messages_after'/);

console.log('VP3 v0.19 compute visibility compatibility contract passed through canonical v4.20 runtime routing');
