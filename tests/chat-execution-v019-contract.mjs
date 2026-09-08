import fs from 'node:fs';
import assert from 'node:assert/strict';

const root = new URL('../', import.meta.url);
const api = fs.readFileSync(new URL('api/chat-v236.php', root), 'utf8');
const execution = fs.readFileSync(new URL('includes/chat-execution-v019.php', root), 'utf8');
const chat = fs.readFileSync(new URL('chat.js', root), 'utf8');

assert.match(api, /chat-execution-v019\.php/);
assert.match(api, /homeserver_agent_v018_chat\(\$user,\$query,\$conversationId\)/);
assert.match(api, /chat_execution_v019_homeserver\(\$homeResult\)/);
assert.match(api, /chat_execution_v019_fallback\(\$user,\$homePaired,\$homePaired\)/);
assert.match(api, /chat_execution_v019_tool\(\)/);
assert.match(api, /chat_execution_v019_source\(\$execution\)/);
assert.match(api, /'execution'=>\$execution/);
assert.match(api, /'sources'=>\$publicSources/);

assert.match(execution, /'homeserver_local'/);
assert.match(execution, /'user_provider'/);
assert.match(execution, /'vp3_cloud'/);
assert.match(execution, /'vp3_retrieval'/);
assert.match(execution, /'vp3_tool'/);
assert.match(execution, /'homeserver_not_paired'/);
assert.match(execution, /'homeserver_unavailable'/);
assert.match(execution, /'cloud_tokens_debited'/);
assert.match(execution, /'source'=>'compute-routing:v019'/);
assert.match(execution, /'Compute: '/);
assert.doesNotMatch(execution, /relay_token|homeserver_token|bearer_token|provider_response|raw_error/i);

// Existing Chat source rendering is deliberately reused: the compute badge is
// visible on the immediate response, a conversation reload, and messages_after.
assert.match(chat, /function sourceHtml\(source\)/);
assert.match(chat, /sources\.map\(sourceHtml\)/);
assert.match(chat, /JSON\.parse\(message\.context_json\)/);
assert.match(chat, /context\.sources/);
assert.match(chat, /action:'messages_after'/);

console.log('VP3 v0.19 compute routing visibility contract passed');
