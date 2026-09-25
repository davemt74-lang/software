import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const bootstrap=read('includes/bootstrap.php');
const acceptance=read('includes/homeserver-acceptance-v236.php');
const api=read('api/homeserver-acceptance-v236.php');
const workflow=read('.github/workflows/homeserver-runtime-journey.yml');

const checks=[
 ['v2.3 acceptance loads after Section 5 execution helpers',bootstrap.indexOf('homeserver-profile-agent-v235.php')<bootstrap.indexOf('homeserver-acceptance-v236.php')],
 ['acceptance is explicitly zero-token and read-only',/zero_token_read_only_release_acceptance/.test(acceptance)&&/token_spend'=>0/.test(acceptance)&&/write_actions_executed'=>0/.test(acceptance)&&/physical_actions_executed'=>0/.test(acceptance)],
 ['acceptance checks product version 2.3',/version_compare\(\$version,'2\.3','>='\)/.test(acceptance)],
 ['acceptance checks complete unified execution operation set',/agent\.infer\.local/.test(acceptance)&&/speech\.status/.test(acceptance)&&/action\.approve/.test(acceptance)],
 ['acceptance checks bounded files and citation-safe Knowledge',/write_policy_gated/.test(acceptance)&&/arbitrary_paths/.test(acceptance)&&/citation_safe_search/.test(acceptance)],
 ['acceptance checks governed physical devices',/governed_device_actions/.test(acceptance)&&/ambient_direct_execution/.test(acceptance)],
 ['acceptance checks Profile Agent privacy contract',/profile_safe_local_inference/.test(acceptance)&&/caller_supplied_context_only/.test(acceptance)&&/tools_enabled/.test(acceptance)],
 ['acceptance checks compute fallback and physical no-fallback',/homeserver_execution_v230_policy\('agent\.chat'/.test(acceptance)&&/devices\.command/.test(acceptance)],
 ['acceptance checks canonical execution receipt schema',/homeserver_execution_v230_schema_ready/.test(acceptance)&&/homeserver_execution_v230_recent/.test(acceptance)],
 ['acceptance endpoint requires account/chat permissions and CSRF',/account\.access/.test(api)&&/chat\.access/.test(api)&&/hash_equals\(csrf_token\(\)/.test(api)],
 ['acceptance helper never calls Agent chat or governed action execution',!/homeserver_agent_v018_chat\(/.test(acceptance)&&!/homeserver_governed_v233_execute\(/.test(acceptance)],
 ['runtime journey lints and executes Section 6 acceptance tests',/homeserver-v236-release-acceptance-contract\.mjs/.test(workflow)&&/homeserver-v236-release-acceptance-unit\.php/.test(workflow)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`HomeServer v2.3 Section 6 Cloud release acceptance contract: ${checks.length}/${checks.length} passed`);
