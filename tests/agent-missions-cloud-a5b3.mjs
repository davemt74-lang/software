import assert from 'node:assert/strict';
import fs from 'node:fs';
const load=p=>fs.readFileSync(new URL('../'+p,import.meta.url),'utf8');
const php=load('api/agent-missions-cloud-v1.php');
const ui=load('chat-agent-teams-a3.js');
const css=load('chat-agent-teams-a3.css');
for(const operation of ['browser.action.propose','browser.action.approve']){
  assert.ok(php.includes("'"+operation+"'"),'Action not allowlisted in Cloud: '+operation);
  assert.ok(ui.includes("'"+operation+"'"),'Action missing from Chat: '+operation);
}
assert.match(php,/hash_equals\(csrf_token\(\)/,'CSRF protection required');
assert.match(php,/require_permission\('chat\.access'\)/,'User must have current chat access');
assert.match(php,/homeserver_https_v1300_queue\(\$userId/,'Must use authenticated paired relay');
assert.match(php,/\$input\['confirmed'\]/,'Approval must be explicit');
assert.match(php,/\$input\['proposal_id'\]/,'Approval is bound to exact ID');
assert.match(php,/strlen\(\$value\)>300/,'Bound human-supplied form input');
assert.match(ui,/Ask agent about page controls/);
assert.match(ui,/Approve safe control/);
assert.match(ui,/window\.confirm\('Approve this exact non-submitting/);
assert.match(ui,/data-dom-approval-task/);
assert.match(ui,/textContent/,'Safe rendering must be retained');
assert.match(ui,/proposal\.kind==='fill'/);
assert.match(css,/vp3-dom-approval/);
assert.doesNotMatch(php,/api\/v1\/control/,'Cloud must not proxy owner API');
assert.doesNotMatch(ui,/\beval\(/,'UI must not evaluate model text');
console.log('CLOUD_A5B3 PASS: bounded owner-entered data, exact proposal consent, CSRF, paired app and safe canvas');
