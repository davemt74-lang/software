import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const value=read('includes/cognitive-value-roi-v2570.php');
const release=read('includes/cognitive-release-v2570.php');
const budget=read('includes/cognitive-budget-governance-v2560.php');
const economics=read('includes/cognitive-economics-v2550.php');
const commitment=read('includes/cognitive-commitment-protection-v2540.php');
const portfolio=read('includes/cognitive-portfolio-v2480.php');
const replan=read('includes/cognitive-replanning-v2530.php');
const resource=read('includes/cognitive-resource-budget-v2520.php');
const jobs=read('includes/agent-job-engine-v1900.php');
const goalReview=read('includes/agent-goal-review-v1714.php');
const meetingVerification=read('includes/video-meetings-followthrough-verification-v18200-part1.php');
const profileRevenue=read('includes/profile-revenue-intelligence-v180.php');
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
const page=read('outcome-value.php');
const css=read('outcome-value-v2570.css');
const workflow=read('.github/workflows/cognitive-loop-release-v2360.yml');
const recovery=read('tools/run_recovery_baseline.py');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const docs=read('docs/VP3_COGNITIVE_VALUE_ROI_V2570.md');

const checks=[
 ['v25.70 loads after v25.60 and has a release gate',
   bootstrap.indexOf("cognitive-budget-governance-v2560.php")<bootstrap.indexOf("cognitive-value-roi-v2570.php")
   &&bootstrap.includes("cognitive-release-v2570.php")],
 ['normal upgrade installs and verifies v25.70 schema',
   /cognitive-value-roi-v2570\.php/.test(upgrade)
   &&/vp3_cognitive_value_ensure_schema_v2570/.test(upgrade)
   &&/vp3_cognitive_value_schema_ready_v2570/.test(upgrade)],
 ['v25.70 creates exactly two durable value-governance tables',
   (value.match(/CREATE TABLE IF NOT EXISTS cognitive_value_/g)||[]).length===2
   &&/cognitive_value_profiles_v2570/.test(value)
   &&/cognitive_value_events_v2570/.test(value)],
 ['v25.70 creates no second revenue billing usage queue worker lease or receipt ledger',
   !/CREATE TABLE IF NOT EXISTS (?:profile_events|ai_execution_ledger|agent_workflow_runs|agent_workflow_actions|agent_workflow_receipts)/.test(value)
   &&/'second_revenue_ledger'=>false/.test(release)
   &&/'second_billing_ledger'=>false/.test(release)
   &&/'second_usage_ledger'=>false/.test(release)
   &&/'second_lease_system'=>false/.test(release)],
 ['value profiles are explicit user configuration and never seeded by schema install',
   value.indexOf('INSERT INTO cognitive_value_profiles_v2570')>value.indexOf('function vp3_cognitive_value_profile_save_v2570')
   &&/'default_value_profile_created'=>false/.test(release)
   &&/'model_inferred_monetary_value'=>false/.test(release)],
 ['value scopes are goal workflow project Agent and meeting',
   /\['goal','workflow','project','agent','meeting'\]/.test(value)
   &&/scope_kind/.test(page)],
 ['value kinds remain money or neutral score only',
   /\['money','score'\]/.test(value)
   &&/Outcome score/.test(page)
   &&/Money/.test(page)],
 ['realization modes are explicit and verified completion is scope-limited',
   /\['manual_confirmation','verified_completion','profile_conversion'\]/.test(value)
   &&/verified_completion.*\['goal','workflow','meeting'\]/s.test(value)
   &&/verified\.disabled=!completionAllowed/.test(page)],
 ['manual realization is accepted only for manual-confirmation profiles',
   /realization_mode.*manual_confirmation/.test(value)
   &&/Manual realized value is available only for profiles configured for manual confirmation/.test(value)
   &&/'manual_realization_only_when_configured'=>true/.test(release)
   &&/realization_mode'\]\s*===?'manual_confirmation'/.test(page)],
 ['manual value evidence is append-only',
   /INSERT INTO cognitive_value_events_v2570/.test(value)
   &&!/UPDATE cognitive_value_events_v2570|DELETE FROM cognitive_value_events_v2570/.test(value)
   &&/'value_events_are_append_only_user_evidence'=>true/.test(release)],
 ['profile definition changes revoke stale manual evidence and evidence changes reset baseline',
   /profile_configuration_change/.test(value)
   &&/baseline_at=CASE WHEN \?=1 THEN NOW\(\)/.test(value)
   &&/'profile_definition_change_revokes_stale_manual_evidence'=>true/.test(release)
   &&/'profile_evidence_change_resets_baseline'=>true/.test(release)],
 ['goal verified value uses Phase 17.14 verified achievement authority',
   /agent_goal_review_actual_achievement_v1714/.test(value)
   &&/objective_verification_status='achieved'/.test(goalReview)
   &&/'goal_verification'=>'agent_goal_review_v1714_verified_achievement'/.test(value)],
 ['workflow verified value requires objective verification achieved',
   /objective_verification_status/.test(value)&&/==='achieved'/.test(value)
   &&/'workflow_verification'=>'agent_workflow_runs_objective_verification'/.test(value)],
 ['meeting verified value requires canonical verified follow-through closures',
   /video_meeting_followthrough_closures/.test(value)
   &&/status='verified'/.test(value)
   &&/verified_count/.test(value)
   &&/video_meeting_followthrough_closures/.test(meetingVerification)],
 ['declared completion value is explicitly not revenue',
   /declared_completion_value/.test(value)
   &&/'declared_completion_value_is_not_labeled_revenue'=>true/.test(release)
   &&/declared completion value becomes a \*\*declared completion value\*\*/.test(docs)],
 ['Profile conversion value reads canonical Profile event revenue evidence',
   /FROM profile_events/.test(value)
   &&/booking_converted/.test(value)&&/product_converted/.test(value)
   &&/profile_revenue_target_key_v180/.test(value)
   &&/profile_revenue_target_key_v180/.test(profileRevenue)
   &&/'canonical_profile_revenue'=>'profile_events_profile_revenue_v180'/.test(value)],
 ['Profile conversion evidence is batched and refuses partial scan undercount',
   /vp3_cognitive_value_profile_conversion_batch_v2570/.test(value)
   &&/COUNT\(\*\) FROM profile_events/.test(value)
   &&/profile_conversion_scan_limit/.test(value)
   &&/'profile_conversion_over_scan_limit_is_incomplete_not_undercounted'=>true/.test(release)
   &&/conversion evidence exceeds bounded scan/.test(page)],
 ['no FX conversion is performed and direct monetary ROI is USD-only',
   /strtoupper\(\$currency\)!=='USD'/.test(value)
   &&/'fx_conversion_authority'=>false/.test(release)
   &&/'non_usd_value_compared_to_usd_ai_cost'=>false/.test(release)
   &&/performs no foreign-exchange conversion/.test(docs)],
 ['unknown historical AI pricing disables direct ROI',
   /\$historicalUnknown/.test(value)
   &&/\$expectedCostKnown=\$remainingCost!==null&&\$historicalUnknown===0/.test(value)
   &&/'unknown_ai_cost_disables_direct_roi'=>true/.test(release)],
 ['value inheritance precedence is goal then workflow then project then Agent',
   /\$profileMap\['goal'\]/.test(value)
   &&/\[\['workflow',\$workflow\],\['project',\$projects\],\['agent',\$agents\]\]/.test(value)],
 ['conflicting inherited value stays ambiguous and neutral',
   /count\(\$rows\)>1.*ambiguous.*true/s.test(value)
   &&/'ambiguous_inherited_value_is_advisory_only'=>true/.test(release)],
 ['one inherited non-goal value is not multiplied across multiple active goals',
   /\$sharedInherited/.test(value)
   &&/\$adjustment=\$sharedInherited\?0\.0/.test(value)
   &&/'shared_inherited_value_not_multiplied_across_goals'=>true/.test(release)
   &&/multiple active portfolio goals/.test(docs)],
 ['commitments and hard budgets neutralize value optimization',
   /commitment_protection_score/.test(value)&&/budget_hard_hold/.test(value)
   &&/'commitments_outrank_value_optimization'=>true/.test(release)
   &&/'hard_budgets_outrank_value_optimization'=>true/.test(release)],
 ['manual and supervised work are not value-reordered',
   /execution_mode/.test(value)&&/!=='autonomous'/.test(value)
   &&/'manual_work_is_not_value_reordered'=>true/.test(release)
   &&/'supervised_work_is_not_value_reordered'=>true/.test(release)],
 ['portfolio applies value after budget and before replanning',
   portfolio.indexOf('vp3_cognitive_budget_apply_v2560')<portfolio.indexOf('vp3_cognitive_value_apply_v2570')
   &&portfolio.indexOf('vp3_cognitive_value_apply_v2570')<portfolio.indexOf('vp3_cognitive_replanning_overlay_v2530')],
 ['v25.30 consumes only bounded value adjustment',
   /value_planning_adjustment/.test(replan)
   &&/max\(-0\.08,min\(0\.08/.test(replan)
   &&/protect_high_value_outcome/.test(replan)
   &&/defer_lower_value_if_safe/.test(replan)],
 ['v25.20 consumes only bounded value adjustment and hard budget eligibility remains unchanged',
   /value_planning_adjustment/.test(resource)
   &&/max\(-0\.08,min\(0\.08/.test(resource)
   &&/if\(!empty\(\$item\['budget_hard_hold'\]\)\)return null/.test(resource)],
 ['v25.70 does not modify Phase 19 claim eligibility or execution directly',
   !/agent_job_claim_(?:run|next)_v1900\(/.test(value)
   &&!/agent_worker_runtime_poll_v1910\(/.test(value)
   &&!/lease_token|random_bytes\(/.test(value)
   &&/'value_optimization_cannot_claim_or_execute'=>true/.test(release)
   &&/function agent_job_claim_next_v1900/.test(jobs)],
 ['v25.70 cannot mutate executor deadline approval budget or tokens',
   /'value_optimization_cannot_change_executor'=>true/.test(release)
   &&/'value_optimization_cannot_change_deadline'=>true/.test(release)
   &&/'value_optimization_cannot_change_approval'=>true/.test(release)
   &&/'value_optimization_cannot_change_budget_or_tokens'=>true/.test(release)
   &&!/subscription_ai_(?:preflight|commit|refund)/.test(value)],
 ['advisory calibration uses verified explicit profiles and does not rewrite expected value',
   /vp3_cognitive_value_calibration_v2570/.test(value)
   &&/verified_explicit_value_profiles/.test(value)
   &&/Calibration is advisory only/.test(docs)],
 ['Working Context contains one bounded value projection below budget governance',
   /'value_roi'=>1/.test(context)
   &&/vp3_cognitive_value_context_item_v2570/.test(context)
   &&/cognitive_value_roi_v2570_projection/.test(context)
   &&context.indexOf("'budget_governance'=>96.05")<context.indexOf("'value_roi'=>96.04")
   &&/instruction_authority'=>false/.test(value)],
 ['Agent Brief uses shared value projection and explicit evidence language',
   /vp3_cognitive_value_activity_projection_v2570/.test(presentation)
   &&/value_focus/.test(presentation)
   &&/Outcome value & ROI · explicit value/.test(briefJs)
   &&/Manage value/.test(briefJs)],
 ['Proactive Now uses shared value projection',
   /vp3_cognitive_value_activity_projection_v2570/.test(proactive)
   &&/'value_roi'=>'cognitive_value_roi_v2570'/.test(proactive)],
 ['Agent Brain API and UI use shared value projection',
   /vp3_cognitive_value_activity_projection_v2570/.test(brainApi)
   &&/'value_roi'=>\$valueRoi/.test(brainApi)
   &&/<strong>Outcome Value & ROI<\/strong>/.test(brainJs)
   &&/Manage Outcome Value/.test(brainJs)],
 ['Agent History remains canonical conversation archive',
   /<strong>Agent History<\/strong>/.test(brainJs)
   &&/Authorized conversation archive/.test(brainJs)],
 ['Outcome Value page is owner-scoped CSRF-protected and exposes all value controls',
   /require_permission\('account\.access'\)/.test(page)
   &&/verify_csrf\(\)/.test(page)
   &&/save_profile/.test(page)&&/disable_profile/.test(page)
   &&/set_realized/.test(page)&&/revoke_realized/.test(page)
   &&/Value at risk/.test(page)&&/Expected vs\. realized/.test(page)
   &&/Realized-value history/.test(page)
   &&css.length>1000],
 ['Account exposes Outcome Value & ROI directly',
   /\/outcome-value\.php/.test(account)&&/Outcome Value & ROI/.test(account)],
 ['chat cache bust exposes v25.70 build',
   /cognitive-value-roi-v2570-20260923/.test(chat)&&/valueRoiBuild/.test(chat)],
 ['CI runs v25.70 PHP and Node gates and lints value workspace',
   /cognitive-value-roi-v2570\.php/.test(workflow)
   &&/cognitive-value-roi-v2570\.mjs/.test(workflow)
   &&/php -l outcome-value\.php/.test(workflow)],
 ['Recovery Baseline retains v25.70 gates',
   /cognitive-value-roi-v2570\.php/.test(recovery)
   &&/cognitive-value-roi-v2570\.mjs/.test(recovery)],
 ['production package requires v25.70 runtime controls upgrade and canonical sources',
   /Cognitive Runtime v25\.70/.test(packageWorkflow)
   &&/cognitive-value-roi-v2570\.php/.test(packageWorkflow)
   &&/cognitive-release-v2570\.php/.test(packageWorkflow)
   &&/outcome-value\.php/.test(packageWorkflow)
   &&/outcome-value-v2570\.css/.test(packageWorkflow)
   &&/upgrade\.php/.test(packageWorkflow)
   &&/agent-goal-review-v1714\.php/.test(packageWorkflow)
   &&/profile-revenue-intelligence-v180\.php/.test(packageWorkflow)
   &&/video-meetings-followthrough-verification-v18200\.php/.test(packageWorkflow)],
 ['docs preserve explicit value evidence ROI semantics shared inheritance and fail-open boundary',
   /Explicit value only/.test(docs)
   &&/No hidden FX/.test(docs)
   &&/Unknown cost/.test(docs)
   &&/AI-cost ROI/.test(docs)
   &&/multiple active portfolio goals/.test(docs)
   &&/No already-authorized work can deadlock/.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Outcome Value & ROI v25.70 gate: ${checks.length}/${checks.length} passed`);
