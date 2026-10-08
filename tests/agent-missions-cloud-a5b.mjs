import assert from 'node:assert/strict';
import fs from 'node:fs';
const file=name=>fs.readFileSync(new URL('../'+name,import.meta.url),'utf8');
const php=file('api/agent-missions-cloud-v1.php');
const js=file('chat-agent-teams-a3.js');
const css=file('chat-agent-teams-a3.css');
for(const action of ['browser.grant','browser.get','browser.capture','browser.revoke']){
 assert.ok(php.includes("'"+action+"'"),'Cloud browser action not allowlisted: '+action);
 assert.ok(js.includes("'"+action+"'"),'Browser UI operation missing: '+action);
}
assert.match(php,/require_permission\('chat\.access'\)/);
assert.match(php,/hash_equals\(csrf_token\(\)/);
assert.match(php,/homeserver_https_v1300_queue\(\$userId/);
assert.match(php,/preg_match\('\/\^\[0-9a-f-\]\{36\}\$\/i'/);
assert.match(js,/vp3-teams-browser-optin/);
assert.match(js,/window\.confirm\('Approve this read-only HTTPS origin/);
assert.match(js,/window\.confirm\('Revoke this worker browser/);
assert.match(js,/data:image\/jpeg;base64/);
assert.match(js,/textContent/);
assert.match(js,/Prepare mission first/);
assert.match(css,/max-height/);
assert.doesNotMatch(php,/api\/v1\/control/);
assert.doesNotMatch(js,/eval\(/);
console.log('CLOUD_A5B PASS: CSRF, explicit approval, bounded screenshots, revoke, stable chat and safe rendering');
