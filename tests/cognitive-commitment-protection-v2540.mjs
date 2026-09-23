import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const commitment=read('includes/cognitive-commitment-protection-v2540.php');
const release=read('includes/cognitive-release-v2540.php');
const goalCommitments=read('includes/agent-goal-commitments-v1715.php');
const meetingCommand=read('includes/video-meetings-commitment-command-v18230.php');
const meetingVerification=read('includes/video-meetings-followthrough-verification-v18200-part2.php');
const memoryLifecycle=read('includes/agent-memory-lifecycle-v123.php');
const portfolio=read('includes/cognitive-portfolio-v2480.php');
const replanning=read('includes/cognitive-replanning-v2530.php');
const resource=read('includes/cognitive-resource-budget-v2520.php');
const jobs=read('includes/agent-job-engine-v1900.php');
const bootstrap=read('includes/bootstrap.php');
const context=read('includes/cognitive-context-v2420.php');
const presentation=read('includes/cognitive-presentation-v510.php');
const proactive=read('includes/cognitive-proactive-now-v2340.php');
const brainApi=read('api/chat-notifications-brain-v240.php');
const briefJs=read('chat-cognitive-presentation-v510.js');
const brainJs=read('chat-notifications-drawer-v240.js');
const chat=read('chat.php');
const workflow=read('.github/workflows/cognitive-loop-release-v2360.yml');
const recovery=read('tools/run_recovery_baseline.py');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const docs=read('docs/VP3_COGNITIVE_COMMITMENT_PROTECTION_V2540.md');

