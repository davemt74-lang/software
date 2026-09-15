import fs from 'node:fs';
import assert from 'node:assert/strict';

const review=fs.readFileSync('includes/agent-goal-review-v1714.php','utf8');
const forecasting=fs.readFileSync('includes/agent-goal-forecasting-v1713.php','utf8');
const execution=fs.readFileSync('includes/agent-goal-execution-v1712.php','utf8');
const planning=fs.readFileSync('includes/agent-goal-planning-v1711.php','utf8');
const strategy=fs.readFileSync('includes/agent-goal-strategy-v1710.php','utf8');
const memory=fs.readFileSync('includes/agent-objective-memory-v177.php','utf8');
const verification=fs.readFileSync('includes/agent-objective-verification-v176.php','utf8');
const chat=fs.readFileSync('includes/release-chat-v105.php','utf8');
const upgrade=fs.readFileSync('upgrade.php','utf8');

assert.match(review,/agent-goal-review-v1714-20260915/,'stable Phase 17.14 marker is required');
assert.match(review,/require_once __DIR__\.\'\/agent-goal-forecasting-v1713\.php\'/,'17.14 must extend Phase 17.13 forecasting');
assert.match(review,/require_once __DIR__\.\'\/agent-objective-memory-v177\.php\'/,'17.14 must explicitly load canonical objective outcome memory');

assert.match(review,/CREATE TABLE IF NOT EXISTS agent_goal_review_snapshots/,'17.14 requires durable owner-scoped review snapshots');
for (const field of ['owner_user_id','goal_id','state_hash','forecast_date','target_date','forecast_days','calibration_factor','risk_status','capacity_score','plan_health','execution_state','progress_percent','remaining_milestones','outcome_status','actual_achieved_at','forecast_error_days']) {
  assert.match(review,new RegExp(`\\b${field}\\b`),`review snapshot field ${field} is required`);
}
assert.match(review,/agent_goal_review_schema_ready_v1714/,'17.14 schema readiness contract is required');
assert.match(review,/agent_goal_review_ensure_schema_v1714/,'17.14 normal upgrade schema installer is required');

assert.match(review,/objective_verification_status='achieved'/,'forecast outcomes must settle only from verified achieved objectives');
assert.match(review,/objective_verified_at/,'actual achievement timing must use Phase 17.6 verification time');
assert.match(review,/forecast_error_days=CASE[\s\S]*DATEDIFF/,'settled snapshots must calculate observed forecast error');
assert.match(review,/outcome_status='closed_unachieved'/,'archived unachieved goals must settle without pretending achievement');
assert.doesNotMatch(review,/SET outcome_status='achieved'[\s\S]*WHERE[^\n]*objective_verification_status/i,'snapshot settlement must not manufacture canonical verification state');

assert.match(review,/agent_goal_review_calibration_v1714/,'verified outcome calibration is required');
assert.match(review,/agent_objective_portfolio_similarity_v179/,'calibration should prefer similar completed goals when evidence exists');
assert.match(review,/latestByGoal/,'calibration must avoid overweighting multiple snapshots of one finished goal');
assert.match(review,/max\(0\.75,min\(1\.50/,'learned calibration must remain bounded');
assert.match(review,/agent_goal_forecast_state_v1713/,'17.14 must use 17.13 as its baseline forecast');
assert.match(review,/agent_goal_forecast_risk_v1713/,'learned forecast must recompute canonical risk');
assert.match(review,/agent_goal_forecast_scenarios_v1713/,'learned forecast must recompute advisory scenarios');
assert.match(review,/uncalibrated_days/,'learned forecast must retain its baseline for transparency');
assert.match(review,/calibration_sample_count/,'forecast must disclose learning evidence size');

assert.match(review,/agent_goal_review_history_v1714/,'review history is required');
assert.match(review,/blocked_count/,'review must identify repeated dependency blocking');
assert.match(review,/approval_count/,'review must identify repeated approval waits');
assert.match(review,/repair_count/,'review must identify remediation history');
assert.match(review,/overcommit_count/,'review must identify repeated capacity pressure');
assert.match(review,/forecast_drift_days/,'review must expose forecast drift');
assert.match(review,/agent_objective_memory_similar_v177/,'17.14 must reuse Phase 17.7 verified outcome memory as evidence');
assert.match(review,/strategy_candidate/,'review must produce an advisory strategy candidate');
assert.match(review,/Nothing here changes the goal, roadmap, target date, approvals, dependencies, or execution state/,'review answer must state the non-mutating boundary');

assert.match(review,/agent_goal_review_chat_v1714/,'Agent Chat review routing is required');
assert.match(review,/review\|retrospective\|postmortem/,'Chat must understand explicit review/retrospective language');
assert.match(review,/forecast accuracy\|accuracy/,'Chat must understand forecast-accuracy language');
assert.ok(review.includes("preg_match('/\\bwhy\\b.*\\b(?:goal|goals)\\b.*\\b(?:slip|late|miss|delay|fail|stuck)\\b/i'"),'Chat must understand recurring-failure questions');
assert.match(review,/if\(!agent_goal_review_schema_ready_v1714\(\$pdo\)\)/,'review must degrade safely before upgrade');
assert.match(review,/if\(!\$reviewIntent\)return \$empty/,'ordinary 17.13 forecast commands must keep working before the 17.14 schema upgrade');

assert.doesNotMatch(review,/agent_goal_update_v1710\s*\(/,'17.14 must never auto-edit goal strategy/date/criteria');
assert.doesNotMatch(review,/agent_goal_priority_v1710\s*\(/,'17.14 must never auto-reprioritize goals');
assert.doesNotMatch(review,/agent_goal_status_v1710\s*\(/,'17.14 must never auto-pause/resume/archive goals');
assert.doesNotMatch(review,/agent_goal_plan_(?:add|seed|link|create|resequence|retire)_/,'17.14 must never mutate the roadmap');
assert.doesNotMatch(review,/agent_work_control_(?:approve|resume|retry|cancel|reschedule|priority)_v173\s*\(/,'17.14 must never mutate workflow controls');
assert.doesNotMatch(review,/lease_owner\s*=|receipt_json\s*=|result_json\s*=|approval_status\s*=/i,'17.14 must not own Phase 19 or approval writes');
assert.doesNotMatch(review,/setInterval\s*\(/,'17.14 must not add polling or a second scheduler');

assert.match(chat,/require_once __DIR__\.\'\/agent-goal-review-v1714\.php\'/,'shared Agent Chat boundary must load Phase 17.14');
assert.match(chat,/agent_goal_review_chat_v1714\(\$query,\$user,\$conversationId\)/,'shared Agent Chat boundary must route Phase 17.14');
assert.ok(chat.indexOf('agent_goal_review_chat_v1714') < chat.indexOf('agent_goal_forecasting_chat_v1713'),'learned review routing must run before baseline forecasting');
assert.match(upgrade,/require_once __DIR__ \. '\/includes\/agent-goal-review-v1714\.php'/,'normal upgrade must load Phase 17.14');
assert.match(upgrade,/agent_goal_review_schema_ready_v1714\(\)/,'upgrade completeness must require Phase 17.14 schema');
assert.match(upgrade,/agent_goal_review_ensure_schema_v1714\(\$pdo\)/,'Run Upgrade must install Phase 17.14 schema');

assert.match(forecasting,/No scenario changes priority, dates, scope, approvals, dependencies, or execution/,'17.13 advisory forecasting boundary must remain intact');
assert.match(execution,/Phase 19 can claim it normally/,'17.12 must retain Phase 19 worker authority');
assert.match(planning,/Advisory milestones sit above Phase 17\.10/,'17.11 roadmap authority must remain advisory');
assert.match(strategy,/Goal progress is derived only from Phase 17\.6 verified objective outcomes/,'goal achievement must remain verification-derived');
assert.match(memory,/never stores or replays receipts/,'Phase 17.7 outcome-memory safety boundary must remain intact');
assert.match(verification,/objective_verified_at/,'Phase 17.6 verified achievement timestamp must remain canonical');

console.log('Agent Goal Review v17.14 contract passed.');
