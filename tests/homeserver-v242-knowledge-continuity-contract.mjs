import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const helper=read('includes/homeserver-knowledge-v242.php');
const retrieval=read('includes/knowledge-retrieval-v162.php');
const api=read('api/homeserver-knowledge-v242.php');
const bootstrap=read('includes/bootstrap.php');
const approvals=read('includes/homeserver-approvals-v028.php');
const vp3=read('includes/homeserver-vp3.php');
const governed=read('includes/homeserver-governed-actions-v233.php');
const knowledgePage=read('knowledge.php');
const workflow=read('.github/workflows/homeserver-runtime-journey.yml');

const checks=[
 ['v2.4 Knowledge helper is loaded',/homeserver-knowledge-v242\.php/.test(bootstrap)],
 ['Cloud Knowledge preserves Cloud authority and canonical identity',/vp3_cloud/.test(helper)&&/knowledge:\'.*\$id/.test(helper)&&/homeserver_federated_v240_envelope/.test(helper)],
 ['HomeServer Knowledge accepts only canonical HomeServer authority keys',/knowledge_item:/.test(helper)&&/homeserver_federated_v240_canonical_id\('homeserver','knowledge'/.test(helper)],
 ['HomeServer search remains read-only execution',/homeserver_execution_v230_execute\(\$userId,'knowledge\.search'/.test(helper)],
 ['HomeServer native mutations use governed action tools',/knowledge\.create/.test(helper)&&/knowledge\.update/.test(helper)&&/knowledge\.delete/.test(helper)&&/homeserver_governed_v233_request/.test(helper)],
 ['Cloud-owned canonical IDs cannot be sent through HomeServer mutation path',/not a writable HomeServer-native record/.test(helper)],
 ['knowledge.write is part of pairing and approval permission upgrade sets',/knowledge\.write/.test(approvals)&&/knowledge\.write/.test(vp3)],
 ['governed action allowlist includes Knowledge mutations',/knowledge\.create/.test(governed)&&/knowledge\.update/.test(governed)&&/knowledge\.delete/.test(governed)],
 ['Agent retrieval calls the v2.4 HomeServer Knowledge adapter',/homeserver_knowledge_v242_agent_search/.test(retrieval)&&/homeserver_knowledge_v242_merge_retrieval/.test(retrieval)],
 ['Agent retrieval retains owner-session boundary',/knowledge_retrieval_v162_owner_session/.test(retrieval)],
 ['Agent context labels federated Knowledge as untrusted evidence',/Federated Knowledge — UNTRUSTED EVIDENCE/.test(retrieval)&&/\[K#\]/.test(retrieval)],
 ['Agent citations carry canonical authority metadata',/canonical_id/.test(retrieval)&&/authority_source/.test(retrieval)&&/record_revision/.test(retrieval)],
 ['HomeServer citation export does not copy relative_path into public citation shape',!/\['relative_path'\]/.test(retrieval)],
 ['Knowledge workspace displays Cloud and HomeServer authority separately',/HomeServer Knowledge/.test(knowledgePage)&&/data-knowledge-authority="homeserver"/.test(knowledgePage)],
 ['API reads unified Knowledge and CSRF-protects mutations',/homeserver_knowledge_v242_unified/.test(api)&&/hash_equals\(csrf_token\(\)/.test(api)],
 ['runtime journey gates Section 3 contract and unit tests',/homeserver-v242-knowledge-continuity-contract\.mjs/.test(workflow)&&/homeserver-v242-knowledge-continuity-unit\.php/.test(workflow)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`HomeServer v2.4 Section 3 Knowledge continuity contract: ${checks.length}/${checks.length} passed`);
