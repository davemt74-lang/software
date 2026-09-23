import fs from 'node:fs';
import assert from 'node:assert/strict';

const read=p=>fs.readFileSync(p,'utf8');
const decision=read('includes/cognitive-decision-calibration-v2580.php');
const release=read('includes/cognitive-release-v2580.php');
const learning=read('includes/cognitive-learning-v540.php');
const presentationCalibration=read('includes/cognitive-calibration-v2350.php');
const forecast=read('includes/cognitive-forecast-v2490.php');
const budget=read('includes/cognitive-budget-governance-v2560.php');
const value=read('includes/cognitive-value-roi-v2570.php');
const portfolio=read('includes/cognitive-portfolio-v2480.php');
const jobs=read('includes/agent-job-engine-v1900.php');
const goalReview=read('includes/agent-goal-review-v1714.php');
const accounting=read('includes/ai-usage-accounting-v032.php');
const bootstrap=read('includes/bootstrap.php');
const upgrade=read('upgrade.php');
const context=read('includes/cognitive-context-v2420.php');
const presentation=read('includes/cognitive-presentation-v510.php');
const proactive=read('includes/cognitive-proactive-now-v2340.php');
const brainApi=read('api/chat-notifications-brain-v240.php');
const briefJs=read('chat-cognitive-presentation-v510.js');
const brainJs=read('chat-notifications-drawer-v240.js');
const chat=read('chat.php');
const account=read('account.php');
const page=read('decision-calibration.php');
const css=read('decision-calibration-v2580.css');
const workflow=read('.github/workflows/cognitive-loop-release-v2360.yml');
const recovery=read('tools/run_recovery_baseline.py');
const packageWorkflow=read('.github/workflows/production-deploy-package.yml');
const docs=read('docs/VP3_COGNITIVE_DECISION_CALIBRATION_V2580.md');

