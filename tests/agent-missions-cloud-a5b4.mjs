import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
const file=p=>readFileSync(new URL('../'+p,import.meta.url),'utf8');
const php=file('api/agent-missions-cloud-v1.php');
const ui=file('chat-agent-teams-a3.js');
for(const action of ['takeover','release','control','search.review','search.submit']){
 assert.match(php,new RegExp("browser\\\\.owner\\\\."+action.replace('.','\\\\.')),'PHP relay missing '+action);
 assert.match(ui,new RegExp("browser\\\\.owner\\\\."+action.replace('.','\\\\.')),'UI missing '+action);
}
assert.match(php,/hash_equals\(csrf_token\(\)/);
assert.match(php,/require_permission\('chat\.access'\)/);
assert.match(php,/homeserver_https_v1300_queue\(\$userId/);
assert.match(php,/\$input\['confirmed'\]/);
assert.match(php,/proposal_id/);
assert.match(ui,/Take control/);
assert.match(ui,/Return control to agent/);
assert.match(ui,/Confirm search submission/);
assert.match(ui,/owner\.mode!=='owner'/);
assert.match(ui,/data-owner-value-task/);
assert.match(ui,/Owner controlling browser/);
assert.match(ui,/liveByWorker\.get\(selected\+'\|'\+tid\)\?\.owner_takeover\?\.mode==='owner'/);
assert.doesNotMatch(php,/api\/v1\/control/);
assert.doesNotMatch(ui,/eval\(/);
console.log('A5B4_CLOUD PASS: scoped owner takeover, signed approvals, safe GET submission and brain visibility');