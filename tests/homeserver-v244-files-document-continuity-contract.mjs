import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const helper=read('includes/homeserver-files-v244.php');
const api=read('api/homeserver-files-v244.php');
const bootstrap=read('includes/bootstrap.php');
const federation=read('includes/homeserver-federated-data-v240.php');
const shared=read('includes/homeserver-shared-agent-v210.php');
const governed=read('includes/homeserver-governed-actions-v233.php');
const policies=read('includes/user-agent-system-v236.php');
const profile=read('includes/profile-agent.php');
const knowledge=read('knowledge.php');
const workflow=read('.github/workflows/homeserver-runtime-journey.yml');

const checks=[
 ['Section 5 helper is loaded before shared Agent exchange',
  bootstrap.includes('homeserver-files-v244.php') &&
  bootstrap.indexOf('homeserver-files-v244.php') < bootstrap.indexOf('homeserver-shared-agent-v210.php')],
 ['files is a first-class v2.4 federation dataset',
  /['"]files['"]/.test(federation) && /dataset['"]?=>['"]files/.test(helper)],
 ['Cloud-native files project file-backed owner My Knowledge records',
  /knowledge_items/.test(helper) && /knowledge_scope='personal'/.test(helper) &&
  /COALESCE\(i\.file_path,' '\)|COALESCE\(i\.file_path,''\)/.test(helper.replaceAll(" <>","<>"))],
 ['Cloud projection does not expose native file_path values',
  !/['"]file_path['"]\s*=>/.test(helper) && /open_url/.test(helper) && /knowledge-file\.php/.test(helper)],
 ['HomeServer-native files are read through files.list only',
  /homeserver_execution_v230_execute\(\$userId,'files\.list'/.test(helper) &&
  !/INSERT INTO knowledge_items|UPDATE knowledge_items|DELETE FROM knowledge_items/.test(helper)],
 ['HomeServer file identity is validated from local_file authority tuples',
  /local_file:/.test(helper) && /homeserver_federated_v240_canonical_id\('homeserver','files'/.test(helper) &&
  /\^\[0-9a-f\]\{64\}\$/.test(helper)],
 ['HomeServer paths and bytes are not persisted in Cloud continuity projection',
  /absolute_homeserver_paths_persisted/.test(helper) && /homeserver_file_bytes_persisted/.test(helper) &&
  !/relative_path/.test(helper)],
 ['HomeServer file mutations route through governed files.update/files.delete',
  /homeserver_governed_v233_request\(\$userId,'files\.'\.\$action/.test(helper) &&
  governed.includes("'files.update'") && governed.includes("'files.delete'") &&
  /local_owner_only'=>true/.test(governed)],
 ['mutation requests require canonical identity revision and idempotency key',
  /canonical_id/.test(helper) && /mutation_id/.test(helper) && /expected_revision/.test(helper)],
 ['Files continuity API is account scoped and CSRF protected',
  /account\.access/.test(api) && /chat\.access/.test(api) && /hash_equals\(csrf_token\(\),\$csrf\)/.test(api)],
 ['shared Agent fabric carries Cloud and HomeServer files metadata',
  /homeserver_files_v244_cloud_items/.test(shared) && /['"]files['"]/.test(shared)],
 ['HomeServer Files is governed by central Agent/Profile data policy',
  /homeserver_files/.test(policies) && /'files'=>'homeserver_files'/.test(profile)],
 ['My Knowledge visibly projects HomeServer Files with storage boundary',
  /homeserver_files_v244_homeserver_items/.test(knowledge) &&
  /HomeServer Files/.test(knowledge) && /file bytes/.test(knowledge) && /filesystem paths/.test(knowledge)],
 ['runtime journey lints and executes both Section 5 gates',
  /homeserver-v244-files-document-continuity-contract\.mjs/.test(workflow) &&
  /homeserver-v244-files-document-continuity-unit\.php/.test(workflow)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`HomeServer v2.4 Section 5 Files & Document continuity contract: ${checks.length}/${checks.length} passed`);
