import fs from 'node:fs';
import assert from 'node:assert/strict';

const root = new URL('../', import.meta.url);
const compute = fs.readFileSync(new URL('includes/agent-compute-v020.php', root), 'utf8');
const bootstrap = fs.readFileSync(new URL('includes/bootstrap.php', root), 'utf8');
const api = fs.readFileSync(new URL('api/user-agent-system-v236.php', root), 'utf8');
const chat = fs.readFileSync(new URL('api/chat-v236.php', root), 'utf8');
const execution = fs.readFileSync(new URL('includes/chat-execution-v019.php', root), 'utf8');
const account = fs.readFileSync(new URL('account-agent-settings-v236.js', root), 'utf8');
const loader = fs.readFileSync(new URL('account-agent-settings-loader-v236.js', root), 'utf8');
const shell = fs.readFileSync(new URL('includes/workspace-sidebar-v82.php', root), 'utf8');
const css = fs.readFileSync(new URL('agent-compute-v020.css', root), 'utf8');
const migration = fs.readFileSync(new URL('upgrade-vp3-agent-compute-v020.sql', root), 'utf8');

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
assert.match(api, /\$state\['compute'\]=agent_compute_v020_state/);
assert.match(api, /save_compute_preference/);
assert.match(api, /agent_compute_v020_save_preference/);

assert.match(chat, /agent_compute_v020_preference\(\$pdo,\$userId\)/);
assert.match(chat, /agent_compute_v020_route_plan/);
assert.match(chat, /\$computePreference==='homeserver_only'/);
assert.match(chat, /homeserver_agent_v018_chat\(\$user,\$query,\$conversationId,!empty\(\$computePlan\['homeserver_cloud_allowed'\]\)\)/);
assert.match(chat, /\$computePreference==='vp3_cloud'\?chat_execution_v019_vp3_direct/);
assert.match(chat, /HomeServer-only compute is selected/);
assert.match(execution, /function chat_execution_v019_vp3_direct/);
assert.match(execution, /'vp3_cloud','VP3 Cloud'/);
assert.match(execution, /'not_used',false,'none'/);

assert.match(account, /Where your Agent runs/);
assert.match(account, /data-compute-preference/);
assert.match(account, /save_compute_preference/);
assert.match(account, /Recent Agent usage/);
assert.match(account, /row\?\.source!=='vp3_cloud'/);
assert.match(account, /VP3 tokens remaining/);
assert.match(css, /\.sf-compute-options/);
assert.match(css, /\.sf-compute-usage-row/);
assert.match(loader, /agent-compute-v020\.css/);
assert.match(loader, /agent-compute-v020-20260908/);
assert.match(shell, /agent-compute-v020-20260908/);

assert.match(migration, /CREATE TABLE IF NOT EXISTS agent_compute_preferences/);
assert.match(migration, /PRIMARY KEY/);
assert.match(migration, /FOREIGN KEY \(user_id\) REFERENCES users\(id\)/);
assert.match(migration, /ON DELETE CASCADE/);

console.log('VP3 v0.20 Agent compute integration contract passed');
