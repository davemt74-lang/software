import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const helper=read('includes/homeserver-agent-brain-memory-v245.php');
const api=read('api/homeserver-agent-brain-memory-v245.php');
const bootstrap=read('includes/bootstrap.php');
const federation=read('includes/homeserver-federated-data-v240.php');
const shared=read('includes/homeserver-shared-agent-v210.php');
const brain=read('includes/agent-brain-context-v142.php');
const governed=read('includes/homeserver-governed-actions-v233.php');
const execution=read('includes/homeserver-local-execution-v230.php');
const policies=read('includes/user-agent-system-v236.php');
const profile=read('includes/profile-agent.php');
const workflow=read('.github/workflows/homeserver-runtime-journey.yml');

const checks=[
 ['Section 6 helper loads before shared Agent fabric',
  bootstrap.includes('homeserver-agent-brain-memory-v245.php') &&
  bootstrap.indexOf('homeserver-agent-brain-memory-v245.php') < bootstrap.indexOf('homeserver-shared-agent-v210.php')],
 ['Cloud-native Agent Brain memory remains agent_memory_items',
  /FROM agent_memory_items m/.test(helper) && /agent_memory_item:/.test(helper) &&
  /vp3_agent_memory_scope_sql_v410/.test(helper)],
 ['Section 6 helper does not mutate Cloud native memory tables',
  !/INSERT\s+INTO\s+agent_memory_items|UPDATE\s+agent_memory_items|DELETE\s+FROM\s+agent_memory_items/i.test(helper)],
 ['Cloud memory projection uses canonical identity and revisions',
  /homeserver_federated_v240_canonical_id\('vp3_cloud','memory'/.test(helper) &&
  /homeserver_agent_brain_memory_v245_revision/.test(helper)],
 ['HomeServer Agent Brain is read through memory.list',
  /'tool_key'=>'memory\.list'/.test(helper) && /homeserver_execution_v230_execute/.test(helper)],
 ['HomeServer memory identity and SHA-256 revision are verified',
  /agent_memory:/.test(helper) && /\^\[0-9a-f\]\{64\}\$/.test(helper) &&
  /homeserver_federated_v240_canonical_id\('homeserver','memory'/.test(helper)],
 ['HomeServer memory is mirror-only in Cloud',
  /'mirror_only'=>true/.test(helper) && /'remote_records_are_mirrors'=>true/.test(helper)],
 ['HomeServer memory content is not persisted by continuity metadata',
  /'homeserver_memory_content_persisted'=>false/.test(helper) &&
  !/memory_text.*homeserver_federated_records|content_text.*homeserver_federated_records/.test(helper)],
 ['governed bridge allowlists memory create update delete',
  governed.includes("'memory.write'") && governed.includes("'memory.update'") && governed.includes("'memory.delete'")],
 ['memory executions have a dedicated Cloud receipt domain',
  /str_starts_with\(\$tool,'memory\.'\)\)return 'memory'/.test(execution)],
 ['memory update/delete require canonical identity revision and mutation ID',
  /canonical_id/.test(helper) && /expected_revision/.test(helper) && /mutation_id/.test(helper)],
 ['Cloud cannot mutate HomeServer native IDs directly',
  /homeserver_agent_brain_memory_v245_known_homeserver/.test(helper) &&
  /no_cross_database_id_writes/.test(helper)],
 ['Section 6 API is account scoped and CSRF protected',
  /account\.access/.test(api) && /chat\.access/.test(api) && /hash_equals\(csrf_token\(\),\$csrf\)/.test(api)],
 ['main Agent Brain continues using shared HomeServer memory with explicit continuity label',
  /HomeServer Agent Brain memory · canonical continuity/.test(brain) && /homeserver_shared_v210_context_items/.test(brain)],
 ['Profile Agent HomeServer memory remains centrally policy gated',
  /homeserver_memory/.test(policies) && /'memory'=>'homeserver_memory'/.test(profile) && /user_data_policy_can_use_v236/.test(profile)],
 ['memory remains a first-class v2.4 federation/shared dataset',
  /['"]memory['"]/.test(federation) && /['"]memory['"]/.test(shared)],
 ['runtime journey executes Section 6 contract and runtime gates',
  /homeserver-v245-agent-brain-memory-continuity-contract\.mjs/.test(workflow) &&
  /homeserver-v245-agent-brain-memory-continuity-unit\.php/.test(workflow)],
];
for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`HomeServer v2.4 Section 6 Agent Brain & Memory continuity contract: ${checks.length}/${checks.length} passed`);
