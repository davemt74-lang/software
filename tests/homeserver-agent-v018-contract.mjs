import fs from 'node:fs';
import assert from 'node:assert/strict';

const root = new URL('../', import.meta.url);
const helper = fs.readFileSync(new URL('includes/homeserver-agent-v018.php', root), 'utf8');
const runtime = fs.readFileSync(new URL('includes/agent-runtime-routing-v420.php', root), 'utf8');
const delegation = fs.readFileSync(new URL('includes/homeserver-agent-v025.php', root), 'utf8');
const chat = fs.readFileSync(new URL('api/chat-v236.php', root), 'utf8');
const bootstrap = fs.readFileSync(new URL('includes/bootstrap.php', root), 'utf8');
const migration = fs.readFileSync(new URL('upgrade-vp3-homeserver-v018.sql', root), 'utf8');
const integration = fs.readFileSync(new URL('includes/homeserver-vp3.php', root), 'utf8');

assert.match(bootstrap, /homeserver-agent-v018\.php/);
assert.match(bootstrap, /agent-runtime-routing-v420\.php/);
assert.match(chat, /homeserver_agent_v025_chat\(\$user,\$query,\$conversationId,/);
assert.match(delegation, /homeserver_agent_v018_chat\(\$user,\$query,\$conversationId,\$cloudAllowed\)/, 'v0.25 must preserve the canonical v0.18 fallback');
assert.match(chat, /chat_generate_answer_policy_v236/);

// v0.18 keeps the legacy usage.write helper for explicit compatibility callers,
// but Section 10 direct VP3 Cloud must not contact HomeServer after execution.
assert.match(helper, /function homeserver_agent_v018_write_cloud_usage\(array \$user\): void/);
assert.match(helper, /'usage\.write'/);
assert.doesNotMatch(chat, /homeserver_agent_v018_write_cloud_usage\(/);
assert.match(runtime, /if\(\$requested!==\'vp3_cloud\'\)/);
assert.match(chat, /homeserver_agent_v018_forget\(\$userId,\$conversationId\)/);

assert.match(helper, /function homeserver_agent_v018_chat\(array \$user,string \$query,int \$conversationId,bool \$cloudAllowed=true\)/);
assert.match(helper, /'cloud_allowed'=>\$cloudAllowed/);
assert.match(helper, /'agent\.chat'/);
assert.match(helper, /homeserver_chat_sessions/);
assert.match(helper, /homeserver_conversation_id/);
assert.match(helper, /include_memory'=>true/);
assert.match(helper, /include_knowledge'=>true/);
assert.match(helper, /include_contacts'=>true/);
assert.match(helper, /WHERE user_id=\? AND trace_id=\?/);
assert.match(helper, /'event_id'=>'vp3-ledger:'/);
assert.match(helper, /ON DUPLICATE KEY UPDATE homeserver_conversation_id/);
assert.match(helper, /catch\(Throwable \$e\)/); // relay/schema failures preserve fallback and completed replies.
assert.match(migration, /PRIMARY KEY \(user_id, vp3_conversation_id\)/);
assert.match(integration, /'agent\.chat'/);
assert.match(integration, /'usage\.write'/);
// Reject PHP process-execution functions without misclassifying PDO->exec().
assert.doesNotMatch(helper, /(?:^|[^>A-Za-z0-9_])(?:shell_exec|exec|system|passthru)\s*\(/m);
assert.doesNotMatch(delegation, /(?:^|[^>A-Za-z0-9_])(?:shell_exec|exec|system|passthru)\s*\(/m);

console.log('VP3 v0.18 HomeServer Agent compatibility contract passed through canonical v4.20 routing');