const checks=[
 ['v25.40 loads after v25.30 and has a release gate',
   bootstrap.indexOf("cognitive-replanning-v2530.php")<bootstrap.indexOf("cognitive-commitment-protection-v2540.php")
   &&bootstrap.includes("cognitive-release-v2540.php")],
 ['v25.40 creates no commitment schema or durable ledger',
   !(commitment.match(/CREATE TABLE|ALTER TABLE|DROP TABLE|INSERT INTO|UPDATE\s+[a-z_]+\s+SET|DELETE FROM/ig)||[]).length
   &&/'second_commitment_store'=>false/.test(release)],
 ['goal commitments reuse Phase 17.15 authority',
   /agent_goal_commitment_state_v1715/.test(commitment)
   &&/agent_goal_commitment_state_v1715/.test(goalCommitments)
   &&/'goals'=>'agent_goal_commitments_v1715_and_agent_goals'/.test(release)],
 ['meeting commitments reuse existing follow-up and verification lineage',
   /video_meeting_agenda_items/.test(commitment)
   &&/video_meeting_followthrough_plans/.test(commitment)
   &&/video_meeting_followthrough_closures/.test(commitment)
   &&/video_meeting_commitment_command_state_v18230/.test(meetingCommand)
   &&/explicit_organizer_closure/.test(meetingVerification)],
 ['Agent Brain tasks reuse v123 memory lifecycle',
   /agent_memory_v123_tasks/.test(commitment)
   &&/memory_type IN \('commitment','task'\)/.test(memoryLifecycle)
   &&/'memory_tasks'=>'agent_memory_items_task_lifecycle_v123'/.test(release)],
 ['commitments use stable source identities',
   /'goal:'\.\$goalId/.test(commitment)
   &&/'meeting:'\.\(int\)\$row\['agenda_item_id'\]/.test(commitment)
   &&/'memory:'\.\(string\)/.test(commitment)],
 ['source-aware commitment strength distinguishes explicit promises',
   /vp3_cognitive_commitment_strength_v2540/.test(commitment)
   &&/'meeting'=>1\.00/.test(commitment)
   &&/'goal'=>0\.82/.test(commitment)
   &&/'memory_commitment'=>0\.90/.test(commitment)],
 ['verified completion comes from canonical source closure',
   /\$derived==='achieved'/.test(commitment)
   &&/\$closure==='verified'/.test(commitment)
   &&/\$status==='completed'/.test(commitment)
   &&/'completion_is_not_inferred_from_execution'=>true/.test(release)],
 ['capacity conflict simulation is bounded and preserves executor',
   /vp3_cognitive_commitment_apply_capacity_conflicts_v2540/.test(commitment)
   &&/capacity_deadline_conflict/.test(commitment)
   &&/capacity_unavailable/.test(commitment)],
 ['portfolio applies commitments before v25.30 and v25.20',
   portfolio.indexOf('vp3_cognitive_commitment_apply_v2540')>=0
   &&portfolio.indexOf('vp3_cognitive_commitment_apply_v2540')<portfolio.indexOf('vp3_cognitive_replanning_overlay_v2530')
   &&portfolio.indexOf('vp3_cognitive_replanning_overlay_v2530')<portfolio.indexOf('vp3_cognitive_resource_budget_plan_v2520')],
 ['v25.30 recovery consumes commitment pressure',
   /commitment_protection_score/.test(replanning)
   &&/protected_commitment_at_risk/.test(replanning)
   &&/protect_commitment/.test(replanning)],
 ['v25.20 reservation scoring consumes commitment pressure',
   /commitment_protection_score/.test(resource)
   &&/\$commitment\*0\.12/.test(resource)],
 ['Phase 19 may reorder only its existing candidate window',
   /vp3_cognitive_commitment_rank_claims_v2540/.test(jobs)
   &&/\$rows=\$s->fetchAll\(\)\?:\[\]/.test(jobs)
   &&/foreach\(\$rows as \$r\)\{\$claim=agent_job_claim_run_v1900/.test(jobs)
   &&/'only_existing_claim_candidates_may_be_reordered'=>true/.test(release)],
 ['commitment ranking does not claim jobs or create leases',
   !/agent_job_claim_(?:run|next)_v1900\(/.test(commitment)
   &&!/lease_token\s*=|random_bytes\(/.test(commitment)
   &&/'phase19_remains_claim_lease_execution_receipt_authority'=>true/.test(release)],
 ['no deadline executor or approval mutation authority is introduced',
   /'deadline_mutation_authority'=>false/.test(commitment)
   &&/'executor_mutation_authority'=>false/.test(commitment)
   &&/'approval_authority'=>false/.test(commitment)],
 ['Working Context contains one bounded commitment projection',
   /'commitment_protection'=>1/.test(context)
   &&/vp3_cognitive_commitment_context_item_v2540/.test(context)
   &&/cognitive_commitment_protection_v2540_projection/.test(context)],
 ['commitment context has no instruction authority',
   /instruction_authority'=>false/.test(commitment)
   &&/ephemeral_projection'=>true/.test(commitment)],
 ['Agent Brief uses shared commitment projection',
   /vp3_cognitive_commitment_activity_projection_v2540/.test(presentation)
   &&/commitment_focus/.test(presentation)
   &&/Commitment protection/.test(briefJs)],
 ['Proactive Now uses shared commitment projection',
   /vp3_cognitive_commitment_activity_projection_v2540/.test(proactive)
   &&/'commitment_protection'=>'cognitive_commitment_protection_v2540'/.test(proactive)],
 ['Agent Brain API uses shared commitment projection',
   /vp3_cognitive_commitment_activity_projection_v2540/.test(brainApi)
   &&/'commitment_protection'=>\$commitmentProtection/.test(brainApi)],
 ['Agent Brain renders protected risk conflict and verified counts',
   /<strong>Commitment Protection<\/strong>/.test(brainJs)
   &&/protectedCommitments/.test(brainJs)
   &&/Verified complete/.test(brainJs)],
 ['Agent History remains canonical conversation archive',
   /<strong>Agent History<\/strong>/.test(brainJs)
   &&/Authorized conversation archive/.test(brainJs)],
 ['chat cache bust exposes v25.40 build',
   /cognitive-commitment-protection-v2540-20260922/.test(chat)
   &&/commitmentProtectionBuild/.test(chat)],
 ['CI runs v25.40 PHP and Node gates',
   /cognitive-commitment-protection-v2540\.php/.test(workflow)
   &&/cognitive-commitment-protection-v2540\.mjs/.test(workflow)],
 ['Recovery Baseline retains v25.40 gates',
   /cognitive-commitment-protection-v2540\.php/.test(recovery)
   &&/cognitive-commitment-protection-v2540\.mjs/.test(recovery)],
 ['production package retains v25.40 runtime release and canonical sources',
   /cognitive-commitment-protection-v2540\.php/.test(packageWorkflow)
   &&/cognitive-release-v2540\.php/.test(packageWorkflow)
   &&/agent-goal-commitments-v1715\.php/.test(packageWorkflow)
   &&/agent-memory-lifecycle-v123\.php/.test(packageWorkflow)
   &&/video-meetings-commitment-command-v18230\.php/.test(packageWorkflow)
   &&/video-meetings-followthrough-verification-v18200\.php/.test(packageWorkflow)],
 ['docs preserve canonical authorities and no-silent-change boundary',
   /Phase 17\.15/.test(docs)&&/Phase 18\.23/.test(docs)&&/Phase 1\.23/.test(docs)
   &&/No silent promise changes/.test(docs)
   &&/Execution is not treated as success/.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Commitment Protection v25.40 gate: ${checks.length}/${checks.length} passed`);
