import assert from 'node:assert/strict';
import fs from 'node:fs';

const context = fs.readFileSync('agent-context-v131.js', 'utf8');
const scopeApi = fs.readFileSync('api/knowledge-scopes-v162.php', 'utf8');
const chat = fs.readFileSync('api/chat-v236.php', 'utf8');
const stream = fs.readFileSync('api/chat-stream-v121.php', 'utf8');

assert.match(context, /chatKnowledgeScopeV162/, 'Agent Chat renders a first-class Knowledge scope selector');
assert.match(context, /All personal knowledge/, 'scope UI exposes all personal Knowledge');
assert.match(context, /No personal knowledge/, 'scope UI exposes an explicit off mode');
assert.match(context, /folder:\$\{id\}/, 'scope UI emits canonical folder:<id> values');
assert.match(context, /knowledge_scope:currentKnowledgeScope\(\)/, 'browser sends the selected Knowledge scope with chat turns');
assert.match(context, /chat-stream-v121\\\.php/, 'same browser scope bridge covers streamed Agent Chat');
assert.match(context, /payload\?\.action==='send'/, 'scope bridge only mutates chat send payloads');
assert.match(context, /stonefellow:knowledge-scope-v162:/, 'scope selection is isolated per user and active agent');
assert.match(context, /new URL\('knowledge-scopes-v162\.php',chatUrl\)/, 'folder discovery stays inside the canonical API directory');

assert.match(scopeApi, /current_user\(\)/, 'folder discovery requires the signed-in user');
assert.match(scopeApi, /has_permission\('chat\.access', \$user\)/, 'folder discovery requires Agent Chat access');
assert.match(scopeApi, /FROM artist_transcript_folders_v177/, 'folder discovery uses the canonical shared-folder table');
assert.match(scopeApi, /WHERE created_by_user_id=\?/, 'folder discovery is owner-scoped at SQL level');
assert.doesNotMatch(scopeApi, /native_path|filesystem|folder_path|local_path/i, 'folder discovery never exposes native filesystem paths');

assert.match(chat, /\$input\['knowledge_scope'\]\?\?null/, 'normal Agent Chat consumes the browser Knowledge scope');
assert.match(stream, /\$input\['knowledge_scope'\]\?\?null/, 'streamed Agent Chat consumes the browser Knowledge scope');
assert.match(chat, /homeserver_local_knowledge'\s*=>\s*'not_queried'/, 'normal Agent Chat preserves the HomeServer privacy boundary');
assert.match(stream, /homeserver_local_knowledge'\s*=>\s*'not_queried'/, 'streamed Agent Chat preserves the HomeServer privacy boundary');

console.log('Knowledge Agent Context v16.2 browser contract passed.');
