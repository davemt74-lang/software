import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const budget=read('includes/cognitive-budget-governance-v2560.php');
const release=read('includes/cognitive-release-v2560.php');
const economics=read('includes/cognitive-economics-v2550.php');
const portfolio=read('includes/cognitive-portfolio-v2480.php');
const replan=read('includes/cognitive-replanning-v2530.php');
const resource=read('includes/cognitive-resource-budget-v2520.php');
const jobs=read('includes/agent-job-engine-v1900.php');
const accounting=read('includes/ai-usage-accounting-v032.php');
const quota=read('includes/subscription-quota.php');
const upgrade=read('upgrade.php');
const bootstrap=read('includes/bootstrap.php');
const context=read('includes/cognitive-context-v2420.php');
const presentation=read('includes/cognitive-presentation-v510.php');
const proactive=read('includes/cognitive-proactive-now-v2340.php');
const brainApi=read('api/chat-notifications-brain-v240.php');
const briefJs=read('chat-cognitive-presentation-v510.js');
const brainJs=read('chat-notifications-drawer-v240.js');
const chat=read('chat.php');
const account=read('account.php');
const page=read('budget-governance.php');
const css=read('budget-governance-v2560.css');
const workflow=read('.github/workflows/cognitive-loop-release-v2360.yml');
const recovery=read('tools/run_recovery_baseline.py');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const docs=read('docs/VP3_COGNITIVE_BUDGET_GOVERNANCE_V2560.md');

