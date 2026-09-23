import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const resource=read('includes/cognitive-resource-budget-v2520.php');
const release=read('includes/cognitive-release-v2520.php');
const portfolio=read('includes/cognitive-portfolio-v2480.php');
const optimization=read('includes/cognitive-optimization-v2510.php');
const forecast=read('includes/cognitive-forecast-v2490.php');
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
const docs=read('docs/VP3_COGNITIVE_RESOURCE_BUDGET_V2520.md');

const checks=[
 ['v25.20 runtime loads after v25.10 and release gate is loaded',
   bootstrap.indexOf("cognitive-optimization-v2510.php")<bootstrap.indexOf("cognitive-resource-budget-v2520.php")
   &&bootstrap.includes("cognitive-release-v2520.php")],
 ['resource budget creates no schema or durable reservation store',
   !(resource.match(/CREATE TABLE|ALTER TABLE|INSERT INTO|UPDATE\s+[a-z_]+\s+SET|DELETE FROM/ig)||[]).length],
 ['resource planning is bounded by horizon lead and reservation caps',
   /VP3_COGNITIVE_RESOURCE_HORIZON_SECONDS_V2520=259200/.test(resource)
   &&/VP3_COGNITIVE_RESOURCE_MIN_LEAD_SECONDS_V2520=1800/.test(resource)
   &&/VP3_COGNITIVE_RESOURCE_MAX_LEAD_SECONDS_V2520=28800/.test(resource)
   &&/VP3_COGNITIVE_RESOURCE_MAX_RESERVED_SLOTS_V2520=2/.test(resource)],
 ['only autonomous goals are eligible for reservations',
   /execution_mode'.*autonomous/.test(resource)&&/return null/.test(resource)],
 ['conditional approval or blocked reservations do not become active holds',
   /reservationState='conditional'/.test(resource)
   &&/active_reserved_goal_ids/.test(resource)],
 ['active reservations can hold unrelated autonomous admission capacity',
   /held_reserved_slots/.test(resource)
   &&/remaining_unreserved_free/.test(resource)
   &&/vp3_cognitive_resource_claim_budget_v2520/.test(resource)],
 ['v24.80 consumes the resource budget before autonomous claim admission',
   /vp3_cognitive_resource_budget_plan_v2520/.test(portfolio)
   &&/vp3_cognitive_resource_claim_budget_v2520/.test(portfolio)
   &&/resource_budget/.test(portfolio)],
 ['reserved needs-objective work may materialize using protected budget',
   /admit_reserved_milestone/.test(portfolio)
   &&/active_reserved_goal_ids/.test(portfolio)],
 ['resource budget failure preserves existing v24.80 admission path',
   /catch\(Throwable \$e\)\{\}/.test(portfolio)
   &&/foreach\(array_slice\(\$candidates,0,\$free\)/.test(portfolio)],
 ['v25.10 optimization authority remains present',
   /function vp3_cognitive_optimization_compare_v2510/.test(optimization)
   &&/'v2510_remains_optimization_authority'=>true/.test(release)],
 ['v24.90 forecast authority remains present',
   /function vp3_cognitive_forecast_snapshot_v2490/.test(forecast)
   &&/'v2490_remains_forecast_authority'=>true/.test(release)],
 ['v24.80 remains admission authority',
   /function vp3_cognitive_portfolio_claim_admission_v2480/.test(portfolio)
   &&/'v2480_remains_admission_authority'=>true/.test(release)],
 ['Phase 19 still creates actual lease tokens and claims',
   /function agent_job_claim_run_v1900/.test(jobs)
   &&/lease_token/.test(jobs)
   &&/function agent_worker_runtime_poll_v1910/.test(workers)
   &&/'phase19_remains_execution_authority'=>true/.test(release)],
 ['resource budget has no direct job claim or worker poll call',
   !/agent_job_claim_(?:run|next)_v1900\(/.test(resource)
   &&!/agent_worker_runtime_poll_v1910\(/.test(resource)],
 ['Working Context contains one bounded resource budget section',
   /'resource_budget'=>1/.test(context)
   &&/vp3_cognitive_resource_context_item_v2520/.test(context)
   &&/cognitive_resource_budget_v2520_projection/.test(context)],
 ['resource context has no instruction authority',
   /instruction_authority'=>false/.test(resource)
   &&/ephemeral_projection'=>true/.test(resource)],
 ['Agent Brief uses shared resource projection',
   /vp3_cognitive_resource_activity_projection_v2520/.test(presentation)
   &&/resource_budget_focus/.test(presentation)
   &&/Capacity reservation/.test(briefJs)],
 ['Proactive Now uses shared resource projection',
   /vp3_cognitive_resource_activity_projection_v2520/.test(proactive)
   &&/'resource_budget'=>'cognitive_resource_budget_v2520'/.test(proactive)],
 ['Agent Brain API uses shared resource projection',
   /vp3_cognitive_resource_activity_projection_v2520/.test(brainApi)
   &&/'resource_budget'=>\$resourceBudget/.test(brainApi)],
 ['Agent Brain renders Capacity Reservations',
   /<strong>Capacity Reservations<\/strong>/.test(brainJs)
   &&/resourceReservations/.test(brainJs)
   &&/admission only/.test(brainJs)],
 ['History remains canonical conversation archive',
   /<strong>Agent History<\/strong>/.test(brainJs)
   &&/Authorized conversation archive/.test(brainJs)],
 ['chat cache bust exposes v25.20 resource build',
   /cognitive-resource-budget-v2520-20260922/.test(chat)
   &&/resourceBudgetBuild/.test(chat)],
 ['CI runs v25.20 node and PHP gates',
   /cognitive-resource-budget-v2520\.mjs/.test(workflow)
   &&/cognitive-resource-budget-v2520\.php/.test(workflow)],
 ['Recovery Baseline retains v25.20 gates',
   /cognitive-resource-budget-v2520\.mjs/.test(recovery)
   &&/cognitive-resource-budget-v2520\.php/.test(recovery)],
 ['production package retains v25.20 runtime and release files',
   /cognitive-resource-budget-v2520\.php/.test(packageWorkflow)
   &&/cognitive-release-v2520\.php/.test(packageWorkflow)],
 ['docs preserve authority chain and explain reservations are not leases',
   /v25\.20 Resource Budget/.test(docs)
   &&/v25\.10 Optimize/.test(docs)
   &&/v24\.80 Admission/.test(docs)
   &&/Phase 19 Claim\/Lease\/Execute\/Receipt/.test(docs)
   &&/not persisted as worker locks/.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Resource Budget v25.20 gate: ${checks.length}/${checks.length} passed`);
