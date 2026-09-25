import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const v018=read('includes/homeserver-agent-v018.php');
const v025=read('includes/homeserver-agent-v025.php');
const routing=read('includes/agent-runtime-routing-v420.php');
const stream=read('api/chat-stream-v121.php');
const workflow=read('.github/workflows/homeserver-runtime-journey.yml');

const checks=[
 ['v0.18 defines one canonical HTTPS-aware Agent compute executor',
  /function homeserver_agent_v018_execute/.test(v018)&&/transport.*vp3_https/.test(v018)&&/homeserver_execution_v230_execute\(\$userId,'agent\.chat'/.test(v018)],
 ['custom relay compatibility stays on the user-aware remote helper',
  /homeserver_vp3_remote_operation_for_user\(/.test(v018)],
 ['v0.18 chat uses the canonical executor and returns its receipt',
  /homeserver_agent_v018_execute\(\$userId,\$credentials,\$payload\)/.test(v018)&&/'execution_receipt'=>\$executionReceipt/.test(v018)],
 ['stateless v0.25 delegation uses the same canonical executor',
  /homeserver_agent_v018_execute\(\$userId,\$credentials,\$payload\)/.test(v025)],
 ['v0.25 no longer calls legacy relay directly with the credential relay field',
  !/homeserver_vp3_remote_operation\(\$credentials\['relay'\]/.test(v025)],
 ['v0.25 returns the canonical v2.3 execution receipt',
  /'execution_receipt'=>\$executionReceipt/.test(v025)],
 ['Agent runtime preserves compute attribution and attaches sanitized HomeServer execution provenance',
  /homeserver_execution/.test(routing)&&/request_id/.test(routing)&&/domain/.test(routing)&&/operation/.test(routing)&&/duration_ms/.test(routing)],
 ['existing automatic Cloud fallback remains after a failed HomeServer attempt',
  /chat_execution_v019_fallback\(\$user,!empty\(\$runtimePlan\['home'\]\['paired'\]\),true\)/.test(stream)&&/allow_vp3_fallback/.test(stream)],
 ['HomeServer-only preference remains fail closed rather than falling back',
  /effective_preference.*homeserver_only/.test(stream)&&/vp3_agent_runtime_block_message_v420/.test(stream)],
 ['runtime journey gates Section 3 contract and unit tests',
  /homeserver-v230-agent-compute-contract\.mjs/.test(workflow)&&/homeserver-v230-agent-compute-unit\.php/.test(workflow)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`HomeServer v2.3 Section 3 Agent compute: ${checks.length}/${checks.length} passed`);
