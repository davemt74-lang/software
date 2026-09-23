import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const replan=read('includes/cognitive-replanning-v2530.php');
const release=read('includes/cognitive-release-v2530.php');
const resource=read('includes/cognitive-resource-budget-v2520.php');
const optimization=read('includes/cognitive-optimization-v2510.php');
const forecast=read('includes/cognitive-forecast-v2490.php');
const portfolio=read('includes/cognitive-portfolio-v2480.php');
const jobs=read('includes/agent-job-engine-v1900.php');
const workers=read('includes/agent-worker-runtime-v1910.php');
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
const docs=read('docs/VP3_COGNITIVE_REPLANNING_V2530.md');

const checks=[
 ['v25.30 runtime loads after v25.20 and release gate is loaded',
   bootstrap.indexOf("cognitive-resource-budget-v2520.php")<bootstrap.indexOf("cognitive-replanning-v2530.php")
   &&bootstrap.includes("cognitive-release-v2530.php")],
 ['replanning creates no schema or durable replan store',
   !(replan.match(/CREATE TABLE|ALTER TABLE|INSERT INTO|UPDATE\s+[a-z_]+\s+SET|DELETE FROM/ig)||[]).length],
 ['replanning detects deadline capacity dependency approval and overlap drift',
   /deadline_threat/.test(replan)&&/capacity_unavailable/.test(replan)
   &&/dependency_blocked/.test(replan)&&/approval_or_user_gate/.test(replan)
   &&/semantic_overlap/.test(replan)],
 ['replanning exposes deterministic baseline-to-recovery rank deltas',
   /baseline_rank/.test(replan)&&/replan_rank/.test(replan)&&/rank_delta/.test(replan)
   &&/recovery_score/.test(replan)&&/usort\(\$items/.test(replan)],
 ['only autonomous order may change',
   /if\(\$aMode!==\'autonomous\'\|\|\$bMode!==\'autonomous\'\)/.test(replan)
   &&/authority_passthrough/.test(replan)],
 ['capacity loss escalates without executor switching',
   /escalate_executor_unavailable/.test(replan)
   &&/'executor_mutation_authority'=>false/.test(replan)
   &&/'capacity_loss_escalates_without_executor_switch'=>true/.test(release)],
 ['approval gates escalate without bypass',
   /request_user_or_approval/.test(replan)
   &&/'approval_authority'=>false/.test(replan)
   &&/'user_or_approval_gates_escalate_without_bypass'=>true/.test(release)],
 ['v24.80 applies replan overlay before v25.20 resource plan',
   portfolio.indexOf('vp3_cognitive_replanning_overlay_v2530')>=0
   &&portfolio.indexOf('vp3_cognitive_replanning_overlay_v2530')<portfolio.indexOf('vp3_cognitive_resource_budget_plan_v2520')],
 ['v24.80 claim candidates honor replan rank while retaining admission authority',
   /replan_rank/.test(portfolio)
   &&/function vp3_cognitive_portfolio_claim_admission_v2480/.test(portfolio)
   &&/'v2480_remains_admission_authority'=>true/.test(release)],
 ['replan failure preserves existing v24.80 path',
   /vp3_cognitive_replanning_overlay_v2530/.test(portfolio)
   &&/catch\(Throwable \$e\)\{\}/.test(portfolio)
   &&/'replan_failure_preserves_v2480_behavior'=>true/.test(release)],
 ['v25.20 remains resource budget authority',
   /function vp3_cognitive_resource_budget_plan_v2520/.test(resource)
   &&/'v2520_remains_resource_budget_authority'=>true/.test(release)],
 ['v25.10 remains optimization authority',
   /function vp3_cognitive_optimization_compare_v2510/.test(optimization)
   &&/'v2510_remains_optimization_authority'=>true/.test(release)],
 ['v24.90 remains forecast authority',
   /function vp3_cognitive_forecast_snapshot_v2490/.test(forecast)
   &&/'v2490_remains_forecast_authority'=>true/.test(release)],
 ['Phase 19 remains actual claim lease execution authority',
   /function agent_job_claim_run_v1900/.test(jobs)&&/lease_token/.test(jobs)
   &&/function agent_worker_runtime_poll_v1910/.test(workers)
   &&/'phase19_remains_execution_authority'=>true/.test(release)],
 ['replanning has no direct job claim worker poll or lease creation',
   !/agent_job_claim_(?:run|next)_v1900\(/.test(replan)
   &&!/agent_worker_runtime_poll_v1910\(/.test(replan)
   &&!/random_bytes\(/.test(replan)],
 ['Working Context contains one bounded replanning projection',
   /'replanning'=>1/.test(context)
   &&/vp3_cognitive_replanning_context_item_v2530/.test(context)
   &&/cognitive_replanning_v2530_projection/.test(context)],
 ['replanning context carries no instruction authority',
   /instruction_authority'=>false/.test(replan)&&/ephemeral_projection'=>true/.test(replan)],
 ['Agent Brief uses shared replanning projection',
   /vp3_cognitive_replanning_activity_projection_v2530/.test(presentation)
   &&/replanning_focus/.test(presentation)
   &&/Portfolio replanning/.test(briefJs)],
 ['Proactive Now uses shared replanning projection',
   /vp3_cognitive_replanning_activity_projection_v2530/.test(proactive)
   &&/'replanning'=>'cognitive_replanning_v2530'/.test(proactive)],
 ['Agent Brain API uses shared replanning projection',
   /vp3_cognitive_replanning_activity_projection_v2530/.test(brainApi)
   &&/'replanning'=>\$replanning/.test(brainApi)],
 ['Agent Brain renders Portfolio Replanning and exact rank deltas',
   /<strong>Portfolio Replanning<\/strong>/.test(brainJs)
   &&/replanChanges/.test(brainJs)&&/from_rank/.test(brainJs)&&/to_rank/.test(brainJs)],
 ['History remains canonical conversation archive',
   /<strong>Agent History<\/strong>/.test(brainJs)
   &&/Authorized conversation archive/.test(brainJs)],
 ['chat cache bust exposes v25.30 replanning build',
   /cognitive-replanning-v2530-20260922/.test(chat)&&/replanningBuild/.test(chat)],
 ['CI runs v25.30 node and PHP gates',
   /cognitive-replanning-v2530\.mjs/.test(workflow)&&/cognitive-replanning-v2530\.php/.test(workflow)],
 ['Recovery Baseline retains v25.30 gates',
   /cognitive-replanning-v2530\.mjs/.test(recovery)&&/cognitive-replanning-v2530\.php/.test(recovery)],
 ['production package retains v25.30 runtime and release files',
   /cognitive-replanning-v2530\.php/.test(packageWorkflow)
   &&/cognitive-release-v2530\.php/.test(packageWorkflow)],
 ['docs preserve authority and derived reservation cleanup',
   /v25\.30 Replan/.test(docs)&&/v25\.20 Resource Budget/.test(docs)
   &&/v24\.80 Admission/.test(docs)&&/Phase 19 Claim\/Lease\/Execute\/Receipt/.test(docs)
   &&/old reservation is naturally absent/.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Replanning v25.30 gate: ${checks.length}/${checks.length} passed`);