const checks=[
 ['v25.60 loads after v25.50 and release gate is loaded',
   bootstrap.indexOf("cognitive-economics-v2550.php")<bootstrap.indexOf("cognitive-budget-governance-v2560.php")
   &&bootstrap.includes("cognitive-release-v2560.php")],
 ['normal upgrade installs and verifies v25.60 schema',
   /cognitive-budget-governance-v2560\.php/.test(upgrade)
   &&/vp3_cognitive_budget_ensure_schema_v2560/.test(upgrade)
   &&/vp3_cognitive_budget_schema_ready_v2560/.test(upgrade)],
 ['only two durable v25.60 governance tables are created',
   (budget.match(/CREATE TABLE IF NOT EXISTS cognitive_budget_/g)||[]).length===2
   &&/cognitive_budget_policies_v2560/.test(budget)
   &&/cognitive_budget_decisions_v2560/.test(budget)],
 ['v25.60 creates no duplicate usage billing token queue worker lease or receipt ledger',
   !/CREATE TABLE IF NOT EXISTS (?:ai_execution_ledger|ai_token_reservations|agent_workflow_runs|agent_workflow_actions|agent_workflow_receipts)/.test(budget)
   &&/'second_usage_ledger'=>false/.test(release)
   &&/'second_billing_ledger'=>false/.test(release)
   &&/'second_token_ledger'=>false/.test(release)
   &&/'second_lease_system'=>false/.test(release)],
 ['budget policies require explicit user save and are never seeded by schema install',
   budget.indexOf('INSERT INTO cognitive_budget_policies_v2560')>budget.indexOf('function vp3_cognitive_budget_policy_save_v2560')
   &&/'default_budget_created'=>false/.test(release)
   &&/'budget_requires_explicit_user_policy'=>true/.test(release)],
 ['policy scopes include account goal Agent and project',
   /\['account','goal','agent','project'\]/.test(budget)
   &&/scope_kind/.test(page)],
 ['policy periods are UTC daily weekly and monthly',
   /\['daily','weekly','monthly'\]/.test(budget)
   &&/gmdate\('Y-m-d 00:00:00'/.test(budget)
   &&/gmdate\('Y-m-01 00:00:00'/.test(budget)],
 ['soft and hard enforcement modes are explicit',
   /\['soft','hard'\]/.test(budget)
   &&/'soft_policy_never_blocks_execution'=>true/.test(release)],
 ['canonical current-period spend comes from AI execution ledger',
   /FROM ai_execution_ledger/.test(budget)
   &&/estimated_cost_micros/.test(budget)
   &&/cloud_tokens_charged/.test(budget)
   &&/CREATE TABLE IF NOT EXISTS ai_execution_ledger/.test(accounting)],
 ['goal budget actuals proportionally attribute shared workflow runs',
   /GREATEST\(1,x\.goal_count\)/.test(budget)
   &&/COUNT\(DISTINCT goal_id\) goal_count/.test(budget)],
 ['Agent and project budgets use canonical ledger/workflow identities',
   /l\.agent_id=\?/.test(budget)
   &&/INNER JOIN agent_workflow_runs r/.test(budget)
   &&/r\.source_key=\?/.test(budget)],
 ['v25.50 proportional Cloud evidence feeds v25.60 projections',
   /attributed_cloud_requests/.test(economics)
   &&/average_attributed_known_cost_micros/.test(budget)
   &&/attributed_cloud_tokens_charged/.test(budget)],
 ['unknown cost under a configured hard cost policy requires user',
   /actual_cost_unknown/.test(budget)&&/projected_cost_unknown/.test(budget)
   &&/'unknown_pricing_under_hard_cost_policy_requires_user'=>true/.test(release)],
 ['commitments receive budget allocation precedence',
   /vp3_cognitive_budget_allocation_compare_v2560/.test(budget)
   &&/commitment_protection_score/.test(budget)
   &&/'protected_commitment_is_not_silently_dropped'=>true/.test(release)],
 ['hard budget conflicts with commitments are surfaced rather than silently resolved',
   /budget_commitment_conflict/.test(budget)
   &&/commitment_budget_conflict/.test(replan)
   &&/'hard_budget_commitment_conflict_is_surfaced'=>true/.test(release)],
 ['portfolio applies budget after economics before replanning and resource reservation',
   portfolio.indexOf('vp3_cognitive_economics_apply_v2550')<portfolio.indexOf('vp3_cognitive_budget_apply_v2560')
   &&portfolio.indexOf('vp3_cognitive_budget_apply_v2560')<portfolio.indexOf('vp3_cognitive_replanning_overlay_v2530')
   &&portfolio.indexOf('vp3_cognitive_replanning_overlay_v2530')<portfolio.indexOf('vp3_cognitive_resource_budget_plan_v2520')],
 ['v24.80 hard holds new autonomous claim materialization and remediation admission',
   /budget_hard_hold/.test(portfolio)
   &&/request_budget_approval/.test(portfolio)
   &&/empty\(\$item\['budget_hard_hold'\]\)/.test(portfolio)],
 ['v25.20 does not reserve capacity for a hard-budget-held goal',
   /if\(!empty\(\$item\['budget_hard_hold'\]\)\)return null/.test(resource)],
 ['v25.30 marks hard budget holds unsafe and requests user approval',
   /hard_budget_hold/.test(replan)
   &&/request_budget_approval/.test(replan)
   &&/!\$budgetHardHold/.test(replan)],
 ['Phase 19 reads id and status then applies v25.60 before actual claim',
   /SELECT id,status FROM agent_workflow_runs/.test(jobs)
   &&/vp3_cognitive_budget_filter_claim_candidates_v2560/.test(jobs)
   &&jobs.indexOf('vp3_cognitive_budget_filter_claim_candidates_v2560')<jobs.indexOf('foreach($rows as $r){$claim=agent_job_claim_run_v1900')],
 ['already-executing multi-step runs pass through budget claim guard',
   /\$row\['status'\].*==='executing'/.test(budget)
   &&/\$passthrough\[\]=\$row/.test(budget)
   &&/'already_executing_work_not_cancelled'=>true/.test(release)],
 ['Phase 19 budget guard reuses canonical portfolio holds for all scopes',
   /vp3_cognitive_portfolio_snapshot_v2480/.test(budget)
   &&/budget_hold_policy_ids/.test(budget)
   &&/vp3_cognitive_budget_policy_rows_v2560/.test(budget)],
 ['shared Phase 19 runs are governed by every linked autonomous goal',
   /vp3_cognitive_budget_goals_for_run_v2560/.test(budget)
   &&/SELECT DISTINCT g\.id/.test(budget)
   &&/foreach\(\$goalIds as \$goalId\)/.test(budget)
   &&/break 2/.test(budget)],
 ['budget guard never directly claims creates lease or writes execution receipts',
   !/agent_job_claim_(?:run|next)_v1900\(/.test(budget)
   &&!/lease_token|random_bytes\(/.test(budget)
   &&!/agent_workflow_receipts\s*\(/.test(budget)
   &&/'phase19_remains_claim_lease_execution_receipt_authority'=>true/.test(release)],
 ['subscription/token enforcement remains canonical and v25.60 never mutates it',
   /function subscription_ai_balance/.test(quota)
   &&!/subscription_ai_(?:preflight|commit|refund)|ai_token_reservations\s*(?:SET|VALUES)|token_pack/i.test(budget)
   &&/'budget_cannot_purchase_tokens'=>true/.test(release)],
 ['budget decision table is append-only in application code',
   /INSERT INTO cognitive_budget_decisions_v2560/.test(budget)
   &&!/UPDATE cognitive_budget_decisions_v2560|DELETE FROM cognitive_budget_decisions_v2560/.test(budget)
   &&/'decision_store_is_append_only_governance_audit'=>true/.test(release)],
 ['overrides are explicit user decisions and expire no later than budget period',
   /override_granted/.test(budget)&&/override_revoked/.test(budget)
   &&/min\(\$expiresTs,\(int\)\$bounds\['end_ts'\]\)/.test(budget)
   &&/'override_requires_explicit_user_decision'=>true/.test(release)],
 ['Budget Governance page is owner-scoped CSRF-protected and supports save disable grant revoke',
   /require_permission\('account\.access'\)/.test(page)
   &&/verify_csrf\(\)/.test(page)
   &&/save_policy/.test(page)&&/disable_policy/.test(page)
   &&/grant_override/.test(page)&&/revoke_override/.test(page)],
 ['Budget Governance page exposes actual projected run-rate held and audit views',
   /Budget policies/.test(page)&&/Projected remaining/.test(page)
   &&/Run-rate period end/.test(page)&&/Held autonomous goals/.test(page)
   &&/Policy & override audit/.test(page)&&/Budget overrides/.test(page)
   &&css.length>1000],
 ['Account exposes Budget Governance directly',
   /\/budget-governance\.php/.test(account)&&/AI Budget Governance/.test(account)],
 ['Working Context contains one bounded budget governance item with no instruction authority',
   /'budget_governance'=>1/.test(context)
   &&/vp3_cognitive_budget_context_item_v2560/.test(context)
   &&/cognitive_budget_governance_v2560_policy/.test(context)
   &&/instruction_authority'=>false/.test(budget)],
 ['Agent Brief uses shared budget projection and manage URL',
   /vp3_cognitive_budget_activity_projection_v2560/.test(presentation)
   &&/budget_focus/.test(presentation)&&/Budget governance/.test(briefJs)
   &&/Manage budgets/.test(briefJs)],
 ['Proactive Now uses shared budget projection',
   /vp3_cognitive_budget_activity_projection_v2560/.test(proactive)
   &&/'budget_governance'=>'cognitive_budget_governance_v2560'/.test(proactive)],
 ['Agent Brain API and UI use the shared budget projection',
   /vp3_cognitive_budget_activity_projection_v2560/.test(brainApi)
   &&/'budget_governance'=>\$budgetGovernance/.test(brainApi)
   &&/<strong>Budget Governance<\/strong>/.test(brainJs)
   &&/Manage Budget Governance/.test(brainJs)],
 ['Agent History remains canonical conversation archive',
   /<strong>Agent History<\/strong>/.test(brainJs)
   &&/Authorized conversation archive/.test(brainJs)],
 ['chat cache bust exposes v25.60 build',
   /cognitive-budget-governance-v2560-20260923/.test(chat)
   &&/budgetGovernanceBuild/.test(chat)],
 ['CI runs v25.60 PHP and Node gates and lints management page',
   /cognitive-budget-governance-v2560\.php/.test(workflow)
   &&/cognitive-budget-governance-v2560\.mjs/.test(workflow)
   &&/php -l budget-governance\.php/.test(workflow)],
 ['Recovery Baseline retains v25.60 gates',
   /cognitive-budget-governance-v2560\.php/.test(recovery)
   &&/cognitive-budget-governance-v2560\.mjs/.test(recovery)],
 ['production package retains v25.60 runtime controls upgrade and canonical sources',
   /cognitive-budget-governance-v2560\.php/.test(packageWorkflow)
   &&/cognitive-release-v2560\.php/.test(packageWorkflow)
   &&/budget-governance\.php/.test(packageWorkflow)
   &&/budget-governance-v2560\.css/.test(packageWorkflow)
   &&/upgrade\.php/.test(packageWorkflow)
   &&/ai-usage-accounting-v032\.php/.test(packageWorkflow)
   &&/subscription-quota\.php/.test(packageWorkflow)],
 ['docs preserve explicit-policy governance authority overrides and failure boundary',
   /Explicit policies only/.test(docs)
   &&/Durable governance, not a second spend ledger/.test(docs)
   &&/Soft policies/.test(docs)&&/Hard policies/.test(docs)
   &&/Overrides/.test(docs)
   &&/No already-authorized work can deadlock/.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Budget Governance v25.60 gate: ${checks.length}/${checks.length} passed`);
