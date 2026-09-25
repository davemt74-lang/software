import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const reads=read('includes/homeserver-local-reads-v231.php');
const bootstrap=read('includes/bootstrap.php');
const chat=read('api/chat-stream-v121.php');
const workflow=read('.github/workflows/homeserver-runtime-journey.yml');

const checks=[
 ['Section 2 layer is loaded after canonical execution contract',bootstrap.indexOf('homeserver-local-execution-v230.php')<bootstrap.indexOf('homeserver-local-reads-v231.php')],
 ['local reads require explicit HomeServer/local intent',/explicitLocal/.test(reads)&&/homeserver|home server|local/.test(reads)],
 ['generic prompts can return no local operation',/return \['domain'=>'','operation'=>'','payload'=>\[\]\]/.test(reads)],
 ['file reference reads use opaque hsf references only',/hsf-\[0-9\]\+/.test(reads)&&/files\.read/.test(reads)],
 ['file discovery is bounded',/files\.list/.test(reads)&&/'limit'=>12/.test(reads)],
 ['Knowledge search is bounded to explicit local intent',/knowledge\.search/.test(reads)&&/'domain'=>'knowledge'/.test(reads)],
 ['tool discovery uses read-only tools.list',/tools\.list/.test(reads)&&/'domain'=>'tools'/.test(reads)],
 ['device reads execute devices.list rather than a device command',/devices\.list/.test(reads)&&!/devices\.command/.test(reads)],
 ['all local reads route through the v2.3 receipt boundary',/homeserver_execution_v230_execute/.test(reads)],
 ['local read failures are soft and do not block Cloud Agent fallback',/catch\(Throwable \$e\)/.test(reads)&&/'status'=>'failed'/.test(reads)],
 ['Cloud Agent context is enriched through the same chat path',/homeserver_reads_v231_enrich_context/.test(chat)],
 ['local execution provenance is attached to execution metadata',/homeserver_local_read/.test(chat)&&/homeserver_reads_v231_last/.test(chat)],
 ['runtime journey gates Section 2 static and runtime tests',/homeserver-v231-local-reads-contract\.mjs/.test(workflow)&&/homeserver-v231-local-reads-unit\.php/.test(workflow)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`HomeServer v2.3 Section 2 local reads contract: ${checks.length}/${checks.length} passed`);
