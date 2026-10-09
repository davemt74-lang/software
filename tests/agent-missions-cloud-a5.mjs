import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const php=readFileSync(new URL('../api/agent-missions-cloud-v1.php',import.meta.url),'utf8');
const js=readFileSync(new URL('../chat-agent-teams-a3.js',import.meta.url),'utf8');
const css=readFileSync(new URL('../chat-agent-teams-a3.css',import.meta.url),'utf8');

assert.match(php,/require_permission\('chat\.access'\)/);
assert.match(php,/hash_equals\(csrf_token\(\)/);
assert.match(php,/homeserver_https_v1300_queue\(\$userId/);
assert.match(php,/'bind_provider'/);
assert.match(php,/'execution'/);
assert.match(php,/'anthropic'/);
assert.match(php,/'ollama'/);
assert.match(php,/'openrouter'/);
assert.match(php,/preg_match\('\/\^\[0-9a-f-\]\{36\}\$\/i'/,
 'Must only accept bounded task identifiers');
assert.match(js,/id="vp3MissionProvider"/);
assert.match(js,/value="anthropic"/);
assert.match(js,/value="openai"/);
assert.match(js,/value="ollama"/);
assert.match(js,/api\('bind_provider'/);
const indexBind=js.indexOf("api('bind_provider'");
const indexStart=js.indexOf("api('start'",indexBind);
assert.ok(indexBind>=0&&indexStart>indexBind,'Bind provider before mission start');
assert.match(js,/isolated contexts/);
assert.match(css,/select\[name=provider_key\]/);
assert.doesNotMatch(js,/eval\(/);
assert.doesNotMatch(php,/api\/v1\/control/);
console.log('CLOUD_A5 PASS: CSRF-scoped model choice, allowlisted providers, isolation disclosure and pre-start binding');
