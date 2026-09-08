import fs from 'node:fs';
import assert from 'node:assert/strict';

const root=new URL('../',import.meta.url);
const helper=fs.readFileSync(new URL('includes/agent-compute-v023.php',root),'utf8');
const bootstrap=fs.readFileSync(new URL('includes/bootstrap.php',root),'utf8');
const api=fs.readFileSync(new URL('api/user-agent-system-v236.php',root),'utf8');
const chat=fs.readFileSync(new URL('api/chat-v236.php',root),'utf8');
const ui=fs.readFileSync(new URL('account-agent-settings-v236.js',root),'utf8');
const loader=fs.readFileSync(new URL('account-agent-settings-loader-v236.js',root),'utf8');
const shell=fs.readFileSync(new URL('includes/workspace-sidebar-v82.php',root),'utf8');
const css=fs.readFileSync(new URL('agent-compute-v023.css',root),'utf8');
const migration=fs.readFileSync(new URL('upgrade-vp3-agent-compute-v023.sql',root),'utf8');

assert.match(bootstrap,/agent-compute-v023\.php/);
assert.match(helper,/function agent_compute_v023_preferences/);
assert.match(helper,/'inherit'/);
assert.match(helper,/function agent_compute_v023_policy_from_values/);
assert.match(helper,/function agent_compute_v023_effective/);
assert.match(helper,/function agent_compute_v023_save_override/);
assert.match(helper,/function agent_compute_v023_assert_owned_agent/);
assert.match(helper,/user_agent_get_v236\(\$pdo,\$userId,\$agentId\)/);
assert.match(helper,/DELETE FROM agent_compute_overrides WHERE user_id=\? AND agent_id=\?/);
assert.match(helper,/if\(\$agentId===0\)return;/);
assert.match(helper,/agent_policy_version'\]\s*=\s*'v0\.23'/);
assert.doesNotMatch(helper,/relay_token_enc|homeserver_token_enc|pending_claim_token_enc|bearer_token|raw_error/i);

assert.match(migration,/CREATE TABLE IF NOT EXISTS agent_compute_overrides/);
assert.match(migration,/PRIMARY KEY \(user_id,agent_id\)/);
assert.match(migration,/FOREIGN KEY \(user_id\) REFERENCES users\(id\)/);

assert.match(api,/agent_compute_v023_ensure_schema\(\$pdo\)/);
assert.match(api,/agent_compute_v023_attach_state\(\$pdo, \$user, \$state\)/);
assert.match(api,/save_agent_compute_preference/);
assert.match(api,/agent_compute_v023_save_override/);
assert.match(api,/agent_compute_v023_delete_override/);

assert.match(chat,/agent_compute_v023_effective\(\$pdo,\$userId,\$activeAgentId\)/);
assert.match(chat,/effective_preference/);
assert.match(chat,/agent_compute_v023_public_policy\(\$computePolicy,\$activeAgentId\)/);
assert.match(chat,/chat_execution_v019_vp3_direct\(\$user\)/);
assert.match(chat,/chat_execution_v019_fallback\(\$user,\$homePaired,\$homeAttempted\)/);
assert.match(chat,/homeserver_agent_v018_chat\(\$user,\$query,\$conversationId,!empty\(\$computePlan\['homeserver_cloud_allowed'\]\)\)/);
const toolIndex=chat.indexOf("release_v105_chat_tool");
const homeOnlyGuardIndex=chat.indexOf("HomeServer-only compute is selected for this Agent");
assert.ok(toolIndex>=0&&homeOnlyGuardIndex>toolIndex,'Tools must run before HomeServer-only compute can block model execution.');

assert.match(ui,/Account compute default/);
assert.match(ui,/function agentComputeControl/);
assert.match(ui,/data-agent-compute/);
assert.match(ui,/save_agent_compute_preference/);
assert.match(ui,/Use account setting|agent_preferences/);
assert.match(ui,/Effective route policy/);
assert.match(css,/\.sf-agent-compute-inline/);
assert.match(loader,/agent-compute-v023\.css/);
assert.match(loader,/agent-compute-v023-20260908/);
assert.match(shell,/agent-compute-v023-20260908/);

console.log('VP3 v0.23 per-Agent compute policy contract passed');
