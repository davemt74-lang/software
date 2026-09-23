import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const optimization=read('includes/cognitive-optimization-v2510.php');
const release=read('includes/cognitive-release-v2510.php');
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
const docs=read('docs/VP3_COGNITIVE_OPTIMIZATION_V2510.md');

const optimizationOrder=forecast.indexOf('vp3_cognitive_optimization_resequence_v2510');
const forecastReturn=forecast.indexOf('return $items;',optimizationOrder);

const checks=[
 ['v25.10 loads after v24.90 and its release gate loads',
   bootstrap.indexOf("cognitive-forecast-v2490.php")<bootstrap.indexOf("cognitive-optimization-v2510.php")
   &&bootstrap.includes("cognitive-release-v2510.php")],
 ['optimization creates no schema or durable optimizer store',
   !(optimization.match(/CREATE TABLE|ALTER TABLE|INSERT INTO|UPDATE\s+[a-z_]+\s+SET|DELETE FROM/ig)||[]).length],
 ['canonical strategy set contains four bounded strategies',
   /\['balanced','protect_deadlines','unlock_dependencies','maximize_throughput'\]/.test(optimization)],
 ['strategy comparison uses observable portfolio metrics',
   /deadline_risk_count/.test(optimization)&&/total_lateness_seconds/.test(optimization)
   &&/dependency_unlock_value/.test(optimization)&&/strategic_value/.test(optimization)
   &&/makespan_seconds/.test(optimization)],
 ['strategy selection is deterministic and not model generated',
   /function vp3_cognitive_optimization_compare_v2510/.test(optimization)
   &&/usort\(\$scenarios/.test(optimization)
   &&!/openai|anthropic|llm|prompt|completion/i.test(optimization)],
 ['only autonomous ordering is optimized',
   /if\(\$aMode!==\'autonomous\'\|\|\$bMode!==\'autonomous\'\)/.test(optimization)
   &&/keeps its existing order/.test(optimization)],
 ['optimizer never mutates execution target or work state',
   /executor_mutation_authority'=>false/.test(optimization)
   &&/approval_authority'=>false/.test(optimization)
   &&/execution_authority'=>false/.test(optimization)],
 ['optimizer has no direct Phase 19 claim call',
   !/agent_job_claim_(?:run|next)_v1900\(/.test(optimization)],
 ['v24.90 consumes v25.10 advisory before returning sequence',
   optimizationOrder>=0&&forecastReturn>optimizationOrder
   &&/failure preserves the proven v24\.90 order/.test(forecast)],
 ['v24.90 remains forecast authority',
   /function vp3_cognitive_forecast_snapshot_v2490/.test(forecast)
   &&/'v2490_remains_forecast_authority'=>true/.test(release)],
 ['v24.80 remains admission authority',
   /function vp3_cognitive_portfolio_claim_admission_v2480/.test(portfolio)
   &&/'v2480_remains_admission_authority'=>true/.test(release)],
 ['Phase 19 remains claim lease execution authority',
   /function agent_job_claim_run_v1900/.test(jobs)
   &&/lease_token/.test(jobs)
   &&/agent_worker_runtime_authorize_claim_v1910/.test(workers)
   &&/'phase19_remains_execution_authority'=>true/.test(release)],
 ['v25.00 calibration store is explicitly not required',
   /'v2500_required'=>false/.test(release)
   &&/'calibration_store_required'=>false/.test(release)
   &&/does \*\*not\*\* require a v25\.00 calibration ledger/.test(docs)],
 ['Working Context contains one bounded optimization projection',
   /'optimization'=>1/.test(context)
   &&/vp3_cognitive_optimization_context_item_v2510/.test(context)
   &&/cognitive_optimization_v2510_projection/.test(context)],
 ['optimization context has no instruction authority',
   /instruction_authority'=>false/.test(optimization)
   &&/ephemeral_projection'=>true/.test(optimization)],
 ['Agent Brief uses the shared optimization projection',
   /vp3_cognitive_optimization_activity_projection_v2510/.test(presentation)
   &&/optimization_focus/.test(presentation)
   &&/Strategic optimization/.test(briefJs)],
 ['Proactive Now uses the shared optimization projection',
   /vp3_cognitive_optimization_activity_projection_v2510/.test(proactive)
   &&/'optimization'=>'cognitive_optimization_v2510'/.test(proactive)],
 ['Agent Brain API uses the shared optimization projection',
   /vp3_cognitive_optimization_activity_projection_v2510/.test(brainApi)
   &&/'optimization'=>\$optimization/.test(brainApi)],
 ['Agent Brain renders all strategic scenarios',
   /<strong>Strategic Portfolio Optimization<\/strong>/.test(brainJs)
   &&/optimizationScenarios/.test(brainJs)
   &&/dependency unlock/.test(brainJs)],
 ['History remains canonical conversation archive',
   /<strong>Agent History<\/strong>/.test(brainJs)
   &&/Authorized conversation archive/.test(brainJs)],
 ['chat cache bust exposes v25.10 optimization build',
   /cognitive-optimization-v2510-20260922/.test(chat)
   &&/optimizationBuild/.test(chat)],
 ['CI runs v25.10 node and PHP gates',
   /cognitive-optimization-v2510\.mjs/.test(workflow)
   &&/cognitive-optimization-v2510\.php/.test(workflow)],
 ['Recovery Baseline retains v25.10 gates',
   /cognitive-optimization-v2510\.mjs/.test(recovery)
   &&/cognitive-optimization-v2510\.php/.test(recovery)],
 ['production package requires v25.10 runtime and release files',
   /Cognitive Runtime v25\.10/.test(packageWorkflow)
   &&/cognitive-optimization-v2510\.php/.test(packageWorkflow)
   &&/cognitive-release-v2510\.php/.test(packageWorkflow)],
 ['docs lock the authority chain and canonical History',
   /v25\.10 Optimize/.test(docs)&&/v24\.90 Forecast\/Sequence/.test(docs)
   &&/v24\.80 Admission/.test(docs)&&/Phase 19 Claim\/Lease\/Execute\/Receipt/.test(docs)
   &&/Agent History remains actual Agent Chat conversation history/.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Optimization v25.10 gate: ${checks.length}/${checks.length} passed`);
