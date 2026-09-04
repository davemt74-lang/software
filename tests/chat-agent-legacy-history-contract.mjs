import assert from 'node:assert/strict';
import fs from 'node:fs';

const chatApi = fs.readFileSync('api/chat-v236.php', 'utf8');
const agentSystem = fs.readFileSync('includes/user-agent-system-v236.php', 'utf8');

assert.match(
  chatApi,
  /function\s+chat_v236_claim_legacy_history_v237\s*\(/,
  'Chat API must retain an explicit legacy-history continuity repair.'
);

const claimStart = chatApi.indexOf('function chat_v236_claim_legacy_history_v237');
const claimEnd = chatApi.indexOf('function chat_v236_scope_sql', claimStart);
assert.ok(claimStart >= 0 && claimEnd > claimStart, 'Legacy-history repair function must be inspectable.');
const claim = chatApi.slice(claimStart, claimEnd);

assert.match(
  claim,
  /FROM user_agents WHERE owner_user_id=\? ORDER BY created_at ASC,id ASC LIMIT 1/,
  'Only the owner\'s first user-owned agent may inherit pre-agent history.'
);
assert.match(
  claim,
  /\(int\)\$row\['id'\]\s*!==\s*\(int\)\$agent\['id'\]/,
  'Later agents must never claim the first agent\'s legacy history.'
);
assert.match(
  claim,
  /user_agent_id IS NULL AND created_at<=\?/,
  'Only legacy NULL-agent conversations created before the first agent may be claimed.'
);
assert.doesNotMatch(
  claim,
  /UPDATE chat_conversations SET user_agent_id=\? WHERE user_id=\?(?![\s\S]*user_agent_id IS NULL)/,
  'The repair must not broadly reassign already-scoped conversations.'
);
assert.match(
  chatApi,
  /try\{\s*chat_v236_claim_legacy_history_v237\(\$pdo,\$userId,\$activeAgent\);/,
  'Legacy repair must run before list/load/send scope checks.'
);

const updateStart = agentSystem.indexOf('function user_agent_update_v236');
const updateEnd = agentSystem.indexOf('function user_agent_delete_v236', updateStart);
assert.ok(updateStart >= 0 && updateEnd > updateStart, 'Agent update function must exist.');
const updateAgent = agentSystem.slice(updateStart, updateEnd);
assert.match(
  updateAgent,
  /UPDATE user_agents SET display_name=/,
  'Renaming an existing agent must update the same row.'
);
assert.doesNotMatch(
  updateAgent,
  /INSERT INTO user_agents/,
  'Renaming an existing agent must never create a replacement agent ID.'
);

assert.match(
  chatApi,
  /WHERE c\.id=\? AND c\.user_id=\? AND \{\$scope\}/,
  'Conversation access must remain owner- and agent-scoped after continuity repair.'
);

console.log('chat agent legacy history contract: ok');
