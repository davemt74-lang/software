import fs from 'node:fs';
import assert from 'node:assert/strict';

const goal=fs.readFileSync('includes/agent-goal-strategy-v1710.php','utf8');
const chat=fs.readFileSync('includes/release-chat-v105.php','utf8');
const upgrade=fs.readFileSync('upgrade.php','utf8');
const brief=fs.readFileSync('chat-agent-intelligence-v171.js','utf8');

assert.match(goal,/agent-goal-strategy-v1710-20260915/,'stable Phase 17.10 marker is required');
assert.match(goal,/CREATE TABLE IF NOT EXISTS agent_goals/,'durable owner-scoped goal records are required');
assert.match(goal,/CREATE TABLE IF NOT EXISTS agent_goal_objectives/,'goal-objective hierarchy links are required');
assert.match(goal,/UNIQUE KEY uq_agent_goal_objective \(owner_user_id,goal_id,objective_run_id\)/,'goal-objective links must be owner-scoped and idempotent');
assert.match(goal,/CREATE TABLE IF NOT EXISTS agent_goal_events/,'goal changes need a lightweight audit trail');
assert.match(goal,/agent_objective_verification_schema_ready_v176/,'goal readiness must depend on canonical objective verification');
assert.match(goal,/objective_verification_status.*achieved/s,'goal progress must derive from verified objective achievement');
assert.match(goal,/derived_status.*achieved/s,'goal achievement must be derived rather than manually asserted');
assert.match(goal,/contribution_weight/,'linked objectives must express contribution to the parent goal');
assert.match(goal,/goal_score_percent/,'goal-specific contribution must affect strategic ranking');
assert.match(goal,/score_percent.*0\.75.*ratio\*25/s,'goal strategy must combine Phase 17.9 portfolio score with goal contribution');
assert.match(goal,/agent_goal_deadline_v1710/,'goal strategy must evaluate target-date risk');
assert.match(goal,/within one week|within 30 days|target date has passed/,'deadline risk must be evidence based');
assert.match(goal,/agent_goal_attention_v1710/,'Phase 17.10 must arbitrate attention across goals');
assert.match(goal,/priority\*0\.40.*deadlineScore\*0\.25.*issues\*0\.20.*remaining\*0\.15/s,'cross-goal attention must combine user priority, deadline pressure, unresolved problems, and remaining verified progress');
assert.match(goal,/needs the most attention right now/,'goal list Chat output must identify the highest-attention goal');
assert.match(goal,/agent_objective_memory_similar_v177/,'Phase 17.7 learned outcomes must inform goal strategy');
assert.match(goal,/agent_proactive_objectives_v178/,'Phase 17.8 proposals must be available as advisory goal inputs');
assert.match(goal,/agent_objective_portfolio_v179/,'Phase 17.9 arbitration must remain the base objective-ranking layer');
assert.match(goal,/agent_objective_create_v175/,'new goal objectives must be fresh canonical Phase 17.5 objectives');
assert.match(goal,/Resume this goal before changing its strategy or objective links/,'paused goals must reject strategy mutations');
assert.match(goal,/Archived goals are immutable history/,'archived goals must be immutable');
assert.match(goal,/Existing objective\/workflow execution was not changed/,'goal pause must not mutate existing execution');
assert.match(goal,/none will be attached or executed without your explicit instruction/,'proactive strategy suggestions must stay advisory');
assert.doesNotMatch(goal,/UPDATE agent_goals SET status=.*achieved|status\s*=\s*['\"]achieved['\"]/i,'goal completion must not have a manual achieved mutation');
assert.doesNotMatch(goal,/lease_owner|lease_expires|receipt_json|result_json|approval_status\s*=|next_attempt_at\s*=/,'goal layer must not own Phase 19 execution state');
assert.doesNotMatch(goal,/setInterval\s*\(/,'PHP goal layer must not add polling loops');

assert.match(chat,/require_once __DIR__\.\'\/agent-goal-strategy-v1710\.php\'/,'shared Chat boundary must load Phase 17.10');
assert.match(chat,/agent_goal_chat_v1710\(\$query,\$user,\$conversationId\)/,'Agent Chat must route explicit goal language through Phase 17.10');
assert.ok(chat.indexOf('agent_goal_chat_v1710') < chat.indexOf('agent_proactive_objective_chat_v178'),'goal hierarchy routing must occur before objective-level routing');

assert.match(upgrade,/agent_goal_strategy_schema_ready_v1710\(\)/,'central upgrade readiness must include Phase 17.10');
assert.match(upgrade,/agent_goal_strategy_ensure_schema_v1710\(\$pdo\)/,'normal Run Upgrade must install Phase 17.10');
assert.match(upgrade,/durable goals, goal-objective strategy links/,'upgrade copy must preserve existing goal data');

assert.match(brief,/data-agent-goals-control/,'existing Agent Brief must expose Goals without a second dashboard');
assert.match(brief,/Show my goals and tell me which goal needs attention first\./,'Goals control must route through canonical Chat');
assert.doesNotMatch(brief,/fetch\s*\(|setInterval\s*\(/,'Agent Brief Goals control must not add polling');

console.log('Agent Goal Strategy v17.10 contract passed.');