const checks=[
 ['v25.80 loads after v25.70 and has release gate',
   bootstrap.indexOf("cognitive-value-roi-v2570.php")<bootstrap.indexOf("cognitive-decision-calibration-v2580.php")
   &&bootstrap.includes("cognitive-release-v2580.php")],
 ['normal upgrade installs and verifies v25.80 schema',
   /cognitive-decision-calibration-v2580\.php/.test(upgrade)
   &&/vp3_cognitive_decision_ensure_schema_v2580/.test(upgrade)
   &&/vp3_cognitive_decision_schema_ready_v2580/.test(upgrade)],
 ['v25.80 creates exactly two decision-calibration tables',
   (decision.match(/CREATE TABLE IF NOT EXISTS cognitive_decision_/g)||[]).length===2
   &&/cognitive_decision_snapshots_v2580/.test(decision)
   &&/cognitive_decision_settlements_v2580/.test(decision)],
 ['v25.80 creates no second relevance outcome usage billing scheduler queue worker lease or receipt authority',
   !/CREATE TABLE IF NOT EXISTS (?:cognitive_feedback_events|cognitive_outcomes|ai_execution_ledger|agent_workflow_runs|agent_workflow_actions|agent_workflow_receipts)/.test(decision)
   &&/'second_relevance_learner'=>false/.test(release)
   &&/'second_outcome_ledger'=>false/.test(release)
   &&/'second_usage_ledger'=>false/.test(release)
   &&/'second_lease_system'=>false/.test(release)],
 ['v5.40 remains relevance learner and v23.50 remains presentation calibration',
   /function vp3_cognitive_learning_profile_recalculate_v540/.test(learning)
   &&/function vp3_cognitive_calibration_state_v2350/.test(presentationCalibration)
   &&/'v540_remains_relevance_learning_authority'=>true/.test(release)
   &&/'v2350_remains_proactive_presentation_calibration_authority'=>true/.test(release)],
 ['decision snapshots and settlements are append-only application evidence',
   /INSERT IGNORE INTO cognitive_decision_snapshots_v2580/.test(decision)
   &&/INSERT IGNORE INTO cognitive_decision_settlements_v2580/.test(decision)
   &&!/UPDATE cognitive_decision_snapshots_v2580|DELETE FROM cognitive_decision_snapshots_v2580/.test(decision)
   &&!/UPDATE cognitive_decision_settlements_v2580|DELETE FROM cognitive_decision_settlements_v2580/.test(decision)],
 ['capture is bounded six-hour bucketed fingerprinted and deduplicated',
   /VP3_COGNITIVE_DECISION_CAPTURE_BUCKET_SECONDS_V2580=21600/.test(decision)
   &&/VP3_COGNITIVE_DECISION_MAX_CAPTURE_V2580=8/.test(decision)
   &&/decision_fingerprint/.test(decision)
   &&/snapshot_key/.test(decision)
   &&/UNIQUE KEY uq_cognitive_decision_snapshot_key_v2580/.test(decision)],
 ['settlement requires canonical verified goal achievement',
   /agent_goal_review_actual_achievement_v1714/.test(decision)
   &&/function agent_goal_review_actual_achievement_v1714/.test(goalReview)
   &&/'goal_outcomes'=>'agent_goal_review_v1714'/.test(release)],
 ['settlement usage comes from canonical AI ledger with proportional shared-goal attribution',
   /FROM ai_execution_ledger/.test(decision)
   &&/1\.0\/max\(1,\(int\)\(\$counts/.test(decision)
   &&/COUNT\(DISTINCT l\.goal_id\) goal_count/.test(decision)
   &&/CREATE TABLE IF NOT EXISTS ai_execution_ledger/.test(accounting)],
 ['forecast cost token factors use only latest settled snapshot per goal',
   /SELECT s2\.goal_id,MAX\(s2\.id\) snapshot_id/.test(decision)
   &&/'one_latest_settlement_per_goal_drives_forecast_cost_token_factors'=>true/.test(release)],
 ['value reliability freezes expected decision value and uses latest canonical v25.70 realized evidence',
   /SELECT s2\.value_profile_id,MAX\(s2\.id\) snapshot_id/.test(decision)
   &&/expected_value_micros/.test(decision)&&/expected_value_score/.test(decision)
   &&/vp3_cognitive_value_realization_v2570/.test(decision)
   &&/'expected_value_is_frozen_from_settled_decision_snapshot'=>true/.test(release)
   &&/'one_latest_settled_snapshot_per_value_profile_drives_reliability'=>true/.test(release)],
 ['zero actual or realized ratios remain valid calibration evidence',
   /\$n>=0&&is_finite/.test(decision)
   &&/\$ratio>=0&&is_finite/.test(decision)
   &&/'zero_actual_or_realized_ratio_is_valid_evidence'=>true/.test(release)],
 ['minimum evidence is five and behavior is neutral below threshold',
   /VP3_COGNITIVE_DECISION_CALIBRATION_MIN_SAMPLES_V2580=5/.test(decision)
   &&/return \['factor'=>1\.0,'sample_count'=>\$count,'calibrated'=>false/.test(decision)],
 ['forecast cost and token factors are bounded 0.75 to 1.35',
   /vp3_cognitive_decision_ratio_factor_v2580\(\$values,\$min,\$max\)/.test(decision)
   &&/'forecast_factor_bounds'=>'0.75_to_1.35'/.test(release)
   &&/'cost_factor_bounds'=>'0.75_to_1.35'/.test(release)
   &&/'token_factor_bounds'=>'0.75_to_1.35'/.test(release)],
 ['value reliability is bounded 0.70 to 1.15',
   /vp3_cognitive_decision_ratio_factor_v2580\(\$ratios,\.70,1\.15\)/.test(decision)
   &&/'value_reliability_bounds'=>'0.70_to_1.15'/.test(release)],
 ['v24.90 retains raw forecasts and applies bounded decision calibration',
   /raw_earliest_completion_at/.test(forecast)
   &&/raw_likely_completion_at/.test(forecast)
   &&/raw_latest_completion_at/.test(forecast)
   &&/calibration_factor/.test(forecast)
   &&/max\(0\.75,min\(1\.35/.test(forecast)
   &&/'raw_forecast_is_retained'=>true/.test(release)],
 ['deadline risk uses calibrated likely completion',
   /\$deadlineRisk=\$deadline>0&&\(\$now\+\$likelySeconds\)>\$deadline/.test(forecast)
   &&/\$likelySeconds=max\(0,\(int\)round\(\$rawLikelySeconds\*\$calibrationFactor\)\)/.test(forecast)],
 ['snapshot capture gets canonical cost/token projections even without budget or value configuration',
   /fallbackProjection=/.test(decision)
   &&/vp3_cognitive_budget_goal_projection_v2560/.test(decision)
   &&/'cost_token_projection_capture_does_not_require_budget_or_value_profile'=>true/.test(release)
   &&/'projection_capture_does_not_create_governance_policy'=>true/.test(release)],
 ['v25.60 retains raw cost and token projections and calibrates only projected remainder',
   /raw_cost_micros/.test(budget)&&/raw_tokens/.test(budget)
   &&/cost_calibration_factor/.test(budget)&&/token_calibration_factor/.test(budget)
   &&/vp3_cognitive_decision_factor_v2580\(\$pdo,\$user,'cost','cloud'\)/.test(budget)
   &&/vp3_cognitive_decision_factor_v2580\(\$pdo,\$user,'tokens','cloud'\)/.test(budget)
   &&/'raw_cost_projection_is_retained'=>true/.test(release)
   &&/'raw_token_projection_is_retained'=>true/.test(release)],
 ['hard budget semantics remain authoritative after cost calibration',
   /budget_hard_hold/.test(budget)&&/request_budget_approval/.test(portfolio)
   &&/'hard_budget_authority_unchanged'=>true/.test(release)],
 ['v25.70 preserves expected value and calibrates only planning adjustment strength',
   /value_reliability_factor/.test(value)
   &&/vp3_cognitive_decision_factor_v2580\(\$pdo,\$user,'value_money'\)/.test(value)
   &&/vp3_cognitive_decision_factor_v2580\(\$pdo,\$user,'value_score'\)/.test(value)
   &&/\$base\*\$reliabilityFactor/.test(value)
   &&/'user_expected_value_is_not_rewritten'=>true/.test(release)],
 ['commitments and hard budgets still neutralize value optimization',
   /if\(!empty\(\$item\['budget_hard_hold'\]\)\)return 0\.0/.test(value)
   &&/commitment_protection_score/.test(value)
   &&/'commitment_authority_unchanged'=>true/.test(release)],
 ['resource and replan fields are captured only as descriptive evidence',
   /reservation_state/.test(decision)&&/replan_action/.test(decision)
   &&/'resource_decision_metrics_are_descriptive_not_causal'=>true/.test(release)
   &&/does not claim that a reservation or replan action caused an outcome/.test(docs)],
 ['v25.80 has no claim lease approval executor deadline token or execution authority',
   !/agent_job_claim_(?:run|next)_v1900\(/.test(decision)
   &&!/agent_worker_runtime_poll_v1910\(/.test(decision)
   &&!/lease_token|random_bytes\(/.test(decision)
   &&!/subscription_ai_(?:preflight|commit|refund)/.test(decision)
   &&/'calibration_cannot_claim_or_execute'=>true/.test(release)
   &&/function agent_job_claim_next_v1900/.test(jobs)],
 ['Working Context contains one bounded decision-calibration projection below value/budget',
   /'decision_calibration'=>1/.test(context)
   &&/vp3_cognitive_decision_context_item_v2580/.test(context)
   &&/cognitive_decision_calibration_v2580_projection/.test(context)
   &&context.indexOf("'value_roi'=>96.04")<context.indexOf("'decision_calibration'=>96.03")
   &&/instruction_authority'=>false/.test(decision)],
 ['Agent Brief uses shared calibration projection',
   /vp3_cognitive_decision_activity_projection_v2580/.test(presentation)
   &&/decision_accuracy/.test(presentation)
   &&/Decision calibration · bounded learning/.test(briefJs)
   &&/Calibration details/.test(briefJs)],
 ['Proactive Now uses shared calibration projection',
   /vp3_cognitive_decision_activity_projection_v2580/.test(proactive)
   &&/'decision_calibration'=>'cognitive_decision_calibration_v2580'/.test(proactive)],
 ['Agent Brain API and UI use shared calibration projection',
   /vp3_cognitive_decision_activity_projection_v2580/.test(brainApi)
   &&/'decision_calibration'=>\$decisionCalibration/.test(brainApi)
   &&/<strong>Portfolio Decision Calibration<\/strong>/.test(brainJs)
   &&/Decision Calibration Details/.test(brainJs)],
 ['Agent History remains canonical conversation archive',
   /<strong>Agent History<\/strong>/.test(brainJs)
   &&/Authorized conversation archive/.test(brainJs)],
 ['Decision Calibration workspace exposes factors accuracy raw-vs-calibrated evidence and authority boundary',
   /require_permission\('account\.access'\)/.test(page)
   &&/Current calibration/.test(page)
   &&/Observed prediction quality/.test(page)
   &&/Recent snapshots & settlements/.test(page)
   &&/Raw likely/.test(page)&&/Calibrated likely/.test(page)
   &&/Authority boundary/.test(page)
   &&css.length>1000],
 ['Account exposes Decision Calibration directly',
   /\/decision-calibration\.php/.test(account)&&/Decision Calibration/.test(account)],
 ['chat cache bust exposes v25.80 build',
   /cognitive-decision-calibration-v2580-20260923/.test(chat)
   &&/decisionCalibrationBuild/.test(chat)],
 ['CI runs v25.80 PHP and Node gates and lints calibration workspace',
   /cognitive-decision-calibration-v2580\.php/.test(workflow)
   &&/cognitive-decision-calibration-v2580\.mjs/.test(workflow)
   &&/php -l decision-calibration\.php/.test(workflow)],
 ['Recovery Baseline retains v25.80 gates',
   /cognitive-decision-calibration-v2580\.php/.test(recovery)
   &&/cognitive-decision-calibration-v2580\.mjs/.test(recovery)],
 ['production package requires v25.80 runtime workspace upgrade and canonical evidence sources',
   /Cognitive Runtime v25\.80/.test(packageWorkflow)
   &&/cognitive-decision-calibration-v2580\.php/.test(packageWorkflow)
   &&/cognitive-release-v2580\.php/.test(packageWorkflow)
   &&/decision-calibration\.php/.test(packageWorkflow)
   &&/decision-calibration-v2580\.css/.test(packageWorkflow)
   &&/upgrade\.php/.test(packageWorkflow)
   &&/agent-goal-review-v1714\.php/.test(packageWorkflow)
   &&/ai-usage-accounting-v032\.php/.test(packageWorkflow)
   &&/cognitive-value-roi-v2570\.php/.test(packageWorkflow)],
 ['docs preserve separate learner authority bounded factors raw estimates evidence semantics and fail-open behavior',
   /not a second relevance learner/i.test(docs)
   &&/latest settled snapshot per goal/.test(docs)
   &&/verified value profiles/.test(docs)
   &&/raw completion window/.test(docs)
   &&/raw projected remaining AI cost/i.test(docs)
   &&/No already-authorized work can deadlock/.test(docs)],
];

for(const [name,ok] of checks){assert.equal(ok,true,name);console.log('PASS',name);}
console.log(`Cognitive Decision Calibration v25.80 gate: ${checks.length}/${checks.length} passed`);
