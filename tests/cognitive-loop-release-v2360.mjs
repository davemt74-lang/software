import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const runtime=read('includes/cognitive-runtime-v500.php');
const feed=read('includes/cognitive-feed-v530.php');
const learning=read('includes/cognitive-learning-v540.php');
const planning=read('includes/cognitive-planning-v550.php');
const orchestration=read('includes/cognitive-orchestration-v560.php');
const memory=read('includes/cognitive-memory-v570.php');
const ops=read('includes/cognitive-operations-v2300.php');
const queue=read('includes/cognitive-priority-queue-v2310.php');
const opp=read('includes/cognitive-opportunities-v2320.php');
const action=read('includes/cognitive-action-planning-v2330.php');
const proactive=read('includes/cognitive-proactive-now-v2340.php');
const calibration=read('includes/cognitive-calibration-v2350.php');
const release=read('includes/cognitive-release-v2360.php');
const browser=read('api/extension-cognitive-now-v2120.php');
const browserVoice=read('includes/extension-notifications-v2140.php');
const presentation=read('includes/cognitive-presentation-v510.php');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const spec=read('docs/VP3_COGNITIVE_LOOP_RELEASE_V2360.md');

const phaseFiles=[
  ops,queue,opp,action,proactive,calibration,release
];

const checks=[
 ['release manifest is read only', /diagnostic_only/.test(release) && !/\b(?:INSERT|UPDATE|DELETE|CREATE TABLE|ALTER TABLE)\b/i.test(release)],
 ['release manifest freezes v23.00-v23.50', /feature_range'=>'v23\.00-v23\.50'/.test(release) && /release_phase'=>'v23\.60'/.test(release)],
 ['all v23 layers remain schema-free', phaseFiles.every(s=>!/CREATE TABLE|ALTER TABLE/i.test(s))],
 ['canonical runtime loaded before v23 layers', bootstrap.indexOf('cognitive-runtime-v500.php')<bootstrap.indexOf('cognitive-operations-v2300.php')],
 ['learning precedes planning and orchestration', bootstrap.indexOf('cognitive-learning-v540.php')<bootstrap.indexOf('cognitive-planning-v550.php') && bootstrap.indexOf('cognitive-planning-v550.php')<bootstrap.indexOf('cognitive-orchestration-v560.php')],
 ['v23 release manifest loaded before feed presentation', bootstrap.indexOf('cognitive-release-v2360.php')<bootstrap.indexOf('cognitive-feed-v530.php') && bootstrap.indexOf('cognitive-feed-v530.php')<bootstrap.indexOf('cognitive-presentation-v510.php')],
 ['opportunity detection feeds canonical observations', /vp3_cognitive_observation_store_v500/.test(opp) && /vp3_cognitive_opportunity_sync_v2320/.test(feed)],
 ['planning sync consumes authorized cognitive candidates', /vp3_cognitive_planning_sync_v550/.test(feed) && /vp3_cognitive_authorize_ref_v500/.test(planning)],
 ['orchestration sync follows learning reconciliation', feed.indexOf('vp3_cognitive_learning_reconcile_v540')<feed.indexOf('vp3_cognitive_orchestration_sync_v560')],
 ['feed learns before selected queue projection', feed.indexOf('vp3_cognitive_learning_adjust_candidate_v540')<feed.indexOf('vp3_cognitive_priority_queue_compose_v2310')],
 ['priority queue derives selected feed items', /selected_feed_projection/.test(queue) && /selectedForQueue/.test(feed)],
 ['action planning remains contract not executor', /model_may_execute'=>false/.test(action) && /automatic_external_writes'=>false/.test(action)],
 ['plan acceptance is not execution', /plan_accepted/.test(calibration) && /plan_accepted/.test(learning) && !/plan_accepted'=>'actions_taken'/.test(learning)],
 ['handoff requested is action but not completion', /'handoff_requested'=>'actions_taken'/.test(learning) && /handoff_requested/.test(orchestration) && /handoff is not completion/i.test(spec)],
 ['canonical outcome closes executable run', /in_array\(\$code,\['successful','resolved'\],true\)/.test(orchestration) && /canonical_outcome/.test(orchestration)],
 ['unsuccessful outcome replans', /\['unsuccessful','ignored'\]/.test(orchestration) && /replan_required/.test(orchestration)],
 ['calibration cannot reorder queue', /'queue_reordering'=>false/.test(calibration)],
 ['calibration only preserves or reduces preview', /\$mode==='conservative'\?1:3/.test(calibration) && /MAX_FOCUS_V2340=3/.test(proactive)],
 ['attention and next-up learning remain deterministic', /\['priorities','opportunities','recent'\]/.test(learning) && /deterministic_attention_unchanged'=>true/.test(calibration)],
 ['Browser uses same feed and proactive brief', /vp3_cognitive_feed_compose_v530/.test(browser) && /'proactive_brief'/.test(browser)],
 ['Browser plan semantics match Agent Chat', /plan_accepted/.test(browser) && /plan_dismissed/.test(browser)],
 ['Browser cannot invoke cognitive orchestration handoff', !/vp3_cognitive_orchestration_handoff_v560/.test(browser)],
 ['Browser tool actions remain review handoffs', /'type'=>'agent_review'/.test(browser)],
 ['voice uses one canonical presentation candidate', /vp3_cognitive_presentation_voice_candidate_v510/.test(browserVoice) && /last_voice_notification_id/.test(presentation)],
 ['Browser voice reuses persisted digest', /vp3_cognitive_presentation_open_digest_v510/.test(browserVoice)],
 ['pure opportunities cannot standalone speak', /standalone_opportunity_voice'=>false/.test(proactive)],
 ['v5 schema chain remains upgrade authority', ['v500','v510','v530','v540','v550','v560','v570'].every(v=>upgrade.includes('schema_ready_'+v))],
 ['production package includes release-critical runtime checks', /cognitive-release-v2360\.php/.test(packageWorkflow) && /extension-cognitive-now-v2120\.php/.test(packageWorkflow) && /extension-notifications-v2140\.php/.test(packageWorkflow)],
 ['production package still excludes dev-only trees', /--exclude='tests\/'/.test(packageWorkflow) && /--exclude='tools\/'/.test(packageWorkflow) && /--exclude='\.github\/'/.test(packageWorkflow)],
 ['release spec defines exact-tree gate', /zero file differences/.test(spec)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Loop Release v23.60 gate: ${checks.length}/${checks.length} passed`);
