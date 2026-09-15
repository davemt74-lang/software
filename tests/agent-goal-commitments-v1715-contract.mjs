import fs from 'node:fs';
import assert from 'node:assert/strict';

const commitments=fs.readFileSync('includes/agent-goal-commitments-v1715.php','utf8');
const review=fs.readFileSync('includes/agent-goal-review-v1714.php','utf8');
const forecasting=fs.readFileSync('includes/agent-goal-forecasting-v1713.php','utf8');
const execution=fs.readFileSync('includes/agent-goal-execution-v1712.php','utf8');
const strategy=fs.readFileSync('includes/agent-goal-strategy-v1710.php','utf8');

assert.match(commitments,/agent-goal-commitments-v1715-20260915/,'stable Phase 17.15 marker is required');
assert.match(commitments,/agent_goal_commitment_state_v1715/,'goal commitment state is required');
assert.match(commitments,/agent_goal_commitment_portfolio_v1715/,'portfolio commitment review is required');
assert.match(commitments,/agent_goal_commitment_chat_v1715/,'Agent Chat commitment routing is required');

assert.match(commitments,/VP3_AGENT_GOAL_COMMITMENT_CHECKIN_DAYS_V1715=14/,'14-day check-in threshold is required');
assert.match(commitments,/VP3_AGENT_GOAL_COMMITMENT_STALE_DAYS_V1715=21/,'21-day stale threshold is required');
assert.match(commitments,/VP3_AGENT_GOAL_COMMITMENT_DRIFT_DAYS_V1715=7/,'7-day review drift threshold is required');
assert.match(commitments,/agent_goal_review_forecast_state_v1714/,'17.15 must build on learned Phase 17.14 review state');
assert.match(commitments,/agent_goal_review_history_v1714/,'17.15 must use durable Phase 17.14 review history');
assert.match(commitments,/agent_goal_objectives_v1710/,'canonical goal/objective activity must drive staleness');
assert.match(commitments,/verified_progress_delta/,'review-to-review verified progress delta is required');
assert.match(commitments,/forecast_drift_days/,'review-to-review forecast drift is required');
assert.match(commitments,/intentional_pause/,'paused goals must be identified explicitly');
assert.match(commitments,/A paused goal is treated as an intentional hold, not as an abandoned commitment/,'paused goals must not be mislabeled as abandoned');

for (const decision of ['revisit_pause','structure_goal','revise_or_recommit','replan_or_recommit','recommit_pause_or_archive','check_in','review_drift']) {
  assert.match(commitments,new RegExp(`['\"]${decision}['\"]`),`decision ${decision} is required`);
}
assert.match(commitments,/check\[- \]\?in\|commitment\|committed\|recommit/,'Chat must understand commitment/check-in language');
assert.match(commitments,/stale\|neglected\|abandoned\|dormant\|forgotten/,'Chat must understand stale commitment language');
assert.match(commitments,/This check-in is advisory only/,'specific commitment answer must state advisory boundary');
assert.match(commitments,/never auto-paused or auto-archived/,'portfolio answer must state no automatic abandonment action');

assert.doesNotMatch(commitments,/CREATE TABLE|ALTER TABLE|DROP TABLE/i,'17.15 must add no schema');
assert.doesNotMatch(commitments,/agent_goal_update_v1710\s*\(/,'17.15 must never auto-edit goal strategy/date/criteria');
assert.doesNotMatch(commitments,/agent_goal_priority_v1710\s*\(/,'17.15 must never auto-reprioritize goals');
assert.doesNotMatch(commitments,/agent_goal_status_v1710\s*\(/,'17.15 must never auto-pause/resume/archive goals');
assert.doesNotMatch(commitments,/agent_goal_plan_(?:add|seed|link|create|resequence|retire)_/,'17.15 must never mutate the roadmap');
assert.doesNotMatch(commitments,/agent_work_control_(?:approve|resume|retry|cancel|reschedule|priority)_v173\s*\(/,'17.15 must never mutate workflow controls');
assert.doesNotMatch(commitments,/lease_owner\s*=|receipt_json\s*=|result_json\s*=|approval_status\s*=/i,'17.15 must not own Phase 19 or approval writes');
assert.doesNotMatch(commitments,/INSERT\s+INTO|UPDATE\s+agent_|DELETE\s+FROM/i,'17.15 commitment evaluation must be read-only');
assert.doesNotMatch(commitments,/setInterval\s*\(/,'17.15 must not add polling or a second scheduler');

assert.match(review,/require_once __DIR__\.\'\/agent-goal-commitments-v1715\.php\'/,'Phase 17.14 shared boundary must load Phase 17.15');
assert.match(review,/agent_goal_commitment_chat_v1715\(\$query,\$user,\$conversationId\)/,'Phase 17.14 shared boundary must route Phase 17.15');
assert.ok(review.indexOf('agent_goal_commitment_chat_v1715') < review.indexOf('$reviewIntent='),'commitment routing must happen before baseline 17.14 review intent parsing');

assert.match(forecasting,/No scenario changes priority, dates, scope, approvals, dependencies, or execution/,'17.13 advisory forecasting boundary must remain intact');
assert.match(execution,/Phase 19 can claim it normally/,'17.12 must retain Phase 19 worker authority');
assert.match(strategy,/Goal progress is derived only from Phase 17\.6 verified objective outcomes/,'goal achievement must remain verification-derived');

console.log('Agent Goal Commitments v17.15 contract passed.');
