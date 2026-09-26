import assert from 'node:assert/strict';
import fs from 'node:fs';

const read=(path)=>fs.readFileSync(path,'utf8');
const helper=read('includes/homeserver-tasks-calendar-v243.php');
const api=read('api/homeserver-tasks-calendar-v243.php');
const bootstrap=read('includes/bootstrap.php');
const federation=read('includes/homeserver-federated-data-v240.php');
const shared=read('includes/homeserver-shared-agent-v210.php');
const calendar=read('includes/user-calendar-v1300.php');
const governed=read('includes/homeserver-governed-actions-v233.php');
const execution=read('includes/homeserver-local-execution-v230.php');
const policies=read('includes/user-agent-system-v236.php');
const profile=read('includes/profile-agent.php');
const workflow=read('.github/workflows/homeserver-runtime-journey.yml');

const checks=[
 ['Section 4 helper is loaded by the canonical bootstrap',bootstrap.includes('homeserver-tasks-calendar-v243.php')],
 ['federation registry includes calendar',/['"]calendar['"]/.test(federation)],
 ['Cloud task authority stays in Agent Brain task and commitment records',/agent_memory_items/.test(helper)&&/memory_type IN \('task','commitment'\)/.test(helper)&&/agent_memory_task:/.test(helper)],
 ['HomeServer tasks are retrieved through canonical tool execution',/tasks\.list/.test(helper)&&/homeserver_task_calendar_v243_homeserver_tasks/.test(helper)],
 ['HomeServer calendar is retrieved through canonical tool execution',/calendar\.list/.test(helper)&&/homeserver_task_calendar_v243_homeserver_calendar_items/.test(helper)],
 ['Cloud and HomeServer calendar items preserve separate authority',/homeserver_federated_v240_envelope\('vp3_cloud','calendar'/.test(helper)&&/'authority_source'=>'homeserver','dataset'=>'calendar'/.test(helper)],
 ['HomeServer mutations use the governed action path only',/homeserver_governed_v233_request/.test(helper)&&!/INSERT INTO local_calendar_events/.test(helper)&&!/UPDATE local_calendar_events/.test(helper)],
 ['governed catalog allowlists task and calendar mutations',['tasks.create','tasks.update','tasks.delete','calendar.create','calendar.update','calendar.delete'].every(x=>governed.includes("'"+x+"'"))],
 ['execution router classifies task and calendar tools explicitly',/str_starts_with\(\$tool,'tasks\.'\)/.test(execution)&&/str_starts_with\(\$tool,'calendar\.'\)/.test(execution)],
 ['shared Agent fabric carries calendar as a first-class dataset',/['"]calendar['"]/.test(shared)&&/homeserver_task_calendar_v243_cloud_tasks/.test(shared)],
 ['User Calendar projects HomeServer events without copying them',/homeserver_task_calendar_v243_homeserver_calendar_items/.test(calendar)&&/homeserver_managed/.test(helper)],
 ['Profile Agent calendar access remains behind central data policy',/homeserver_calendar/.test(policies)&&/'calendar'=>'homeserver_calendar'/.test(profile)],
 ['API requires account access and CSRF for mutations',/account\.access/.test(api)&&/hash_equals\(csrf_token\(\)/.test(api)],
 ['API exposes unified task and calendar reads',/unified_tasks/.test(api)&&/unified_calendar/.test(api)],
 ['runtime journey executes both Section 4 gates',/homeserver-v243-task-calendar-continuity-contract\.mjs/.test(workflow)&&/homeserver-v243-task-calendar-continuity-unit\.php/.test(workflow)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`HomeServer v2.4 Section 4 Tasks & Calendar continuity contract: ${checks.length}/${checks.length} passed`);
