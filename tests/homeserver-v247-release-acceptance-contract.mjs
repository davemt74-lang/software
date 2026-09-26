import fs from 'node:fs';
import assert from 'node:assert/strict';
const read=p=>fs.readFileSync(p,'utf8');
const bootstrap=read('includes/bootstrap.php');
const acceptance=read('includes/homeserver-acceptance-v247.php');
const api=read('api/homeserver-acceptance-v247.php');
const settings=read('settings-homeserver.php');
const js=read('homeserver-settings-v1210.js');
const workflow=read('.github/workflows/homeserver-runtime-journey.yml');
const checks=[
 ['v247 loads after v246 and v236',bootstrap.indexOf('homeserver-reconciliation-v246.php')<bootstrap.indexOf('homeserver-acceptance-v247.php')&&bootstrap.indexOf('homeserver-acceptance-v236.php')<bootstrap.indexOf('homeserver-acceptance-v247.php')],
 ['acceptance is zero-token/read-only',/zero_token_read_only_release_acceptance/.test(acceptance)&&/token_spend'=>0/.test(acceptance)&&/write_actions_executed'=>0/.test(acceptance)&&/physical_actions_executed'=>0/.test(acceptance)],
 ['requires v2.4',/version_compare\(\$version,'2\.4','>='\)/.test(acceptance)],
 ['covers all seven v2.4 sections',/federated_data_continuity/.test(acceptance)&&/contacts_continuity/.test(acceptance)&&/knowledge_continuity/.test(acceptance)&&/task_calendar_continuity/.test(acceptance)&&/file_document_continuity/.test(acceptance)&&/agent_brain_memory_continuity/.test(acceptance)&&/disconnect_reconnect_reconciliation/.test(acceptance)],
 ['checks reconciliation freshness',/needs_reconciliation/.test(acceptance)&&/continuity_current/.test(acceptance)],
 ['checks schema/package contract',/minimum_schema_version/.test(acceptance)&&/windows_installer/.test(acceptance)&&/upgrade_preserves_private_data/.test(acceptance)],
 ['API remains POST/CSRF/account gated',/POST required/.test(api)&&/hash_equals\(csrf_token\(\)/.test(api)&&/account\.access/.test(api)&&/chat\.access/.test(api)],
 ['settings uses v247 endpoint',/homeserver-acceptance-v247\.php/.test(settings)&&/HomeServer 2\.4/.test(settings)],
 ['settings JS renders v2.4 result',/Ready for HomeServer 2\.4/.test(js)&&/HomeServer 2\.4 release acceptance passed/.test(js)],
 ['runtime journey lints/runs v247 tests',/homeserver-v247-release-acceptance-contract\.mjs/.test(workflow)&&/homeserver-v247-release-acceptance-unit\.php/.test(workflow)],
];
for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`HomeServer v2.4 Section 8 Cloud release acceptance contract: ${checks.length}/${checks.length} passed`);
