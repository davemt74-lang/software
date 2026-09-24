import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const vp3=read('includes/homeserver-vp3.php');
const shared=read('includes/homeserver-shared-agent-v210.php');
const continuity=read('includes/homeserver-work-continuity-v230.php');
const worker=read('includes/agent-worker-runtime-v1910.php');
const workerLoop=read('agent-worker-v1910.php');
const bootstrap=read('includes/bootstrap.php');
const setup=read('setup.php');
const upgrade=read('upgrade.php');
const api=read('api/homeserver-work-continuity-v230.php');
const workflows=read('agent-workflows.php');
const brain=read('includes/agent-brain-context-v142.php');
const profile=read('api/profile-agent.php');
const cognitive=read('includes/cognitive-presentation-v510.php');
const cognitiveClient=read('chat-cognitive-presentation-v510.js');

const checks=[
 ['shared Cloud/HomeServer product release is 2.3',
  /VP3_HOMESERVER_RELEASE_VERSION = '2\.3'/.test(vp3)&&/VP3_HOMESERVER_SHARED_AGENT_VERSION='2\.3'/.test(shared)],
 ['v2.3 continuity binds to the existing Phase 19 durable Job Engine',
  /require_once __DIR__\.\/['"]agent-job-engine-v1900\.php['"]/.test(continuity)&&
  /agent_job_retry_v1900/.test(continuity)&&/agent_job_cancel_v1900/.test(continuity)&&/agent_workflow_runs/.test(continuity)],
 ['continuity schema attaches one row to one canonical workflow run',
  /CREATE TABLE IF NOT EXISTS homeserver_work_continuity/.test(continuity)&&
  /run_id BIGINT UNSIGNED NOT NULL PRIMARY KEY/.test(continuity)&&
  /FOREIGN KEY \(run_id\) REFERENCES agent_workflow_runs\(id\) ON DELETE CASCADE/.test(continuity)],
 ['stable HomeServer idempotency key is scoped to owner run and action',
  /hash\('sha256','vp3-v230\|'\.\$uid\.\|'\.\$runId\.\|'\.\$actionId\)/.test(continuity)],
 ['fresh setup and upgrade install the v2.3 continuity schema',
  /homeserver_work_v230_ensure_schema\(\$pdo\)/.test(setup)&&
  /homeserver_work_v230_schema_ready\(\)/.test(upgrade)&&
  /homeserver_work_v230_ensure_schema\(\$pdo\)/.test(upgrade)],
 ['bootstrap loads v2.3 continuity after v2.2 execution routing',
  bootstrap.indexOf('homeserver-execution-routing-v220.php')<bootstrap.indexOf('homeserver-work-continuity-v230.php')],
 ['worker injects stable continuity metadata into the existing HomeServer dispatch',
  /homeserver_work_v230_before_homeserver_dispatch/.test(worker)&&
  /\$payload\['_continuity'\]=\$continuity/.test(worker)&&
  /homeserver_work_v230_after_homeserver_dispatch/.test(worker)],
 ['HomeServer running receipt is treated as retryable pending rather than duplicate completion',
  /homeserver_continuity_pending/.test(worker)&&/continuity_status/.test(worker)&&/continuity_replayed/.test(worker)],
 ['worker loop reconciles continuity before and after execution',
  workerLoop.split('homeserver_work_v230_reconcile_owner').length>=3],
 ['offline HomeServer becomes waiting and reconnect becomes resuming',
  /waiting_homeserver/.test(continuity)&&/resuming/.test(continuity)&&/homeserver_work_v230_connection_ready/.test(continuity)],
 ['routine retry scheduling does not generate waiting/resume chat spam',
  /retry_scheduled/.test(continuity)&&
  /in_array\(\$state,\['waiting_homeserver','resuming','waiting_local_approval','completed','failed','cancelled'\],true\)/.test(continuity)],
 ['durable lifecycle messages are attached to Agent Chat and Agent Brain',
  /homeserver_work_v230_append_chat/.test(continuity)&&/agent_brain_archive_and_parse/.test(continuity)&&
  /cross-runtime-work/.test(brain)&&/Durable Cloud \+ HomeServer work continuity/.test(brain)],
 ['meaningful lifecycle transitions create workflow notifications for current presentation and voice',
  /workflow_update/.test(continuity)&&/workflow_needs_attention/.test(continuity)&&
  /vp3_cognitive_presentation_work_update_v230/.test(cognitive)&&
  /renderWorkContinuityUpdate/.test(cognitiveClient)&&
  /\(\?:meeting\|appointment\|booking\|calendar\|profile\|order\|message\|approval\|workflow\|failed\|failure\)/.test(cognitive)],
 ['signed-in v2.3 API exposes enqueue resume retry cancel and runtime routing',
  /homeserver_work_v230_enqueue/.test(api)&&/homeserver_work_v230_resume/.test(api)&&
  /homeserver_work_v230_retry/.test(api)&&/homeserver_work_v230_cancel/.test(api)&&
  /run_cloud/.test(api)&&/run_homeserver/.test(api)],
 ['Agent Workflows UI controls the same durable run rather than creating copies',
  /Cloud \+ HomeServer Work Continuity/.test(workflows)&&/continuity_resume/.test(workflows)&&
  /continuity_retry/.test(workflows)&&/continuity_cancel/.test(workflows)&&
  /continuity_cloud/.test(workflows)&&/continuity_homeserver/.test(workflows)],
 ['Profile Agent durable work handoff is owner-only',
  /enqueue_durable_work/.test(profile)&&/\$ownerActions=.*enqueue_durable_work/.test(profile)&&
  /vp3_profile_agent_owner_conversation_v390/.test(profile)&&/source_surface'=>'profile_agent/.test(profile)],
 ['public Profile Agent visitor message path does not enqueue durable work',
  !/if\(\$action==='message'\)[\s\S]{0,2500}homeserver_work_v230_enqueue/.test(profile)],
 ['Cloud fallback remains explicit and policy-bound',
  /fallback_allowed/.test(continuity)&&/This job does not permit Cloud fallback/.test(continuity)&&
  /Wait for the active leased action to finish or expire before changing runtimes/.test(continuity)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`VP3 Cloud / HomeServer v2.3 work continuity: ${checks.length}/${checks.length} passed`);
