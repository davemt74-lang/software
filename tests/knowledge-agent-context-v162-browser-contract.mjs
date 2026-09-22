import assert from 'node:assert/strict';
import fs from 'node:fs';

const context = fs.readFileSync('agent-context-v131.js', 'utf8');
const chatUi = fs.readFileSync('chat.js', 'utf8');
const scopeApi = fs.readFileSync('api/knowledge-scopes-v162.php', 'utf8');
const chat = [fs.readFileSync('api/chat-v236.php', 'utf8'),fs.readFileSync('includes/agent-chat-runtime-v2160.php', 'utf8')].join('\n');
const stream = fs.readFileSync('api/chat-stream-v121.php', 'utf8');

assert.doesNotThrow(() => new Function(context), 'Knowledge scope runtime must remain valid JavaScript');
assert.doesNotThrow(() => new Function(chatUi), 'canonical Chat runtime must remain valid JavaScript');

assert.match(context, /chatKnowledgeScopeV162/, 'Agent Chat renders a first-class Knowledge scope selector');
assert.match(context, /All personal knowledge/, 'scope UI exposes all personal Knowledge');
assert.match(context, /No personal knowledge/, 'scope UI exposes an explicit off mode');
assert.match(context, /folder:\$\{id\}/, 'scope UI emits canonical folder:<id> values');
assert.match(context, /stonefellow:knowledge-scope-v162:/, 'scope selection is isolated per user and active agent');
assert.match(context, /new URL\('knowledge-scopes-v162\.php',chatUrl\)/, 'folder discovery stays inside the canonical API directory');

assert.match(context, /Saved folder · loading…/, 'stored folder scope remains selected while folder discovery is pending');
assert.match(context, /setKnowledgeScopeOptions\(\[\],\{finalize:false\}\)/, 'initial selector hydration is provisional and must not erase a stored folder');
assert.match(context, /if\(finalize\)persistKnowledgeScope\(next\)/, 'stored scope is only rewritten after authoritative folder discovery');
assert.match(context, /AbortController/, 'folder discovery has a bounded network request');
assert.match(context, /Knowledge folders request timed out\./, 'folder timeout becomes an actionable UI state');
assert.match(context, /Saved folder · unavailable/, 'failed discovery must not leave a stale loading label');
assert.match(context, /dataset\.knowledgeScopeReady='0'/, 'folder discovery failures remain observable');
assert.match(context, /addEventListener\('focus'/, 'focusing a failed selector retries folder discovery');
assert.doesNotMatch(context, /window\.fetch\s*=/, 'Knowledge scope must never replace the global fetch implementation');

assert.match(chatUi, /payload\.knowledge_scope=knowledgeScopeRuntime\.value\(\)/, 'canonical Chat sends the selected Knowledge scope directly');
assert.match(context, /knowledge_scope:currentKnowledgeScope\(\)/, 'scope runtime exposes a canonical payload helper');

assert.match(scopeApi, /current_user\(\)/, 'folder discovery requires the signed-in user');
assert.match(scopeApi, /has_permission\('chat\.access', \$user\)/, 'folder discovery requires Agent Chat access');
assert.match(scopeApi, /FROM artist_transcript_folders_v177/, 'folder discovery uses the canonical shared-folder table');
assert.match(scopeApi, /WHERE created_by_user_id=\?/, 'folder discovery is owner-scoped at SQL level');
assert.doesNotMatch(scopeApi, /native_path|filesystem|folder_path|local_path/i, 'folder discovery never exposes native filesystem paths');
assert.match(scopeApi, /Knowledge folders need the latest VP3 database upgrade\./, 'missing folder schema must fail visibly instead of silently erasing a saved scope');
assert.match(scopeApi, /Knowledge folders are temporarily unavailable\./, 'folder query failures must return a retryable API error');
assert.doesNotMatch(scopeApi, /catch \(Throwable \$e\) \{\s*\$folders = \[\];/, 'folder query failures must not masquerade as an authoritative empty folder list');

assert.match(chat, /\$input\['knowledge_scope'\]\?\?null/, 'normal Agent Chat consumes the browser Knowledge scope');
assert.match(stream, /\$input\['knowledge_scope'\]\?\?null/, 'streamed Agent Chat consumes the browser Knowledge scope');
assert.match(chat, /homeserver_local_knowledge'\s*=>\s*'not_queried'/, 'normal Agent Chat preserves the HomeServer privacy boundary');
assert.match(stream, /homeserver_local_knowledge'\s*=>\s*'not_queried'/, 'streamed Agent Chat preserves the HomeServer privacy boundary');

console.log('Knowledge Agent Context v16.2 browser contract passed.');
