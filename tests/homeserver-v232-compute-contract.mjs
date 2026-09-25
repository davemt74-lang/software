import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const compute=read('includes/homeserver-compute-v232.php');
const reads=read('includes/homeserver-local-reads-v231.php');
const policy=read('includes/chat-agent-policy-v236.php');
const textChat=read('includes/agent-chat-runtime-v2160.php');
const stream=read('api/chat-stream-v121.php');
const bootstrap=read('includes/bootstrap.php');
const workflow=read('.github/workflows/homeserver-runtime-journey.yml');

const checks=[
 ['compute receipt layer loads after local read layer',bootstrap.indexOf('homeserver-local-reads-v231.php')<bootstrap.indexOf('homeserver-compute-v232.php')],
 ['compute receipts use canonical v2.3 receipt writer',/homeserver_execution_v230_receipt/.test(compute)&&/homeserver_execution_v230_policy/.test(compute)],
 ['compute route distinguishes local user-provider and VP3 Cloud via HomeServer',["homeserver_local","homeserver_user_provider","vp3_cloud_via_homeserver"].every(x=>compute.includes("'"+x+"'"))],
 ['compute receipt metadata excludes answer prompt and private payload bodies',/homeserver_compute_v232_meta/.test(compute)&&!/\['answer'\]|\['reply'\]|prompt_json|payload_json|result_json/.test(compute)],
 ['failed HomeServer attempts create failed receipts',/homeserver_compute_v232_failure/.test(compute)&&/'failed'/.test(compute)],
 ['direct Cloud fallback after HomeServer failure is recorded',/homeserver_compute_v232_fallback/.test(compute)&&/'cloud_fallback'/.test(compute)&&/'completed'/.test(compute)],
 ['text Agent Chat attaches compute receipts',/homeserver_compute_v232_attach/.test(textChat)&&/homeserver_compute_receipt/.test(compute)],
 ['streaming Agent Chat attaches compute receipts',/homeserver_compute_v232_attach/.test(stream)],
 ['HomeServer-only hard failures are recorded before blocking in text and stream',/homeserver_compute_v232_failure/.test(textChat)&&/homeserver_compute_v232_failure/.test(stream)],
 ['local read enrichment moved to shared policy context',/homeserver_reads_v231_enrich_context/.test(policy)&&!/homeserver_reads_v231_enrich_context/.test(stream)],
 ['local reads are request-cached to avoid duplicate retrieval',/static \$cache=\[\]/.test(reads)&&/\$cacheKey/.test(reads)],
 ['text Agent Chat persists local-read execution metadata',/homeserver_local_read/.test(textChat)&&/homeserver_reads_v231_last/.test(textChat)],
 ['runtime journey gates Section 3 contract and runtime tests',/homeserver-v232-compute-contract\.mjs/.test(workflow)&&/homeserver-v232-compute-unit\.php/.test(workflow)],
];
for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`HomeServer v2.3 Section 3 compute receipts: ${checks.length}/${checks.length} passed`);
