import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = path => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const boundary = read('includes/agent-chat-boundary-v380.php');
const textApi = read('api/chat-v236.php');
const streamApi = read('api/chat-stream-v121.php');
const policy = read('includes/chat-agent-policy-v236.php');
const legacyEngine = read('includes/chat-engine.php');
const surfaceContext = read('includes/agent-surface-context-v131.php');
const human = read('includes/human-messaging-v370.php');
const architecture = read('docs/VP3_PLATFORM_ARCHITECTURE_V320.md');

// One durable principal key owns every Agent Chat conversation: VP3 user +
// exact user-owned Agent id, with NULL permanently reserved for the system Agent.
assert.match(boundary, /VP3_AGENT_CHAT_BOUNDARY_V380/);
assert.match(boundary, /A NULL user_agent_id is the durable system-agent namespace/);
assert.match(boundary, /function vp3_agent_chat_scope_sql_v380/);
assert.match(boundary, /user_agent_id=\?/);
assert.match(boundary, /user_agent_id IS NULL/);
assert.match(boundary, /function vp3_agent_chat_conversation_v380/);
assert.match(boundary, /c\.id=\? AND c\.user_id=\? AND \{\$scope\}/);
assert.match(boundary, /function vp3_agent_chat_create_conversation_v380/);
assert.match(boundary, /INSERT INTO chat_conversations \(user_id,user_agent_id,artist_workspace_id,title\)/);

// Active text Chat consumes the canonical boundary and must never heuristically
// move system-Agent history into the first user-owned Agent.
assert.match(textApi, /agent-chat-boundary-v380\.php/);
assert.match(textApi, /vp3_agent_chat_resolve_agent_v380/);
assert.match(textApi, /vp3_agent_chat_scope_sql_v380/);
assert.match(textApi, /vp3_agent_chat_conversation_v380/);
assert.match(textApi, /vp3_agent_chat_create_conversation_v380/);
assert.match(textApi, /\$rawAgentContext\['user_agent_id'\]=\$activeAgentId/);
assert.doesNotMatch(textApi, /chat_v236_claim_legacy_history_v237/);
assert.doesNotMatch(textApi, /UPDATE\s+chat_conversations\s+SET\s+user_agent_id/i);

// Voice/stream Chat uses the same principal/scope/create contract. Voice is
// transport only and cannot switch the conversation's owning Agent namespace.
assert.match(streamApi, /agent-chat-boundary-v380\.php/);
assert.match(streamApi, /vp3_agent_chat_scope_sql_v380/);
assert.match(streamApi, /vp3_agent_chat_conversation_v380/);
assert.match(streamApi, /vp3_agent_chat_stream_scope_v380/);
assert.match(streamApi, /vp3_agent_chat_principal_v380/);
assert.match(streamApi, /vp3_agent_chat_create_conversation_v380/);
assert.match(streamApi, /\$rawAgentContext\['user_agent_id'\]=\$agentScopeId/);
assert.doesNotMatch(streamApi, /UPDATE\s+chat_conversations\s+SET\s+user_agent_id/i);

// Existing conversation identity wins over stale browser/cross-surface context.
// Explicit system-Agent context is meaningful: zero must not be treated as
// “Agent omitted” when the stored conversation belongs to a user-owned Agent.
assert.match(boundary, /SELECT user_agent_id FROM chat_conversations WHERE id=\? AND user_id=\?/);
assert.match(boundary, /array_key_exists\('user_agent_id',\$rawContext\)/);
assert.match(boundary, /if\(\$hasRequested&&\$requested!==\$storedId\)throw new RuntimeException/);
assert.match(boundary, /Conversation not found for this Agent/);
assert.match(boundary, /user_agent_get_v236\(\$pdo,\$userId,\$requestedAgentId\)/);
assert.match(boundary, /empty\(\$agent\['is_active'\]\)/);

// Human Conversations remain a separate persistence domain. They may only be
// reached by an explicit future tool after v3.70 authorization, never as ambient
// Agent context or by querying the human message ledger in Chat policy code.
assert.match(boundary, /function vp3_agent_chat_human_conversation_allowed_v380/);
assert.match(boundary, /vp3_human_conversation_v370/);
assert.match(boundary, /vp3_human_can_access_v370/);
for (const source of [policy, legacyEngine, surfaceContext]) {
  assert.doesNotMatch(source, /\bhuman_messages\b/);
  assert.doesNotMatch(source, /\bhuman_conversations\b/);
}
assert.match(human, /human_messages/);
assert.match(human, /human_conversations/);
assert.doesNotMatch(textApi, /profile_agent_messages|human_messages/);
assert.doesNotMatch(streamApi, /profile_agent_messages|human_messages/);

// The architecture continues to define Agent Chat, Human Conversations and
// Profile Agent conversations as separate persistence/authorization domains.
assert.match(architecture, /Agent Chat remains the private user↔agent interface/);
assert.match(architecture, /Human messages must not be stored as Agent Chat messages/);
assert.match(architecture, /only through explicit, permission-aware tools/);
assert.match(architecture, /Profile Agent visitor conversations remain/);

console.log('AGENT_CHAT_BOUNDARIES_V380=PASS');
