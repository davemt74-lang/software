import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const runtime = read('includes/agent-runtime-routing-v420.php');
const textChat = read('api/chat-v236.php');
const streamChat = read('api/chat-stream-v121.php');
const accounting = read('includes/ai-usage-accounting-v032.php');
const status = read('api/agent-runtime-status-v034.php');
const proactive = read('includes/agent-proactive-operations-v036.php');
const bootstrap = read('includes/bootstrap.php');
const setup = read('setup.php');
const upgrade = read('upgrade.php');

assert.match(runtime, /VP3_AGENT_RUNTIME_ROUTING_V420/);
assert.match(runtime, /function vp3_agent_runtime_plan_v420/);
assert.match(runtime, /function vp3_agent_runtime_tool_plan_v420/);
assert.match(runtime, /subscription_has_entitlement\(\$user,'main_ai\.access'\)/);
assert.match(runtime, /subscription_ai_balance\(\$user\)/);
assert.match(runtime, /ai_gateway_v031_plan/);
assert.match(runtime, /if\(\$requested!==\'vp3_cloud\'\)[\s\S]*homeserver_scope_v026_fetch/);
assert.match(runtime, /if\(\$requested!==\'vp3_cloud\'\)[\s\S]*homeserver_capability_v024_registry/);
assert.match(runtime, /\$homeCloudAllowed=\$effective==='auto'[\s\S]*!empty\(\$home\['cloud_allowed'\]\)[\s\S]*!empty\(\$cloud\['ready'\]\)/);
assert.match(runtime, /'homeserver_cloud_allowed'=>\$homeCloudAllowed/);
assert.match(runtime, /requested_preference/);
assert.match(runtime, /effective_preference/);
assert.match(runtime, /try_homeserver/);
assert.match(runtime, /allow_vp3_fallback/);
assert.match(runtime, /homeserver_cloud_allowed/);
assert.match(runtime, /homeserver_only_retry/);
assert.match(runtime, /VP3 Cloud via HomeServer/);
assert.match(runtime, /homeserver_vp3_cloud/);
assert.match(runtime, /homeserver_user_provider/);
assert.match(runtime, /runtime_version.*v4\.20/s);
assert.match(runtime, /attempted_route/);
assert.match(runtime, /actual_route/);
assert.match(runtime, /fallback_reason/);

// Text Chat must plan compute through v4.20, while tools stay local and do not
// trigger a HomeServer capability probe simply to answer a deterministic tool.
assert.match(textChat, /vp3_agent_runtime_tool_plan_v420/);
assert.match(textChat, /vp3_agent_runtime_plan_v420/);
assert.match(textChat, /vp3_agent_runtime_homeserver_execution_v420/);
assert.match(textChat, /vp3_agent_runtime_finalize_v420/);
assert.match(textChat, /vp3_agent_runtime_capability_route_v420/);
assert.doesNotMatch(textChat, /agent_compute_v020_route_plan\(/);
assert.doesNotMatch(textChat, /agent_compute_v023_effective\(/);
assert.doesNotMatch(textChat, /homeserver_agent_v018_write_cloud_usage\(/);
assert.match(textChat, /homeserver_agent_v025_chat[\s\S]*!empty\(\$runtimePlan\['homeserver_cloud_allowed'\]\)/);
assert.match(textChat, /vp3_agent_tool_execute_query_v400/);
assert.match(textChat, /ai_usage_accounting_v032_record/);

// Streaming/voice is transport only: it uses exactly the same Agent compute
// policy and tool authorization boundaries as the text endpoint.
assert.match(streamChat, /chat-execution-v019\.php/);
assert.match(streamChat, /homeserver-agent-v025\.php/);
assert.match(streamChat, /agent-tool-authorization-v400\.php/);
assert.match(streamChat, /vp3_agent_runtime_tool_plan_v420/);
assert.match(streamChat, /vp3_agent_runtime_plan_v420/);
assert.match(streamChat, /homeserver_agent_v025_chat[\s\S]*!empty\(\$runtimePlan\['homeserver_cloud_allowed'\]\)/);
assert.match(streamChat, /vp3_agent_runtime_homeserver_execution_v420/);
assert.match(streamChat, /vp3_agent_runtime_finalize_v420/);
assert.match(streamChat, /vp3_agent_tool_execute_query_v400/);
assert.doesNotMatch(streamChat, /\bagent_tool_execute_query\(/);
assert.doesNotMatch(streamChat, /homeserver_agent_v018_write_cloud_usage\(/);
assert.match(streamChat, /ai_usage_accounting_v032_record/);
assert.match(streamChat, /'execution'=>\$execution/);
assert.match(streamChat, /effective_preference.*homeserver_only/s);
assert.match(streamChat, /vp3_agent_runtime_block_message_v420/);

// One execution ledger records both source billing and v4.20 route truth. The
// legacy insert remains only as an upgrade-safe fallback until columns exist.
for (const column of ['runtime_version','requested_route','attempted_route','actual_route','route_reason','fallback_reason']) {
  assert.match(accounting, new RegExp(`ADD COLUMN ${column}|${column} VARCHAR`));
}
assert.match(accounting, /column_exists\('ai_execution_ledger','runtime_version'\)/);
assert.match(accounting, /column_exists\('ai_execution_ledger','actual_route'\)/);
assert.match(accounting, /INSERT INTO ai_execution_ledger[\s\S]*runtime_version[\s\S]*actual_route/);

assert.match(status, /runtime_version/);
assert.match(status, /requested_route/);
assert.match(status, /attempted_route/);
assert.match(status, /actual_route/);
assert.match(status, /homeserver_vp3_cloud/);

// Proactive routing-health telemetry must use the real ledger timestamp and,
// once v4.20 is installed, the canonical actual route instead of a missing
// historical column or generic source alone.
assert.match(proactive, /created_at>=\?/);
assert.doesNotMatch(proactive, /occurred_at>=\?/);
assert.match(proactive, /column_exists\('ai_execution_ledger', 'actual_route'\)/);
assert.match(proactive, /actual_route='vp3_cloud'/);

assert.match(bootstrap, /agent-compute-v023\.php[\s\S]*agent-runtime-routing-v420\.php/);
assert.match(setup, /ai_usage_accounting_v032_ensure_schema\(\$pdo\)/);
assert.match(upgrade, /column_exists\('ai_execution_ledger','runtime_version'\)/);
assert.match(upgrade, /column_exists\('ai_execution_ledger','actual_route'\)/);
assert.match(upgrade, /ai_usage_accounting_v032_ensure_schema\(\)/);

console.log('AGENT_RUNTIME_ROUTING_V420=PASS');
