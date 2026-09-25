import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const v220=read('includes/homeserver-execution-routing-v220.php');
const v230=read('includes/homeserver-local-execution-v230.php');
const bootstrap=read('includes/bootstrap.php');
const setup=read('setup.php');
const upgrade=read('upgrade.php');
const workflow=read('.github/workflows/homeserver-runtime-journey.yml');

const checks=[
 ['v2.3 execution layer is loaded after v2.2 routing',bootstrap.indexOf('homeserver-execution-routing-v220.php')<bootstrap.indexOf('homeserver-local-execution-v230.php')],
 ['v2.3 defines durable sanitized execution receipts',/homeserver_execution_receipts/.test(v230)&&/result_meta_json/.test(v230)&&!/payload_json|result_json|prompt_json/.test(v230)],
 ['setup installs v2.3 receipt schema',/homeserver_execution_v230_ensure_schema\(\$pdo\)/.test(setup)],
 ['upgrade readiness requires v2.3 receipt schema',/homeserver_execution_v230_schema_ready\(\)/.test(upgrade)&&/homeserver_execution_v230_ensure_schema\(\$pdo\)/.test(upgrade)],
 ['legacy plural tools.execute is normalized to the canonical tool.execute operation',/tools\.execute/.test(v230)&&/tool\.execute/.test(v230)&&/tool\.execute/.test(v220)],
 ['execution domains cover compute files knowledge tools voice and devices',["agent_compute","files","knowledge","tools","voice","devices"].every(x=>v230.includes("'"+x+"'"))],
 ['Cloud fallback is limited to read/compute-safe operations',/fallback_allowed/.test(v230)&&/write_or_physical/.test(v230)&&/devices\.list/.test(v230)&&!/devices\.command'\]/.test(v230)],
 ['execution receipts classify route status fallback failure and duration',["route","status","fallback_used","failure_class","duration_ms"].every(x=>v230.includes("'"+x+"'"))],
 ['result provenance stores bounded metadata rather than private result bodies',/homeserver_execution_v230_result_meta/.test(v230)&&/mb_strimwidth/.test(v230)&&/result_keys/.test(v230)],
 ['existing v2.2 projection can expose recent execution receipts to cognition',/recent_receipts/.test(v220)&&/homeserver_execution_v230_recent/.test(v220)],
 ['runtime journey lints and executes v2.3 contract and unit tests',/homeserver-local-execution-v230\.php/.test(workflow)&&/homeserver-v230-execution-contract\.mjs/.test(workflow)&&/homeserver-v230-execution-unit\.php/.test(workflow)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`HomeServer v2.3 Section 1 execution contract: ${checks.length}/${checks.length} passed`);
