import assert from 'node:assert/strict';
import fs from 'node:fs';
const file=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const php=file('api/agent-missions-cloud-v1.php');
const js=file('chat-agent-teams-a3.js');
const css=file('chat-agent-teams-a3.css');
for(const name of ['start','get','refresh','propose','approve','stop']){
 assert.ok(php.includes("'browser.live."+name+"'"),'Cloud relay missing '+name);
 assert.ok(js.includes("'browser.live."+name+"'"),'Chat missing '+name);
}
assert.match(php,/hash_equals\(csrf_token\(\)/);
assert.match(php,/require_permission\('chat\.access'\)/);
assert.match(php,/homeserver_https_v1300_queue\(\$userId/);
assert.match(php,/\$input\['confirmed'\]/);
assert.match(php,/proposal_id/);
assert.match(js,/data-live-browser/);
assert.match(js,/Ask agent for next link/);
assert.match(js,/Approve suggested navigation/);
assert.match(js,/window\.confirm\('Approve the agent-suggested link/);
assert.match(js,/Refresh live view/);
assert.match(js,/Stop live browser/);
assert.match(js,/liveByWorker\.delete\(key\)/);
assert.match(js,/image\/jpeg;base64/);
assert.match(css,/vp3-worker-live-preview/);
assert.doesNotMatch(js,/eval\(/);
assert.doesNotMatch(php,/api\/v1\/control/);
console.log('CLOUD_A5B2 PASS: same Chat, scoped relay, direct approval, live frames, revoke hygiene, no owner API');
