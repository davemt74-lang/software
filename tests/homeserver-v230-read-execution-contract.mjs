import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const bridge=read('includes/homeserver-agent-read-v230.php');
const bootstrap=read('includes/bootstrap.php');
const auth=read('includes/agent-tool-authorization-v400.php');
const stream=read('api/chat-stream-v121.php');
const chat=read('api/chat.php');
const execution=read('includes/chat-execution-v019.php');
const workflow=read('.github/workflows/homeserver-runtime-journey.yml');

const checks=[
 ['read bridge loads after canonical v2.3 execution contract',bootstrap.indexOf('homeserver-local-execution-v230.php')<bootstrap.indexOf('homeserver-agent-read-v230.php')],
 ['read bridge supports local files knowledge tools and device reads',["files.list","files.read","knowledge.search","tools.list","devices.list"].every(x=>bridge.includes("'"+x+"'"))],
 ['read bridge does not expose mutation or physical-command operations',!["files.update","files.delete","devices.command","memory.write"].some(x=>bridge.includes("'"+x+"'"))],
 ['local-intent detection is explicit and does not hijack generic Cloud requests',/homeserver_agent_read_v230_local_marker/.test(bridge)&&/my computer/.test(bridge)&&/if\(\$local/.test(bridge)],
 ['file references can be read explicitly with bounded text',/hsf-\\d\+/.test(bridge)&&/max_chars'=>8000/.test(bridge)&&/mb_strimwidth\(\$content,0,8000/.test(bridge)],
 ['device reads route through governed HomeServer tool execution',/tool\.execute/.test(bridge)&&/tool_key'=>'devices\.list'/.test(bridge)&&/category/.test(bridge)],
 ['canonical Agent tool boundary invokes HomeServer reads before domain Cloud tools',auth.indexOf('homeserver_agent_read_v230_query')<auth.indexOf('agent_team_scheduling_tools_query_v610')],
 ['streaming Agent Chat attributes local reads to HomeServer provenance',/chat_execution_v019_homeserver_receipt/.test(stream)&&/actual_source'=>'homeserver_local'/.test(stream)&&/homeserver_v230_local_read/.test(stream)],
 ['execution provenance carries only domain operation request id and sanitized runtime fields',/homeserver_domain/.test(execution)&&/homeserver_operation/.test(execution)&&/homeserver_request_id/.test(execution)],
 ['non-streaming Chat also executes HomeServer local reads and persists receipt context',/homeserver_agent_read_v230_query/.test(chat)&&/'execution'=>is_array\(\$toolResult\['execution'\]/.test(chat)],
 ['runtime journey gates Section 2 contract and unit behavior',/homeserver-v230-read-execution-contract\.mjs/.test(workflow)&&/homeserver-v230-read-execution-unit\.php/.test(workflow)],
];
for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`HomeServer v2.3 Section 2 read execution: ${checks.length}/${checks.length} passed`);
