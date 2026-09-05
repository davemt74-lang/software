import assert from 'node:assert/strict';
import fs from 'node:fs';

const index=fs.readFileSync('includes/shared-knowledge-index-v236.php','utf8');
const chat=fs.readFileSync('includes/chat-agent-policy-v236.php','utf8');
const usage=fs.readFileSync('includes/user-data-usage-v236.php','utf8');
const account=fs.readFileSync('account-agent-settings-v236.js','utf8');
const identity=fs.readFileSync('chat-agent-identity-v236.js','utf8');
const chatPage=fs.readFileSync('chat.php','utf8');
const settingsApi=fs.readFileSync('api/user-agent-system-v236.php','utf8');

assert.match(index,/function shared_knowledge_index_revoke_v236/,'shared index has one revocation owner');
assert.match(index,/topic_tags=''[^;]*embedding_ref=''[^;]*source_version_hash=REPEAT\('0',64\)/,'revocation erases content-derived discovery metadata');
assert.match(index,/share_scope='private'/,'revoked pointers no longer retain the prior audience scope');
assert.match(index,/INNER JOIN knowledge_items k ON k\.id=i\.knowledge_id AND k\.knowledge_scope='personal' AND k\.is_published=1/,'candidate discovery independently requires a still-published personal source');
assert.match(index,/function shared_knowledge_index_sync_due_v236/,'shared index self-heals new or changed shared knowledge');
assert.match(index,/COALESCE\(pe\.stonefellow_shared,pw\.stonefellow_shared,0\)=1/,'exact knowledge policy overrides wildcard policy during automatic indexing');
assert.match(index,/k\.updated_at>i\.last_indexed_at/,'changed source knowledge is re-indexed before discovery');
assert.match(index,/i\.knowledge_id IS NULL/,'newly shared published knowledge is indexed automatically');
assert.match(index,/shared_knowledge_index_sync_due_v236\(\$pdo,60\)/,'candidate retrieval refreshes only due pointers before searching');

const sharedPersonalPolicy=chat.match(/function chat_policy_shared_personal_knowledge_v242\([\s\S]*?\n}\n\nfunction chat_policy_system_knowledge_v242/)?.[0]||'';
assert.ok(sharedPersonalPolicy,'cross-user personal Knowledge retrieval policy is inspectable');
assert.match(sharedPersonalPolicy,/shared_knowledge_index_candidates_v236/,'cross-user retrieval starts from the pointer-only shared index');
assert.doesNotMatch(sharedPersonalPolicy,/FROM knowledge_items|JOIN knowledge_items/,'cross-user retrieval never falls back to a raw private-Knowledge scan');
assert.match(chat,/shared_knowledge_index_hash_v236/,'authoritative source hash is rechecked before retrieval');
assert.match(chat,/chat_policy_can_use_v236/,'live permissions remain authoritative at retrieval time');
assert.match(chat,/user_data_usage_log_v236/,'authorized retrievals are attributed in the usage ledger');

const usageSchema=usage.match(/CREATE TABLE IF NOT EXISTS user_data_retrieval_log \([\s\S]*?\) ENGINE=/)?.[0]||'';
assert.ok(usageSchema,'usage schema is present');
assert.doesNotMatch(usageSchema,/prompt|query_text|message_text|conversation_text|request_body/,'usage ledger never stores prompt or conversation bodies');
assert.match(usage,/Owner-facing telemetry intentionally omits requester identity and conversation identifiers/,'owner view is deliberately privacy-minimized');
assert.match(usage,/requester_name/,'admin audit can still attribute the requesting account');

assert.match(account,/\?agent=system/,'Account Settings exposes an explicit universal system-agent chat');
assert.match(account,/Delete \$\{a\.display_name\} and its agent chat history\? This cannot be undone\./,'agent deletion UI accurately warns that scoped history is deleted');
assert.match(account,/Agent and its chat history deleted\./,'deletion success copy matches backend behavior');
assert.match(settingsApi,/DELETE FROM chat_conversations WHERE user_id=\? AND user_agent_id=\?/,'agent deletion removes scoped conversations before deleting the agent');
assert.match(settingsApi,/beginTransaction\(\)/,'agent/history deletion is transactional');

assert.match(identity,/requestedAgent\s*!==\s*['"]system['"]/,'explicit system-agent URL bypasses browser fallback selection');
assert.match(identity,/const active\s*=\s*agents\.filter\(agent\s*=>\s*Number\(agent\.is_active\)\)/,'browser fallback ignores inactive agents');
assert.match(identity,/active\.find\(agent\s*=>\s*Number\(agent\.is_default\)\)\s*\|\|\s*active\[0\]\s*\|\|\s*null/,'normal Chat browser fallback uses the default active agent or first active renamed agent');
assert.match(chatPage,/\$activeUserAgent = \$preferred \?: \$ownedAgents\[0\]/,'server-rendered normal Chat uses the default active agent or first active renamed agent before first paint');
assert.match(chatPage,/\$explicitSystemAgent = strcasecmp\(\$requestedAgentRaw, 'system'\) === 0/,'explicit system-agent selection remains server authoritative');
assert.match(identity,/window\.location\.assign\(data\.chat_url\s*\|\|\s*agentUrl\(data\.agent_id\)\)/,'completed first-time onboarding opens the saved named-agent chat immediately');
assert.match(identity,/data-keep-system/,'onboarding keeps the system name only as an explicit agent-name choice, not as a hidden system-chat bypass');
assert.match(identity,/input\.value\s*=\s*cfg\.systemName\s*\|\|\s*['"]STONEFELLOW['"]/,'system-name shortcut writes the configured system name into the onboarding agent field');

console.log('USER_AGENT_SHARED_KNOWLEDGE_HARDENING_CONTRACT=PASS');