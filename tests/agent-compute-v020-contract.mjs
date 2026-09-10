import fs from 'node:fs';
import assert from 'node:assert/strict';

const root = new URL('../', import.meta.url);
const compute = fs.readFileSync(new URL('includes/agent-compute-v020.php', root), 'utf8');
const runtime = fs.readFileSync(new URL('includes/agent-runtime-routing-v420.php', root), 'utf8');
const bootstrap = fs.readFileSync(new URL('includes/bootstrap.php', root), 'utf8');
const api = fs.readFileSync(new URL('api/user-agent-system-v236.php', root), 'utf8');
const chat = fs.readFileSync(new URL('api/chat-v236.php', root), 'utf8');
const delegation = fs.readFileSync(new URL('includes/homeserver-agent-v025.php', root), 'utf8');
const execution = fs.readFileSync(new URL('includes/chat-execution-v019.php', root), 'utf8');
const account = fs.readFileSync(new URL('account-agent-settings-v236.js', root), 'utf8');
const loader = fs.readFileSync(new URL('account-agent-settings-loader-v236.js', root), 'utf8');
const shell = fs.readFileSync(new URL('includes/workspace-sidebar-v82.php', root), 'utf8');
const css = fs.readFileSync(new URL('agent-compute-v020.css', root), 'utf8');
const migration = fs.readFileSync(new URL('upgrade-vp3-agent-compute-v020.sql', root), 'utf8');

// v0.20 remains the compatibility/storage contract for the three account-level
// compute preferences and its pure legacy route adapter.
assert.match(compute, /'auto'/);
assert.match(compute, /'homeserver_only'/);
assert.match(compute, /'vp3_cloud'/);
assert.match(compute, /function agent_compute_v020_route_plan/);
assert.match(compute, /'try_homeserver'/);
assert.match(compute, /'homeserver_cloud_allowed'/);
assert.match(compute, /'allow_vp3_fallback'/);
assert.match(compute, /agent_compute_preferences/);
assert.match(compute, /usage\.read/);
assert.match(compute, /subscription_ai_balance/);
assert.match(compute, /subscription_recent_usage/);
assert.doesNotMatch(compute, /relay_token_enc.*return|homeserver_token_enc.*return|pending_claim_token_enc.*return/i);

assert.match(bootstrap, /agent-compute-v020\.php/);
assert.match(bootstrap, /agent-compute-v023\.php[\s\S]*agent-runtime-routing-v420\.php/);
// v0.21 wraps v0.20 state; v0.23 then attaches per-Agent policy metadata.
assert.match(api, /\$state\['compute'\]\s*=\s*agent_compute_v021_state/);
assert.match(api, /save_compute_preference/);
assert.match(api, /agent_compute_v020_save_preference/);

// Section 10 owns live request routing. Chat must not independently recompute
// v0.20/v0.23 policy or create a second route decision path.
assert.match(runtime, /function vp3_agent_runtime_plan_v420/);
assert.match(runtime, /agent_compute_v020_preference\(\$pdo,\$userId\)/);
assert.match(runtime, /agent_compute_v023_override\(\$pdo,\$userId,max\(0,\$agentId\)\)/);
assert.match(runtime, /ai_gateway_v031_plan/);
assert.match(chat, /vp3_agent_runtime_plan_v420\(\$pdo,\$user,\$activeAgentId,'chat'\)/);
assert.match(chat, /vp3_agent_runtime_tool_plan_v420\(\$userId,\$activeAgentId,'chat'\)/);
assert.doesNotMatch(chat, /agent_compute_v023_effective\(/);
assert.doesNotMatch(chat, /agent_compute_v020_route_plan\(/);
assert.match(chat, /\(string\)\$runtimePlan\['effective_preference'\]==='homeserver_only'/);
assert.match(chat, /homeserver_agent_v025_chat\(\$user,\$query,\$conversationId,\$history,\$principal,\$activeAgent,\$agentContext,!empty\(\$runtimePlan\['homeserver_cloud_allowed'\]\)\)/);
assert.match(delegation, /homeserver_agent_v018_chat\(\$user,\$query,\$conversationId,\$cloudAllowed\)/, 'v0.20 HomeServer routing must retain legacy fallback');

// Direct VP3 Cloud is now isolated by the v4.20 planner and finalizer while
// preserving the v0.19 execution helper used for source/billing visibility.
assert.match(runtime, /if\(\$requested!==\'vp3_cloud\'\)/);
assert.match(chat, /chat_execution_v019_vp3_direct\(\$user\)/);
assert.match(chat, /vp3_agent_runtime_finalize_v420/);
assert.match(chat, /vp3_agent_runtime_block_message_v420/);
assert.doesNotMatch(chat, /homeserver_agent_v018_write_cloud_usage\(/);
assert.match(execution, /function chat_execution_v019_vp3_direct/);
assert.match(execution, /'vp3_cloud','VP3 Cloud'/);
assert.match(execution, /'not_used',false,'none'/);

assert.match(account, /Account compute default/);
assert.match(account, /data-compute-preference/);
assert.match(account, /save_compute_preference/);
assert.match(account, /Recent Agent usage/);
assert.match(account, /row\?\.source!=='vp3_cloud'/);
assert.match(account, /VP3 tokens remaining/);
assert.match(css, /\.sf-compute-options/);
assert.match(css, /\.sf-compute-usage-row/);
assert.match(loader, /agent-compute-v020\.css/);
assert.match(loader, /agent-compute-v023-20260908/);
assert.match(shell, /agent-compute-v023-20260908/);

assert.match(migration, /CREATE TABLE IF NOT EXISTS agent_compute_preferences/);
assert.match(migration, /PRIMARY KEY/);
assert.match(migration, /FOREIGN KEY \(user_id\) REFERENCES users\(id\)/);
assert.match(migration, /ON DELETE CASCADE/);

console.log('VP3 v0.20 Agent compute compatibility contract passed through canonical v4.20 runtime routing');
