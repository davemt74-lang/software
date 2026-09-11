import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const scope = read('includes/agent-memory-scope-v410.php');
const brain = read('includes/agent-brain-v82.php');
const state = read('includes/agent-brain-v122.php');
const lifecycle = read('includes/agent-memory-lifecycle-v123.php');
const context = read('includes/agent-brain-context-v142.php');
const bootstrap = read('includes/bootstrap.php');
const setup = read('setup.php');
const upgrade = read('upgrade.php');
const memoryPage = read('memory.php');

assert.match(scope, /VP3_AGENT_MEMORY_SCOPE_V410/);
assert.match(scope, /user_agent_id INT UNSIGNED NULL/);
assert.match(scope, /memory_scope_version SMALLINT UNSIGNED NOT NULL DEFAULT 0/);
assert.match(scope, /agent_chat_archive ADD COLUMN user_agent_id INT UNSIGNED NULL/);
assert.match(scope, /column_exists\('chat_conversations','user_agent_id'\)/);
assert.match(scope, /idx_agent_memory_agent_type_v410/);
assert.match(scope, /idx_agent_memory_agent_occurrence_v410/);
assert.match(scope, /idx_agent_archive_agent_created_v410/);
assert.match(scope, /SELECT 1 FROM agent_memory_items WHERE memory_scope_version<410 LIMIT 1/);
assert.match(scope, /UPDATE agent_chat_archive a[\s\S]*JOIN chat_conversations c[\s\S]*SET a\.user_agent_id=c\.user_agent_id/);
assert.doesNotMatch(scope, /LEFT JOIN chat_conversations c[\s\S]*SET a\.user_agent_id/);
assert.match(scope, /UPDATE agent_memory_items m[\s\S]*LEFT JOIN agent_chat_archive a ON a\.id=m\.source_archive_id AND a\.user_id=m\.user_id[\s\S]*SET m\.user_agent_id=COALESCE\(m\.user_agent_id,a\.user_agent_id\)[\s\S]*WHERE m\.memory_scope_version<410/);
assert.match(scope, /WHERE memory_scope_version<410/);
assert.match(scope, /SHA1\(CONCAT\('v410\|agent:',COALESCE\(user_agent_id,0\),'\|',memory_hash\)\)/);
assert.doesNotMatch(scope, /SET user_agent_id=NULL/);

const provenanceMigration = scope.indexOf('LEFT JOIN agent_chat_archive a ON a.id=m.source_archive_id');
const hashMigration = scope.indexOf("SHA1(CONCAT('v410|agent:'");
assert.ok(provenanceMigration >= 0 && hashMigration > provenanceMigration, 'recover Agent provenance before re-hashing legacy memory');

