import fs from 'node:fs';
import assert from 'node:assert/strict';

const root = new URL('../', import.meta.url);
const api = fs.readFileSync(new URL('api/chat-v236.php', root), 'utf8');
const home = fs.readFileSync(new URL('includes/homeserver-agent-v018.php', root), 'utf8');
const delegation = fs.readFileSync(new URL('includes/homeserver-agent-v025.php', root), 'utf8');
const execution = fs.readFileSync(new URL('includes/chat-execution-v019.php', root), 'utf8');
const chat = fs.readFileSync(new URL('chat.js', root), 'utf8');

assert.match(home, /function homeserver_agent_v018_set_last_attempt/);
assert.match(home, /function homeserver_agent_v018_last_attempt/);
assert.match(home, /function homeserver_agent_v018_failure_class/);
assert.match(home, /microtime\(true\)/);
assert.match(home, /'latency_ms'=>\$latency/);
assert.match(home, /'timeout'/);
assert.match(home, /'relay_unreachable'/);
assert.match(home, /'authorization'/);
assert.match(home, /'provider_unavailable'/);
assert.doesNotMatch(home, /'raw_error'\s*=>|'provider_response'\s*=>|'relay_token'\s*=>|'homeserver_token'\s*=>/i);
assert.match(delegation, /homeserver_agent_v018_set_last_attempt/);
assert.match(delegation, /homeserver_agent_v018_failure_class/);
assert.match(delegation, /'latency_ms'=>\$latency/);
assert.doesNotMatch(delegation, /'raw_error'\s*=>|'provider_response'\s*=>|'relay_token'\s*=>|'homeserver_token'\s*=>/i);

assert.match(execution, /'runtime_version'=>'v0\.22'/);
assert.match(execution, /'failure_class'=>\$failureClass/);
assert.match(execution, /'latency_ms'=>max\(0,\$latencyMs\)/);
assert.match(execution, /cloud_balance_remaining/);
assert.match(execution, /VP3 tokens charged/);
assert.match(execution, /VP3 tokens left/);
assert.match(execution, /ms HomeServer/);
assert.match(execution, /→ fallback/);
assert.match(execution, /homeserver_agent_v018_last_attempt/);
assert.doesNotMatch(execution, /relay_token|homeserver_token|bearer_token|provider_response|raw_error/i);

// Canonical chat persistence is unchanged. Runtime truth is stored on the same
// assistant message context and rendered through the existing source-chip UI.
assert.match(api, /'execution'=>\$execution/);
assert.match(api, /chat_execution_v019_source\(\$execution\)/);
assert.match(api, /homeserver_agent_v025_chat/);
assert.match(delegation, /homeserver_agent_v018_chat/);
assert.match(api, /chat_execution_v019_fallback/);
assert.match(chat, /function sourceHtml\(source\)/);
assert.match(chat, /sources\.map\(sourceHtml\)/);
assert.match(chat, /JSON\.parse\(message\.context_json\)/);
assert.match(chat, /action:'messages_after'/);

console.log('VP3 v0.22 live compute runtime contract passed through v0.25 delegation');