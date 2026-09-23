import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const economics=read('includes/cognitive-economics-v2550.php');
const release=read('includes/cognitive-release-v2550.php');
const accounting=read('includes/ai-usage-accounting-v032.php');
const quota=read('includes/subscription-quota.php');
const subscriptions=read('includes/subscriptions.php');
const portfolio=read('includes/cognitive-portfolio-v2480.php');
const commitments=read('includes/cognitive-commitment-protection-v2540.php');
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
const docs=read('docs/VP3_COGNITIVE_ECONOMICS_V2550.md');

const economicsUiStart=brainJs.indexOf('<strong>Cost & Resource Economics</strong>');
const replanningUiStart=brainJs.indexOf('<strong>Portfolio Replanning</strong>');

const checks=[
 ['v25.50 loads after commitment protection and has release gate',
   bootstrap.indexOf("cognitive-commitment-protection-v2540.php")<bootstrap.indexOf("cognitive-economics-v2550.php")
   &&bootstrap.includes("cognitive-release-v2550.php")],
 ['v25.50 creates no cost billing token queue lease or receipt schema',
   !(economics.match(/CREATE TABLE|ALTER TABLE|DROP TABLE|INSERT INTO|UPDATE\s+[a-z_]+\s+SET|DELETE FROM/ig)||[]).length
   &&/'second_cost_ledger'=>false/.test(release)
   &&/'second_billing_ledger'=>false/.test(release)
   &&/'second_token_ledger'=>false/.test(release)
   &&/'second_token_reservation_system'=>false/.test(release)],
 ['canonical usage cost authority is AI execution accounting v0.32',
   /ai_execution_ledger/.test(economics)
   &&/estimated_cost_micros/.test(economics)
   &&/function ai_usage_accounting_v032_cost/.test(accounting)
   &&/'usage_cost'=>'ai_execution_ledger_v032'/.test(release)],
 ['estimated cost is explicitly not provider invoice',
   /estimated_cost_is_not_provider_invoice'=>true/.test(release)
   &&/not a provider invoice/.test(docs)
   &&/not an invoice/.test(brainJs)],
 ['unknown pricing is never coerced to zero',
   /unknown_pricing_is_not_zero'=>true/.test(economics)
   &&/unknown_cost_requests/.test(economics)
   &&/Unknown pricing/.test(brainJs)],
 ['canonical token balance authority is subscription quota',
   /subscription_ai_balance/.test(economics)
   &&/function subscription_ai_balance/.test(quota)
   &&/subscription-quota\.php/.test(subscriptions)
   &&/'token_balance'=>'subscription_ai_balance'/.test(release)],
 ['economics does not reserve debit refund or purchase tokens',
   !/subscription_ai_(?:preflight|commit|refund)|ai_token_reservations\s*(?:INSERT|UPDATE|DELETE)|token_pack/i.test(economics)
   &&/'economics_cannot_change_package_or_token_balance'=>true/.test(release)],
 ['portfolio exposes canonical goal workflow run lineage',
   /vp3_cognitive_portfolio_goal_run_ids_v2480/.test(portfolio)
   &&/'workflow_run_ids'=>vp3_cognitive_portfolio_goal_run_ids_v2480/.test(portfolio)],
 ['shared runs are proportionally attributed',
   /vp3_cognitive_economics_attribution_share_v2550/.test(economics)
   &&/\$runGoalCounts\[\$runId\]/.test(economics)
   &&/attributed_known_cost_micros/.test(economics)
   &&/'shared_run_cost_is_proportionally_attributed'=>true/.test(release)],
 ['cloud economics compares against cloud average rather than local-zero blended average',
   /cloud_average_known_cost_micros/.test(economics)
   &&/source='vp3_cloud'/.test(economics)
   &&/account_cloud_average_known_cost_micros/.test(economics)],
 ['commitment protection precedes economics and commitments are economics-exempt',
   portfolio.indexOf('vp3_cognitive_commitment_apply_v2540')>=0
   &&portfolio.indexOf('vp3_cognitive_commitment_apply_v2540')<portfolio.indexOf('vp3_cognitive_economics_apply_v2550')
   &&/commitment_protection_score/.test(economics)
   &&/commitment_exempt/.test(economics)
   &&/'commitments_outrank_economics'=>true/.test(release)],
 ['economics precedes bounded replanning and resource reservations',
   portfolio.indexOf('vp3_cognitive_economics_apply_v2550')<portfolio.indexOf('vp3_cognitive_replanning_overlay_v2530')
   &&portfolio.indexOf('vp3_cognitive_replanning_overlay_v2530')<portfolio.indexOf('vp3_cognitive_resource_budget_plan_v2520')],
 ['v25.30 consumes only bounded economic planning adjustment',
   /economic_planning_adjustment/.test(replanning)
   &&/max\(-0\.10,min\(0\.08/.test(replanning)
   &&/economic_pressure/.test(replanning)],
 ['v25.20 consumes only bounded economic planning adjustment',
   /economic_planning_adjustment/.test(resource)
   &&/max\(-0\.10,min\(0\.08/.test(resource)],
 ['manual supervised HomeServer unknown-price and low-pressure work stay neutral',
   /execution_mode/.test(economics)&&/supervised/.test(economics)&&/homeserver/.test(economics)
   &&/!\$costKnown/.test(economics)&&/\$quotaPressure<0\.25/.test(economics)
   &&/'manual_work_is_not_economically_reordered'=>true/.test(release)
   &&/'supervised_work_is_not_economically_reordered'=>true/.test(release)],
 ['economics has no claim lease executor deadline approval or execution authority',
   !/agent_job_claim_(?:run|next)_v1900\(/.test(economics)
   &&!/agent_worker_runtime_poll_v1910\(/.test(economics)
   &&!/lease_token|random_bytes\(/.test(economics)
   &&/'economics_cannot_block_execution'=>true/.test(release)
   &&/'economics_cannot_change_executor'=>true/.test(release)
   &&/'economics_cannot_change_deadline'=>true/.test(release)
   &&/'economics_cannot_change_approval'=>true/.test(release)],
 ['Phase 19 remains claimant and execution authority',
   /function agent_job_claim_next_v1900/.test(jobs)
   &&/'phase19_remains_claim_lease_execution_receipt_authority'=>true/.test(release)],
 ['v25.40 remains commitment authority',
   /function vp3_cognitive_commitment_apply_v2540/.test(commitments)
   &&/'v2540_remains_commitment_authority'=>true/.test(release)],
 ['Working Context contains one bounded economics projection below commitments',
   /'economics'=>1/.test(context)
   &&/vp3_cognitive_economics_context_item_v2550/.test(context)
   &&/cognitive_economics_v2550_projection/.test(context)
   &&context.indexOf("'commitment_protection'=>95.99")<context.indexOf("'economics'=>95.985")],
 ['economics context has no instruction authority',
   /instruction_authority'=>false/.test(economics)&&/ephemeral_projection'=>true/.test(economics)],
 ['Agent Brief uses shared economics projection and accurate estimate language',
   /vp3_cognitive_economics_activity_projection_v2550/.test(presentation)
   &&/economics_focus/.test(presentation)
   &&/30-day ledger estimate/.test(briefJs)
   &&/estimated AI cost from recorded usage/.test(briefJs)],
 ['Proactive Now uses shared economics projection',
   /vp3_cognitive_economics_activity_projection_v2550/.test(proactive)
   &&/'economics'=>'cognitive_economics_v2550'/.test(proactive)],
 ['Agent Brain API uses shared economics projection',
   /vp3_cognitive_economics_activity_projection_v2550/.test(brainApi)
   &&/'economics'=>\$economics/.test(brainApi)],
 ['Agent Brain economics UI is intact before Portfolio Replanning',
   economicsUiStart>=0&&replanningUiStart>economicsUiStart
   &&/30d est\. cost/.test(brainJs)
   &&/shared runs proportionally attributed/.test(brainJs)],
 ['Agent History remains canonical conversation archive',
   /<strong>Agent History<\/strong>/.test(brainJs)
   &&/Authorized conversation archive/.test(brainJs)],
 ['chat cache bust exposes v25.50 build',
   /cognitive-economics-v2550-20260923/.test(chat)&&/economicsBuild/.test(chat)],
 ['CI runs v25.50 PHP and Node gates',
   /cognitive-economics-v2550\.php/.test(workflow)&&/cognitive-economics-v2550\.mjs/.test(workflow)],
 ['Recovery Baseline retains v25.50 gates',
   /cognitive-economics-v2550\.php/.test(recovery)&&/cognitive-economics-v2550\.mjs/.test(recovery)],
 ['production package requires v25.50 runtime release and canonical sources',
   /Cognitive Runtime v25\.50/.test(packageWorkflow)
   &&/cognitive-economics-v2550\.php/.test(packageWorkflow)
   &&/cognitive-release-v2550\.php/.test(packageWorkflow)
   &&/ai-usage-accounting-v032\.php/.test(packageWorkflow)
   &&/subscription-quota\.php/.test(packageWorkflow)],
 ['docs preserve authority chain estimation shared attribution and failure behavior',
   /v25\.50 Economics/.test(docs)
   &&/estimated_cost_micros/.test(docs)
   &&/Shared workflow attribution/.test(docs)
   &&/Commitment protection always outranks economics/.test(docs)
   &&/No already-authorized work can deadlock/.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Economics v25.50 gate: ${checks.length}/${checks.length} passed`);