assert.match(scope, /user_agent_id=\?/);
assert.match(scope, /user_agent_id IS NULL/);
assert.match(scope, /sha1\('v410\|agent:'\.vp3_agent_memory_scope_id_v410/);
assert.match(scope, /SELECT user_agent_id FROM chat_conversations WHERE id=\? AND user_id=\?/);
assert.match(scope, /Conversation does not belong to this user/);
assert.match(scope, /vp3_agent_memory_scope_current_context_v410/);
assert.match(scope, /agent_scope/);
assert.match(scope, /user_agent_id.*agent_kind/s);

assert.match(bootstrap, /agent-memory-scope-v410\.php[\s\S]*agent-brain-v82\.php/);
assert.match(setup, /vp3_agent_memory_scope_ensure_schema_v410\(\$pdo\)/);
assert.match(upgrade, /vp3_agent_memory_scope_schema_ready_v410\(\)/);
assert.match(upgrade, /vp3_agent_memory_scope_ensure_schema_v410\((?:\$pdo)?\)/);

assert.match(brain, /vp3_agent_memory_scope_from_conversation_v410\(\$user,\s*\$conversationId,\s*true\)/);
assert.match(brain, /INSERT INTO agent_chat_archive[\s\S]*?\(user_id,user_agent_id,conversation_id,/);
assert.match(brain, /user_agent_id=VALUES\(user_agent_id\)/);
assert.match(brain, /INSERT INTO agent_memory_items[\s\S]*?\(user_id,user_agent_id,/);
assert.match(brain, /memory_scope_version/);
assert.match(brain, /vp3_agent_memory_scope_hash_v410/);
assert.match(brain, /vp3_agent_memory_scope_provenance_v410/);
assert.match(brain, /SELECT COUNT\(\*\) FROM agent_chat_archive a WHERE a\.user_id=\? AND \{\$archiveScope\}/);
assert.doesNotMatch(brain, /FROM agent_chat_archive a\s+JOIN chat_conversations c/);
assert.match(brain, /WHERE m\.user_id=\? AND \{\$scope\}/);

assert.match(state, /vp3_agent_memory_scope_current_v410/);
assert.match(state, /INSERT INTO agent_memory_items[\s\S]*?\(user_id,user_agent_id,/);
assert.match(state, /vp3_agent_memory_scope_from_conversation_v410\(\$user,\$conversationId,true\)/);
assert.match(state, /WHERE user_id=\? AND '\.\$scope\.' AND memory_hash=\?/);
assert.match(state, /JOIN chat_conversations c ON c\.id=m\.conversation_id WHERE c\.user_id=\? AND '\.\$scope/);

// v123 is a public helper used by older callers. Keep the four-argument
// signature while applying the current v4.10 scope inside the UPDATE itself.
assert.match(lifecycle, /function agent_memory_v123_write_row\(int \$id,array \$meta,\?float \$confidence=null,\?int \$active=null\)/);
assert.match(lifecycle, /vp3_agent_memory_scope_current_context_v410/);
assert.match(lifecycle, /if\(\$uid<1\)return/);
assert.match(lifecycle, /UPDATE agent_memory_items SET .* WHERE id=\? AND user_id=\? AND '\.\$scope/s);
assert.match(lifecycle, /vp3_agent_memory_scope_set_current_v410\(\$uid,\$agentId\)/);
assert.match(lifecycle, /agent_chat_archive a WHERE a\.user_id=\? AND \{\$scope\}/);
assert.doesNotMatch(lifecycle, /JOIN chat_conversations c ON c\.id=a\.conversation_id/);
assert.match(lifecycle, /SELECT \* FROM agent_memory_items WHERE user_id=\? AND '\.\$scopeSql/);
assert.match(lifecycle, /user_agent_id.*\$agentId>0\?\$agentId:null/s);
assert.doesNotMatch(lifecycle, /schema_ready_ready_v410/);

assert.match(context, /vp3_agent_memory_scope_current_v410/);
assert.match(context, /\$memoryScope/);
assert.match(context, /\$archiveScope/);
assert.match(context, /\$conversationScope/);
assert.match(context, /agent_memory_items m WHERE m\.user_id=\? AND '\.\$memoryScope/);
assert.match(context, /agent_chat_archive a WHERE a\.user_id=\? AND '\.\$archiveScope/);
assert.match(context, /agent_tool_history t JOIN chat_conversations c/);
assert.match(context, /memory_type='theme'/);
assert.doesNotMatch(context, /agent_memory_items WHERE user_id=\? AND is_active=1/);
assert.doesNotMatch(context, /agent_chat_archive WHERE user_id=\?/);

// The shared member shell intentionally locks the viewport. Long Memory content
// therefore has to own the middle grid row and provide its own scroll container.
assert.match(memoryPage, /\.memory-main\{[^}]*min-height:0;[^}]*grid-template-rows:58px minmax\(0,1fr\)/);
assert.match(memoryPage, /\.memory-canvas\{[^}]*min-height:0;[^}]*overflow-y:auto;[^}]*overscroll-behavior:contain/);

// Same logical memory must produce a different durable identity in every Agent
// namespace, including the canonical system-Agent namespace (id 0 / SQL NULL).
const scopedHashInput = (agentId, legacyHash) => `v410|agent:${Math.max(0, agentId)}|${legacyHash}`;
assert.notEqual(scopedHashInput(11, 'abc'), scopedHashInput(12, 'abc'));
assert.notEqual(scopedHashInput(0, 'abc'), scopedHashInput(11, 'abc'));

console.log('AGENT_MEMORY_SCOPE_V410=PASS');
